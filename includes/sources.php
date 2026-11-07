<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group, Inc.                           |
 | Copyright (C) 2015-2026 Petr Macek                                      |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | https://github.com/xmacan/plugin_topx                                   |
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/**
 * Returns the ordered list of plugin_topx_source columns that the management
 * UI, importer and exporter round-trip. Excludes the surrogate 'id' primary
 * key, which is install-local and never exported. Kept free of database access
 * so it can be unit tested directly.
 *
 * @return string[] The managed column names in a stable, export-friendly order.
 */
function topx_source_columns(): array {
	return [
		'sorting', 'dt_name', 'hash', 'operation', 'unit',
		'final_operation', 'final_unit', 'final_number', 'final_decimals', 'system',
		'range_type', 'range_normal', 'range_warning', 'range_alert', 'chart_label',
	];
}

/**
 * Returns a blank source definition pre-populated with sensible defaults, used
 * to seed the Add form. Kept free of database access so it can be unit tested.
 *
 * @return array A new, unsaved source row (id 0).
 */
function topx_source_blank(): array {
	return [
		'id'              => 0,
		'sorting'         => 'desc',
		'dt_name'         => '',
		'hash'            => '',
		'operation'       => '',
		'unit'            => '',
		'final_operation' => 'strip',
		'final_unit'      => '',
		'final_number'    => '',
		'final_decimals'  => '',
		'system'          => 'decimal',
		'range_type'      => 0,
		'range_normal'    => '',
		'range_warning'   => '',
		'range_alert'     => '',
		'chart_label'     => '',
	];
}

/**
 * Returns the translated labels for the range_type column. range_type captures
 * the semantic the original plugin's per-source 'sorting' only approximated:
 * whether a higher value is the more significant one (increasing, e.g. CPU or
 * traffic) or a lower value is (decreasing, e.g. free disk space or uptime
 * headroom). Built at call time so the labels pick up the active locale.
 *
 * @return array<int,string> Map of range_type value to translated label.
 */
function topx_range_type_labels(): array {
	return [
		0 => __('Increasing - higher is more significant', 'topx'),
		1 => __('Decreasing - lower is more significant', 'topx'),
	];
}

/**
 * Returns the translated 'system' (display scaling) options. Kept free of
 * database access so it can be unit tested directly.
 *
 * @return array<string,string> Map of system token to translated label.
 */
function topx_source_system_labels(): array {
	return [
		'decimal' => __('Decimal (SI, base 1000)', 'topx'),
		'binary'  => __('Binary (base 1024)', 'topx'),
		'bits'    => __('Bits', 'topx'),
	];
}

/**
 * Returns the translated Decimal Places options for the editor dropdown. The
 * empty key is "Auto" (two places); 0-4 are explicit decimal places. Kept free
 * of database access so it can be unit tested directly.
 *
 * @return array<string,string> Map of decimal-places token to translated label.
 */
function topx_source_decimal_labels(): array {
	return [
		''  => __('Auto', 'topx'),
		'0' => __('0', 'topx'),
		'1' => __('1', 'topx'),
		'2' => __('2', 'topx'),
		'3' => __('3', 'topx'),
		'4' => __('4', 'topx'),
	];
}

/**
 * Substitutes Cacti "suggested value" style tokens in a chart label pattern
 * with their resolved values. Tokens take the pipe-delimited form |token| and
 * are matched case-insensitively against the supplied map (e.g.
 * 'host_description', 'query_ifName'). Any unresolved token is removed and the
 * resulting dangling separators/whitespace are tidied, so an interface pattern
 * such as "|host_description| - |query_ifName|" degrades cleanly to just the
 * host when the interface field is unavailable. Kept free of database access so
 * it can be unit tested directly.
 *
 * @param string $pattern The chart label pattern containing |token| markers.
 * @param array  $tokens  Map of token name (any case) to replacement string.
 *
 * @return string The resolved label, or '' when the pattern is empty or every
 *                token resolved away.
 */
