<?php

/*
 * Copyright (C) 2009 - 2019 Internet Neutral Exchange Association Company Limited By Guarantee.
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
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GpNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License v2.0
 * along with IXP Manager.  If not, see:
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

namespace Repositories;

use Doctrine\ORM\EntityRepository;

use D2EM, Exception;

use Entities\{
    BGPSessionData  as BGPSessionDataEntity,
    Customer        as CustomerEntity,
    CustomerToUser  as CustomerToUserEntity,
    CoreBundle      as CoreBundleEntity,
};


/**
 * CustomerRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class Customer extends EntityRepository
{
    /**
     * DQL for selecting customers that are current in terms of `datejoin` and `dateleave`
     *
     * @var string DQL for selecting customers that are current in terms of `datejoin` and `dateleave`
     */
    const DQL_CUST_CURRENT = "c.datejoin <= CURRENT_DATE() AND ( c.dateleave IS NULL OR c.dateleave >= CURRENT_DATE() )";
    
    /**
     * DQL for selecting customers that are active (i.e. not suspended)
     *
     * @var string DQL for selecting customers that are active (i.e. not suspended)
     */
    const DQL_CUST_ACTIVE = "c.status IN ( 1, 2 )";
    
    /**
     * DQL for selecting all customers except for internal / dummy customers
     *
     * @var string DQL for selecting all customers except for internal / dummy customers
     */
    const DQL_CUST_EXTERNAL = "c.type != 3";
    
    /**
     * DQL for selecting all trafficing customers
     *
     * @var string DQL for selecting all trafficing customers
     */
    const DQL_CUST_TRAFFICING = "c.type != 2";
    
    /**
     * DQL for selecting all "connected" customers
     *
     * @var string DQL for selecting all "connected" customers
     */
    const DQL_CUST_CONNECTED = "c.status = 1";
    
    
    /**
     * Utility function to provide a count of different customer types as `type => count`
     * where type is as defined in Entities\Customer::$CUST_TYPES_TEXT
     *
     * @return array Number of customers of each customer type as `[type] => count`
     */
    public function getTypeCounts()
    {
        $atypes = $this->getEntityManager()->createQuery(
            "SELECT c.type AS ctype, COUNT( c.type ) AS cnt FROM Entities\\Customer c
                WHERE " . self::DQL_CUST_CURRENT . " AND " . self::DQL_CUST_ACTIVE . "
                GROUP BY c.type"
        )->getArrayResult();
        
        $types = [];
        foreach( $atypes as $t )
            $types[ $t['ctype'] ] = $t['cnt'];
    
        return $types;
    }
    
    
    /**
     * Utility function to provide a array of all active and current customers.
     *
     * @param bool $asArray If `true`, return an associative array, else an array of Customer objects
     * @param bool $trafficing If `true`, only include trafficing customers (i.e. no associates)
     * @param bool $externalOnly If `true`, only include external customers (i.e. no internal types)
     * @param \Entities\IXP $ixp Limit to a specific IXP
     * @return array
     */
    public function getCurrentActive( $asArray = false, $trafficing = false, $externalOnly = false, $ixp = false )
    {
        $dql = "SELECT c FROM \\Entities\\Customer c
                WHERE " . self::DQL_CUST_CURRENT . " AND " . self::DQL_CUST_ACTIVE;

        if( $trafficing )
            $dql .= " AND " . self::DQL_CUST_TRAFFICING . " AND " . self::DQL_CUST_CONNECTED;
        
        if( $externalOnly )
            $dql .= " AND " . self::DQL_CUST_EXTERNAL;

        if( $ixp !== false )
            $dql .= " AND :ixp MEMBER OF c.IXPs";

        $dql .= " ORDER BY c.name ASC";
        
        $custs = $this->getEntityManager()->createQuery( $dql );

        if( $ixp !== false )
            $custs->setParameter( 'ixp', $ixp );
        
        return $asArray ? $custs->getArrayResult() : $custs->getResult();
    }

    /**
     * Utility function to provide a array of all current associate customers
     *
     * @param bool $asArray If `true`, return an associative array, else an array of Customer objects
     * @return array
     */
    public function getCurrentAssociate( $asArray = false )
    {
        $dql = "SELECT c FROM Entities\\Customer c
                WHERE " . self::DQL_CUST_CURRENT . " AND c.type = " . CustomerEntity::TYPE_ASSOCIATE;

        $dql .= " ORDER BY c.name ASC";

        $custs = $this->getEntityManager()->createQuery( $dql );

        return $asArray ? $custs->getArrayResult() : $custs->getResult();
    }

    /**
     * Utility function to provide a array of all members connected to the exchange (including at 
     * least one physical interface with status 'CONNECTED').
     *
     * @param bool $asArray If `true`, return an associative array, else an array of Customer objects
     * @param bool $externalOnly If `true`, only include external customers (i.e. no internal types)
     * @return array
     */
    public function getConnected( $asArray = false, $externalOnly = false )
    {
        $dql = "SELECT c FROM \\Entities\\Customer c
                    LEFT JOIN c.VirtualInterfaces vi
                    LEFT JOIN vi.PhysicalInterfaces pi
                WHERE " . self::DQL_CUST_CURRENT . " AND " . self::DQL_CUST_TRAFFICING . " 
                    AND pi.status = " . \Entities\PhysicalInterface::STATUS_CONNECTED;
        
        if( $externalOnly )
            $dql .= " AND " . self::DQL_CUST_EXTERNAL;

        $dql .= " ORDER BY c.name ASC";
        
        $custs = $this->getEntityManager()->createQuery( $dql );

        return $asArray ? $custs->getArrayResult() : $custs->getResult();
    }
    
    /**
     * Takes an array of \Entities\Customer and filters them for a given infrastructure.
     *
     * Often used by passing the return fo `getCurrentActive()`
     *
     * @param \Entities\Customer[] $customers
     * @param \Entities\Infrastructure $infra
     * @return \Entities\Customer[]
     */
    public function filterForInfrastructure( $customers, $infra )
    {
        $filtered = [];
        
        foreach( $customers as $c )
        {
            foreach( $c->getVirtualInterfaces() as $vi )
            {
                foreach( $vi->getPhysicalInterfaces() as $pi )
                {
                    if( $pi->getSwitchport()->getSwitcher()->getInfrastructure() == $infra )
                    {
                        $filtered[] = $c;
                        continue 3;
                    }
                }
            }
        }
        
        return $filtered;
    }
    
    
    /**
     * Return an array of all customer names where the array key is the customer id.
     *
     * @param bool $activeOnly If true, only return active / current customers
     * @return array An array of all customer names with the customer id as the key.
     */
    public function getNames( $activeOnly = false )
    {
        $acusts = $this->getEntityManager()->createQuery(
            "SELECT c.id AS id, c.name AS name "
                . "FROM Entities\\Customer c "
                . ( $activeOnly ? "WHERE " . self::DQL_CUST_CURRENT : '' ) 
                . "ORDER BY name ASC"
        )->getResult();
        
        $customers = [];
        foreach( $acusts as $c )
            $customers[ $c['id'] ] = $c['name'];
        
        return $customers;
    }

    /**
     * Return an array of all customers who are not related with a given IXP.
     *
     * @param \Entities\IXP $ixp IXP for filtering results
     * @return \Entities\Customerp[ An array of customers
     */
    public function getNamesNotAssignedToIXP( $ixp )
    {
        return $this->getEntityManager()->createQuery(
                "SELECT c
                    FROM Entities\\Customer c
                    WHERE ?1 NOT MEMBER OF c.IXPs
                    ORDER BY c.name ASC" )
            ->setParameter( 1, $ixp )
            ->getResult();
    }

    /**
     * Return an array of all reseller names where the array key is the customer id.
     *
     * @return array An array of all reseller names with the customer id as the key.
     */
    public function getResellerNames(): array
    {
        $acusts = $this->getEntityManager()->createQuery(
            "SELECT c.id AS id, c.name AS name FROM Entities\\Customer c WHERE c.isReseller = 1 ORDER BY c.name ASC"
        )->getResult();
        
        $customers = [];
        foreach( $acusts as $c )
            $customers[ $c['id'] ] = $c['name'];
        
        return $customers;
    }

    /**
     * Return an array of all resold customers names where the array key is the customer id.
     *
     * @param int $id Customers id to get resold customer names list
     * @return array An array of all reseller names with the customer id as the key.
     */
    public function getResoldCustomerNames( $custid = false )
    {
        $query = "SELECT c.id AS id, c.name AS name FROM Entities\\Customer c WHERE c.Reseller IS NOT NULL";
        if( $custid )
            $query .= " AND c.Reseller = " . $custid;
        $query .= " ORDER BY c.name ASC";
        
        $acusts = $this->getEntityManager()
                    ->createQuery( $query )
                    ->getResult();
        
        $customers = [];
        foreach( $acusts as $c )
            $customers[ $c['id'] ] = $c['name'];
        
        return $customers;
    }
    
    /**
     * Return an array of the must recent customers (who are current,
     * external, active and trafficing).
     *
     * @return array An array of all customer names with the customer id as the key.
     */
    public function getRecent()
    {
        return $this->getEntityManager()->createQuery(
                "SELECT c
                 FROM \\Entities\\Customer c
                     LEFT JOIN c.VirtualInterfaces vi
                     LEFT JOIN vi.PhysicalInterfaces pi
                 WHERE " . self::DQL_CUST_CURRENT . " AND " . self::DQL_CUST_ACTIVE . "
                     AND " . self::DQL_CUST_EXTERNAL . " AND " . self::DQL_CUST_TRAFFICING . "
                     AND pi.status = " . \Entities\PhysicalInterface::STATUS_CONNECTED . "
                ORDER BY c.datejoin DESC"
            )
            ->getResult();
    }
    
    /**
     * Return an array of the customer's peers as listed in the `PeeringManager`
     * table.
     *
     * @param $cid int The customer ID
     * @return array An array of all the customer's PeeringManager entries
     */
    public function getPeers( $cid )
    {
        $tmpPeers = $this->getEntityManager()->createQuery(
            "SELECT pm.id AS id, c.id AS custid, p.id AS peerid,
                pm.email_last_sent AS email_last_sent, pm.emails_sent AS emails_sent,
                pm.peered AS peered, pm.rejected AS rejected, pm.notes AS notes,
                pm.created AS created, pm.updated AS updated
        
             FROM \\Entities\\PeeringManager pm
                 LEFT JOIN pm.Customer c
                 LEFT JOIN pm.Peer p
        
             WHERE c.id = ?1"
        )
        ->setParameter( 1, $cid )
        ->getArrayResult();

        $peers = [];
        foreach( $tmpPeers as $p )
            $peers[ $p['peerid'] ] = $p;
        
        return $peers;
    }
    
    
    /**
     * Utility function to load all customers suitable for inclusion in the peering manager
     *
     */
    public function getForPeeringManager()
    {
        $customers = $this->getEntityManager()->createQuery(
                "SELECT c
        
                 FROM \\Entities\\Customer c

                 WHERE " . self::DQL_CUST_ACTIVE . " AND " . self::DQL_CUST_CURRENT . "
                     AND " . self::DQL_CUST_EXTERNAL . " AND " . self::DQL_CUST_TRAFFICING . "

                ORDER BY c.name ASC"
        
            )->getResult();
        
    
        $custs = array();
    
        foreach( $customers as $c )
        {
            $custs[ $c->getAutsys() ] = [];
    
            $custs[ $c->getAutsys() ]['id']            = $c->getId();
            $custs[ $c->getAutsys() ]['name']          = $c->getName();
            $custs[ $c->getAutsys() ]['shortname']     = $c->getShortname();
            $custs[ $c->getAutsys() ]['autsys']        = $c->getAutsys();
            $custs[ $c->getAutsys() ]['maxprefixes']   = $c->getMaxprefixes();
            $custs[ $c->getAutsys() ]['peeringemail']  = $c->getPeeringemail();
            $custs[ $c->getAutsys() ]['peeringpolicy'] = $c->getPeeringpolicy();
    
            $custs[ $c->getAutsys() ]['vlaninterfaces'] = array();
    
            foreach( $c->getVirtualInterfaces() as $vi )
            {
                foreach( $vi->getVlanInterfaces() as $vli )
                {
                    if( !isset( $custs[ $c->getAutsys() ]['vlaninterfaces'][ $vli->getVlan()->getNumber() ] ) )
                    {
                        $custs[ $c->getAutsys() ]['vlaninterfaces'][ $vli->getVlan()->getNumber() ] = [];
                        $cnt = 0;
                    }
                    else
                        $cnt = count( $custs[ $c->getAutsys() ]['vlaninterfaces'][ $vli->getVlan()->getNumber() ] );
                        
                    $custs[ $c->getAutsys() ]['vlaninterfaces'][ $vli->getVlan()->getNumber() ][ $cnt ]['ipv4enabled'] = $vli->getIpv4enabled();
                    $custs[ $c->getAutsys() ]['vlaninterfaces'][ $vli->getVlan()->getNumber() ][ $cnt ]['ipv6enabled'] = $vli->getIpv6enabled();
                    $custs[ $c->getAutsys() ]['vlaninterfaces'][ $vli->getVlan()->getNumber() ][ $cnt ]['rsclient']    = $vli->getRsclient();
                }
            }
                         
        }
                        
        return $custs;
    }


    /**
     * Build an array of data for the peering matrice
     *
     * Sample return:
     *
     *     [
     *         "me" => [    "id" => 69
     *                       "name" => "3 Ireland's"
     *                       "shortname" => "three"
     *                       "autsys" => 34218
     *                       "maxprefixes" => 100
     *                       "peeringemail" => "io.ip@three.co.uk"
     *                       "peeringpolicy" => "open"
     *                       "vlaninterfaces" => [
     *                               10 => [
     *                                   0 => [
     *                                           "ipv4enabled" => true
     *                                           "ipv6enabled" => false
     *                                           "rsclient" => true
     *                                       ]
     *                               ]
     *
     *                          ]
     *
     *                  ]
     *
     *         "potential" => [
     *               12041 => false
     *               56767 => false
     *               196737 => false
     *          ]
     *
     *          "potential_bilat" => [
     *               12041 => true
     *               56767 => true
     *               196737 => false
     *          ]
     *
     *          "peered" => [
     *               12041 => true
     *               56767 => true
     *               196737 => false
     *          ]
     *
     *          "peered" => [
     *               12041 => false
     *               56767 => false
     *               196737 => false
     *          ]
     *
     *         "peers" => [
     *               60 => [
     *                   "id" => 44
     *                   "custid" => 69
     *                   "peerid" => 60
     *                   "email_last_sent" => null
     *                   "emails_sent" => 0
     *                   "peered" => false
     *                   "rejected" => false
     *                   "notes" => ""
     *                   "created" => DateTime
     *                   "updated" => DateTime
     *                   "email_days" => -1
     *               ]
     *           ]
     *
     *          "custs" => [
     *               12041 => [
     *                   "id" => 146
     *                   "name" => "Afilias"
     *                   "shortname" => "afilias"
     *                   "autsys" => 12041
     *                   "maxprefixes" => 500
     *                   "peeringemail" => "peering@afilias-nst.info"
     *                   "peeringpolicy" => "open"
     *                   "vlaninterfaces" => [...]
     *                   "ispotential" => true
     *                   10 => [
     *                   4 => 1
     *                   ]
     *               ]
     *               56767 => [...]
     *               196737 => [...]
     *
     *     ]
     *
     * @param CustomerEntity    $cust       Current customer
     * @param \Entities\Vlan[]  $vlans      Array of Vlans
     * @param array             $protos     Array of protos
     *
     * @return array
     *
     * @throws
     */
    public function getPeeringManagerArrayByType( CustomerEntity $cust , $vlans, $protos ) {

        if( !count( $vlans ) ) {
            return null;
        }

        $bilat = array();

        foreach( $vlans as $vlan ){
            foreach( $protos as $proto ){
                $bilat[ $vlan->getNumber() ][ $proto ] = D2EM::getRepository( BGPSessionDataEntity::class )->getPeers( $vlan->getId(), $proto );
            }
        }

        $custs = D2EM::getRepository( CustomerEntity::class )->getForPeeringManager();


        $potential = $potential_bilat = $peered = $rejected = [];

        if( isset( $custs[ $cust->getAutsys() ] ) ){
            $me = $custs[ $cust->getAutsys() ];
            unset( $custs[ $cust->getAutsys() ] );
        } else {
            $me = null;
        }

        foreach( $custs as $c ) {
            $custs[ $c[ 'autsys' ] ][ 'ispotential' ] = false;

            foreach( $vlans as $vlan ) {

                if( isset( $me[ 'vlaninterfaces' ][ $vlan->getNumber() ] ) ) {

                    if( isset( $c[ 'vlaninterfaces' ][$vlan->getNumber()] ) ) {

                        foreach( $protos as $proto ) {

                            if( $me[ 'vlaninterfaces' ][ $vlan->getNumber() ][ 0 ][ "ipv{$proto}enabled" ] && $c[ 'vlaninterfaces' ][ $vlan->getNumber() ][ 0 ][ "ipv{$proto}enabled" ] ) {

                                if( isset( $bilat[ $vlan->getNumber() ][ 4 ][ $me['autsys' ] ][ 'peers' ] ) && in_array( $c[ 'autsys' ], $bilat[ $vlan->getNumber() ][ 4 ][ $me[ 'autsys' ] ][ 'peers' ] ) ){

                                    $custs[ $c[ 'autsys' ] ][$vlan->getNumber()][$proto] = 2;
                                } else if( $me[ 'vlaninterfaces' ][ $vlan->getNumber() ][ 0 ][ 'rsclient' ] && $c[ 'vlaninterfaces' ][ $vlan->getNumber() ][ 0 ][ 'rsclient' ] ){

                                    $custs[ $c[ 'autsys' ] ][ $vlan->getNumber() ][ $proto ] = 1;
                                    $custs[ $c[ 'autsys' ] ][ 'ispotential' ] = true;

                                } else {

                                    $custs[ $c[ 'autsys' ] ][ $vlan->getNumber() ][ $proto ] = 0;
                                    $custs[ $c[ 'autsys' ] ][ 'ispotential' ] = true;

                                }
                            }
                        }
                    }
                }
            }
        }

        foreach( $custs as $c ) {
            $peered[          $c[ 'autsys' ] ] = false;
            $potential_bilat[ $c[ 'autsys' ] ] = false;
            $potential[       $c[ 'autsys' ] ] = false;
            $rejected[        $c[ 'autsys' ] ] = false;

            foreach( $vlans as $vlan ) {
                foreach( $protos as $proto ) {
                    if( isset( $c[ $vlan->getNumber() ][ $proto ] ) ) {
                        switch( $c[ $vlan->getNumber() ][ $proto ] ) {
                            case 2:
                                $peered[ $c[ 'autsys' ] ] = true;
                                break;

                            case 1:
                                $peered[          $c[ 'autsys' ] ] = true;
                                $potential_bilat[ $c[ 'autsys' ] ] = true;
                                break;

                            case 0:
                                $potential[       $c[ 'autsys' ] ] = true;
                                $potential_bilat[ $c[ 'autsys' ] ] = true;
                                break;
                        }
                    }
                }
            }
        }


        foreach( $peers = D2EM::getRepository( CustomerEntity::class )->getPeers( $cust->getId() ) as $i => $p ) {
            // days since last peering request email sent
            if( !$p[ 'email_last_sent' ] ){
                $peers[ $i ][ 'email_days' ] = -1;
            } else {
                $peers[ $i ][ 'email_days' ] = floor( ( time() - $p[ 'email_last_sent' ]->getTimestamp() ) / 86400 );
            }

        }

        foreach( $custs as $c ) {
            if( isset( $peers[ $c[ 'id' ] ] ) ) {
                if( isset( $peers[ $c[ 'id' ] ][ 'peered' ] ) && $peers[ $c[ 'id' ] ][ 'peered' ] ) {
                    $peered[            $c[ 'autsys' ] ] = true;
                    $rejected[          $c[ 'autsys' ] ] = false;
                    $potential[         $c[ 'autsys' ] ] = false;
                    $potential_bilat[   $c[ 'autsys' ] ] = false;
                } else if( isset( $peers[ $c[ 'id' ] ][ 'rejected' ] ) && $peers[ $c[ 'id' ] ][ 'rejected' ] ) {
                    $peered[            $c['autsys' ] ] = false;
                    $rejected[          $c['autsys' ] ] = true;
                    $potential[         $c['autsys' ] ] = false;
                    $potential_bilat[   $c['autsys' ] ] = false;
                }
            }
        }


        return [    "me"                => $me,
                    "potential"         => $potential,
                    "potential_bilat"   => $potential_bilat,
                    "peered"            => $peered,
                    "rejected"          => $rejected,
                    "peers"             => $peers,
                    "custs"             => $custs,
                    "bilat"             => $bilat,
                    "vlan"              => $vlans ,
                    "protos"            => $protos
        ];

    }


    /**
     * Find customers by ASN
     * 
     * @param  string $asn The ASN number to search for
     * @return \Entities\Customer[] Matching customers
     */
    public function findByASN( $asn )
    {
        return $this->getEntityManager()->createQuery(
                "SELECT c
        
                 FROM \\Entities\\Customer c

                 WHERE c.autsys = :asn

                 ORDER BY c.name ASC"
        
            )
            ->setParameter( 'asn', $asn )
            ->getResult();
    }
    
    /**
     * Find customers by AS Macro
     * 
     * @param  string $asm The AS macro to search for
     * @return \Entities\Customer[] Matching customers
     */
    public function findByASMacro( $asm )
    {
        return $this->getEntityManager()->createQuery(
                "SELECT c
        
                 FROM \\Entities\\Customer c

                 WHERE c.peeringmacro = :asm OR c.peeringmacrov6 = :asm

                 ORDER BY c.name ASC"
        
            )
            ->setParameter( 'asm', strtoupper( $asm ) )
            ->getResult();
    }
    
    /**
     * Find customers by 'wildcard'
     * 
     * @param  string $wildcard The test string to search for
     * @return \Entities\Customer[] Matching customers
     */
    public function findWild( $wildcard )
    {
        return $this->getEntityManager()->createQuery(
                "SELECT c
        
                 FROM \\Entities\\Customer c
                 LEFT JOIN c.RegistrationDetails r

                 WHERE 
                    c.name LIKE :wildcard
                    OR c.shortname LIKE :wildcard
                    OR c.abbreviatedName LIKE :wildcard
                    OR r.registeredName LIKE :wildcard

                 ORDER BY c.name ASC"
        
            )
            ->setParameter( 'wildcard', "%{$wildcard}%" )
            ->getResult();
    }


    /**
     * Return an array of one or all customer names where the array key is the customer id.
     *
     * @param $types array the types needed
     * @param $cid int The customer ID
     *
     * @return array An array of all customers names with the customers id as the key.
     */
    public function getAsArray( int $cid = null, array $types = [] ): array {
        $request = "SELECT c
                    FROM \\Entities\\customer c
                    WHERE 1 = 1";

        if( $cid ){
            $request .= " AND c.id = {$cid} ";
        }

        if( count( $types) > 0 ){
            $request .= " AND c.type IN (" . implode( ',', $types ) . ")";
        }

        $request .= " ORDER BY c.name ASC ";

        $listCustomers = $this->getEntityManager()->createQuery( $request )->getResult();

        $customers = [];
        foreach( $listCustomers as $cust ) {
            $customers[ $cust->getId() ] = $cust->getName() ;
        }

        return $customers;
    }

    /**
     * Return an array of customers for display on the frontend list
     *
     * @param bool $showCurrentOnly Limit to current customers only
     * @param int  $state           Array index of CustomerEntity::$CUST_STATUS_TEXT
     * @param int $type             Array index of CustomerEntity::$CUST_TYPE_TEXT
     *
     * @return array An array of all customers objects
     */
    public function getAllForFeList( $showCurrentOnly = false, $state = null, $type = null, $tag = null ): array {

        $q = "SELECT c
                FROM Entities\\Customer c ";

        if( $tag ){
            $q .= " LEFT JOIN c.tags t";
        }

        $q .= " WHERE 1 = 1";

        if( $state && isset( CustomerEntity::$CUST_STATUS_TEXT[ $state ] ) ) {
            $q .= " AND c.status = {$state} " ;
        }

        if( $type && isset( CustomerEntity::$CUST_TYPES_TEXT[ $type ] ) ) {
            $q .= " AND c.type = {$type} " ;
        }

        if( $showCurrentOnly ) {
            $q .= " AND " . Customer::DQL_CUST_CURRENT;
        }

        if( $tag ) {
            $q .= " AND t.id = " . $tag;
        }

        $q .= " ORDER BY c.name ASC ";

        return $this->getEntityManager()->createQuery( $q )->getResult();
    }

    /**
     * Delete the customer.
     *
     * Related entities are mostly handled by 'ON DELETE CASCADE'.
     *
     * @param CustomerEntity $c The customer Object
     *
     * @return bool
     * @throws
     */
    public function delete( CustomerEntity $c ): bool {

        try {
            $this->getEntityManager()->getConnection()->beginTransaction();

            $cbd = $c->getBillingDetails();
            $crd = $c->getRegistrationDetails();

            // Delete Customer Logo
            foreach( $c->getLogos() as $logo){

                if( file_exists( $logo->getFullPath() ) ) {
                    @unlink( $logo->getFullPath() );
                }

                $c->removeLogo( $logo );
                $this->getEntityManager()->remove( $logo );
            }

            // delete contact to contact group links
            $conn = $this->getEntityManager()->getConnection();
            $stmt = $conn->prepare("DELETE FROM contact_to_group WHERE contact_id = :id");
            foreach( $c->getContacts() as $contact ) {
                $stmt->bindValue('id', $contact->getId() );
                $stmt->execute();
            }

            // Delete User Logins
            $stmt2 = $conn->prepare("DELETE FROM user_logins WHERE customer_to_user_id = :id");

            /** @var CustomerToUserEntity $c2User */
            foreach( $c->getC2Users() as $c2User ) {
                $stmt2->bindValue('id', $c2User->getId() );
                $stmt2->execute();

                // Delete User, if that user only have the customer that we want to delete linked
                if( $c2User->getUser()->getCustomers2User()->count() == 1 ) {
                    $this->getEntityManager()->remove( $c2User->getUser() );
                }

                // Delete Customer2User
                $conn->prepare("DELETE FROM customer_to_users WHERE id = " . $c2User->getId() )->execute();

                // Set a new default customer to the user
                if( $c2User->getUser()->getCustomer()->getId() == $c->getId() ) {
                    $newAssignatedCustomer = null;

                    foreach( $c2User->getUser()->getCustomers() as $cust ){
                        if( $cust->getId() != $c->getId() ){
                            $newAssignatedCustomer = $cust;
                            break;
                        }
                    }

                    $c2User->getUser()->setCustomer( $newAssignatedCustomer );
                }
            }

            // Delete the Core Bundle
            foreach ( D2EM::getRepository( CoreBundleEntity::class)->getAllForCustomer( $c ) as $cb ) {
                D2EM::getRepository( CoreBundleEntity::class)->delete( $cb );
            }

            $this->getEntityManager()->remove( $c );
            $this->getEntityManager()->remove( $cbd );
            $this->getEntityManager()->remove( $crd );

            $this->getEntityManager()->flush();
            $this->getEntityManager()->getConnection()->commit();


        } catch( Exception $e ) {
            $this->getEntityManager()->getConnection()->rollBack();
            throw $e;
        }

        return true;
    }
}
