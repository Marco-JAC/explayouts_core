<?php
/**
 * Writes a planned layouts transfer into the database, in one transaction.
 *
 * It is a layout service so it can use the service's own steps (drafts,
 * publishing, copy(), clearing a layout's content) without clearing the whole
 * resolver cache after each one: only the cache entries of the layouts and
 * rules the import touched are removed, once, after the commit.
 *
 * Actions (see expLayoutsCoreTransferDiff::action()):
 *   create     a new layout or rule, as in the file
 *   overwrite  a layout gets a draft with the file's content, published only
 *              when the import is asked to publish; a rule is updated
 *   copy       a layout becomes a copy (copy()) holding the file's content, a
 *              draft unless asked to publish; a rule is created disabled
 *   skip/none  nothing is written
 *
 * Zone links are written by the linked layout's identifier, looked up on this
 * installation after the linked layouts themselves were written, and checked
 * to reach a zone; a link that cannot be made stops the import.
 *
 * @copyright Copyright (C) 1998 - 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 */
class expLayoutsCoreTransferImporter extends expLayoutsCoreLayoutService
{
    protected $archive = array();
    protected $refs = array();

    /** @var expLayoutsCoreTransferReferences */
    protected $references;

    protected $unmappedPolicy = 'drop';

    /** "kind:id" => target id or null */
    protected $mapped = array();

    protected $report = array();

    protected $affectedLayouts = array();
    protected $affectedRules = array();

    public function __construct( ?expLayoutsCoreTransferReferences $references = null )
    {
        $this->references = $references ? $references : new expLayoutsCoreTransferReferences();
    }

    /** The service's whole-cache clear is replaced by the selective clear after the commit. */
    protected function clearResolverCache()
    {
    }

    /**
     * Every reference of the archive with the id it has on this installation.
     *
     * @return array mapped ("kind:id" => target id or null) and unmapped (list of kind, id, remote_id, path)
     */
    public function mapReferences( array $archive )
    {
        $refs = isset( $archive['references.json'] ) ? $archive['references.json'] : array();
        $mapped = array();
        $unmapped = array();
        foreach ( array( 'node', 'object' ) as $kind )
        {
            foreach ( isset( $refs[$kind] ) ? (array)$refs[$kind] : array() as $id => $entry )
            {
                $target = $this->references->map( $kind, (int)$id, $refs );
                $mapped[$kind . ':' . (int)$id] = $target;
                if ( $target === null )
                    $unmapped[] = array( 'kind' => $kind, 'id' => (int)$id,
                                         'remote_id' => isset( $entry['remote_id'] ) ? $entry['remote_id'] : null,
                                         'path' => isset( $entry['path'] ) ? $entry['path'] : null );
            }
        }
        return array( 'mapped' => $mapped, 'unmapped' => $unmapped );
    }

