# Laravel Migration Atlas — Architecture

| | |
|---|---|
| **Phase** | 1 — Step 2: Architecture & MVP Specification |
| **Status** | **Revision 2** — final architecture corrections applied; awaiting human approval |
| **Related docs** | [mvp-spec.md](mvp-spec.md) · [implementation-plan.md](implementation-plan.md) |

This document defines the internal design: components, boundaries, data flow, the operation and schema-state models, error strategy, security constraints, and the reasoning behind every significant decision. All PHP signatures below are **design sketches** for this document only — no implementation exists yet (Phase 1, Step 2 is planning-only).

**Revision 1 changes** (from review round 1): attribution + live-window global uncertainty model replacing the raw-SQL file-scoping heuristic; minimal connection partitioning; `shouldRun()` detection and conditional-operation semantics; lineage-based identity resolution (rename/drop/recreate ambiguity, rename-target conflicts); realistic parser resource safeguards (removed incorrect timeout/streaming claims); PSR-4 mapping aligned with the folder structure; three-axis semantics (support tier / analysis status / fidelity) defined consistently. **The five-stage pipeline is unchanged.**

**Revision 2 corrections:** ordered whole-file unknown-effects markers with a conservative completeness cap; migration-level connection precedence; conditional existence/name preservation; status precedence over tombstones; unsupported custom facade macros and one `SHOULD_RUN_DETECTED` code. Changes remain inside the accepted components; no implementation or M0 work is authorized by this revision.

---

## 1. Design principles

1. **Safety is non-negotiable.** Migration source is untrusted input. The tool never executes, includes, requires, or evals it; it never touches a database.
2. **Honesty over coverage.** Unknown constructs cap completeness (status axis); partial support is always visibly marked (fidelity axis); nothing is guessed. An incomplete answer is marked incomplete; a wrong answer is a bug.
3. **Parse ≠ Reconstruct.** Source analysis (AST → typed operations) is fully decoupled from state reconstruction (operations → table states). Each is testable without the other.
4. **CLI-first, thin presentation.** Commands are dumb: parse input, call the domain service, render a result DTO. All rendering logic lives in swappable renderers.
5. **Determinism everywhere.** Same file set → same order, same operations, same output bytes. No timestamps or environment leakage in output.
6. **Minimal dependencies.** Runtime: `nikic/php-parser` + `illuminate/console` + `illuminate/support` only. Notably **no `illuminate/database`** — Blueprint semantics are encoded in the normalizer's support matrix, not reflected at runtime (D5).
7. **No premature abstraction.** Interfaces exist only where a second implementation or test double is genuinely expected. SOLID through simplicity.

---

## 2. System overview and data flow

```
 artisan command (inspect | history | trace)
        │  table / table.column + options (--path, --connection, --json, --strict)
        ▼
 ┌─────────────────────────────┐
 │  Atlas (domain orchestrator)│
 └─────────────────────────────┘
   1 │                                            result
     ▼
 MigrationFinder ──► MigrationFile[] (deterministic order)      (Discovery)
     │
   2 ▼  per file — PHP source read as text only
 MigrationParser ──► nikic/php-parser AST
     │   ├─ UpMethodLocator          (finds up(), ignores down(); flags shouldRun())
     │   ├─ SchemaCallExtractor      (Schema::…, Schema::connection()…, DB::statement, …)
     │   └─ BlueprintCallAnalyzer    (chains on the closure's $table variable)
     ▼
 OperationNormalizer ──► Operation[]   (typed immutable DTOs + SourceLocation)
     │                                  (unrecognized → UnresolvedOperation w/ attribution)
   3 ▼
 SchemaReconstructor ──► replay: TableRegistry (partition-aware)
     │                     + HistoryEvent[] (append-only log)
     │                     + UncertaintyLedger (unattributable op positions)
     ▼
 HistoryService / ColumnTraceResolver   (lineage-based resolution)
     │
   4 ▼
 InspectResult | HistoryResult | TraceResult   (+ DiagnosticCollection)
     ▼
 Renderer (Text | Json) ──► stdout + exit code
```

The five stages are **discover → parse → normalize → reconstruct → present**. Discovery and parsing read source; normalization and reconstruction operate on data; result composition/history lookup and rendering are presentation. The diagram's numbered arrows group interactions, not an alternative stage count. Stage outputs are serializable value objects for testing and possible future caching.

The existing **UncertaintyLedger** records unresolved table/connection targets and whole-file skip markers with stable positions, kind, reason and source. It drives attribution/live-window rules and the stronger skipped-file cap (§7.2), then rolls up into `meta.analysis.global_uncertainty` (mvp-spec §6). No additional pipeline stage or uncertainty framework is introduced.

---

## 3. Proposed folder structure

Composer PSR-4 mapping: **`"MigrationAtlas\\": "src/"`** — all classes live directly under `src/` in FQCN order (`MigrationAtlas\Atlas` → `src/Atlas.php`). There is deliberately **no `src/MigrationAtlas/` subdirectory**, so the mapping and the tree cannot drift apart.