function topx_apply_chart_label(string $pattern, array $tokens): string {
	if (trim($pattern) === '') {
		return '';
	}

	$map = [];

	foreach ($tokens as $key => $value) {
		$map[strtolower((string) $key)] = (string) $value;
	}

	$out = preg_replace_callback('/\|([a-zA-Z0-9_]+)\|/', function ($matches) use ($map) {
		$key = strtolower($matches[1]);

		return array_key_exists($key, $map) ? $map[$key] : '';
	}, $pattern);

	$out = (string) $out;

	// Collapse runs of whitespace and tidy separators orphaned by empty tokens.
	$out = preg_replace('/\s+/', ' ', $out);
	$out = preg_replace('/(\s*[-:\/]\s*){2,}/', ' - ', (string) $out);
	$out = trim((string) $out);
	$out = trim($out, " \t-:/");

	return trim($out);
}

/**
 * Classifies a value against a source's absolute health thresholds, returning
 * the band it falls in: 'alert', 'warning' or 'normal'. Respects range_type so
 * a decreasing source (lower is worse, e.g. free space) is compared with the
 * sense inverted. The healthy (normal) band runs from the best end up to the
 * Warning threshold: for an increasing source every value below Warning is
 * green, for a decreasing source every value above Warning is green, so a value
 * sitting between the Normal target and the Warning threshold reads as healthy.
 * The Normal threshold is the healthy target and only acts as the warning
 * boundary when no explicit Warning threshold is configured, so it still has an
 * effect on its own without overriding a Warning that is set. Returns '' when
 * the source defines no thresholds, so callers can fall back to a neutral
 * colour. This is deliberately separate from the dashboard's "% of peak"
 * distribution, which is relative to the live peak of the returned set;
 * thresholds express an absolute, operator-defined health band instead. Kept
 * free of database access so it can be unit tested.
 *
 * @param float $value  The value to classify.
 * @param array $source A source row carrying range_type and range_* thresholds.
 *
 * @return string 'alert', 'warning', 'normal' or '' when no thresholds are set.
 */
function topx_range_class(float $value, array $source): string {
	$decreasing = (int) ($source['range_type'] ?? 0) === 1;

	$has = static function ($v): bool {
		return $v !== null && $v !== '';
	};

	$warning = $source['range_warning'] ?? null;
	$alert   = $source['range_alert'] ?? null;
	$normal  = $source['range_normal'] ?? null;

	if (!$has($warning) && !$has($alert) && !$has($normal)) {
		return '';
	}

	if ($decreasing) {
		if ($has($alert) && $value <= (float) $alert) {
			return 'alert';
		}

		if ($has($warning) && $value <= (float) $warning) {
			return 'warning';
		}

		// Normal is the warning boundary only when no Warning threshold is set;
		// otherwise everything above Warning stays healthy (green).
		if (!$has($warning) && $has($normal) && $value < (float) $normal) {
			return 'warning';
		}

		return 'normal';
	}

	if ($has($alert) && $value >= (float) $alert) {
		return 'alert';
	}

	if ($has($warning) && $value >= (float) $warning) {
		return 'warning';
	}

	// Normal is the warning boundary only when no Warning threshold is set;
	// otherwise everything below Warning stays healthy (green).
	if (!$has($warning) && $has($normal) && $value > (float) $normal) {
		return 'warning';
	}

	return 'normal';
}

/**
 * Maps a health band returned by topx_range_class() to a bar colour for the
 * dashboard chart. Kept free of database access so it can be unit tested.
 *
 * @param string $class The band: 'normal', 'warning' or 'alert'.
 *
 * @return string A CSS colour, or '' for an unknown band.
 */
function topx_range_color(string $class): string {
	$colors = [
		'normal'  => '#2ecc71',
		'warning' => '#f0932b',
		'alert'   => '#eb4d4b',
	];

	return $colors[$class] ?? '';
}

/**
 * Maps a health band to a muted, darker tint of its topx_range_color() so the
 * dashboard's peak "range band" is mildly colourised by the peak's own band
 * (normal/warning/alert) instead of a flat grey, while staying subtle enough to
 * read as a background behind the solid average measure bar. Kept free of
 * database access so it can be unit tested.
 *
 * @param string $class The band: 'normal', 'warning' or 'alert'.
 *
 * @return string A CSS colour, or '' for an unknown band.
 */
