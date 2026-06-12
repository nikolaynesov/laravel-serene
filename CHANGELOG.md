# Changelog

All notable changes to `laravel-serene` will be documented in this file.

## 0.3.0 - 2026-06-12

### Fixed
- **The resolved group key now drives the error tracker's native grouping.**
  Previously the group key (explicit `$key`, `GroupableException::getErrorGroup()`, or the
  auto-generated key) only controlled Serene's throttling — `BugsnagReporter` attached it as
  display metadata but never set Bugsnag's grouping hash. Bugsnag therefore grouped
  Serene-reported errors by its default stacktrace algorithm, silently ignoring `getErrorGroup()`
  and explicit keys. `BugsnagReporter` now calls `setGroupingHash()` with the resolved key so all
  occurrences of a group collapse into a single error regardless of stacktrace.

### Added
- New config key `group_by_key` (env `SERENE_REPORTER_GROUP_BY_KEY`), default `true`. A
  cross-provider toggle: when enabled, each reporter uses the resolved key to drive its tracker's
  native grouping (Bugsnag's grouping hash; a custom reporter could map it to e.g. Sentry's
  fingerprint; reporters without native grouping ignore it). Set it to `false` to keep the
  tracker's default stacktrace-based grouping and have the key affect throttling only.

### Upgrade notes
- This is a **behavior change**: errors previously reported through Serene will **re-group** in
  your tracker once deployed. Existing stacktrace-based errors stop receiving events and new
  key-based errors appear. You may want to mark the superseded errors as fixed after upgrading.
- To preserve the previous behavior, set `group_by_key => false` in `config/serene.php`.
