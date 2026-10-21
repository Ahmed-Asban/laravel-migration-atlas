# Laravel Migration Atlas — MVP Specification

| | |
|---|---|
| **Package (working name)** | `laravel-migration-atlas` — final vendor namespace TBD (Open Question Q1) |
| **Phase** | 1 — Step 2: Architecture & MVP Specification |
| **Status** | **Revision 2** — final architecture corrections applied; awaiting human approval |
| **Target** | PHP 8.3+, Laravel 12 / 13, Composer package with auto-discovery |
| **Related docs** | [architecture.md](architecture.md) · [implementation-plan.md](implementation-plan.md) |

This document defines the **product boundary** of the MVP: what the tool does, exactly what it supports and does not, how it behaves on the command line, and how we accept it as complete. Internal design lives in [architecture.md](architecture.md); sequencing in [implementation-plan.md](implementation-plan.md).

**Revision 1 changes** (from review round 1): explicit global-uncertainty handling for unattributable operations; `shouldRun()` detection and honest conditional-execution output; schema-dump honesty rules; minimal connection isolation; realistic parser resource safeguards; PSR-4/folder alignment; a consistent three-axis semantics model (support tier / analysis status / fidelity); lineage rules for rename/drop/recreate ambiguity; updated acceptance criteria.

**Revision 2 corrections:** skipped migrations retain ordered unknown-effects markers and cap completeness; migration-level `$connection` routing is explicit; conditional create/drop/rename preserve uncertain existence and names; custom facade macros stay unsupported; `SHOULD_RUN_DETECTED` is the single conditional-migration diagnostic. The five stages and MVP boundary remain unchanged. Acceptance cases below are requirements for future implementation, not tests already run.

---

## 1. Problem statement

A Laravel application's database schema is not described anywhere as a whole. It is the cumulative result of every migration ever written — an append-only history of PHP programs that create, alter, rename, and drop tables and columns over months or years.

Consequences developers face daily:

- **"What does this table actually look like now?"** — requires either a live database connection (`SHOW CREATE TABLE`, `DESCRIBE`) or manually reading dozens of migration files.
- **"Why does this column exist? Who added it? Has it changed?"** — requires `git log` plus manual archaeology across migration files.
- **Database introspection is not a substitute for source analysis.** Staging/production databases may drift from the codebase; CI environments may have no database at all. What a developer needs when reviewing code is the *expected schema*: the structure the migration chain would produce.

**Laravel Migration Atlas** closes this gap. It statically analyzes migration source files — without executing them and without any database connection — to reconstruct the **expected final structure** of tables, their **change history**, and the **lifecycle of individual columns**.

The guiding principle: **never fabricate schema knowledge**. When the source cannot be statically resolved, the tool says so explicitly — and never presents a result as fully complete when an unresolved operation could have invalidated it.

---

## 2. Goals and non-goals

### 2.1 Goals (MVP)

| # | Goal |
|---|------|
| G1 | Reconstruct the expected final structure of a named table from migration source (`schema:inspect`). |
| G2 | Produce a chronological, source-referenced (file + line) history of migration changes for a table (`schema:history`). |
| G3 | Trace a single column's lifecycle — added, modified, renamed, dropped — with exact provenance (`schema:trace`). |
| G4 | Machine-readable JSON output for all commands; deterministic byte-for-byte across runs. |
| G5 | Safety: never execute, `include`, `require`, or `eval` migration code; never connect to or modify any database. |
| G6 | Honesty: unresolved constructs cap completeness (status), partial support is always visibly marked (fidelity), and nothing is ever guessed. |
| G7 | Determinism: identical inputs (same file set) produce identical ordering and identical output. |
| G8 | Drop-in usability in fresh and existing Laravel 12/13 projects via package auto-discovery. |

### 2.2 Non-goals (explicitly out of MVP scope)

- Executing, validating, dry-running, or generating migrations.
- Reading, comparing, or synchronizing with a **live database** (no DB connection, ever, in the MVP).
- Parsing `database/schema/*.sql` schema dumps (detection + defined honest behavior only — §8.2).
- Full support for arbitrary migration code (dynamic names, loops, conditionals, blueprint macros).
- Analyzing `down()` methods (final state derives from the `up()` chain only).
- **Full** multi-connection support: resolving which physical connection the default refers to, cross-connection validation, per-connection dumps (§8.5 — minimal isolation *is* in scope).
- Schema diffing (expected vs. actual), visualization, or documentation export.
- Performance work such as result caching.

---

## 3. Terminology

| Term | Definition |
|------|------------|
| **Migration file** | A PHP file in a migration directory, conventionally named `YYYY_MM_DD_HHMMSS_name.php`. |
| **Expected schema** | The schema implied by replaying supported `up()` operations in deterministic filename order. Explicitly *not* the actual schema of any real database. |
| **Operation** | A typed, immutable representation of one recognized schema change extracted from source (e.g. `AddColumn`, `RenameTable`). |
| **Unresolved operation** | A detected construct that cannot be statically resolved (e.g. `Schema::table($name, …)`). Recorded, never silently skipped. |
| **Support tier** | Per-**construct** classification (the §5 matrix): **SUPPORTED** (full semantic normalization), **PARTIALLY SUPPORTED** (detected and recorded at reduced fidelity), **DEFERRED** (detected and reported as unresolved). |
| **Analysis status** | Per-**table/result** completeness: `complete` or `incomplete`. Inspect may render `dropped` only as a convenience when analysis is complete and absence is certain. |
| **Existence** | `present`, `absent`, or `unknown`, separate from completeness and fidelity. Conditional structural changes can make existence/name uncertain without proving absence. |
| **Fidelity** | Per-**element** confidence: `full` (produced only by SUPPORTED constructs) or `reduced` (a PARTIALLY SUPPORTED construct contributed). Orthogonal to status; always visible; never changes exit codes. |
| **Attribution** | Known table/connection targets of an unresolved operation. Missing table means unattributable; known table with unknown connection means name-scoped unattributable. Whole-file skips have neither. |
| **Global uncertainty** | Run-level ledger of unresolved table/connection targets and skipped migrations. Analyzed operations use attribution/live-window rules; whole-file skips impose a conservative run-wide cap (§8.8). Always caveats relevant not-found results. |
| **Lineage** | An identity chain: table (or column) identities linked by renames, bounded by create/drop. All name lookups resolve through lineages (architecture §7.4). |
| **Conditional migration** | A migration defining `shouldRun()`; whether it actually executed is not statically determinable. |
| **Reconstruction** | The in-memory replay of the ordered operation stream producing final table states. |
| **Diagnostic** | A structured warning or error attached to an analysis result (code, severity, message, source location). |

