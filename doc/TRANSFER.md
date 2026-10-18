Layouts transfer file
=====================

`expLayoutsCoreTransferService` exports layouts, shared layouts and their mappings (rules) into a ZIP file and imports
such a file. The admin pages `explayouts_ui/transfer_export` and `explayouts_ui/transfer_import` and the console command
`./console exp:layouts` (extension explayouts_ui) use it.

Format version 1
----------------

A ZIP archive with four JSON files (UTF-8, pretty-printed, slashes and Unicode unescaped). Other entries are ignored
with a warning.

### manifest.json

| Key | Meaning |
|-----|---------|
| `format` | Always `exponential-layouts-transfer` |
| `format_version` | `1`. A reader refuses a version above the one it knows (`expLayoutsCoreTransferFormat::VERSION`) before it reads anything else |
| `created` | ISO 8601 time of the export (UTC) |
| `generator` | `name` (`explayouts_core`) and `version` of the extension that wrote it |
| `source` | `site_name`, `site_url`, `exponential_version`, `database` of the exporting installation |
| `selection` | What was chosen: `layouts`, `shared` (`all`, `none` or a list of identifiers), `rules` (`none`, `selected`, `all`), `drafts` |
| `locale` | `main` and `translations` of the layout content. Layouts are single-language in this version (`eng`); block parameter values are carried as stored |
| `counts` | `layouts`, `shared_layouts`, `drafts`, `zones`, `linked_zones`, `blocks`, `parameters`, `collections`, `items`, `queries`, `rules`, `targets`, `conditions`, `node_references`, `object_references`. A reader checks `layouts`, `blocks` and `rules` |
| `files` | For each of the three documents: `sha256` (hex) and `bytes`. A reader checks every checksum |

### layouts.json

`{ "layouts": [ layout, ... ] }`, shared layouts first, then by identifier, the published row of an identifier before its
draft.

layout:

| Key | Meaning |
|-----|---------|
| `id` | The row id on the exporting installation (information only; imports never use it) |
| `identifier` | `[A-Za-z0-9_][A-Za-z0-9_.-]*`, unique per status in the file |
| `name`, `layout_type` | Strings |
| `shared` | `true` for a shared layout |
| `status` | `published` or `draft` (drafts only when the export asked for them) |
| `dependency` | `true` when the layout is in the file only because a chosen layout links a zone to it |
| `created`, `modified` | Unix times (information only) |
| `zones` | List of zones, one per identifier |

zone: `identifier`, `position`, `link` (`null`, or `{ "layout": <identifier of a published layout in the file>, "zone":
<zone identifier> }`), `blocks` (top-level blocks in order).

block: `definition_identifier` (may be empty), `name`, `view_type`, `item_view_type`, `position`, `placeholder`,
`parameters` (name to text value), `parameter_refs` (parameter name to reference, for parameters that hold a node id:
`parent_node_id`, `parent_location_id`, `location_id`, `node_id`), `collections`, `children` (nested blocks).

collection: `collection_type`, `offset`, `limit`, `query` (`null` or `{ "query_type", "parameters": <the stored JSON
text>, "refs": { "parent_location_id": "node:89", "topic_content_id": "object:5" } }`), `items`.

item: `position`, `value_type`, `item_type`, `value_id`, `ref` (`node:<id>` for `ez_location`, `object:<id>` for
`ez_content`, otherwise `null`).

### rules.json

`{ "rules": [ rule, ... ] }`. rule: `id` (information only), `layout` (identifier of a published layout in the file),
`priority`, `enabled`, `targets` (`type`, `value`, `ref`: `node:<id>` for numeric `node` and `subtree` targets),
`conditions` (`type`, `value`).

### references.json

`{ "node": { "<id>": { "remote_id", "path" } }, "object": { "<id>": { "remote_id" } } }`: every node and object a
reference names, with its remote id on the exporting installation (`null` when it did not exist there).

Export
------

- A selection names layouts and shared layouts by id or identifier, or `all`. Only published layouts unless drafts are
  asked for.
- Every zone link (`expLayoutsZone::isLinked()`: `linked_layout_id` and `linked_zone_identifier`) is written by the
  linked layout's identifier and checked with `expLayoutsZone::resolveLinkedSource()`; the linked layout is exported
  too, followed through chains. A link that leads nowhere is left out with a warning.
- Several zone rows with one identifier (damage from older copies) are exported as one zone, merged the way
  `expLayoutsCoreLayoutService::copy()` merges them, with a warning.
- Rules: none, those of the chosen layouts, or all (their layouts come along). Rules of layouts that are not
  published, or that point at no layout, are left out with a warning.

Import
------

1. **Validate**: the manifest (format, version), every checksum, the schema (`expLayoutsCoreTransferFormat::validateDocuments()`),
   the counts. Nothing is compared before the file is valid.
2. **Plan** (`expLayoutsCoreTransferDiff::plan()`): the file is compared with the same documents exported from this
   installation, by identifier and status for layouts and by layout and targets for rules. Node and object ids are
   compared by remote id. Each item is `new`, `identical`, `changed` or `conflicting`:
   - a layout conflicts when its layout type or shared flag differs, or a draft of it is open here;
   - a rule conflicts when the same targets map to another layout here.
   `only` limits the import to some layouts; the layouts they link to come along.
3. **Strategy** (`expLayoutsCoreTransferDiff::action()`), global or per item (plan key):
   - `new` items are created (unless their item says `skip`), in the status they have in the file;
   - `skip` keeps what is here;
   - `overwrite` writes the file's content into a draft of the layout (the existing draft, or a new one); it is
     published only when the import is asked to publish. A published layout keeps its row id, so links and rules to it
     stay. A rule is updated (priority, enabled, conditions; a conflicting rule is pointed at the file's layout);
   - `copy` makes a copy with `expLayoutsCoreLayoutService::copy()` and replaces its content with the file's, as a draft
     unless the import publishes. A copied rule is created disabled, and only when its own item asks for it: the global
     `copy` does not copy rules;
   - layouts in the file only as a dependency keep the version here unless their own item names a strategy.
4. **References**: ids are mapped by remote id. References without a match are reported; `unmapped=drop` (default)
   leaves out the items, sets query parameters to `null`, empties node parameters and leaves out rules with such a
   target; `keep` keeps the file's ids; `fail` writes nothing.
5. **Write**: one database transaction. Layouts that are linked to are written first; every zone link is made by the
   linked layout's identifier and checked to reach a zone. Any error rolls everything back and is reported.
6. **After the commit**: audit events (`data.explayouts_core.import`, one `data.explayouts_core.import.item` per written
   item; exports `data.explayouts_core.export`), and only the resolver cache entries that name an affected layout or
   rule are removed (plus the "no rule matched" entries when rules were written).

Tests
-----

- `php vendor/bin/phpunit -c extension/explayouts_core/phpunit.xml.dist`: format, checksums, version, schema, diff,
  strategies, id mapping and the selective cache clear, without a database.
- `php bin/php/ezexec.php extension/explayouts_core/tests/explayoutscoretransferroundtriptest.php`: copies the site's
  layout tables into a throwaway SQLite database, exports everything, imports it into a fresh one with other node and
  object ids, compares both row by row by natural key, then checks a dry run (all identical), a copy, an overwrite, a
  rollback and unmapped references. The site's database is only read.
