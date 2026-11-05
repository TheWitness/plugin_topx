# Changelog

All notable changes to the TopX plugin are documented in this file.

## 1.0

Modernization for Cacti 1.2.32 standards:

- Security: replaced every string-concatenated SQL query with prepared
  statements, binding the data template id and whitelisting all dynamic
  identifiers (dsstats table, order column, sort direction, record limit).
- Security: all request input is now validated against an allowlist through
  Cacti request helpers (`get_nfilter_request_var()`), and all output is run
  through `html_escape()`/`__esc()`. The filter form uses Cacti's standard
  `html_start_box()`/`get` form so it inherits Cacti's CSRF protection.
- Restructured the plugin API into an `includes/` directory
  (`includes/functions.php`, `includes/arrays.php`) with strongly typed,
  PHPDoc-documented functions.
- `setup.php` now registers the `config_arrays` hook, provisions its schema
  idempotently through `api_plugin_db_table_create()`, seeds default sources
  only when empty, and adds an install/upgrade/`check_config` lifecycle with
  manifest-driven file pruning.
- Internationalization: every user-facing string is wrapped in `__()`/`__esc()`
  with the `topx` text domain. Added the `locales/` tree with
  `build_gettext.sh`, `po/cacti.pot`, and the standard per-language catalogs.
- CSS/JS organization: consolidated the per-theme `themes/*.css` into a single
  theme-neutral `css/topx.css` (CSS custom properties + `currentColor`); moved
  the inline chart initialization into `js/topx.js`, fed by a JSON data island.
- Added a `manifest.json` (with a `themes/` tombstone) plus a Pest test suite
  and the shared CI tooling, GitHub workflows, Copilot instructions, and issue
  and pull request templates used across the Cacti plugin fleet.

## 0.7

- Limit display graph for more than 50 records.
- Add sort by peak.

## 0.6

- Add arithmetical operation again.
- Bug fixes.
- Graph display optimization.

## 0.5

- Add theme support.
- Remove custom data source poller code, using native dsstats.
- Remove arithmetical operation (dsstats did not support this).

## 0.4

- Add Chart.js graphs.
- Add human readable values.
- Fix empty source.

## 0.3

- Rewrite for Cacti 1.x.

## 0.2

- Fixed error - logging too much.

## 0.1

- Beginning.