```
laravel-migration-atlas/
├── composer.json                  # PSR-4: "MigrationAtlas\\" => "src/"
├── LICENSE                        # MIT
├── README.md
├── config/migration-atlas.php     # publishable defaults
├── phpstan.neon.dist              # Larastan
├── pint.json
├── src/
│   ├── MigrationAtlasServiceProvider.php   # auto-discovered (FQCN MigrationAtlas\MigrationAtlasServiceProvider)
│   ├── Atlas.php                           # domain orchestrator (inspect/history/trace)
│   ├── Discovery/
│   │   ├── MigrationFinder.php             # interface
│   │   ├── FilesystemMigrationFinder.php
│   │   ├── MigrationFile.php               # readonly DTO
│   │   ├── MigrationList.php               # ordered, deduplicated
│   │   └── DiscoveryOptions.php            # readonly DTO (paths, …)
│   ├── Parsing/                            # AST layer — never executes code
│   │   ├── MigrationParser.php             # interface
│   │   ├── PhpParserMigrationParser.php    # file → ParsedMigration
│   │   ├── ParsedMigration.php             # calls, connection context, conditional/parsed flags, diagnostics
│   │   ├── UpMethodLocator.php             # up(), shouldRun(), migration connection metadata
│   │   ├── SchemaCallExtractor.php
│   │   ├── BlueprintCallAnalyzer.php       # fluent chain → raw call model
│   │   ├── ExpressionEvaluator.php         # static literal evaluation only
│   │   └── NodeHelpers.php                 # private static AST utilities
│   ├── Normalization/
│   │   ├── OperationNormalizer.php         # raw call model → Operation[]
│   │   └── SupportMatrix.php               # single source of truth: §5 of mvp-spec
│   ├── Operations/                         # immutable DTOs, one per operation
│   │   ├── Operation.php                   # abstract: source(), execution certainty
│   │   ├── SourceLocation.php
│   │   ├── ExecutionCertainty.php          # enum: ALWAYS | CONDITIONAL (shouldRun)
│   │   ├── Fidelity.php                    # enum: FULL | REDUCED
│   │   ├── CreateTable.php / AlterTable.php / DropTable.php / RenameTable.php
│   │   ├── AddColumn.php / DropColumn.php / RenameColumn.php / ChangeColumn.php
│   │   ├── ColumnDefinitionDto.php         # shared column payload (incl. fidelity)
│   │   ├── AddIndex.php / DropIndex.php
│   │   ├── AddForeignKey.php / DropForeignKey.php
│   │   └── UnresolvedOperation.php         # attribution + reason, never dropped
│   ├── Reconstruction/
│   │   ├── SchemaReconstructor.php         # replay Operation[] → TableRegistry
│   │   ├── TableRegistry.php               # partition-aware (connection → tables)
│   │   ├── UncertaintyLedger.php           # unresolved targets + whole-file skip positions
│   │   └── State/
│   │       ├── TableState.php / ColumnState.php
│   │       ├── IndexState.php / ForeignKeyState.php
│   │       ├── TableStatus.php             # enum: COMPLETE | INCOMPLETE (not existence)
│   │       ├── Existence.php               # enum: PRESENT | ABSENT | UNKNOWN
│   │       ├── FidelityState.php           # enum: FULL | REDUCED (per element)
│   │       ├── Origin.php                  # CREATED_HERE | UNKNOWN (alter-before-create)
│   │       └── IncompletenessReason.php    # { origin: direct|global|conditional|conflict, diagnostic }
│   ├── History/
│   │   ├── HistoryEvent.php                # readonly DTO (incl. conditional flag)
│   │   ├── TableHistory.php                # lineage-grouped event queries
│   │   └── ColumnTraceResolver.php         # lineage + rename-chain identity resolution
│   ├── Diagnostics/
│   │   ├── Diagnostic.php                  # readonly DTO
│   │   ├── DiagnosticCode.php              # enum — mvp-spec §7
│   │   ├── DiagnosticSeverity.php          # enum: WARNING | ERROR
│   │   └── DiagnosticCollection.php
│   ├── Cli/
│   │   ├── SchemaInspectCommand.php / SchemaHistoryCommand.php / SchemaTraceCommand.php
│   │   ├── Render/
│   │   │   ├── Renderer.php                # interface: render(result): int
│   │   │   ├── TextRenderer.php            # per-result-type methods
│   │   │   └── JsonRenderer.php
│   │   ├── ExitCode.php                    # enum: SUCCESS=0 FAILURE=1 INCOMPLETE_STRICT=2
│   │   └── AnalysisResult.php              # marker interface for result DTOs
│   └── Support/
       ├── InspectResult.php / HistoryResult.php / TraceResult.php
       └── AnalysisMeta.php                 # status, global uncertainty, files scanned
└── tests/
    ├── TestCase.php                        # Orchestra Testbench + Pest bootstrap
    ├── Unit/  …
    ├── Feature/ …
    └── Fixtures/
        └── migrations/                     # curated corpora per scenario (AC-1…AC-26)
```

---

## 4. Components and responsibilities

| Component | Responsibility | Explicitly **not** responsible for |
|---|---|---|
| `MigrationFinder` | Locate files, deduplicate by migration basename (last configured path wins), sort basenames lexically, and assign stable 1-based migration ordinals. Never remove an entry because a later read/parse failed. | Parsing or inferring actual execution order. |
| `MigrationParser` (`PhpParserMigrationParser`) | Read text, parse AST (php-parser v5 + NameResolver), find migration/up(), detect shouldRun and migration connection metadata. Enforce size/read/parse guards. Return parsed=false + root diagnostic on failure, discard partial calls, retain file/ordinal; other files continue (mvp-spec §8.8). | Runtime evaluation, loading parents/macros/config, or applying schema semantics. |
| `SchemaCallExtractor` | Identify `Schema::…` static calls, `Schema::connection('x')->…` wrappers (connection recorded), `DB::statement`-family raw SQL, and schema calls nested in control flow (flagged as unresolved regions). | Evaluating arguments. |
| `BlueprintCallAnalyzer` | Within each `Schema::create/table` closure: bind the Blueprint variable by **closure parameter name** (not a hardcoded `$table`), destructure fluent chains into (root method, modifiers[]) with line numbers. Support `function (Blueprint $t) {}` and `fn (Blueprint $t) => …`. | Deciding what a method *means*. |
| `ExpressionEvaluator` | Evaluate expressions to static literals only: strings, ints, floats (incl. unary minus), `true/false/null`, arrays of literals, `X::class` when resolvable via file-level `use`. Anything else → `Unresolvable`. | Calling functions, resolving constants other than `true/false/null`. |
| `OperationNormalizer` + `SupportMatrix` | Normalize calls with fidelity, execution certainty and effective connection (§6). parsed=false produces one whole-file UnresolvedOperation, never an empty successful stream. Unknown facade macros (including createIfNotExists) are unattributable unresolved operations, never create aliases. Matrix mirrors mvp-spec §5. | Replaying operations or executing macro registrations. |
| `SchemaReconstructor` | Replay the ordered operation stream against a partition-aware `TableRegistry`: route operations to connection partitions, apply conflict rules (§7.3), maintain lineages, apply the live-window uncertainty rule, record the `UncertaintyLedger`, emit `HistoryEvent`s and diagnostics. | Reading or parsing anything. |
| `HistoryService` / `ColumnTraceResolver` | Query the event log by lineage (resolving former names through rename chains, incl. dead lineages); annotate conditional events. | Mutating state. |
| `Atlas` | The only entry point commands talk to: `inspect()`, `history()`, `trace()` — each taking the connection partition into account. Composes the pipeline via constructor injection. | Formatting output. |
| Commands | Argument/option validation (incl. `--connection`), calling `Atlas`, selecting a renderer, exiting. | Domain logic. |
| Renderers | Turn result DTOs into text or JSON; return exit codes. | Domain logic; they may not call `Atlas`. |