    /**
     * Writes the plan. Returns a report: ok, errors, written (list of what was
     * done), affected_layouts, affected_rules, cache_entries_removed, page_cache_purged.
     *
     * @param array $options strategy, items (plan key => strategy), publish, unmapped (drop, keep, fail)
     */
    public function import( array $archive, array $plan, array $options = array() )
    {
        $strategy = isset( $options['strategy'] ) && in_array( $options['strategy'], expLayoutsCoreTransferFormat::strategies(), true ) ? $options['strategy'] : 'skip';
        $perItem = isset( $options['items'] ) && is_array( $options['items'] ) ? $options['items'] : array();
        $publish = !empty( $options['publish'] );
        $this->unmappedPolicy = isset( $options['unmapped'] ) && in_array( $options['unmapped'], array( 'drop', 'keep', 'fail' ), true ) ? $options['unmapped'] : 'drop';

        $this->archive = $archive;
        $this->refs = isset( $archive['references.json'] ) ? $archive['references.json'] : array();
        $this->report = array( 'ok' => false, 'errors' => array(), 'warnings' => array(), 'written' => array(),
                               'affected_layouts' => array(), 'affected_rules' => array(), 'cache_entries_removed' => 0,
                               'page_cache_purged' => false );
        $this->affectedLayouts = array();
        $this->affectedRules = array();

        $mapping = $this->mapReferences( $archive );
        $this->mapped = $mapping['mapped'];
        if ( $mapping['unmapped'] && $this->unmappedPolicy === 'fail' )
        {
            $this->report['errors'][] = count( $mapping['unmapped'] ) . ' node or object references cannot be mapped on this installation; nothing was written.';
            return $this->report;
        }

        $documents = array();
        foreach ( isset( $archive['layouts.json']['layouts'] ) ? $archive['layouts.json']['layouts'] : array() as $layout )
            $documents[expLayoutsCoreTransferDiff::layoutKey( $layout )] = $layout;
        $rules = array();
        foreach ( isset( $archive['rules.json']['rules'] ) ? $archive['rules.json']['rules'] : array() as $rule )
            $rules[expLayoutsCoreTransferDiff::ruleKey( $rule, $this->refs )] = $rule;

        $layoutItems = $this->orderByLinks( $plan['layouts'], $documents );

        $db = eZDB::instance();
        $db->setErrorHandling( eZDB::ERROR_HANDLING_EXCEPTIONS );
        $db->begin();
        try
        {
            foreach ( $layoutItems as $item )
            {
                $action = expLayoutsCoreTransferDiff::action( $item, $strategy, $perItem );
                if ( $action === 'none' || $action === 'skip' )
                    continue;
                $this->writeLayout( $item, $documents[$item['key']], $action, $publish );
            }
            foreach ( $plan['rules'] as $item )
            {
                $action = expLayoutsCoreTransferDiff::action( $item, $strategy, $perItem );
                if ( $action === 'none' || $action === 'skip' )
                    continue;
                $this->writeRule( $item, $rules[$item['key']], $action );
            }
            $db->commit();
            $this->report['ok'] = true;
        }
        catch ( Exception $e )
        {
            if ( $db->transactionCounter() > 0 )
                $db->rollback();
            $this->report['errors'][] = $e->getMessage();
            $this->report['written'] = array();
            $this->affectedLayouts = array();
            $this->affectedRules = array();
        }
        $db->setErrorHandling( eZDB::ERROR_HANDLING_STANDARD );

        $this->report['affected_layouts'] = array_values( array_unique( $this->affectedLayouts ) );
        $this->report['affected_rules'] = array_values( array_unique( $this->affectedRules ) );
        if ( $this->report['ok'] )
        {
            $this->report['cache_entries_removed'] = self::clearResolverCacheFor( $this->report['affected_layouts'], $this->report['affected_rules'] );
            $this->report['page_cache_purged'] = self::pagesChanged( $this->report, $publish ) && self::purgePageCaches();
        }
        return $this->report;
    }

    /**
     * Whether what an import wrote can change pages of the site: a published layout or a rule. A layout imported as
     * a draft changes nothing the site shows.
     *
     * @param array $report the report of import()
     * @param bool $publish whether the layouts were published
     * @return bool
     */
    public static function pagesChanged( array $report, $publish )
    {
        if ( empty( $report['ok'] ) || empty( $report['written'] ) )
            return false;
        return $publish || !empty( $report['affected_rules'] );
    }

    /**
     * Purges every page of the page caches (the HTTP page cache, which Velocity answers from too), as a write in the
     * layout editor does (ezpHttpCacheListener::requestInput()). An import on the command line made no request to
     * the editor, so the pages kept the old layout until the cache was cleared by hand.
     *
     * @return bool whether a page cache was there to purge
     */
    public static function purgePageCaches()
    {
        if ( !class_exists( 'ezpHttpCacheListener' ) || !method_exists( 'ezpHttpCacheListener', 'purgeTags' ) )
            return false;
        try
        {
            ezpHttpCacheListener::purgeTags( array( 'ez-all' ) );
        }
        catch ( Exception $e )
        {
            if ( class_exists( 'eZDebug' ) )
                eZDebug::writeWarning( 'The page cache was not purged after the import: ' . $e->getMessage(), __METHOD__ );
            return false;
        }
        return true;
    }