function topx_range_peak_color(string $class): string {
	$colors = [
		'normal'  => '#19703e',
		'warning' => '#845118',
		'alert'   => '#812a29',
	];

	return $colors[$class] ?? '';
}

/**
 * Whether a source defines a usable health range, i.e. at least one of its
 * Normal/Warning/Alert thresholds is set to a non-blank, non-zero value. A
 * source with no thresholds (or all left blank/zero) has no health bands, so the
 * ranking chart should fall back to a plain Peak/Average bullet rather than
 * colouring every bar as an alert. Kept free of database access so it can be
 * unit tested.
 *
 * @param array $source A source row carrying range_* thresholds.
 *
 * @return bool True when at least one threshold carries a usable value.
 */
function topx_source_has_range(array $source): bool {
	foreach (['range_normal', 'range_warning', 'range_alert'] as $key) {
		$value = $source[$key] ?? null;

		if ($value !== null && $value !== '' && (float) $value != 0.0) {
			return true;
		}
	}

	return false;
}

/**
 * The solid measure-bar colour used for the ranking chart's Average series when
 * a source has no health range. A neutral, theme-legible blue that avoids the
 * green/orange/red health connotation. Kept free of database access so it can be
 * unit tested.
 *
 * @return string A CSS colour.
 */
function topx_chart_plain_avg_color(): string {
	return '#4a9fe0';
}

/**
 * The background range-band colour used for the ranking chart's Peak series when
 * a source has no health range: a darker tint of the plain Average colour so the
 * peak band stays visible behind the Average measure bar on every theme. Kept
 * free of database access so it can be unit tested.
 *
 * @return string A CSS colour.
 */
function topx_chart_plain_peak_color(): string {
	return '#285f8f';
}

/**
 * Normalises raw request/import input into a validated source row ready to be
 * written to plugin_topx_source. Clamps enumerated fields to their allowlists,
 * coerces range_type to 0/1 and converts empty thresholds to SQL NULL. Kept
 * free of database access so it can be unit tested directly.
 *
 * @param array $input The raw field map (from a form post or an import row).
 *
 * @return array The sanitised row keyed by column name plus 'id'.
 */
function topx_source_prepare_save(array $input): array {
	$systems = topx_source_system_labels();

	$save = [
		'id'              => isset($input['id']) ? (int) $input['id'] : 0,
		'sorting'         => (($input['sorting'] ?? 'desc') === 'asc') ? 'asc' : 'desc',
		'dt_name'         => trim((string) ($input['dt_name'] ?? '')),
		'hash'            => trim((string) ($input['hash'] ?? '')),
		'operation'       => trim((string) ($input['operation'] ?? '')),
		'unit'            => trim((string) ($input['unit'] ?? '')),
		'final_operation' => (($input['final_operation'] ?? 'strip') === '/') ? '/' : 'strip',
		'final_unit'      => trim((string) ($input['final_unit'] ?? '')),
		'final_number'    => trim((string) ($input['final_number'] ?? '')),
		'final_decimals'  => in_array((string) ($input['final_decimals'] ?? ''), ['0', '1', '2', '3', '4'], true) ? (string) $input['final_decimals'] : '',
		'system'          => isset($systems[$input['system'] ?? '']) ? (string) $input['system'] : 'decimal',
		'range_type'      => ((int) ($input['range_type'] ?? 0) === 1) ? 1 : 0,
		'chart_label'     => trim((string) ($input['chart_label'] ?? '')),
	];

	foreach (['range_normal', 'range_warning', 'range_alert'] as $key) {
		$value      = $input[$key] ?? '';
		$save[$key] = ($value === '' || $value === null) ? null : (float) $value;
	}

	return $save;
}

/**
 * Serialises a set of source rows to a portable XML export document keyed on
 * the Cacti data template hash, mirroring how Cacti templates travel between
 * installs. Only the managed columns are emitted; the install-local 'id' is
 * omitted. Kept free of database access so it can be unit tested directly.
 *
 * @param array $rows The source rows to export.
 *
 * @return string The XML export document.
 */
