<?php
/**
 * Builds the documents of a layouts transfer archive from the database.
 *
 * A selection names the layouts, the shared layouts and the rules to export.
 * Every shared layout a selected layout links a zone to is always exported
 * too (marked "dependency"), followed through link chains, so an archive can
 * be imported without leaving a link pointing nowhere.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 */
class expLayoutsCoreTransferExporter
{
    const STATUS_DRAFT = 1;
    const STATUS_PUBLISHED = 2;

    /** @var expLayoutsCoreTransferReferences */
    protected $references;

    protected $warnings = array();

    /** kind => id => true, the references met while exporting */
    protected $met = array( 'node' => array(), 'object' => array() );

    public function __construct( ?expLayoutsCoreTransferReferences $references = null )
    {
        $this->references = $references ? $references : new expLayoutsCoreTransferReferences();
    }

    public function warnings()
    {
        return $this->warnings;
    }

    /**
     * Normalises a selection.
     *
     * layouts: 'all' (every layout that is not shared), 'none' or a list of ids or identifiers
     * shared:  'all', 'none' or a list of ids or identifiers of shared layouts
     * rules:   'none', 'selected' (the rules of the selected layouts) or 'all'
     * drafts:  true to export the open drafts as well
     */
    public static function normaliseSelection( array $selection )
    {
        $normalised = array( 'layouts' => 'none', 'shared' => 'none', 'rules' => 'none', 'drafts' => false );
        foreach ( array( 'layouts', 'shared' ) as $key )
        {
            if ( !isset( $selection[$key] ) )
                continue;
            $value = $selection[$key];
            if ( $value === 'all' || $value === 'none' )
                $normalised[$key] = $value;
            elseif ( is_array( $value ) || is_string( $value ) )
            {
                $list = is_array( $value ) ? $value : explode( ',', $value );
                $list = array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', $list ) ), 'strlen' ) ) );
                $normalised[$key] = $list ? $list : 'none';
            }
        }
        if ( isset( $selection['rules'] ) && in_array( $selection['rules'], array( 'none', 'selected', 'all' ), true ) )
            $normalised['rules'] = $selection['rules'];
        $normalised['drafts'] = !empty( $selection['drafts'] );
        return $normalised;
    }

    /**
     * The layout identifiers a list of ids or identifiers names; unknown entries are reported.
     */
    protected function identifiersOf( array $list )
    {
        $identifiers = array();
        foreach ( $list as $entry )
        {
            $layout = ctype_digit( (string)$entry ) ? expLayoutsLayout::fetch( (int)$entry ) : false;
            if ( !$layout )
            {
                $layout = expLayoutsLayout::fetchByIdentifier( (string)$entry, self::STATUS_PUBLISHED );
                if ( !$layout )
                    $layout = expLayoutsLayout::fetchByIdentifier( (string)$entry, self::STATUS_DRAFT );
            }
            if ( !$layout )
            {
                $this->warnings[] = 'No layout "' . $entry . '" was found.';
                continue;
            }
            $identifiers[(string)$layout->attribute( 'identifier' )] = true;
        }
        return array_keys( $identifiers );
    }

