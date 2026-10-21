# M1 migration discovery — 21 October 2026

**Status:** M1 implementation and local verification are complete, ready for
review. All eight local compatibility combinations pass. GitHub-hosted/Linux CI
has not run. M2 AST parsing has not started.

## Implemented scope

- `MigrationFinder`, `FilesystemMigrationFinder`, immutable `MigrationFile`,
  `MigrationList`, and `DiscoveryOptions`, under `src/Discovery/`.
- Literal, non-recursive directory enumeration for `*_*.php` files. Duplicate
  basenames use the last configured path, then sort lexically and receive stable
  1-based ordinals. Path precedence is never sorted away.
- Relative paths resolve against the Laravel application root. Repeatable CLI
  `--path` values replace configuration for that run. Invalid configuration,
  missing/non-directory/unreadable inputs, and enumeration failures exit 1.
- Valid filename timestamps produce timezone-independent date strings. Invalid
  names/timestamps retain the file with a null date and a structured
  `NON_STANDARD_FILENAME` warning (file present, source line null).
- An empty path set or empty directories return an empty `MigrationList`.
  `NO_MIGRATIONS_FOUND` result composition remains for the later analyzer/CLI
  milestones. A failed path never silently returns an empty or partial list.
- A native directory reader behind a small injectable interface permits portable
  shuffled-listing and unreadable-directory tests without an additional runtime
  dependency. Directory scanning reads metadata only, never migration source.
- Minimal diagnostic value objects carry the implemented filename warning. Other
  diagnostic codes will be added with the milestones that actually produce them.
- The provider binds discovery interfaces. A shared command base handles path
  options, discovery errors/warnings, and the explicit unfinished-analyzer message.

Ordering and duplicate precedence were checked against installed Laravel 13 code
and the official [Laravel 12 Migrator](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Database/Migrations/Migrator.php)
and [Laravel 13 Migrator](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Migrations/Migrator.php).
Atlas deliberately accepts directory paths only, as scoped in M1. Unlike Laravel's
glob-based directory scan, Atlas fails explicitly when a directory cannot be
enumerated and treats directory names containing glob characters literally.

## Tests and quality checks

Every combination below passes dependency resolution, strict Composer
validation, platform requirements, 59 tests / 288 assertions, Pint, and
maximum-level PHPStan/Larastan with zero errors and no baseline/ignored errors.

| PHP | Laravel (lowest / highest) | Testbench (lowest / highest) | Result |
|---|---|---|---|
| 8.3.30 | 12.69.0 / 12.70.0 | 10.0.0 / 10.12.0 | Both pass |
| 8.3.30 | 13.30.0 / 13.37.0 | 11.0.0 / 11.3.0 | Both pass |
| 8.4.26 | 12.69.0 / 12.70.0 | 10.0.0 / 10.12.0 | Both pass |
| 8.4.26 | 13.30.0 / 13.37.0 | 11.0.0 / 11.3.0 | Both pass |

These are Windows runs against the framework/Testbench versions established in
M0, using cached dependencies with network access disabled. The original local
Composer lockfile and installed dependency selection were restored after the
matrix; the manifest and version constraints never changed.

After the matrix, final review tightened Unix-specific path joining so a literal
trailing backslash in a directory name is preserved. The complete quality checks
and platform checks then passed again on both PHP 8.3 and 8.4 against the restored
Laravel 13 dependencies: **59 passed / 288 assertions, one Unix-only test skipped
on Windows**. The new Unix regression is included for the configured Linux CI;
its platform-specific behavior has not been exercised locally.

Coverage includes basename sorting across directories, duplicate precedence in
both path orders, non-recursion/filtering, literal paths containing spaces and
brackets, valid and invalid timestamps, nullable filename-warning locations, empty
sets, invalid directories, injected directory failure, shuffled listing
determinism, retained entries after disappearance, and throwing/invalid source
that is never executed. Feature tests cover provider bindings and configured vs.
overridden paths for all three commands, with database connections blocked.
Windows drive, UNC, drive-relative, and root-relative paths have dedicated tests.

Separate installed-package smoke checks pass in Laravel 12.70.0 and 13.37.0
applications without package development dependencies or manual provider
registration. They verify actual auto-discovery, discovery bindings, ordering,
duplicate precedence, filename warnings, and path options for all three commands.
The current M1 package was explicitly reinstalled to replace the cached M0 copy;
database connections were blocked and throwing/invalid source never executed.

Ignored evidence is under `.cache/verification/m1/`: `matrix-results.json`,
per-row logs/lockfiles, `final-results.json`, `final-source-hashes.json`, and
`installation-results.json`. The matrix snapshot precedes the final Unix-only
path correction; final-check hashes and installation snapshots cover the final
source. Hosted CI and Linux execution remain unverified.

## Current behavior and limitations

All three commands now perform discovery. After a successful scan they print any
filename warnings, then `The analyzer is not implemented yet (M1 discovery only).`
and exit 1. An input/directory failure prints its error and exits 1 earlier.
`--json` still does not emit the future JSON contract; `--connection` and
`--strict` retain their signatures without schema-analysis behavior.

No migration source is read, parsed, included, or executed. There is no schema
reconstruction, schema-dump detection, table history, column trace, or renderer
implementation. Source-read/size/parser failures and their ordered skip markers
belong to M2 and later milestones.

No runtime dependency or supported framework constraint changed. The approved
specification documents and `prompt.md` remain byte-identical to the M0 baseline.
Their old approval/status wording is historical; README and this handoff record
current progress. `M0-HANDOFF.md` remains the original verification record.

No commits, pushes, publication, hosted CI execution, application database access,
or production changes were made.

## Next milestone

Review M1, then separately authorize M2: static AST parsing, import/name
resolution, migration/up-method discovery, conditional/connection metadata, and
recoverable per-file failures. Discovery's ordered file DTOs are ready as input;
M2 has not started.
