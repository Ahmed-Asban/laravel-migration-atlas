# Laravel Migration Atlas — Implementation Plan

| | |
|---|---|
| **Phase** | 1 — Step 2: Architecture & MVP Specification |
| **Status** | **Revision 2** — final architecture corrections applied; awaiting human approval. **No implementation has started. M0 is not authorized.** |
| **Related docs** | [mvp-spec.md](mvp-spec.md) · [architecture.md](architecture.md) |

This plan turns the spec into small, verifiable milestones. Every milestone ends with working, tested code and a green quality gate. Nothing later in the queue is needed to justify anything earlier.

**Revision 1 changes** (from review round 1): milestones updated for global uncertainty (live-window rule), connection partitioning, `shouldRun()` detection, schema-dump honesty, parser resource safeguards, fidelity stamping, and lineage rules. The five-stage architecture and the milestone graph are unchanged — corrections slot into the existing milestones (mostly M2, M5, M6, M7, M8).

**Revision 2 corrections:** existing milestones now cover ordered unknown-effects markers for skipped files, migration-level connection precedence, uncertain conditional create/drop/rename identities, explicit existence/name certainty and consistent diagnostics. AC-23…AC-26 extend the acceptance contract; earlier criteria are tightened without expanding the product. These are planned tests, not executed implementation tests. Stop at documentation approval before M0.

---

## 1. Approach and sequencing

- **Bottom-up through the pipeline:** discovery → parsing → normalization → reconstruction → history/trace → CLI. Each layer consumes the previous layer's DTOs, so layers can be built and tested with fixtures before any command exists.
- **Test-first per milestone:** the acceptance criteria (mvp-spec §9, AC-1…AC-26) are distributed across milestones; each milestone's test list is its completion contract.
- **Model assignment** (per project instruction):
  - **GLM-5.3** — architecture, complex code, and reviews: anything involving AST edge cases, replay semantics (uncertainty, lineages, partitions), security-sensitive behavior, and every milestone's final review.
  - **GLM-5.3 Flash** — routine implementation and tests: mechanical DTOs, straightforward normalizers, fixtures, renderer formatting, and test scaffolding.
- **Quality gates run continuously** (§4), not at the end.

## 2. Milestone dependency graph

```
M0 scaffolding
 └─ M1 discovery
     └─ M2 parsing core ──► M3 table-op + Tier-1 column normalization ──► M5 reconstruction ──► M6 history & trace ──┐
                           └─► M4 index/FK/change + Tier-2 normalization ──────────────┘                            │
                                                                                                    M7 CLI + renderers
                                                                                                        │
                                                                                                    M8 hardening & docs
```

M3 and M4 can run in parallel after M2. M7 needs M5 + M6. M8 last. Unchanged from the initial plan.

---

## 3. Milestones

### M0 — Package scaffolding

| | |
|---|---|
| **Recommended model** | **GLM-5.3** (sets conventions everything else inherits) |
| **Depends on** | — |

**Scope**
- `composer.json`: PHP `^8.3`; `nikic/php-parser: ^5.0`; `illuminate/console` + `illuminate/support`: `^12.0\|^13.0`; **PSR-4 `"MigrationAtlas\\": "src/"` with all classes directly under `src/`** (no `src/MigrationAtlas/` subdirectory — mapping and tree cannot drift); auto-discovery (`extra.laravel.providers`); MIT license.
- `MigrationAtlasServiceProvider` with command registration + publishable `config/migration-atlas.php`: `paths` (default `database_path('migrations')`) and `max_file_size` (default 1 MiB, D18).
- Tooling: Pest + Orchestra Testbench bootstrap (`tests/TestCase.php`), Pint (Laravel preset), Larastan/PHPStan config at the strictest level that passes, GitHub Actions matrix (PHP 8.3/8.4 × Laravel 12/13).
- `README.md` stub, `LICENSE`, `.gitignore`, `.editorconfig`.

**Tests**
- Provider boots in Testbench; config defaults merge (incl. `max_file_size`); all three command signatures are registered (dummy behavior acceptable at this stage).