    /**
     * Layouts that others link to come first, so a link can be made by identifier.
     */
    protected function orderByLinks( array $items, array $documents )
    {
        $byIdentifier = array();
        foreach ( $items as $item )
            $byIdentifier[$item['identifier']][] = $item;

        $ordered = array();
        $visiting = array();
        $visit = function ( $identifier ) use ( &$visit, &$ordered, &$visiting, $byIdentifier, $documents )
        {
            if ( isset( $visiting[$identifier] ) || !isset( $byIdentifier[$identifier] ) )
                return;
            $visiting[$identifier] = true;
            foreach ( $byIdentifier[$identifier] as $item )
                foreach ( isset( $documents[$item['key']]['zones'] ) ? $documents[$item['key']]['zones'] : array() as $zone )
                    if ( !empty( $zone['link']['layout'] ) )
                        $visit( $zone['link']['layout'] );
            foreach ( $byIdentifier[$identifier] as $item )
                $ordered[] = $item;
        };
        foreach ( array_keys( $byIdentifier ) as $identifier )
            $visit( $identifier );
        return $ordered;
    }

    protected function check( $condition, $message )
    {
        if ( !$condition )
            throw new RuntimeException( $message );
    }

    protected function writeLayout( array $item, array $document, $action, $publish )
    {
        $status = $document['status'] === 'published' ? 2 : 1;
        $identifier = (string)$document['identifier'];

        if ( $action === 'create' )
        {
            // A new layout is written in the status it has in the file, zones,
            // blocks and collections included; nothing on this installation
            // has that identifier and status yet (the plan said "new").
            $this->check( !expLayoutsLayout::fetchByIdentifier( $identifier, $status ),
                'The layout "' . $identifier . '" exists on this installation already; it was not created.' );
            $layout = expLayoutsLayout::create( $identifier );
            $this->setLayoutAttributes( $layout, $document, '' );
            $layout->setAttribute( 'status', $status );
            $layout->store();
            $this->writeZones( $layout, $document );
            $this->affectedLayouts[] = (int)$layout->attribute( 'id' );
            $this->written( 'layout', $item['key'], $status === 2 ? 'created and published' : 'created as a draft', $layout );
            return;
        }

        $this->check( $item['target_id'] !== null && expLayoutsLayout::fetch( (int)$item['target_id'] ), 'The layout "' . $identifier . '" is no longer on this installation.' );

        if ( $action === 'overwrite' )
        {
            // A published layout is overwritten through a draft, so it changes on
            // the site only when the import publishes (or an editor does later).
            $draft = $this->createDraft( (int)$item['target_id'] );
            $this->check( $draft instanceof expLayoutsLayout, 'No draft of "' . $identifier . '" could be made.' );
            $this->clearLayoutContent( $draft );
            $this->setLayoutAttributes( $draft, $document, '' );
            $draft->store();
            $this->writeZones( $draft, $document );
            $this->affectedLayouts[] = (int)$item['target_id'];
            $this->affectedLayouts[] = (int)$draft->attribute( 'id' );
            $result = $draft;
            if ( $status === 2 && $publish )
            {
                $result = $this->publishUncleared( (int)$draft->attribute( 'id' ) );
                $this->check( (bool)$result, 'The layout "' . $identifier . '" could not be published.' );
                $this->affectedLayouts[] = (int)$result->attribute( 'id' );
            }
            $this->written( 'layout', $item['key'], $result === $draft ? 'overwritten as a draft' : 'overwritten and published', $result );
            return;
        }

        // copy: the layout service's copy() of the existing layout makes the
        // new identifier and row; its content is then replaced by the file's.
        $copy = $this->copy( (int)$item['target_id'] );
        $this->check( $copy instanceof expLayoutsLayout, 'No copy of "' . $identifier . '" could be made.' );
        $this->clearLayoutContent( $copy );
        $this->setLayoutAttributes( $copy, $document, ' (copy)' );
        $copy->store();
        $this->writeZones( $copy, $document );
        $this->affectedLayouts[] = (int)$copy->attribute( 'id' );
        $result = $copy;
        if ( $publish )
        {
            $result = $this->publishUncleared( (int)$copy->attribute( 'id' ) );
            $this->check( (bool)$result, 'The copy of "' . $identifier . '" could not be published.' );
            $this->affectedLayouts[] = (int)$result->attribute( 'id' );
        }
        $this->written( 'layout', $item['key'], $publish ? 'copied and published' : 'copied as a draft', $result );
    }

