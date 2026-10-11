<?php

class expLayoutsCoreBlockService
{
    public function load( $blockId )
    {
        return expLayoutsBlock::fetch( (int)$blockId );
    }

    public function loadByZone( $zoneId, $status = null )
    {
        return expLayoutsBlock::fetchByZone( (int)$zoneId, $status );
    }

    public function create( $zoneId, $layoutId, $definitionIdentifier, $name = '', $viewType = 'default' )
    {
        $block = expLayoutsBlock::create( (int)$zoneId, (int)$layoutId, trim( $definitionIdentifier ), trim( $name ) );
        $block->setAttribute( 'view_type', trim( $viewType ) );
        $block->store();
        return $block;
    }

    public function update( $blockId, $attributes )
    {
        $block = $this->load( (int)$blockId );
        if ( !$block )
            return false;

        foreach ( $attributes as $key => $value )
        {
            if ( in_array( $key, array( 'name', 'view_type', 'zone_id', 'position' ) ) )
                $block->setAttribute( $key, $value );
        }
        $block->store();
        return $block;
    }

    public function move( $blockId, $targetZoneId )
    {
        $block = $this->load( (int)$blockId );
        if ( !$block )
            return false;

        $block->setAttribute( 'zone_id', (int)$targetZoneId );
        $block->store();
        return $block;
    }

    public function delete( $blockId )
    {
        $block = $this->load( (int)$blockId );
        if ( !$block )
            return false;

        $params = expLayoutsBlockParameter::fetchByBlock( (int)$block->attribute( 'id' ) );
        foreach ( $params as $param )
            $param->remove();

        $this->deleteCollections( (int)$block->attribute( 'id' ) );

        $block->remove();
        return true;
    }

    /**
     * Removes every collection of a block with its items and its query.
     */
    public function deleteCollections( $blockId )
    {
        foreach ( $this->collectionsOf( (int)$blockId ) as $collection )
        {
            $collectionId = (int)$collection->attribute( 'id' );
            foreach ( expLayoutsCollectionItem::fetchByCollection( $collectionId ) as $item )
                $item->remove();
            expLayoutsCollectionQuery::removeByCollection( $collectionId );
            $collection->remove();
        }
    }

    public function setParameters( $blockId, $parameters )
    {
        $block = $this->load( (int)$blockId );
        if ( !$block )
            return false;

        foreach ( $parameters as $name => $value )
            expLayoutsBlockParameter::set( (int)$block->attribute( 'id' ), trim( $name ), $value );

        return expLayoutsBlockParameter::fetchByBlock( (int)$block->attribute( 'id' ) );
    }

    public function setCollection( $blockId, $type = 'manual' )
    {
        $existing = expLayoutsCollection::fetchByBlock( (int)$blockId );
        if ( $existing )
            return $existing;

        $collection = expLayoutsCollection::create( (int)$blockId, trim( $type ) );
        $collection->store();
        return $collection;
    }

    /**
     * Copies a block with everything it owns, like the reference
     * BlockService::copyBlock(): its parameters, every collection with its
     * items, query and query parameters, and its child blocks in every
     * placeholder, each child with its own collections, recursively.
     *
     * Every row of the copy is new: nothing is shared by id with the source,
     * so changing the copy (or its items) never changes the source.
     *
     * $target places the copy: 'zone_id', 'layout_id' (default: the
     * source's), 'parent_id' and 'placeholder' (default: top level of the
     * zone), 'position' (default: the source's) and 'status' (default: the
     * source's). The caller renumbers the siblings if it needs to.
     *
     * Returns the new block, or false when the source does not exist or the
     * target parent is the source itself or one of its descendants (a block
     * cannot be copied below itself).
     */
    public function copy( $block, $target = array() )
    {
        if ( !$block instanceof expLayoutsBlock )
            $block = $this->load( (int)$block );
        if ( !$block instanceof expLayoutsBlock )
            return false;

        $parentId = isset( $target['parent_id'] ) ? (int)$target['parent_id'] : 0;
        if ( $parentId > 0 && $this->isBlockOrDescendant( $parentId, (int)$block->attribute( 'id' ) ) )
            return false;

        return $this->copyTree(
            $block,
            isset( $target['zone_id'] ) ? (int)$target['zone_id'] : (int)$block->attribute( 'zone_id' ),
            isset( $target['layout_id'] ) ? (int)$target['layout_id'] : (int)$block->attribute( 'layout_id' ),
            isset( $target['status'] ) ? (int)$target['status'] : (int)$block->attribute( 'status' ),
            $parentId,
            $parentId > 0 && isset( $target['placeholder'] ) ? (string)$target['placeholder'] : '',
            isset( $target['position'] ) ? (int)$target['position'] : (int)$block->attribute( 'position' )
        );
    }

