{include file="header.tpl" pageTitle="IXP Manager :: Member Dashboard"}

<div class="yui-g">

<div id="content">

<table class="adminheading" border="0">
<tr>
    <th class="Switch">
        INEX Route Server Details
    </th>
</tr>
</table>

{include file="message.tpl"}

<div id='ajaxMessage'></div>

<div id="overviewMessage">
    {if $rsSessionsEnabled}
        <div class="message message-success">
            You are now enabled to use INEX's robust route server cluster.<br />
            <br />
            Please note that the provisioning system updates the route servers twice daily
            so place allow up to twelve hours for your configuration to become active on
            our systems.<br />
            <br />
            Please see below for configuration details.
        </div>
    {elseif not $rsEnabled}
        <div class="message message-error">
	        You are not using INEX's robust route server cluster. Please <a href="{genUrl controller="dashboard" action="enable-route-server"}">click here to have our provisioning system create sessions</a> for you.
	    </div>
    {else}
	    <div class="message message-success">
	        You are enabled to use INEX's robust route server cluster.
	    </div>
    {/if}
</div>



<h3>Overview</h3>

<p>
Normally on a peering exchange, all connected parties will establish bilateral peering relationships
with each other member port connected to the exchange. As the number of connected parties increases,
it becomes increasingly more difficult to manage peering relationships with members of the exchange.
A typical peering exchange full-mesh eBGP configuration might look something similar to the diagram
on the left hand side.
</p>

<table border="0" align="center">
<tr>
    <td width="354">
        <img src="{genUrl}/images/route-server-peering-fullmesh.png" title=" IXP full mesh peering relationships" alt="[ IXP full mesh peering relationships ]" width="345" height="317" />
    </td>
    <td width="25"></td>
    <td width="354">
        <img src="{genUrl}/images/route-server-peering-rsonly.png" title=" IXP route server peering relationships" alt="[  IXP route server peering relationships ]" width="345" height="317" />
    </td>
</tr>
<tr>
    <td align="center">
        <em> IXP full mesh peering relationships </em>
    </td>
    <td></td>
    <td align="center">
        <em> IXP route server peering relationships</em>
    </td>
</tr>
</table>

<p>
<br />
The full-mesh BGP session relationship scenario requires that each BGP speaker configure and manage
BGP sessions to every other BGP speaker on the exchange. In this example, a full-mesh setup requires
7 BGP sessions per member router, and this increases every time a new member connects to the exchange.
</p>

<p>
However, by using a route server for all peering relationships, the number of BGP sessions per router
stays at two: one for each route server. Clearly this is a more sustainable way of maintaining IXP
peering relationships with a large number of participants.
</p>


<h3>Should I use this service?</h3>

<p>
The INEX route server cluster is aimed at:
</p>

<ul>
    <li> small to medium sized members of the exchange who don't have the time or resources to
         aggressively manage their peering relationships
    </li>
    <li> larger members of the exchange who have an open peering policy, but where it may not
         be worth their while managing peering relationships with smaller members of the exchange.
    </li>
</ul>

<p>
As a rule of thumb: <strong>If you don't have any good reasons not to use the route server cluster, you should probably use it.</strong>
</p>