    protected function setLayoutAttributes( expLayoutsLayout $layout, array $document, $nameSuffix )
    {
        $layout->setAttribute( 'name', (string)$document['name'] . $nameSuffix );
        $layout->setAttribute( 'layout_type', (string)$document['layout_type'] );
        $layout->setAttribute( 'shared', !empty( $document['shared'] ) ? 1 : 0 );
        $layout->setAttribute( 'modified', time() );
        if ( !(int)$layout->attribute( 'created' ) )
            $layout->setAttribute( 'created', time() );
    }

    protected function written( $kind, $key, $what, $object )
    {
        $entry = array( 'kind' => $kind, 'key' => $key, 'what' => $what, 'id' => $object ? (int)$object->attribute( 'id' ) : null );
        if ( $kind === 'layout' && $object )
            $entry['identifier'] = (string)$object->attribute( 'identifier' );
        $this->report['written'][] = $entry;
    }

    protected function writeZones( expLayoutsLayout $layout, array $document )
    {
        $layoutId = (int)$layout->attribute( 'id' );
        $status = (int)$layout->attribute( 'status' );
        foreach ( $document['zones'] as $zoneData )
        {
            $zone = expLayoutsZone::create( $layoutId, (string)$zoneData['identifier'], $status );
            $zone->setAttribute( 'position', isset( $zoneData['position'] ) ? (int)$zoneData['position'] : 0 );
            if ( !empty( $zoneData['link'] ) )
            {
                $target = expLayoutsLayout::fetchByIdentifier( (string)$zoneData['link']['layout'], 2 );
                $this->check( (bool)$target, 'The zone "' . $zoneData['identifier'] . '" of "' . $document['identifier'] . '" links to "' . $zoneData['link']['layout'] . '", which is not published on this installation.' );
                $zone->setAttribute( 'linked_layout_id', (int)$target->attribute( 'id' ) );
                $zone->setAttribute( 'linked_zone_identifier', (string)$zoneData['link']['zone'] );
                $this->check( (bool)expLayoutsZone::resolveLinkedSource( $zone ),
                    'The zone "' . $zoneData['identifier'] . '" of "' . $document['identifier'] . '" links to the zone "' . $zoneData['link']['zone'] . '" of "' . $zoneData['link']['layout'] . '", which does not exist.' );
            }
            $zone->store();
            foreach ( $zoneData['blocks'] as $blockData )
                $this->writeBlock( $blockData, $zone, $layoutId, $status, 0, isset( $blockData['placeholder'] ) ? (string)$blockData['placeholder'] : '' );
        }
    }

    /**
     * The id on this installation for a reference, or null when it is dropped.
     */
    protected function mapReference( $reference, $fallback )
    {
        $parsed = expLayoutsCoreTransferFormat::parseReference( $reference );
        if ( !$parsed )
            return $fallback;
        $key = $parsed[0] . ':' . $parsed[1];
        if ( isset( $this->mapped[$key] ) )
            return $this->mapped[$key];
        return $this->unmappedPolicy === 'keep' ? $parsed[1] : null;
    }