---

## 5. Interfaces and DTOs (design sketches)

```php
// ── Discovery ────────────────────────────────────────────────
interface MigrationFinder {
    /** Deterministically ordered; never executes or includes files. */
    public function find(DiscoveryOptions $options): MigrationList;
}

final readonly class MigrationFile {
    public function __construct(
        public string $absolutePath,
        public string $name,          // basename without .php
        public ?string $date,         // parsed from timestamp prefix, else null
        public int $ordinal,          // stable 1-based position after discovery sort/dedup
    ) {}
}

// ── Parsing ──────────────────────────────────────────────────
interface MigrationParser {
    public function parse(MigrationFile $file): ParsedMigration;
}

final readonly class ParsedMigration {
    public function __construct(
        public MigrationFile $file,
        public list<RawSchemaCall> $calls,        // structural model
        public bool $conditional,                 // file defines shouldRun()
        public ?string $connection,               // migration default: symbolic 'default', literal name, or null if unresolved
        public DiagnosticCollection $diagnostics, // syntax errors, FILE_TOO_LARGE, …
        public bool $parsed,                      // false when body cannot be established; normalizer MUST emit skip marker
    ) {}
}

// ── Operations (immutable, serializable) ─────────────────────
abstract readonly class Operation {
    public function __construct(
        public SourceLocation $source,
        public ExecutionCertainty $execution = ExecutionCertainty::Always, // Conditional when the file defines shouldRun()
    ) {}
    abstract public function describe(): string;   // human summary
}

final readonly class AddColumn extends Operation {
    public function __construct(
        SourceLocation $source,
        ExecutionCertainty $execution,
        public string $table,
        public string $connection,                 // partition key: 'default' | literal name
        public ColumnDefinitionDto $column,        // name, type, modifiers, fidelity
    ) {}
}

final readonly class UnresolvedOperation extends Operation {
    public function __construct(
        SourceLocation $source,
        ExecutionCertainty $execution,
        public ?string $table,        // null ⇒ unknown table; cover both known endpoints of a rename
        public ?string $connection,   // null ⇒ unresolved connection, never a default fallback
        public DiagnosticCode $reason,
        public string $rawDescription, // verbatim source rendering
        public bool $wholeFileUnknown = false, // skipped-file marker; source text need not be available
    ) {}
}

// ── Reconstruction ───────────────────────────────────────────
interface SchemaReconstructor {                   // single impl, interface for testing seams
    public function replay(list<Operation> $operations): ReconstructionResult;
}

// ── Domain orchestrator ──────────────────────────────────────
final class Atlas {
    public function inspect(string $table, ?DiscoveryOptions $options, string $connection = 'default'): InspectResult;
    public function history(string $table, ?DiscoveryOptions $options, string $connection = 'default'): HistoryResult;
    public function trace(string $table, string $column, ?DiscoveryOptions $options, string $connection = 'default'): TraceResult;
}

// ── Presentation ─────────────────────────────────────────────
interface Renderer {
    /** @return int exit code */
    public function render(AnalysisResult $result): int;
}
```

Result DTOs (`InspectResult`, `HistoryResult`, `TraceResult`) are immutable and carry `AnalysisMeta` (incl. the `global_uncertainty` rollup) + `DiagnosticCollection` + command-specific data. They are the **only** thing renderers see — the contract that keeps presentation separated.

`SourceLocation` permits a null line (unreadable/oversized source) and carries stable `position: [migrationOrdinal, operationOrdinal]`. Successful-file operations use AST order, 1-based; a whole-file skip uses operation ordinal 0. No fabricated line 1. Parsed=false is never inferred merely from calls=[]: a verified empty up() is a known no-op. This DTO sketch adds metadata only, not an alternate parser path.

---

## 6. Operation representation

The operation catalog (one immutable DTO each, all carrying `SourceLocation` and `ExecutionCertainty`):

| Operation | Key fields | Produced by |
|---|---|---|
| `CreateTable` | connection, table, columns[], indexes[], foreignKeys[] | `Schema::create` only; custom facade macros are unresolved |
| `AlterTable` | connection, table, sub-operations[] | `Schema::table` |
| `DropTable` | connection, table | `Schema::drop`, `dropIfExists` |
| `RenameTable` | connection, from, to | `Schema::rename` |
| `AddColumn` | connection, table, ColumnDefinitionDto (incl. fidelity) | blueprint column calls |
| `DropColumn` | connection, table, columns[] | `dropColumn` |
| `RenameColumn` | connection, table, from, to | `renameColumn` |
| `ChangeColumn` | connection, table, column, declaredModifiers[] | `…->change()` |
| `AddIndex` / `DropIndex` | connection, table, name?, type, columns[] | `primary/unique/index/fullText/spatialIndex` + drop variants |
| `AddForeignKey` / `DropForeignKey` | connection, table, name?, columns[], referencedTable, referencedColumns, onDelete, onUpdate | `foreign()->…`, `foreignId()->constrained()->…` |
| `UnresolvedOperation` | connection?, table?, reason code, rawDescription, wholeFileUnknown | outside matrix, unresolved routing, or one synthetic marker per skipped migration |