function topx_source_export_xml(array $rows): string {
	$doc               = new DOMDocument('1.0', 'UTF-8');
	$doc->formatOutput = true;

	$root = $doc->createElement('topx_data_sources');
	$root->setAttribute('version', '1.1');
	$doc->appendChild($root);

	$columns = topx_source_columns();

	foreach ($rows as $row) {
		$node = $doc->createElement('source');

		foreach ($columns as $column) {
			// Null/missing values coalesce to an empty element.
			$value = $row[$column] ?? '';

			$child = $doc->createElement($column);
			$child->appendChild($doc->createTextNode((string) $value));
			$node->appendChild($child);
		}

		$root->appendChild($node);
	}

	return (string) $doc->saveXML();
}

/**
 * Parses a TopX XML export document back into an array of source rows. Entity
 * loading is left at the libxml default (external entities disabled) and
 * network access is forbidden, so the parser is not exposed to XXE. Kept free
 * of database access so it can be unit tested directly.
 *
 * @param string $xml The XML export document.
 *
 * @return array{sources: array, error: string} The parsed rows and an error
 *               message (empty on success).
 */
function topx_source_import_xml(string $xml): array {
	$xml = trim($xml);

	if ($xml === '') {
		return ['sources' => [], 'error' => __('The import document is empty.', 'topx')];
	}

	$previous = libxml_use_internal_errors(true);
	$sx       = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
	libxml_clear_errors();
	libxml_use_internal_errors($previous);

	if ($sx === false) {
		return ['sources' => [], 'error' => __('The import document is not valid XML.', 'topx')];
	}

	if ($sx->getName() !== 'topx_data_sources') {
		return ['sources' => [], 'error' => __('The import document is not a TopX Data Source export.', 'topx')];
	}

	$columns = topx_source_columns();
	$sources = [];

	foreach ($sx->source as $element) {
		$row = [];

		foreach ($columns as $column) {
			$row[$column] = isset($element->$column) ? (string) $element->$column : '';
		}

		if ($row['hash'] === '') {
			continue;
		}

		$sources[] = topx_source_prepare_save($row);
	}

	if (cacti_sizeof($sources) === 0) {
		return ['sources' => [], 'error' => __('The import document contains no Data Sources.', 'topx')];
	}

	return ['sources' => $sources, 'error' => ''];
}

/**
 * Fetches a single source definition by id.
 *
 * @param int $id The plugin_topx_source id.
 *
 * @return array The source row, or an empty array when not found.
 */
function topx_get_source(int $id): array {
	$row = db_fetch_row_prepared('SELECT * FROM plugin_topx_source WHERE id = ?', array($id));

	return is_array($row) ? $row : [];
}

/**
 * Writes a prepared source row to plugin_topx_source, inserting when id is 0
 * and updating otherwise. Uses Cacti's sql_save so the column/value mapping is
 * bound, never concatenated. Cacti's sql_save() coerces an empty nullable
 * numeric column to 0 rather than SQL NULL, so the optional range_* thresholds
 * are restored to NULL afterwards; otherwise a blank threshold would read back
 * as 0 and classify every non-negative value as alert.
 *
 * @param array $save A row produced by topx_source_prepare_save().
 *
 * @return int|false The affected row id, or false on failure.
 */
function topx_source_save(array $save) {
	$id = sql_save($save, 'plugin_topx_source');

	if ($id) {
		$nulls = [];

		foreach (['range_normal', 'range_warning', 'range_alert'] as $column) {
			$value = $save[$column] ?? null;

			if ($value === null || $value === '') {
				$nulls[] = "`$column` = NULL";
			}
		}

		if (cacti_sizeof($nulls) > 0) {
			db_execute_prepared('UPDATE plugin_topx_source SET ' . implode(', ', $nulls) . ' WHERE id = ?', array($id));
		}
	}

	return $id;
}

/**
 * Deletes the given source definitions by id using a bound IN () clause.
 *
 * @param array $ids The plugin_topx_source ids to remove.
 *
 * @return void
 */
function topx_source_remove(array $ids): void {
	$ids = array_values(array_filter(array_map('intval', $ids)));

	if (cacti_sizeof($ids) === 0) {
		return;
	}

	$placeholders = implode(', ', array_fill(0, cacti_sizeof($ids), '?'));

	db_execute_prepared("DELETE FROM plugin_topx_source WHERE id IN ($placeholders)", $ids);
}

