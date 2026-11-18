# M7 CLI commands and renderers - 18 November 2026

**Status:** Implementation and local verification are complete. The full
local suite, formatting, maximum-level static analysis, strict Composer
validation, and both production runtime smoke scripts pass against the
final source. No commit or push was performed during this checkpoint. The
hosted compatibility matrix re-verifies the milestone on the push that
carries it.

## Implemented scope

- The three commands are real. `schema:inspect`, `schema:history`, and
  `schema:trace` resolve their arguments, run the `Atlas` pipeline for one
  command invocation, and render the result through a text or JSON
  renderer. `table.column` targets are validated before analysis; a
  malformed target is an argument error at exit 1. Discovery and
  path-configuration failures remain command errors with their explanatory
  messages.
- `ExitCode` implements the approved contract: errors take precedence over
  strictness (`0` resolved, `1` errors, `2` incomplete under `--strict`).
  Complete dropped tables exit 0 including strict; conditional migrations,
  skips, and uncertainty exit 2 under strict; reduced fidelity never
  affects codes. Errors are detected from the diagnostics envelope's error
  severity, so future error codes inherit the rule.
- `TextRenderer` follows the approved CLI examples: a status banner with
  reasons split by origin (`direct`/`global`/`conditional`/`conflict`),
  per-column provenance lines (`added by`, `changed by`, `renamed from`,
  `dropped by` with file:line), reduced-fidelity footnotes (`†`),
  conditional event markers (`‡`) with the `shouldRun()` footnote, the
  `DROPPED` banner with last-known state, `INCOMPLETE · existence: …`
  banners, clearly separated lineage sections (earlier dead lineages
  before the primary), candidate/last-known/current definition labels for
  traces, partition hints on not-found results, and an always-visible
  warnings block.
- `JsonRenderer` emits the deterministic machine envelope: stable key
  order, no timestamps, the always-present `global_uncertainty` block with
  ledger sources in replay order, reconciled counters, existence and
  possible names, fidelity, conditional event flags, per-command data
  payloads, and full diagnostics with error severities. Malformed UTF-8
  payloads (binary column defaults) are visibly substituted
  (`JSON_INVALID_UTF8_SUBSTITUTE`) rather than silently transcoded — the
  explicit encoding policy the earlier handoffs reserved for this
  milestone. Two consecutive runs emit byte-identical output.
- `TABLE_NOT_FOUND` now carries the partition hint from the approved
  connection-isolation criterion: when the requested partition lacks the
  table but other partitions hold live identities, the message lists them.
  The no-migrations error envelope keeps any discovery warnings the empty
  scan produced.
- Column renames keep their position in the lineage column map: the
  previous key removal appended the renamed identity at the end, which the
  rendered column ordering exposed. Renamed columns now hold their
  original position with the new current name.
- Architecture checks pin the CLI boundary: renderers only consume result
  DTOs (never the orchestrator, replay, normalization, or discovery
  layers), and commands orchestrate instead of analyzing (no replay,
  history, normalization, or parsing access). All renderers and commands
  remain free of database and code-execution dependencies.

## Verification

The full local suite passes **703 tests / 3,609 assertions**, with the same
two Unix-only discovery regressions skipped on Windows. The new command
rendering suite drives the real console kernel against curated corpora:
complete inspect text with column specs, provenance, and fidelity
footnotes; byte-identical JSON across two runs; dropped tables with
last-known state at exit 0 under strict; conditional results at exit 0/2
with and without `--strict`; errors held at exit 1 even under strict;
caveated not-found envelopes with ledger sources; the skipped-file cap
surfacing through the CLI; partition hints; ambiguous candidates with
lineage identifiers; conditional history markers; both-direction rename
traces through the CLI; dropped-column tombstones with provenance; and
malformed target rejection. The zero-database proof runs the whole
pipeline under the blocked-connection test environment. Command signature,
path-plumbing, and discovery-failure tests were updated to the new
behavior.

Formatting (Pint) and maximum-level PHPStan/Larastan pass with zero errors,
baselines, or ignored findings; the findings raised during development —
including the escaped discovery failure and the column-order defect —
were fixed rather than suppressed.

## Current limits and next checkpoint

Guard-clause recognition (`if (Schema::hasTable(...)) { return; }`), schema
dump detection (`SCHEMA_DUMP_DETECTED` with caveated not-found), the
diagnostic-catalog completeness suite, the support-matrix conformance
suite, the CHANGELOG, and the final security review remain M8, ending with
the full AC-1…AC-26 acceptance sweep. JSON `history_summary` renders the
per-lineage event list; a condensed per-migration rollup stays a rendering
refinement. Column-position modifiers (`after`/`first`) are captured as
data without ordering effects until replay defines them.

No framework constraints, runtime dependencies, approved specification
documents, or historical handoffs were changed. No migration execution,
database access, production changes, package publication, commit, or push
was performed during this checkpoint.