    /**
     * The recursive part of copy(), also used to copy a whole layout (draft
     * from published, published from draft, a layout copy). No checks: the
     * caller has placed the copy already.
     */
    public function copyTree( expLayoutsBlock $source, $zoneId, $layoutId, $status, $parentId, $placeholder, $position )
    {
        // The children are read before the copy is stored, so a copy placed
        // inside its own source's subtree could never be copied again.
        $children = expLayoutsBlock::fetchChildren( (int)$source->attribute( 'id' ), (int)$source->attribute( 'status' ) );

        $copy = expLayoutsBlock::create(
            (int)$zoneId,
            (int)$layoutId,
            (string)$source->attribute( 'definition_identifier' ),
            (string)$source->attribute( 'name' )
        );
        $copy->setAttribute( 'view_type', (string)$source->attribute( 'view_type' ) );
        $copy->setAttribute( 'item_view_type', (string)$source->attribute( 'item_view_type' ) );
        $copy->setAttribute( 'position', (int)$position );
        $copy->setAttribute( 'parent_id', (int)$parentId );
        $copy->setAttribute( 'placeholder', (int)$parentId > 0 ? (string)$placeholder : '' );
        $copy->setAttribute( 'status', (int)$status );
        $copy->store();

        $this->copyData( $source, $copy );

        $copyId = (int)$copy->attribute( 'id' );
        foreach ( $children as $child )
        {
            $this->copyTree(
                $child, $zoneId, $layoutId, $status, $copyId,
                (string)$child->attribute( 'placeholder' ),
                (int)$child->attribute( 'position' )
            );
        }

        return $copy;
    }

    /**
     * Copies what a block owns besides its children onto another block:
     * parameters, then every collection with offset, limit, items and query.
     * The new collections take the status of the target block.
     */
    public function copyData( expLayoutsBlock $source, expLayoutsBlock $target )
    {
        $sourceId = (int)$source->attribute( 'id' );
        $targetId = (int)$target->attribute( 'id' );

        foreach ( expLayoutsBlockParameter::fetchByBlock( $sourceId ) as $param )
            expLayoutsBlockParameter::set( $targetId, (string)$param->attribute( 'name' ), (string)$param->attribute( 'value' ) );

        foreach ( $this->collectionsOf( $sourceId ) as $collection )
        {
            $collectionId = (int)$collection->attribute( 'id' );

            $newCollection = expLayoutsCollection::create( $targetId, (string)$collection->attribute( 'collection_type' ) );
            $newCollection->setAttribute( 'offset_value', (int)$collection->attribute( 'offset_value' ) );
            $newCollection->setAttribute( 'limit_value', (int)$collection->attribute( 'limit_value' ) );
            $newCollection->setAttribute( 'status', (int)$target->attribute( 'status' ) );
            $newCollection->store();
            $newCollectionId = (int)$newCollection->attribute( 'id' );

            $query = expLayoutsCollectionQuery::fetchByCollection( $collectionId );
            if ( $query )
                expLayoutsCollectionQuery::set( $newCollectionId, (string)$query->attribute( 'query_type' ), (string)$query->attribute( 'parameters' ) );

            foreach ( expLayoutsCollectionItem::fetchByCollection( $collectionId ) as $item )
            {
                $newItem = expLayoutsCollectionItem::create(
                    $newCollectionId,
                    (int)$item->attribute( 'value_id' ),
                    (string)$item->attribute( 'value_type' ),
                    (string)$item->attribute( 'item_type' )
                );
                $newItem->setAttribute( 'position', (int)$item->attribute( 'position' ) );
                $newItem->store();
            }
        }
    }

    /**
     * Every collection of a block, in the order they were made (a block
     * usually has one, the "default" collection).
     */
    public function collectionsOf( $blockId )
    {
        $list = eZPersistentObject::fetchObjectList(
            expLayoutsCollection::definition(), null,
            array( 'block_id' => (int)$blockId ),
            array( 'id' => 'asc' ), null, true
        );
        return is_array( $list ) ? $list : array();
    }

    /**
     * Whether $blockId is $ancestorId or lies anywhere below it.
     */
    public function isBlockOrDescendant( $blockId, $ancestorId )
    {
        $seen = array();
        $id = (int)$blockId;
        while ( $id > 0 && !isset( $seen[$id] ) )
        {
            if ( $id === (int)$ancestorId )
                return true;
            $seen[$id] = true;
            $block = expLayoutsBlock::fetch( $id );
            $id = $block instanceof expLayoutsBlock ? (int)$block->attribute( 'parent_id' ) : 0;
        }
        return false;
    }
}
