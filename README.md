Exponential Layouts Core
========================

General description
-------------------

Exponential Layouts Core (`explayouts_core`) is the core domain service layer of the Exponential Layouts suite for Exponential 6 / Exponential Legacy. It wraps the persistent value objects owned by the `explayouts` extension (`expLayoutsLayout`, `expLayoutsZone`, `expLayoutsBlock`, `expLayoutsCollection`, `expLayoutsRule`, ...) in small service classes with a create/load/update/delete API, so admin modules and the JSON API do not have to talk to `eZPersistentObject` directly.

It is an Exponential Legacy port inspired by the `netgen/layouts-core` package. The services are intentionally thin: they use the Exponential Legacy environment (database, cache, permissions) rather than the Doctrine/Symfony stack of the upstream package.

This extension provides the following capabilities:

- Layout services - Create, load, list, update, publish, copy and delete layouts, including draft/published handling and lookup by identifier or layout type.
- Zone services - Create, load, update and delete zones per layout, count their blocks and link a zone to a shared layout (zone linking).
- Block services - Create, load, update, move and delete blocks, set their parameters and attach collections.
- Collection services - Manage block collections and their items: add, load, update, remove and replace items in one call.
- Rule services - Manage layout mapping rules: create, list, copy, enable/disable, delete and replace their targets and conditions.
- Layouts transfer - Export layouts, shared layouts and rules into a versioned ZIP file and import it with a dry-run diff, per-item strategies, remote id mapping and one transaction (`expLayoutsCoreTransferService`, format in [doc/TRANSFER.md](doc/TRANSFER.md)).
- Stable programmatic API - One intended public API surface for every consumer (admin modules, JSON API, CLI scripts, your own integrations) instead of raw persistence calls.

Features
--------

The following features are provided by the Exponential Layouts Core extension:

- Five focused service classes - Each service covers one aggregate of the layouts domain and exposes plain PHP methods with predictable signatures; all services are instantiated directly with `new` and carry no global state.
- Draft/published awareness - Layout methods distinguish drafts (`status` 1) and published rows (`status` 2): `loadDraft()`, `loadPublished()`, `publish()`, and optional status filters on the list methods.
- Zone linking - `expLayoutsCoreZoneService::setLinkedLayout()` links a zone to a shared layout, the mechanism behind reusable shared zones.
- Whole-list item replacement - `expLayoutsCoreCollectionService::setItems()` replaces a collection's items in one call (item values are node ids), the operation the SPA editor uses when saving manual collections.
- Target/condition replacement - `expLayoutsCoreRuleService::setTargets()` and `setConditions()` accept arrays of `type`/`value` hashes and replace the existing sets atomically.
- Almost no configuration of its own - The extension ships no modules or templates and one INI file (`audit.ini.append.php`, which registers the audit events of the layouts transfer); it contains the five service classes, the transfer classes and an `autoloads/explayouts_core_autoload.php` class map. All domain configuration (block definitions, layout types, resolver fallback) stays in `explayouts.ini` of the `explayouts` extension.
- Proven consumers - The services power `extension/explayouts_ui/modules/explayouts_ui/` (`layout_list.php`, `rule_list.php`, `rule_edit.php`), the `explayouts_ui_api` JSON API dispatcher and module views, and `extension/explayouts/bin/php/layout_info.php`.
- Subclass-friendly - Services are created with `new` at each call site, so behavior can be changed by subclassing in your own extension without any global replacement mechanism.

Version
-------

- The current version of Exponential Layouts Core is 1.0.0
- Last Major update: July 30, 2026

Copyright
---------

- Exponential Layouts Core is copyright 1998 - 2026 7x
- See: [LICENSE.md](LICENSE.md) for more information on the terms of the copyright and license

License
-------

Exponential Layouts Core is licensed under the GNU General Public License.

The complete license agreement is included in the [LICENSE.md](LICENSE.md) file.

Exponential Layouts Core is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License or at your
option a later version.

Exponential Layouts Core is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

The GNU GPL gives you the right to use, modify and redistribute
Exponential Layouts Core under certain conditions. The GNU GPL license
is distributed with the software, see the file LICENSE.md.

It is also available at http://www.gnu.org/licenses/gpl.txt

You should have received a copy of the GNU General Public License
along with Exponential Layouts Core in LICENSE.md. If not, see http://www.gnu.org/licenses/.

Using Exponential Layouts Core under the terms of the GNU GPL is free (as in freedom).

For more information or questions please contact
info@se7enx.com

Requirements
------------

The following requirements exists for using the Exponential Layouts Core extension:

Exponential version
- Make sure you use Exponential 6 / eZ Publish Legacy (required) or higher.

PHP version
- Make sure you have PHP 8.1 or higher.

