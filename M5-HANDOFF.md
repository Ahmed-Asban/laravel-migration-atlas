# M5 schema reconstruction - 18 November 2026

**Status:** Implementation and local verification are complete. The full
local suite, formatting, maximum-level static analysis, strict Composer
validation, and both production runtime smoke scripts pass against the
final source; the production smoke now also replays its fixture end to end.
No commit or push was performed during this checkpoint. The hosted
compatibility matrix re-verifies the milestone on the push that carries it.

## Implemented scope

- `MigrationAtlas\Reconstruction\SchemaReconstructor` is the container-bound
  replay seam, implemented by the functional-fold
  `FunctionalSchemaReconstructor`. `replay(list<NormalizedMigration>)`
  consumes the ordered normalization output and returns a
  `ReconstructionResult` with the partition-aware `TableRegistry`, the
  ordered `HistoryEvent` log, the `UncertaintyLedger`, replay and carried
  diagnostics, and reconciled counters. A flat operation stream could not
  represent empty analyzed files, per-migration dates, or skip markers, so
  the milestone deliberately feeds migrations rather than operations.
- The state model under `Reconstruction\State` separates the four approved
  axes: `TableStatus` (completeness only), `Existence` (present/absent/unknown
  with absent as a certain tombstone), name certainty with observed possible
  names, and per-element fidelity that is sticky and propagates to the
  table. `ColumnState` replays the flattened column attributes with former
  and possible names, existence, drop provenance, and last-known
  definitions; `IndexState` and `ForeignKeyState` carry their payloads with
  fidelity and provenance. `IncompletenessReason` records the origin
  (direct/global/conditional/conflict), catalog code, file, and line.
- Replay rules implement the approved table. Certain operations follow
  lineage semantics: create starts a lineage, rename moves within the
  partition and records former names, drop writes a tombstone with
  `droppedBy`, add/drop/rename/change columns maintain per-column identity
  and provenance, and index/foreign-key operations append or remove. A
  certain drop followed by a create starts a fresh complete lineage while
  the old identity stays tombstoned.
- Conflicts are honest diagnostics, never crashes: a definite duplicate
  create keeps both live identities incomplete (`DUPLICATE_TABLE_CREATE`),
  a create over a phantom lineage retains the earlier `ALTER_UNKNOWN_TABLE`
  uncertainty, a create over a possibly-absent identity keeps both
  candidates and their reasons without a duplicate diagnostic, a rename
  onto a live name is not applied and marks both identities
  (`RENAME_TARGET_CONFLICT`), a duplicate column replaces the definition
  and marks the table, and unknown drop/rename targets warn without
  fabricating state. `dropPrimary()` without arguments keeps a null name
  because no static name exists.
- Conditional operations retain candidates and never choose branches:
  conditional creates are unknown-existence candidates, conditional drops
  and `dropIfExists` keep the pre-state as possible drops without
  `droppedBy`, conditional renames retain both possible names with an
  uncertain current name, and later operations conservatively touch every
  compatible candidate. Their `SHOULD_RUN_DETECTED` reasons are sticky and
  the corresponding history events carry `conditional: true`.
- Unresolved operations act through their attribution with a ledger entry
  even when nothing matches: attributed effects mark matching lineages with
  a direct reason, name-scoped effects match current/former/possible names
  across partitions (rename endpoints included), and unattributable effects
  apply the live-window rule — everything present or possibly present at
  that replay position becomes incomplete with uncertain names, and
  tombstones are demoted to unknown because an unknown effect may have
  recreated them. Tables created after an unattributable effect remain
  complete when nothing else affects them.
- A skipped migration contributes exactly one `skipped_migration` ledger
  entry at `[migrationOrdinal, 0]` and caps the entire run: every existing
  and future lineage in every partition becomes incomplete with unknown
  existence and names, carrying the skipped file's root code. A verified
  empty `up()` counts as analyzed and caps nothing. Counters reconcile:
  `migrations_analyzed + migrations_skipped = migrations_scanned`, and skip
  markers never count as applied operations or events.
- Two interpretation notes are recorded for review. The diagnostics catalog
  has no dedicated code for a `change()` whose target column is absent, so
  `DROP_UNKNOWN_COLUMN` is reused strictly for its documented meaning
  ("operation target not present at replay time"). And per-target replay
  violations (for example a duplicate column against the second of several
  compatible candidates) emit their diagnostic per candidate while events
  and nested unresolved effects are announced once, keeping the event log
  one-to-one with the operation stream.

## Verification

The full local suite passes **664 tests / 3,321 assertions**, with the same
two Unix-only discovery regressions skipped on Windows. The replay suite
covers the approved scenario contract: created tables with full column,
index, and key state; merged multi-migration state; dropped columns with
provenance and events; duplicate create/column conflicts; live-window
scoping and post-window completeness; tombstone demotion after unknown
effects; the run-wide skip cap with reconciled counters; partition
isolation and cross-partition name scoping; conditional create/drop/rename
and column lifecycle candidates; drop/recreate lineage separation; rename
target conflicts; alter-before-create phantoms and the create-over-phantom
case; column rename and `change()` replay with sticky reduced fidelity;
nested unresolved attribution; and container resolution with every database
connection blocked. A feature integration test replays a discovered corpus
end to end through the container bindings.

Formatting (Pint) and maximum-level PHPStan/Larastan pass with zero errors,
baselines, or ignored findings; all 17 findings raised during development
were fixed rather than suppressed. Three new architecture checks keep
reconstruction free of framework/database/parsing/filesystem access and
history events independent of the pipeline. The production smoke replays
its fixture through the full parser, normalizer, and reconstructor stack,
proving the production dependency floor.

## Current limits and next checkpoint

The replay registry and events are internal state, not the final command
JSON envelope; `schema:inspect`, `schema:history`, and `schema:trace`
still report their discovery-only behavior. Resolution-level results
(`TABLE_NOT_FOUND`, `AMBIGUOUS_TABLE_IDENTITY`, caveated not-found,
dropped-table inspect payloads, and trace chains through rename history)
are M6's composition job over this registry and event log. Schema-dump
detection and guard-clause recognition remain M8 scope; table prefixes stay
ignored per the open-questions register.

No framework constraints, runtime dependencies, approved specification
documents, or historical handoffs were changed. No migration execution,
database access, production changes, package publication, commit, or push
was performed during this checkpoint. The next implementation scope is M6:
history timelines, column tracing, and the three command result DTOs.