**Completion criteria**
- `composer test`, `composer lint` (Pint), `composer analyse` (Larastan) green on the full CI matrix. A fresh Laravel app with `composer require` sees the commands registered.

---

### M1 — Migration discovery

| | |
|---|---|
| **Recommended model** | **GLM-5.3 Flash** (mechanical, well-specified) |
| **Depends on** | M0 |

**Scope**
- `MigrationFinder` interface + `FilesystemMigrationFinder`, `MigrationFile`, `MigrationList`, `DiscoveryOptions`.
- Discovery semantics: per configured path, glob `*_*.php` (non-recursive); deduplicate by migration basename with **last configured path winning**, matching Migrator's keyBy behavior; sort basenames lexically (not directory paths). Preserve configured path precedence, assign stable 1-based ordinals, and retain entries even if later reading/parsing fails. NON_STANDARD_FILENAME warns when timestamp prefix is invalid (date null). Discovery/path enumeration failure is a command error, not an empty list.
- `--path` override plumbing down to `DiscoveryOptions`.

**Tests**
- Basename ordering across different directories, last-path duplicate precedence, nonstandard names and stable ordinals; identical output across shuffled directory listings (AC-8 groundwork).
- Custom paths, multiple paths, duplicate names across paths, empty paths (`NO_MIGRATIONS_FOUND` → exit-1 diagnostic later).
- Non-standard filename warning.

**Completion criteria**
- All discovery tests green; `MigrationList` is a stable, ordered, deduplicated DTO accepted by M2.

---

### M2 — AST parsing core *(highest-risk milestone)*

| | |
|---|---|
| **Recommended model** | **GLM-5.3** (AST edge cases and safety guarantees are the hard part) |
| **Depends on** | M1 |

**Scope**
- `PhpParserMigrationParser`: read file as **text** → php-parser v5 (`ParserFactory` newest-supported, `NameResolver` visitor for `use` resolution). No file is ever included/executed.
- Resource/read safeguards (D18/D20): FILE_TOO_LARGE for the size cap, MIGRATION_SYNTAX_ERROR for syntax, MIGRATION_PARSE_FAILURE for recoverable non-syntax parse failures, MIGRATION_SOURCE_UNREADABLE for a discovered-file read/stat failure. Return parsed=false with original file/ordinal/root code and no partial calls; normalization inserts the marker. No fatal-OOM recovery promise.
- `UpMethodLocator`: locate migration class (named `class X extends Migration` **and** `return new class extends Migration`), find `up()` method node. **Detect `shouldRun()`** and flag the file's operations conditional (D17). Missing class / missing `up()` → diagnostics. `down()` is never visited (D3).
- `SchemaCallExtractor`: `Schema::…` static calls; `Schema::connection('lit')->…` wrappers (connection recorded) vs `Schema::connection($dyn)->…` (connection unresolvable); `DB::statement`/`DB::unprepared`/`Schema::getConnection()->…` → raw-SQL detection; calls inside `if`/`foreach`/`while`/`try` → unresolved-region flagging (D13).
- `BlueprintCallAnalyzer`: closure parameter-name binding (works for `$table`, `$bp`, arrow fns); fluent chain destructuring → raw call model `{rootCall, modifiers[], line}`; every node carries start line.
- `ExpressionEvaluator`: static literals only (string/int/float/negative/bool/null/array-of-literals, `X::class` when resolvable); everything else returns `Unresolvable`.

