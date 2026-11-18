# Laravel Migration Atlas

Laravel Migration Atlas is a Composer package for reconstructing expected schemas,
migration history, and column lineage through static analysis of migration source.
The planned analyzer will never execute migrations or connect to a database.

**Status: the analyzer is implemented.** Discovery, static parsing, operation
normalization, schema reconstruction, history/trace resolution, and the CLI
renderers are complete: `schema:inspect`, `schema:history`, and
`schema:trace` now render real analysis results in text or deterministic
JSON with exit codes 0/1/2. Remaining before the acceptance sweep: guard
clauses, schema-dump honesty, and the diagnostic-catalog conformance suite
(M8). This repository is not yet a published package release.

## Requirements

- PHP 8.3 or newer within PHP 8.x.
- Laravel 12 or 13.

The package identity is `ahmed-asban/laravel-migration-atlas`, with namespace
`MigrationAtlas\` and the MIT license. All production classes live under `src/`.

## Laravel integration

The Composer manifest declares Laravel package auto-discovery for
`MigrationAtlas\MigrationAtlasServiceProvider`. No manual provider registration is
needed in an application with package discovery enabled once the package is installed.

Publish configuration with:

```shell
php artisan vendor:publish --tag=migration-atlas-config
```

Approved defaults:

```php
return [
    'paths' => [database_path('migrations')],
    'max_file_size' => 1024 * 1024,
];
```

## Commands and current behavior

```shell
php artisan schema:inspect users
php artisan schema:history users
php artisan schema:trace users.email
```

All three commands accept `--json`, `--connection=default`, repeatable `--path`, and
`--strict`. They run the full static pipeline — discovery, parsing,
normalization, reconstruction, and one command resolution — and render the
result through a text or JSON renderer. Exit codes: `0` for resolved results
(including complete dropped tables), `1` for invalid arguments, discovery
failures, unresolved targets, or ambiguous identities, and `2` when a
resolved result is incomplete and `--strict` was passed. Reduced fidelity
never affects exit codes; errors always take precedence over strictness.

Text output shows the status banner with reasons split by origin
(direct/global/conditional/conflict), per-column provenance
(`added by`/`changed by`/`renamed from`/`dropped by` with file:line),
reduced-fidelity footnotes (`†`), conditional event markers (`‡`), a
`DROPPED` banner with last-known state, clearly separated lineage sections
for recreated tables, and candidate/last-known definition labels for
uncertain traces. JSON output is the deterministic envelope from the
specification: stable key order, no timestamps, always-present
`global_uncertainty` with ledger sources, reconciled counters, existence
and possible names, fidelity, and per-command data payloads. Malformed
UTF-8 payloads (binary defaults) are visibly substituted rather than
silently transcoded.

## Migration discovery

- Configured `paths` are scanned in their configured order. Repeatable `--path`
  options replace that list for the current command.
- Relative directory paths resolve against the Laravel project root; absolute
  local directory paths are also supported. Individual files and stream URLs are
  not accepted as discovery paths. Directory names containing spaces or glob
  characters are treated literally. On Windows, paths must include a drive or
  UNC share when absolute; drive-relative and root-relative paths are rejected.
- Discovery matches direct `*_*.php` files only, without recursion. It excludes
  directories, dotfiles, and other extensions.
- Duplicate migration basenames use the last configured path. The resulting
  basenames sort lexically, with stable 1-based ordinals after deduplication.
- A valid `YYYY_MM_DD_HHMMSS_name.php` timestamp is recorded as
  `YYYY-MM-DD HH:MM:SS`, without timezone conversion. Nonstandard or invalid
  timestamps retain the file with a null date and `NON_STANDARD_FILENAME` warning.
- An empty input set returns an empty migration list. A failed directory scan
  throws `DiscoveryFailed`; it never silently becomes an empty or partial list.
- Source contents are never read or executed by discovery. Discovered entries
  remain in the list if a source disappears before parsing.

The container resolves `MigrationAtlas\Discovery\MigrationFinder` to
`FilesystemMigrationFinder`. Its `find(DiscoveryOptions)` method returns an
immutable `MigrationList` of `MigrationFile` values and structured diagnostics.
Automatic discovery of third-party providers' migration paths remains outside
MVP scope; add those directories to the configuration explicitly.

## Static parsing

`MigrationParser::parse(MigrationFile)` returns an immutable `ParsedMigration`
containing the original file/ordinal, raw calls, migration connection context,
conditional flag, diagnostics, and an explicit `parsed` flag.

- Source is opened read-only as bytes. The native reader rejects nonregular
  files, checks file metadata, and bounds reads to `max_file_size + 1` bytes.
  The configured size limit defaults to 1 MiB and must be a positive integer
  below `PHP_INT_MAX`. This is not a CPU-timeout or fatal-memory-recovery promise.
- php-parser v5 builds an AST; import and class names resolve without loading
  migration classes, parents, traits, or application configuration. Named and
  directly returned anonymous migrations are supported. `down()` schema effects
  and connection writes are excluded from forward analysis.
- Schema calls, explicit connection wrappers, SQL-executing calls, closure/arrow
  Blueprint callbacks, and fluent method chains retain source text and lines.
  Blueprint binding follows the actual callback parameter, including aliases.
- Static literals and resolved `X::class` values are captured; dynamic
  expressions remain `Unresolvable` values. No functions, constructors,
  predicates, migration methods, or source code are executed.
- Literal migration connections and explicit wrappers retain their distinct
  context. Dynamic routing, property mutations, instance escapes/aliases, custom
  inheritance, traits, and `getConnection()` overrides never fabricate a default
  partition.
- `shouldRun()` is detected by presence only and emits `SHOULD_RUN_DETECTED`.
  Control flow, callback aborts, opaque side effects, unsupported call chains,
  and uncertain Blueprint bindings remain visibly unresolved in the raw model.
- Size/read/stat/syntax/parser failures retain the original file and ordinal,
  return `parsed=false`, and carry one root warning with no partial calls.
  Missing or ambiguous migration classes and missing/uninspectable `up()` bodies
  also fail conservatively. A verified empty `up()` is a successful no-op.

These are structural parser results. The normalization layer translates them
into typed schema operations and ordered unknown-effects markers; schema replay
and the resulting completeness/existence rules remain unimplemented.

Raw and normalized values preserve PHP string/source bytes. Ordinary UTF-8 payloads can be
JSON-encoded directly; binary strings require an explicit encoding policy in
the future renderer. The parser does not silently replace or transcode bytes.

## Operation normalization

`OperationNormalizer::normalize(ParsedMigration)` returns an immutable
`NormalizedMigration` with the original file, ordered operations, accumulated
diagnostics, and the explicit `parsed` flag. Normalization uses data only: it
does not reopen source, evaluate PHP, inspect application configuration, or
connect to a database.

- Table operations: create, alter, drop/drop-if-exists, and rename.
- Basic columns: `id`, `string`, `integer`, `boolean`, `text`, and `timestamps`.
  `id` captures unsigned bigint, auto-increment, and primary-key intent;
  timestamps expand into nullable `created_at` and `updated_at`. Unspecified
  length/precision stays null rather than guessing runtime-configured defaults.
- Modifiers: positional `nullable()`/boolean values and static literal
  `default(...)`, with an explicit `hasDefault` distinction for `default(null)`.
  Column drop/rename operations retain static column names.
- Each operation has a kind, connection context, execution certainty, and
  file/line plus stable `[migrationOrdinal, operationOrdinal]` position.
  `shouldRun()` makes supported operations conditional without repeating its
  parser diagnostic. Supported columns carry full fidelity.
- `CreateTable` and `AlterTable` contain an ordered operation body. A create's
  `columns` list is a projection of its `AddColumn` children, not another stream
  to replay. Positions traverse parent then children in source order.
- Dynamic routing never falls back to default. Unresolved operations distinguish
  known-table/known-connection attribution, name-scoped unknown connections, and
  unknown table targets; rename metadata retains known endpoints.
- Raw SQL produces both `RAW_SQL_DETECTED` and `UNATTRIBUTABLE_OPERATION`.
  Unknown facade macros, including `createIfNotExists`, never fabricate a table
  target or a create operation. Unknown Blueprint methods stay table-attributed.
- Failed parses produce exactly one whole-file marker at operation ordinal 0,
  preserving the root warning and nullable line. A verified empty body stays a
  successful no-op. Reconstruction will apply the stronger whole-file cap later.
- Tier-2 columns are recorded with their canonical raw type and literal
  arguments (`total`/`places`, `precision`, `length`, `allowedValues`),
  increments carry auto-increment/unsigned/primary intent, and known
  expansions (`softDeletes`, `softDeletesTz`, `rememberToken`, `timestampsTz`)
  record their defined columns. Every Tier-2 element is fidelity `reduced`.
- Partial modifiers (`unsigned`, `after`, `first`, `comment`, `charset`,
  `collation`, `useCurrent`, `useCurrentOnUpdate`) record static data and mark
  the column reduced. `default(now())`/`default(DB::raw(...))` record an
  explicit `[dynamic]` marker; `useCurrent()` records `CURRENT_TIMESTAMP`.
- `change()` captures a column-modification event carrying only the declared
  definition; the affected column is always fidelity `reduced`.
- Index operations normalize Laravel's exact convention naming
  (`{table}_{columns}_{type}`, lowercased, dashes/dots to underscores) when no
  name is given, and drops resolve literal names or conventional names from
  column lists. `dropPrimary()` without arguments keeps a null name because
  no static name exists.
- Foreign-key chains (`foreign()->references()->on()->onDelete()->onUpdate()`,
  the `cascadeOnDelete`/`restrictOnDelete`/`nullOnDelete`/`cascadeOnUpdate`
  family, `dropForeign`) are captured at full fidelity from literals.
  `foreignId()->constrained()` derives the convention table through
  Laravel's pluralization and stays full fidelity;
  `foreignIdFor(Model::class)` resolves `::class` only when statically
  resolvable, records model conventions, and stays reduced because a model may
  override its table or key. FK-modifier calls before a key command exists
  (`foreignId('x')->onDelete(...)`) are visibly unresolved rather than
  dead Fluent attributes.

The `SupportMatrix` registry records the full approved construct classifications
and whether a normalization rule is implemented at this checkpoint. Tier-2
generic columns, increment columns, known expansions, partial modifiers,
dynamic-default markers, indexes, foreign keys, and `change()` are now
normalized with their defined reduced fidelity; `storedAs`/`virtualAs`/
`generatedAs`, guard clauses, and custom macros remain deferred. An
unsupported fluent chain is retained whole, so an unknown modifier cannot
accidentally become a captured column effect. Generic control flow and opaque
callback effects preserve the parser's conservative uncertainty.

The normalized DTO JSON snapshots are an internal test contract. They are not
the final command JSON envelope or a reconstruction result.

## Schema reconstruction

`SchemaReconstructor::replay(list<NormalizedMigration>)` (resolved from the
container to `FunctionalSchemaReconstructor`) folds the ordered operation
stream into a `ReconstructionResult`: a partition-aware `TableRegistry`,
the ordered `HistoryEvent` log, the `UncertaintyLedger`, replay and carried
diagnostics, and the reconciled counters
(`migrations_scanned = analyzed + skipped`, `operations_applied` excludes
skip markers). Replay is pure: no filesystem, database, or parser access,
enforced by architecture tests.

- The state model separates four axes per lineage: `TableStatus`
  (completeness only), `Existence` (present/absent/unknown, where absent is
  a certain tombstone), name certainty with observed possible names, and
  element fidelity (sticky per element; a table is reduced once any element
  is). Columns, indexes, and foreign keys replay with provenance; dropped
  columns keep last-known definitions with their drop source.
- Connection partitions never merge. A lineage exists within exactly one
  partition keyed by the operation's connection routing.
- Certain create/rename/drop follow their lineage rules: create starts a
  lineage, rename moves within the partition and records former names, drop
  writes a tombstone with `droppedBy`. A drop → re-create starts a fresh
  lineage while the old one stays tombstoned.
- Conflicts are diagnostics, never crashes: `DUPLICATE_TABLE_CREATE` keeps
  both live identities incomplete, `RENAME_TARGET_CONFLICT` does not apply
  the rename and marks both tables, `DUPLICATE_COLUMN` replaces the column
  and marks the table, `ALTER_UNKNOWN_TABLE` creates an unknown-origin
  phantom that is incomplete from birth, and unknown drop/rename targets
  warn without fabricating state. A create over a phantom retains the
  phantom's uncertainty; a create over a possibly-absent identity keeps
  both candidates and their reasons.
- Conditional operations (`shouldRun()`) never choose branches: creates are
  unknown-existence candidates, drops keep the pre-state as possible drops
  without `droppedBy`, renames retain both possible names with uncertain
  current names, and later operations touch all compatible candidates
  conservatively. Their reasons stay sticky.
- Unresolved operations act through their attribution: attributed effects
  mark matching lineages with a direct reason; name-scoped effects match
  current/former/possible names across partitions (including rename
  endpoints); unattributable effects apply the live-window rule, marking
  everything live or possibly live incomplete with uncertain names and
  demoting tombstones to unknown. All of them are retained in the ledger
  even when no lineage matched.
- A skipped file (syntax error, oversized, unreadable source) contributes
  one `skipped_migration` ledger entry and caps the entire run: every
  lineage — earlier, later, tombstoned, or recreated, in every partition —
  becomes incomplete with unknown existence and names until the result is
  composed. A verified empty `up()` is an analyzed no-op and caps nothing.

## History, tracing, and command results

`MigrationAtlas\Atlas` orchestrates the whole pure pipeline for one command
invocation and resolves a result DTO that presentation can render without
domain knowledge. Every result carries the shared analysis envelope
(`AnalysisMeta`): command identity, effective completeness, the
always-present global-uncertainty rollup with ledger sources in replay
order, and the reconciled counters. Error results keep the envelope,
counters, and diagnostics with a null data payload, never fabricating one.

- Table resolution follows the approved algorithm: a single present or
  uncertain candidate resolves; a certain live lineage wins over certain
  tombstones; several live or uncertain candidates are
  `AMBIGUOUS_TABLE_IDENTITY` errors listing every candidate with none
  silently chosen; only tombstones resolve to the latest certain one; no
  identity at all is `TABLE_NOT_FOUND`, caveated with the run's unresolved
  operations and skipped files so absence is never claimed as proof.
- `Atlas::inspect` resolves one identity and projects its status, existence,
  possible names, name certainty, reasons, fidelity, provenance, columns,
  indexes, foreign keys, and a per-lineage event summary. Complete certain
  absence renders `dropped` with `dropped_by` and last-known state;
  incompleteness takes precedence over a tombstone.
- `Atlas::history` renders every lineage that carried the requested name:
  earlier dead identities by their drop order, then the primary one, each
  with its state axes and its event timeline. Events carry sequence, date,
  operation, summary, conditional flag, and provenance; conditional events
  record intent, not proof. `current_name` is null when uncertain.
- `Atlas::trace` resolves the table lineage, then walks the column identity
  chain through renames in both directions: tracing a former name resolves
  forward to the current name, and tracing the current name still shows the
  original add. Certain drops terminate the chain with `dropped_by`; a
  certainly absent table makes its columns absent while keeping their
  last-known definitions; uncertain tables or columns report `exists` null
  with observed possible names. Unknown columns are `COLUMN_NOT_FOUND`
  errors.
- An empty discovery is `NO_MIGRATIONS_FOUND`; a run where every file was
  skipped still resolves target lookups as caveated `TABLE_NOT_FOUND`.

## Development

```shell
composer install
composer validate --strict
composer test
composer lint
composer analyse
php tests/Runtime/parser-smoke.php
php tests/Runtime/normalization-smoke.php
```

`composer check` runs validation, tests, formatting checks, and static analysis.
`composer format` applies Laravel Pint formatting. PHPStan/Larastan run at maximum
level against production code and configuration, without a baseline or ignored errors.

The library does not commit a Composer lockfile: contributors resolve a supported
dependency combination locally, and CI resolves each combination independently.
The database-related framework classes used by Testbench/Larastan are development
dependencies; `illuminate/database` is not a runtime package requirement. Feature
tests substitute a database-manager mock that rejects connection attempts and use
no application DB. Laravel can still register its own Eloquent resolver at boot.

| Laravel | Testbench | Pest / PHPUnit | PHP in CI |
|---|---|---|---|
| 12 | 10.x | Pest 3.8.7+ / PHPUnit 11.5.56 | 8.3, 8.4 |
| 13 | 11.x | Pest 3.8.7+ / PHPUnit 11.5.56 | 8.3, 8.4 |

Pest runs Testbench directly. `pest-plugin-laravel` v3 is deliberately absent
because its declared Laravel constraint excludes Laravel 13. The architecture
plugin is enabled for checks against database and code-execution dependencies.
GitHub Actions resolves lowest and highest compatible dependencies for all four
PHP/Laravel combinations (eight jobs); Composer's security blocking stays enabled.
Passing CI status must be checked on GitHub; the workflow alone does not establish it.
An additional production-only CI job pins `nikic/php-parser` to **5.0.0** without
development dependencies. The development matrix alone cannot test that floor:
its PHPUnit dependency requires a newer parser. The standalone smoke checks cover
imports, connection routing, fluent/arrow callbacks, source locations, size and
syntax failures, typed normalization, skip markers, and migration non-execution.

Current implementation, verification results, and limitations are recorded in
[the M7 handoff](M7-HANDOFF.md). The [M6 handoff](M6-HANDOFF.md), [M5 handoff](M5-HANDOFF.md), [M4 handoff](M4-HANDOFF.md), [M3 handoff](M3-HANDOFF.md), [M2 handoff](M2-HANDOFF.md), [M1 handoff](M1-HANDOFF.md), and
[M0 verification handoff](M0-HANDOFF.md) preserve the earlier checkpoints.

## Design and scope

The approved design lives in [the MVP specification](docs/mvp-spec.md),
[architecture](docs/architecture.md), and [implementation plan](docs/implementation-plan.md).
The specification documents preserve the approved design baseline and may still
contain their original pre-implementation approval wording. Current progress is
recorded in this README and the milestone handoffs. M7 supplies the working
CLI with both renderers and the exit-code contract; guard clauses, schema-dump
honesty, and the diagnostic-catalog conformance suite remain for the final
hardening milestone.

## License

[MIT](LICENSE), copyright 2026 Ahmed Asban.