Design rules:

- Operations are **dumb data**. No behavior beyond `describe()` for summaries.
- Operations are **serializable** (`json_encode`-able), enabling future caching and snapshot tests of the normalization layer.
- `ExecutionCertainty` comes from the file: `CONDITIONAL` when the migration defines `shouldRun()` (D17).
- `Fidelity` is stamped at normalization (FULL for SUPPORTED constructs, REDUCED for PARTIALLY SUPPORTED ones) and flows into element state — partial support can never silently imply full fidelity (D6).
- `UnresolvedOperation` is a first-class citizen, not an error: it flows through the same pipeline and drives completeness flags downstream. Its **attribution** (table/connection known?) determines whether uncertainty is scoped or global (§7.2).

**Migration connection context (D15).** Inspect the declaration of protected $connection on named/anonymous migration classes without instantiating them. For a direct Laravel Migration subclass, absent/null/empty-string/'0' means symbolic default; a non-falsy literal string means that partition. Explicit literal Schema::connection('c') takes precedence per call; explicit null/empty-string/'0' uses the migration default. Nonliteral wrappers never fall back to a known declaration. Detect property writes, getConnection overrides and uninspectable/custom inheritance: dependent unqualified calls become UnresolvedOperation with connection=null, while explicit literal wrappers remain independently routable. No full constant folding, inheritance loading, config/env evaluation, database manager or physical-default resolution. Known names use name-scoped uncertainty (include both rename endpoints); unknown names use the live-window rule. --connection selects queried output, never overrides source context. See mvp-spec §8.5 and its framework references.

---

## 7. Schema-state design and replay semantics

### 7.1 State model

```
TableRegistry (partition-aware)
 └─ partitions: map<connectionKey, map<lineageId, TableState>>
     name index: current/former/possible name → list<lineageId>
     connectionKey: 'default' | '<literal connection name>'
                   Partitions never merge (D15).

 TableState
     ├─ status: COMPLETE | INCOMPLETE                      (completeness only)
     ├─ existence: PRESENT | ABSENT | UNKNOWN               (ABSENT = certain tombstone)
     ├─ possibleNames: list<string>                         (observed candidate current names)
     ├─ nameCertain: bool                                  (false after conditional rename/unknown effects)
     ├─ fidelity: FULL | REDUCED                            (any element reduced ⇒ reduced)
     ├─ origin: CREATED_HERE | UNKNOWN                      (alter-before-create)
     ├─ lineageId: int                                      (increments per create; see §7.4)
     ├─ formerNames: list<string>
     ├─ columns: map<currentName, ColumnState>
     │    ├─ type, length, precision/scale, nullable, default,
     │    │   unsigned, autoIncrement, allowedValues, comment
     │    ├─ formerNames: list<string>
     │    ├─ existence, possibleNames, nameCertain           (same uncertainty rules as tables)
     │    ├─ fidelity: FULL | REDUCED
     │    └─ source: SourceLocation                         (last definition site)
     ├─ indexes: list<IndexState>        (name, type, columns, fidelity, source)
     ├─ foreignKeys: list<ForeignKeyState>
     └─ incompletenessReasons: list<IncompletenessReason>   (origin: direct | global | conditional | conflict)

 UncertaintyLedger
 └─ sources: list<{ position: [int,int], source, kind, reason }>
        kind: operation | skipped_migration
        (attribution/live-window + whole-file cap; meta.analysis.global_uncertainty)
```

States are **immutable value objects**; the reconstructor produces new states as it replays (functional fold), which makes replay deterministic and trivially testable.

### 7.2 Attribution, uncertainty, completeness, fidelity

**Attribution model.** Every `UnresolvedOperation` carries what could be statically determined — its table and/or connection:

| Table known? | Connection known? | Classification | Effect |
|---|---|---|---|
| yes | yes | **Attributed** | Matching lineages in that partition become incomplete; uncertainty cannot be lost when the target is absent. |
| yes | no (dynamic) | **Name-scoped unattributable** | Matching current/former/possible names across partitions become incomplete; include both rename endpoints. Ledger/not-found caveat survives even if no known table matched. |
| no | either | **Unattributable** | Live-window rule below + ledger. A known connection may be retained as evidence, but MVP conservatively checks all partitions. |
| unknown whole file | unknown | **Skipped migration** | Stronger run-wide cap below; never treated as an analyzed no-op. |

**Live-window rule for analyzed unknown-target operations.** At position P, mark every present or possibly present table across partitions incomplete, with a global source reason. A tombstoned name may also have been recreated by an unknown operation: retain it as an uncertain candidate, not a guaranteed absence. Arbitrary unknown effects make affected existence/name uncertain; do not invent definitions. A table first created later may remain complete in the expected-replay model, subject to conflict rules and the stronger skip cap. This is not a proof about arbitrary runtime PHP side effects. Known-name/unknown-connection operations use the narrower matching-name scope, including tombstones, with a direct reason; they still appear in the run-level ledger.

