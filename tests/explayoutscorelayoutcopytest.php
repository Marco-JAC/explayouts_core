<?php
/**
 * Tests for expLayoutsCoreLayoutService::copy(), createDraft() and publish():
 * every copy of a layout holds each zone exactly once per status, with the
 * source zone's link and blocks, so a block added to the copy is the block
 * the editor and the site show, and it survives the next draft.
 *
 * Runs against a throwaway in-memory SQLite database built from the
 * explayouts SQLite schema; the site's own database is never touched.
 *
 * Run with the kernel bootstrap, from the installation root:
 *   php bin/php/ezexec.php extension/explayouts_core/tests/explayoutscorelayoutcopytest.php
 */

require_once __DIR__ . '/../classes/explayoutscorelayoutservice.php';

// Counted in $GLOBALS: ezexec runs this file inside a function, so its
// top-level variables are not globals.
$GLOBALS['expLayoutsCopyTestFailures'] = 0;
$GLOBALS['expLayoutsCopyTestChecks'] = 0;
if ( !function_exists( 'check' ) )
{
    function check( $condition, $message )
    {
        $GLOBALS['expLayoutsCopyTestChecks']++;
        if ( !$condition )
        {
            echo "FAIL: $message\n";
            $GLOBALS['expLayoutsCopyTestFailures']++;
        }
    }
}

if ( !extension_loaded( 'sqlite3' ) || !class_exists( 'eZSQLite3DB' ) || !class_exists( 'expLayoutsZone' ) )
{
    echo "SKIP: needs the sqlite3 PHP extension and the explayouts extension\n";
    return 1;
}

$schemaFile = eZExtension::baseDirectory() . '/explayouts/sql/sqlite/schema.sql';
if ( !is_file( $schemaFile ) )
{
    echo "SKIP: $schemaFile not found\n";
    return 1;
}

$siteDB = eZDB::instance();
$memoryDB = new eZSQLite3DB( array(
    'server' => false, 'port' => false, 'user' => false, 'password' => false, 'database' => ':memory:',
    'use_slave_server' => false, 'slave_server' => false, 'slave_port' => false, 'slave_user' => false,
    'slave_password' => false, 'slave_database' => false, 'socket' => false, 'charset' => 'utf-8',
    'is_internal_charset' => true, 'builtin_encoding' => false, 'connect_retries' => 1,
    'use_persistent_connection' => false,
) );
eZDB::setInstance( $memoryDB );

