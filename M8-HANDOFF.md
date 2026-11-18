# M8 hardening, guard clauses, and documentation - 18 November 2026

**Status:** Implementation and local verification are complete. This is the
final Phase 1 milestone: guard clauses, schema-dump honesty, the diagnostic
catalog and support-matrix conformance suites, the changelog, and the final
security review are done. The full local suite, formatting, maximum-level
static analysis, strict Composer validation, and both production runtime
smoke scripts pass against the final source. No commit or push was
performed during this checkpoint. The hosted compatibility matrix
re-verifies the milestone on the push that carries it.

## Implemented scope

- **Guard clauses (D13):** the exact approved shape —
  `if (Schema::hasTable('<literal>')) { return; }` with a single void
  return, no else branches, and a literal table name — is semantically
  ignored. On a fresh replay the guarded table does not exist yet, so the
  body after the guard runs normally; the guard never taints the region,
  sets an early exit, or emits an unresolved marker. Fully qualified facade
  spellings are recognized through the parser's resolved names. Every
  deviation — dynamic names, negation, extra statements, else branches,
  valued returns, empty bodies — falls back to the conservative unresolved
  path. An unsupported schema call inside a conditional region now carries
  `UNRESOLVED_CONDITIONAL_REGION` instead of `UNRESOLVED_UNKNOWN_METHOD`,
  matching the region semantics the parser already records. The `hasTable`
  registry row flips to implemented.
- **Schema-dump honesty (§8.2):** `SchemaDumpLocator` observes
  `database/schema/*-schema.sql` per connection — names only, contents
  never read. A dump for the requested partition warns
  `SCHEMA_DUMP_DETECTED` on every result, and `TABLE_NOT_FOUND` explains
  that the table may live in the unanalyzed dump rather than being absent
  anywhere. Surviving alters of dump-era tables already surface as
  unknown-origin phantoms from replay. Dump-free partitions stay
  uncaveated, and `DiscoveryOptions` now exposes the validated project
  root the locator reads.
- **Diagnostic catalog completeness:** the 26-code enum is asserted to
  match the approved §7 catalog exactly, and every CLI-reachable code is
  triggered end to end by a dedicated fixture through the real console
  kernel — one executable-documentation case per code. The two codes that
  only arise through injected test doubles (`MIGRATION_PARSE_FAILURE`,
  `MIGRATION_SOURCE_UNREADABLE`) keep their dedicated unit fixtures from
  the parsing milestone; the catalog test pins their enum membership and
  ordering.
- **Support-matrix conformance:** every registry row is asserted to carry
  its approved support level, implementation state, and fidelity stamp —
  SUPPORTED implies implemented at full fidelity, PARTIAL implies
  implemented at reduced fidelity, DEFERRED constructs stay visibly
  unresolved, and the guard shape now normalizes without markers.
- **Final security review:** the architecture suite gains a network/write
  test — package code never opens network sockets and never writes files
  (the read-only source reader uses explicit read-mode handles with size
  caps, unchanged). No include/eval/exec/proc exists anywhere in `src/`,
  no database or facade access exists outside the ban list, reconstruction
  and history stay pure, and renderers consume result DTOs only.
- **Documentation:** the README gains the support matrix, the guard and
  dump behavior, and an honest limitations section; `CHANGELOG.md` records
  the full release history; this handoff completes the checkpoint series.

Two defects surfaced during this milestone's verification and were fixed
at their root rather than suppressed: the not-found error envelope was
dropping the schema-dump warning (the dump was passed to the error message
builder but not the diagnostics builder), and the CLI-reachable dump case
in the catalog suite initially raced the no-migrations early return
(fixed by giving the fixture a real migration, keeping the test honest
about what it exercises).

## Verification

The full local suite passes **884 tests / 4,186 assertions**, with the same
two Unix-only discovery regressions skipped on Windows. New coverage: guard
recognition (exact shape, fully qualified facade, five deviations, and the
empty-body case), dump detection with caveated not-found and per-connection
scoping, the end-to-end acceptance scenarios for AC-6 (live-window with
post-window completeness), AC-7 (raw SQL with caveated not-found), AC-23
(all-files-skipped resolves as caveated `TABLE_NOT_FOUND` with reconciled
counters), AC-25 (conditional destructive identities with strict exits),
and the catalog and conformance suites. The pre-existing suites already
pin AC-1 through AC-5 and AC-8 through AC-22 and AC-24 and AC-26 at their
respective layers; the catalog suite now gives every diagnostic an
executable end-to-end fixture.

Formatting (Pint) and maximum-level PHPStan/Larastan pass with zero errors,
baselines, or ignored findings. Both production runtime smoke scripts pass,
including the full parse-normalize-replay stack under the production
dependency floor.

## Phase 1 conclusion

With M8, every milestone of the approved implementation plan is complete:
discovery, parsing, normalization, reconstruction, history/tracing, the CLI,
and hardening. The acceptance-criteria sweep is executable across the
suite — unit layers for parser/replay rules, orchestrator scenarios for
resolution contracts, and console-kernel runs for the CLI surface, the
exit-code contract, determinism, and the zero-database proof. The package
remains unpublished: tagging and Packagist submission are the natural next
steps, followed by the non-binding post-MVP direction (specification §10).

No framework constraints, runtime dependencies, approved specification
documents, or historical handoffs were changed. No migration execution,
database access, production changes, package publication, commit, or push
was performed during this checkpoint.