**The three axes are independent and always reported separately.** Invariants (enforced everywhere, including output):

1. A **DEFERRED** construct ⇒ the affected result is `incomplete` (never silently complete).
2. A **PARTIALLY SUPPORTED** construct ⇒ the affected element is fidelity `reduced` (never silently full).
3. **Fidelity never affects exit codes**; only status does (§4.2).

---

## 4. CLI specification

### 4.1 Commands

All three commands share the same analysis pipeline; they differ only in presentation.

```
php artisan schema:inspect {table}   [--json] [--connection=] [--path=…] [--strict]
php artisan schema:history {table}   [--json] [--connection=] [--path=…] [--strict]
php artisan schema:trace  {column}   [--json] [--connection=] [--path=…] [--strict]
```

| Element | Description |
|---------|-------------|
| `table` | Table name from source; current/former names resolve through lineages. Observed possible names from conditional renames are also queryable, with incomplete status and uncertain current name. |
| `column` | Dot notation `table.column`, e.g. `users.email`. The column may be a former name; it is resolved forward through renames. |
| `--json` | Emit the machine-readable envelope (§6) instead of human-readable text. |
| `--connection=` | Select the connection partition to query (default: `default`). Unqualified calls inherit a statically resolved migration-level `$connection`, otherwise the symbolic default. This option does not override source routing (§8.5). |
| `--path=` | Repeatable. Overrides configured migration paths for this run (relative to the project root, or absolute). |
| `--strict` | Promote "succeeded but incomplete" to exit code `2` (CI opt-in). |

### 4.2 Exit codes

| Code | Meaning |
|------|---------|
| `0` | Command succeeded — including a determinate answer of "table was dropped" (§4.3). The analysis may carry warnings; these are always visible in output. |
| `1` | Command failed: invalid arguments, no migration files found, target table/column unresolved, ambiguous table identity (§7 of architecture), or discovery/path failure. A discovered file becoming unreadable is a recoverable analysis finding (§8.8). |
| `2` | A result was resolved, is `incomplete`, and `--strict` was passed. Includes direct/global uncertainty, skipped migrations, and conditional create/drop/rename. **Reduced fidelity alone never produces `2`.** Errors take precedence: caveated not-found and ambiguous identity remain `1` even under `--strict`. |

### 4.3 Example: `schema:inspect users`

```
users — expected schema (reconstructed from 37 migration files; no database was read)

Status: complete

COLUMNS
  id               bigint unsigned, not null, auto-increment, primary
      added by 0001_01_01_000000_create_users_table.php:16
  name             varchar(255), not null
      added by 0001_01_01_000000_create_users_table.php:17
      changed by 2024_03_01_120000_make_users_name_nullable.php:16 (nullable)
  email            varchar(255), not null, unique
      added by 0001_01_01_000000_create_users_table.php:19
      renamed from user_email by 2026_01_05_093000_rename_user_email_column.php:16
  password         varchar(255), not null
      added by 0001_01_01_000000_create_users_table.php:20
  referral_code    uuid †
      added by 2025_02_10_081500_add_referral_code_to_users.php:16
  created_at       timestamp, nullable
      added by 0001_01_01_000000_create_users_table.php:24 (timestamps())
  updated_at       timestamp, nullable
      added by 0001_01_01_000000_create_users_table.php:24 (timestamps())

  † reduced fidelity: type captured generically (PARTIALLY SUPPORTED construct)

INDEXES
  users_email_unique        unique (email)      0001_01_01_000000_create_users_table.php:19

FOREIGN KEYS
  (none)

HISTORY SUMMARY
  12 operations across 9 migrations · first: 0001_01_01_000000 · last: 2026_01_05_093000
```

When the reconstruction is incomplete, the banner changes and reasons are listed, distinguishing **direct** and **global** causes:

```
Status: INCOMPLETE — this reconstruction cannot be guaranteed complete

  ⚠ direct: 2025_06_11_101500_add_exotic_indexes.php:24 uses a dynamic column name
  ⚠ global: 2019_11_14_110000_legacy_raw_sql.php:18 contains raw SQL
            (unattributable operation — any table live at that point may be affected)
```

A table certainly dropped by unconditional analyzed operations, with no remaining incompleteness, produces a determinate answer:

```
Status: DROPPED — showing last known state before the drop
  dropped by 2025_09_30_142000_drop_legacy_settings.php:15
```

A conditional drop retains the last-known definition and renders `Status: INCOMPLETE · existence: unknown` with the possible drop's source. It never gets an unqualified `DROPPED` banner or definitive `dropped_by`. An incomplete history with a certain unconditional drop still shows `INCOMPLETE · existence: absent`; strict mode exits 2.

### 4.4 Example: `schema:history users`

