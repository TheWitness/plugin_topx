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

chdir('../../');

include_once('./include/auth.php');
include_once($config['base_path'] . '/plugins/topx/includes/functions.php');
include_once($config['base_path'] . '/plugins/topx/includes/arrays.php');
include_once($config['base_path'] . '/plugins/topx/includes/sources.php');

$topx_source_actions = [
	1 => __('Delete', 'topx'),
	2 => __('Export', 'topx'),
];

set_default_action();

switch (get_request_var('action')) {
	case 'save':
		topx_sources_form_save();

		break;
	case 'actions':
		topx_sources_form_actions();

		break;
	case 'export':
		topx_sources_export();

		break;
	case 'import':
		top_header();

		topx_sources_import();

		bottom_footer();

		break;
	case 'import_save':
		top_header();

		topx_sources_import_save();

		bottom_footer();

		break;
	case 'edit':
		top_header();

		topx_sources_edit();

		bottom_footer();

		break;
	default:
		top_header();

		topx_sources();

		bottom_footer();

		break;
}

/**
 * Persists a source definition from the edit form. Validates the selected
 * template hash, normalises every field through topx_source_prepare_save() and
 * writes the row with a bound sql_save. Redirects back to the editor.
 *
 * @return void
 */
function topx_sources_form_save(): void {
	if (isset_request_var('id') && !is_numeric(get_filter_request_var('id'))) {
		set_request_var('id', 0);
	}

	if (!isset_request_var('save_component_source')) {
		header('Location: topx_sources.php');

		return;
	}

	$hash = get_nfilter_request_var('hash');

	if (!preg_match('/^[a-f0-9]{32}$/', $hash) || !db_fetch_cell_prepared('SELECT id FROM data_template WHERE hash = ?', array($hash))) {
		raise_message('topx_hash', __('Please choose a valid Data Template.', 'topx'), MESSAGE_LEVEL_ERROR);

		header('Location: topx_sources.php?action=edit&id=' . get_filter_request_var('id'));

		return;
	}

	$input = [
		'id'              => get_filter_request_var('id'),
		'hash'            => $hash,
		'dt_name'         => get_nfilter_request_var('dt_name'),
		'operation'       => get_nfilter_request_var('operation'),
		'unit'            => get_nfilter_request_var('unit'),
		'final_operation' => get_nfilter_request_var('final_operation'),
		'final_unit'      => get_nfilter_request_var('final_unit'),
		'final_number'    => get_nfilter_request_var('final_number'),
		'system'          => get_nfilter_request_var('system'),
		'sorting'         => get_nfilter_request_var('sorting'),
		'range_type'      => get_nfilter_request_var('range_type'),
		'range_normal'    => get_nfilter_request_var('range_normal'),
		'range_warning'   => get_nfilter_request_var('range_warning'),
		'range_alert'     => get_nfilter_request_var('range_alert'),
		'chart_label'     => get_nfilter_request_var('chart_label'),
	];

	$save = topx_source_prepare_save($input);

	// Default the display name to the Cacti template name when left blank.
	if ($save['dt_name'] === '') {
		$save['dt_name'] = (string) db_fetch_cell_prepared('SELECT name FROM data_template WHERE hash = ?', array($hash));
	}

	$id = topx_source_save($save);

	if ($id) {
		raise_message(1);
	} else {
		raise_message(2);
	}

	header('Location: topx_sources.php?action=edit&id=' . (int) $id);
}

/**
 * Handles the list actions dropdown. Delete shows a confirmation then removes
 * the selected definitions; Export streams the selected definitions as an XML
 * document. Both operate only on real source rows (id > 0).
 *
 * @return void
 */
