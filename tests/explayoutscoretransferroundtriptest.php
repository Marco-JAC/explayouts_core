<?php
/**
 * Round trip of the layouts transfer through two throwaway SQLite databases:
 *
 *   1. the explayouts tables of the site are copied, read only, into a source database in memory;
 *   2. everything is exported from that copy into a transfer file;
 *   3. the file is imported into a fresh, empty database in memory, where every node and object has another id
 *      (mapped by remote id), and the two databases are compared row by row, by natural key (identifiers,
 *      positions), never by row id;
 *   4. a dry run of the same file against the imported database reports every item identical;
 *   5. a copy, an overwrite (draft, then published), an import that fails half way (rolled back) and an import
 *      with references that cannot be mapped are checked on the imported database.
 *
 * The site's own database is only read. Run with the kernel bootstrap, from the installation root:
 *   php bin/php/ezexec.php extension/explayouts_core/tests/explayoutscoretransferroundtriptest.php
 *
 * @license GNU General Public License v2.0 (or any later version)
 */

foreach ( glob( __DIR__ . '/../classes/explayoutscore*.php' ) as $classFile )
    require_once $classFile;

$GLOBALS['expLayoutsTransferFailures'] = 0;
$GLOBALS['expLayoutsTransferChecks'] = 0;
if ( !function_exists( 'transferCheck' ) )
{
    function transferCheck( $condition, $message )
    {
        $GLOBALS['expLayoutsTransferChecks']++;
        if ( !$condition )
        {
            echo "FAIL: $message\n";
            $GLOBALS['expLayoutsTransferFailures']++;
        }
    }
}

if ( !extension_loaded( 'sqlite3' ) || !class_exists( 'eZSQLite3DB' ) || !class_exists( 'expLayoutsZone' ) || !class_exists( 'ZipArchive' ) )
{
    echo "SKIP: needs the sqlite3 and zip PHP extensions and the explayouts extension\n";
    return 1;
}

$schemaFile = eZExtension::baseDirectory() . '/explayouts/sql/sqlite/schema.sql';
$tables = array( 'explayouts_layout', 'explayouts_zone', 'explayouts_block', 'explayouts_block_parameter', 'explayouts_collection',
                 'explayouts_collection_item', 'explayouts_collection_query', 'explayouts_rule', 'explayouts_rule_target', 'explayouts_rule_condition' );

$memory = function () use ( $schemaFile, $tables )
{
    $db = new eZSQLite3DB( array(
        'server' => false, 'port' => false, 'user' => false, 'password' => false, 'database' => ':memory:',
        'use_slave_server' => false, 'slave_server' => false, 'slave_port' => false, 'slave_user' => false,
        'slave_password' => false, 'slave_database' => false, 'socket' => false, 'charset' => 'utf-8',
        'is_internal_charset' => true, 'builtin_encoding' => false, 'connect_retries' => 1,
        'use_persistent_connection' => false,
    ) );
    foreach ( explode( ';', file_get_contents( $schemaFile ) ) as $statement )
    {
        $statement = trim( $statement );
        foreach ( $tables as $table )
            if ( preg_match( '/^CREATE (TABLE|INDEX|UNIQUE INDEX)\b.*\b' . $table . '\b/is', $statement ) )
            {
                $db->query( $statement );
                break;
            }
    }
    return $db;
};

$work = 'var/tmp/explayouts-transfer-roundtrip-' . getmypid();
eZDir::mkdir( $work, false, true );

$siteDB = eZDB::instance();
// The audit records of the test go into its own directory, never into the site's audit trail.
$auditDir = realpath( $work ) . '/audit';
if ( class_exists( 'expAuditConfig' ) )
    expAuditConfig::setTestLogDir( $auditDir );

