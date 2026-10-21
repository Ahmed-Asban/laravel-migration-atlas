# M0 implementation and verification — 18 October 2026

**Status:** Package scaffolding and local verification are complete. Awaiting human
review. GitHub-hosted CI has not run. Do not start M1 without separate approval.

## Files created or modified

| Area | Files |
|---|---|
| Package | `composer.json`, `README.md`, `LICENSE`, `.gitignore`, `.editorconfig` |
| Provider/config | `src/MigrationAtlasServiceProvider.php`, `config/migration-atlas.php` |
| Commands | `src/Cli/SchemaInspectCommand.php`, `src/Cli/SchemaHistoryCommand.php`, `src/Cli/SchemaTraceCommand.php` |
| Quality configuration | `phpunit.xml.dist`, `phpstan.neon.dist`, `pint.json` |
| Test bootstrap | `tests/TestCase.php`, `tests/Pest.php` |
| Feature tests | `tests/Feature/ServiceProviderTest.php`, `tests/Feature/CommandsTest.php`, `tests/Feature/PackageDiscoveryTest.php` |
| Safety tests/fixture | `tests/Architecture/SafetyTest.php`, `tests/Fixtures/migrations/2026_01_01_000000_never_execute.php` |
| CI | `.github/workflows/ci.yml` |
| Review record | `M0-HANDOFF.md` (this file, replacing the paused checkpoint) |

There are 22 package/review files. The three approved specification documents and
`prompt.md` remain byte-identical. Generated dependencies, the local lockfile,
portable PHP runtime, verification tools and logs are ignored under `vendor/`,
`composer.lock` and `.cache/`; they are not package source changes.

## Dependencies and constraints

Composer name: `ahmed-asban/laravel-migration-atlas`; namespace `MigrationAtlas\`;
MIT license. PSR-4 maps the package namespace directly to `src/`.

| Dependency | Constraint | Scope |
|---|---|---|
| PHP | `^8.3` | Runtime |
| illuminate/console | `^12.0` or `^13.0` | Runtime |
| illuminate/support | `^12.0` or `^13.0` | Runtime |
| nikic/php-parser | `^5.0` | Runtime, declared for future milestones; unused by M0 |
| larastan/larastan | `^3.13` | Development |
| laravel/pint | `^1.32` | Development |
| orchestra/testbench | `^10.0` or `^11.0` | Development |
| pestphp/pest | `^3.8.7` | Development |
| pestphp/pest-plugin-arch | `^3.1.1` | Development |
| phpstan/phpstan | `^2.3` | Development |

Testbench 10 is selected for Laravel 12; Testbench 11 for Laravel 13. Pest runs
Testbench directly: the Laravel plugin v3 excludes Laravel 13 and is not required.
Pest 3.8.7 and PHPUnit 11.5.56 resolved and passed in every tested combination.
`illuminate/database` is not a runtime requirement; database classes provided by
the development framework are used only for Testbench and its connection guard.

## Completed local compatibility matrix

The following eight combinations passed dependency resolution, strict Composer
validation, platform checks, tests, formatting checks and static analysis:

| PHP | Dependencies | Laravel resolved | Testbench resolved | Result |
|---|---|---|---|---|
| 8.3.30 | Lowest | 12.69.0 | 10.0.0 | All checks passed |
| 8.3.30 | Highest | 12.70.0 | 10.12.0 | All checks passed |
| 8.3.30 | Lowest | 13.30.0 | 11.0.0 | All checks passed |
| 8.3.30 | Highest | 13.37.0 | 11.3.0 | All checks passed |
| 8.4.26 | Lowest | 12.69.0 | 10.0.0 | All checks passed |
| 8.4.26 | Highest | 12.70.0 | 10.12.0 | All checks passed |
| 8.4.26 | Lowest | 13.30.0 | 11.0.0 | All checks passed |
| 8.4.26 | Highest | 13.37.0 | 11.3.0 | All checks passed |

These are actual Composer resolutions with security blocking enabled. Lowest
means the lowest compatible dependencies selected by Composer under that policy,
not every historical patch release. Two completed PHP 8.4 highest rows from
17 October were retained; the six unfinished rows passed on 18 October using
unchanged production code. Earlier download timeouts are superseded by successful
reruns. The interrupted local dependency installation has been restored; the
current local default is PHP 8.3 / Laravel 13 with highest compatible dependencies.

## Test results

- **14 tests / 88 assertions pass in each of the eight combinations.**
- Provider boot, default config merging, preservation of overrides, config
  publication, command arguments/options and explicit unimplemented behavior pass.
- Composer metadata and Laravel's package manifest discovery pass.
- Architecture tests reject database dependencies and migration/shell execution
  in production code. Feature tests use a database-manager mock that rejects
  connection attempts, with no configured connections. The throwing migration
  fixture is never executed.
- Separate isolated Laravel 12.70.0 and 13.37.0 host installations passed actual
  auto-discovery, config and all three command checks without manual provider
  registration or development dependencies. Database connections were blocked.
  Production/config/manifest bytes still match those successful installation snapshots.

The commands deliberately print `The analyzer is not implemented yet (M0
scaffolding only).` and exit 1. No JSON result, migration discovery, AST parsing,
schema reconstruction or M1 domain code is implemented.

## Static analysis and formatting

- PHPStan 2.3.0 (lowest) / 2.3.1 (highest), with Larastan 3.13.0: **maximum level,
  zero errors** in `src/` and `config/` for all eight combinations. No baseline or
  ignored PHPStan errors.
- Laravel Pint 1.32.0 (lowest) / 1.32.1 (highest), Laravel preset: **all checks pass**.
- `composer validate --strict`, `composer check-platform-reqs`, `composer test`,
  `composer lint` and `composer analyse` all exit 0 in every matrix row.
- Composer resolution reported no security vulnerability advisories.

## CI configuration

`.github/workflows/ci.yml` defines eight Ubuntu jobs: PHP 8.3/8.4 × Laravel 12/13 ×
lowest/highest dependencies, mapping to Testbench 10/11. It runs resolution,
validation, platform checks, tests, Pint and PHPStan/Larastan. Workflow YAML and
matrix structure were checked locally. Repository permissions are read-only;
checkout credentials are not persisted. No database service is configured.

The equivalent combinations passed locally on Windows. **GitHub Actions/Linux
execution remains unverified**, because nothing has been committed or pushed.
A configured workflow is not a green hosted CI run.

## Remaining warnings and review gate

- No unresolved local test, dependency, static-analysis or formatting failures.
- Composer may print its root-version fallback notice in this repository because
  it has no commits. Local verification used `COMPOSER_ROOT_VERSION=dev-main`;
  no package version was hardcoded into the library manifest.
- The package is unpublished and intentionally scaffolding-only.
- No application databases were accessed, no approved specifications were edited,
  and no commits or pushes were made. M1 has not started.

Detailed local evidence is in `.cache/verification/matrix-results.json`, per-row
logs and `installation-results.json`. These local artifacts are ignored. The
paused checkpoint was preserved as `.cache/verification/M0-HANDOFF-paused.md`.

**Next action: human review of M0. Await approval before any further milestone.**