```
users — migration history (9 migrations touch this table)

  1. 0001-01-01 00:00:00  create table users (6 columns)
     0001_01_01_000000_create_users_table.php:15
  2. 2024-02-01 09:00:00  add column email varchar(255) not null
     2024_02_01_090000_add_email_to_users.php:16
  3. 2025-07-19 11:30:00  add column legacy_flag boolean not null default false  [conditional ‡]
     2025_07_19_113000_add_legacy_flag_conditionally.php:18
  ...

  ‡ migration defines shouldRun() — whether it executed is not statically determinable

Status: INCOMPLETE — conditional migration · 37 migration files scanned
```

### 4.5 Example: `schema:trace users.email`

```
users.email — column trace (resolved to current name: users.email)

  2024-02-01 09:00:00  added as user_email varchar(255) not null
     2024_02_01_090000_add_email_to_users.php:16
  2025-11-02 14:12:00  changed: default 'unknown@example.com'
     2025_11_02_141200_set_email_default.php:16
  2026-01-05 09:30:00  renamed user_email → email
     2026_01_05_093000_rename_user_email_column.php:16

Current definition: varchar(255), not null, unique
Status: exists · analysis complete
```

If the column was certainly dropped, the trace ends with a tombstone event and `Status: dropped (by <file>:<line>)` only when analysis is complete. Conditional column drops/renames preserve candidate definitions/names, render `INCOMPLETE`, and never claim definite absence or a definite current name.

---

## 5. MVP support matrix

Three orthogonal axes govern behavior (§3):

1. **Support tier** (this matrix) — a property of a *construct*.
2. **Analysis status** (`complete` / `incomplete`) — whether unresolved operations may have affected the result. **DEFERRED constructs always make the affected result `incomplete`.** Existence is reported separately; inspect's `dropped` shorthand requires complete analysis and certain absence.
3. **Fidelity** (`full` / `reduced`) — whether the result relies on PARTIALLY SUPPORTED capture. **PARTIALLY SUPPORTED constructs always mark the affected element fidelity `reduced`** — visibly (text footnote `†`, JSON `"fidelity": "reduced"`), never silently, and never affecting exit codes.

Classifications:

- **SUPPORTED** — parsed, normalized into typed operations with full semantics, replayed into state, covered by tests. Contributes at full fidelity.
- **PARTIALLY SUPPORTED** — detected and recorded, but with reduced fidelity. Never silently dropped, never presented as full fidelity.
- **DEFERRED** — detected and reported as an unresolved operation or diagnostic; does not contribute to state; affects completeness per the attribution rules (architecture §7.2).

The matrix is a **contract**: anything not listed as SUPPORTED must not be assumed to work. Conformance tests pin every row.

### 5.1 Table-level operations

| Construct | Classification | Notes |
|---|---|---|
| `Schema::create('t', $cb)` | **SUPPORTED** | Literal table name required. |
| `Schema::createIfNotExists('t', $cb)` / other custom Schema facade macros | **DEFERRED** | Not built into Laravel 12/13. `UNRESOLVED_UNKNOWN_METHOD`; never normalized as `create`. Macro arguments do not establish a trusted target: record an unattributable unresolved operation and apply §8.4. Never load/execute macro registrations. |
| `Schema::table('t', $cb)` | **SUPPORTED** | |
| `Schema::drop('t')` / `dropIfExists('t')` | **SUPPORTED** | |
| `Schema::rename('a', 'b')` | **SUPPORTED** | Rename-target conflicts are detected (§7.3 of architecture). |
| `Schema::connection('c')->…` wrapper (literal name) | **SUPPORTED** | Operations are isolated into a per-connection partition (§8.5); `--connection` selects it. |
| Migration-level `protected $connection = 'c'` | **SUPPORTED** | Statically resolved declaration supplies the partition for unqualified Schema calls. Explicit literal wrappers take precedence; absent/null uses symbolic `default` (§8.5). |
| Dynamic connection wrapper or unresolvable migration-level connection | **DEFERRED** | No fallback to `default`. Known table names get name-scoped uncertainty across partitions; unknown names use the live-window rule. Explicit literal wrappers still route independently (§8.5). |
| Guard clause `if (Schema::hasTable('t')) { return; }` (exact narrow shape) | **SUPPORTED** (M8) | Semantically ignorable idempotency guard. |
| Any other conditional or loop containing schema operations | **DEFERRED** | Unresolved region; if the enclosing call's table is a literal, the op is table-attributed; otherwise unattributable → global uncertainty. |
| Dynamic table names (variables, concatenation, method calls) | **DEFERRED** | **Unattributable** → `UNATTRIBUTABLE_OPERATION` + global uncertainty (§8.4). |

### 5.2 Column types

| Construct | Classification | Notes |
|---|---|---|
| `id()` | **SUPPORTED** | Normalizes to `bigint unsigned, auto-increment, primary`. |
| `string('x'[, len])` | **SUPPORTED** | Length captured. |
| `integer('x')` | **SUPPORTED** | |
| `boolean('x')` | **SUPPORTED** | |
| `text('x')` | **SUPPORTED** | |
| `timestamps()` | **SUPPORTED** | Expands to nullable `created_at` + `updated_at`. |
| `bigIncrements`, `increments`, `mediumIncrements`, … | **PARTIALLY SUPPORTED** | Recorded generically as auto-increment integer types; fidelity `reduced`. |
| `bigInteger`, `unsignedInteger`, `unsignedBigInteger`, `date`, `dateTime`, `time`, `json`, `jsonb`, `decimal`, `float`, `double`, `enum`, `set`, `char`, `tinyText`, `mediumText`, `longText`, `uuid`, `ulid`, `year`, `foreignId` (bare), `ipAddress`, `macAddress` | **PARTIALLY SUPPORTED** | Recorded with raw arguments (length, precision/scale, allowed values); fidelity `reduced`. |
| `foreignId('x')->constrained()` + cascade/restrict/null modifier family | **SUPPORTED** | Column + FK fully derived. The bare `foreignId('x')` without `constrained()` is Tier-2. |
| `foreignIdFor(Model::class)` | **PARTIALLY SUPPORTED** | `::class` resolved only when statically resolvable via `use` statements; table inferred by Laravel convention; otherwise unresolved. Fidelity `reduced`. |
| `softDeletes()`, `softDeletesTz()`, `rememberToken()`, `timestampsTz()` | **PARTIALLY SUPPORTED** | Known expansions recorded; fidelity `reduced`. |
| Custom column types / blueprint macros (package-defined) | **DEFERRED** | Unknown method → `UNRESOLVED_UNKNOWN_METHOD`. |