// The rows of a database by natural key: what a round trip must keep.
$canonical = function ( $db, $mapValue )
{
    $rows = array();
    $layouts = array();
    foreach ( $db->arrayQuery( 'SELECT * FROM explayouts_layout WHERE status IN (1, 2)' ) as $row )
    {
        $layouts[(int)$row['id']] = $row['identifier'] . '|' . $row['status'];
        $rows['layout ' . $row['identifier'] . '|' . $row['status']] = array( $row['name'], $row['layout_type'], (int)$row['shared'] );
    }
    $zones = array();
    foreach ( $db->arrayQuery( 'SELECT * FROM explayouts_zone ORDER BY id' ) as $row )
    {
        if ( !isset( $layouts[(int)$row['layout_id']] ) )
            continue;
        $key = $layouts[(int)$row['layout_id']] . '|' . $row['identifier'];
        $zones[(int)$row['id']] = $key;
        $linked = (int)$row['linked_layout_id'] > 0 && isset( $layouts[(int)$row['linked_layout_id']] ) ? $layouts[(int)$row['linked_layout_id']] : '';
        $rows['zone ' . $key] = array( (int)$row['position'], $linked, $linked !== '' ? (string)$row['linked_zone_identifier'] : '', (int)$row['status'] );
    }
    $children = array();
    $blocks = array();
    foreach ( $db->arrayQuery( 'SELECT * FROM explayouts_block ORDER BY position, id' ) as $row )
    {
        $blocks[(int)$row['id']] = $row;
        $children[(int)$row['zone_id'] . ':' . (int)$row['parent_id']][] = (int)$row['id'];
    }
    $blockKeys = array();
    $walk = function ( $zoneId, $parentId, $prefix ) use ( &$walk, &$blockKeys, $children )
    {
        foreach ( isset( $children[$zoneId . ':' . $parentId] ) ? $children[$zoneId . ':' . $parentId] : array() as $index => $blockId )
        {
            $blockKeys[$blockId] = $prefix . '/' . $index;
            $walk( $zoneId, $blockId, $prefix . '/' . $index );
        }
    };
    foreach ( $zones as $zoneId => $zoneKey )
        $walk( $zoneId, 0, $zoneKey );
    foreach ( $blockKeys as $blockId => $key )
    {
        $row = $blocks[$blockId];
        $rows['block ' . $key] = array( $row['definition_identifier'], $row['view_type'], $row['item_view_type'], $row['name'],
                                         (int)$row['position'], $row['placeholder'], (int)$row['status'] );
    }
    foreach ( $db->arrayQuery( 'SELECT * FROM explayouts_block_parameter' ) as $row )
        if ( isset( $blockKeys[(int)$row['block_id']] ) )
        {
            $value = (string)$row['value'];
            if ( in_array( $row['name'], expLayoutsCoreTransferDiff::$nodeParameters, true ) && ctype_digit( (string)$value ) )
                $value = (string)$mapValue( 'node', (int)$value );
            $rows['parameter ' . $blockKeys[(int)$row['block_id']] . '|' . $row['name']] = array( $value );
        }
    $collections = array();
    $collectionIndex = array();
    foreach ( $db->arrayQuery( 'SELECT * FROM explayouts_collection ORDER BY id' ) as $row )
    {
        if ( !isset( $blockKeys[(int)$row['block_id']] ) )
            continue;
        $blockKey = $blockKeys[(int)$row['block_id']];
        $index = isset( $collectionIndex[$blockKey] ) ? ++$collectionIndex[$blockKey] : ( $collectionIndex[$blockKey] = 0 );
        $key = $blockKey . '#' . $index;
        $collections[(int)$row['id']] = $key;
        $rows['collection ' . $key] = array( $row['collection_type'], (int)$row['offset_value'], (int)$row['limit_value'], (int)$row['status'] );
    }
    $itemIndex = array();
    foreach ( $db->arrayQuery( 'SELECT * FROM explayouts_collection_item ORDER BY collection_id, position, id' ) as $row )
    {
        if ( !isset( $collections[(int)$row['collection_id']] ) )
            continue;
        $key = $collections[(int)$row['collection_id']];
        $index = isset( $itemIndex[$key] ) ? ++$itemIndex[$key] : ( $itemIndex[$key] = 0 );
        $value = (int)$row['value_id'];
        if ( $row['value_type'] === 'ez_location' )
            $value = $mapValue( 'node', $value );
        elseif ( $row['value_type'] === 'ez_content' )
            $value = $mapValue( 'object', $value );
        $rows['item ' . $key . '#' . $index] = array( (int)$row['position'], $row['value_type'], $row['item_type'], (int)$value );
    }
    foreach ( $db->arrayQuery( 'SELECT * FROM explayouts_collection_query' ) as $row )
        if ( isset( $collections[(int)$row['collection_id']] ) )
        {
            $parameters = json_decode( (string)$row['parameters'], true );
            if ( is_array( $parameters ) )
            {
                foreach ( expLayoutsCoreTransferDiff::$queryReferenceKeys as $name => $kind )
                    if ( isset( $parameters[$name] ) && is_numeric( $parameters[$name] ) )
                        $parameters[$name] = $mapValue( $kind, (int)$parameters[$name] );
                ksort( $parameters );
            }
            $rows['query ' . $collections[(int)$row['collection_id']]] = array( $row['query_type'], json_encode( $parameters ) );
        }
    $targets = array();
    foreach ( $db->arrayQuery( 'SELECT * FROM explayouts_rule_target' ) as $row )
    {
        $value = (string)$row['target_value'];
        if ( in_array( $row['target_type'], expLayoutsCoreTransferDiff::$nodeTargets, true ) && ctype_digit( $value ) )
            $value = (string)$mapValue( 'node', (int)$value );
        $targets[(int)$row['rule_id']][] = $row['target_type'] . '=' . $value;
    }
    $conditions = array();
    foreach ( $db->arrayQuery( 'SELECT * FROM explayouts_rule_condition' ) as $row )
        $conditions[(int)$row['rule_id']][] = $row['condition_type'] . '=' . $row['condition_value'];
    foreach ( $db->arrayQuery( 'SELECT * FROM explayouts_rule' ) as $row )
    {
        if ( !isset( $layouts[(int)$row['layout_id']] ) )
            continue;
        $ruleTargets = isset( $targets[(int)$row['id']] ) ? $targets[(int)$row['id']] : array();
        $ruleConditions = isset( $conditions[(int)$row['id']] ) ? $conditions[(int)$row['id']] : array();
        sort( $ruleTargets );
        sort( $ruleConditions );
        $rows['rule ' . $layouts[(int)$row['layout_id']] . '|' . implode( ',', $ruleTargets )] = array( (int)$row['priority'], (int)$row['enabled'], implode( ',', $ruleConditions ) );
    }
    ksort( $rows );
    return $rows;
};

