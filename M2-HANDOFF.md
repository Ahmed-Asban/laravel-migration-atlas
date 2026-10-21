# M2 static AST parsing - 22 October 2026

**Status:** M2 implementation and local verification are complete, ready for
review. All eight compatibility combinations and the exact production parser
floor pass against the final source. This checkpoint is uncommitted and has not
been pushed. GitHub-hosted CI has not run against M2.

## Implemented scope

- `MigrationParser` and `MigrationSourceReader` interfaces, their native
  implementations, and container bindings. The parser honors the configured
  `max_file_size`, validates its bounds, and enforces the limit even when a custom
  source reader is supplied.
- A guarded local source reader rejects streams, directories, and special files
  before opening. It checks regular-file metadata and size before and after a
  bounded read, detects changed/unreliable source, and distinguishes oversized
  files from unreadable files.
- AST parsing through `nikic/php-parser` and `NameResolver`. Migration files are
  read as text; no migration, application parent, trait, constructor, accessor,
  configuration expression, or `shouldRun()` body is executed or loaded.
- Declaration-level named migrations and directly returned anonymous migrations,
  resolved imports/aliases, case-insensitive PHP class/method matching, and
  explicit handling of missing, ambiguous, or bodyless migration/up declarations.
  `down()` bodies are excluded from analysis and connection mutation checks.
- `shouldRun()` detection by presence only. A successful parse emits the single
  `SHOULD_RUN_DETECTED` warning and retains conditional metadata, including when
  the body literally returns true or false.
- Migration connection metadata: direct Laravel migration subclasses use a
  literal connection declaration or symbolic `default` for absent/null/empty/
  `'0'` declarations. Custom inheritance, traits, accessor overrides, nonliteral
  declarations, connection mutation, direct instance escapes/aliases, and relevant
  property hooks remain unknown. Instance escape is a conservative guard, not
  general alias/data-flow analysis.
  Literal per-call wrappers retain independent routing; dynamic wrappers remain
  unresolved rather than falling back to a known declaration.
- Raw Schema calls, literal/dynamic connection wrappers, and SQL execution calls
  through the DB facade or Schema connection receiver. Unknown calls and side
  effects remain structural uncertainty. Control flow, nested schema/SQL effects,
  early exits, and unresolved callbacks retain uncertainty rather than being
  replayed as certain schema changes.
- Blueprint closure/arrow-function binding and fluent root/modifier extraction,
  including argument names/unpacking, source slices, and nullable source lines.
  Binding mutation/escape, nested callbacks, and callback aborts are handled
  conservatively.
- Static literal evaluation for supported scalar literals, signed numbers,
  literal arrays, and resolved `::class` names. Dynamic expressions return an
  `Unresolvable` value; no application/runtime resolution is attempted.
- Readonly raw DTOs retain the original `MigrationFile` and ordinal, extracted
  calls, arguments/modifiers, connection/conditional metadata, diagnostics, and
  the explicit `parsed` flag.

## Failure semantics

Each failed file returns `parsed=false`, its original discovery metadata, no
partial calls, and one root warning. Other files can still be analyzed in their
discovery order. A successfully inspected empty `up()` is a parsed no-op.

| Failure | Root diagnostic |
|---|---|
| Source exceeds the configured byte limit | `FILE_TOO_LARGE` |
| Discovered source cannot be read reliably | `MIGRATION_SOURCE_UNREADABLE` |
| PHP syntax error | `MIGRATION_SYNTAX_ERROR` |
| Recoverable nonsyntax parser failure or null AST | `MIGRATION_PARSE_FAILURE` |
| No unambiguous inspectable migration class | `MIGRATION_CLASS_NOT_FOUND` |
| Missing/abstract/bodyless `up()` | `UP_METHOD_MISSING` |

Locations retain the available file/line; unavailable lines remain null. SQL and
unsupported constructs are represented in the raw model, with normalization
diagnostics and ordered whole-file skip operations deferred to M3. Structural
unknown placeholders at this stage are not replayed schema operations.

## Tests and quality checks