### 5.3 Column modifiers

| Construct | Classification | Notes |
|---|---|---|
| `nullable()` / `nullable(true)` / `nullable(false)` | **SUPPORTED** | |
| `default(<static literal>)` | **SUPPORTED** | String, integer, float (incl. negative), `true`/`false`/`null`, and plain arrays (JSON columns). |
| `default(now())`, `default(DB::raw(…))`, `useCurrent()`, `useCurrentOnUpdate()` | **PARTIALLY SUPPORTED** | Recorded as dynamic markers (`CURRENT_TIMESTAMP`, `[dynamic]`); fidelity `reduced`. |
| `unsigned()`, `after('col')`, `first()`, `comment('…')`, `charset(…)`, `collation(…)` | **PARTIALLY SUPPORTED** | Recorded; `after()` affects MySQL column-order display only; fidelity `reduced`. |
| `change()` | **PARTIALLY SUPPORTED** | Captured as a column-modification event; fidelity limited to the modifiers declared in the migration (Laravel 11+ semantics). The affected column is marked fidelity `reduced`. |
| `storedAs(…)`, `virtualAs(…)`, `generatedAs(…)` | **DEFERRED** | Flagged as generated-column expression, not interpreted. |
| Unknown modifiers | **DEFERRED** | Diagnostic. |

### 5.4 Column lifecycle operations

| Construct | Classification | Notes |
|---|---|---|
| `dropColumn('x')` / `dropColumn(['a','b'])` | **SUPPORTED** | Static literals only. |
| `renameColumn('a', 'b')` | **SUPPORTED** | Rename-target conflicts detected (architecture §7.3). |
| Dynamic arguments to either | **DEFERRED** | Table-attributed when the table is a literal; otherwise unattributable. |

### 5.5 Indexes and foreign keys

| Construct | Classification | Notes |
|---|---|---|
| `primary/unique/index/fullText/spatialIndex` — single column, array of columns, custom name argument | **SUPPORTED** | Laravel's default naming convention (`{table}_{columns}_{type}`) applied when no name given. |
| `dropPrimary/dropUnique/dropIndex/dropFullText/dropSpatialIndex` | **SUPPORTED** | By name or column list. |
| `foreign('col')->references('id')->on('t')->onDelete(…)->onUpdate(…)` | **SUPPORTED** | Full fluent chain resolution. |
| `dropForeign('t_col_foreign')` / `dropForeign(['col'])` | **SUPPORTED** | |
| Index/FK expressions using `DB::raw(…)` or variables | **DEFERRED** | Unresolved. |

### 5.6 Raw SQL, execution semantics, and miscellany

| Construct | Classification | Notes |
|---|---|---|
| `DB::statement(…)`, `DB::unprepared(…)`, `Schema::getConnection()->statement(…)` | **DEFERRED** | `RAW_SQL_DETECTED` + `UNATTRIBUTABLE_OPERATION` → global uncertainty (§8.4). |
| `shouldRun()` method on the migration class | **SUPPORTED** (detection + defined semantics) | Emits `SHOULD_RUN_DETECTED`; retain conditional operations/events and mark touched tables `incomplete`. Structural changes use uncertain existence/names, never certain create/drop/rename (§8.3). |
| Schema dumps (`database/schema/*.sql`) | **DEFERRED** (detection only) | `SCHEMA_DUMP_DETECTED`; baseline not parsed; honest behavior defined in §8.2. |
| `down()` methods | **Not analyzed** | By design (D3). |
| `Artisan::call(…)`, jobs, side-effect calls inside `up()` | **DEFERRED** | Reported; never executed. |
| Syntax error / parser failure / oversized file / unreadable discovered file | **DEFERRED** (whole file skipped) | Root diagnostic + one ordered unknown-effects marker; no extracted operations is not evidence of no schema effects (§8.8). |

---

## 6. JSON output contract

All commands emit the same envelope under `--json`. Output is **deterministic**: no timestamps, no random identifiers, stable key order. This makes outputs diffable and snapshot-testable.

```json
{
  "meta": {
    "command": "schema:inspect",
    "table": "users",
    "connection": "default",
    "analysis": {
      "status": "incomplete",
      "global_uncertainty": {
        "present": true,
        "unattributable_operations": 1,
        "skipped_migrations": 0,
        "sources": [
          { "file": "2019_11_14_110000_legacy_raw_sql.php", "line": 18, "position": [2, 1], "kind": "operation", "reason": "UNATTRIBUTABLE_OPERATION" }
        ]
      },
      "migrations_scanned": 37,
      "migrations_analyzed": 37,
      "migrations_skipped": 0,
      "operations_applied": 212
    }
  },
  "data": {
    "name": "users",
    "connection": "default",
    "status": "incomplete",
    "existence": "unknown",
    "possible_names": ["users"],
    "name_certain": false,
    "incompleteness_reasons": [
      { "origin": "global", "code": "UNATTRIBUTABLE_OPERATION", "file": "2019_11_14_110000_legacy_raw_sql.php", "line": 18 }
    ],
    "fidelity": "full",
    "created_by": { "file": "0001_01_01_000000_create_users_table.php", "line": 15 },
    "columns": [
      {
        "name": "id",
        "type": "bigint",
        "length": null,
        "nullable": false,
        "default": null,
        "unsigned": true,
        "auto_increment": true,
        "primary": true,
        "fidelity": "full",
        "existence": "unknown",
        "possible_names": ["id"],
        "name_certain": false,
        "source": { "file": "0001_01_01_000000_create_users_table.php", "line": 16 }
      }
    ],
    "indexes": [],
    "foreign_keys": []
  },
  "diagnostics": [
    {
      "severity": "warning",
      "code": "UNATTRIBUTABLE_OPERATION",
      "message": "Raw SQL may affect any table live at this point.",
      "file": "2019_11_14_110000_legacy_raw_sql.php",
      "line": 18
    }
  ]
}
```

