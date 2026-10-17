# Using explayouts_core

All services are plain classes; instantiate them directly.

## Layouts

```php
<?php
$layoutService = new expLayoutsCoreLayoutService();

// List layouts (optionally filter by status: 1 = draft, 2 = published)
$layouts = $layoutService->listAll();
$published = $layoutService->listAll( 2 );
$byType = $layoutService->listByType( '2_column' );

// Load a single layout
$layout = $layoutService->load( $layoutId );
$draft = $layoutService->loadDraft( $layoutId );
$live = $layoutService->loadPublished( $layoutId );
$byIdentifier = $layoutService->loadByIdentifier( 'homepage' ); // status 2 by default

// Create, update, publish, copy, delete
$layout = $layoutService->create( 'homepage', 'Homepage', '2_column' );
$layoutService->update( $layoutId, array( 'name' => 'New name' ) );
$layoutService->publish( $layoutId );
$copy = $layoutService->copy( $layoutId );
$layoutService->delete( $layoutId );
```

`copy()` makes a new draft with the source's zones, each exactly once, with
the zone's link to a shared layout (when it has one), its position and its
blocks, collections and parameters; a zone the layout type defines and the
source lacks is added once. Since 1.0.8: earlier versions made every zone of
the copy twice, and a block added to the copy could land in the row the
editor does not show. A source damaged that way is merged into one zone per
identifier in the copy. Draft and publish copy a layout row for row and do
not change it.

Test (an in-memory SQLite database, the site database is not touched):

```bash
php bin/php/ezexec.php extension/explayouts_core/tests/explayoutscorelayoutcopytest.php
```

## Zones

```php
<?php
$zoneService = new expLayoutsCoreZoneService();

$zones = $zoneService->loadByLayout( $layoutId );        // all statuses
$drafts = $zoneService->loadByLayout( $layoutId, 1 );    // drafts only
$zone  = $zoneService->create( $layoutId, 'left' );      // status 1 (draft) by default
$zoneService->update( $zoneId, array( 'identifier' => 'main' ) );
$count = $zoneService->countBlocks( $zoneId );
$zoneService->setLinkedLayout( $zoneId, $sharedLayoutId ); // zone linking to a shared layout
$zoneService->delete( $zoneId );
```

## Blocks

```php
<?php
$blockService = new expLayoutsCoreBlockService();

$blocks = $blockService->loadByZone( $zoneId );
$block  = $blockService->create( $zoneId, $layoutId, 'text', 'Intro', 'default' );
$blockService->update( $blockId, array( 'view_type' => 'default', 'position' => 2 ) );
$blockService->setParameters( $blockId, array( 'content' => '<p>Hello</p>' ) );
$blockService->setCollection( $blockId, 'manual' );
$blockService->move( $blockId, $otherZoneId );
$blockService->delete( $blockId );
```

## Collections

```php
<?php
$collectionService = new expLayoutsCoreCollectionService();

$collection = $collectionService->create( $blockId, 'manual' );
$collection = $collectionService->loadByBlock( $blockId );

$collectionService->addItem( $collectionId, $nodeId );
$items = $collectionService->loadItems( $collectionId );
$collectionService->updateItem( $itemId, array( 'position' => 0 ) );
$collectionService->removeItem( $itemId );

// Replace the whole item list at once (item values are node ids)
$collectionService->setItems( $collectionId, array( $nodeIdA, $nodeIdB ) );
```

## Mapping rules

```php
<?php
$ruleService = new expLayoutsCoreRuleService();

$rules = $ruleService->listAll();          // all rules
$enabled = $ruleService->listAll( true );  // enabled only
$forLayout = $ruleService->listByLayout( $layoutId );

$rule = $ruleService->create( $layoutId, 10, 1 ); // priority 10, enabled

// Targets/conditions are arrays of hashes with 'type' and 'value' keys;
// existing targets/conditions are replaced.
$ruleService->setTargets( $ruleId, array(
    array( 'type' => 'subtree', 'value' => '2' ),
) );
$ruleService->setConditions( $ruleId, array(
    array( 'type' => 'siteaccess', 'value' => 'site' ),
) );

$ruleService->enable( $ruleId );
$ruleService->disable( $ruleId );
$copy = $ruleService->copy( $ruleId );
$ruleService->delete( $ruleId );
```

## Using the services from CLI scripts

Bootstrap an `eZScript` environment first, then use the services as usual. `extension/explayouts/bin/php/layout_info.php` is a working example.

## Customization

### Settings layer

`explayouts_core` ships no INI files of its own. All behavior the services expose (block definitions, layout types, query types, resolver fallback) is configured through `explayouts.ini` of the `explayouts` extension, which follows the normal INI cascade — extension defaults, then `settings/siteaccess/<siteaccess>/`, then siteaccess settings shipped in other active extensions, then `settings/override/`. See `extension/explayouts/doc/USAGE.md` for concrete override examples.

### Template layer

This extension contains no templates and no design directory; there is nothing to override at the template layer. Rendering templates belong to `explayouts` (frontend) and `explayouts_ui` / `explayouts_ui_api` (admin) and are overridden there through the design cascade.

### PHP layer (safe extension points)

- The five service classes are the intended public API for manipulating layouts programmatically; write your integration code against them rather than against `eZPersistentObject` rows.
- To change service behavior, subclass a service in your own extension (e.g. `class mySiteLayoutService extends expLayoutsCoreLayoutService`) and instantiate your subclass in your code — the services are created with `new` at each call site, so no global replacement mechanism exists (and none is needed).
- Data shapes accepted by `update()` methods are attribute arrays of the underlying `explayouts` value objects; treat those column names as a stable contract (`name`, `identifier`, `layout_type`, `view_type`, `position`, ...).