**Whole-file skip rule (D20).** parsed=false normalizes to exactly one UnresolvedOperation with wholeFileUnknown=true, table=null, connection=null, root diagnostic reason, and position [migrationOrdinal,0]. Discard any partially extracted calls. Add a skipped_migration ledger entry even if no tables exist at that point. Since no trustworthy footprint is available, cap **every lineage/result across the entire run** to incomplete with global reasons and unknown existence/name, including earlier tombstones, later creates and recreated lineages. Apply this cap before identity resolution and preserve it in result composition; neither a later create nor partition selection clears it. No invented aliases/states for names absent from analyzed source: not-found instead gets the skip caveat. Preserve counters and root diagnostics (do not emit a duplicate generic warning). A verified empty up() is not skipped. Size/read/parser/missing-class-or-body failures share this rule (mvp-spec §8.8); a discovery failure is exit 1, not a fabricated empty input set.

**Completeness (status axis).** A table is `INCOMPLETE` if:

- (a) an **attributed** unresolved operation affected it (`direct`);
- (b) an **unattributable** operation occurred while it was live (`global`, live-window rule);
- (c) a **conditional** (`shouldRun()`) operation touched it (`conditional`) — the migration may not have executed;
- (d) a replay **conflict** was detected on it (§7.3) — including `ALTER_UNKNOWN_TABLE` phantoms, whose origin is `UNKNOWN` and which are `INCOMPLETE` from birth;
- (e) it is the source or target of a `RENAME_TARGET_CONFLICT`.
- (f) any discovered migration has unknown whole-file effects (D20), regardless of replay position.

The flag is **sticky within a lineage**: once incomplete, always incomplete, including after a certain drop. A **certain unconditional** drop → create starts a new lineage whose status is computed fresh, except for the run-wide skipped-file cap. A possible drop does not close the prior lineage: later create must preserve the competing identity. Unknown existence/name also remains conservative within that lineage; later best-effort operations do not prove which conditional path ran.

**Existence is separate from completeness.** State uses PRESENT / ABSENT / UNKNOWN; a certain unconditional drop stores ABSENT plus droppedBy. A conditional drop has UNKNOWN, retains pre-drop columns, and records its possible-drop event without droppedBy. A conditional rename keeps one lineage and both observed possible names with nameCertain=false; no exclusive alias/move is asserted. Conditional create supplies an UNKNOWN candidate. The same rules apply to columns. possibleNames need not be exhaustive after unknown effects; nameCertain=false prevents the renderer inventing a current name. On output, incompleteness always wins: data.status=incomplete even for an ABSENT lineage; only complete+ABSENT renders dropped. Unknown existence never becomes false in trace JSON. Fidelity remains a separate axis, not a fourth support tier.

**Fidelity axis.** An element (column/index/FK) is `REDUCED` once **any** construct that contributed to it was PARTIALLY SUPPORTED (Tier-2); otherwise `FULL`. Table fidelity is `REDUCED` if any element is. Fidelity is **sticky per element** (a later full-fidelity redefinition, e.g. `change()` with Tier-1 modifiers on a Tier-2 column, keeps the column `REDUCED` — conservative and simple). Fidelity is always rendered (text footnote `†` / JSON field) and **never affects exit codes** — that is the status axis's job (D6).

### 7.3 Replay rules (violations are diagnostics, never crashes)

| Operation | Expected precondition | Effect | On violation |
|---|---|---|---|
| `CreateTable` | table certainly absent in partition | new PRESENT state (CREATED_HERE), new lineageId | Definite duplicate: DUPLICATE_TABLE_CREATE, replacement incomplete as before; over phantom retain ALTER_UNKNOWN_TABLE. If a prior identity is only possibly absent, retain both candidates/reasons and the conflict; never silently replace the uncertain lineage. |
| `AlterTable` | table live in partition | apply children | `ALTER_UNKNOWN_TABLE`; phantom state with `origin: UNKNOWN`, status `INCOMPLETE` (typical after a schema squash — see mvp-spec §8.2) |
| `DropTable` (unconditional) | uniquely identified, certainly named table | existence ABSENT, droppedBy recorded, history/reasons retained | DROP_UNKNOWN_TABLE if no known target. On uncertain names/existence, retain a conservative UNKNOWN candidate/reasons; do not infer earlier predicates ran. |
| `RenameTable` | `from` live, `to` **absent** | move state within partition; record alias from→to | `from` unknown → `RENAME_UNKNOWN_TABLE`; `to` already live → `RENAME_TARGET_CONFLICT`, rename **not applied**, both tables `INCOMPLETE` |
| `AddColumn` | column absent | add `ColumnState` (fidelity from operation) | `DUPLICATE_COLUMN`; replace; status `INCOMPLETE` |
| `DropColumn` | column present | remove | `DROP_UNKNOWN_COLUMN` |
| `RenameColumn` | column present, target absent | move + alias | source unknown → `RENAME_UNKNOWN_COLUMN`; target exists → `RENAME_TARGET_CONFLICT`, not applied, table `INCOMPLETE` |
| `ChangeColumn` | column present | merge declared modifiers; fidelity → `REDUCED` | unknown column → diagnostic |
| `AddIndex`/`AddForeignKey` | table live | append (default Laravel naming when unnamed) | unknown table → diagnostic |
| `DropIndex`/`DropForeignKey` | target exists | remove | diagnostic |
| `UnresolvedOperation` (attributed) | — | matching partition/name lineages incomplete, reason direct; preserve unresolved provenance for missing targets | — |
| `UnresolvedOperation` (name-scoped) | — | matching lineages across partitions incomplete, existence/name uncertain; ledger entry even if none matched | — |
| `UnresolvedOperation` (unknown table, analyzed source) | — | live-window rule (§7.2), including possibly live/tombstoned candidates; append ledger entry | — |
| `UnresolvedOperation` (wholeFileUnknown) | — | one skipped_migration ledger entry; run-wide cap (§7.2) | — |
| Conditional CreateTable / AddColumn | — | retain candidate definition, existence UNKNOWN; incomplete reason conditional; conditional event | existing identity remains a competing candidate, never replaced as certainly absent |
| Conditional DropTable / DropColumn | — | retain pre-drop state, existence UNKNOWN, incomplete reason conditional, possible-drop event; no definitive droppedBy | unknown source remains unresolved, not a fabricated certain tombstone |
| Conditional RenameTable / RenameColumn | — | retain lineage + both possible names, existence UNKNOWN, nameCertain=false; incomplete reason conditional; conditional event | conflict on either possible name retains candidates/reasons, never overwrites a live identity |
| Other conditional operations | — | best-effort candidate values + touched results incomplete, reason conditional, event conditional=true | never erase prior uncertainty or prove execution |

