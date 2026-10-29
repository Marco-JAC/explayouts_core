<?php
/**
 * Compares the documents of a layouts transfer archive with the same
 * documents exported from the target installation, and plans what an import
 * does with each item. Needs no database.
 *
 * Each layout (by identifier and status) and each rule (by its layout and its
 * targets) comes out in one of four states:
 *
 *   new          the target has no such item
 *   identical    the target has it with the same content
 *   changed      the target has it with other content
 *   conflicting  the target has it but it cannot simply be replaced: another
 *                layout type, the shared flag differs, an open draft on the
 *                target, or (for a rule) the same targets map to another layout
 *
 * Node and object ids are compared by remote id, so an installation with other
 * ids but the same content compares as identical.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 */
class expLayoutsCoreTransferDiff
{
    const STATE_NEW = 'new';
    const STATE_IDENTICAL = 'identical';
    const STATE_CHANGED = 'changed';
    const STATE_CONFLICTING = 'conflicting';

    /** Block parameters that hold a node id. */
    public static $nodeParameters = array( 'parent_node_id', 'parent_location_id', 'location_id', 'node_id' );

    /** Collection query parameters (JSON keys) that hold a node or an object id. */
    public static $queryReferenceKeys = array( 'parent_location_id' => 'node', 'topic_content_id' => 'object' );

    /** Rule targets whose numeric value is a node id. */
    public static $nodeTargets = array( 'node', 'subtree' );

    public static function layoutKey( array $layout )
    {
        return 'layout:' . $layout['identifier'] . ':' . $layout['status'];
    }

    /**
     * The canonical form of a reference: the remote id when there is one,
     * the id otherwise.
     */
    protected static function token( $kind, $id, array $refs )
    {
        $remoteId = expLayoutsCoreTransferReferences::archiveRemoteId( $kind, $id, $refs );
        return $remoteId !== null ? $kind . '@' . $remoteId : $kind . '#' . (int)$id;
    }

    /**
     * A layout with everything that does not describe its content left out
     * (times, the dependency flag) and every reference replaced by its token.
     */
    public static function canonicalLayout( array $layout, array $refs )
    {
        $zones = array();
        foreach ( isset( $layout['zones'] ) ? $layout['zones'] : array() as $zone )
        {
            $zones[(string)$zone['identifier']] = array(
                'position' => isset( $zone['position'] ) ? (int)$zone['position'] : 0,
                'link' => !empty( $zone['link'] ) ? array( 'layout' => (string)$zone['link']['layout'], 'zone' => (string)$zone['link']['zone'] ) : null,
                'blocks' => self::canonicalBlocks( isset( $zone['blocks'] ) ? $zone['blocks'] : array(), $refs ),
            );
        }
        ksort( $zones );

        return array(
            'name' => (string)$layout['name'],
            'layout_type' => (string)$layout['layout_type'],
            'shared' => (bool)$layout['shared'],
            'zones' => $zones,
        );
    }

    protected static function canonicalBlocks( array $blocks, array $refs )
    {
        $result = array();
        foreach ( $blocks as $block )
        {
            $parameters = isset( $block['parameters'] ) ? (array)$block['parameters'] : array();
            foreach ( $parameters as $name => $value )
                // The table stores no NULL through a persistent object (a copy or a draft writes ""), so the two are one value.
                $parameters[$name] = (string)$value;
            foreach ( isset( $block['parameter_refs'] ) ? (array)$block['parameter_refs'] : array() as $name => $reference )
            {
                $parsed = expLayoutsCoreTransferFormat::parseReference( $reference );
                if ( $parsed && array_key_exists( $name, $parameters ) )
                    $parameters[$name] = self::token( $parsed[0], $parsed[1], $refs );
            }
            ksort( $parameters );

            $collections = array();
            foreach ( isset( $block['collections'] ) ? $block['collections'] : array() as $collection )
            {
                $items = array();
                foreach ( isset( $collection['items'] ) ? $collection['items'] : array() as $item )
                {
                    $parsed = isset( $item['ref'] ) ? expLayoutsCoreTransferFormat::parseReference( $item['ref'] ) : false;
                    $items[] = array(
                        'position' => isset( $item['position'] ) ? (int)$item['position'] : 0,
                        'value_type' => (string)$item['value_type'],
                        'item_type' => isset( $item['item_type'] ) ? (string)$item['item_type'] : 'manual',
                        'value' => $parsed ? self::token( $parsed[0], $parsed[1], $refs ) : 'id#' . (int)$item['value_id'],
                    );
                }
                $query = null;
                if ( !empty( $collection['query'] ) )
                    $query = array( 'query_type' => (string)$collection['query']['query_type'],
                                    'parameters' => self::canonicalQueryParameters( $collection['query'], $refs ) );
                $collections[] = array(
                    'collection_type' => isset( $collection['collection_type'] ) ? (string)$collection['collection_type'] : 'manual',
                    'offset' => isset( $collection['offset'] ) ? (int)$collection['offset'] : 0,
                    'limit' => isset( $collection['limit'] ) ? (int)$collection['limit'] : 0,
                    'query' => $query,
                    'items' => $items,
                );
            }

            $result[] = array(
                'definition_identifier' => (string)$block['definition_identifier'],
                'name' => isset( $block['name'] ) ? (string)$block['name'] : '',
                'view_type' => isset( $block['view_type'] ) ? (string)$block['view_type'] : '',
                'item_view_type' => isset( $block['item_view_type'] ) ? (string)$block['item_view_type'] : '',
                'position' => isset( $block['position'] ) ? (int)$block['position'] : 0,
                'placeholder' => isset( $block['placeholder'] ) ? (string)$block['placeholder'] : '',
                'parameters' => $parameters,
                'collections' => $collections,
                'children' => self::canonicalBlocks( isset( $block['children'] ) ? $block['children'] : array(), $refs ),
            );
        }
        return $result;
    }