    /**
     * The documents of an archive for a selection: layouts.json, rules.json, references.json.
     */
    public function export( array $selection )
    {
        $selection = self::normaliseSelection( $selection );
        $this->warnings = array();
        $this->met = array( 'node' => array(), 'object' => array() );

        $all = expLayoutsLayout::fetchList();
        $rowsByIdentifier = array();
        foreach ( is_array( $all ) ? $all : array() as $layout )
            $rowsByIdentifier[(string)$layout->attribute( 'identifier' )][(int)$layout->attribute( 'status' )] = $layout;

        $wanted = array();
        foreach ( array( 'layouts' => false, 'shared' => true ) as $key => $shared )
        {
            if ( $selection[$key] === 'all' )
            {
                foreach ( $rowsByIdentifier as $identifier => $rows )
                {
                    $row = isset( $rows[self::STATUS_PUBLISHED] ) ? $rows[self::STATUS_PUBLISHED] : reset( $rows );
                    if ( ( (int)$row->attribute( 'shared' ) === 1 ) === $shared )
                        $wanted[$identifier] = true;
                }
            }
            elseif ( is_array( $selection[$key] ) )
            {
                foreach ( $this->identifiersOf( $selection[$key] ) as $identifier )
                    $wanted[$identifier] = true;
            }
        }

        // The rules: those of the chosen layouts, or all (whose layouts then come along).
        $ruleRows = array();
        if ( $selection['rules'] !== 'none' )
        {
            $rules = eZPersistentObject::fetchObjectList( expLayoutsRule::definition(), null, null, array( 'priority' => 'desc', 'id' => 'asc' ), null, true );
            foreach ( is_array( $rules ) ? $rules : array() as $rule )
            {
                $layout = expLayoutsLayout::fetch( (int)$rule->attribute( 'layout_id' ) );
                if ( !$layout )
                {
                    if ( $selection['rules'] === 'all' )
                        $this->warnings[] = 'Rule ' . (int)$rule->attribute( 'id' ) . ' maps to a layout that does not exist and was left out.';
                    continue;
                }
                $identifier = (string)$layout->attribute( 'identifier' );
                if ( $selection['rules'] === 'selected' && !isset( $wanted[$identifier] ) )
                    continue;
                if ( !isset( $rowsByIdentifier[$identifier][self::STATUS_PUBLISHED] ) )
                {
                    $this->warnings[] = 'Rule ' . (int)$rule->attribute( 'id' ) . ' maps to the layout "' . $identifier . '", which is not published, and was left out.';
                    continue;
                }
                $wanted[$identifier] = true;
                $ruleRows[] = array( $rule, $identifier );
            }
        }

        // The layouts, with every layout they link to.
        $layouts = array();
        $done = array();
        $queue = array();
        foreach ( array_keys( $wanted ) as $identifier )
            $queue[] = array( $identifier, false );
        while ( $queue )
        {
            list( $identifier, $dependency ) = array_shift( $queue );
            if ( isset( $done[$identifier] ) )
                continue;
            $done[$identifier] = true;
            $rows = isset( $rowsByIdentifier[$identifier] ) ? $rowsByIdentifier[$identifier] : array();
            $statuses = $dependency || !$selection['drafts'] ? array( self::STATUS_PUBLISHED ) : array( self::STATUS_PUBLISHED, self::STATUS_DRAFT );
            $exported = false;
            foreach ( $statuses as $status )
            {
                if ( !isset( $rows[$status] ) )
                    continue;
                $document = $this->exportLayout( $rows[$status], $dependency );
                $layouts[] = $document;
                $exported = true;
                foreach ( $document['zones'] as $zone )
                    if ( $zone['link'] !== null && !isset( $done[$zone['link']['layout']] ) )
                        $queue[] = array( $zone['link']['layout'], !isset( $wanted[$zone['link']['layout']] ) );
            }
            if ( !$exported )
                $this->warnings[] = $dependency
                    ? 'The linked layout "' . $identifier . '" is not published and could not be exported.'
                    : 'The layout "' . $identifier . '" is not published; export drafts to take it along.';
        }

        // A link whose layout could not be exported would break on import: the zone is exported without it.
        $published = array();
        foreach ( $layouts as $layout )
            if ( $layout['status'] === 'published' )
                $published[$layout['identifier']] = true;
        foreach ( $layouts as &$layout )
            foreach ( $layout['zones'] as &$zone )
                if ( $zone['link'] !== null && !isset( $published[$zone['link']['layout']] ) )
                {
                    $this->warnings[] = 'The zone "' . $zone['identifier'] . '" of "' . $layout['identifier'] . '" links to "' . $zone['link']['layout'] . '", which is not published; the link was left out.';
                    $zone['link'] = null;
                }
        unset( $layout, $zone );

        usort( $layouts, function ( $a, $b )
        {
            if ( $a['shared'] !== $b['shared'] )
                return $a['shared'] ? -1 : 1;
            $c = strcmp( $a['identifier'], $b['identifier'] );
            return $c !== 0 ? $c : strcmp( $b['status'], $a['status'] );
        } );

        $rulesDocument = array();
        foreach ( $ruleRows as $entry )
            $rulesDocument[] = $this->exportRule( $entry[0], $entry[1] );

        return array(
            'layouts.json' => array( 'layouts' => $layouts ),
            'rules.json' => array( 'rules' => $rulesDocument ),
            'references.json' => $this->referenceTable(),
        );
    }

    /**
     * Every layout of the installation, drafts included, and every rule: what
     * an import compares an archive with.
     */
    public function exportEverything()
    {
        return $this->export( array( 'layouts' => 'all', 'shared' => 'all', 'rules' => 'all', 'drafts' => true ) );
    }

    protected function reference( $kind, $id )
    {
        $id = (int)$id;
        if ( $id <= 0 )
            return null;
        $this->met[$kind][$id] = true;
        return $kind . ':' . $id;
    }