Conditional rows take precedence over ordinary rows. After any uncertainty, later operations target all compatible candidates conservatively; they cannot choose a branch. This is one candidate snapshot plus names/reasons per lineage, not full branch enumeration. A generic unresolved conditional/loop is not converted to supported conditional replay.

### 7.4 History, lineages, and tracing

Every recognized operation, including a conditional intent retained without destructive application, appends a HistoryEvent { sequence, date (from filename), operation, summary, conditional, file, line }. Skip markers live in the uncertainty ledger/diagnostics, not fabricated per-table change events. Reasons remain visible alongside each command's timeline.

**Lineage model.** Certain create/rename/drop retain their original identity rules. Conditional create starts a candidate; conditional rename adds possible names without declaring a completed rename; conditional drop leaves that identity possibly present. Only a certain drop creates a tombstone. Whole-file caps may make an earlier tombstone possibly present again. Keep each lineage and reasons in the existing registry/name index.

**Table resolution algorithm** (for a requested name in a partition):

1. Apply the whole-file cap, then collect lineages whose current, former or statically observed possible name matches in the selected partition.
2. Exactly one PRESENT or UNKNOWN candidate → resolve it, preserving existence/name uncertainty and all reasons. A certain live lineage wins over certain tombstones, including ordinary unconditional drop/recreate.
3. Multiple PRESENT/UNKNOWN candidates → AMBIGUOUS_TABLE_IDENTITY, exit 1, candidates listed. Definite-live never silently wins over possibly-live: conditional drop/recreate and conditional rename/name reuse must not disappear behind nominal tombstones/aliases.
4. Only ABSENT lineages → latest certain tombstone by sequence. Complete analysis renders DROPPED, dropped_by, last-known state (0 including strict); incomplete analysis renders INCOMPLETE with existence absent and preserved reasons (0 / strict 2).
5. No known matching lineage → TABLE_NOT_FOUND (1 even under strict), caveated for dumps, relevant unresolved table/connection targets and skipped files. No extracted operation is not proof of absence. If every file was skipped, it is still this error, not NO_MIGRATIONS_FOUND. Error envelopes retain incomplete status and ledger/counters (mvp-spec §6).

**History rendering** groups events by lineage. When dead lineages share the requested name (drop → re-create), they are rendered as clearly separated "earlier lineage" sections before the primary (live/latest) one — the user sees *both* lives of `users` without ambiguity.

**Column trace:**

1. Resolve the table lineage (above).
2. Within it, find the column identity chain: walk events in order; a `RenameColumn` that renames any identity in the chain extends it.
3. Emit events chronologically with provenance. Certain drop terminates the chain; possible drop/rename keeps candidate definitions/names and conditional reasons. Unknown existence gives exists=null; uncertain current table/column names are null with observed possible names supplied.
4. Trace by former name (`schema:trace users.user_email`) resolves forward to the current name and reports both.

Column-level rename conflicts and drop/recreate follow the table-level rules scoped to the containing table lineage. Exported column/trace existence also incorporates table existence/name uncertainty: a conditional table drop yields exists=null for a formerly certain column, with table and column reasons preserved. A certainly absent table yields absent current columns while retaining last-known definitions. Projection never upgrades a candidate definition to a guaranteed current one.

---

## 8. CLI architecture

```
SchemaInspectCommand          "presentation only"
  handle(Atlas $atlas, RendererFactory $renderers)
    1. resolve options → DiscoveryOptions + connection partition
    2. $result = $atlas->inspect($table, $options, $connection)
    3. $renderer = $json ? JsonRenderer : TextRenderer
    4. return $renderer->render($result)     // exit code
```

- Commands share an abstract base for option parsing (`--json`, `--connection`, `--path`, `--strict`) only — no domain code.
- `TextRenderer` uses Laravel console components (tables) where helpful; output is stable and diff-friendly. It must render, per mvp-spec §4: the status banner (incl. `INCOMPLETE` reasons split by direct/global/conditional origin, and the `DROPPED` banner), fidelity footnotes (`†`), conditional event markers (`[conditional]`), and partition hints on `TABLE_NOT_FOUND`.
- `JsonRenderer` implements the envelope from mvp-spec §6 (incl. `connection`, always-present `global_uncertainty`, `fidelity`, `conditional`). No timestamps (D8).
- Exit codes: `0` success (incl. dropped-table inspect) · `1` failure (errors, not-found, ambiguous identity) · `2` incomplete + `--strict`. Reduced fidelity never changes exit codes (D6).

Renderers also expose existence, observed possible names/name certainty, incompleteness reasons, analyzed/skipped counters and nullable source lines. Unknown conditional drops never render DROPPED or definitive dropped_by; trace exists is null. Incomplete certain tombstones remain incomplete. Error exit 1 outranks strict 2, including caveated not-found and conditional identity ambiguity.

---

## 9. Error and diagnostic strategy

**Two failure classes, never mixed:**

1. **Programmer/container errors** — broken DI or a missing package class: thrown exceptions, standard Laravel error reporting. These are bugs.
2. **Analysis/input findings** — syntax/parse/size/read failures, unknown code, conflicts, raw SQL and conditionals are data in DiagnosticCollection; one bad discovered file never aborts a run. Root warnings produce the skip marker/cap when the body is unavailable (AC-10/19/23). Invalid/unreadable discovery paths are command errors (1), because the input set cannot be established; they are not programmer bugs or recoverable empty migrations.

