# M3 table and basic column normalization - 22 October 2026

**Status:** Implementation and local verification are complete. All eight
compatibility combinations and the exact production parser floor pass against
the final source. No commit or push has been performed during this checkpoint.

## Implemented scope

- `SupportMatrix` is a data-driven registry for the approved construct
  classifications. Each entry records category, support level, tier, fidelity,
  handler, and current implementation availability. The registry distinguishes
  the approved eventual support from the subset implemented now. `down()` is
  explicitly not analyzed. Real PHP methods are matched case-insensitively;
  Fluent attribute modifiers retain their case-sensitive names.
- `OperationNormalizer::normalize(ParsedMigration)` returns a readonly
  `NormalizedMigration` with original discovery metadata, ordered operations,
  accumulated diagnostics, and explicit `parsed` state. The Laravel container
  resolves both the registry and normalizer. Normalization does not read source,
  parse PHP, load application classes, inspect configuration, or contact a DB.
- Immutable operations: `CreateTable`, `AlterTable`, `DropTable` (including its
  if-exists intent), `RenameTable`, `AddColumn`, `DropColumn`, `RenameColumn`, and
  `UnresolvedOperation`. All carry an explicit operation kind, source location,
  and execution certainty; schema mutations carry their connection partition.
- Basic column definitions for `id`, `string`, `integer`, `boolean`, `text`, and
  `timestamps`. `id` records bigint/unsigned/auto-increment/primary-key intent;
  timestamps expand into nullable `created_at` and `updated_at`. Literal length,
  precision, and integer flags are retained. Missing length/precision remains
  null instead of guessing runtime-configured defaults.
- Positional `nullable()` / boolean values and static `default(...)` literals.
  Repeated supported modifiers are applied in declared order. `hasDefault`
  distinguishes `default(null)` from no declared default. Scalars, signed finite
  numbers, plain literal arrays, and resolved class-name strings remain data.
- Static column drop lists and column/table rename endpoints. The argument
  binder accepts supported positional/named signatures and rejects ambiguous,
  missing, duplicate, unknown, extra, or unpacked bindings. Magic Fluent
  modifiers use the documented positional grammar only.

## Operation ordering and uncertainty

Source positions use `[migrationOrdinal, operationOrdinal]`. Ordinary operations
are 1-based, traversing a table parent followed by its ordered body, then the next
Schema call. Timestamp expansion gives each column its own position at the same
source line. Normalizing another file or normalizing the same input again resets
the local operation counter and does not mutate input diagnostics.

`CreateTable` and `AlterTable` are containers for their ordered body operations.
A create's `columns` list is a projection of its `AddColumn` children, referencing
the same immutable definitions. Future replay must consume the body once, not
apply both representations. Index/FK payloads will be introduced with M4.

`shouldRun()` remains presence-only detection. All operations, including nested
ones and unresolved effects, retain conditional execution when the parser marks
the migration conditional. Normalization preserves the parser's single
`SHOULD_RUN_DETECTED` diagnostic without evaluating the predicate or adding another.
Generic branches/loops and opaque callback effects stay unresolved; they do not
become supported conditional replay.

Unresolved operations retain their source text and distinguish:

- known table and connection: `attributed`;
- known table with unknown connection: `name_scoped`;
- unknown table: `unattributable`, retaining any known connection.

Known rename endpoints are retained through `table` and `relatedTables`, even
when routing or the other endpoint is unresolved. A footprint with an unknown
table or connection uses `UNATTRIBUTABLE_OPERATION` as its reason and retains
the specific cause separately. Diagnostics preserve the specific cause and add
the attribution warning only when needed.

Raw SQL produces `RAW_SQL_DETECTED` and `UNATTRIBUTABLE_OPERATION`; it is never
interpreted. Unknown facade macros, including `createIfNotExists`, ignore apparent
table arguments for footprint purposes and never fabricate `CreateTable`.
Unknown Blueprint calls remain table-attributed. An unsupported fluent chain is
retained whole, preventing `change()` or another unknown modifier from silently
turning into an ordinary column addition. Modifier failures use the modifier's
own source line while retaining the full chain text.