function topx_sources_form_actions(): void {
	get_filter_request_var('drp_action', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^([0-9]+)$/')));

	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected_items != false) {
			if (get_nfilter_request_var('drp_action') == '1') {
				topx_source_remove($selected_items);
			} elseif (get_nfilter_request_var('drp_action') == '2') {
				topx_sources_export($selected_items);

				return;
			}
		}

		header('Location: topx_sources.php');

		return;
	}

	$ids  = [];
	$list = '';

	foreach ($_POST as $var => $val) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			$id    = (int) $matches[1];
			$ids[] = $id;
			$list .= '<li>' . html_escape(db_fetch_cell_prepared('SELECT dt_name FROM plugin_topx_source WHERE id = ?', array($id))) . '</li>';
		}
	}

	if (cacti_sizeof($ids) === 0) {
		raise_message(40);

		header('Location: topx_sources.php');

		return;
	}

	top_header();

	form_start('topx_sources.php');

	$confirm_title = (get_nfilter_request_var('drp_action') == '1')
		? __('Delete Data Sources', 'topx')
		: __('Export Data Sources', 'topx');

	html_start_box($confirm_title, '60%', '', '3', 'center', '');

	if (get_nfilter_request_var('drp_action') == '1') {
		print "<tr><td class='textArea'><p>" .
			__n('Click \'Continue\' to delete the following Data Source.', 'Click \'Continue\' to delete the following Data Sources.', cacti_sizeof($ids), 'topx') .
			"</p><div class='itemlist'><ul>$list</ul></div></td></tr>";

		$save_html = "<input type='button' class='ui-button ui-corner-all ui-widget cactiReturnTo' value='" . __esc('Cancel', 'topx') . "'>&nbsp;<input type='submit' class='ui-button ui-corner-all ui-widget' value='" . __esc('Continue', 'topx') . "' title='" . __n('Delete Data Source', 'Delete Data Sources', cacti_sizeof($ids), 'topx') . "'>";
	} else {
		print "<tr><td class='textArea'><p>" .
			__n('Click \'Continue\' to export the following Data Source.', 'Click \'Continue\' to export the following Data Sources.', cacti_sizeof($ids), 'topx') .
			"</p><div class='itemlist'><ul>$list</ul></div></td></tr>";

		$save_html = "<input type='button' class='ui-button ui-corner-all ui-widget cactiReturnTo' value='" . __esc('Cancel', 'topx') . "'>&nbsp;<input type='submit' class='ui-button ui-corner-all ui-widget' value='" . __esc('Continue', 'topx') . "' title='" . __esc('Export Data Sources', 'topx') . "'>";
	}

	print "<tr><td class='saveRow'>
		<input type='hidden' name='action' value='actions'>
		<input type='hidden' name='selected_items' value='" . html_escape(serialize($ids)) . "'>
		<input type='hidden' name='drp_action' value='" . html_escape(get_nfilter_request_var('drp_action')) . "'>
		$save_html
		</td></tr>";

	html_end_box();

	form_end();

	bottom_footer();
}

/**
 * Streams the given source definitions (or every definition when no ids are
 * supplied) as a downloadable XML export document keyed on template hash.
 *
 * @param array|null $ids Optional list of plugin_topx_source ids to export.
 *
 * @return void
 */
function topx_sources_export(?array $ids = null): void {
	if ($ids !== null && cacti_sizeof($ids) > 0) {
		$ids          = array_values(array_filter(array_map('intval', $ids)));
		$placeholders = implode(', ', array_fill(0, cacti_sizeof($ids), '?'));
		$rows         = db_fetch_assoc_prepared("SELECT * FROM plugin_topx_source WHERE id IN ($placeholders) ORDER BY dt_name", $ids);
	} else {
		$rows = db_fetch_assoc('SELECT * FROM plugin_topx_source ORDER BY dt_name');
	}

	$xml = topx_source_export_xml($rows);

	header('Content-Type: text/xml; charset=UTF-8');
	header('Content-Disposition: attachment; filename="topx_data_sources.xml"');
	header('Content-Length: ' . strlen($xml));

	print $xml;
}

/**
 * Renders the import form, where an administrator pastes or uploads a TopX XML
 * export to apply to this install.
 *
 * @return void
 */
