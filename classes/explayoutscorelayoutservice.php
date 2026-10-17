<?php

class expLayoutsCoreLayoutService
{
    /**
     * Admin layouts are cached per module/view and generation (see
     * expLayoutsResolver::adminCacheKey()); a publish or a rule change must
     * make every cached answer stop being found.
     */
    protected function clearResolverCache()
    {
        if ( class_exists( 'expLayoutsResolver' ) && method_exists( 'expLayoutsResolver', 'clearCache' ) )
            expLayoutsResolver::clearCache();
    }

    public function load( $id, $status = null )
    {
        return expLayoutsLayout::fetch( (int)$id );
    }

    public function loadDraft( $id )
    {
        $layout = $this->load( (int)$id );
        if ( !$layout )
            return false;

        if ( (int)$layout->attribute( 'status' ) === 1 )
            return $layout;

        return expLayoutsLayout::fetchByIdentifier( (string)$layout->attribute( 'identifier' ), 1 );
    }

    public function loadPublished( $id )
    {
        $layout = $this->load( (int)$id );
        if ( !$layout )
            return false;

        if ( (int)$layout->attribute( 'status' ) === 2 )
            return $layout;

        return expLayoutsLayout::fetchByIdentifier( (string)$layout->attribute( 'identifier' ), 2 );
    }

    public function listAll( $status = null )
    {
        if ( $status !== null )
            return expLayoutsLayout::fetchList( $status );

        $all = expLayoutsLayout::fetchList();
        $byIdentifier = array();
        foreach ( $all as $layout )
        {
            $identifier = (string)$layout->attribute( 'identifier' );
            $layoutStatus = (int)$layout->attribute( 'status' );
            if ( !isset( $byIdentifier[$identifier] ) || $layoutStatus === 2 )
                $byIdentifier[$identifier] = $layout;
        }

        return array_values( $byIdentifier );
    }

    public function create( $identifier, $name = '', $layoutType = '', $shared = false )
    {
        $layout = $this->createLayoutRow( $identifier, $name, $layoutType, $shared );
        $this->addMissingTypeZones( $layout );
        return $layout;
    }

    /**
     * The layout row of a new draft, with a unique identifier and no zones.
     */
    protected function createLayoutRow( $identifier, $name = '', $layoutType = '', $shared = false )
    {
        $identifier = trim( $identifier );
        $baseIdentifier = $identifier !== '' ? $identifier : 'layout';
        $uniqueIdentifier = $baseIdentifier;
        $counter = 2;
        while ( expLayoutsLayout::fetchByIdentifier( $uniqueIdentifier, 1 ) !== false )
        {
            $uniqueIdentifier = $baseIdentifier . '_' . $counter++;
        }

        $layout = expLayoutsLayout::create( $uniqueIdentifier );
        $layout->setAttribute( 'name', trim( $name ) );
        $layout->setAttribute( 'layout_type', trim( $layoutType ) );
        $layout->setAttribute( 'shared', $shared ? 1 : 0 );
        $layout->setAttribute( 'created', time() );
        $layout->setAttribute( 'modified', time() );
        $layout->store();

        return $layout;
    }

    /**
     * Adds each zone the layout's type defines and the layout does not have
     * yet, once. A new layout gets them at positions 0, 1, 2 ...; zones added
     * to a layout that already has some go after the last one.
     */
    protected function addMissingTypeZones( $layout )
    {
        $layoutId = (int)$layout->attribute( 'id' );
        $status = (int)$layout->attribute( 'status' );

        $existing = array();
        $position = 0;
        foreach ( expLayoutsZone::fetchByLayout( $layoutId, $status ) as $zone )
        {
            $existing[(string)$zone->attribute( 'identifier' )] = true;
            $position = max( $position, (int)$zone->attribute( 'position' ) + 1 );
        }

        foreach ( expLayoutsLayoutType::getZones( trim( (string)$layout->attribute( 'layout_type' ) ) ) as $zoneIdentifier )
        {
            if ( isset( $existing[$zoneIdentifier] ) )
                continue;
            $zone = expLayoutsZone::create( $layoutId, $zoneIdentifier, $status );
            $zone->setAttribute( 'position', $position++ );
            $zone->store();
            $existing[$zoneIdentifier] = true;
        }
    }

    public function update( $id, $attributes )
    {
        $layout = $this->load( (int)$id );
        if ( !$layout )
            return false;

        foreach ( $attributes as $key => $value )
        {
            if ( in_array( $key, array( 'identifier', 'name', 'layout_type', 'status' ) ) )
                $layout->setAttribute( $key, $value );
            elseif ( $key === 'shared' )
                $layout->setAttribute( 'shared', $value ? 1 : 0 );
        }
        $layout->setAttribute( 'modified', time() );
        $layout->store();
        return $layout;
    }

