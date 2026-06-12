# Changelog

All notable changes to `laravel-serene` will be documented in this file.

## 0.3.0 - 2026-06-12

### Fixed
- **`BugsnagReporter` now drives Bugsnag's error grouping with the resolved group key.**
  Previously the group key (explicit `$key`, `GroupableException::getErrorGroup()`, or the
  auto-generated key) only controlled Serene's throttling — it was attached to Bugsnag as
  display metadata but never set as the grouping hash. Bugsnag therefore grouped Serene-reported
  errors by its default stacktrace algorithm, silently ignoring `getErrorGroup()` and explicit
  keys. The reporter now calls `setGroupingHash()` with the resolved key so all occurrences of a
  group collapse into a single Bugsnag error regardless of stacktrace.

### Added
- New config key `set_grouping_hash` (env `SERENE_REPORTER_SET_GROUPING_HASH`), default `true`.
  Set it to `false` to keep Bugsnag's default stacktrace-based grouping and have the key affect
  throttling only.

### Upgrade notes
- This is a **behavior change**: errors previously reported through Serene will **re-group** in
  Bugsnag once deployed. Existing stacktrace-based errors stop receiving events and new key-based
  errors appear. You may want to mark the superseded Bugsnag errors as fixed after upgrading.
- To preserve the previous behavior, set `set_grouping_hash => false` in `config/serene.php`.
