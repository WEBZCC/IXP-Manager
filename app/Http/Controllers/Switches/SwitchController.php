<?php

namespace IXP\Http\Controllers\Switches;

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

use Auth, DateTime, Former, Route;

use Illuminate\Http\{
    Request,
    RedirectResponse
};

use Illuminate\View\View;

use IXP\Models\{
    Cabinet,
    Infrastructure,
    Location,
    PhysicalInterface,
    Switcher,
    SwitchPort,
    User,
    Vendor,
    Vlan
};

use IXP\Rules\IdnValidate;

use IXP\Http\Requests\Switches\{
    StoreBySmtp as StoreBySmtpRequest
};

use IXP\Utils\Http\Controllers\Frontend\EloquentController;

use IXP\Utils\View\Alert\{
    Alert,
    Container as AlertContainer
};

use OSS_SNMP\{
    Exception as SNMPException,
    Platform,
    SNMP
};

/**
 * Switch Controller
 * @author     Barry O'Donovan <barry@islandbridgenetworks.ie>
 * @author     Yann Robin <yann@islandbridgenetworks.ie>
 * @category   Controller
 * @copyright  Copyright (C) 2009 - 2020 Internet Neutral Exchange Association Company Limited By Guarantee
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class SwitchController extends EloquentController
{
    /**
     * The object being created / edited
     * @var Switcher
     */
    protected $object = null;

    /**
     * This function sets up the frontend controller
     */
    public function feInit(): void
    {
        $this->feParams         = (object)[
            'entity'            => Switcher::class,
            'pagetitle'         => 'Switches',
            'titleSingular'     => 'Switch',
            'nameSingular'      => 'a switch',
            'listOrderBy'       => 'name',
            'listOrderByDir'    => 'ASC',
            'viewFolderName'    => 'switches',
            'documentation'     => 'https://docs.ixpmanager.org/usage/switches/',

            'listColumns'       => [
                'id'        => [
                    'title' => 'UID',
                    'display' => false
                ],
                'name'           => 'Name',
                'cabinet'  => [
                    'title'      => 'Rack',
                    'type'       => self::$FE_COL_TYPES[ 'HAS_ONE' ],
                    'controller' => 'rack',
                    'action'     => 'view',
                    'idField'    => 'cabinetid'
                ],
                'vendor'  => [
                    'title'      => 'Vendor',
                    'type'       => self::$FE_COL_TYPES[ 'HAS_ONE' ],
                    'controller' => 'vendor',
                    'action'     => 'view',
                    'idField'    => 'vendorid'
                ],
                'infrastructure' => 'Infrastructure',
                'active'       => [
                    'title'    => 'Active',
                    'type'     => self::$FE_COL_TYPES[ 'YES_NO' ]
                ],
                'model'          => 'Model',
                'ipv4addr'       => 'IPv4 Address',
            ]
        ];

        // display the same information in the view as the list
        $this->feParams->viewColumns = array_merge(
            $this->feParams->listColumns,
            [
                'ipv6addr'       => 'IPv6 Address',
                'hostname'       => 'Hostname',
                'snmppasswd'     => 'SNMP Community',
                'os'             => 'OS',
                'osVersion'      => 'OS Version',
                'osDate'         => [
                    'title'      => 'OS Date',
                    'type'       => self::$FE_COL_TYPES[ 'DATETIME' ]
                ],
                'lastPolled'         => [
                    'title'      => 'Last Polled',
                    'type'       => self::$FE_COL_TYPES[ 'DATETIME' ]
                ],
                'serialNumber'   => 'Serial Number',
                'mauSupported'   => [
                    'title'    => 'MAU Supported',
                    'type'     => self::$FE_COL_TYPES[ 'YES_NO_NULL' ]
                ],
                'asn'            => 'ASN',
                'loopback_ip'    => 'Loopback IP',
                'loopback_name'  => 'Loopback Name',
                'mgmt_mac_address' => 'Mgmt MAC Address',
                'notes'       => [
                    'title'         => 'Notes',
                    'type'          => self::$FE_COL_TYPES[ 'PARSDOWN' ]
                ]
            ]
        );

        // phpunit / artisan trips up here without the cli test:
        if( php_sapi_name() !== 'cli' ) {
            // custom access controls:
            switch( Auth::check() ? Auth::user()->getPrivs() : User::AUTH_PUBLIC ) {
                case User::AUTH_SUPERUSER:
                    break;
                case User::AUTH_CUSTUSER || User::AUTH_CUSTADMIN:
                    switch( Route::current()->getName() ) {
                        case 'switch@configuration':
                            break;

                        default:
                            $this->unauthorized();
                    }
                    break;
                default:
                    $this->unauthorized();
            }
        }
    }

    /**
     * Additional routes
     *
     * @param string $route_prefix
     *
     * @return void
     */
    protected static function additionalRoutes( string $route_prefix ): void
    {
        // NB: this route is marked as 'read-only' to disable normal CRUD operations. It's not really read-only.
        Route::group( [  'prefix' => $route_prefix ], function() {
            Route::get(  'add-by-snmp',         'Switches\SwitchController@addBySnmp'           )->name( "switch@add-by-snmp" );
            Route::get(  'port-report/{switch}','Switches\SwitchController@portReport'          )->name( "switch@port-report" );
            Route::get(  'configuration',       'Switches\SwitchController@configuration'       )->name( "switch@configuration" );
            Route::post(  'store-by-snmp',      'Switches\SwitchController@storeBySmtp'         )->name( "switch@store-by-snmp" );
        });
    }

    public function list( Request $r  ) : View
    {
        if( ( $showActiveOnly = $r->activeOnly ) !== null  ) {
            $r->session()->put( "switch-list-active-only", $showActiveOnly );
        } else if( $r->session()->exists( "switch-list-active-only" ) ) {
            $showActiveOnly = $r->session()->get( "switch-list-active-only" );
        } else {
            $showActiveOnly = false;
        }

        if( $vtype = $r->vtype ) {
            $r->session()->put( "switch-list-vtype", $vtype );
        } elseif( $r->session()->exists( "switch-list-vtype" ) ) {
            $vtype = $r->session()->get( "switch-list-vtype" );
        } else {
            $r->session()->remove( "switch-list-vtype" );
            $vtype = Switcher::VIEW_MODE_DEFAULT;
        }

        if( $r->infra ) {
            if(  $infra = Infrastructure::find( $r->infra ) ) {
                $r->session()->put( "switch-list-infra", $infra );
            } else {
                $r->session()->remove( "switch-list-infra" );
                $infra = false;
            }
        } else if( $r->session()->exists( "switch-list-infra" ) ) {
            $infra = $r->session()->get( "switch-list-infra" );
        } else {
            $infra = false;
        }

        if( $vtype === Switcher::VIEW_MODE_OS ) {
            $this->setUpOsView();
        } else if( $vtype === Switcher::VIEW_MODE_L3 ){
            $this->setUpL3View();
        }

        $this->data[ 'params' ][ 'activeOnly' ]         = $showActiveOnly;
        $this->data[ 'params' ][ 'vtype' ]              = $vtype;
        $this->data[ 'params' ][ 'infra' ]              = $infra;
        $this->data[ 'rows' ] = $this->listGetData();

        $this->listIncludeTemplates();
        $this->preList();

        return $this->display( 'list' );
    }

    /**
     * Set Up the the table to display the OS VIEW
     *
     * @return bool
     */
    private function setUpOsView(): bool
    {
        $this->feParams->listColumns = [
            'id'        =>
                [ 'title' => 'UID',
                  'display' => false
                ],
            'name'           => 'Name',
            'vendor'  => [
                'title'      => 'Vendor',
                'type'       => self::$FE_COL_TYPES[ 'HAS_ONE' ],
                'controller' => 'vendor',
                'action'     => 'view',
                'idField'    => 'vendorid'
            ],
            'model'          => 'Model',
            'os'             => 'OS',
            'osVersion'      => 'OS Version',
            'serialNumber'   => 'Serial Number',
            'osDate'         => [
                'title'      => 'OS Date',
                'type'       => self::$FE_COL_TYPES[ 'DATETIME' ]
            ],
            'lastPolled'         => [
                'title'      => 'Last Polled',
                'type'       => self::$FE_COL_TYPES[ 'DATETIME' ]
            ],
            'active'       => [
                'title'    => 'Active',
                'type'     => self::$FE_COL_TYPES[ 'YES_NO' ]
            ]
        ];
        return true;
    }

    /**
     * Set Up the the table to display the OS VIEW
     *
     * @return bool
     */
    private function setUpL3View(): bool
    {
        $this->feParams->listColumns = [
            'id'                => [
                'title' => 'UID',
                'display' => false
            ],
            'name'              => 'Name',
            'hostname'          => 'Hostname',
            'asn'               => 'ASN',
            'loopback_ip'       => 'Loopback',
            'mgmt_mac_address'  => 'Mgmt Mac',
            'active'            => [
                'title'    => 'Active',
                'type'     => self::$FE_COL_TYPES[ 'YES_NO' ]
            ]
        ];

        return true;
    }

    /**
     * Provide array of rows for the list action and view action
     *
     * @param int $id The `id` of the row to load for `view` action`. `null` if `listAction`
     *
     * @return array
     */
    protected function listGetData( $id = null ): array
    {
        return Switcher::getFeList( $this->feParams, $id, $this->data );
    }

    /**
     * Display the form to create an object
     *
     * @return array
     */
    protected function createPrepareForm(): array
    {
        return [
            'object'            => $this->object,
            'addBySnmp'         => request()->old( 'add_by_snnp', false ),
            'preAddForm'        => false,
            'cabinets'          => Cabinet::getListAsArray(),
            'infra'             => Infrastructure::getListAsArray(),
            'vendors'           => Vendor::getListAsArray(),
        ];
    }

    /**
     * Display the form to edit an object
     *
     * @param   int $id ID of the row to edit
     *
     * @return array
     */
    protected function editPrepareForm( $id = null ): array
    {
        $this->object = Switcher::findOrFail( $id );

        Former::populate([
            'name'              => request()->old( 'name',                  $this->object->name ),
            'hostname'          => request()->old( 'hostname',              $this->object->hostname ),
            'cabinetid'         => request()->old( 'cabinetid',             $this->object->cabinetid ),
            'infrastructure'    => request()->old( 'infrastructure',        $this->object->infrastructure ),
            'ipv4addr'          => request()->old( 'ipv4addr',              $this->object->ipv4addr ),
            'ipv6addr'          => request()->old( 'ipv6addr',              $this->object->ipv6addr ),
            'snmppasswd'        => request()->old( 'snmppasswd',            $this->object->snmppasswd ),
            'vendorid'          => request()->old( 'vendorid',              $this->object->vendorid ),
            'model'             => request()->old( 'model',                 $this->object->model ),
            'active'            => request()->old( 'active',                ( $this->object->active ? 1 : 0 ) ),
            'asn'               => request()->old( 'asn',                   $this->object->asn ),
            'loopback_ip'       => request()->old( 'loopback_ip',           $this->object->loopback_ip ),
            'loopback_name'     => request()->old( 'loopback_name',         $this->object->loopback_name ),
            'mgmt_mac_address'  => request()->old( 'mgmt_mac_address',      $this->object->mgmt_mac_address ) ,
            'notes'             => request()->old( 'notes',                 $this->object->notes ) ,
        ]);

        return [
            'object'            => $this->object,
            'addBySnmp'         => request()->old( 'add_by_snnp', false ),
            'preAddForm'        => false,
            'cabinets'          => Cabinet::getListAsArray(),
            'infra'             => Infrastructure::getListAsArray(),
            'vendors'           => Vendor::getListAsArray()
        ];
    }

    /**
     * Display the form to add by SNMP
     *
     * @return View
     */
    public function addBySnmp(): View
    {
        // wipe any preexisting cached switch platform entry:
        session()->remove( "snmp-platform" );

        $this->addEditSetup();
        return $this->display( 'add-by-smtp-form' );
    }

    /**
     * Process the hostname and SNMP community, poll the switch and set up the proper add/edit form
     *
     * @param StoreBySmtpRequest $request
     *
     * @return bool|RedirectResponse|View
     *
     * @throws
     */
    public function storeBySmtp( StoreBySmtpRequest $request )
    {
        $vendorid = null;

        // can we get it by SNMP and discover some basic details?
        try {
            $snmp   = new SNMP( $request->hostname, $request->snmppasswd );
            $vendor = $snmp->getPlatform()->getVendor();

            // Store the platform in session to be able to get back the information when we will create the object
            $request->session()->put( "snmp-platform", $snmp->getPlatform() );

            if( $v = Vendor::find( $vendor ) ) {
                $vendorid = $v->id;
            }
        } catch( SNMPException $e ) {
            $snmp = null;
        }

        $sp = strpos( $request->hostname, '.' );

        Former::populate([
            'name'              => substr( $request->input( 'hostname' ), 0, $sp ? $sp : strlen( $request->input( 'hostname' ) ) ),
            'snmppasswd'        => $request->snmppasswd,
            'hostname'          => $request->hostname,
            'ipv4addr'          => resolve_dns_a(    $request->hostname ) ?? '',
            'ipv6addr'          => resolve_dns_aaaa( $request->hostname ) ?? '',
            'vendorid'          => $vendorid ?? "",
            'model'             => $snmp ? $snmp->getPlatform()->getModel() : "",
        ]);

        $this->feParams->titleSingular = "Switch via SNMP";
        $this->addEditSetup();

        $this->data[ 'params' ]['isAdd']        = true;
        $this->data[ 'params' ]['addBySnmp']    = true;
        $this->data[ 'params' ]['preAddForm']   = false;
        $this->data[ 'params' ]['object']       = null;
        $this->data[ 'params' ]['cabinets']     = Cabinet::getListAsArray();
        $this->data[ 'params' ]['infra']        = Infrastructure::getListAsArray();
        $this->data[ 'params' ]['vendors']      = Vendor::getListAsArray();

        return $this->display( 'edit' );
    }

    /**
     * Check if the form is valid
     *
     * @param $request
     */
    public function checkForm( Request $request ): void
    {
        $request->validate( [
            'name' => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use( $request ) {
                    $switcher = Switcher::whereName( $value )->get()->first();
                    if( $switcher && $switcher->exists() && $switcher->id !== (int)$request->id ) {
                        return $fail( 'The name must be unique.' );
                    }
                },
            ],
            'hostname' => [
                'required', 'string', 'max:255', new IdnValidate(),
                function ($attribute, $value, $fail) use( $request ) {
                    $switcher = Switcher::whereHostname( $value )->get()->first();
                    if( $switcher && $switcher->exists() && $switcher->id !== (int)$request->id ) {
                        return $fail( 'The hostname must be unique.' );
                    }
                },
            ],
            'cabinetid'            => [ 'required', 'integer',
                function( $attribute, $value, $fail ) {
                    if( !Cabinet::whereId( $value )->exists() ) {
                        return $fail( 'Cabinet is invalid / does not exist.' );
                    }
                }
            ],
            'infrastructure'            => [ 'required', 'integer',
                function( $attribute, $value, $fail ) {
                    if( !Infrastructure::whereId( $value )->exists() ) {
                        return $fail( 'Infrastructure is invalid / does not exist.' );
                    }
                }
            ],
            'snmppasswd'                => 'nullable|string|max:255',
            'vendorid'            => [ 'required', 'integer',
                function( $attribute, $value, $fail ) {
                    if( !Vendor::whereId( $value )->exists() ) {
                        return $fail( 'Vendor is invalid / does not exist.' );
                    }
                }
            ],
            'ipv4addr'                  => 'nullable|ipv4',
            'ipv6addr'                  => 'nullable|ipv6',
            'model'                     => 'nullable|string|max:255',
            'asn'                       => 'nullable|integer|min:1',
            'loopback_ip' => [
                'nullable', 'string', 'max:255',
                function ($attribute, $value, $fail) use( $request ) {
                    $switcher = Switcher::whereLoopbackIp( $value )->get()->first();
                    if( $switcher && $switcher->exists() && $switcher->id !== (int)$request->id ) {
                        return $fail( 'The loopback IP must be unique.' );
                    }
                },
            ],
            'loopback_name'             => 'nullable|string|max:255',
            'mgmt_mac_address'          => 'nullable|string|max:17|regex:/^[a-f0-9:\.\-]{12,17}$/i',
        ] );
    }

    /**
     * Add some extra attributes to the object
     *
     * @param $request
     *
     * @return void
     *
     * @throws
     */
    private function extraAttributes( Request $request ): void
    {
        if( $request->session()->exists( "snmp-platform" ) ) {
            /** @var Platform $platform */
            $platform = $request->session()->get( "snmp-platform" );
            $osDate = null;

            if( $platform->getOsDate() instanceof DateTime ) {
                $osDate = $platform->getOsDate();
            } else if( is_string( $platform->getOsDate() ) ) {
                $osDate = new DateTime( $platform->getOsDate() );
            }

            $this->object->os =             $platform->getOs();
            $this->object->osDate =         $osDate;
            $this->object->osVersion =      $platform->getOsVersion();
            $this->object->serialNumber =   $platform->getSerialNumber();
            $this->object->save();
            $request->session()->remove( "snmp-platform" );
        }

        if( $request->add_by_snnp ) {
            $this->object->lastPolled =   now();
            $this->object->save();
        }
    }
    /**
     * Function to do the actual validation and storing of the submitted object.
     *
     * @param Request $request
     * @return bool|RedirectResponse
     *
     * @throws
     */
    public function doStore( Request $request )
    {
        $this->checkForm( $request );

        if( $request->asn && Switcher::where( 'asn', $request->asn )->exists() ) {
            AlertContainer::push( "Note: this ASN is already is use by at least one other switch. If you are using eBGP, this may cause prefixes to be black-holed.", Alert::WARNING );
        }

        $this->object = Switcher::create( array_merge( $request->except( 'mgmt_mac_address' ),
            [
                'mgmt_mac_address' => preg_replace( "/[^a-f0-9]/i", '', strtolower( $request->mgmt_mac_address ) )
            ])
        );

        $this->extraAttributes( $request );

        return true;
    }

    /**
     * Function to do the actual validation and editing of the submitted object.
     *
     * @param Request $request
     * @param int $id
     *
     * @return bool|RedirectResponse
     *
     * @throws
     */
    public function doUpdate( Request $request, int $id )
    {
        $this->object = Switcher::findOrFail( $id );

        $this->checkForm( $request );

        if( $request->asn && Switcher::where( 'asn', $request->asn )->where( 'id', '!=', $this->object->id )->exists() ){
            AlertContainer::push( "Note: this ASN is already is use by at least one other switch. If you are using eBGP, this may cause prefixes to be black-holed.", Alert::WARNING );
        }

        $this->object->update( array_merge( $request->except( 'mgmt_mac_address' ),
            [
                'mgmt_mac_address' => preg_replace( "/[^a-f0-9]/i", '', strtolower( $request->mgmt_mac_address ) )
            ])
        );

        $this->extraAttributes( $request );

        return true;
    }


    /**
     * Overriding optional method to clear cached entries:
     *
     * @param string $action Either 'add', 'edit', 'delete'
     * @return bool
     */
    protected function postFlush( string $action ): bool
    {
        // wipe cached entries, this is created in Switcher::getAndCache()
        Switcher::clearCacheAll();
        return true;
    }

    /**
     * @inheritdoc
     */
    protected function preDelete() : bool
    {
        $okay = $okayPPP = true;

        if( $this->object->getPhysicalInterfaces()->count() ) {
                $okay = false;
                AlertContainer::push( "Cannot delete switch: there are switch ports assigned to one or more physical interfaces.", Alert::DANGER );
        }

        if( $this->object->getPatchPanelPorts()->count() ) {
            $okay = false;
            AlertContainer::push( "Cannot delete switch: there are switch ports assigned to patch panel ports", Alert::DANGER );
        }

        if( $okay ){
            $this->object->switchPorts()->delete();
        }

        return $okay;
    }


    /**
     * Display the Port report for a switch
     *
     * @param Switcher $switch ID for the switch
     *
     * @return view
     */
    public function portReport( Switcher $switch ) : View
    {
        $allPorts   = SwitchPort::getAllPortsForSwitch( $switch->id, [] , [], false );
        $ports      = SwitchPort::getAllPortsAssignedToPIForSwitch( $switch->id );

        $matchingValues = array_uintersect($ports, $allPorts , function ($val1, $val2){
            return strcmp($val1['name'], $val2['name']);
        });

        $diffValues = array_udiff($allPorts, $ports , function ($val1, $val2){
            return strcmp($val1['name'], $val2['name']);
        });

        return view( 'switches/port-report' )->with([
            'switches'                  => Switcher::getListAsArray(),
            's'                         => $switch,
            'ports'                     => array_merge( $matchingValues, $diffValues ),
        ]);
    }

    /**
     * Display the switch configurations
     *
     * @param Request $r
     *
     * @return view
     *
     * @throws
     */
    public function configuration( Request $r ) : View
    {
        $speeds = PhysicalInterface::getAllSpeed();

        $switch = $infra = $location = $speed = $vlan = false;
        if( $r->switch !== null ) {
            if(  $switch = Switcher::find( $r->switch ) ) {
                $r->session()->put( "switch-configuration-switch", $switch );
            } else {
                $r->session()->remove( "switch-configuration-switch" );
                $switch = false;
            }
        } else if( $r->session()->exists( "switch-configuration-switch" ) ) {
            $switch = $r->session()->get( "switch-configuration-switch" );
        }

        if( $r->infra !== null ) {
            if(  $infra = Infrastructure::find( $r->infra ) ) {
                $r->session()->put( "switch-configuration-infra", $infra );
            } else {
                $r->session()->remove( "switch-configuration-infra" );
                $infra = false;
            }
        } else if( $r->session()->exists( "switch-configuration-infra" ) ) {
            $infra = $r->session()->get( "switch-configuration-infra" );
        }

        if( $r->location !== null ) {
            if( $location = Location::find( $r->location ) ) {
                $r->session()->put( "switch-configuration-location", $location );
            } else {
                $r->session()->remove( "switch-configuration-location" );
                $location = false;
            }
        } else if( $r->session()->exists( "switch-configuration-location" ) ) {
            $location = $r->session()->get( "switch-configuration-location" );
        }

        if( $r->speed !== null ) {
            if( in_array( $r->speed, $r->speed, false ) ) {
                $r->session()->put( "switch-configuration-speed", $r->speed );
            } else {
                $r->session()->remove( "switch-configuration-speed" );
                $speed = false;
            }
        } else if( $r->session()->exists( "switch-configuration-speed" ) ) {
            $speed = $r->session()->get( "switch-configuration-speed" );
        }

        if( $r->vlan !== null ) {
            $vlan = Vlan::find( $r->vlan );
        }

        if( $switch || $infra || $location ) {
            $summary = ":: Connections details for ";

            if( $switch ) {
                $summary .= $switch->name . " (on " . $switch->infrastructure->name . " at " . $switch->cabinet->location->name . ")";
            } elseif( $infra && $location ){
                $summary .= $infra->name . " at " . $location->name;
            } elseif( $infra ){
                $summary .= $infra->name;
            } elseif( $location ){
                $summary .= $location->name;
            }
        } else{
            $summary = false;
        }

        $config = Switcher::getConfiguration(
            $switch ? $switch->id : null,
            $infra ? $infra->id : null,
            $location ? $location->id : null,
            $speed,
            $vlan ? $vlan->id : null,
            $r->input( 'rs-client' )    ? true : false,
            $r->input( 'ipv6-enabled' ) ? true : false
        );

        return view( 'switches/configuration' )->with([
            's'                         => $switch,
            'speed'                     => $speed,
            'infra'                     => $infra,
            'location'                  => $location,
            'summary'                   => $summary,
            'speeds'                    => $speeds,
            'infras'                    => $switch ? [ $switch->infrastructure->id     => $switch->infrastructure->name     ] : Infrastructure::getListAsArray(),
            'locations'                 => $switch ? [ $switch->cabinet->location->id  => $switch->cabinet->location->name  ] : Location::getListAsArray(),
            'switches'                  => Switcher::getByLocationInfrastructureSpeed( $infra, $location, $speed ),
            'config'                    => $config,
        ]);
    }
}