Every `parsed=false` input emits exactly one whole-file unknown marker at
`[originalMigrationOrdinal, 0]`, with unknown table/connection, original root
warning code, and available nullable line. Partial calls are discarded, and no
duplicate generic attribution warning is added. A malformed custom-parser DTO
without a root warning receives one `MIGRATION_PARSE_FAILURE` warning rather than
vanishing. A verified empty `up()` remains a successful no-op.

## Verification

The full local suite passes **473 tests / 2,234 assertions**, with the same two
Unix-only discovery/source-reader regressions skipped on Windows. Reviewed JSON
snapshots pin basic operations and uncertainty. Focused cases cover all basic
types/modifiers, lifecycle changes, connection routing and both rename endpoints,
conditional provenance, root failure preservation, deterministic positions,
named/invalid argument shapes, dynamic values, macros, SQL, and non-execution.

Two new architecture checks keep normalization independent of AST/source IO and
operation DTOs independent of framework/pipeline services. The existing global
database/code-execution restrictions remain in place.

The compatibility matrix uses the frozen, previously verified dependency graphs,
with unchanged manifest constraints. Each row runs installation, strict Composer
validation, platform checks, the complete tests, Pint, and maximum-level
PHPStan/Larastan, with zero errors, baselines, or ignored findings. Matrix source
fingerprints include the snapshot fixtures. The source remained unchanged
throughout, and the original lockfile and installed dependency selection were
restored after all eight combinations completed:

| PHP | Selection | Laravel | Testbench | Result |
|---|---|---|---|---|
| 8.3.30 | Lowest | 12.69.0 | 10.0.0 | PASS |
| 8.3.30 | Highest | 12.70.0 | 10.12.0 | PASS |
| 8.3.30 | Lowest | 13.30.0 | 11.0.0 | PASS |
| 8.3.30 | Highest | 13.37.0 | 11.3.0 | PASS |
| 8.4.26 | Lowest | 12.69.0 | 10.0.0 | PASS |
| 8.4.26 | Highest | 12.70.0 | 10.12.0 | PASS |
| 8.4.26 | Lowest | 13.30.0 | 11.0.0 | PASS |
| 8.4.26 | Highest | 13.37.0 | 11.3.0 | PASS |

An independent production installation without development dependencies passes
platform checks and both repository runtime smoke scripts with exact
**php-parser 5.0.0**, Laravel 12.70.0, and PHP **8.3.30 / 8.4.26**. All 61 package
snapshot files match the workspace and installed package. The new normalization
smoke covers typed columns, conditional connection routing, advanced-chain
uncertainty, ordered syntax-failure markers, and migration non-execution. The
existing production-floor CI job now runs both smoke scripts.

Ignored evidence is recorded under `.cache/verification/m3/` and its `floor/`
subdirectory. `final-verification.json` records source/fixture hash consistency,
dependency restoration, production-floor package consistency, and unchanged
approved documents/manifest. These are local Windows results; GitHub-hosted M3 CI and the two
Unix-only regressions remain unverified until the changes are pushed and CI runs.
No new hosted-CI claim is made for the prior M2 checkpoint.

## Current limits and next checkpoint

Commands retain their existing discovery-only message and exit behavior; there
is no working schema reconstruction or final command JSON envelope yet. Indexes,
foreign keys, additional column types, `change()`, dynamic defaults, and partial
modifiers remain unresolved at this checkpoint. Their full/reduced normalization
is M4. `Fidelity::Reduced` and the registry classification are available, but M3
does not pretend those pending handlers already emit reduced-fidelity elements.

The operation snapshots are an internal DTO contract, not the final public JSON
contract. Raw and normalized PHP strings retain original bytes, including binary
literal data; arbitrary non-UTF-8 output still needs an explicit renderer encoding
policy in M7. No silent transcoding or replacement was introduced.

Whole-file completeness caps, live-window uncertainty, connection-partition replay,
existence/name certainty, conflict handling, and history/trace results remain M5+
work. M3 provides the provenance and markers they will consume.

No framework constraints, runtime dependencies, approved specification documents,
or historical handoffs were changed. No migration execution, database access,
production changes, package publication, commit, or push was performed. The next
implementation scope is M4: index/FK operations, `change()`, and reduced-fidelity
capture of the additional column types and modifiers.
