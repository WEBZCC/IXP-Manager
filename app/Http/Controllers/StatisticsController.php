<?php
/*
 * Copyright (C) 2009 - 2020 Internet Neutral Exchange Association Company Limited By Guarantee.
 * All Rights Reserved.
 *
 * This file is part of IXP Manager.
 *
 * IXP Manager is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, version v2.0 of the License.
 *
 * IXP Manager is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License v2.0
 * along with IXP Manager.  If not, see:
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

namespace IXP\Http\Controllers;

use App, Auth;

use Carbon\Carbon;

use Illuminate\Auth\Access\AuthorizationException;

use Illuminate\Support\Arr;
use Illuminate\Http\{
    Request,
    RedirectResponse
};

use Illuminate\View\View;

use IXP\Exceptions\Services\Grapher\GraphCannotBeProcessedException;
use IXP\Http\Requests\StatisticsRequest;

use IXP\Models\{
    Aggregators\TrafficDailyPhysIntAggregator,
    Aggregators\VirtualInterfaceAggregator,
    Aggregators\VlanInterfaceAggregator,
    CoreBundle,
    Customer,
    Infrastructure,
    PhysicalInterface,
    Switcher,
    TrafficDaily,
    TrafficDailyPhysInt,
    VirtualInterface,
    Vlan,
    VlanInterface
};

use IXP\Services\Grapher\Graph;
use IXP\Services\Grapher;

use IXP\Services\Grapher\Graph\{
    Customer as CustomerGraph
};

use IXP\Utils\View\Alert\{
    Alert,
    Container as AlertContainer
};

/**
 * Statistics Controller
 * @author     Barry O'Donovan  <barry@islandbridgenetworks.ie>
 * @author     Yann Robin       <yann@islandbridgenetworks.ie>
 * @category   Statistics
 * @copyright  Copyright (C) 2009 - 2020 Internet Neutral Exchange Association Company Limited By Guarantee
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class StatisticsController extends Controller
{
    /**
     * Process and update request parameters for standard graph attributes: period, category, protocol, type.
     *
     * These are safe for use from the request.
     *
     * @param StatisticsRequest $r
     *
     * @return void
     */
    private function processGraphParams( StatisticsRequest $r ): void
    {
        $r->period   = Graph::processParameterPeriod(   $r->period );
        $r->category = Graph::processParameterCategory( $r->category );
        $r->protocol = Graph::processParameterProtocol( $r->protocol );
        $r->type     = Graph::processParameterType(     $r->type );
    }

    /**
     * Show overall IXP graphs
     *
     * @param string $category Category of graph to show (e.g. bits / pkts)
     *
     * @return View
     *
     * @throws
     */
    public function ixp( string $category = Graph::CATEGORY_BITS ) : View
    {
        $category = Graph::processParameterCategory( $category, true );

        $graph = App::make( Grapher::class )
            ->ixp()->setType( Graph::TYPE_PNG )
            ->setProtocol( Graph::PROTOCOL_ALL )->setCategory( $category );

        $graph->authorise();

        return view( 'statistics/ixp' )->with( [
            'graph'    => $graph,
            'category' => $category,
        ] );
    }

    /**
     * Show IXP infrastructure graphs
     *
     * @param int $infraid ID of the infrastructure to show the graph of
     * @param string $category Category of graph to show (e.g. bits / pkts)
     *
     * @return View
     *
     * @throws
     */
    public function infrastructure( int $infraid = 0, string $category = Graph::CATEGORY_BITS ) : View
    {
        $infras     = Infrastructure::select( [ 'name', 'id' ] )
            ->orderBy( 'name' )
            ->get()->keyBy( 'id' )->toArray();
        $infra      = Infrastructure::whereId( isset( $infras[ $infraid ] ) ? $infraid : array_keys( $infras )[0] )->get()->first();
        $category   = Graph::processParameterCategory( $category, true );

        $graph      = App::make( Grapher::class )
            ->infrastructure( $infra )->setType( Graph::TYPE_PNG )
            ->setProtocol( Graph::PROTOCOL_ALL )->setCategory( $category );

        $graph->authorise();

        return view( 'statistics/infrastructure' )->with( [
            'infras'   => $infras,
            'infra'    => $infra,
            'graph'    => $graph,
            'category' => $category,
        ] );
    }

    /**
     * Show Vlan (sflow) graphs
     *
     * @param int       $vlanid     ID of the VLAN to show the graph of
     * @param string    $protocol   IPv4/6
     * @param string    $category
     *
     * @return View
     *
     * @throws
     */
    public function vlan( int $vlanid = 0, string $protocol = Graph::PROTOCOL_IPV4, string $category = Graph::CATEGORY_BITS ) : View
    {
        $vlans   = Vlan::publicOnly()
            ->where( 'peering_matrix', true )->where( 'peering_manager', true )
            ->orderBy( 'name' )->get()->keyBy( 'id' )->toArray();

        $protocol = Graph::processParameterRealProtocol( $protocol );
        $category = Graph::processParameterCategory( $category, true );

        if( !count( [ $vlans ] ) ) {
            abort( 404, 'No VLANs available for graphing' );
        }

        $vlan     = Vlan::whereId( isset( $vlans[ $vlanid ] ) ? $vlanid : array_keys( $vlans )[0] )->get()->first();
        $graph    = App::make( Grapher::class )
            ->vlan( $vlan )->setType( Graph::TYPE_PNG )
            ->setProtocol( $protocol )->setCategory( $category );

        try {
            $graph->backend();
        } catch( GraphCannotBeProcessedException $e ) {
            abort( 404, 'No backend available to process VLAN graphs' );
        }

        $graph->authorise();

        return view( 'statistics/vlan' )->with( [
            'vlans'    => $vlans,
            'vlan'     => $vlan,
            'graph'    => $graph,
            'protocol' => $protocol,
            'category' => $category,
        ] );
    }

    /**
     * Show IXP switch graphs
     *
     * @param int       $switchid       ID of the switch to show the graph of
     * @param string    $category       Category of graph to show (e.g. bits / pkts)
     *
     * @return View
     *
     * @throws
     */
    public function switch( int $switchid = 0, string $category = Graph::CATEGORY_BITS ) : View
    {
        $switches = Switcher::where( 'active', true )->orderBy( 'name' )->get()->keyBy( 'id' )->toArray();
        $category = Graph::processParameterCategory( $category, true );

        $switch   = Switcher::whereId( isset( $switches[ $switchid ] ) ? $switchid : array_keys( $switches )[0] )->get()->first();
        $graph    = App::make( Grapher::class )->switch( $switch )->setType( Graph::TYPE_PNG )->setProtocol( Graph::PROTOCOL_ALL )->setCategory( $category );

        $graph->authorise();

        return view( 'statistics/switch' )->with([
            'switches'  => $switches,
            'switch'    => $switch,
            'graph'     => $graph,
            'category'  => $category,
        ]);
    }

    /**
     * Show IXP trunk graphs
     *
     * @param string $trunkid ID of the trunk to show the graph of
     * @param string $category Category of graph to show (e.g. bits / pkts)
     *
     * @return RedirectResponse|View
     *
     * @throws
     */
    public function trunk( string $trunkid = null, string $category = Graph::CATEGORY_BITS )
    {
        if( !is_array( config('grapher.backends.mrtg.trunks') ) || !count( config('grapher.backends.mrtg.trunks') ) ) {
            AlertContainer::push(
                "Trunk graphs have not been configured. Please see <a target='_blank' href=\"https://docs.ixpmanager.org/grapher/introduction/\">this documentation</a> for instructions.",
                Alert::DANGER
            );
            return redirect('');
        }

        // get the available graphs
        $images = [];
        $graphs = [];
        foreach( config('grapher.backends.mrtg.trunks') as $g ) {
            $images[]           = $g['name'];
            $graphs[$g['name']] = $g['title'];
        }

        if( !in_array( $trunkid, $images, true ) ) {
            $trunkid = $images[ 0 ];
        }

        $graph = App::make( Grapher::class )
            ->trunk( $trunkid )->setType( Graph::TYPE_PNG )
            ->setProtocol( Graph::PROTOCOL_ALL )->setCategory( Graph::CATEGORY_BITS );

        $graph->authorise();

        return view( 'statistics/trunk' )->with( [
            'graphs'    => $graphs,
            'trunkid'   => $trunkid,
            'graph'     => $graph,
            'category'  => $category,
        ] );
    }

    /**
     * Display all member graphs
     *
     * @param StatisticsRequest $r
     *
     * @return View
     *
     * @throws
     */
    public function members( StatisticsRequest $r ): View
    {
        if( !CustomerGraph::authorisedForAllCustomers() ) {
            abort( 403, "You are not authorised to view this member's graphs." );
        }

        $grapher = App::make( Grapher::class );
        $this->processGraphParams($r);

        // do we have an infrastructure or vlan?
        $vlan = $infra = false;

        if( $r->infra ) {
            if( $infra = Infrastructure::find( $r->infra ) ) {
                $targets = VirtualInterfaceAggregator::getForInfrastructure( $infra );
            } else {
                $targets = Customer::currentActive( true, false )->get();
            }
            $r->protocol = Graph::PROTOCOL_ALL;
        } else if( $r->vlan && ( $vlan = Vlan::find( $r->vlan ) ) ) {
            if( !in_array( $r->protocol, Graph::PROTOCOLS_REAL, true ) ) {
                $r->protocol = Graph::PROTOCOL_IPV4;
            }

            $targets = VlanInterfaceAggregator::forVlan( $vlan, $r->protocol );
        } else {
            $targets = [];
        }

        $graphs = [];
        foreach( $targets as $t ) {
            if( !$t->isGraphable() ) {
                continue;
            }

            if( $infra ) {
                $g = $grapher->virtint( $t );
            } else if( $vlan ) {
                $g = $grapher->vlanint( $t );
            } else {
                $g = $grapher->customer( $t );
            }

            /** @var Graph $g */
            $g->setType(     Graph::TYPE_PNG )
                ->setProtocol( $r->protocol   )
                ->setCategory( $r->category   )
                ->setPeriod(   $r->period     );

            $g->authorise();
            $graphs[] = $g;
        }

        return view( 'statistics/members' )->with([
            'graph'         => $graphs[0] ?? false,  // sample graph as all types/protocols/categories/periods will be the same
            'graphs'        => $graphs,
            'r'             => $r,
            'infras'        => Infrastructure::orderBy( 'name', 'asc' )->get(),
            'infra'         => $infra ?? false,
            'vlans'         => Vlan::publicOnly()->orderBy('number')->get(),
            'vlan'          => $vlan ?? false,
        ]);
    }


    /**
     * Display all graphs for a member
     *
     * @param StatisticsRequest $r
     * @param int|null          $id ID of the member
     *
     * @return RedirectResponse|View
     *
     */
    public function member( StatisticsRequest $r, int $id = null )
    {
        if( !$id && Auth::check() ) {
            $id = Auth::user()->getCustomer()->getId();
        }

        $c = Customer::findOrFail( $id );

        $grapher = App::make( Grapher::class );

        // if the customer is authorised, then so too are all of their virtual and physical interfaces:
        try {
            $grapher->customer( $c )->authorise();
        } catch( AuthorizationException $e ) {
            abort( 403, "You are not authorised to view this member's graphs." );
        }

        if( !$c->hasInterfacesConnectedOrInQuarantine() ) {
            AlertContainer::push(
                "This customer has no graphable interfaces (i.e. no physical interfaces in quarantine or connected)",
                Alert::WARNING
            );
            return redirect()->back();
        }

        return view( 'statistics/member' )->with([
            "c"                     => $c,
            "grapher"               => $grapher,
            "category"              => Graph::processParameterCategory( $r->input( 'category' ) ),
            "period"                => Graph::processParameterPeriod( $r->input( 'period' ) ),
        ]);
    }

    /**
     * Display Aggregate/LAG/Port for all periods (day/week/month/year)
     *
     * @param   StatisticsRequest     $r
     * @param   string                $type       type
     * @param   integer               $typeid     ID of type
     *
     * @return  View
     *
     * @throws
     */
    public function memberDrilldown( StatisticsRequest $r, string $type, int $typeid ): View
    {
        switch( strtolower( $type ) ) {
            case 'agg':
                $c = Customer::findOrFail( $typeid );
                $graph = App::make( Grapher::class )->customer( $c );
                break;
            case 'vi':
                $vi = VirtualInterface::findOrFail( $typeid );
                $c = $vi->customer;
                $graph = App::make( Grapher::class )->virtint( $vi );
                break;
            case 'pi':
                $pi = PhysicalInterface::findOrFail( $typeid );
                $c = $pi->virtualInterface->customer;
                $graph = App::make( Grapher::class )->physint( $pi );
                break;
            default:
                abort( 404, 'Unknown graph type' );
        }

        /** @var Graph $graph */
        $graph->setCategory( Graph::processParameterCategory( $r->category ) );
        $graph->authorise();

        return view( 'statistics/member-drilldown' )->with( [
            'c'     => $c,
            'graph' => $graph,
        ]);
    }

    /**
     * Show latency graphs
     *
     * @param VlanInterface     $vli
     * @param string            $protocol
     *
     * @return View|RedirectResponse
     *
     * @throws
     */
    public function latency( VlanInterface $vli, string $protocol )
    {
        $protocol = Graph::processParameterProtocol( $protocol );

        $graph = App::make( Grapher::class )->latency( $vli )->setProtocol( $protocol );
        $graph->authorise();

        $fnEnabled = strtolower( $protocol ) . 'enabled';
        $fnCanping = strtolower( $protocol ) . 'canping';
        $fnAddress = strtolower( $protocol ) . 'address';

        if( !$vli->$fnEnabled || !$vli->$fnCanping ) {
            AlertContainer::push(
                "Protocol or ping not enabled on the requested interface",
                Alert::WARNING
            );
            return redirect()->to( route( "statistics@member" ), [ "id" => $vli->virtualInterface->customer->id ] );
        }

        return view( 'statistics/latency' )->with([
            'c'         => $vli->virtualInterface->customer,
            'vli'       => $vli,
            'ip'        => $vli->$fnAddress->address,
            'protocol'  => $protocol,
            'graph'     => $graph,
        ]);
    }


    /**
     * sFlow Peer to Peer statistics
     *
     * @param Request $r
     * @param null $cid
     *
     * @return RedirectResponse|View
     *
     * @throws
     */
    public function p2p( Request $r, $cid = null )
    {
        // default to the current user:
        if( !$cid && Auth::check() ) {
            $cid = Auth::user()->getCustomer()->getId();
        }

        // for larger IXPs, it's quite intensive to display all the graphs - decide if we need to do this or not
        if( config('grapher.backends.sflow.show_graphs_on_index_page') !== null ) {
            $showGraphsOption = true;
            $showGraphs       = config('grapher.backends.sflow.show_graphs_on_index_page');
        } else {
            $showGraphsOption = false;
            $showGraphs       = true;
        }

        if( $showGraphsOption ) {
            if( $r->submit === "Show Graphs" ) {
                $showGraphs = true;
                $r->session()->put( 'controller.statistics.p2p.show_graphs', true );
            } else if( $r->submit === "Hide Graphs" ) {
                $showGraphs = false;
                $r->session()->put( 'controller.statistics.p2p.show_graphs', false );
            } else {
                $showGraphs = $r->session()->get( 'controller.statistics.p2p.show_graphs', config('grapher.backends.sflow.show_graphs_on_index_page') );
            }
        }

        $r->category = Graph::processParameterCategory(     $r->category, true );
        $r->period   = Graph::processParameterPeriod(       $r->period );
        $r->protocol = Graph::processParameterRealProtocol( $r->protocol );

        $c = Customer::findOrFail( $cid );

        $srcVlis = VlanInterface::select( [ 'vli.*' ] )
            ->from( 'vlaninterface AS vli' )
            ->Join( 'virtualinterface AS vi', 'vi.id', 'vli.virtualinterfaceid' )
            ->Join( 'cust AS c', 'c.id', 'vi.custid' )
            ->Join( 'vlan AS v', 'v.id', 'vli.vlanid' )
            ->where( 'c.id', $c->id )
            ->orderBy( 'v.number' )->get()->keyBy( 'id' );

        // Find the possible VLAN interfaces that this customer has for the given IXP
        if( !count( $srcVlis ) ) {
            AlertContainer::push( "There were no interfaces available for the given criteria.", Alert::WARNING );
            return redirect()->back();
        }

        if( ( $svlid = $r->svli ) && isset( $srcVlis[ $svlid ] ) ) {
            /** @var VlanInterface $srcVli */
            $srcVli = $srcVlis[ $svlid ];
        } else {
            $srcVli = $srcVlis[ $srcVlis->first()->id ];
        }

        // is the requested protocol support?
        if( !$srcVli->vlan->private && !$srcVli->ipvxEnabled( $r->protocol ) ) {
            AlertContainer::push( Graph::resolveProtocol( $r->protocol ) . " is not supported on the requested VLAN interface.", Alert::WARNING );
            return redirect()->back();
        }
        // Now find the possible other VLAN interfaces that this customer could exchange traffic with
        // (as well as removing the source vli)
        $dstVlis = VlanInterfaceAggregator::forVlan( $srcVli->vlan );
        unset( $dstVlis[ $srcVli->id ] );

        if( !$dstVlis->count() ) {
            AlertContainer::push( "There were no destination interfaces available for traffic exchange for the given criteria.", Alert::WARNING );
            return redirect()->back();
        }

        if( ( $dvlid = $r->dvli ) && isset( $dstVlis[ $dvlid ] ) ) {
            $dstVli = $dstVlis[ $dvlid ];
        } else {
            $dstVli = false;

            // possibility that we've changed the source VLI in the UI and so the destination dli provided is on another LAN
            if( $dvlid && $otherDstVli = VlanInterface::find( $dvlid ) ) {
                // does this customer have a VLAN interface on the same VLAN as the srcVli?
                foreach( $otherDstVli->virtualInterface->customer->virtualInterfaces as $vi ) {
                    foreach( $vi->vlanInterfaces as $vli ) {
                        if( $srcVli->vlan->id === $vli->vlan->id ) {
                            $dstVli = $vli;
                            break 2;
                        }
                    }
                }
            }

            if( !$dstVli && $r->dvli !== null ) {
                AlertContainer::push( "The customer selected for destination traffic does not have any interfaces on the requested VLAN", Alert::WARNING );
                return redirect()->back();
            }
        }

        // if we have a $dstVli, then remove any VLANs from $srcVlis where both src and dst do not have VLIs on the same VLAN:
        if( $dstVli ) {
            foreach( $srcVlis as $i => $svli ) {
                $haveMatch = false;
                foreach( $dstVli->virtualInterface->customer->virtualInterfaces as $vi ) {
                    foreach( $vi->vlanInterfaces as $dvli ) {
                        if( $svli->vlan->id === $dvli->vlan->id ) {
                            $haveMatch = true;
                            break 2;
                        }
                    }
                }

                if( !$haveMatch ) {
                    unset( $srcVlis[ $i ] );
                }
            }
        }

        // authenticate on one of the graphs
        $graph = App::make( Grapher::class )
            ->p2p( $srcVli, $dstVli ? $dstVli : $dstVlis[ $dstVlis->first()->id ] )
            ->setProtocol( $r->protocol )
            ->setCategory( $r->category )
            ->setPeriod( $r->period );
        $graph->authorise();

        $viewOptions = [
            'c'                => $c,
            'category'         => $r->category,
            'dstVlis'          => $dstVlis,
            'dstVli'           => $dstVli,
            'graph'            => $graph,
            'period'           => $r->period,
            'protocol'         => $r->protocol,
            'showGraphs'       => $showGraphs,
            'showGraphsOption' => $showGraphsOption,
            'srcVlis'          => $srcVlis,
            'srcVli'           => $srcVli,
        ];

        if( $dstVli ) {
            return view( 'statistics/p2p-single', $viewOptions );
        }

        return view( 'statistics/p2p', $viewOptions );

    }

    /**
     * Show daily traffic for customers in a table.
     *
     * @param Request $r
     *
     * @return View
     *
     * @throws
     */
    public function leagueTable( Request $r ): View
    {
        $metrics = [
            'Total'   => 'data',
            'Max'     => 'max',
            'Average' => 'average'
        ];

        $metric = $r->input( 'metric', $metrics['Total'] );
        if( !in_array( $metric, $metrics, true ) ) {
            $metric = $metrics[ 'Total' ];
        }

        $tday = $r->day;
        if( !preg_match( '/^\d\d\d\d\-\d\d\-\d\d$/', $tday ) ) {
            $tday = Carbon::now()->format( 'Y-m-d');
        }

        $day = Carbon::createFromFormat( 'Y-m-d', $tday );
        $category = Graph::processParameterCategory( $r->category );

        return view( 'statistics/league-table' )->with( [
            'metric'       => $metric,
            'metrics'      => $metrics,
            'day'          => $day,
            'category'     => $category,
            'trafficDaily' => TrafficDaily::loadTraffic( $day, $category ),
        ] );
    }

    /**
     * Display graphs for a core bundle
     *
     * @param StatisticsRequest   $r
     * @param CoreBundle          $cb the core bundle
     *
     * @return RedirectResponse|View
     *
     * @throws
     */
    public function coreBundle( StatisticsRequest $r, CoreBundle $cb )
    {
        $category = Graph::processParameterCategory( $r->input( 'category' ) );
        $graph    = App::make( Grapher::class )
            ->coreBundle( $cb )->setCategory( $category )
            ->setSide( $r->input( 'side', 'a' ) );

        // if the customer is authorised, then so too are all of their virtual and physical interfaces:
        try {
            $graph->authorise();
        } catch( AuthorizationException $e ) {
            abort( 403, "You are not authorised to view this graph." );
        }

        return view( 'statistics/core-bundle' )->with([
            "cbs"                   => CoreBundle::active()->get(),
            "cb"                    => $cb,
            "graph"                 => $graph,
            "category"              => $category,
            "categories"            => Auth::check() && Auth::user()->isSuperUser() ? Graph::CATEGORY_DESCS : Graph::CATEGORIES_BITS_PKTS_DESCS,
        ]);
    }


    /**
     * Show utilisation of member ports
     *
     * @param StatisticsRequest $r
     *
     * @return View
     *
     * @throws
     */
    public function utilisation( StatisticsRequest $r ): View
    {
        $metrics = [
            'Max'     => 'max',
            'Total'   => 'data',
            'Average' => 'average'
        ];

        $metric = $r->input( 'metric', $metrics['Max'] );
        if( !in_array( $metric, $metrics, true ) ) {
            $metric = $metrics[ 'Max' ];
        }

        $days =  Arr::flatten( TrafficDailyPhysInt::select( [ 'day' ] )
            ->distinct( 'day' )
            ->orderBy( 'day')->get()->toArray() );

        if( count( $days ) ) {
            $day = $r->day;
            if( !in_array( $day, $days, true ) ) {
                $day = $days[0];
            }
        } else {
            $day = null;
        }

        $vid = false;
        if( $r->vlan && ( $vlan = Vlan::find( $r->vlan ) ) ) {
            $vid = $vlan->id;
        }

        $category = Graph::processParameterCategory( $r->category );
        $period   = Graph::processParameterPeriod( $r->period, Graph::PERIOD_MONTH );

        return view( 'statistics/utilisation' )->with( [
            'metric'       => $metric,
            'metrics'      => $metrics,
            'day'          => $day,
            'days'         => $days,
            'category'     => $category,
            'period'       => $period,
            'tdpis'        => ( $day ? TrafficDailyPhysIntAggregator::loadTraffic( $day, $category, $period, $vid ) : [] ),
            'vlans'        => Vlan::publicOnly()->orderBy('number')->get(),
            'vlan'         => $vid,
        ] );
    }
}