<p>
The service is designed to be reliable. It operates on two physical servers, each located in a
different data centre. The service is available on all INEX networks (public peering lans #1 and #2,
and voip peering lans #1 and #2), on both ipv4 and ipv6. Each server runs a separate routing daemon
per vlan and per L3 protocol. Should a single BGP server die for some unlikely reason, no other BGP
server is likely to be affected. If one of the physical servers becomes unavailable, the second server
will continue to provide BGP connectivity.
</p>

<p>
INEX has also implemented inbound prefix filtering on its route-server cluster. This uses internet
routing registry data from the RIPE IRR database to allow connected members announce only the address
prefixes which they have registered publicly.
</p>

<p>
INEX uses Quagga running on FreeBSD for its route server cluster. Quagga is widely used at Internet
exchanges for route server clusters (e.g. LINX, AMS-IX, DE-CIX), and has been found to be reliable
in production.
</p>


<h3>How do I use the service?</h3>

<p>
In order to use the service, you should first instruct the route servers to create sessions for you:
</p>

<div id="overviewMessage">
    {if not $rsEnabled}
        <div class="message message-error">
            You are not enabled to use INEX's route server cluster.
            Please <a href="{genUrl controller="dashboard" action="enable-route-server"}">click here to have our provisioning system create sessions</a> for you.
        </div>
    {else}
        <div class="message message-success">
            You are enabled to use INEX's robust route server cluster.
        </div>
    {/if}
</div>

<p>
If enabled, the route servers are set up to accept BGP connections from your router. Once this has
been done, you will need to configure a BGP peering session to the correct internet address. The
IP addresses of the route servers are listed as follows:
</p>

<center>
<div id="routeServerDetailsContainer">
</div>
</center>

<script type="text/javascript">
{literal}
data = {
		rsipaddresses: [
		    ["Public Peering LAN #1","193.242.111.8","2001:7f8:18::8","193.242.111.9","2001:7f8:18::9"],
		    ["Public Peering LAN #2","194.88.240.8","2001:7f8:18:12::8","194.88.240.9","2001:7f8:18:12::9"],
		    ["VoIP Peering LAN #1","194.88.241.8","2001:7f8:18:70::8","194.88.241.9","2001:7f8:18:70::9"],
		    ["VoIP Peering LAN #2","194.88.241.72","2001:7f8:18:72::8","194.88.241.73","2001:7f8:18:72::9"]
		]
};

YAHOO.util.Event.addListener(window, "load", function() {
    rsServerTable = new function() {
        var myColumnDefs = [
            { key:"pl", label:"Peering LAN", sortable:true, resizeable:true },
            { label:"Router Server #1", formatter:YAHOO.widget.DataTable.formatString, children: [
                    { key:"rs1v4", label:"IPv4 Address",sortable:false, resizeable:true },
                    { key:"rs1v6", label:"IPv6 Address",sortable:false, resizeable:true }
                ]
            },
            { label:"Router Server #2", formatter:YAHOO.widget.DataTable.formatString, children: [
                    { key:"rs2v4", label:"IPv4 Address",sortable:false, resizeable:true },
                    { key:"rs2v6", label:"IPv6 Address",sortable:false, resizeable:true }
                ]
            }
        ];

        this.myDataSource = new YAHOO.util.DataSource( data.rsipaddresses );
        this.myDataSource.responseType = YAHOO.util.DataSource.TYPE_JSARRAY;
        this.myDataSource.responseSchema = {
            fields: ["pl","rs1v4","rs1v6","rs2v4","rs2v6"]
        };

        this.myDataTable = new YAHOO.widget.DataTable( "routeServerDetailsContainer", myColumnDefs, this.myDataSource );
    };
});

{/literal}
</script>

<p>
<br /><br />
For Cisco routers, you will need something like the following bgp configuration:
</p>

<pre>
    router bgp 99999
     no bgp enforce-first-as

     ! Route server #1

     neighbor 193.242.111.8 remote-as 43760
     neighbor 193.242.111.8 description INEX Route Server
     address-family ipv4
     neighbor 193.242.111.8 password s00persekr1t
     neighbor 193.242.111.8 activate
     neighbor 193.242.111.8 filter-list 100 out

     ! Route server #2

     neighbor 193.242.111.9 remote-as 43760
     neighbor 193.242.111.9 description INEX Route Server
     address-family ipv4
     neighbor 193.242.111.9 password s00persekr1t
     neighbor 193.242.111.9 activate
     neighbor 193.242.111.9 filter-list 100 out
</pre>

<p>
You should also use <code>route-maps</code> (or <code>distribute-lists</code>) to control
outgoing prefix announcements to allow only the prefixes which you indend to announce.
</p>

<p>
Note that the route server system depends on information in the RIPE IRR database. If you
have not published correct <code>route:</code> and <code>route6:</code> objects in this database,
your prefix announcements will be ignored by the route server and your peers will not route their
traffic to you via the exchange.
</p>

<h3>Community based prefix filtering</h3>

<p>
The INEX route server system also provides well known communities to allow members to
control the distribution of their prefixes. These communities are defined as follows:
</p>

<div id="communitiesContainer">
    <table id="communitiesTable">
        <thead>
        <tr>
            <th>Description</th>
            <th>Community</th>
        </tr>
        </thead>

        <tbody>
        <tr>
            <td>Prevent announcement of a prefix to a peer</td>
            <td><code>0:peer-as</code></td>
        </tr>
        <tr>
            <td>Announce a route to a certain peer</td>
            <td><code>43760:peer-as</code></td>
        </tr>
        <tr>
            <td>Prevent announcement of a prefix to all peers</td>
            <td><code>0:43760</code></td>
        </tr>
        <tr>
            <td>Announce a route to all peers</td>
            <td><code>43760:43760</code></td>
        </tr>
        </tbody>
    </table>
</div>

<script type="text/javascript">
{literal}
var communitiesDataSource = new YAHOO.util.DataSource( YAHOO.util.Dom.get( "communitiesTable" ) );
communitiesDataSource.responseType = YAHOO.util.DataSource.TYPE_HTMLTABLE;

communitiesDataSource.responseSchema = {
    fields: [
        {key:'Description'},
        {key:'Community'}
    ]
};

var communitiesColumnDefs = [
    {key:'Description'},
    {key:'Community'}
];

var communitiesDataTable = new YAHOO.widget.DataTable( "communitiesContainer", communitiesColumnDefs, communitiesDataSource );
{/literal}
</script>

<p>
<br /><br />
So, for example, to instruct the route server to distribute a particular prefix only to
AS64111 and AS64222, the prefix should be tagged with communities: 0:43760, 43760:64111
and 43760:64222.
</p>

<p>
Alternatively, to announce a prefix to all INEX members, excluding AS64333, the prefix
should be tagged with community 0:64333.
</p>

</div>
</div>

{include file="footer.tpl"}