function topx_sources_import(): void {
	form_start('topx_sources.php?action=import_save', 'chk', true);

	html_start_box(__('Import TopX Data Sources', 'topx'), '100%', true, '3', 'center', '');

	$fields = [
		'import_text' => [
			'method'        => 'textarea',
			'friendly_name' => __('Import Text', 'topx'),
			'description'   => __('Paste the contents of a TopX Data Source XML export here, or choose a file below.', 'topx'),
			'value'         => '',
			'textarea_rows' => 10,
			'textarea_cols' => 80,
			'class'         => 'textAreaNotes',
		],
		'import_file' => [
			'method'        => 'file',
			'friendly_name' => __('Import File', 'topx'),
			'description'   => __('A TopX Data Source XML export file to import.', 'topx'),
		],
	];

	draw_edit_form(
		[
			'config' => ['no_form_tag' => true],
			'fields' => $fields,
		]
	);

	html_end_box(true, true);

	form_save_button('topx_sources.php', 'import', 'import', false);
}

/**
 * Applies an uploaded/pasted import document. Parses the XML, matches rows to
 * local templates by hash and reports how many were inserted, updated and
 * skipped.
 *
 * @return void
 */
function topx_sources_import_save(): void {
	$xml = '';

	if (isset($_FILES['import_file']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
		$xml = (string) file_get_contents($_FILES['import_file']['tmp_name']);
	} elseif (isset_request_var('import_text')) {
		$xml = (string) get_nfilter_request_var('import_text');
	}

	$parsed = topx_source_import_xml($xml);

	if ($parsed['error'] !== '') {
		raise_message('topx_import', $parsed['error'], MESSAGE_LEVEL_ERROR);

		header('Location: topx_sources.php?action=import');

		return;
	}

	$summary = topx_import_apply($parsed['sources']);

	raise_message('topx_import',
		sprintf(__('Import complete: %1$d inserted, %2$d updated, %3$d skipped (no matching Data Template on this install).', 'topx'),
			$summary['inserted'], $summary['updated'], $summary['skipped']),
		$summary['skipped'] > 0 ? MESSAGE_LEVEL_WARN : MESSAGE_LEVEL_INFO);

	header('Location: topx_sources.php');
}

/**
 * Renders the Add/Edit form for a single source definition.
 *
 * @return void
 */
function topx_sources_edit(): void {
	get_filter_request_var('id');

	$source = topx_source_blank();

	if (!isempty_request_var('id')) {
		$existing = topx_get_source(get_request_var('id'));

		if (cacti_sizeof($existing)) {
			$source       = $existing;
			$header_label = __esc('Data Source [edit: %s]', $source['dt_name'], 'topx');
		} else {
			$header_label = __('Data Source [new]', 'topx');
		}
	} else {
		$header_label = __('Data Source [new]', 'topx');
	}

	$templates = topx_source_template_choices();

	$fields = [
		'hash' => [
			'method'        => 'drop_array',
			'friendly_name' => __('Data Template', 'topx'),
			'description'   => __('The Cacti Data Template this definition ranks. The portable template hash is stored, so the definition survives export/import between installs. The count shows how many Data Sources currently use each template.', 'topx'),
			'value'         => '|arg1:hash|',
			'array'         => $templates,
		],
		'dt_name' => [
			'method'        => 'textbox',
			'friendly_name' => __('Display Name', 'topx'),
			'description'   => __('A friendly name shown in the TopX dashboards. Leave blank to use the Data Template name.', 'topx'),
			'value'         => '|arg1:dt_name|',
			'max_length'    => 200,
			'size'          => 60,
		],
		'operation' => [
			'method'        => 'textbox',
			'friendly_name' => __('Metric Expression', 'topx'),
			'description'   => __('How the RRD data source fields are combined, e.g. "cpu=cpu" (single field), "traffic_in+traffic_out" (sum), "hdd_total%hdd_used" (percentage) or "uptime/100" (scaled).', 'topx'),
			'value'         => '|arg1:operation|',
			'max_length'    => 100,
			'size'          => 40,
		],
		'unit' => [
			'method'        => 'textbox',
			'friendly_name' => __('Metric Label', 'topx'),
			'description'   => __('A short label for what is measured, e.g. "Utilization", "Bits". Used for the chart value axis.', 'topx'),
			'value'         => '|arg1:unit|',
			'max_length'    => 100,
			'size'          => 30,
		],
		'range_type' => [
			'method'        => 'drop_array',
			'friendly_name' => __('Range Direction', 'topx'),
			'description'   => __('Whether a higher value is the more significant one (increasing, e.g. CPU/traffic) or a lower value is (decreasing, e.g. free disk space or uptime headroom). Drives the default ranking sense and threshold colouring.', 'topx'),
			'value'         => '|arg1:range_type|',
			'array'         => topx_range_type_labels(),
		],
		'sorting' => [
			'method'        => 'drop_array',
			'friendly_name' => __('Default Sort', 'topx'),
			'description'   => __('The default sort direction applied when this source is first opened.', 'topx'),
			'value'         => '|arg1:sorting|',
			'array'         => ['desc' => __('Descending', 'topx'), 'asc' => __('Ascending', 'topx')],
		],
		'final_operation' => [
			'method'        => 'drop_array',
			'friendly_name' => __('Display Scaling', 'topx'),
			'description'   => __('How computed values are formatted for display. "Strip" rounds to the precision and appends the unit; "Divide" walks a slash-delimited unit/divisor ladder (e.g. bit/Kbit/Mbit).', 'topx'),
			'value'         => '|arg1:final_operation|',
			'array'         => ['strip' => __('Strip (round + unit)', 'topx'), '/' => __('Divide (unit ladder)', 'topx')],
		],
		'final_unit' => [
			'method'        => 'textbox',
			'friendly_name' => __('Display Unit(s)', 'topx'),
			'description'   => __('The display unit, or a slash-delimited ladder for the Divide scaling (e.g. "bit/Kbit/Mbit/Gbit").', 'topx'),
			'value'         => '|arg1:final_unit|',
			'max_length'    => 100,
			'size'          => 40,
		],
		'final_number' => [
			'method'        => 'textbox',
			'friendly_name' => __('Precision / Divisors', 'topx'),
			'description'   => __('For Strip scaling, the number of decimal places. For Divide scaling, the slash-delimited divisor ladder (e.g. "1/1024/1048576").', 'topx'),
			'value'         => '|arg1:final_number|',
			'max_length'    => 100,
			'size'          => 40,
		],
		'system' => [
			'method'        => 'drop_array',
			'friendly_name' => __('Value System', 'topx'),
			'description'   => __('The numbering system used when auto-scaling unsupported values.', 'topx'),
			'value'         => '|arg1:system|',
			'array'         => topx_source_system_labels(),
		],
		'range_normal' => [
			'method'        => 'textbox',
			'friendly_name' => __('Normal Threshold', 'topx'),
			'description'   => __('Optional. The upper bound of the healthy band. Leave blank to disable threshold colouring.', 'topx'),
			'value'         => '|arg1:range_normal|',
			'max_length'    => 20,
			'size'          => 12,
		],
		'range_warning' => [
			'method'        => 'textbox',
			'friendly_name' => __('Warning Threshold', 'topx'),
			'description'   => __('Optional. Values at or beyond this (at or below, for decreasing ranges) are shown in the warning colour.', 'topx'),
			'value'         => '|arg1:range_warning|',
			'max_length'    => 20,
			'size'          => 12,
		],
		'range_alert' => [
			'method'        => 'textbox',
			'friendly_name' => __('Alert Threshold', 'topx'),
			'description'   => __('Optional. Values at or beyond this (at or below, for decreasing ranges) are shown in the alert colour.', 'topx'),
			'value'         => '|arg1:range_alert|',
			'max_length'    => 20,
			'size'          => 12,
		],
		'chart_label' => [
			'method'        => 'textbox',
			'friendly_name' => __('Chart Label Pattern', 'topx'),
			'description'   => __('How each ranked item is named on the chart, using |token| substitution: |host_description|, |host_hostname|, |host_id| and |query_<field>| (e.g. |query_ifName|). Example: "|host_description| - |query_ifName|". Leave blank to derive the label from the Data Source name.', 'topx'),
			'value'         => '|arg1:chart_label|',
			'max_length'    => 128,
			'size'          => 60,
		],
		'id' => [
			'method' => 'hidden_zero',
			'value'  => '|arg1:id|',
		],
		'save_component_source' => [
			'method' => 'hidden',
			'value'  => '1',
		],
	];

	form_start('topx_sources.php', 'chk');

	html_start_box($header_label, '100%', true, '3', 'center', '');

	draw_edit_form(
		[
			'config' => ['no_form_tag' => true],
			'fields' => inject_form_variables($fields, $source),
		]
	);

	html_end_box(true, true);

	form_save_button('topx_sources.php', 'return');
}

/**
 * Renders the TopX Data Sources management list: a filterable table of Data
 * Templates and their TopX configuration, with a Supported (All/Yes/No) filter
 * mirroring Cacti's own management grids.
 *
 * @return void
 *
 * @global array $topx_source_actions The list action map.
 */
function topx_sources(): void {
	global $topx_source_actions;

	$filters = [
		'rows' => [
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1',
		],
		'page' => [
			'filter'  => FILTER_VALIDATE_INT,
			'default' => '1',
		],
		'filter' => [
			'filter'  => FILTER_DEFAULT,
			'pageset' => true,
			'default' => '',
		],
		'supported' => [
			'filter'  => FILTER_VALIDATE_REGEXP,
			'options' => ['options' => ['regexp' => '/^(-1|0|1)$/']],
			'pageset' => true,
			'default' => '-1',
		],
		'sort_column' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'name',
			'options' => ['options' => 'sanitize_search_string'],
		],
		'sort_direction' => [
			'filter'  => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => ['options' => 'sanitize_search_string'],
		],
	];

	validate_store_request_vars($filters, 'sess_topx_sources');

	$rows = (get_request_var('rows') == '-1') ? read_config_option('num_rows_table') : get_request_var('rows');

	html_start_box(__('TopX Data Sources', 'topx'), '100%', '', '3', 'center', 'topx_sources.php?action=edit');

	topx_sources_filter();

	html_end_box();

	// A single projection over both configured (supported) definitions and
	// bare, unconfigured Data Templates, so the Supported filter can show
	// either set. All request-derived pieces are bound, never concatenated.
	$usage = 'LEFT JOIN (
			SELECT data_template_id, COUNT(DISTINCT local_data_id) AS in_use
			FROM data_template_data
			WHERE local_data_id > 0
			GROUP BY data_template_id
		) AS u ON u.data_template_id = dt.id';

	$base = "SELECT s.id AS id, s.dt_name AS name, dt.name AS template, s.hash AS hash,
			s.operation AS operation, s.unit AS unit, s.range_type AS range_type,
			s.chart_label AS chart_label, COALESCE(u.in_use, 0) AS in_use, 'Yes' AS supported
		FROM plugin_topx_source AS s
		LEFT JOIN data_template AS dt ON dt.hash = s.hash
		$usage
		UNION ALL
		SELECT 0 AS id, dt.name AS name, dt.name AS template, dt.hash AS hash,
			'' AS operation, '' AS unit, 0 AS range_type,
			'' AS chart_label, COALESCE(u.in_use, 0) AS in_use, 'No' AS supported
		FROM data_template AS dt
		LEFT JOIN plugin_topx_source AS s ON s.hash = dt.hash
		$usage
		WHERE s.id IS NULL AND dt.hash != ''";

	$sql_where  = '';
	$sql_params = [];

	if (get_request_var('filter') != '') {
		$sql_where    = 'WHERE (name LIKE ? OR template LIKE ?)';
		$sql_params[] = '%' . get_request_var('filter') . '%';
		$sql_params[] = '%' . get_request_var('filter') . '%';
	}

	if (get_request_var('supported') == '1') {
		$sql_where    .= ($sql_where === '' ? 'WHERE ' : ' AND ') . "supported = 'Yes'";
	} elseif (get_request_var('supported') == '0') {
		$sql_where    .= ($sql_where === '' ? 'WHERE ' : ' AND ') . "supported = 'No'";
	}

	$sql_order = get_order_string();
	$sql_limit = ' LIMIT ' . ($rows * (get_request_var('page') - 1)) . ',' . $rows;

	$total_rows = db_fetch_cell_prepared("SELECT COUNT(*) FROM ($base) AS rs $sql_where", $sql_params);

	$list = db_fetch_assoc_prepared("SELECT * FROM ($base) AS rs $sql_where $sql_order $sql_limit", $sql_params);

	$nav = html_nav_bar('topx_sources.php?filter=' . get_request_var('filter'), MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, 8, __('Data Sources', 'topx'), 'page', 'main');

	form_start('topx_sources.php', 'chk');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	$display_text = [
		'name' => [
			'display' => __('Name', 'topx'),
			'align'   => 'left',
			'sort'    => 'ASC',
			'tip'     => __('The TopX display name, or the Data Template name when not yet configured.', 'topx'),
		],
		'template' => [
			'display' => __('Data Template', 'topx'),
			'align'   => 'left',
			'sort'    => 'ASC',
		],
		'supported' => [
			'display' => __('Supported', 'topx'),
			'align'   => 'center',
			'sort'    => 'ASC',
			'tip'     => __('Whether a TopX Data Source definition exists for the Data Template.', 'topx'),
		],
		'in_use' => [
			'display' => __('In Use', 'topx'),
			'align'   => 'right',
			'sort'    => 'DESC',
			'tip'     => __('The number of Data Sources on devices that use the Data Template.', 'topx'),
		],
		'range_type' => [
			'display' => __('Direction', 'topx'),
			'align'   => 'left',
		],
		'operation' => [
			'display' => __('Expression', 'topx'),
			'align'   => 'left',
		],
		'chart_label' => [
			'display' => __('Chart Label', 'topx'),
			'align'   => 'left',
		],
	];

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false);

	$range_labels = topx_range_type_labels();

	if (cacti_sizeof($list)) {
		foreach ($list as $row) {
			$is_source = ((int) $row['id'] > 0);

			$edit_url = 'topx_sources.php?action=edit&id=' . (int) $row['id'];

			if (!$is_source) {
				$edit_url = 'topx_sources.php?action=edit&hash=' . html_escape($row['hash']);
			}

			form_alternate_row('line' . ($is_source ? (int) $row['id'] : 't' . html_escape($row['hash'])), true);

			form_selectable_cell(filter_value($row['name'] !== '' ? $row['name'] : $row['template'], get_request_var('filter'), $edit_url), $row['id']);
			form_selectable_cell(html_escape($row['template']), $row['id']);
			form_selectable_cell($row['supported'] === 'Yes' ? __('Yes', 'topx') : __('No', 'topx'), $row['id'], '', 'text-align:center');
			form_selectable_cell(number_format_i18n((int) $row['in_use'], -1), $row['id'], '', 'text-align:right');
			form_selectable_cell($is_source ? html_escape($range_labels[(int) $row['range_type']] ?? '') : '', $row['id']);
			form_selectable_cell($is_source ? html_escape($row['operation']) : '', $row['id']);
			form_selectable_cell($is_source ? html_escape($row['chart_label']) : '', $row['id']);

			if ($is_source) {
				form_checkbox_cell($row['name'], $row['id']);
			} else {
				print "<td style='text-align:right;'></td>";
			}

			form_end_row();
		}
	} else {
		print "<tr class='tableRow odd'><td colspan='" . (cacti_sizeof($display_text) + 1) . "'><em>" . __('No Data Sources Found', 'topx') . '</em></td></tr>';
	}

	html_end_box(false);

	if (cacti_sizeof($list)) {
		print $nav;
	}

	draw_actions_dropdown($topx_source_actions);

	form_end();
}

