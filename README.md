# Laravel Migration Atlas

Laravel Migration Atlas is a Composer package for reconstructing expected schemas,
migration history, and column lineage through static analysis of migration source.
The planned analyzer will never execute migrations or connect to a database.

**Status: M1 migration discovery implemented. The analyzer is not implemented yet.**
Package integration and deterministic migration discovery are available. AST
parsing, reconstruction, and result rendering are future milestones. This
repository is not yet a functional schema inspection tool or a published release.

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
  remain in the list if a source disappears before the future parsing stage.

The container resolves `MigrationAtlas\Discovery\MigrationFinder` to
`FilesystemMigrationFinder`. Its `find(DiscoveryOptions)` method returns an
immutable `MigrationList` of `MigrationFile` values and structured diagnostics.
Automatic discovery of third-party providers' migration paths remains outside
MVP scope; add those directories to the configuration explicitly.

## Development

```shell
composer install
composer validate --strict
composer test
composer lint
composer analyse
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

Current implementation, verification results, and limitations are recorded in
[the M1 handoff](M1-HANDOFF.md). The [M0 verification handoff](M0-HANDOFF.md)
preserves the earlier scaffolding checkpoint.

## Design and scope

The approved design lives in [the MVP specification](docs/mvp-spec.md),
[architecture](docs/architecture.md), and [implementation plan](docs/implementation-plan.md).
The specification documents preserve the approved design baseline and may still
contain their original pre-implementation approval wording. Current progress is
recorded in this README and the milestone handoffs. M1 supplies discovery only;
M2 (AST parsing) and later milestones require separate review and authorization.

## License

[MIT](LICENSE), copyright 2026 Ahmed Asban.