    protected static function canonicalQueryParameters( array $query, array $refs )
    {
        $raw = isset( $query['parameters'] ) ? (string)$query['parameters'] : '';
        $decoded = json_decode( $raw, true );
        if ( !is_array( $decoded ) )
            return $raw;
        foreach ( isset( $query['refs'] ) ? (array)$query['refs'] : array() as $key => $reference )
        {
            $parsed = expLayoutsCoreTransferFormat::parseReference( $reference );
            if ( $parsed && array_key_exists( $key, $decoded ) )
                $decoded[$key] = self::token( $parsed[0], $parsed[1], $refs );
        }
        ksort( $decoded );
        return $decoded;
    }

    /**
     * The targets of a rule as a sorted list of "type=value" with references
     * as tokens: what tells two rules apart.
     */
    public static function ruleSignature( array $rule, array $refs )
    {
        $targets = array();
        foreach ( isset( $rule['targets'] ) ? $rule['targets'] : array() as $target )
        {
            $parsed = isset( $target['ref'] ) ? expLayoutsCoreTransferFormat::parseReference( $target['ref'] ) : false;
            $targets[] = (string)$target['type'] . '=' . ( $parsed ? self::token( $parsed[0], $parsed[1], $refs ) : (string)$target['value'] );
        }
        sort( $targets );
        return implode( "\n", $targets );
    }

    public static function canonicalRule( array $rule, array $refs )
    {
        $conditions = array();
        foreach ( isset( $rule['conditions'] ) ? $rule['conditions'] : array() as $condition )
            $conditions[] = (string)$condition['type'] . '=' . (string)$condition['value'];
        sort( $conditions );
        return array(
            'layout' => (string)$rule['layout'],
            'priority' => isset( $rule['priority'] ) ? (int)$rule['priority'] : 0,
            'enabled' => !empty( $rule['enabled'] ),
            'targets' => self::ruleSignature( $rule, $refs ),
            'conditions' => $conditions,
        );
    }

    public static function ruleKey( array $rule, array $refs )
    {
        return 'rule:' . $rule['layout'] . ':' . substr( hash( 'sha256', self::ruleSignature( $rule, $refs ) ), 0, 12 );
    }