**Tests**
- Named and anonymous migration classes parse identically (AC-9).
- Fixture with top-level side effects + throwing `up()` analyzed without execution (AC-12) — the never-executed proof.
- Syntax-error file does not abort the run; parsed=false, root warning, original ordinal, no partial extracted calls (AC-10).
- Oversized source retains metadata/nullable line; parsed=false, FILE_TOO_LARGE; remaining files analyzed (AC-19).
- Inject non-syntax parser failure and unreadable/disappearing discovered file via test double; stable root codes/nullable lines, continuation, discovery failures distinguished (AC-23). A verified empty up() is a successful no-op, not a failed parse.
- `shouldRun()` fixture flagged conditional; `SHOULD_RUN_DETECTED` emitted (AC-17 groundwork).
- Source locations (file:line) correct for calls and modifiers.
- Connection-wrapped calls (literal and dynamic) and arrow-fn closures extracted correctly.
- Literal/absent/null/falsy migration-level connection declarations on named/anonymous classes; property writes, getConnection overrides and custom inheritance detected as unresolved metadata; explicit literal wrappers remain independently routable (AC-24 groundwork). No runtime/config/env resolution.

**Completion criteria**
- Raw call model carries provenance, connection context, conditional/parsed flags and root diagnostics; failed files cannot disappear as an empty successful call list. Zero include/eval anywhere in src/ (architecture test).

---

### M3 — Normalization: table ops + Tier-1 columns/modifiers

| | |
|---|---|
| **Recommended model** | **GLM-5.3 Flash** (mechanical mapping), **GLM-5.3** review of the matrix encoding |
| **Depends on** | M2 |

**Scope**
- `SupportMatrix` as a single data-driven registry mirroring mvp-spec §5 (method → category, tier, normalizer).
- `OperationNormalizer`: raw calls → `CreateTable`, `AlterTable`, `DropTable`, `RenameTable`, `AddColumn`, `DropColumn`, `RenameColumn` + `ColumnDefinitionDto` for Tier-1 types (`id`, `string`, `integer`, `boolean`, `text`, `timestamps`) and Tier-1 modifiers (`nullable`, static `default`). Every operation stamped with its connection partition key (`default` or literal name) and `ExecutionCertainty`.
- **Fidelity stamping (D6):** SUPPORTED constructs → `FULL`; PARTIALLY SUPPORTED → `REDUCED` (on `ColumnDefinitionDto` and index/FK payloads).
- **Attribution on `UnresolvedOperation` (D12):** table known/unknown and connection known/unknown, distinguishing attributed / name-scoped / unattributable cases; reason codes `UNRESOLVED_DYNAMIC_EXPRESSION` vs `UNATTRIBUTABLE_OPERATION`.
- **Skipped files (D20):** parsed=false emits exactly one wholeFileUnknown marker at [migrationOrdinal,0], unknown table/connection, root diagnostic code and nullable line; discard partial AST output and do not emit a duplicate generic warning (AC-10/19/23).
- **Connection precedence (D15):** literal per-call wrapper overrides migration declaration; unresolved dependent routing emits unresolved operations, never silently default; known rename endpoints both covered (AC-24).
- **Custom facade macros:** createIfNotExists is not standard support or a create alias. Unknown macros emit UNRESOLVED_UNKNOWN_METHOD + unattributable unresolved operations, never execute registrations (AC-26).
- `describe()` summaries used later by history rendering.

**Tests**
- Snapshot tests: fixture file → expected `Operation[]` JSON (operations are serializable by design), asserting fidelity stamps, connection keys, and attribution fields.
- One test per matrix row classified SUPPORTED/PARTIAL in §5.1–5.4; unknown methods produce unresolved ops (AC-6 groundwork).

**Completion criteria**
- Every Tier-1 row of the matrix is normalized exactly as specified; nothing unrecognized is silently dropped; fidelity and attribution are never absent.

---

### M4 — Normalization: indexes, foreign keys, `change()`, Tier-2 capture

| | |
|---|---|
| **Recommended model** | **GLM-5.3 Flash** (fluent-chain mapping is well-specified), **GLM-5.3** review of FK chain resolution |
| **Depends on** | M2 (parallel with M3) |

