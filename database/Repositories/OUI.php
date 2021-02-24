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

use Cache;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\Entity;

use Entities\Layer2Address as Layer2AddressEntity;

/**
 * OUI
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class OUI extends EntityRepository
{

    /**
     * Delete all entries from the OUI table.
     * 
     * @return int Number of records deleted
     */
    public function clear()
    {
        return $this->getEntityManager()->createQuery( "DELETE FROM Entities\OUI" )->execute();
    }

    /**
     * Get the organisation / manufacturer behind a given six character OUI.
     *
     * The OUI should be all lower case with no punctuation.
     * 
     * @param  string $oui The six character OUI
     * @return string      The organisation name (or 'Unknown')
     */
    public function getOrganisation( $oui )
    {
        try
        {
            return $this->getEntityManager()->createQuery(
                    "SELECT o.organisation
                     FROM \\Entities\\OUI o
                     WHERE  o.oui = :oui"
                )
                ->setParameter( 'oui', $oui )
                ->useResultCache( true, 86400 )
                ->getSingleScalarResult();
        }
        catch( \Doctrine\ORM\NoResultException $e )
        {
            return 'Unknown';
        }
    }


    /**
     * Return an array of all oui organistation where the array key is the oui id.
     * @return array An array of all oui organistation with the oui id as the key.
     */
    public function getAsArray(): array {
        $listOui = [];

        foreach( self::findAll() as $oui ) {
            $listOui[ $oui->getOui() ] = $oui->getOrganisation();
        }

        return $listOui;
    }


    /**
     * Key for cached result of layer2address MACs to organisations.
     * @see getForLayer2Addresses()
     *
     */
    const CACHE_KEY_FORLAYER2ADDRESSES = 'rep_oui_getForLayer2Addresses';

    /**
     * Return an array of all OUI organisations for a Layer2Address collection.
     *
     *
     *
     * The question here is whether it's more efficient to:
     * a) load all ~23k entries from the OUI table
     * b) individually load just the ones we need
     *
     * Note the result of:
     *
     *     select count( distinct substring( mac, 1, 6 ) ) from l2address;  => 167
     *     select count( substring( mac, 1, 6 ) ) from l2address;           => 87
     *
     * Based on this, I'm going to go with (b) for now with a 7 day cache of existing
     * MACs.
     *
     * @param array $l2as Layer2Address entities
     * @param bool $resetCache If true, reset the cache
     * @return array An array of all oui organistations with the OUI identifier as the key.
     */
    public function getForLayer2Addresses( array $l2as, bool $resetCache = false ): array {

        if( $resetCache ) {
            $ouis = [];
        } else {
            $ouis = Cache::get( self::CACHE_KEY_FORLAYER2ADDRESSES, [] );
        }

        foreach( $l2as as $l2a ) {
            /** @var Layer2AddressEntity $l2a */
            $mac = substr( $l2a->getMac(), 0, 6 );
            if( !isset( $ouis[ $mac ] ) ) {
                if( ( $oui = $this->findOneBy( [ 'oui' => $mac ] ) ) == null ) {
                    $ouis[ $mac ] = 'Unknown';
                } else {
                    $ouis[ $mac ] = $oui->getOrganisation();
                }
            }
        }

        Cache::put( self::CACHE_KEY_FORLAYER2ADDRESSES, $ouis, 86400 );

        return $ouis;
    }
}