    protected function writeBlock( array $data, expLayoutsZone $zone, $layoutId, $status, $parentId, $placeholder )
    {
        $block = expLayoutsBlock::create( (int)$zone->attribute( 'id' ), $layoutId, (string)$data['definition_identifier'], isset( $data['name'] ) ? (string)$data['name'] : '' );
        $block->setAttribute( 'view_type', isset( $data['view_type'] ) ? (string)$data['view_type'] : 'default' );
        $block->setAttribute( 'item_view_type', isset( $data['item_view_type'] ) ? (string)$data['item_view_type'] : '' );
        $block->setAttribute( 'position', isset( $data['position'] ) ? (int)$data['position'] : 0 );
        $block->setAttribute( 'parent_id', (int)$parentId );
        // As in the file: top-level blocks of some installations carry "root".
        $block->setAttribute( 'placeholder', (string)$placeholder );
        $block->setAttribute( 'status', (int)$status );
        $block->store();
        $blockId = (int)$block->attribute( 'id' );

        $parameterRefs = isset( $data['parameter_refs'] ) ? (array)$data['parameter_refs'] : array();
        foreach ( isset( $data['parameters'] ) ? (array)$data['parameters'] : array() as $name => $value )
        {
            if ( isset( $parameterRefs[$name] ) )
            {
                $mapped = $this->mapReference( $parameterRefs[$name], $value );
                $value = $mapped === null ? '' : (string)$mapped;
            }
            $parameter = new expLayoutsBlockParameter( array( 'block_id' => $blockId, 'name' => (string)$name, 'value' => $value === null ? null : (string)$value ) );
            $parameter->store();
        }

        foreach ( isset( $data['collections'] ) ? $data['collections'] : array() as $collectionData )
        {
            $collection = expLayoutsCollection::create( $blockId, isset( $collectionData['collection_type'] ) ? (string)$collectionData['collection_type'] : 'manual' );
            $collection->setAttribute( 'offset_value', isset( $collectionData['offset'] ) ? (int)$collectionData['offset'] : 0 );
            $collection->setAttribute( 'limit_value', isset( $collectionData['limit'] ) ? (int)$collectionData['limit'] : 0 );
            $collection->setAttribute( 'status', (int)$status );
            $collection->store();
            $collectionId = (int)$collection->attribute( 'id' );

            if ( !empty( $collectionData['query'] ) )
            {
                $parameters = isset( $collectionData['query']['parameters'] ) ? (string)$collectionData['query']['parameters'] : '';
                $queryRefs = isset( $collectionData['query']['refs'] ) ? (array)$collectionData['query']['refs'] : array();
                $decoded = $queryRefs ? json_decode( $parameters, true ) : null;
                if ( is_array( $decoded ) )
                {
                    // Only the referenced keys change; the rest of the JSON stays byte for byte.
                    $changed = false;
                    foreach ( $queryRefs as $key => $reference )
                    {
                        if ( !array_key_exists( $key, $decoded ) )
                            continue;
                        $mapped = $this->mapReference( $reference, $decoded[$key] );
                        if ( $mapped !== $decoded[$key] )
                        {
                            $decoded[$key] = $mapped;
                            $changed = true;
                        }
                    }
                    if ( $changed )
                        $parameters = json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                }
                expLayoutsCollectionQuery::set( $collectionId, (string)$collectionData['query']['query_type'], $parameters );
            }

            foreach ( isset( $collectionData['items'] ) ? $collectionData['items'] : array() as $itemData )
            {
                $valueId = (int)$itemData['value_id'];
                if ( !empty( $itemData['ref'] ) )
                {
                    $mapped = $this->mapReference( $itemData['ref'], $valueId );
                    if ( $mapped === null )
                    {
                        $this->report['warnings'][] = 'A collection item (' . $itemData['ref'] . ') of a "' . $data['definition_identifier'] . '" block was left out: it has no match on this installation.';
                        continue;
                    }
                    $valueId = (int)$mapped;
                }
                $item = expLayoutsCollectionItem::create( $collectionId, $valueId,
                    (string)$itemData['value_type'], isset( $itemData['item_type'] ) ? (string)$itemData['item_type'] : 'manual' );
                $item->setAttribute( 'position', isset( $itemData['position'] ) ? (int)$itemData['position'] : 0 );
                $item->store();
            }
        }

        foreach ( isset( $data['children'] ) ? $data['children'] : array() as $child )
            $this->writeBlock( $child, $zone, $layoutId, $status, $blockId, isset( $child['placeholder'] ) ? (string)$child['placeholder'] : '' );

        return $block;
    }

