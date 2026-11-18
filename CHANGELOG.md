# Changelog

All notable changes to Laravel Migration Atlas are documented here. The
format follows [Keep a Changelog](https://keepachangelog.com/) and the
project adheres to [Semantic Versioning](https://semver.org/). No release
has been tagged yet; entries describe the development history on `main`.

## [Unreleased]

### Added — M8 hardening (18 November 2026)

- Narrow guard-clause recognition: the exact
  `if (Schema::hasTable('<literal>')) { return; }` shape is semantically
  ignored during replay; every deviation stays conservatively unresolved.
- Schema-dump honesty: `database/schema/<connection>-schema.sql` detection
  emits `SCHEMA_DUMP_DETECTED`, caveats `TABLE_NOT_FOUND` with the dump
  reference, and never reads or parses the dump contents.
- Diagnostic catalog completeness suite: the enum is kept in lockstep with
  the approved catalog and every CLI-reachable code is triggered end to end
  by a dedicated fixture.
- Support-matrix conformance suite: every SUPPORTED/PARTIAL row carries its
  approved fidelity stamp, deferred constructs stay visibly unresolved, and
  the exact guard shape normalizes without markers.
- A network/write architecture test: package code never opens network
  connections or writes files.

### Added — M7 CLI (18 November 2026)

- Working commands: `schema:inspect`, `schema:history`, and `schema:trace`
  run the full static pipeline with validated arguments and shared options.
- Exit-code contract: `0` resolved (including complete dropped tables),
  `1` errors taking precedence over strictness, `2` incomplete under
  `--strict`; reduced fidelity never affects codes.
- Text renderer: status banners split by reason origin, per-column
  provenance, fidelity footnotes, conditional markers, dropped banners,
  separated lineage sections, and partition hints.
- Deterministic JSON envelope: stable key order, no timestamps, the
  always-present global-uncertainty block, ledger sources, reconciled
  counters, and visibly substituted malformed UTF-8 payloads.

### Added — M6 history and traces (18 November 2026)

- `Atlas` orchestrator producing presentation-ready inspect, history, and
  trace results with the shared analysis envelope.
- Approved table resolution: single candidates resolve, certain live wins
  over tombstones, ambiguous identities list every candidate, latest
  tombstones resolve, unmatched names become caveated errors.
- Column chains through renames in both directions with dropped-by
  provenance and combined table/column existence.

### Added — M5 reconstruction (18 November 2026)

- Pure replay engine: partition-aware table registry, four-axis state
  model (status, existence, name certainty, sticky fidelity), lineage
  tracking, conflict diagnostics, conditional candidates, attribution
  uncertainty, live-window rules, and whole-file skip caps.

### Added — M4 normalization (18 November 2026)

- Index and foreign-key operations with Laravel-exact convention naming.
- `foreignId()->constrained()` with framework-exact pluralization;
  `foreignIdFor` with statically resolved class literals.
- Tier-2 columns, increments, expansions, partial modifiers, dynamic
  default markers, and `change()` events at reduced fidelity.

### Added — M3 normalization (18 November 2026)

- Typed table operations and Tier-1 columns/modifiers at full fidelity
  with connection routing, source locations, and conditional execution.

### Added — M2 parsing (21 October 2026)

- Static AST parsing that never executes migration source: guarded reads,
  fluent-chain destructuring, connection context, `shouldRun()` detection,
  and conservative unresolved markers.

### Added — M1 discovery and M0 scaffolding (21 October 2026)

- Deterministic non-recursive migration discovery with duplicate
  precedence, filename diagnostics, and stable ordinals.
- Package integration: auto-discovery, publishable configuration, command
  signatures, and the quality toolchain.

### Fixed

- CI workflow startup failure: a job-level `runner.temp` expression was
  rejected by GitHub before any job started (18 November 2026).
- Column renames left a stale current-name key in lineage column maps,
  exposed by rendered column ordering (18 November 2026).
- Rename chain traces by the current name missed the original add event
  until the chain walk learned to extend backwards (18 November 2026).