    protected function referenceTable()
    {
        $table = array( 'node' => array(), 'object' => array() );
        foreach ( $this->met as $kind => $ids )
        {
            ksort( $ids );
            foreach ( array_keys( $ids ) as $id )
            {
                $entry = $this->references->describe( $kind, $id );
                if ( $entry['remote_id'] === null )
                    $this->warnings[] = 'The ' . $kind . ' ' . $id . ' does not exist; it cannot be mapped on another installation.';
                $table[$kind][(string)$id] = $entry;
            }
        }
        // Empty tables are objects in JSON, as the format requires.
        foreach ( $table as $kind => $entries )
            if ( !$entries )
                $table[$kind] = new stdClass();
        return $table;
    }

    public function exportLayout( expLayoutsLayout $layout, $dependency = false )
    {
        $layoutId = (int)$layout->attribute( 'id' );
        $status = (int)$layout->attribute( 'status' );
        $identifier = (string)$layout->attribute( 'identifier' );

        // One zone per identifier. Rows an earlier copy duplicated are merged
        // the way expLayoutsCoreLayoutService::copy() merges them: the row
        // with a link (else the most blocks) gives the link and position, and
        // the blocks of every row are kept.
        $groups = array();
        foreach ( expLayoutsZone::fetchByLayout( $layoutId, $status ) as $zone )
            $groups[(string)$zone->attribute( 'identifier' )][] = $zone;

        $zones = array();
        foreach ( $groups as $zoneIdentifier => $rows )
        {
            if ( count( $rows ) > 1 )
                $this->warnings[] = 'The layout "' . $identifier . '" has ' . count( $rows ) . ' rows for the zone "' . $zoneIdentifier . '"; they were exported as one.';

            $blocksByRow = array();
            $primary = 0;
            foreach ( $rows as $index => $zone )
            {
                $blocksByRow[$index] = array();
                foreach ( expLayoutsBlock::fetchByZone( (int)$zone->attribute( 'id' ), $status ) as $block )
                    if ( (int)$block->attribute( 'parent_id' ) <= 0 )
                        $blocksByRow[$index][] = $block;
                $linked = $zone->isLinked();
                $bestLinked = $rows[$primary]->isLinked();
                if ( ( $linked && !$bestLinked ) || ( $linked === $bestLinked && count( $blocksByRow[$index] ) > count( $blocksByRow[$primary] ) ) )
                    $primary = $index;
            }
            $zone = $rows[$primary];

            $link = null;
            if ( $zone->isLinked() )
            {
                $linkedLayout = expLayoutsLayout::fetch( (int)$zone->attribute( 'linked_layout_id' ) );
                // The link is kept as declared (layout and zone), addressed by
                // identifier; resolveSource() tells whether it still leads to a zone.
                if ( $linkedLayout && expLayoutsZone::resolveLinkedSource( $zone ) )
                    $link = array( 'layout' => (string)$linkedLayout->attribute( 'identifier' ), 'zone' => (string)$zone->attribute( 'linked_zone_identifier' ) );
                else
                    $this->warnings[] = 'The zone "' . $zoneIdentifier . '" of "' . $identifier . '" links to a zone that does not exist; the link was left out.';
            }

            $blocks = array();
            $order = array_merge( array( $primary ), array_diff( array_keys( $rows ), array( $primary ) ) );
            foreach ( $order as $index )
                foreach ( $blocksByRow[$index] as $block )
                    $blocks[] = $this->exportBlock( $block, 0 );

            $zones[] = array(
                'identifier' => $zoneIdentifier,
                'position' => (int)$zone->attribute( 'position' ),
                'link' => $link,
                'blocks' => $blocks,
            );
        }

        return array(
            'id' => $layoutId,
            'identifier' => $identifier,
            'name' => (string)$layout->attribute( 'name' ),
            'layout_type' => (string)$layout->attribute( 'layout_type' ),
            'shared' => (int)$layout->attribute( 'shared' ) === 1,
            'status' => $status === self::STATUS_PUBLISHED ? 'published' : 'draft',
            'dependency' => (bool)$dependency,
            'created' => (int)$layout->attribute( 'created' ),
            'modified' => (int)$layout->attribute( 'modified' ),
            'zones' => $zones,
        );
    }