Contract rules:

- `meta.analysis.status`: `complete` | `incomplete` — effective completeness, including all applicable uncertainty. Inspect's `data.status` is `incomplete` whenever analysis is incomplete, otherwise `complete` for a present table or `dropped` for certain absence. A tombstone never overrides incompleteness.
- `meta.analysis.global_uncertainty` is **always present**. Clean shape: `{present:false, unattributable_operations:0, skipped_migrations:0, sources:[]}`. Sources include unresolved table/connection targets and skipped-file markers in replay order: `{file,line,position:[migrationOrdinal,operationOrdinal],kind:operation|skipped_migration,reason}`. Ordinals are 1-based; a skipped-file marker uses operation ordinal 0. `line` is null when unknown. Counts distinguish ordinary operations from skipped files (no double counting). `present` is a run-level fact; target status follows relevance rules, not this boolean alone.
- `migrations_scanned` counts discovered, deduplicated files attempted; `migrations_analyzed + migrations_skipped = migrations_scanned`. A skip marker is not an applied schema operation. Ordering uses migration basename, then AST source order; diagnostics use that same order with a code tie-breaker. No exception stack, nondeterministic OS error text, or invented source line enters JSON.
- `existence`: `present` | `absent` | `unknown`, on table and column results; `possible_names` lists statically observed candidate current names in stable order. `name_certain` is always present: false after a conditional rename or unknown effects; possible names need not be exhaustive then. Candidate/last-known definitions must be labeled in text when existence or name is uncertain. `incompleteness_reasons` is always present (empty when clean), with `{origin,code,file,line}` entries for direct/global/conditional/conflict reasons; history and trace also expose applicable reasons.
- `fidelity` appears on the table and on every column (`full` | `reduced`), with the reason rendered in text output as the `†` footnote.
- Exported column/trace existence incorporates the containing table: an uncertain table/drop/name makes current column existence unknown even if its last-known definition was certain; certain table absence makes current column existence absent. Stored definitions remain available as candidate/last-known values. Uncertainty reasons are unioned from the table and the selected column, never dropped during projection.
- History events always carry `conditional: true|false`. A conditional event records source intent, not proof that the change occurred. `dropped_by` is reserved for a certain drop; a possible drop's provenance remains in conditional events/reasons.
- `data` payload per command:
  - **inspect**: `data = { name, connection, status, existence, possible_names[], name_certain, incompleteness_reasons[], fidelity, created_by?, dropped_by?, columns[], indexes[], foreign_keys[], history_summary }`; `name` identifies the candidate snapshot, not a guaranteed current name when name_certain=false.
  - **history**: `data = { table, connection, current_name, possible_names[], name_certain, existence, incompleteness_reasons[], former_names[], lineages[] → events[] }`; each lineage also exposes existence, possible names, name certainty and reasons. An event is `{ sequence, date, operation, summary, conditional, file, line }`; events are grouped by lineage (architecture §7.4). `current_name` is null if uncertain.
  - **trace**: `data = { requested: {table,column}, current: {table,column,exists,existence,possible_table_names[],possible_column_names[]}, incompleteness_reasons[], definition?, events[], dropped_by? }`. `exists` is true/false only for certain presence/absence, null for unknown; current table/column names are null when uncertain. A retained definition may be a candidate/last-known definition, never a guaranteed current definition.
- Column shape: `{ name, type, length, precision, scale, nullable, default, unsigned, auto_increment, allowed_values?, fidelity, existence, possible_names[], name_certain, source }`
- Index shape: `{ name, type, columns[], fidelity, source }` · FK shape: `{ name, columns[], referenced_table, referenced_columns, on_delete, on_update, fidelity, source }`

Diagnostic severity values: `warning` | `error`. Errors imply exit code 1.

For not-found/ambiguous results, retain the envelope, diagnostics, counters and relevant uncertainty; `data` is null (ambiguous candidates are listed in diagnostic details). Caveated not-found has `meta.analysis.status: incomplete`; it means no resolvable identity in analyzed source, never proven database absence. Exit remains 1, including under `--strict`.

---

## 7. Diagnostics catalog