The current full local suite passes **322 tests / 1,100 assertions**, with two
Unix-only tests skipped on Windows. Pint and maximum-level PHPStan/Larastan pass.
Coverage includes safety fixtures with top-level effects and throwing migration
methods, database-blocked Laravel integration, injected parser/read failures,
source caps and continuation, import resolution, connection routing, source
locations, raw SQL, dynamic expressions, control flow, and Blueprint uncertainty.

The following full compatibility runs use the frozen M1 lockfiles. Every row
passes installation, strict Composer validation, platform checks, the complete
test suite, Pint, and maximum-level PHPStan/Larastan with zero errors. The source
remained byte-identical throughout the final matrix. The original local lockfile
and installed dependency selection were restored afterward; no framework or
constraint update was needed.

| PHP | Dependency selection | Laravel | Testbench | M2 result |
|---|---|---|---|---|
| 8.3.30 | Lowest | 12.69.0 | 10.0.0 | PASS |
| 8.3.30 | Highest | 12.70.0 | 10.12.0 | PASS |
| 8.3.30 | Lowest | 13.30.0 | 11.0.0 | PASS |
| 8.3.30 | Highest | 13.37.0 | 11.3.0 | PASS |
| 8.4.26 | Lowest | 12.69.0 | 10.0.0 | PASS |
| 8.4.26 | Highest | 12.70.0 | 10.12.0 | PASS |
| 8.4.26 | Lowest | 13.30.0 | 11.0.0 | PASS |
| 8.4.26 | Highest | 13.37.0 | 11.3.0 | PASS |

An independent production installation without development dependencies verifies
the exact declared **php-parser 5.0.0** floor. Platform checks, the committed
runtime smoke script, and 22 extended checks each pass on PHP **8.3.30** and
**8.4.26**, using Laravel 12.70.0. This separate check matters because development
dependencies can require a newer parser even under a lowest-dependency test run.
Evidence is recorded in ignored `.cache/verification/m2/floor/summary.json`.
The floor installation was refreshed to the final source: all 38 package snapshot
files match both the workspace and installed copy, with no hash mismatches. A
separate exact-floor alias-routing regression also confirms that an instance
escape retains unknown migration routing while an explicit literal wrapper
remains independently routable.

Ignored matrix evidence is under `.cache/verification/m2/`: `matrix-results.json`,
`matrix-summary.json`, per-row logs, `source-hashes.json`, and the original local
lockfile. Earlier pre-alias-review results are retained separately and are not the
final verification record. `final-verification.json` records the final source,
specification, dependency-restoration, and floor-snapshot consistency checks.

The previous M1 commit, `f6adbd854105f6ee9a0f1a25734caa2ac6cb66a8`, passed all
eight Ubuntu quality jobs in the
[M1 GitHub Actions run](https://github.com/Ahmed-Asban/laravel-migration-atlas/actions/runs/39673251657).
That result does not verify the uncommitted M2 source. A separate production
runtime-floor CI job is now configured alongside the existing matrix; it has not
run on GitHub yet. The two Unix-only regressions also await M2 Linux execution.

## Current public behavior and limits

The new parser is available through the container/API. All three commands retain
their existing discovery-only stub behavior and exit 1 after the explicit
unfinished-analyzer message. There is no full CLI analysis or JSON contract yet.

M2 does not normalize raw calls into typed schema operations or reconstruct a
schema. Table history, column lineage, renderers, and whole-file skip replay
semantics remain subsequent work. SQL is detected, not interpreted. Arbitrary
PHP/data-flow analysis, runtime/configuration resolution, and physical connection
alias resolution are outside this implementation.

Raw PHP strings and source slices preserve their original bytes. UTF-8 inputs
are directly JSON-encodable; arbitrary binary PHP strings require an explicit
encoding policy in the M7 renderer. Do not claim that every raw DTO can already
be encoded as the final public JSON response.

No framework constraints or runtime dependencies were upgraded. The approved
specification documents and historical handoffs remain unchanged. No migration
execution, application database access, production changes, package publication,
commits, or pushes were performed by the agents during M2.

## Next checkpoint

Review M2. The next implementation scope is M3: normalize the raw model into
typed table operations and Tier-1
column/modifier operations, preserve routing/conditional provenance, and produce
the specified ordered whole-file unknown markers for failed migrations.