/**
 * Builds the data template choices for the edit form's template picker, keyed
 * by the portable template hash (what the source table stores). Each label is
 * annotated with how many data sources currently use the template so the
 * administrator can see in-use versus unused templates at a glance.
 *
 * @return array<string,string> Map of template hash to annotated display name.
 */
function topx_source_template_choices(): array {
	$rows = db_fetch_assoc("SELECT dt.hash, dt.name,
		SUM(CASE WHEN dtd.local_data_id > 0 THEN 1 ELSE 0 END) AS in_use
		FROM data_template AS dt
		LEFT JOIN data_template_data AS dtd ON dt.id = dtd.data_template_id
		WHERE dt.hash != ''
		GROUP BY dt.id, dt.hash, dt.name
		ORDER BY dt.name");

	$choices = [];

	foreach ($rows as $row) {
		$count = (int) $row['in_use'];

		$suffix = $count > 0
			? __('(%d in use)', $count, 'topx')
			: __('(unused)', 'topx');

		$choices[$row['hash']] = $row['name'] . ' ' . $suffix;
	}

	return $choices;
}

/**
 * The static per-host chart-label replacement tokens (without the surrounding
 * pipes). These apply to every data source regardless of type, so they are
 * always offered in the editor's Chart Label helper. Kept free of database
 * access so it can be unit tested directly.
 *
 * @return string[] The bare host replacement token names.
 */
function topx_host_replacements(): array {
	return ['host_description', 'host_hostname', 'host_id'];
}

/**
 * Returns the RRD data source field names defined by the Data Template with the
 * given hash (e.g. traffic_in, traffic_out), so the editor can offer them as
 * clickable building blocks for the Metric Expression. Reads the template
 * master rows (local_data_id = 0 is implied by joining the template directly).
 *
 * @param string $hash The portable Data Template hash.
 *
 * @return string[] The distinct data source field names, ordered by name.
 */
function topx_source_rrd_fields(string $hash): array {
	if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
		return [];
	}

	$rows = db_fetch_assoc_prepared("SELECT DISTINCT dtr.data_source_name AS field
		FROM data_template_rrd AS dtr
		INNER JOIN data_template AS dt ON dtr.data_template_id = dt.id
		WHERE dt.hash = ? AND dtr.data_source_name != ''
		ORDER BY dtr.data_source_name",
		array($hash));

	$fields = [];

	foreach ($rows as $row) {
		$fields[] = (string) $row['field'];
	}

	return $fields;
}

/**
 * Returns the SNMP data-query field names available to the Data Sources of the
 * template with the given hash (e.g. ifAlias, ifName), so the editor can offer
 * |query_<field>| tokens for the Chart Label. Empty for templates that are not
 * data-query based or that have no cached instances.
 *
 * @param string $hash The portable Data Template hash.
 *
 * @return string[] The distinct data-query field names, ordered by name.
 */
function topx_source_query_fields(string $hash): array {
	if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
		return [];
	}

	$rows = db_fetch_assoc_prepared("SELECT DISTINCT hsc.field_name AS field
		FROM data_local AS dl
		INNER JOIN data_template AS dt ON dl.data_template_id = dt.id
		INNER JOIN host_snmp_cache AS hsc ON dl.host_id = hsc.host_id AND dl.snmp_query_id = hsc.snmp_query_id
		WHERE dt.hash = ? AND dl.snmp_query_id > 0 AND hsc.field_name != ''
		ORDER BY hsc.field_name",
		array($hash));

	$fields = [];

	foreach ($rows as $row) {
		$fields[] = (string) $row['field'];
	}

	return $fields;
}

/**
 * Builds the editor "token help" payload for a Data Template hash: the RRD field
 * names usable in the Metric Expression, the always-available host replacement
 * tokens, and (for data-query templates) the data-query field names usable as
 * |query_<field>| Chart Label tokens. Shared by the initial server render and
 * the AJAX refresh that fires when the template selection changes.
 *
 * @param string $hash The portable Data Template hash.
 *
 * @return array{rrd: string[], host: string[], query: string[]} The token sets.
 */