| Code | Severity | Meaning |
|------|----------|---------|
| `NO_MIGRATIONS_FOUND` | error | No migration files in configured paths. |
| `TABLE_NOT_FOUND` | error | No resolvable table identity in analyzed source. Caveat relevant dumps, unresolved targets/connections and skipped files (§8.2, §8.4, §8.8); never infer certain absence from a missing extracted operation. |
| `COLUMN_NOT_FOUND` | error | No resolvable column identity in analyzed source for the selected table; preserve/caveat relevant table/column uncertainty rather than claiming proven absence. |
| `AMBIGUOUS_TABLE_IDENTITY` | error | Two live lineages match the requested name; the candidates are listed and none is silently chosen. |
| `MIGRATION_SYNTAX_ERROR` | warning | File failed to parse (`PhpParser\Error`); file skipped, others still analyzed. |
| `MIGRATION_PARSE_FAILURE` | warning | Unexpected `Throwable` while parsing a file (not a syntax error); file skipped, run continues. |
| `FILE_TOO_LARGE` | warning | File exceeds `max_file_size`; skipped (§8.8). |
| `MIGRATION_SOURCE_UNREADABLE` | warning | A discovered migration cannot be read/stat-ed reliably (including disappearance during analysis); skipped with unknown effects (§8.8). Discovery/path enumeration failure remains a command error. |
| `UP_METHOD_MISSING` | warning | Migration class has no `up()` method. |
| `MIGRATION_CLASS_NOT_FOUND` | warning | No migration class (named or anonymous) found in file. |
| `SHOULD_RUN_DETECTED` | warning | Migration defines `shouldRun()`; execution is not statically determinable. |
| `NON_STANDARD_FILENAME` | warning | Filename does not match `YYYY_MM_DD_HHMMSS_*.php`; date shown as unknown. |
| `UNRESOLVED_DYNAMIC_EXPRESSION` | warning | Non-literal argument (column name, default, etc.) where the table *is* identifiable. |
| `UNATTRIBUTABLE_OPERATION` | warning | Table or connection target cannot be determined statically (raw SQL, dynamic names/connections, unknown facade macros). Uses name-scoped or live-window uncertainty. Skips instead use their root diagnostic as the marker reason. |
| `UNRESOLVED_CONDITIONAL_REGION` | warning | Schema operations inside conditionals/loops. |
| `UNRESOLVED_UNKNOWN_METHOD` | warning | Blueprint/facade method outside the support matrix. |
| `RAW_SQL_DETECTED` | warning | Raw SQL statement present (always accompanied by `UNATTRIBUTABLE_OPERATION`). |
| `SCHEMA_DUMP_DETECTED` | warning | Squash dump present; early history may be absent (§8.2). |
| `DUPLICATE_TABLE_CREATE` | warning | `create` for an already-live table. |
| `DUPLICATE_COLUMN` | warning | Column added twice without drop/rename between. |
| `ALTER_UNKNOWN_TABLE` | warning | `Schema::table` on a table never created in analyzed source (typical after a schema squash). |
| `DROP_UNKNOWN_TABLE` / `DROP_UNKNOWN_COLUMN` / `RENAME_UNKNOWN_TABLE` / `RENAME_UNKNOWN_COLUMN` | warning | Operation target not present at replay time. |
| `RENAME_TARGET_CONFLICT` | warning | Rename target (table or column) already live; the rename is not applied and both involved tables become `incomplete`. |

Diagnostics never abort analysis. Only `error`-severity diagnostics and usage failures produce exit code 1.

---

## 8. Known limitations and honesty rules

1. **Execution order is inferred, not recorded.** Laravel records actual execution order in the `migrations` database table (batch numbers). Without a DB connection, Atlas uses deterministic filename order — the standard Laravel convention and what `migrate:fresh` would do. Migrations historically executed out of filename order (e.g. re-dated files) will reconstruct differently from a long-lived database. All outputs are labeled **"expected schema"** for this reason. An optional `--from-database` cross-check is post-MVP.
2. **Schema dumps (squash) produce incomplete history — with defined behavior.** If the project squashed migrations (`schema:dump`), migrations older than the dump were deleted and the dump becomes an unparsed baseline. Atlas detects `database/schema/*.sql` (per connection) and: (a) emits `SCHEMA_DUMP_DETECTED`; (b) replays only surviving migrations on top of that unknown baseline; (c) surfaces pre-dump tables as `ALTER_UNKNOWN_TABLE` phantoms with origin `unknown` and status `incomplete`; (d) **caveats `TABLE_NOT_FOUND`** — the message states that the table may be defined in the unanalyzed dump rather than claiming it does not exist anywhere. Dump *contents* are never parsed in the MVP.
3. **Conditional migrations record possibilities, not definite structural changes.** Detect `shouldRun()` without executing or constant-folding its body; emit only `SHOULD_RUN_DETECTED`. Every schema operation/event in that migration is conditional, and every touched result is `incomplete`. Conditional **create** supplies a candidate definition with `existence: unknown`. Conditional **drop/dropIfExists** retains the pre-drop definition, sets existence unknown, and records a possible-drop event rather than a definitive tombstone. Conditional **rename** retains the lineage and both possible current names; lookup by either name resolves it as incomplete, without claiming a definite current name. Column create/drop/rename follow the same rule. Other mutations are best-effort candidate values, visibly conditional. Uncertainty is sticky: later operations on a possible name cannot silently promote the lineage to certain, erase a competing candidate, or infer that `shouldRun()` must have returned true. A conditional drop followed by create of the same name retains both possible identities and reports ambiguity (exit 1) when neither can be uniquely selected. No path enumeration, runtime predicate evaluation, or general branch engine is required. Generic conditionals/loops remain unresolved regions, not supported conditional replay.
4. **Analyzed unresolved operations use attribution/live-window rules.** Unknown connections with known table names mark same-named potentially present lineages across partitions; both endpoints of an unresolved rename are covered. Unknown table targets mark every live or possibly live table at replay position *P* `incomplete` (global reason with file:line). Existing tombstones whose names might be recreated are also uncertain, never definite absence; affected existence/names become unknown. Tables first created after *P* may still be complete under this expected-replay model **unless a whole-file skip exists** (§8.8). The run-level ledger lists all unresolved target/connection sources, including ones matching no known table. Relevant not-found results are caveated, including unknown creates/renames/connections. This models source replay, not every possible runtime side effect of arbitrary PHP.
5. **Connections are partitioned, not resolved.** For unqualified `Schema::…`, statically inspect the migration's own `$connection` declaration: a non-falsy literal string selects that named partition; an absent property on a direct Laravel Migration subclass or literal null/empty string/`'0'` uses symbolic `default` (Laravel's fallback). An explicit `Schema::connection('lit')->…` overrides that migration default for that call only; it also remains routable if the migration default is unresolved. Explicit null/empty string/`'0'` uses the migration's effective default. Dynamic wrappers, nonliteral declarations, writes to `$this->connection`, overridden `getConnection()`, or uninspectable/custom inheritance are unresolved for dependent calls, never silently `default`. Known table names then use name-scoped uncertainty across all partitions, unknown names use §8.4; no definitive schema is extracted from those calls. No `env()`, `config()`, constructors, accessors, custom parents, connection resolvers or application database config are evaluated/loaded. `--connection=` selects a result partition and never re-routes source operations. Physical aliases of symbolic `default`/named connections are not inferred or merged; cross-connection validation and dump parsing remain post-MVP.
6. **`change()` fidelity.** A `change()` call shows the modifiers declared in that migration; the affected column is marked fidelity `reduced`. Pre-Laravel-11 migrations relying on `doctrine/dbal` semantics are out of scope (we target Laravel 12/13).
7. **Expected ≠ actual.** Atlas reports what the source implies, never what a real database contains. The two can diverge (pending migrations, manual edits, drift).
8. **Skipped source has unknown effects.** Syntax error (`MIGRATION_SYNTAX_ERROR`), recoverable parser failure (`MIGRATION_PARSE_FAILURE`), file-size cap (`FILE_TOO_LARGE`, default 1 MiB), or unreadable discovered source (`MIGRATION_SOURCE_UNREADABLE`) discards that file's partial extraction and inserts **one** `UnresolvedOperation` marker at its deterministic migration ordinal, operation ordinal 0, with unknown table and connection. Missing/uninspectable migration class or `up()` also uses this conservative path when the analyzable body cannot be established. Preserve the root warning code, file and available line (otherwise null); do not add a duplicate generic warning. The ledger marks it `kind: skipped_migration`. A successfully analyzed empty `up()` is a known no-op and produces no marker.

   A whole-file skip has no trustworthy footprint, so it **caps every resolved result in every partition to incomplete**, even earlier tombstones, later creates, and drop/recreated lineages. Existence/names are unknown where the omitted source could have changed them; in MVP the skip conservatively makes all returned existence unknown. This run-wide cap is deliberately stronger than the ordinary live-window rule and is not cleared by a later create. `migrations_scanned` alone never implies full analysis. Resolved commands exit 0 normally / 2 under `--strict`; not-found remains a caveated error (exit 1), even when every file was skipped (not `NO_MIGRATIONS_FOUND`). Discovery/path enumeration failure still exits 1 because the input set itself is unknown. The remaining files continue to be analyzed. There are **no CPU-timeout, streaming, or fatal-OOM recovery guarantees**; php-parser parses whole strings in memory, so adequate `memory_limit` is still required (architecture §10).