**Scope**
- Index ops: `primary/unique/index/fullText/spatialIndex` (+ drops), default naming `{table}_{columns}_{type}`, composite arrays, custom names.
- FK ops: `foreign()->references()->on()->onDelete()->onUpdate()` chains; `foreignId()->constrained()` family (`cascadeOnDelete`, `restrictOnDelete`, `nullOnDelete`, `cascadeOnUpdate`); `dropForeign` by name or columns; `foreignIdFor` with `::class` resolution + convention table inference (else unresolved).
- `ChangeColumn` (declared modifiers only, D14) — stamped fidelity `REDUCED`.
- Tier-2 generic type capture (raw type + args) — stamped fidelity `REDUCED`; `softDeletes`/`rememberToken`/`timestampsTz` known expansions; dynamic-default markers (`useCurrent`, `now()`, `DB::raw`) — `REDUCED`.

**Tests**
- Snapshot tests per construct (asserting `REDUCED` fidelity where applicable); composite index naming; FK chain variants; `change()` modifier merging data; Tier-2 rows of the matrix.

**Completion criteria**
- Full support matrix §5 covered by normalization snapshots; FK/index naming matches Laravel conventions; every PARTIALLY SUPPORTED construct carries `REDUCED`.

---

### M5 — Schema reconstruction

| | |
|---|---|
| **Recommended model** | **GLM-5.3** (core state machine: partitions, uncertainty, lineages, and conflicts are the subtle part) |
| **Depends on** | M3 (M4 operations integrate as they land) |

**Scope**
- Immutable state model: partition-aware TableRegistry with lineage/name index; TableState/ColumnState, TableStatus (completeness only), Existence (present/absent/unknown), possible names/name certainty, Origin, FidelityState and IncompletenessReason (architecture §7.1). No general branch engine.
- `SchemaReconstructor.replay()`: functional fold over the operation stream implementing every rule of architecture §7.3, specifically:
  - **Connection routing** (D15): operations apply only within their partition; partitions never merge.
  - **Attribution-driven uncertainty** (D12): attributed/name-scoped ops affect compatible lineages (including possible names/tombstones); unknown-table ops use the live-window rule; ledger retains unknown targets even if none matched.
  - **Whole-file cap (D20):** skipped_migration ledger entries cap every lineage/result/partition incomplete with unknown existence/name, including earlier tombstones/later creates/recreated lineages. Apply before identity lookup; markers never count as applied schema changes.
  - **Conditional operations** (D17): retain source intent/events and candidate values; create is possibly present, drop retains pre-state (no definitive droppedBy), rename retains both possible names. Conditional reasons survive later replay. Column lifecycle follows the same rules (AC-25).
  - **Conflicts** (D19): `RENAME_TARGET_CONFLICT` (rename not applied, both tables `incomplete`), duplicate create/column, alter-before-create phantoms (`origin: UNKNOWN`).
  - **Lineages**: certain create → new lineage; certain rename → alias chain; certain drop → absent/tombstone. Conditional drop/rename never erases a possible identity; later create preserves competing candidates and conflict reasons.
- Fidelity propagation: element fidelity from operations (sticky per element); table fidelity = any element reduced.

**Tests**
- Basic create → final state (AC-1).
- Multiple migrations altering one table → merged final state (AC-2).
- Column add/drop replay; dropped columns absent from state, present in events (AC-3).
- Duplicate create / duplicate column → diagnostics + `INCOMPLETE`, no crash (AC-5).
- **Live-window uncertainty (AC-6/7):** unknown-target op at P marks present/possible tables incomplete, existence/name unknown; earlier tombstones possibly recreated. A later first-create remains complete if no skip/conflict applies.
- **Whole-file unknown effects (AC-10/19/23):** one marker caps earlier/later/recreated lineages across partitions; analyzed/skipped counters reconcile; empty up() produces no cap.
- **Connection isolation/routing (AC-16/24):** literal migration property and wrapper precedence, dynamic routing never default, same-name/rename-endpoint uncertainty across partitions.
- **Conditional ops (AC-17/25):** conditional create/drop/dropIfExists/rename + column equivalents preserve possible existence/names; later operations retain reasons; competing identities survive drop/recreate or rename/name reuse. No definite absence fabricated.
- **Identity rules (AC-21 groundwork):** drop → re-create → new lineage, old tombstoned; rename onto live name → `RENAME_TARGET_CONFLICT`, not applied, both `incomplete`; two live lineages sharing a name detectable for `AMBIGUOUS_TABLE_IDENTITY`.
- Alter-before-create → phantom table with `origin: UNKNOWN` + `ALTER_UNKNOWN_TABLE` (AC-18 groundwork).