    protected function exportBlock( expLayoutsBlock $block, $depth )
    {
        $blockId = (int)$block->attribute( 'id' );

        $parameters = array();
        $parameterRefs = array();
        foreach ( expLayoutsBlockParameter::fetchByBlock( $blockId ) as $parameter )
        {
            $name = (string)$parameter->attribute( 'name' );
            $value = $parameter->attribute( 'value' );
            $parameters[$name] = $value === null ? null : (string)$value;
            if ( in_array( $name, expLayoutsCoreTransferDiff::$nodeParameters, true ) && ctype_digit( (string)$value ) && (int)$value > 0 )
                $parameterRefs[$name] = $this->reference( 'node', (int)$value );
        }
        ksort( $parameters );
        ksort( $parameterRefs );

        $collections = array();
        $blockService = new expLayoutsCoreBlockService();
        foreach ( $blockService->collectionsOf( $blockId ) as $collection )
        {
            $collectionId = (int)$collection->attribute( 'id' );
            $items = array();
            foreach ( expLayoutsCollectionItem::fetchByCollection( $collectionId ) as $item )
            {
                $valueType = (string)$item->attribute( 'value_type' );
                $valueId = (int)$item->attribute( 'value_id' );
                $ref = null;
                if ( $valueType === 'ez_location' )
                    $ref = $this->reference( 'node', $valueId );
                elseif ( $valueType === 'ez_content' )
                    $ref = $this->reference( 'object', $valueId );
                $items[] = array(
                    'position' => (int)$item->attribute( 'position' ),
                    'value_type' => $valueType,
                    'item_type' => (string)$item->attribute( 'item_type' ),
                    'value_id' => $valueId,
                    'ref' => $ref,
                );
            }

            $query = null;
            $queryRow = expLayoutsCollectionQuery::fetchByCollection( $collectionId );
            if ( $queryRow )
            {
                $raw = (string)$queryRow->attribute( 'parameters' );
                $refs = array();
                $decoded = json_decode( $raw, true );
                if ( is_array( $decoded ) )
                    foreach ( expLayoutsCoreTransferDiff::$queryReferenceKeys as $key => $kind )
                        if ( isset( $decoded[$key] ) && is_numeric( $decoded[$key] ) && (int)$decoded[$key] > 0 )
                            $refs[$key] = $this->reference( $kind, (int)$decoded[$key] );
                $query = array( 'query_type' => (string)$queryRow->attribute( 'query_type' ), 'parameters' => $raw, 'refs' => $refs ? $refs : new stdClass() );
            }

            $collections[] = array(
                'collection_type' => (string)$collection->attribute( 'collection_type' ),
                'offset' => (int)$collection->attribute( 'offset_value' ),
                'limit' => (int)$collection->attribute( 'limit_value' ),
                'query' => $query,
                'items' => $items,
            );
        }

        $children = array();
        if ( $depth < 20 )
            foreach ( expLayoutsBlock::fetchChildren( $blockId, (int)$block->attribute( 'status' ) ) as $child )
                $children[] = $this->exportBlock( $child, $depth + 1 );

        return array(
            'definition_identifier' => (string)$block->attribute( 'definition_identifier' ),
            'name' => (string)$block->attribute( 'name' ),
            'view_type' => (string)$block->attribute( 'view_type' ),
            'item_view_type' => (string)$block->attribute( 'item_view_type' ),
            'position' => (int)$block->attribute( 'position' ),
            'placeholder' => (string)$block->attribute( 'placeholder' ),
            'parameters' => $parameters ? $parameters : new stdClass(),
            'parameter_refs' => $parameterRefs ? $parameterRefs : new stdClass(),
            'collections' => $collections,
            'children' => $children,
        );
    }

    protected function exportRule( expLayoutsRule $rule, $identifier )
    {
        $targets = array();
        foreach ( expLayoutsRuleTarget::fetchByRule( (int)$rule->attribute( 'id' ) ) as $target )
        {
            $type = (string)$target->attribute( 'target_type' );
            $value = (string)$target->attribute( 'target_value' );
            $ref = in_array( $type, expLayoutsCoreTransferDiff::$nodeTargets, true ) && ctype_digit( $value ) && (int)$value > 0
                ? $this->reference( 'node', (int)$value ) : null;
            $targets[] = array( 'type' => $type, 'value' => $value, 'ref' => $ref );
        }
        $conditions = array();
        foreach ( expLayoutsRuleCondition::fetchByRule( (int)$rule->attribute( 'id' ) ) as $condition )
            $conditions[] = array( 'type' => (string)$condition->attribute( 'condition_type' ), 'value' => (string)$condition->attribute( 'condition_value' ) );

        return array(
            'id' => (int)$rule->attribute( 'id' ),
            'layout' => (string)$identifier,
            'priority' => (int)$rule->attribute( 'priority' ),
            'enabled' => (int)$rule->attribute( 'enabled' ) === 1,
            'targets' => $targets,
            'conditions' => $conditions,
        );
    }
}
