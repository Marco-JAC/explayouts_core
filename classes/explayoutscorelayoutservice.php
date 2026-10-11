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

        $zones = expLayoutsLayoutType::getZones( trim( $layoutType ) );
        $position = 0;
        foreach ( $zones as $zoneIdentifier )
        {
            $zone = expLayoutsZone::create( (int)$layout->attribute( 'id' ), $zoneIdentifier, (int)$layout->attribute( 'status' ) );
            $zone->setAttribute( 'position', $position++ );
            $zone->store();
        }

        return $layout;
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
        $copy = $this->create(
            $newIdentifier,
            (string)$source->attribute( 'name' ) . ' (copy)',
            (string)$source->attribute( 'layout_type' ),
            (int)$source->attribute( 'shared' ) === 1
        );

        $this->copyLayoutContent( $source, $copy );

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

    protected function copyLayoutContent( $source, $target )
    {
        $sourceId = (int)$source->attribute( 'id' );
        $sourceStatus = (int)$source->attribute( 'status' );
        $targetId = (int)$target->attribute( 'id' );
        $targetStatus = (int)$target->attribute( 'status' );

        $zones = expLayoutsZone::fetchByLayout( $sourceId, $sourceStatus );
        foreach ( $zones as $zone )
        {
            $newZone = expLayoutsZone::create( $targetId, (string)$zone->attribute( 'identifier' ), $targetStatus );
            $newZone->setAttribute( 'position', (int)$zone->attribute( 'position' ) );

            $linkedLayoutId = $zone->attribute( 'linked_layout_id' );
            if ( $linkedLayoutId !== null && $linkedLayoutId !== false && (int)$linkedLayoutId > 0 )
            {
                $newZone->setAttribute( 'linked_layout_id', (int)$linkedLayoutId );
                $newZone->setAttribute( 'linked_zone_identifier', $zone->attribute( 'linked_zone_identifier' ) );
            }

            $newZone->store();

            $blocks = expLayoutsBlock::fetchByZone( (int)$zone->attribute( 'id' ), $sourceStatus );
            foreach ( $blocks as $block )
            {
                if ( (int)$block->attribute( 'parent_id' ) > 0 )
                    continue;

                $this->copyBlockTree( $block, $newZone, $targetStatus );
            }
        }
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
