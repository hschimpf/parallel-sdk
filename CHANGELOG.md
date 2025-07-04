# Changelog

All notable changes to **parallel-sdk** are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## `3.0.0` – 2025-07-04

### Added
- **Domain-specific exception hierarchy**  
  `ActionNotImplementedException`, `InvalidMessageReceivedException`, `NoWorkerDefinedException`, `TaskExecutionFailedException`, `WorkerAlreadyDefinedException`, `WorkerNotDefinedException`, plus a `ParallelException` value object to serialise task-side errors.
- `Scheduler::runTask()` now surfaces worker exceptions through `TaskExecutionFailedException` for clearer diagnostics.
- Adopted modern PHP 8.2 language features:  
  * `readonly` properties and promoted constructor parameters  
  * Precise return-type hints (e.g. `void` on closures)  
  * `match`-style strict comparisons and assorted PSR-12 tidy-ups.

### Changed
- **BC-BREAK:** Minimum supported PHP version raised from 8.0 → **8.2**.  
  Consumers on <8.2 will remain on the `2.x` series.
- `Scheduler::using()` now throws `WorkerAlreadyDefinedException` when attempting to re-register an existing worker with constructor args.
- Error bubbling in `Scheduler::runTask()` changed from `RuntimeException` with string message to the typed `TaskExecutionFailedException`.

### Removed
- CI workflows for PHP 8.0 & 8.1 ‒ the suite now runs on 8.2/8.3/8.4.
- Legacy catch-all `RuntimeException` branches replaced with specific domain exceptions.

### Fixed
- Sporadic race-condition when starting the runner under PHP ≥ 8.1 by ensuring a micro-sleep and event handshake.
- Several docblock inaccuracies and progress-bar initialisation edge cases.

---

## [2.1.4] – 2024-xx-xx
_Refer to Git history for details prior to the 3.x line._