function topx_token_help(string $hash): array {
	return [
		'rrd'   => topx_source_rrd_fields($hash),
		'host'  => topx_host_replacements(),
		'query' => topx_source_query_fields($hash),
	];
}

/**
 * Returns a sensible default Metric Expression for a template when setting up a
 * new mapping. A template with a single RRD data source needs a "field=field"
 * expression to rank at all (as the user discovered with users=users), so that
 * is defaulted automatically. Multi-field templates return '' because the
 * administrator must choose how to combine or pick the fields.
 *
 * @param string $hash The portable Data Template hash.
 *
 * @return string The default expression, or '' when it cannot be inferred.
 */
function topx_default_operation(string $hash): string {
	$fields = topx_source_rrd_fields($hash);

	if (cacti_sizeof($fields) === 1) {
		return $fields[0] . '=' . $fields[0];
	}

	return '';
}

/**
 * Whether a Metric Expression is one the ranking engine can actually execute,
 * so the editor and importer can reject gibberish that would otherwise save but
 * return no ranked rows. The executable shapes are: a single field ("field",
 * "field=field", "field/N" or "field=field/N"), a two-field sum ("A+B") and a
 * two-field percentage ("A%B"). Kept free of database access so it can be unit
 * tested directly.
 *
 * @param string $operation The Metric Expression.
 *
 * @return bool True when the engine can execute the expression.
 */
function topx_operation_is_executable(string $operation): bool {
	$operation = trim($operation);

	if ($operation === '') {
		return false;
	}

	$field = '[A-Za-z0-9_]+';

	$patterns = array(
		"~^{$field}(={$field})?(/[0-9]*\.?[0-9]+)?$~",
		"~^{$field}\+{$field}$~",
		"~^{$field}%{$field}$~",
	);

	foreach ($patterns as $pattern) {
		if (preg_match($pattern, $operation)) {
			return true;
		}
	}

	return false;
}

/**
 * Validates a definition's range thresholds against its direction and returns a
 * human readable error (empty string when valid). For an increasing range the
 * set thresholds must ascend Normal < Warning < Alert; for a decreasing range
 * they must descend Normal > Warning > Alert. Blank thresholds are skipped, so
 * only the thresholds that are actually set are ordered. Kept free of database
 * access so it can be unit tested directly.
 *
 * @param array $save A prepared row carrying range_type and range_* thresholds.
 *
 * @return string The validation error, or '' when the thresholds are consistent.
 */
function topx_range_validation_error(array $save): string {
	$decreasing = (int) ($save['range_type'] ?? 0) === 1;

	$order = [
		'range_normal'  => __('Normal', 'topx'),
		'range_warning' => __('Warning', 'topx'),
		'range_alert'   => __('Alert', 'topx'),
	];

	$points = [];

	foreach ($order as $key => $label) {
		$value = $save[$key] ?? null;

		if ($value !== null && $value !== '') {
			$points[] = ['label' => $label, 'value' => (float) $value];
		}
	}

	for ($i = 1; $i < cacti_sizeof($points); $i++) {
		$prev = $points[$i - 1];
		$cur  = $points[$i];

		if (!$decreasing && $prev['value'] >= $cur['value']) {
			return __('For an increasing range, the %1$s threshold must be less than the %2$s threshold.', $prev['label'], $cur['label'], 'topx');
		}

		if ($decreasing && $prev['value'] <= $cur['value']) {
			return __('For a decreasing range, the %1$s threshold must be greater than the %2$s threshold.', $prev['label'], $cur['label'], 'topx');
		}
	}

	return '';
}

/**
 * Resolves the chart-label substitution tokens for a set of ranked data source
 * instances in bulk, in two queries regardless of how many rows are supplied
 * (instead of a query per row). Returns host-level tokens (host_description,
 * host_hostname, host_id) plus one query_<field> token per SNMP data-query
 * field cached for each instance. Thin database wrapper.
 *
 * @param array $local_data_ids The Cacti local data source ids of the rows.
 *
 * @return array<int,array<string,string>> Token map keyed by local_data_id.
 */
