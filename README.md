# Laravel Migration Atlas

Laravel Migration Atlas is a Composer package for reconstructing expected schemas,
migration history, and column lineage through static analysis of migration source.
The planned analyzer will never execute migrations or connect to a database.

**Status: migration discovery and static AST parsing implemented.**
Package integration, deterministic discovery, and a guarded parser API are
available. Schema operation normalization, reconstruction, and result rendering
remain unimplemented. This repository is not yet a functional schema inspection
tool or a published package release.

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
`--strict` for signature compatibility with the approved design. Commands now
discover migration files from configured directories, or from `--path` overrides.
After successful discovery they print any filename warnings, then
`The analyzer is not implemented yet (M1 discovery only).` and exit with code 1.
Invalid/unreadable discovery directories also exit 1 with an explanatory error.
`--json` does not yet produce the future JSON contract; `--connection` and
`--strict` do not yet perform schema analysis.

The commands deliberately retain their discovery-only behavior. The new parser
is available through the `MigrationAtlas\Parsing\MigrationParser` container
binding; it is not yet connected to a complete command analysis pipeline.

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

These are structural parser results, not reconstructed tables or replayable
schema operations. The later normalization layer must translate failures into
ordered unknown-effects markers and apply uncertainty semantics.

Raw values preserve PHP string/source bytes. Ordinary UTF-8 payloads can be
JSON-encoded directly; binary strings require an explicit encoding policy in
the future renderer. The parser does not silently replace or transcode bytes.

## Development

```shell
composer install
composer validate --strict
composer test
composer lint
composer analyse
php tests/Runtime/parser-smoke.php
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
its PHPUnit dependency requires a newer parser. The standalone smoke check covers
imports, connection routing, fluent/arrow callbacks, source locations, size and
syntax failures, and migration non-execution.

Current implementation, verification results, and limitations are recorded in
[the M2 handoff](M2-HANDOFF.md). The [M1 handoff](M1-HANDOFF.md) and
[M0 verification handoff](M0-HANDOFF.md) preserve the earlier checkpoints.

## Design and scope

The approved design lives in [the MVP specification](docs/mvp-spec.md),
[architecture](docs/architecture.md), and [implementation plan](docs/implementation-plan.md).
The specification documents preserve the approved design baseline and may still
contain their original pre-implementation approval wording. Current progress is
recorded in this README and the milestone handoffs. M2 supplies parsing
foundations; normalization (M3/M4), reconstruction, history/trace, and complete
CLI rendering require subsequent review and implementation.

## License

[MIT](LICENSE), copyright 2026 Ahmed Asban.