/**
 * Renders the filter toolbar for the management list: a text search, the
 * Supported (All/Yes/No) dropdown, a rows selector and Import/Export links.
 *
 * @return void
 */
function topx_sources_filter(): void {
	?>
	<tr class='even'>
		<td>
			<form id='form_topx_sources' action='topx_sources.php'>
				<table role='presentation' class='filterTable'>
					<tr>
						<td><?php print __('Search', 'topx'); ?></td>
						<td>
							<input type='text' class='ui-state-default ui-corner-all' id='filter' size='30' value='<?php print html_escape_request_var('filter'); ?>'>
						</td>
						<td><?php print __('Supported', 'topx'); ?></td>
						<td>
							<select id='supported' onChange='applyFilter()'>
								<option value='-1'<?php print (get_request_var('supported') == '-1' ? ' selected' : ''); ?>><?php print __('All', 'topx'); ?></option>
								<option value='1'<?php print (get_request_var('supported') == '1' ? ' selected' : ''); ?>><?php print __('Yes', 'topx'); ?></option>
								<option value='0'<?php print (get_request_var('supported') == '0' ? ' selected' : ''); ?>><?php print __('No', 'topx'); ?></option>
							</select>
						</td>
						<td><?php print __('Rows', 'topx'); ?></td>
						<td>
							<select id='rows' onChange='applyFilter()'>
								<option value='-1'<?php print (get_request_var('rows') == '-1' ? ' selected' : ''); ?>><?php print __('Default', 'topx'); ?></option>
								<?php
								global $item_rows;

								if (isset($item_rows) && cacti_sizeof($item_rows)) {
									foreach ($item_rows as $key => $value) {
										print "<option value='" . $key . "'" . (get_request_var('rows') == $key ? ' selected' : '') . '>' . html_escape($value) . "</option>\n";
									}
								}
								?>
							</select>
						</td>
						<td>
							<span>
								<input type='submit' class='ui-button ui-corner-all ui-widget' id='refresh' value='<?php print __esc('Go', 'topx'); ?>' title='<?php print __esc('Set/Refresh Filters', 'topx'); ?>'>
								<input type='button' class='ui-button ui-corner-all ui-widget' id='clear' value='<?php print __esc('Clear', 'topx'); ?>' title='<?php print __esc('Clear Filters', 'topx'); ?>'>
								<input type='button' class='ui-button ui-corner-all ui-widget' id='import' value='<?php print __esc('Import', 'topx'); ?>' title='<?php print __esc('Import Data Sources', 'topx'); ?>'>
								<input type='button' class='ui-button ui-corner-all ui-widget' id='export' value='<?php print __esc('Export All', 'topx'); ?>' title='<?php print __esc('Export All Data Sources', 'topx'); ?>'>
							</span>
						</td>
					</tr>
				</table>
			</form>
			<script type='text/javascript' <?php print plugin_topx_csp_nonce(); ?>>
				function applyFilter() {
					strURL  = 'topx_sources.php?header=false';
					strURL += '&filter='+$('#filter').val();
					strURL += '&supported='+$('#supported').val();
					strURL += '&rows='+$('#rows').val();
					loadPageNoHeader(strURL);
				}

				function clearFilter() {
					loadPageNoHeader('topx_sources.php?clear=1&header=false');
				}

				$(function() {
					$('#refresh').click(function() { applyFilter(); });
					$('#clear').click(function() { clearFilter(); });
					$('#import').click(function() { loadUrl({url:'topx_sources.php?action=import'}); });
					$('#export').click(function() { document.location = 'topx_sources.php?action=export'; });

					$('#form_topx_sources').submit(function(event) {
						event.preventDefault();
						applyFilter();
					});
				});
			</script>
		</td>
	</tr>
	<?php
}