function topx_chart_label_tokens_bulk(array $local_data_ids): array {
	$ids = array_values(array_unique(array_filter(array_map('intval', $local_data_ids))));

	if (cacti_sizeof($ids) === 0) {
		return [];
	}

	$placeholders = implode(', ', array_fill(0, cacti_sizeof($ids), '?'));

	$meta = db_fetch_assoc_prepared("SELECT dl.id AS ldid, h.description AS host_description,
		h.hostname AS host_hostname, h.id AS host_id, dl.snmp_query_id, dl.snmp_index
		FROM data_local AS dl
		LEFT JOIN host AS h ON dl.host_id = h.id
		WHERE dl.id IN ($placeholders)",
		$ids);

	$tokens      = [];
	$query_ldids = [];
	$tuples      = [];

	foreach ($meta as $row) {
		$ldid = (int) $row['ldid'];

		$tokens[$ldid] = [
			'host_description' => (string) ($row['host_description'] ?? ''),
			'host_hostname'    => (string) ($row['host_hostname'] ?? ''),
			'host_id'          => (string) ($row['host_id'] ?? ''),
		];

		if (!empty($row['snmp_query_id']) && isset($row['snmp_index']) && $row['snmp_index'] !== '') {
			$key = $row['host_id'] . '|' . $row['snmp_query_id'] . '|' . $row['snmp_index'];

			if (!isset($tuples[$key])) {
				$tuples[$key] = [$row['host_id'], $row['snmp_query_id'], $row['snmp_index']];
			}

			$query_ldids[$key][] = $ldid;
		}
	}

	if (cacti_sizeof($tuples) > 0) {
		$row_values = implode(', ', array_fill(0, cacti_sizeof($tuples), '(?, ?, ?)'));
		$params     = [];

		foreach ($tuples as $tuple) {
			$params[] = $tuple[0];
			$params[] = $tuple[1];
			$params[] = $tuple[2];
		}

		$fields = db_fetch_assoc_prepared("SELECT host_id, snmp_query_id, snmp_index, field_name, field_value
			FROM host_snmp_cache
			WHERE (host_id, snmp_query_id, snmp_index) IN ($row_values)",
			$params);

		foreach ($fields as $field) {
			$key = $field['host_id'] . '|' . $field['snmp_query_id'] . '|' . $field['snmp_index'];

			foreach (($query_ldids[$key] ?? []) as $ldid) {
				$tokens[$ldid]['query_' . $field['field_name']] = (string) $field['field_value'];
			}
		}
	}

	return $tokens;
}

/**
 * Resolves the chart-label substitution tokens for a single ranked data source
 * instance. Thin wrapper around topx_chart_label_tokens_bulk().
 *
 * @param int $local_data_id The Cacti local data source id of the ranked row.
 *
 * @return array<string,string> The token map for topx_apply_chart_label().
 */
function topx_chart_label_tokens(int $local_data_id): array {
	$map = topx_chart_label_tokens_bulk(array($local_data_id));

	return $map[$local_data_id] ?? [];
}

/**
 * Applies a set of imported/prepared source rows to the database, matching each
 * by its template hash. Rows whose hash matches a data template present on this
 * install update the existing source (or insert a new one); rows whose hash is
 * not present on this install are skipped and reported, so an export from one
 * system imports cleanly onto another without creating orphan definitions.
 *
 * @param array $sources Rows produced by topx_source_import_xml().
 *
 * @return array{inserted: int, updated: int, skipped: int, skipped_hashes: array}
 *               A summary of what the import changed.
 */
function topx_import_apply(array $sources): array {
	$summary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'skipped_hashes' => []];

	foreach ($sources as $source) {
		$hash = (string) ($source['hash'] ?? '');

		if ($hash === '' || !db_fetch_cell_prepared('SELECT id FROM data_template WHERE hash = ?', array($hash))) {
			$summary['skipped']++;
			$summary['skipped_hashes'][] = $hash;

			continue;
		}

		$existing = db_fetch_cell_prepared('SELECT id FROM plugin_topx_source WHERE hash = ? AND operation = ?',
			array($hash, (string) ($source['operation'] ?? '')));

		$save       = $source;
		$save['id'] = (int) $existing;

		topx_source_save($save);

		if ($existing) {
			$summary['updated']++;
		} else {
			$summary['inserted']++;
		}
	}

	return $summary;
}
