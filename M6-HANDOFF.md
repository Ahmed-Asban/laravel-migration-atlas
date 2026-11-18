# M6 history and column tracing - 18 November 2026

**Status:** Implementation and local verification are complete. The full
local suite, formatting, maximum-level static analysis, strict Composer
validation, and both production runtime smoke scripts pass against the
final source. No commit or push was performed during this checkpoint. The
hosted compatibility matrix re-verifies the milestone on the push that
carries it.

## Implemented scope

- `MigrationAtlas\Atlas` is the domain orchestrator: it runs discovery,
  parsing, normalization, and replay for one command invocation and resolves
  a single result DTO. The architecture sketch allowed a nullable
  `DiscoveryOptions`; the implemented signature requires one because there
  is no meaningful default without application context — the command layer
  owns option construction from configuration and overrides. Discovery
  failures propagate to the command layer as before.
- `Support\AnalysisMeta` carries the shared envelope on every result:
  command identity, effective completeness, the always-present
  global-uncertainty rollup (present flag, unattributable-operation and
  skipped-migration counts, ledger sources in replay order), and the
  reconciled counters. `Support\ResultStatus` projects the per-payload
  status: complete, incomplete (which takes precedence over a tombstone),
  or dropped only for certain absence under complete analysis.
- `History\TableHistory` implements the approved resolution algorithm.
  A single present or uncertain candidate resolves; a certain live lineage
  wins over certain tombstones (including drop/recreate); several live or
  uncertain candidates are `AMBIGUOUS_TABLE_IDENTITY` with every candidate
  listed and none silently chosen; tombstone-only matches resolve to the
  latest certain drop by replay position; no match at all is
  `TABLE_NOT_FOUND`, caveated with the run's unresolved operations and
  skipped files — absence is never claimed as proof, and an all-skipped run
  still resolves lookups instead of reporting `NO_MIGRATIONS_FOUND`.
- `History\ColumnTraceResolver` walks a column identity chain through
  rename events in both directions: a former name resolves forward to the
  current name, and tracing by the current name retroactively includes the
  renamed source so the original add still appears. The chain's current
  identity is the one the lineage still holds as a current column name.
  Certain drops terminate the chain with dropped-by provenance; possible
  drops and conditional identities keep candidates and unknown existence.
- The three result DTOs (`Support\InspectResult`, `HistoryResult`,
  `TraceResult`) are presentation-only contracts: resolved data or a null
  payload with preserved envelope, counters, and diagnostics. Inspect
  carries the full state projection with `created_by` (null for unknown-
  origin phantoms), `dropped_by`, last-known columns, indexes, foreign keys,
  and a per-lineage event summary. History groups every lineage that
  carried the requested name — earlier dead identities by drop order, the
  primary identity last — each with its state axes and event timeline;
  `current_name` is null when uncertain. Trace carries the requested and
  resolved identities, `exists` (true/false only for certainty, null when
  unknown), observed possible names, unioned reasons, the candidate or
  last-known definition, the chain events, and dropped-by.
- Column existence incorporates the containing table: a certainly absent
  table makes its current columns absent while retaining their last-known
  definitions; an uncertain table keeps column existence unknown. A
  renaming bug surfaced during tracing and was fixed at its root: column
  renames now remove the old current-name key from the lineage's column
  map instead of leaving a stale second identity.
- Replay now records which lineages each event touched (`lineageEvents` on
  the reconstruction result), so history grouping never re-runs replay
  logic. Multi-candidate bodies announce events once for the operation
  stream but associate them with every compatible candidate's timeline.
  Rename events list the source identity first and the target second;
  column-level events carry their touched identities for chain walking.
- Four result-level diagnostic codes join the catalog per the approved
  list: `NO_MIGRATIONS_FOUND`, `TABLE_NOT_FOUND`, `COLUMN_NOT_FOUND`, and
  `AMBIGUOUS_TABLE_IDENTITY`, all at error severity. Empty discovery is
  `NO_MIGRATIONS_FOUND` with a zeroed envelope; every error result keeps
  the analysis meta and the carried diagnostics.

## Verification

The full local suite passes **686 tests / 3,466 assertions**, with the same
two Unix-only discovery regressions skipped on Windows. New coverage pins
the resolution algorithm (single candidate, live-over-tombstone, latest
tombstone, not-found, ambiguous, former/possible names) and the end-to-end
command assembly through the container against curated corpora: created
tables, dropped-table inspect payloads, clean and caveated not-found
envelopes, ambiguous candidates with lineage identifiers, the skipped-file
cap surviving result assembly, conditional history events, drop/recreate
lineage separation with the live identity last, former-name history
resolution, both-direction rename chains with provenance, certain-drop
termination, dropped-table column traces with last-known definitions,
conditional columns as unknown existence, unknown columns as
`COLUMN_NOT_FOUND`, and `NO_MIGRATIONS_FOUND` envelopes.

Formatting (Pint) and maximum-level PHPStan/Larastan pass with zero errors,
baselines, or ignored findings; all 49 findings raised during development
were fixed rather than suppressed, including the stale column-key state bug
that tracing exposed. The architecture checks continue to keep
reconstruction and history pure; the orchestrator itself only composes
already-analyzed data.

## Current limits and next checkpoint

The commands still report their discovery-only behavior; wiring them to
these results, the text and JSON renderers, and the exit-code semantics
(`0` resolved, `1` errors, `2` incomplete under `--strict`) are the CLI
milestone. Reason codes on error results currently flow from the ledger
and replay state only; the schema-dump caveat lands with dump detection in
the hardening milestone. The trace envelope unions table-level reasons
with column-level uncertainty; per-column reason attribution stays a
recorded simplification because conditional column operations mark their
lineage.

No framework constraints, runtime dependencies, approved specification
documents, or historical handoffs were changed. No migration execution,
database access, production changes, package publication, commit, or push
was performed during this checkpoint. The next implementation scope is M7:
the CLI commands and renderers.