    public function publish( $id )
    {
        $result = $this->publishUncleared( $id );
        $this->clearResolverCache();
        return $result;
    }

    protected function publishUncleared( $id )
    {
        $draft = $this->loadDraft( (int)$id );
        if ( !$draft )
            return false;

        $identifier = (string)$draft->attribute( 'identifier' );
        $published = expLayoutsLayout::fetchByIdentifier( $identifier, 2 );

        if ( !$published )
        {
            $draft->publish();
            return $this->loadPublished( (int)$draft->attribute( 'id' ) );
        }

        $published->setAttribute( 'name', (string)$draft->attribute( 'name' ) );
        $published->setAttribute( 'layout_type', (string)$draft->attribute( 'layout_type' ) );
        $published->setAttribute( 'shared', (int)$draft->attribute( 'shared' ) );
        $published->setAttribute( 'status', 2 );
        $published->setAttribute( 'modified', time() );
        $published->store();

        $this->clearLayoutContent( $published );
        $this->copyLayoutContent( $draft, $published );

        $this->delete( (int)$draft->attribute( 'id' ) );

        return $published;
    }

    public function createDraft( $id )
    {
        $published = $this->load( (int)$id );
        if ( !$published )
            return false;

        $identifier = (string)$published->attribute( 'identifier' );

        if ( (int)$published->attribute( 'status' ) === 1 )
            return $published;

        $draft = expLayoutsLayout::fetchByIdentifier( $identifier, 1 );
        if ( $draft )
            return $draft;

        $draft = expLayoutsLayout::create( $identifier, (string)$published->attribute( 'name' ), (string)$published->attribute( 'layout_type' ) );
        $draft->setAttribute( 'status', 1 );
        $draft->setAttribute( 'shared', (int)$published->attribute( 'shared' ) );
        $draft->setAttribute( 'created', (int)$published->attribute( 'created' ) );
        $draft->setAttribute( 'modified', time() );
        $draft->store();

        $this->copyLayoutContent( $published, $draft );

        return $draft;
    }

    public function discard( $id )
    {
        $draft = $this->loadDraft( (int)$id );
        if ( !$draft )
            return false;

        if ( (int)$draft->attribute( 'status' ) !== 1 )
            return $this->loadPublished( (int)$draft->attribute( 'id' ) );

        $identifier = (string)$draft->attribute( 'identifier' );
        $published = $this->loadByIdentifier( $identifier, 2 );

        if ( !$published )
            return $draft;

        $this->delete( (int)$draft->attribute( 'id' ) );
        return $published;
    }

    public function copy( $id )
    {
        $source = $this->load( (int)$id );
        if ( !$source )
            return false;

        $newIdentifier = trim( $source->attribute( 'identifier' ) ) . '_' . time();

        // The copy's zones are the source's, each made once with its link and
        // its blocks. Making the type's zones first as well (as create() does
        // for a new layout) gave every zone two rows: the editor added blocks
        // to one and drew the other, so a block added to the copy vanished.
        $copy = $this->createLayoutRow(
            $newIdentifier,
            (string)$source->attribute( 'name' ) . ' (copy)',
            (string)$source->attribute( 'layout_type' ),
            (int)$source->attribute( 'shared' ) === 1
        );

        $this->copyLayoutContent( $source, $copy, true );
        $this->addMissingTypeZones( $copy );

        return $copy;
    }

    public function delete( $id )
    {
        $result = $this->deleteUncleared( $id );
        $this->clearResolverCache();
        return $result;
    }

    protected function deleteUncleared( $id )
    {
        $layout = $this->load( (int)$id );
        if ( !$layout )
            return false;

        // Disable any rules tied to this layout so a removed layout does not
        // leave the resolver matching a path to a non-existent layout.
        eZDB::instance()->query( 'UPDATE explayouts_rule SET enabled = 0 WHERE layout_id = ' . (int)$id );

        // Same reasoning for zones that inherit their blocks from this one:
        // a link to a layout that is gone renders as nothing at all, and the
        // editor would draw the zone as locked with no way to unlock it.
        eZDB::instance()->query( 'UPDATE explayouts_zone SET linked_layout_id = NULL, linked_zone_identifier = NULL WHERE linked_layout_id = ' . (int)$id );

        $this->clearLayoutContent( $layout );
        $layout->remove();
        return true;
    }

    public function loadByIdentifier( $identifier, $status = 2 )
    {
        return expLayoutsLayout::fetchByIdentifier( trim( $identifier ), (int)$status );
    }

    public function listByType( $layoutType )
    {
        return eZPersistentObject::fetchObjectList(
            expLayoutsLayout::definition(),
            null,
            array( 'layout_type' => trim( $layoutType ) ),
            array( 'modified' => 'desc', 'id' => 'desc' ),
            null, true
        );
    }