    protected function writeRule( array $item, array $rule, $action )
    {
        $layout = expLayoutsLayout::fetchByIdentifier( (string)$rule['layout'], 2 );
        if ( !$layout )
        {
            $this->report['warnings'][] = 'A rule of "' . $rule['layout'] . '" was not written: that layout is not published on this installation.';
            return;
        }

        $targets = array();
        foreach ( $rule['targets'] as $target )
        {
            $value = (string)$target['value'];
            if ( !empty( $target['ref'] ) )
            {
                $mapped = $this->mapReference( $target['ref'], $value );
                if ( $mapped === null )
                {
                    // A rule without one of its targets could match pages it never matched.
                    $this->report['warnings'][] = 'A rule of "' . $rule['layout'] . '" was not written: its target ' . $target['type'] . ' ' . $value . ' has no match on this installation.';
                    return;
                }
                $value = (string)$mapped;
            }
            $targets[] = array( (string)$target['type'], $value );
        }

        $priority = isset( $rule['priority'] ) ? (int)$rule['priority'] : null;
        $enabled = !empty( $rule['enabled'] ) ? 1 : 0;

        if ( $action === 'overwrite' && $item['target_id'] )
        {
            $existing = expLayoutsRule::fetch( (int)$item['target_id'] );
            $this->check( (bool)$existing, 'A rule of "' . $rule['layout'] . '" is no longer on this installation.' );
            $this->affectedRules[] = (int)$existing->attribute( 'id' );
            $this->affectedLayouts[] = (int)$existing->attribute( 'layout_id' );
            $existing->setAttribute( 'layout_id', (int)$layout->attribute( 'id' ) );
            if ( $priority !== null )
                $existing->setAttribute( 'priority', $priority );
            $existing->setAttribute( 'enabled', $enabled );
            $existing->store();
            foreach ( expLayoutsRuleCondition::fetchByRule( (int)$existing->attribute( 'id' ) ) as $condition )
                $condition->remove();
            $this->writeConditions( (int)$existing->attribute( 'id' ), $rule );
            $this->affectedLayouts[] = (int)$layout->attribute( 'id' );
            $this->written( 'rule', $item['key'], 'updated', $existing );
            return;
        }

        $new = expLayoutsRule::create( (int)$layout->attribute( 'id' ), $priority );
        // A copied rule is written disabled: two enabled rules with the same
        // targets would leave it to their priorities which layout a page gets.
        $new->setAttribute( 'enabled', $action === 'copy' ? 0 : $enabled );
        $new->store();
        $ruleId = (int)$new->attribute( 'id' );
        foreach ( $targets as $target )
            expLayoutsRuleTarget::create( $ruleId, $target[0], $target[1] )->store();
        $this->writeConditions( $ruleId, $rule );
        $this->affectedRules[] = $ruleId;
        $this->affectedLayouts[] = (int)$layout->attribute( 'id' );
        $this->written( 'rule', $item['key'], $action === 'copy' ? 'created disabled' : 'created', $new );
    }

    protected function writeConditions( $ruleId, array $rule )
    {
        foreach ( $rule['conditions'] as $condition )
            expLayoutsRuleCondition::create( (int)$ruleId, (string)$condition['type'], (string)$condition['value'] )->store();
    }

    /**
     * Removes the resolver cache entries that name one of the layouts or rules,
     * and, when rules were written, the entries that remembered "no rule
     * matched" (a new rule may match them now). Entries of other layouts stay.
     *
     * @return int the number of entries removed
     */
    public static function clearResolverCacheFor( array $layoutIds, array $ruleIds, $dir = null )
    {
        if ( $dir === null )
            $dir = eZINI::instance( 'site.ini' )->variable( 'FileSettings', 'VarDir' ) . '/cache/explayouts/resolver';
        if ( !is_dir( $dir ) || ( !$layoutIds && !$ruleIds ) )
            return 0;

        $layouts = array_flip( array_map( 'intval', $layoutIds ) );
        $rules = array_flip( array_map( 'intval', $ruleIds ) );
        $removed = 0;
        foreach ( glob( $dir . '/*.php' ) ?: array() as $file )
        {
            $data = @include $file;
            if ( !is_array( $data ) )
                continue;
            $layoutId = isset( $data['layout_id'] ) ? (int)$data['layout_id'] : 0;
            $ruleId = isset( $data['rule_id'] ) ? (int)$data['rule_id'] : 0;
            if ( isset( $layouts[$layoutId] ) || ( $ruleId > 0 && isset( $rules[$ruleId] ) ) || ( $rules && $ruleId === 0 ) )
            {
                if ( @unlink( $file ) )
                    $removed++;
            }
        }
        return $removed;
    }
}