Diagnostics carry: severity, code (enum, mvp-spec §7), message, optional file/line. Renderers print them as a block. Global vs per-table relevance is expressed by attachment point (result-level vs table-state reasons, with `origin: direct|global|conditional|conflict`).

**Testing posture:** every diagnostic code has at least one fixture producing it and one assertion consuming it — the catalog is executable documentation.

---

## 10. Security constraints

The threat model: **migration files are untrusted input**. They may come from third-party packages installed into a project, and may contain anything PHP can express.

| Constraint | Enforcement |
|---|---|
| Never execute migration source | Files are read with `file_get_contents` and handed to php-parser. No `include`, `require`, `eval`, `assert`, `exec`, `proc_*`, or `ReflectionFile` anywhere in the package — enforced by an architecture test (Pest arch plugin) plus code review. |
| Never connect to a database | No `illuminate/database` dependency at all (D5); commands and services never touch `DB`/capsule. Enforced by AC-11 (analysis succeeds with unusable DB config). |
| Resource safeguards | Configurable max_file_size (default 1 MiB); MIGRATION_SYNTAX_ERROR for syntax errors, MIGRATION_PARSE_FAILURE for recoverable parsing Throwables, MIGRATION_SOURCE_UNREADABLE for discovered-file stat/read failure, FILE_TOO_LARGE for oversize. Discard partial calls, emit one skip marker and retain unknown effects (D18/D20). No CPU timeout, streaming or fatal-OOM recovery guarantee: php-parser parses whole strings; an in-limit pathological file can be slow/memory-hungry. Run with adequate memory_limit (at least 256M guidance, not a guarantee). |
| Treat analyzed files as untrusted | Beyond the size cap, any parse failure degrades to a diagnostic; analysis is read-only and single-purpose. |
| Read-only filesystem | The package opens files for reading only; it creates no files, writes no cache in MVP. |
| No network | No HTTP clients, no remote fetching — zero network-capable dependencies. |
| Path containment | Discovery only reads the explicitly configured paths (default `database/migrations`); `--path` is user-provided by definition. No directory traversal originates from *file contents*. |
| Output safety | Text renderer avoids interpreting ANSI in data values; JSON renderer uses `json_encode` escaping. Source snippets are shown verbatim, never re-parsed. |

---

## 11. Configuration

`config/migration-atlas.php` (publishable; sensible zero-config defaults):

```php
return [
    'paths' => [
        database_path('migrations'),   // Laravel's default; array — user-extensible
    ],

    // Resource safeguard: migration files larger than this are skipped
    // with a FILE_TOO_LARGE diagnostic. Default 1 MiB (see D18).
    'max_file_size' => 1024 * 1024,
];
```

MVP honors package-registered migration paths (`loadMigrationsFrom()` in third-party service providers) **only if the user adds them to `paths`** — auto-detecting provider paths is out of scope (Open Question Q8). The connection partition key `'default'` is implicit; there is no config for it (its physical meaning is environment config and stays unresolved in the MVP — D15).

---

## 12. Architectural decision record

Short-form ADRs. R1 records the accepted first revision; R2 identifies this final correctness pass. The five stages remain unchanged. D20 formalizes whole-file uncertainty within the existing ledger/operation model.