try
{
    foreach ( explode( ';', file_get_contents( $schemaFile ) ) as $statement )
    {
        $statement = trim( $statement );
        if ( $statement !== '' && strpos( $statement, 'explayouts_' ) !== false )
            $memoryDB->query( $statement );
    }

    // The test's own layout type, so it does not depend on the site's settings.
    $ini = eZINI::instance( 'explayouts.ini' );
    $ini->setVariable( 'LayoutType_w_copy_test', 'Name', 'Copy test' );
    $ini->setVariable( 'LayoutType_w_copy_test', 'Zones', array( 'top', 'main', 'side' ) );
    check( expLayoutsLayoutType::getZones( 'w_copy_test' ) === array( 'top', 'main', 'side' ), 'the test layout type has three zones' );

    $service = new expLayoutsCoreLayoutService();

    $zoneRows = function( $layoutId, $status )
    {
        $rows = array();
        foreach ( expLayoutsZone::fetchByLayout( (int)$layoutId, (int)$status ) as $zone )
            $rows[(string)$zone->attribute( 'identifier' )][] = $zone;
        return $rows;
    };
    $assertOneZoneEach = function( $label, $layoutId, $status ) use ( $zoneRows )
    {
        $rows = $zoneRows( $layoutId, $status );
        $identifiers = array_keys( $rows );
        sort( $identifiers );
        check( $identifiers === array( 'main', 'side', 'top' ), "$label: zones top, main, side, got " . implode( ',', array_keys( $rows ) ) );
        foreach ( $rows as $identifier => $list )
            check( count( $list ) === 1, "$label: one '$identifier' zone row, got " . count( $list ) );
        return $rows;
    };
    $blockNames = function( $zone )
    {
        $names = array();
        foreach ( expLayoutsBlock::fetchByZone( (int)$zone->attribute( 'id' ), (int)$zone->attribute( 'status' ) ) as $block )
            $names[] = (string)$block->attribute( 'name' );
        sort( $names );
        return $names;
    };
    // The zone the editor picks for a new block, and the one it draws: the
    // API's own lookups when they are installed, else the same rules.
    $apiClass = class_exists( 'expLayoutsUIApplicationApi' ) ? 'expLayoutsUIApplicationApi' : false;
    $zoneForNewBlock = function( $layoutId, $identifier, $status ) use ( $apiClass )
    {
        if ( $apiClass && method_exists( $apiClass, 'findZoneByIdentifier' ) )
        {
            $method = new ReflectionMethod( $apiClass, 'findZoneByIdentifier' );
            $method->setAccessible( true );
            return $method->invoke( null, (int)$layoutId, $identifier, (int)$status );
        }
        foreach ( expLayoutsZone::fetchByLayout( (int)$layoutId, null ) as $zone )
            if ( (string)$zone->attribute( 'identifier' ) === $identifier && (int)$zone->attribute( 'status' ) === (int)$status )
                return $zone;
        return false;
    };
    $zoneTheEditorShows = function( $layoutId, $identifier )
    {
        $shown = false;
        foreach ( expLayoutsZone::fetchByLayout( (int)$layoutId, null ) as $zone )
            if ( (string)$zone->attribute( 'identifier' ) === $identifier )
                $shown = $zone;
        return $shown;
    };
    $addBlock = function( $zone, $name )
    {
        $block = expLayoutsBlock::create( (int)$zone->attribute( 'id' ), (int)$zone->attribute( 'layout_id' ), 'title', $name );
        $block->setAttribute( 'status', (int)$zone->attribute( 'status' ) );
        $block->store();
        return $block;
    };

    // A shared layout to link to, published.
    $shared = $service->create( 'w_copy_shared', 'Shared', 'w_copy_test', true );
    $shared = $service->publish( (int)$shared->attribute( 'id' ) );
    check( $shared && (int)$shared->attribute( 'status' ) === 2, 'the shared layout is published' );
    $sharedId = (int)$shared->attribute( 'id' );

    // The source: a non-shared layout, 'top' linked to the shared layout,
    // 'main' with a block that has a parameter and a collection with an item.
    $source = $service->create( 'w_copy_source', 'Source', 'w_copy_test', false );
    $sourceRows = $assertOneZoneEach( 'new layout', (int)$source->attribute( 'id' ), 1 );
    $sourceRows['top'][0]->setAttribute( 'linked_layout_id', $sharedId );
    $sourceRows['top'][0]->setAttribute( 'linked_zone_identifier', 'top' );
    $sourceRows['top'][0]->store();
    // Source positions that differ from the layout type's order, as the
    // editor's repair writes them (1-based).
    foreach ( array( 'top' => 1, 'main' => 2, 'side' => 3 ) as $identifier => $position )
    {
        $sourceRows[$identifier][0]->setAttribute( 'position', $position );
        $sourceRows[$identifier][0]->store();
    }
    $first = $addBlock( $sourceRows['main'][0], 'first' );
    expLayoutsBlockParameter::set( (int)$first->attribute( 'id' ), 'title', 'Hello' );
    $collection = expLayoutsCollection::create( (int)$first->attribute( 'id' ) );
    $collection->store();
    expLayoutsCollectionItem::create( (int)$collection->attribute( 'id' ), 42 )->store();

    $published = $service->publish( (int)$source->attribute( 'id' ) );
    check( $published && (int)$published->attribute( 'status' ) === 2, 'the source is published' );
    $publishedId = (int)$published->attribute( 'id' );
    $assertOneZoneEach( 'published source', $publishedId, 2 );

    // 1. copy(): one zone per identifier, with the source's link and blocks.
    $copy = $service->copy( $publishedId );
    check( $copy instanceof expLayoutsLayout, 'copy() returns the copy' );
    $copyId = (int)$copy->attribute( 'id' );
    check( (int)$copy->attribute( 'status' ) === 1, 'the copy is a draft' );
    check( (int)$copy->attribute( 'shared' ) === 0, 'the copy of a non-shared layout is not shared' );
    $copyRows = $assertOneZoneEach( 'copy', $copyId, 1 );
    check( $copyRows['top'][0]->isLinked(), 'the copy keeps the link of the source zone' );
    check( (int)$copyRows['top'][0]->attribute( 'linked_layout_id' ) === $sharedId
        && (string)$copyRows['top'][0]->attribute( 'linked_zone_identifier' ) === 'top', 'the link points at the same shared zone' );
    check( !$copyRows['main'][0]->isLinked() && !$copyRows['side'][0]->isLinked(), 'unlinked zones stay unlinked' );
    foreach ( array( 'top' => 1, 'main' => 2, 'side' => 3 ) as $identifier => $position )
        check( (int)$copyRows[$identifier][0]->attribute( 'position' ) === $position, "the copy keeps the position of '$identifier'" );
    check( $blockNames( $copyRows['main'][0] ) === array( 'first' ), 'the copy holds the source block in main' );
    $copiedBlocks = expLayoutsBlock::fetchByZone( (int)$copyRows['main'][0]->attribute( 'id' ), 1 );
    $copiedFirst = $copiedBlocks ? $copiedBlocks[0] : false;
    check( $copiedFirst && (int)$copiedFirst->attribute( 'id' ) !== (int)$first->attribute( 'id' ), 'the copied block is a new row' );
    check( $copiedFirst && (int)$copiedFirst->attribute( 'layout_id' ) === $copyId, 'the copied block belongs to the copy' );
    $param = $copiedFirst ? expLayoutsBlockParameter::fetchByBlock( (int)$copiedFirst->attribute( 'id' ) ) : array();
    check( count( $param ) === 1 && (string)$param[0]->attribute( 'value' ) === 'Hello', 'the copied block keeps its parameter' );
    $copiedCollection = $copiedFirst ? expLayoutsCollection::fetchByBlock( (int)$copiedFirst->attribute( 'id' ) ) : false;
    check( $copiedCollection && (int)$copiedCollection->attribute( 'id' ) !== (int)$collection->attribute( 'id' ), 'the copied block has its own collection' );
    check( $copiedCollection && count( expLayoutsCollectionItem::fetchByCollection( (int)$copiedCollection->attribute( 'id' ) ) ) === 1, 'the collection item is copied' );

    // 2. The editor adds a block to the copy: it lands in the zone the editor draws.
    $target = $zoneForNewBlock( $copyId, 'main', 1 );
    check( $target instanceof expLayoutsZone, 'the editor finds the main zone of the copy' );
    $second = $addBlock( $target, 'second' );
    $shown = $zoneTheEditorShows( $copyId, 'main' );
    check( $shown && (int)$shown->attribute( 'id' ) === (int)$second->attribute( 'zone_id' ), 'the new block is in the zone the editor shows' );
    check( $shown && $blockNames( $shown ) === array( 'first', 'second' ), 'the shown zone holds both blocks' );

    // 3. Publish the copy, draft it again: both blocks are there, once.
    $copyPublished = $service->publish( $copyId );
    check( $copyPublished && (int)$copyPublished->attribute( 'status' ) === 2, 'the copy is published' );
    $copyPublishedId = (int)$copyPublished->attribute( 'id' );
    $rows = $assertOneZoneEach( 'published copy', $copyPublishedId, 2 );
    check( $blockNames( $rows['main'][0] ) === array( 'first', 'second' ), 'the published copy holds both blocks in main' );

    $draft = $service->createDraft( $copyPublishedId );
    check( $draft && (int)$draft->attribute( 'status' ) === 1, 'createDraft() makes a draft' );
    $draftId = (int)$draft->attribute( 'id' );
    $rows = $assertOneZoneEach( 'draft of the copy', $draftId, 1 );
    check( $blockNames( $rows['main'][0] ) === array( 'first', 'second' ), 'the draft holds both blocks in main' );
    check( $rows['top'][0]->isLinked(), 'the draft keeps the link' );
    $shown = $zoneTheEditorShows( $draftId, 'main' );
    check( $shown && $blockNames( $shown ) === array( 'first', 'second' ), 'the editor shows both blocks on the draft' );

    // 4. Publish over an existing published layout (the copy-into path).
    $third = $addBlock( $zoneForNewBlock( $draftId, 'side', 1 ), 'third' );
    $again = $service->publish( $draftId );
    check( $again && (int)$again->attribute( 'id' ) === $copyPublishedId, 'publishing a draft keeps the published id' );
    $rows = $assertOneZoneEach( 'republished copy', $copyPublishedId, 2 );
    check( $blockNames( $rows['main'][0] ) === array( 'first', 'second' ), 'main keeps its blocks after a republish' );
    check( $blockNames( $rows['side'][0] ) === array( 'third' ), 'side holds the new block after a republish' );
    check( $rows['top'][0]->isLinked(), 'the republished copy keeps the link' );
    check( count( expLayoutsZone::fetchByLayout( $draftId, null ) ) === 0, 'the draft is removed with its zones' );

    // 5. A copy of a draft and a copy of a shared layout: same rules.
    $draftAgain = $service->createDraft( $copyPublishedId );
    $copyOfDraft = $service->copy( (int)$draftAgain->attribute( 'id' ) );
    $rows = $assertOneZoneEach( 'copy of a draft', (int)$copyOfDraft->attribute( 'id' ), 1 );
    check( $blockNames( $rows['main'][0] ) === array( 'first', 'second' ), 'the copy of a draft holds its blocks' );
    $copyOfShared = $service->copy( $sharedId );
    check( (int)$copyOfShared->attribute( 'shared' ) === 1, 'the copy of a shared layout is shared' );
    $assertOneZoneEach( 'copy of a shared layout', (int)$copyOfShared->attribute( 'id' ), 1 );

    // 6. A source without zones (a type changed away) still gives the copy its type's zones.
    $bare = expLayoutsLayout::create( 'w_copy_bare', 'Bare', 'w_copy_test' );
    $bare->store();
    $copyOfBare = $service->copy( (int)$bare->attribute( 'id' ) );
    $assertOneZoneEach( 'copy of a layout without zones', (int)$copyOfBare->attribute( 'id' ), 1 );

    // 7. A source whose zones are not all of its type: the extra zone is copied once, the missing one added once.
    $odd = $service->create( 'w_copy_odd', 'Odd', 'w_copy_test', false );
    $oddRows = $zoneRows( (int)$odd->attribute( 'id' ), 1 );
    $oddRows['side'][0]->setAttribute( 'identifier', 'legacy' );
    $oddRows['side'][0]->store();
    $addBlock( $oddRows['side'][0], 'kept' );
    $copyOfOdd = $service->copy( (int)$odd->attribute( 'id' ) );
    $rows = $zoneRows( (int)$copyOfOdd->attribute( 'id' ), 1 );
    ksort( $rows );
    check( array_keys( $rows ) === array( 'legacy', 'main', 'side', 'top' ), 'the copy has the source zones plus the missing type zone: ' . implode( ',', array_keys( $rows ) ) );
    foreach ( $rows as $identifier => $list )
        check( count( $list ) === 1, "copy of an odd layout: one '$identifier' zone row, got " . count( $list ) );
    check( isset( $rows['legacy'] ) && $blockNames( $rows['legacy'][0] ) === array( 'kept' ), 'the extra zone keeps its block' );

    // 8. A source an earlier copy() damaged (two rows per zone): the copy has
    //    one row per zone, the link, and the blocks of both rows.
    $damaged = $service->create( 'w_copy_damaged', 'Damaged', 'w_copy_test', false );
    $damagedId = (int)$damaged->attribute( 'id' );
    $damagedRows = $zoneRows( $damagedId, 1 );
    $addBlock( $damagedRows['main'][0], 'in-first-row' );
    foreach ( array( 'top', 'main' ) as $identifier )
    {
        $extra = expLayoutsZone::create( $damagedId, $identifier, 1 );
        $extra->setAttribute( 'position', 5 );
        if ( $identifier === 'top' )
        {
            $extra->setAttribute( 'linked_layout_id', $sharedId );
            $extra->setAttribute( 'linked_zone_identifier', 'top' );
        }
        $extra->store();
        if ( $identifier === 'main' )
        {
            $addBlock( $extra, 'in-second-row-a' );
            $addBlock( $extra, 'in-second-row-b' );
        }
    }
    $copyOfDamaged = $service->copy( $damagedId );
    $rows = $assertOneZoneEach( 'copy of a damaged layout', (int)$copyOfDamaged->attribute( 'id' ), 1 );
    check( $rows['top'][0]->isLinked(), 'the merged top zone takes the link of the linked row' );
    check( (int)$rows['main'][0]->attribute( 'position' ) === 5, 'the merged main zone takes the row with the most blocks' );
    check( $blockNames( $rows['main'][0] ) === array( 'in-first-row', 'in-second-row-a', 'in-second-row-b' ), 'the merged main zone holds the blocks of both rows' );

    // Draft and publish copy row for row: they do not change a damaged layout.
    $damagedPublished = $service->publish( $damagedId );
    $publishedRows = $zoneRows( (int)$damagedPublished->attribute( 'id' ), 2 );
    check( count( $publishedRows['main'] ) === 2 && count( $publishedRows['top'] ) === 2, 'publish keeps the rows of a damaged layout as they are' );
    $damagedDraft = $service->createDraft( (int)$damagedPublished->attribute( 'id' ) );
    $draftRows = $zoneRows( (int)$damagedDraft->attribute( 'id' ), 1 );
    check( count( $draftRows['main'] ) === 2 && count( $draftRows['top'] ) === 2 && count( $draftRows['side'] ) === 1, 'createDraft keeps the rows of a damaged layout as they are' );
    $total = 0;
    foreach ( $draftRows['main'] as $zone )
        $total += count( $blockNames( $zone ) );
    check( $total === 3, 'createDraft copies the blocks of every row' );
}
catch ( Throwable $e )
{
    check( false, 'exception: ' . get_class( $e ) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
}

eZDB::setInstance( $siteDB );
$memoryDB->close();

$failures = $GLOBALS['expLayoutsCopyTestFailures'];
$checks = $GLOBALS['expLayoutsCopyTestChecks'];
echo $failures === 0 ? "OK ($checks checks)\n" : "$failures of $checks checks FAILED\n";
// ezexec sets exit code 1 for any return value other than 1.
return $failures === 0 ? 1 : 0;