**Completion criteria**
- The replay rule table in architecture §7.3 is fully implemented and each row has a passing test; reconstruction is pure (no I/O, no parser knowledge).

---

### M6 — History and column tracing

| | |
|---|---|
| **Recommended model** | **GLM-5.3 Flash** (event-log queries), **GLM-5.3** for the lineage-resolution review |
| **Depends on** | M5 |

**Scope**
- HistoryService: lineage timelines per architecture §7.4. Resolve current/former/possible names; one present/unknown candidate resolves, multiple yield AMBIGUOUS_TABLE_IDENTITY with candidates (1). Certain live wins only over certain tombstones. Only absent → latest tombstone, preserving incomplete reasons. No match → caveated TABLE_NOT_FOUND when applicable. Apply skip cap before lookup.
- `ColumnTraceResolver`: forward identity resolution through rename chains within a table lineage; both-direction lookup (`trace users.user_email` finds today's `users.email`); tombstone termination with dropped-by provenance.
- Inspect/History/Trace result assembly: ledger/counters, completeness, existence/possible names/name certainty, reasons, diagnostics; conditional event intent, uncertain trace exists=null. Only complete+certain absence gets status=dropped; incomplete takes precedence over a tombstone. Error envelopes preserve uncertainty/counters and do not fabricate a data payload (mvp-spec §6).

**Tests**
- Column rename trace end-to-end (AC-4): add → change → rename → (optional) drop, with provenance.
- Trace by former name resolves forward; trace by current name shows original add.
- Table history lists every touching migration chronologically with file:line; conditional events flagged (AC-17).
- **Drop/recreate history (AC-21):** both lineages rendered, clearly separated; live lineage primary.
- **Dropped-table inspect payload (AC-22 groundwork):** last-known state + `dropped_by`.
- Conditional create/drop/rename lookup by every possible name; later name reuse keeps candidates (AC-25); skipped-file cap is never lost through lookup (AC-10/19/23). Conditional drop has no definitive dropped_by; incomplete certain tombstones stay incomplete (AC-22).
- Not-found handling (`TABLE_NOT_FOUND`, `COLUMN_NOT_FOUND`, `AMBIGUOUS_TABLE_IDENTITY`) as result-level errors.

**Completion criteria**
- All three result DTOs are produced correctly against the fixture corpus, including uncertainty, conditional, lineage, and dropped-table data; presentation layer can consume them without domain knowledge.

---

### M7 — CLI commands and renderers

| | |
|---|---|
| **Recommended model** | **GLM-5.3 Flash** (thin commands + formatting), **GLM-5.3** review of exit-code and banner semantics |
| **Depends on** | M5 + M6 |

**Scope**
- `SchemaInspectCommand`, `SchemaHistoryCommand`, `SchemaTraceCommand` (`table.column` parsing for trace), shared option handling (`--json`, `--connection=`, `--path=*`, `--strict`).
- `TextRenderer` (stable, diff-friendly layout per mvp-spec §4.3–4.5): status banner with reasons split by origin (direct/global/conditional), fidelity footnotes (`†`), conditional event markers (`[conditional]`), `DROPPED` banner, partition hints on `TABLE_NOT_FOUND`.
- JsonRenderer per mvp-spec §6: connection, always-present ledger/counts, analyzed/skipped counters, existence, observed possible names/name certainty, reasons, fidelity and conditional events. Nullable source lines; deterministic, no timestamps. Text labels candidate/last-known definitions and uncertain current names.
- ExitCode: errors (including caveated not-found and conditional identity ambiguity) → 1, overriding strict; otherwise incomplete + strict → 2 (including skips and conditional destruction); complete certain drops → 0 including strict. Reduced fidelity alone never affects codes.

**Tests** (feature tests through Testbench console kernel)
- All three commands against the fixture corpus: text and JSON (AC-13 snapshots, incl. new contract fields).
- Exit codes incl. `--strict` on incomplete fixtures and global-uncertainty fixtures (AC-14).
- `--connection` partition selection + not-found partition hint (AC-16).
- Dropped-table inspect through the CLI (AC-22) and conditional history rendering (AC-17).
- Skip/conditional/connection/macro cases through every relevant command in text and JSON: no falsely complete, dropped, absent or named result; source reasons and error precedence pinned (AC-10/19/23…26).
- Fidelity footnotes and `"fidelity": "reduced"` in JSON (AC-20).
- JSON byte-identical across two runs with shuffled input order (AC-8).
- Auto-discovery in fresh Laravel 12 and 13 apps (AC-15).
- **Zero-DB proof (AC-11):** full pipeline succeeds with an unusable/misconfigured database; no PDO instance constructed.
- Full acceptance sweep AC-1…AC-4 through the CLI surface.

**Completion criteria**
- Every acceptance criterion in mvp-spec §9 passes through the actual commands on the CI matrix.

---

### M8 — Hardening, guard clauses, and documentation

| | |
|---|---|
| **Recommended model** | **GLM-5.3** (edge cases, review pass, docs) |
| **Depends on** | M7 |

**Scope**
- Narrow guard-clause recognition: exact `if (Schema::hasTable('<literal>')) { return; }` shape is semantically ignored (D13); all other control flow stays unresolved.
- **Schema-dump honesty (mvp-spec §8.2):** detect `database/schema/*.sql` per connection → `SCHEMA_DUMP_DETECTED`; caveated `TABLE_NOT_FOUND` messages when a dump (or unattributable operations) exists.
- Diagnostic catalog completeness: every code in mvp-spec §7 has a fixture + assertion (executable documentation).
- Support-matrix conformance suite: automated check that every SUPPORTED/PARTIAL row has a normalization snapshot test **with the correct fidelity stamp**.
- README (usage, support matrix, limitations — expected vs actual schema, global uncertainty, fidelity semantics), CHANGELOG, and final security review (no include/eval/exec/proc/network; read-only FS; resource-guard behavior documented).

**Tests**
- **AC-6 end-to-end:** dynamic table name → `UNATTRIBUTABLE_OPERATION`, live-window `incomplete` statuses, `--strict` exit 2, post-window tables `complete`.
- **AC-7 end-to-end:** raw SQL → `RAW_SQL_DETECTED` + `UNATTRIBUTABLE_OPERATION` + caveated `TABLE_NOT_FOUND`.
- **AC-18 end-to-end:** dump fixture → caveated not-found (exit 1) and unknown-origin phantom tables.
- Guard-clause fixture: guarded create is still reconstructed; generic conditional stays unresolved.
- AC-23/24/25/26 end-to-end: skip failures and counters, migration connection precedence/unresolved routing, conditional destructive identity retention and strict exits, unsupported facade macros/one shouldRun diagnostic.
- Full AC-1…AC-26 sweep as the milestone's exit gate.

**Completion criteria**
- All acceptance criteria green; docs complete; security review sign-off.

---

## 4. Cross-cutting quality gates (every milestone, every PR)

| Gate | Tool | Standard |
|---|---|---|
| Formatting | Laravel Pint | Zero diffs (`composer lint`). |
| Static analysis | PHPStan + Larastan | Start at level 8, raise to max by M5; zero ignores without a linked decision. |
| Tests | Pest + Orchestra Testbench | New code covered; failing-(pathological) fixtures required where specified. |
| Architecture test | Pest arch plugin | `src/` contains no `eval`/`include`/`require`/`exec`/`proc_*`; commands contain no domain logic; renderers don't call `Atlas`. |
| CI | GitHub Actions | Matrix: PHP 8.3/8.4 × Laravel 12/13 × lowest/highest deps. |

---

## 5. Test strategy summary

Mapped to the required coverage list (mvp-spec §9 defines the automated acceptance criteria):

| Required coverage | Tests | Milestone |
|---|---|---|
| Basic table creation | AC-1 | M5/M7 |
| Multiple migrations modifying one table | AC-2 | M5/M7 |
| Column additions and removals | AC-3 | M5/M7 |
| Column rename history | AC-4 | M6/M7 |
| Duplicate or conflicting operations | AC-5 | M5/M7 |
| Unsupported dynamic expressions | AC-6 | M2/M3/M5/M8 |
| Raw SQL warnings | AC-7 | M2/M5/M8 |
| Deterministic ordering | AC-8 | M1/M7 |
| Anonymous migration classes | AC-9 | M2 |
| Syntax errors + unknown whole-file effects | AC-10 | M2/M3/M5/M6/M7 |
| Zero database interaction | AC-11 | M7 |
| Proof that migration files are never executed | AC-12 | M2 |
| Global uncertainty (unattributable ops, live-window) | AC-6/AC-7 (assertions on statuses, ledger, caveats) | M5/M7/M8 |
| Connection isolation | AC-16 | M5/M7 |
| Conditional migrations (`shouldRun()`) | AC-17 | M2/M5/M6/M7 |
| Schema-dump honesty | AC-18 | M5(phantoms)/M7/M8 |
| File-size guard + completeness/counters | AC-19 | M2/M3/M5/M7 |
| Fidelity marking | AC-20 | M3/M4/M7 |
| Rename/drop/recreate identity ambiguity + conflicts | AC-21 | M5/M6 |
| Dropped-table inspect | AC-22 | M6/M7 |
| Parser/read failures, all files skipped, discovery failures | AC-23 | M1/M2/M3/M5/M6/M7/M8 |
| Migration connection declaration/explicit override/dynamic routing | AC-24 | M2/M3/M5/M6/M7/M8 |
| Conditional destructive operations, possible names/existence, ambiguity | AC-25 | M5/M6/M7/M8 |
| Unsupported facade macros + diagnostic consistency | AC-26 | M3/M5/M7/M8 |

Additional layers: normalization snapshot tests (M3/M4) pin the operation stream per fixture — **including fidelity stamps, connection keys, and attribution**; architecture tests pin security/boundary rules; JSON contract snapshots pin the envelope.

Fixture corpus lives in `tests/Fixtures/migrations/` — one curated scenario per acceptance criterion, never real-world customer code.

---

## 6. Risk register

| # | Risk | L | I | Mitigation |
|---|---|---|---|---|
| R1 | php-parser v5 edge cases on unusual-but-valid migration syntax | M | M | Recoverable parse failures produce root diagnostics plus whole-file uncertainty markers/caps (AC-10/23). Fatal OOM/process termination remains outside recovery guarantees. |
| R2 | Support-matrix scope creep (the Blueprint modifier zoo) | H | M | The matrix is the contract; unknowns are unresolved by design; matrix extension is a deliberate, reviewed act. |
| R3 | Execution-order mismatch confuses users (expected ≠ actual) | M | H | Prominent "expected schema" labeling in every output; documented limitation; post-MVP `--from-database`. |
| R4 | **Conservative uncertainty noise:** skipped source caps every result; unresolved calls may affect many tables | M | M | Deliberate D12/D20 distinction: analyzed calls keep attribution/live-window scope, wholly skipped source keeps the broader cap. Show precise source/root reasons, counters and opt-in strict exits; never hide incompleteness to reduce noise. |
| R5 | Rename/drop/lineage state-machine bugs, especially conditional name reuse | M | H | Pure replay with explicit existence/name certainty; every §7.3/7.4 rule has a test. AC-25 covers conditional drop/recreate, rename/name reuse, column equivalents and ambiguity; no branch enumeration or guessed winner. |
| R6 | Testbench/Laravel 13 alignment churn | M | M | CI matrix from M0; version bounds `^12\|^13`; pin early. |
| R7 | Semantic drift from Laravel Blueprint behavior | L | M | Conformance suite (M8) pins every matrix row incl. fidelity; matrix changes require tests. |
| R8 | Performance/memory on very large or pathological migration corpora | L | M | `max_file_size` cap (D18); documented `memory_limit` guidance; operations serializable for post-MVP caching; residual risk documented, not hidden. |
| R9 | Guard-clause recognition too clever / false safety | L | M | Narrow exact-shape match only (D13); any deviation falls back to unresolved. |
| R10 | **Fidelity/status confusion** — users misread `reduced` fidelity as incompleteness or vice versa | M | L | Distinct, always-visible renderings (`†` footnote vs `INCOMPLETE` banner); README explains the three axes with examples. |
| R11 | **Partition edge cases** (migration defaults, dynamic routing, physical aliases) | M | M | AC-16/24 pin declaration/wrapper precedence and unresolved routing; no config/runtime resolution. Symbolic default and named partitions may alias physically, which remains an explicit MVP limitation. |

---

## 7. Definition of Done (Phase 1 → Phase 2 handoff)

- All milestones M0–M8 complete; AC-1…AC-26 automated and green on PHP 8.3/8.4 × Laravel 12/13.
- Quality gates (§4) green with zero unresolved ignores.
- README + docs reflect the shipped behavior; support matrix published (with tiers, fidelity, and uncertainty semantics).
- Security constraints (architecture §10) verified by architecture tests and review — including resource-guard behavior.
- No database interaction anywhere in the analysis path; never-executed proof test in the default suite.

---

## 8. Open questions (unresolved — need decisions before/at implementation)

| # | Question | Proposed default | Decide by |
|---|---|---|---|
| Q1 | Final vendor namespace / Packagist name (`migration-atlas/laravel`? personal vendor?) | Placeholder `laravel-migration-atlas`, namespace `MigrationAtlas\` | M0 |
| Q2 | Recursive subdirectory scanning: follow Laravel's migrator (non-recursive per path) or add a config flag? | Match Laravel; config `scan_subdirectories => false` for later | M1 |
| Q3 | Should JSON ever include a `generated_at` field (breaks determinism)? | No — omit; add behind a flag only if requested | M7 |
| Q4 | Full multi-connection support beyond minimal partitioning (physical default resolution, cross-connection FK validation, per-connection dumps) | **Resolved for MVP:** minimal partitioning ships (D15); the rest stays post-MVP | Post-MVP |
| Q5 | `--from-database` cross-check: read `migrations` table for true execution order + diff expected/actual? | Post-MVP, opt-in, clearly separated from static analysis | Post-MVP |
| Q6 | Table prefix handling (`database.connections.*.prefix`) | Ignore prefixes in MVP; document | M5 |
| Q7 | Testbench Core version pinning for Laravel 13 in the CI matrix | `^10.0\|^11.0` dual constraint, matrix-verified | M0 |
| Q8 | Auto-detecting `loadMigrationsFrom()` paths of installed packages | Config-only in MVP (documented); revisit post-MVP | M1 |
| Q9 | Criteria for promoting Tier-2 (PARTIALLY SUPPORTED) column types to Tier-1 | Real-world corpus evidence + normalization tests | Post-MVP |
| Q10 | Default value of `max_file_size` (currently proposed 1 MiB) | 1 MiB; validate against real-world corpora in M2 | M2 |
| Q11 | Should reduced fidelity ever affect exit codes? | **No in MVP** (D6: fidelity never affects exit codes); post-MVP option `--strict=fidelity` | Post-MVP |

---

## 9. Final architecture approval gate

Revision 2 is **ready for human architecture approval before M0**: the accepted five stages and milestone graph remain intact; all final findings have specified replay/output/exit behavior and acceptance coverage (AC-1…AC-26). Readiness is a documentation assessment, not evidence of a working parser, passing package tests or a validated dependency matrix.

After approval, M0 must settle Q1's package identity and verify Q7's dependency/version constraints against the actual Laravel/PHP CI targets. Remaining risks are deliberate conservative incomplete/ambiguous output, unparsed dumps/runtime/config effects, physical connection aliases, and parser resource limits. **Stop here and await human approval; do not start M0, install dependencies, commit or push.**