Sibling extensions
- Required: `extension/explayouts` installed with its `explayouts_*` database schema — this extension only provides services on top of it (value objects and database tables).
- Consumed by: `explayouts_ui` and `explayouts_ui_api` — activate `explayouts_core` before either of them.

Installation
------------

In short: place the extension in `extension/explayouts_core`, activate it via `site.ini` `[ExtensionSettings] ActiveExtensions[]` (or per siteaccess via `ActiveAccessExtensions[]`), then regenerate autoloads and clear all caches. The extension ships no INI settings of its own — it contains only the five service classes plus an `autoloads/explayouts_core_autoload.php` class map.

See [INSTALL.md](INSTALL.md) for the full step-by-step installation instructions.

Usage
-----

All services are plain classes; instantiate them directly.

| Class | Purpose |
|-------|---------|
| `expLayoutsCoreLayoutService` | `load`, `loadDraft`, `loadPublished`, `listAll`, `create`, `update`, `publish`, `copy`, `delete`, `loadByIdentifier`, `listByType` |
| `expLayoutsCoreZoneService` | `load`, `loadByLayout`, `create`, `update`, `delete`, `countBlocks`, `setLinkedLayout` |
| `expLayoutsCoreBlockService` | `load`, `loadByZone`, `create`, `update`, `move`, `delete`, `setParameters`, `setCollection` |
| `expLayoutsCoreCollectionService` | `load`, `loadByBlock`, `create`, `update`, `delete`, `addItem`, `removeItem`, `loadItems`, `updateItem`, `setItems` |
| `expLayoutsCoreRuleService` | `load`, `listAll`, `create`, `update`, `setTargets`, `setConditions`, `copy`, `delete`, `listByLayout`, `enable`, `disable` |

A minimal example creating and publishing a layout with one block:

```php
<?php
$layoutService = new expLayoutsCoreLayoutService();
$blockService  = new expLayoutsCoreBlockService();
$zoneService   = new expLayoutsCoreZoneService();

$layout = $layoutService->create( 'homepage', 'Homepage', '2_column' );
$zones  = $zoneService->loadByLayout( $layout->attribute( 'id' ), 1 );

$block = $blockService->create( $zones[0]->attribute( 'id' ),
                                $layout->attribute( 'id' ),
                                'text', 'Intro', 'default' );
$blockService->setParameters( $block->attribute( 'id' ),
                              array( 'content' => '<p>Hello</p>' ) );

$layoutService->publish( $layout->attribute( 'id' ) );
```

Mapping rules follow the same pattern:

```php
<?php
$ruleService = new expLayoutsCoreRuleService();
$rule = $ruleService->create( $layoutId, 10, 1 ); // priority 10, enabled
$ruleService->setTargets( $ruleId, array( array( 'type' => 'subtree', 'value' => '2' ) ) );
$ruleService->setConditions( $ruleId, array( array( 'type' => 'siteaccess', 'value' => 'site' ) ) );
```

Consumers of these services in the suite:

- `extension/explayouts_ui/modules/explayouts_ui/` (`layout_list.php`, `rule_list.php`, `rule_edit.php`)
- `extension/explayouts_ui_api/` (JSON API dispatcher and module views)
- `extension/explayouts/bin/php/layout_info.php`

See [doc/USAGE.md](doc/USAGE.md) for exhaustive scenarios with the real method signatures for layouts, zones, blocks, collections and mapping rules, CLI bootstrap notes, and the full customization guide covering the settings layer (INI cascade via `explayouts.ini`), the template layer (none here — rendering templates belong to `explayouts` and the UI extensions) and the PHP layer (subclassing services, stable data shapes for `update()` calls).

Documentation
-------------

| Document | Description |
|----------|-------------|
| [INSTALL.md](INSTALL.md) | Step-by-step installation: activation, autoloads, sibling extension ordering |
| [doc/USAGE.md](doc/USAGE.md) | PHP examples with real signatures for all five services, customization layers |
| [doc/FAQ.md](doc/FAQ.md) | Answers to the most common questions and problems |
| [doc/TODO.md](doc/TODO.md) | Known gaps and planned improvements |
| [doc/SUPPORT.md](doc/SUPPORT.md) | How and where to get help |
| [LICENSE.md](LICENSE.md) | The complete GNU General Public License agreement |

Troubleshooting
---------------

Read the FAQ
- Some problems are more common than others. The most common ones are listed in [doc/FAQ.md](doc/FAQ.md).

Use our support systems
- If you have questions not handled by this document or the FAQ, you can reach us via [7x : se7enx.com](https://se7enx.com).
- If you find a bug or defect, please report it to the [Exponential Layouts Core: Issue Tracker](https://github.com/se7enxweb/explayouts_core/issues).