| # | Decision | Rationale | Alternatives rejected |
|---|---|---|---|
| D1 | **Static AST analysis; never execute migrations** | Safety (untrusted source, no side effects), speed, works with no DB. | Sandbox execution against in-memory SQLite: would resolve dynamic code but executes untrusted PHP and needs `illuminate/database` + full app boot. Post-MVP option behind explicit opt-in only. |
| D2 **(revised R2)** | **Deterministic basename ordering and preserved failure positions** | Sort migration basenames lexically after deduplication (last configured path wins, matching Migrator); retain failed files and AST operation ordinals. Skip markers/diagnostics cannot move when extraction yields no calls. | Directory-path sorting or removing failed entries (changes replay order and uncertainty). |
| D3 | **Analyze `up()` only; ignore `down()`** | Final state derives from forward history; `down()` adds noise, zero signal for final structure. | Parsing both for consistency checks (interesting, post-MVP). |
| D4 | **Typed operation DTOs between parser and reconstructor** | Decouples phases; snapshot-testable; serializable for future caching; the reconstructor needs no php-parser knowledge. | Replaying directly from AST nodes (couples phases, untestable in isolation). |
| D5 **(revised R2)** | **No illuminate/database runtime dependency; verified support matrix** | Encode Blueprint semantics without runtime reflection. Standard create only; createIfNotExists/custom facade macros remain unresolved and never execute. Official Laravel references are in mvp-spec §9; conformance tests pin the matrix. | Reflecting/loading schema builders or assuming custom macros have built-in semantics. |
| D6 **(revised R2)** | **Support tier / completeness / fidelity stay separate; existence is explicit** | DEFERRED affects completeness; PARTIALLY SUPPORTED affects fidelity only. Existence/name uncertainty has explicit data, not a new support tier. Incomplete status takes precedence over a dropped lifecycle; only complete+certain absence renders dropped. | Tombstone status masking conditional/global reasons; treating reduced fidelity as strict failure. |
| D7 | **Immutable value objects + functional replay** | Determinism, cheap snapshot tests, no aliasing bugs across migrations. | Mutable registry mutated in place (fragile with rename/drop chains). |
| D8 | **No timestamps/IDs in output; byte-stable JSON** | Diffable, snapshot-testable, CI-friendly (AC-8). | Including `generated_at` (breaks determinism; add post-MVP behind a flag if asked). |
| D9 **(revised R2)** | **Diagnostics as data with recoverable per-file failure** | Syntax/parse/size/read failures keep the run going with root diagnostics and unknown-effects markers. Discovery/path enumeration failure exits 1 because the file set is unknown. | Treating unreadable source as empty; hiding a discovery failure behind NO_MIGRATIONS_FOUND. |
| D10 | **Blueprint variable bound by closure parameter name** | Supports `function (Blueprint $bp)` / `fn` / any naming; not hardcoded `$table`. | Hardcoded `$table` (breaks on unconventional-but-valid code). |
| D11 **(revised R2)** | **Sticky per-lineage reasons plus run-wide skip cap** | Conditional/global/direct/conflict reasons survive drops and lookup. Certain drop/recreate can start fresh except when a whole-file cap applies. Possible drops never clear earlier candidate identity. | Losing uncertainty on destructive operations or lineage resolution. |
| D12 **(revised R2)** | **Attribution/live-window rules for analyzed unresolved operations** | Known-name unknown connections affect matching lineages across partitions; unknown table targets affect present/possible lineages and possibly recreated tombstones. Ledger records unknown targets even with no match. Later first-created tables may stay complete under expected replay; whole-file skips use D20. | File-scoped guesses, unqualified definite absence after an unknown operation, or using live-window precision for wholly unreadable source. |
| D13 | **Conditionals/loops → unresolved regions (no constant folding)** | Correctness of folding arbitrary PHP is out of reach; report, don't guess. Narrow exception: the exact `if (Schema::hasTable('lit')) return;` guard shape is semantically ignorable (idempotency only) — recognized in M8. | Partial constant evaluation (slippery slope to D1 rejection). |
| D14 | **`change()` recorded as declared-modification event** | Laravel 11+ requires full new definition in the migration; we show exactly what the source declares, nothing inferred; fidelity `reduced`. | Diffing old/new definitions (impossible statically when prior state came from a dump). |
| D15 **(revised R2)** | **Minimal partitioning with migration-level connection precedence** | A static migration connection routes unqualified calls; literal explicit wrappers override it per call. Unknown routing never falls back to default; use unresolved name-scoped/global rules. CLI selects results, not execution. No runtime/config/physical alias resolution. | Ignoring protected $connection, silently merging partitions, or evaluating constructors/config/accessors. |
| D16 | **Interface only where a second impl/test double is expected** | Finder, Parser, Reconstructor, Renderer get interfaces (filesystem/php-parser/presentation are swap seams). Everything else is final classes. | Interfaces everywhere (ceremony without benefit). |
| D17 **(revised R2)** | **shouldRun detection with conservative structural candidates** | SHOULD_RUN_DETECTED is the sole code. Record conditional source intent; creates are possible, drops retain pre-state, renames retain possible names. Touched results stay incomplete through history/trace/JSON and strict exits. One snapshot plus uncertainty metadata; no branch enumeration. | Replaying conditional destructive operations as facts; evaluating shouldRun; omitting conditional history. |
| D18 **(revised R2)** | **Resource/read failures retain unknown schema effects** | Size cap and recoverable Throwable/read guards emit root warnings plus one ordered skip marker (D20). No partial AST replay. No CPU timeout/streaming/fatal-OOM recovery promises. | Treating skipped source as an empty migration or claiming parser self-limits. |
| D19 **(revised R2)** | **Resolve certain and possibly live lineages without guessing** | Current/former/possible names participate; more than one present/unknown candidate is ambiguous (1). A certain tombstone never outranks a possible live identity or suppresses incomplete status. Ordinary unconditional rename/drop/recreate behavior remains. | Latest/live-wins heuristics that erase conditional identities. |
| D20 **(new R2)** | **One ordered unknown-effects marker per skipped migration; run-wide cap** | Unreadable/unparseable/oversized bodies have unknown effects, even with zero extracted operations. Reuse UnresolvedOperation + ledger, retain original ordinal/root code and null lines, cap every result/partition/lineage to incomplete with unknown existence/name. Resolved strict exit 2; caveated missing/ambiguous identity stays 1. | Dropping the file from replay, guessing scope from partial source, applying only the live-window rule, or adding a new pipeline/branch engine. |

---

## 13. Architectural tradeoffs (summary)

1. **Precision vs coverage.** Strict tiering means exotic-but-valid codebases see `INCOMPLETE` where a heuristic tool might "mostly work". We choose trust.
2. **Static analysis vs sandboxed execution.** We cannot resolve dynamic code; we gain safety, speed, zero-config, and DB-free operation (the whole point of the package).
3. **Filename order vs recorded order.** Expected schema may differ from long-lived databases; we label everything "expected" and offer post-MVP cross-check.
4. **Hardcoded Blueprint semantics vs framework reflection.** Smaller dependency graph at the cost of a maintenance duty (conformance tests).
5. **Scoped uncertainty vs whole-file cap.** Analyzed unresolved calls retain attribution/live-window precision. Wholly skipped source has no reliable footprint and conservatively caps all results, accepting extra incomplete output rather than fabricated completeness.
6. **Fidelity separated from status.** Partial support is visible without failing CI; `--strict` couples only to genuine incompleteness. Teams wanting stricter gating get a post-MVP option.
7. **Partitioning vs full multi-connection.** Partitioning eliminates the silent-merge hazard at minimal cost; physical connection resolution stays out.
8. **Serializable operations as pipeline currency.** Slight verbosity now; buys snapshot testing and future caching for free.

---

## 14. Extension points (post-MVP, non-binding)

- `MigrationFinder` interface → cached / git-aware finder.
- `Operation` hierarchy → SQL-dump-derived operations (restoring pre-squash history).
- `Renderer` interface → Markdown/SRD export, GitHub-annotation format.
- `SchemaReconstructor` → `--from-database` comparator (expected vs actual diff; true execution order from the `migrations` table).
- `SupportMatrix` as data → user-extensible custom type registry (package macros).
- Connection partitions → full multi-connection resolution (physical default, cross-connection FK validation, per-connection dump baselines).
- Serialization of `Operation[]` → result cache keyed on file content hashes.
- `--strict=fidelity` → fidelity-gated CI mode (D6 alternative).