    protected function clearLayoutContent( $layout )
    {
        $layoutId = (int)$layout->attribute( 'id' );
        $status = (int)$layout->attribute( 'status' );

        $zones = expLayoutsZone::fetchByLayout( $layoutId, $status );
        foreach ( $zones as $zone )
        {
            $blocks = expLayoutsBlock::fetchByZone( (int)$zone->attribute( 'id' ), $status );
            foreach ( $blocks as $block )
            {
                $this->clearBlockData( (int)$block->attribute( 'id' ) );
                $block->remove();
            }
            $zone->remove();
        }
    }

    protected function clearBlockData( $blockId )
    {
        $params = expLayoutsBlockParameter::fetchByBlock( $blockId );
        foreach ( $params as $param )
            $param->remove();

        $this->blockService()->deleteCollections( (int)$blockId );
    }

    /**
     * The one place blocks are copied: the editor's "Duplicate block" and
     * the layout copies below (draft, publish, layout copy) all go through
     * expLayoutsCoreBlockService::copyTree().
     */
    protected function blockService()
    {
        return new expLayoutsCoreBlockService();
    }

    /**
     * Copies the zones of $source, with their links and their blocks, into
     * $target, which has no zones of its own yet.
     *
     * Draft and publish copy row for row ($mergeDuplicates false), so they
     * never change what a layout holds. A layout copy ($mergeDuplicates true)
     * makes one zone per identifier even when the source has several rows for
     * one (the damage an earlier copy() left): the target zone takes the link
     * and position of the row that has a link, else of the row with the most
     * blocks, else of the first, and the blocks of every row of that
     * identifier, so nothing in the source is left out.
     */
    protected function copyLayoutContent( $source, $target, $mergeDuplicates = false )
    {
        $sourceId = (int)$source->attribute( 'id' );
        $sourceStatus = (int)$source->attribute( 'status' );
        $targetId = (int)$target->attribute( 'id' );
        $targetStatus = (int)$target->attribute( 'status' );

        $groups = array();
        foreach ( expLayoutsZone::fetchByLayout( $sourceId, $sourceStatus ) as $zone )
        {
            $key = $mergeDuplicates ? (string)$zone->attribute( 'identifier' ) : 'zone:' . (int)$zone->attribute( 'id' );
            $groups[$key][] = $zone;
        }

        foreach ( $groups as $zones )
        {
            $blocksByZone = array();
            foreach ( $zones as $index => $zone )
            {
                $blocksByZone[$index] = array();
                foreach ( expLayoutsBlock::fetchByZone( (int)$zone->attribute( 'id' ), $sourceStatus ) as $block )
                {
                    if ( (int)$block->attribute( 'parent_id' ) <= 0 )
                        $blocksByZone[$index][] = $block;
                }
            }

            $primary = self::primaryZoneIndex( $zones, $blocksByZone );
            $zone = $zones[$primary];

            $newZone = expLayoutsZone::create( $targetId, (string)$zone->attribute( 'identifier' ), $targetStatus );
            $newZone->setAttribute( 'position', (int)$zone->attribute( 'position' ) );
            if ( $zone->isLinked() )
            {
                $newZone->setAttribute( 'linked_layout_id', (int)$zone->attribute( 'linked_layout_id' ) );
                $newZone->setAttribute( 'linked_zone_identifier', (string)$zone->attribute( 'linked_zone_identifier' ) );
            }
            $newZone->store();

            // The primary row's blocks first, then the others' in row order.
            $order = array_merge( array( $primary ), array_diff( array_keys( $zones ), array( $primary ) ) );
            foreach ( $order as $index )
            {
                foreach ( $blocksByZone[$index] as $block )
                    $this->copyBlockTree( $block, $newZone, $targetStatus );
            }
        }
    }

    /**
     * Of several rows for one zone: the one with a link, else the one with
     * the most top-level blocks, else the first.
     */
    protected static function primaryZoneIndex( $zones, $blocksByZone )
    {
        $best = 0;
        foreach ( $zones as $index => $zone )
        {
            $linked = $zone->isLinked();
            $bestLinked = $zones[$best]->isLinked();
            if ( $linked && !$bestLinked )
                $best = $index;
            elseif ( $linked === $bestLinked && count( $blocksByZone[$index] ) > count( $blocksByZone[$best] ) )
                $best = $index;
        }
        return $best;
    }

    protected function copyBlockTree( $sourceBlock, $targetZone, $targetStatus )
    {
        return $this->blockService()->copyTree(
            $sourceBlock,
            (int)$targetZone->attribute( 'id' ),
            (int)$targetZone->attribute( 'layout_id' ),
            (int)$targetStatus,
            0,
            '',
            (int)$sourceBlock->attribute( 'position' )
        );
    }

    protected function copyBlockData( $sourceBlock, $targetBlock )
    {
        $this->blockService()->copyData( $sourceBlock, $targetBlock );
    }
}
