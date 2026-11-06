<?php
/*
 +-------------------------------------------------------------------------+
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
		'final_operation', 'final_unit', 'final_number', 'system',
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
		'final_number'    => '0',
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
 * sense inverted. Returns '' when the source defines no thresholds, so callers
 * can fall back to a neutral colour. This is deliberately separate from the
 * dashboard's "% of peak" distribution, which is relative to the live peak of
 * the returned set; thresholds express an absolute, operator-defined health
 * band instead. Kept free of database access so it can be unit tested.
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

		return 'normal';
	}

	if ($has($alert) && $value >= (float) $alert) {
		return 'alert';
	}

	if ($has($warning) && $value >= (float) $warning) {
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
		'normal'  => '#3fa796',
		'warning' => '#e0a800',
		'alert'   => '#d9534f',
	];

	return $colors[$class] ?? '';
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
		'final_number'    => trim((string) ($input['final_number'] ?? '0')),
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
 * bound, never concatenated.
 *
 * @param array $save A row produced by topx_source_prepare_save().
 *
 * @return int|false The affected row id, or false on failure.
 */
function topx_source_save(array $save) {
	return sql_save($save, 'plugin_topx_source');
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
 * Resolves the chart-label substitution tokens for a single ranked data source
 * instance. Returns host-level tokens (host_description, host_hostname,
 * host_id) plus one query_<field> token per SNMP data-query field cached for
 * the instance, so patterns can name the chart by interface, storage volume,
 * CPU index, etc. Thin database wrapper around topx_apply_chart_label().
 *
 * @param int $local_data_id The Cacti local data source id of the ranked row.
 *
 * @return array<string,string> The token map for topx_apply_chart_label().
 */
function topx_chart_label_tokens(int $local_data_id): array {
	$tokens = [];

	$meta = db_fetch_row_prepared('SELECT h.description AS host_description,
		h.hostname AS host_hostname, h.id AS host_id, dl.snmp_query_id, dl.snmp_index
		FROM data_local AS dl
		LEFT JOIN host AS h ON dl.host_id = h.id
		WHERE dl.id = ?',
		array($local_data_id));

	if (!is_array($meta)) {
		return $tokens;
	}

	$tokens['host_description'] = (string) ($meta['host_description'] ?? '');
	$tokens['host_hostname']    = (string) ($meta['host_hostname'] ?? '');
	$tokens['host_id']          = (string) ($meta['host_id'] ?? '');

	if (!empty($meta['snmp_query_id']) && isset($meta['snmp_index']) && $meta['snmp_index'] !== '') {
		$fields = db_fetch_assoc_prepared('SELECT field_name, field_value
			FROM host_snmp_cache
			WHERE host_id = ? AND snmp_query_id = ? AND snmp_index = ?',
			array($meta['host_id'], $meta['snmp_query_id'], $meta['snmp_index']));

		foreach ($fields as $field) {
			$tokens['query_' . $field['field_name']] = (string) $field['field_value'];
		}
	}

	return $tokens;
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