    /**
     * Where two canonical values differ, as dotted paths (at most $limit).
     */
    public static function differences( $a, $b, $path = '', $limit = 25, array &$out = array() )
    {
        if ( count( $out ) >= $limit )
            return $out;
        if ( is_array( $a ) && is_array( $b ) )
        {
            $keys = array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) );
            foreach ( $keys as $key )
            {
                $here = $path === '' ? (string)$key : $path . '.' . $key;
                if ( !array_key_exists( $key, $a ) )
                    $out[] = $here . ' (only on this installation)';
                elseif ( !array_key_exists( $key, $b ) )
                    $out[] = $here . ' (only in the file)';
                else
                    self::differences( $a[$key], $b[$key], $here, $limit, $out );
                if ( count( $out ) >= $limit )
                    break;
            }
            return $out;
        }
        if ( $a !== $b )
            $out[] = $path === '' ? '(value)' : $path;
        return $out;
    }

    /**
     * The plan of an import.
     *
     * @param array $archive the archive's documents (layouts.json, rules.json, references.json)
     * @param array $target the same documents exported from the target installation (all layouts, drafts included, all rules)
     * @param array|null $only identifiers of the layouts to import (their rules follow them); null for all
     * @return array layouts, rules (lists of plan items) and totals (state => count)
     */
    public static function plan( array $archive, array $target, $only = null )
    {
        $archiveRefs = isset( $archive['references.json'] ) ? $archive['references.json'] : array();
        $targetRefs = isset( $target['references.json'] ) ? $target['references.json'] : array();

        $targetLayouts = array();
        $targetDrafts = array();
        foreach ( isset( $target['layouts.json']['layouts'] ) ? $target['layouts.json']['layouts'] : array() as $layout )
        {
            $targetLayouts[self::layoutKey( $layout )] = $layout;
            if ( $layout['status'] === 'draft' )
                $targetDrafts[$layout['identifier']] = isset( $layout['id'] ) ? (int)$layout['id'] : null;
        }

        $plan = array( 'layouts' => array(), 'rules' => array(), 'totals' => array() );
        foreach ( array( self::STATE_NEW, self::STATE_IDENTICAL, self::STATE_CHANGED, self::STATE_CONFLICTING ) as $state )
            $plan['totals'][$state] = 0;

        $selected = self::selectedIdentifiers( $archive, $only );

        foreach ( isset( $archive['layouts.json']['layouts'] ) ? $archive['layouts.json']['layouts'] : array() as $layout )
        {
            if ( !isset( $selected[$layout['identifier']] ) )
                continue;
            $key = self::layoutKey( $layout );
            $item = array(
                'kind' => 'layout',
                'key' => $key,
                'identifier' => $layout['identifier'],
                'name' => (string)$layout['name'],
                'status' => $layout['status'],
                'shared' => (bool)$layout['shared'],
                'dependency' => !empty( $layout['dependency'] ) || $selected[$layout['identifier']] === 'dependency',
                'state' => self::STATE_NEW,
                'reasons' => array(),
                'differences' => array(),
                'target_id' => null,
            );

            if ( isset( $targetLayouts[$key] ) )
            {
                $existing = $targetLayouts[$key];
                $item['target_id'] = isset( $existing['id'] ) ? (int)$existing['id'] : null;
                $mine = self::canonicalLayout( $layout, $archiveRefs );
                $theirs = self::canonicalLayout( $existing, $targetRefs );
                if ( $mine === $theirs )
                    $item['state'] = self::STATE_IDENTICAL;
                else
                {
                    $item['differences'] = self::differences( $theirs, $mine );
                    $item['state'] = self::STATE_CHANGED;
                    if ( $mine['layout_type'] !== $theirs['layout_type'] )
                        $item['reasons'][] = 'The layout type differs.';
                    if ( $mine['shared'] !== $theirs['shared'] )
                        $item['reasons'][] = 'The shared flag differs.';
                    if ( $layout['status'] === 'published' && array_key_exists( $layout['identifier'], $targetDrafts ) )
                        $item['reasons'][] = 'A draft of this layout is open on this installation.';
                    if ( $item['reasons'] )
                        $item['state'] = self::STATE_CONFLICTING;
                }
            }
            elseif ( $layout['status'] === 'published' && array_key_exists( $layout['identifier'], $targetDrafts ) )
            {
                // Not published here, but someone's draft of it is open: the
                // draft is what an overwrite or a copy works on.
                $item['state'] = self::STATE_CONFLICTING;
                $item['target_id'] = $targetDrafts[$layout['identifier']];
                $item['reasons'][] = 'A draft of this layout is open on this installation.';
            }
            $plan['totals'][$item['state']]++;
            $plan['layouts'][] = $item;
        }

        $targetRules = array();
        $targetSignatures = array();
        foreach ( isset( $target['rules.json']['rules'] ) ? $target['rules.json']['rules'] : array() as $rule )
        {
            $targetRules[self::ruleKey( $rule, $targetRefs )] = $rule;
            $targetSignatures[self::ruleSignature( $rule, $targetRefs )][] = $rule;
        }

        foreach ( isset( $archive['rules.json']['rules'] ) ? $archive['rules.json']['rules'] : array() as $rule )
        {
            if ( !isset( $selected[$rule['layout']] ) || $selected[$rule['layout']] === 'dependency' )
                continue;
            $key = self::ruleKey( $rule, $archiveRefs );
            $signature = self::ruleSignature( $rule, $archiveRefs );
            $item = array(
                'kind' => 'rule',
                'key' => $key,
                'layout' => $rule['layout'],
                'targets' => $signature,
                'state' => self::STATE_NEW,
                'reasons' => array(),
                'differences' => array(),
                'target_id' => null,
            );
            if ( isset( $targetRules[$key] ) )
            {
                $existing = $targetRules[$key];
                $item['target_id'] = isset( $existing['id'] ) ? (int)$existing['id'] : null;
                $mine = self::canonicalRule( $rule, $archiveRefs );
                $theirs = self::canonicalRule( $existing, $targetRefs );
                if ( $mine === $theirs )
                    $item['state'] = self::STATE_IDENTICAL;
                else
                {
                    $item['state'] = self::STATE_CHANGED;
                    $item['differences'] = self::differences( $theirs, $mine );
                }
            }
            elseif ( $signature !== '' && isset( $targetSignatures[$signature] ) )
            {
                $other = $targetSignatures[$signature][0];
                $item['state'] = self::STATE_CONFLICTING;
                $item['target_id'] = isset( $other['id'] ) ? (int)$other['id'] : null;
                $item['reasons'][] = 'The same targets map to the layout "' . $other['layout'] . '" on this installation.';
            }
            $plan['totals'][$item['state']]++;
            $plan['rules'][] = $item;
        }

        return $plan;
    }

    /**
     * identifier => 'selected' | 'dependency' for the layouts an import takes
     * from the archive: those asked for, plus every layout they link to.
     */
    public static function selectedIdentifiers( array $archive, $only = null )
    {
        $layouts = isset( $archive['layouts.json']['layouts'] ) ? $archive['layouts.json']['layouts'] : array();
        $byIdentifier = array();
        foreach ( $layouts as $layout )
            $byIdentifier[$layout['identifier']][] = $layout;

        $selected = array();
        $queue = array();
        foreach ( $byIdentifier as $identifier => $rows )
        {
            if ( $only !== null && !in_array( $identifier, $only, true ) )
                continue;
            $dependency = true;
            foreach ( $rows as $row )
                if ( empty( $row['dependency'] ) )
                    $dependency = false;
            $selected[$identifier] = $dependency && $only === null ? 'dependency' : 'selected';
            $queue[] = $identifier;
        }
        while ( $queue )
        {
            $identifier = array_shift( $queue );
            foreach ( isset( $byIdentifier[$identifier] ) ? $byIdentifier[$identifier] : array() as $row )
                foreach ( isset( $row['zones'] ) ? $row['zones'] : array() as $zone )
                {
                    if ( empty( $zone['link']['layout'] ) )
                        continue;
                    $linked = $zone['link']['layout'];
                    if ( !isset( $selected[$linked] ) )
                    {
                        $selected[$linked] = 'dependency';
                        $queue[] = $linked;
                    }
                }
        }
        return $selected;
    }

    /**
     * What the import does with a plan item: create, none, skip, overwrite or copy.
     *
     * A layout carried only because another links to it keeps the target's
     * own version unless its item names a strategy: copying or overwriting a
     * shared layout nobody asked for would change pages the import is not about.
     *
     * @param array $item a plan item
     * @param string $strategy the global strategy (skip, overwrite, copy)
     * @param array $perItem plan key => strategy
     */
    public static function action( array $item, $strategy, array $perItem = array() )
    {
        $explicit = isset( $perItem[$item['key']] ) && in_array( $perItem[$item['key']], expLayoutsCoreTransferFormat::strategies(), true ) ? $perItem[$item['key']] : null;
        $chosen = $explicit !== null ? $explicit : $strategy;
        if ( $explicit === null && !empty( $item['dependency'] ) )
            $chosen = 'skip';
        // Copying layouts does not copy their rules: a copy of a rule maps the
        // same pages again. A rule is copied (written disabled) only when its
        // own item asks for it.
        if ( $explicit === null && $item['kind'] === 'rule' && $chosen === 'copy' )
            $chosen = 'skip';

        switch ( $item['state'] )
        {
            case self::STATE_NEW:
                return $explicit === 'skip' ? 'skip' : 'create';
            case self::STATE_IDENTICAL:
                return $chosen === 'copy' ? 'copy' : 'none';
            default:
                return in_array( $chosen, array( 'overwrite', 'copy' ), true ) ? $chosen : 'skip';
        }
    }
}