$compare = function ( array $expected, array $actual, $label )
{
    $missing = array_diff_key( $expected, $actual );
    $extra = array_diff_key( $actual, $expected );
    $changed = array();
    foreach ( array_intersect_key( $expected, $actual ) as $key => $row )
        if ( $row !== $actual[$key] )
            $changed[] = $key;
    transferCheck( !$missing && !$extra && !$changed,
        "$label: " . count( $missing ) . ' rows missing, ' . count( $extra ) . ' extra, ' . count( $changed ) . ' changed'
        . ( $missing ? '; missing e.g. ' . implode( ', ', array_slice( array_keys( $missing ), 0, 3 ) ) : '' )
        . ( $extra ? '; extra e.g. ' . implode( ', ', array_slice( array_keys( $extra ), 0, 3 ) ) : '' )
        . ( $changed ? '; changed e.g. ' . implode( ', ', array_slice( $changed, 0, 3 ) ) . ' ' . json_encode( $expected[$changed[0]] ) . ' vs ' . json_encode( $actual[$changed[0]] ) : '' ) );
    return !$missing && !$extra && !$changed;
};

try
{
    // The remote ids of every node and object the site's layouts refer to, read from the site (read only).
    $siteExport = ( new expLayoutsCoreTransferExporter() )->export( array( 'layouts' => 'all', 'shared' => 'all', 'rules' => 'all', 'drafts' => true ) );
    $siteRefs = json_decode( json_encode( $siteExport['references.json'] ), true );

    // 1. The source: a copy of the site's explayouts rows.
    $source = $memory();
    foreach ( $tables as $table )
    {
        foreach ( $siteDB->arrayQuery( "SELECT * FROM $table" ) as $row )
        {
            $values = array();
            foreach ( $row as $value )
                $values[] = $value === null ? 'NULL' : "'" . $source->escapeString( (string)$value ) . "'";
            $source->query( "INSERT INTO $table (" . implode( ', ', array_keys( $row ) ) . ') VALUES (' . implode( ', ', $values ) . ')' );
        }
    }
    eZDB::setInstance( $source );

    // On the source the ids are the site's; on the target every node and object id is 100000 higher.
    $offset = 100000;
    $sourceLookups = array(
        'node_remote' => function ( $id ) use ( $siteRefs ) { return isset( $siteRefs['node'][(string)$id]['remote_id'] ) ? $siteRefs['node'][(string)$id]['remote_id'] : null; },
        'node_path' => function ( $id ) { return null; },
        'object_remote' => function ( $id ) use ( $siteRefs ) { return isset( $siteRefs['object'][(string)$id]['remote_id'] ) ? $siteRefs['object'][(string)$id]['remote_id'] : null; },
        'node_by_remote' => function ( $remoteId ) { return null; },
        'object_by_remote' => function ( $remoteId ) { return null; },
    );
    $byRemote = array( 'node' => array(), 'object' => array() );
    foreach ( array( 'node', 'object' ) as $kind )
        foreach ( isset( $siteRefs[$kind] ) ? $siteRefs[$kind] : array() as $id => $entry )
            if ( $entry['remote_id'] !== null )
                $byRemote[$kind][$entry['remote_id']] = (int)$id + $offset;
    $targetLookups = array(
        'node_remote' => function ( $id ) use ( $siteRefs, $offset ) { $id -= $offset; return isset( $siteRefs['node'][(string)$id]['remote_id'] ) ? $siteRefs['node'][(string)$id]['remote_id'] : null; },
        'node_path' => function ( $id ) { return null; },
        'object_remote' => function ( $id ) use ( $siteRefs, $offset ) { $id -= $offset; return isset( $siteRefs['object'][(string)$id]['remote_id'] ) ? $siteRefs['object'][(string)$id]['remote_id'] : null; },
        'node_by_remote' => function ( $remoteId ) use ( $byRemote ) { return isset( $byRemote['node'][$remoteId] ) ? $byRemote['node'][$remoteId] : null; },
        'object_by_remote' => function ( $remoteId ) use ( $byRemote ) { return isset( $byRemote['object'][$remoteId] ) ? $byRemote['object'][$remoteId] : null; },
    );
    $mapToTarget = function ( $kind, $id ) use ( $siteRefs, $offset )
    {
        return isset( $siteRefs[$kind][(string)$id]['remote_id'] ) && $siteRefs[$kind][(string)$id]['remote_id'] !== null ? $id + $offset : $id;
    };

    // 2. Export everything from the copy, drafts included.
    $sourceService = new expLayoutsCoreTransferService( $work, new expLayoutsCoreTransferReferences( $sourceLookups ) );
    $file = $work . '/all.zip';
    $export = $sourceService->export( array( 'layouts' => 'all', 'shared' => 'all', 'rules' => 'all', 'drafts' => true ), $file );
    transferCheck( $export['ok'], 'the export of the copy succeeds: ' . implode( ' ', $export['errors'] ) );
    $expected = $canonical( $source, $mapToTarget );
    // Rules of layouts that are not published are not exported (a rule maps to a published layout).
    foreach ( array_keys( $expected ) as $key )
        if ( strpos( $key, 'rule ' ) === 0 && !preg_match( '/^rule [^|]+\|2\|/', $key ) )
            unset( $expected[$key] );

    // 3. Import into a fresh database and compare row by row.
    $target = $memory();
    eZDB::setInstance( $target );
    $targetService = new expLayoutsCoreTransferService( $work, new expLayoutsCoreTransferReferences( $targetLookups ) );
    $preview = $targetService->import( $file, array( 'strategy' => 'skip', 'dry_run' => true ) );
    transferCheck( $preview['ok'] && $preview['plan']['totals']['new'] === count( $preview['plan']['layouts'] ) + count( $preview['plan']['rules'] ),
        'into an empty database every item is new: ' . json_encode( $preview['plan']['totals'] ) . ' ' . implode( ' ', $preview['errors'] ) );
    $result = $targetService->import( $file, array( 'strategy' => 'skip', 'dry_run' => false, 'unmapped' => 'keep' ) );
    transferCheck( $result['ok'], 'the import succeeds: ' . implode( ' ', $result['errors'] ) );
    $compare( $expected, $canonical( $target, function ( $kind, $id ) { return $id; } ), 'source copy vs imported database' );

    // Every node and object reference was mapped to the target's ids.
    $ids = $target->arrayQuery( "SELECT value_id FROM explayouts_collection_item WHERE value_type = 'ez_location'" );
    $mapped = 0;
    foreach ( $ids as $row )
        if ( (int)$row['value_id'] > $offset )
            $mapped++;
    transferCheck( $mapped > 0, 'collection items point at the target installation\'s node ids' );

    // 4. The same file against the imported database: all identical.
    $again = $targetService->import( $file, array( 'strategy' => 'overwrite', 'dry_run' => true ) );
    $notIdentical = array();
    foreach ( array( 'layouts', 'rules' ) as $list )
        foreach ( $again['plan'][$list] as $item )
            if ( $item['state'] !== 'identical' )
                $notIdentical[] = $item['key'] . ' ' . $item['state'] . ' ' . implode( ', ', array_slice( $item['differences'], 0, 3 ) );
    transferCheck( $again['ok'] && !$notIdentical, 'a dry run against the imported database is all identical: ' . implode( '; ', array_slice( $notIdentical, 0, 5 ) ) );

    // 5a. Copy one layout that links zones to shared layouts.
    $documents = $again['ok'] ? expLayoutsCoreTransferFormat::readArchive( $file )['documents'] : array();
    $linking = null;
    foreach ( $documents['layouts.json']['layouts'] as $layout )
    {
        if ( $layout['shared'] || $layout['status'] !== 'published' )
            continue;
        foreach ( $layout['zones'] as $zone )
            if ( $zone['link'] )
                $linking = $layout;
        if ( $linking )
            break;
    }
    transferCheck( $linking !== null, 'the site has a layout that links a zone to a shared layout' );
    if ( $linking )
    {
        $sharedBefore = count( expLayoutsLayout::fetchShared( 2 ) );
        $layoutsBefore = (int)$target->arrayQuery( 'SELECT count(*) AS c FROM explayouts_layout' )[0]['c'];
        $copy = $targetService->import( $file, array( 'strategy' => 'copy', 'only' => array( $linking['identifier'] ), 'dry_run' => false, 'publish' => true ) );
        transferCheck( $copy['ok'], 'the copy import succeeds: ' . implode( ' ', $copy['errors'] ) );
        $written = isset( $copy['import']['written'] ) ? $copy['import']['written'] : array();
        transferCheck( count( $written ) === 1 && $written[0]['what'] === 'copied and published', 'exactly the chosen layout is copied, not its shared layouts: ' . json_encode( $written ) );
        transferCheck( count( expLayoutsLayout::fetchShared( 2 ) ) === $sharedBefore, 'no shared layout was added' );
        transferCheck( (int)$target->arrayQuery( 'SELECT count(*) AS c FROM explayouts_layout' )[0]['c'] === $layoutsBefore + 1, 'one layout row was added' );
        if ( $written )
        {
            $copied = expLayoutsLayout::fetch( $written[0]['id'] );
            transferCheck( $copied && strpos( $copied->attribute( 'identifier' ), $linking['identifier'] . '_' ) === 0 && (int)$copied->attribute( 'status' ) === 2,
                'the copy has its own identifier and is published' );
            $links = 0;
            foreach ( expLayoutsZone::fetchByLayout( (int)$written[0]['id'], 2 ) as $zone )
            {
                if ( !$zone->isLinked() )
                    continue;
                $links++;
                $linkedLayout = expLayoutsLayout::fetch( (int)$zone->attribute( 'linked_layout_id' ) );
                transferCheck( $linkedLayout && $linkedLayout->isShared() && (int)$linkedLayout->attribute( 'status' ) === 2 && expLayoutsZone::resolveLinkedSource( $zone ),
                    'zone ' . $zone->attribute( 'identifier' ) . ' of the copy links to the published shared layout' );
            }
            transferCheck( $links > 0, 'the copy keeps its zone links' );
            $service = new expLayoutsCoreLayoutService();
            $service->delete( (int)$written[0]['id'] );
        }

        // 5b. Overwrite: a changed block name makes a draft; published only when asked.
        $changedFile = $work . '/changed.zip';
        $changedDocs = $documents;
        foreach ( $changedDocs['layouts.json']['layouts'] as &$layout )
            if ( $layout['identifier'] === $linking['identifier'] && $layout['status'] === 'published' )
                foreach ( $layout['zones'] as &$zone )
                    if ( $zone['blocks'] )
                    {
                        $zone['blocks'][0]['name'] = 'Changed by the round trip test';
                        break;
                    }
        unset( $layout, $zone );
        expLayoutsCoreTransferFormat::writeArchive( $changedFile, expLayoutsCoreTransferFormat::files( $changedDocs, array() ) );
        $plan = $targetService->import( $changedFile, array( 'strategy' => 'overwrite', 'only' => array( $linking['identifier'] ) ) );
        $states = array();
        foreach ( $plan['plan']['layouts'] as $item )
            $states[$item['identifier'] . '|' . $item['status']] = $item['state'];
        transferCheck( isset( $states[$linking['identifier'] . '|published'] ) && $states[$linking['identifier'] . '|published'] === 'changed', 'the changed layout is reported changed: ' . json_encode( $states ) );

        $publishedBefore = expLayoutsLayout::fetchByIdentifier( $linking['identifier'], 2 );
        $draftRun = $targetService->import( $changedFile, array( 'strategy' => 'overwrite', 'only' => array( $linking['identifier'] ), 'dry_run' => false ) );
        transferCheck( $draftRun['ok'], 'the overwrite succeeds: ' . implode( ' ', $draftRun['errors'] ) );
        $draft = expLayoutsLayout::fetchByIdentifier( $linking['identifier'], 1 );
        transferCheck( (bool)$draft, 'the overwrite made a draft' );
        $names = array();
        foreach ( $target->arrayQuery( "SELECT name FROM explayouts_block WHERE layout_id = " . (int)$publishedBefore->attribute( 'id' ) ) as $row )
            $names[] = $row['name'];
        transferCheck( !in_array( 'Changed by the round trip test', $names, true ), 'without publish the published layout is unchanged' );

        $publishRun = $targetService->import( $changedFile, array( 'strategy' => 'overwrite', 'only' => array( $linking['identifier'] ), 'dry_run' => false, 'publish' => true ) );
        transferCheck( $publishRun['ok'], 'the overwrite with publish succeeds: ' . implode( ' ', $publishRun['errors'] ) );
        $publishedAfter = expLayoutsLayout::fetchByIdentifier( $linking['identifier'], 2 );
        transferCheck( $publishedAfter && (int)$publishedAfter->attribute( 'id' ) === (int)$publishedBefore->attribute( 'id' ), 'the published layout keeps its id, so links and rules to it stay' );
        $names = array();
        foreach ( $target->arrayQuery( "SELECT name FROM explayouts_block WHERE layout_id = " . (int)$publishedAfter->attribute( 'id' ) ) as $row )
            $names[] = $row['name'];
        transferCheck( in_array( 'Changed by the round trip test', $names, true ), 'with publish the published layout has the change' );
        transferCheck( !expLayoutsLayout::fetchByIdentifier( $linking['identifier'], 1 ), 'publishing used up the draft' );
    }

    // 5c. A failure half way rolls everything back.
    $counts = function () use ( $target, $tables )
    {
        $c = array();
        foreach ( $tables as $table )
            $c[$table] = (int)$target->arrayQuery( "SELECT count(*) AS c FROM $table" )[0]['c'];
        return $c;
    };
    $before = $counts();
    $failingImporter = new class( new expLayoutsCoreTransferReferences( $targetLookups ) ) extends expLayoutsCoreTransferImporter
    {
        private $calls = 0;

        // The first layout is written, the second stops the import.
        protected function writeLayout( array $item, array $document, $action, $publish )
        {
            if ( ++$this->calls > 1 )
                throw new RuntimeException( 'Stopped on purpose by the round trip test.' );
            parent::writeLayout( $item, $document, $action, $publish );
        }
    };
    $read = expLayoutsCoreTransferFormat::readArchive( $file );
    $everything = expLayoutsCoreTransferDiff::plan( $read['documents'], $targetService->targetDocuments() );
    $report = $failingImporter->import( $read['documents'], $everything, array( 'strategy' => 'copy' ) );
    transferCheck( !$report['ok'] && $report['errors'] === array( 'Stopped on purpose by the round trip test.' ), 'the failing import reports its error' );
    transferCheck( $counts() === $before, 'after the failure every table has the rows it had before' );
    transferCheck( $report['written'] === array() && $report['affected_layouts'] === array(), 'a rolled back import reports nothing written' );

    // 5d. References that cannot be mapped: reported; "fail" writes nothing; "drop" leaves the items out.
    $noContent = new expLayoutsCoreTransferService( $work, new expLayoutsCoreTransferReferences( array(
        'node_by_remote' => function () { return null; }, 'object_by_remote' => function () { return null; },
        'node_remote' => $targetLookups['node_remote'], 'object_remote' => $targetLookups['object_remote'], 'node_path' => function () { return null; } ) ) );
    $unmapped = $noContent->import( $file, array() );
    transferCheck( count( $unmapped['unmapped'] ) === count( $siteRefs['node'] ) + count( $siteRefs['object'] ), 'every reference is reported unmapped when the content is missing' );
    $before = $counts();
    $failRun = $noContent->import( $file, array( 'strategy' => 'copy', 'dry_run' => false, 'unmapped' => 'fail', 'only' => $linking ? array( $linking['identifier'] ) : null ) );
    transferCheck( !$failRun['ok'] && $counts() === $before, 'with unmapped=fail nothing is written' );

    // 6. The imports were audited: one event per import, one per written item.
    if ( class_exists( 'expAudit' ) )
    {
        expAudit::flush( true );
        $records = '';
        foreach ( glob( $auditDir . '/*.jsonl' ) ?: array() as $log )
            $records .= file_get_contents( $log );
        transferCheck( strpos( $records, '"data.explayouts_core.import"' ) !== false, 'the import is in the audit trail' );
        transferCheck( strpos( $records, '"data.explayouts_core.import.item"' ) !== false, 'the written items are in the audit trail' );
        transferCheck( strpos( $records, '"data.explayouts_core.export"' ) !== false, 'the export is in the audit trail' );
    }
}
catch ( Exception $e )
{
    transferCheck( false, 'exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
}
finally
{
    eZDB::setInstance( $siteDB );
    if ( class_exists( 'expAuditConfig' ) )
        expAuditConfig::setTestLogDir( null );
    eZDir::recursiveDelete( $work );
}

$failures = $GLOBALS['expLayoutsTransferFailures'];
echo ( $failures ? 'FAIL' : 'PASS' ) . ': ' . ( $GLOBALS['expLayoutsTransferChecks'] - $failures ) . ' of ' . $GLOBALS['expLayoutsTransferChecks'] . " checks\n";
return $failures ? 1 : 0;