9. **Tier-2 approximations are marked, never silent.** Any PARTIALLY SUPPORTED construct marks the affected element fidelity `reduced` (text footnote `†`, JSON `"fidelity": "reduced"`). Partial column support can therefore never silently imply full fidelity.

---

## 9. Acceptance criteria

Each criterion is automated as a test (see [implementation-plan.md](implementation-plan.md) §5). The MVP is done when all pass on PHP 8.3+ and Laravel 12 & 13.

| # | Criterion |
|---|-----------|
| AC-1 | `schema:inspect users` against the fixture corpus reconstructs a created table: columns with types, nullability, defaults, attributes; implicit PK from `id()`; source file+line on every element. |
| AC-2 | A table modified by several migrations reflects the merged final state (add → change → rename ordering preserved). |
| AC-3 | Unconditional column addition/removal are replayed: certainly dropped columns absent from final state but present in history. Conditional lifecycle cases follow AC-25. |
| AC-4 | `schema:trace` follows a rename chain (`user_email` → `email`) in both directions and lists add/change/rename/drop events with provenance. |
| AC-5 | Duplicate/conflicting operations (double `create`, double column add) produce the matching diagnostics and mark the table `incomplete`; analysis does not crash. |
| AC-6 | A migration with a dynamic table name (`Schema::table($name, …)`) yields `UNATTRIBUTABLE_OPERATION`; every table live at that replay position reports `incomplete` with a global-uncertainty reason (file:line); a table created only after it remains `complete`; `--strict` exits 2 for affected tables. |
| AC-7 | A migration containing `DB::statement(…)` yields `RAW_SQL_DETECTED` + `UNATTRIBUTABLE_OPERATION`; tables live at that position are `incomplete`; `TABLE_NOT_FOUND` for another table carries the raw-SQL caveat. |
| AC-8 | Deterministic ordering: shuffled directory listings yield byte-identical JSON, including diagnostics, counters and skip markers. Sort by migration basename (not directory path), with operations in AST order; deduplicate equal basenames with the last configured path winning. Preserve configured path precedence. Include different directories, nonstandard filenames, and failed files with nullable lines. |
| AC-9 | Anonymous migration classes (`return new class extends Migration`) and classic named classes parse identically. |
| AC-10 | A syntax-error file yields `MIGRATION_SYNTAX_ERROR` + exactly one skipped-file marker at its original ordinal; no partial AST operations replay. Other files continue. Tables created before and after the skip, earlier tombstones, and recreated lineages are incomplete with unknown existence in every partition; normal exit 0 / strict 2 for a resolved result. Not-found is caveated, exit 1. |
| AC-11 | Zero database interaction: full analysis succeeds in a Testbench app with no usable database configured; no PDO instance is ever constructed. |
| AC-12 | Never-executed proof: a fixture migration containing top-level side effects and an `up()` that throws is analyzed successfully; the side effect never occurs. |
| AC-13 | JSON snapshots for all commands pin §6: connection, always-present ledger/counts, analyzed/skipped counters, existence/possible names/name_certain, reasons, fidelity, and conditional events; unknown trace `exists` is null. Pin error envelopes, nullable source lines, and no definitive `dropped_by` for conditional drops. |
| AC-14 | Exit codes: 0 resolved success (including complete dropped-table inspect); 1 for not-found / no-migrations / ambiguity / discovery failures; 2 for a resolved incomplete result + strict (including skips and conditional destructive changes). Errors take precedence over strict incompleteness; reduced fidelity alone remains 0. |
| AC-15 | Package auto-discovery registers all three commands in a fresh Laravel 12 and Laravel 13 app. |
| AC-16 | Connection isolation: `Schema::connection('archive')->drop('users')` does not affect `users` in the `default` partition; `--connection=archive` reflects it; a not-found table that exists in another partition is reported with a partition hint; no identities are merged across partitions. |
| AC-17 | `shouldRun()` yields only `SHOULD_RUN_DETECTED`, is never executed (even literal true/false bodies), and marks all schema events conditional and touched results incomplete. Conditional additions are candidate definitions; history's status is incomplete. All three commands exit 0 / strict 2 when the identity is uniquely resolvable. |
| AC-18 | Schema-dump honesty: with `database/schema/default-schema.sql` present, a table never created in surviving migrations produces `TABLE_NOT_FOUND` **with** the dump caveat (exit 1); a table only altered by surviving migrations surfaces as unknown-origin and `incomplete`. |
| AC-19 | Oversized source yields `FILE_TOO_LARGE`, one ordered skip marker and the same global cap/exit behavior as AC-10; remaining files continue. `migrations_analyzed + migrations_skipped = migrations_scanned`, markers excluded from operations_applied; successfully analyzed empty up is complete and not skipped. |
| AC-20 | Fidelity marking: a Tier-2 column (e.g. `uuid()`) renders with the `†` footnote in text and `"fidelity": "reduced"` in JSON; the table's fidelity is `reduced`; its status remains `complete`; exit code 0 (and 0 under `--strict`). |
| AC-21 | With certain unconditional operations and no whole-file cap, drop → re-create resolves inspect to the live lineage and history shows both lineages separately. Rename onto a live name yields RENAME_TARGET_CONFLICT, both tables incomplete, no crash. Multiple present/possibly-present lineages matching one name yield AMBIGUOUS_TABLE_IDENTITY with candidates (1); conditional cases are pinned by AC-25 and skipped-file cases by AC-10/23. |
| AC-22 | A certainly dropped table with complete analysis renders last-known state, DROPPED, existence absent and dropped_by; exit 0, including strict. Prior conditional column mutation followed by certain unconditional table drop retains incomplete status and reasons, existence absent, strict 2; no tombstone masks uncertainty. |
| AC-23 | Inject recoverable non-syntax parser failure and unreadable/disappearing source through a test double (portable across OS permissions). Assert root diagnostic, one marker, no partial extraction, continuation, all-partition/run-wide cap, nullable line and strict 2. All-files-skipped + unknown target gives caveated TABLE_NOT_FOUND (1), not NO_MIGRATIONS_FOUND; unreadable directory/discovery failure gives 1. |
| AC-24 | Named and anonymous migrations with literal protected $connection route unqualified create/alter/drop/rename to that partition; absent/null/falsy fallback stays symbolic default. Literal Schema::connection overrides it only for that call (including a literal wrapper under an unresolved property). Dynamic wrappers override literal properties as unresolved. Nonliteral declarations, property writes, getConnection overrides and custom parents cannot fabricate default-partition state. Test known-name effects across partitions, both rename endpoints, caveated not-found for an unresolved create, and strict 2 for affected resolvable targets. No config/env/model/runtime resolution or DB access. |
| AC-25 | Unconditional create then conditional drop/dropIfExists retains candidate definition and unknown existence, no definitive dropped_by/DROPPED; conditional create is never definite presence; conditional rename resolves both names to one incomplete lineage with uncertain current name. Assert inspect/history/trace, JSON reasons/conditional flags, ordinary 0 / strict 2, and column drop/rename equivalents; trace of a formerly certain column after a conditional table drop has exists=null and table reasons. Conditional drop → create same name or conditional rename → create old name retains competing candidates and returns AMBIGUOUS_TABLE_IDENTITY (1), never silently latest/live wins; later operations do not erase conditional reasons. |
| AC-26 | createIfNotExists and an arbitrary custom Schema facade macro yield UNRESOLVED_UNKNOWN_METHOD plus unattributable uncertainty; never CreateTable or a fabricated target definition. Assert existing-table incompleteness, caveated not-found, strict behavior and no macro execution. Support-matrix and diagnostic catalog agree that SHOULD_RUN_DETECTED is the sole shouldRun diagnostic. |

Framework contract references (reviewed 17 October 2026): Laravel's [Migration declaration](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Database/Migrations/Migration.php) and [Migrator connection/default handling](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Migrations/Migrator.php) support §8.5; [Schema facade](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Support/Facades/Schema.php) supplies explicit connection wrappers. The standard [Laravel 12 Builder](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Database/Schema/Builder.php) and [Laravel 13 Builder](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Schema/Builder.php) define create/drop/rename, not createIfNotExists. Recheck against the installed target versions at implementation.

---

## 10. Post-MVP direction (non-binding)

- `--from-database`: cross-check expected vs. actual schema using a live connection (opt-in); read the `migrations` table for true execution order.
- Schema-dump (SQL) parsing to restore pre-squash history.
- Full multi-connection support: resolving the physical default connection, cross-connection FK validation, per-connection dump baselines.
- `schema:tables` overview command; `schema:why table.column` alias for trace.
- Table prefix awareness; result caching keyed on file hashes (fast re-runs).
- Optional fidelity-gated strict mode (`--strict=fidelity`) for teams that want Tier-2 usage to fail CI.
- JSON Schema publication for the envelope; GitHub Action for CI schema diffing.
