# M4 index, foreign key, change and Tier-2 normalization - 18 November 2026

**Status:** Implementation and local verification are complete. The full local
suite, formatting, maximum-level static analysis, strict Composer validation,
and both production runtime smoke scripts pass against the final source. The
previously broken workflow startup was repaired (the `runtime-floor` job
declared a job-level `runner.temp` expression that GitHub rejects before any
job starts), hosted CI is green again, and the M4 push re-verifies the whole
compatibility matrix on GitHub. No commit or push was performed during the M3
checkpoint; the CI repair and this milestone are committed separately.

## Implemented scope

- Immutable index and foreign-key operations join the ordered operation model:
  `AddIndex`, `DropIndex`, `AddForeignKey`, `DropForeignKey`, and
  `ChangeColumn`, each carrying source location, execution certainty, and the
  table/connection partition. `IndexDefinitionDto` and
  `ForeignKeyDefinitionDto` hold the element payloads with explicit fidelity.
- Index operations normalize `primary`, `unique`, `index`, `fullText`, and
  `spatialIndex` plus their drops. Default names follow Laravel's exact
  convention: the lowercased `{table}_{columns}_{type}` string with dashes and
  dots replaced by underscores. Drops resolve literal names, conventional names
  from column lists, or keep a null name for `dropPrimary()` without arguments,
  where no static name exists. Composite column arrays and custom names bind
  through the shared argument binder; dynamic values, raw expressions, extra
  arguments, and chained unknown modifiers stay unresolved.
- Foreign-key chains resolve `foreign()->references()->on()->onDelete()
  ->onUpdate()` with the `cascadeOnDelete`, `restrictOnDelete`,
  `nullOnDelete`, and `cascadeOnUpdate` shortcuts at full fidelity from
  literals. `dropForeign` resolves by literal or conventional name.
  Incomplete chains end at their most precise chain position as unresolved
  operations; `deferrable` and other unlisted chain methods remain
  `UNRESOLVED_UNKNOWN_METHOD`.
- `foreignId()` records a Tier-2 unsigned column; a key command starts only
  through `constrained()` or `references()`, mirroring the framework. Dead
  Fluent attributes before a key exists (`foreignId('x')->onDelete(...)`)
  are visibly unresolved instead of fabricating key semantics.
  `constrained()` derives the convention table with Laravel's exact
  `Str::plural` on the name before the referenced-column marker and remains
  full fidelity. `foreignIdFor(Model::class)` resolves `::class` when the
  parser resolved a fully-qualified literal, records the snake-case column,
  `references`, and convention table, and stays reduced because a real model
  may override its table or key. Dynamic model identities stay unresolved.
- Tier-2 columns record their canonical raw type with literal arguments:
  lengths, precisions, `total`/`places`, and enum/set `allowedValues`.
  Increment families (`bigIncrements` … `tinyIncrements`) carry
  auto-increment, unsigned, and primary intent. Known expansions
  (`softDeletes`, `softDeletesTz`, `rememberToken`, `timestampsTz`) record
  their defined columns, nullability, and precision. Every Tier-2 element is
  fidelity `reduced`, never presented as full.
- Partial modifiers record static data and mark the column reduced:
  `unsigned`, `after`, `first`, `comment`, `charset`, and `collation`.
  Dynamic defaults record explicit markers: `useCurrent()` becomes a
  `CURRENT_TIMESTAMP` default, `useCurrentOnUpdate()` an on-update attribute,
  and `default(now())`/`default(DB::raw(...))` an explicit `[dynamic]`
  marker with `hasDefault` true. Other dynamic values and malformed partial
  modifiers stay unresolved.
- `change()` captures a `ChangeColumn` modification event carrying only the
  declared definition; the affected column is always fidelity `reduced`. On
  `foreignId` chains a `change()` keeps the derived key command alongside the
  modification event, mirroring the framework's command accumulation.
- The shared `ColumnModifierReducer` walks every column chain: supported
  modifiers apply full semantics, partial modifiers record reduced data, and
  anything else stays unresolved at the precise failing modifier line with
  the whole chain retained.

## Registry and uncertainty alignment

- `SupportMatrix` now marks the index, foreign, foreign-modifier, increment,
  generic-column, model-foreign-id, expanded-column, partial-modifier, and
  dynamic-default rows implemented. `hasTable` guard clauses, generated-column
  expressions, and custom macros remain deferred exactly as approved.
- Tier-2 canonical types preserve the approved spelling (for example
  `bigIncrements`, `foreignIdFor`) through the registry rather than call-site
  casing.
- The `uncertainty` snapshot now captures its `uuid('token')` and
  `default(now())` constructs as reduced-fidelity data instead of unresolved
  operations; every other uncertainty behavior in the fixture, including
  conditional execution stamps, dynamic routing, the macro guard, and raw
  SQL, is unchanged. The `basic` snapshot is byte-identical to the M3
  contract. A new `tier-two` fixture pins the ordered M4 contract: Tier-2
  columns, both foreign-id families, index naming and drops, `change()`,
  partial modifiers, and dynamic defaults inside one create/alter sequence.
- Column-level index creation in modifier position (`$table->string('email')
  ->unique()`) is deliberately not captured: the approved matrix defines
  index methods as Blueprint calls only, and matrix extension is a reviewed
  act. Such chains remain `UNRESOLVED_UNKNOWN_METHOD` and are documented for
  the next support-matrix review.
- Written `::class` literals are always resolved by the AST name resolver, so
  `foreignIdFor(User::class)` without an import still produces a definite
  fully-qualified name; only dynamic model expressions stay unresolved.

## Verification

The full local suite passes **638 tests / 3,102 assertions**, with the same two
Unix-only discovery regressions skipped on Windows. New focused suites cover
every index construct and naming rule, every foreign-key chain variant and
convention derivation (including pluralization cases), Tier-2 columns,
expansions, partial modifiers, dynamic-default markers, and `change()` on
tier-one, tier-two, and foreign-id roots. The production runtime smoke now
also proves foreign-id convention derivation and `change()` capture under a
production dependency installation.

Formatting (Pint) and maximum-level PHPStan/Larastan pass with zero errors,
baselines, or ignored findings; the two findings raised during development
were fixed rather than suppressed. The architecture constraints keep
normalization free of AST/source IO and keep operation DTOs free of framework
dependencies; Laravel's `Str` pluralization is used only in the normalization
layer, where `illuminate/support` already hard-requires the same inflector the
framework uses.

Hosted CI runs the eight PHP/Laravel dependency combinations and the
production parser-floor job on the push carrying this milestone; the workflow
startup repair that preceded it is verified green on GitHub with all nine jobs
passing.

## Current limits and next checkpoint

Commands retain their discovery-only behavior; there is no schema
reconstruction or final command JSON envelope yet. Index algorithms
(`$table->index(...)->algorithm('btree')`) remain unbound extra arguments and
stay unresolved. Column-position modifiers (`after`/`first`) are recorded as
data only, without ordering effects, until replay defines them. `storedAs`,
`virtualAs`, and `generatedAs` remain deferred per the matrix, and
modifier-position index creation remains outside the approved contract.

Whole-file completeness caps, live-window uncertainty, connection-partition
replay, existence/name certainty, conflict handling, and history/trace results
remain M5+ work. M4 supplies the typed operations, indexes, foreign keys,
fidelity stamps, and provenance that reconstruction will consume. The next
implementation scope is M5: the schema state machine and replay of the
architecture's rule table.
