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
include_once('./include/global_arrays.php');
include_once($config['base_path'] . '/plugins/topx/includes/functions.php');
include_once($config['base_path'] . '/plugins/topx/includes/arrays.php');

$title = __('TopX', 'topx');

general_header();

print get_md5_include_css('plugins/topx/css/topx.css');
print get_md5_include_js('plugins/topx/js/topx.js');

if (read_config_option('dsstats_enable') != 'on') {
	print __('Please enable and configure %sDS Stats%s', '<a href="' . html_escape($config['url_path']) . 'settings.php?tab=data">', '</a>', 'topx');

	bottom_footer();

	exit;
}

$filters = topx_filter_arrays();

$ar_ds = topx_get_ds_list();

/* if the user pushed the 'clear' button */
if (isset_request_var('clear_x')) {
	unset($_SESSION['sess_topx_ds']);
	unset($_SESSION['sess_topx_age']);
	unset($_SESSION['sess_topx_records']);
	unset($_SESSION['sess_topx_sort']);
	unset($_SESSION['sess_topx_what']);
}

/* validate every externally controlled filter against its allowlist before use */
if (isset_request_var('ds') && array_key_exists(get_nfilter_request_var('ds'), $ar_ds)) {
	$_SESSION['sess_topx_ds'] = get_nfilter_request_var('ds');
}

if (!isset($_SESSION['sess_topx_ds'])) {
	$_SESSION['sess_topx_ds'] = key($ar_ds);
}

if (isset_request_var('age') && array_key_exists(get_nfilter_request_var('age'), $filters['age'])) {
	$_SESSION['sess_topx_age'] = get_nfilter_request_var('age');
}

if (!isset($_SESSION['sess_topx_age'])) {
	$_SESSION['sess_topx_age'] = 'hour';
}

if (isset_request_var('topx') && array_key_exists(get_nfilter_request_var('topx'), $filters['records'])) {
	$_SESSION['sess_topx_records'] = get_nfilter_request_var('topx');
}

if (!isset($_SESSION['sess_topx_records'])) {
	$_SESSION['sess_topx_records'] = 5;
}

if (isset_request_var('sort') && array_key_exists(get_nfilter_request_var('sort'), $filters['sort'])) {
	$_SESSION['sess_topx_sort'] = get_nfilter_request_var('sort');
}

if (!isset($_SESSION['sess_topx_sort'])) {
	$_SESSION['sess_topx_sort'] = 'desc';
}

if (isset_request_var('what') && array_key_exists(get_nfilter_request_var('what'), $filters['what'])) {
	$_SESSION['sess_topx_what'] = get_nfilter_request_var('what');
}

if (!isset($_SESSION['sess_topx_what'])) {
	$_SESSION['sess_topx_what'] = key($filters['what']);
}

topx_render_filter($ar_ds, $filters);

/* translate the validated request values into whitelisted SQL fragments */
$table        = topx_age_table($_SESSION['sess_topx_age']);
$order        = topx_order_column($_SESSION['sess_topx_what']);
$dir          = topx_sort_direction($_SESSION['sess_topx_sort']);
$limit        = topx_record_limit($_SESSION['sess_topx_records']);
$limit_clause = $limit > 0 ? " LIMIT $limit" : '';

/* the computed branches order on the derived column rather than t2.<stat> */
$computed_order = ($_SESSION['sess_topx_what'] === 'peak') ? 'xpeak' : 'xvalue';

$id = (int) $_SESSION['sess_topx_ds'];

if ($ar_ds[$_SESSION['sess_topx_ds']]['supported'] === 'true') {
	$id *= -1;
}

$graph = [];
$label = [];
$avg   = 0;

if ($ar_ds[$_SESSION['sess_topx_ds']]['supported'] === 'true') {
	$source = db_fetch_row_prepared('SELECT plugin_topx_source.*
		FROM plugin_topx_source
		INNER JOIN data_template ON plugin_topx_source.hash = data_template.hash
		WHERE data_template.id = ?',
		array($id));

	if (strpos($source['operation'], '=') !== false) {	// only one value
		$from = " FROM data_template_data AS t1
			LEFT JOIN $table AS t2 ON t1.local_data_id = t2.local_data_id
			WHERE t1.data_template_id = ?";

		$avg = db_fetch_cell_prepared("SELECT AVG(average)$from", array($id));

		$result = db_fetch_assoc_prepared("SELECT t1.local_data_id AS ldid, CONCAT(t1.name_cache, ' - ', t2.rrd_name) AS name, t2.average AS xvalue, t2.peak AS xpeak
			$from ORDER BY t2.$order $dir$limit_clause", array($id));
	}

	if (strpos($source['operation'], '/') !== false) {	// only one value/number
		$from = " FROM data_template_data AS t1
			LEFT JOIN $table AS t2 ON t1.local_data_id = t2.local_data_id
			WHERE t1.data_template_id = ?";

		$avg = db_fetch_cell_prepared("SELECT AVG(average)$from", array($id));

		$result = db_fetch_assoc_prepared("SELECT t1.local_data_id AS ldid, CONCAT(t1.name_cache, ' - ', t2.rrd_name) AS name, t2.average AS xvalue, t2.peak AS xpeak
			$from ORDER BY t2.$order $dir$limit_clause", array($id));
	} elseif (strpos($source['operation'], '%') !== false) {	// hdd_total%hdd_used
		$columns = "name_cache AS name, t2.local_data_id AS ldid,
			100 * average / (SELECT average FROM $table WHERE local_data_id = ldid AND rrd_name = 'hdd_total') AS xvalue,
			100 * peak / (SELECT peak FROM $table WHERE local_data_id = ldid AND rrd_name = 'hdd_total') AS xpeak";

		$from = " FROM data_template_data AS t1
			LEFT JOIN $table AS t2 ON t1.local_data_id = t2.local_data_id
			WHERE t1.data_template_id = ? AND rrd_name = 'hdd_used'";

		$result = db_fetch_assoc_prepared("SELECT $columns $from ORDER BY $computed_order $dir$limit_clause", array($id));

		$xavg = db_fetch_assoc_prepared("SELECT t1.local_data_id AS ldid,
			100 * average / (SELECT average FROM $table WHERE local_data_id = ldid AND rrd_name = 'hdd_total') AS xvalue
			$from", array($id));

		$avg = topx_average_rows($xavg);
	} elseif ($source['operation'] === 'discards_in+errors_in') {	// discards_in + errors_in
		$avg    = topx_combined_sum_average($table, $id, 'discards_in', 'errors_in');
		$result = topx_combined_result($table, $id, 'discards_in', 'errors_in', 1, $computed_order, $dir, $limit_clause);
	} elseif ($source['operation'] === 'discards_out+errors_out') {	// discards_out + errors_out
		$avg    = topx_combined_sum_average($table, $id, 'discards_out', 'errors_out');
		$result = topx_combined_result($table, $id, 'discards_out', 'errors_out', 1, $computed_order, $dir, $limit_clause);
	} elseif ($source['operation'] === 'traffic_in+traffic_out') {	// traffic_in + traffic_out (bytes -> bits)
		$avg    = 8 * topx_combined_sum_average($table, $id, 'traffic_in', 'traffic_out');
		$result = topx_combined_result($table, $id, 'traffic_in', 'traffic_out', 8, $computed_order, $dir, $limit_clause);
	}

	if (!isset($result)) {
		$result = [];
	}

	$pie_title = $source['unit'] . ' [ ' . $source['final_unit'] . ' ] ';

	print '<table class="topx_table">';
	print '<tr><th>' . __('Data Source', 'topx') . '</th><th>' . __('Avg. value [%s]', html_escape($source['final_unit']), 'topx') . '</th><th>' . __('Peak [%s]', html_escape($source['final_unit']), 'topx') . '</th></tr>';

	$avg_count = 0;

	foreach ($result as $row) {
		$graph_id = db_fetch_cell_prepared('SELECT DISTINCT(local_graph_id)
			FROM graph_templates_item
			LEFT JOIN data_template_rrd ON graph_templates_item.task_item_id = data_template_rrd.id
			LEFT JOIN data_local ON data_template_rrd.local_data_id = data_local.id
			LEFT JOIN data_template_data ON data_local.id = data_template_data.local_data_id
			WHERE data_template_data.local_data_id = ?',
			array($row['ldid']));

		/* some sources carry a trailing divisor in their operation (x/number) */
		if (strpos($source['operation'], '/') !== false) {
			$val           = (int) substr($source['operation'], strpos($source['operation'], '/') + 1);
			$row['xvalue'] = $val != 0 ? $row['xvalue'] / $val : $row['xvalue'];
			$row['xpeak']  = $val != 0 ? $row['xpeak'] / $val : $row['xpeak'];

			if ($avg_count == 0) {
				$avg = $val != 0 ? $avg / $val : $avg;
				$avg_count++;
			}
		}

		$row['xvalue'] = is_null($row['xvalue']) ? 0 : $row['xvalue'];
		$row['xpeak']  = is_null($row['xpeak']) ? 0 : $row['xpeak'];

		$graph[] = $row['xvalue'];
		$label[] = $row['name'];

		print '<tr><td><a href="' . html_escape($config['url_path']) . 'graphs.php?action=graph_edit&id=' . (int) $graph_id . '">' . html_escape($row['name']) . '</a></td>' .
			'<td>' . html_escape(topx_final_operation($row['xvalue'], $source['final_operation'], $source['final_unit'], $source['final_number'])) . '</td>' .
			'<td>' . html_escape(topx_final_operation($row['xpeak'], $source['final_operation'], $source['final_unit'], $source['final_number'])) . '</td></tr>';
	}

	$graph[] = $avg;
	$label[] = __('Average all', 'topx');

	print '<tr><td>' . __('Average all DS', 'topx') . '</td><td colspan="2">' . html_escape(topx_final_operation($avg, $source['final_operation'], $source['final_unit'], $source['final_number'])) . '</td></tr>';
	print '</table>';
} else {	// unsupported - plain data with decimal unit conversion only
	print __('Unsupported: plain data without units, only with decimal unit conversion', 'topx');

	$from = " FROM data_template_data AS t1
		LEFT JOIN $table AS t2 ON t1.local_data_id = t2.local_data_id
		WHERE t1.data_template_id = ?";

	$avg = db_fetch_cell_prepared("SELECT AVG(average)$from", array($id));

	$result = db_fetch_assoc_prepared("SELECT t1.local_data_id AS ldid, CONCAT(t1.name_cache, ' - ', t2.rrd_name) AS name, t2.average AS xvalue, t2.peak AS xpeak
		$from ORDER BY $computed_order $dir$limit_clause", array($id));

	$label[]   = __('Average all', 'topx');
	$graph[]   = $avg;
	$pie_title = '';

	print '<table class="topx_table">';
	print '<tr><th>' . __('Data Source', 'topx') . '</th><th>' . __('Avg. value', 'topx') . '</th><th>' . __('Peak', 'topx') . '</th></tr>';

	foreach ($result as $row) {
		$graph_id = db_fetch_cell_prepared('SELECT DISTINCT(local_graph_id)
			FROM graph_templates_item
			LEFT JOIN data_template_rrd ON graph_templates_item.task_item_id = data_template_rrd.id
			LEFT JOIN data_local ON data_template_rrd.local_data_id = data_local.id
			LEFT JOIN data_template_data ON data_local.id = data_template_data.local_data_id
			WHERE data_template_data.local_data_id = ?',
			array($row['ldid']));

		$graph[] = $row['xvalue'];
		$label[] = $row['name'];

		print '<tr><td><a href="' . html_escape($config['url_path']) . 'graphs.php?action=graph_edit&id=' . (int) $graph_id . '">' . html_escape($row['name']) . '</a></td>' .
			'<td>' . html_escape(topx_human_readable($row['xvalue'])) . '</td>' .
			'<td>' . html_escape(topx_human_readable($row['xpeak'])) . '</td></tr>';
	}

	print '<tr><td>' . __('Average all DS', 'topx') . '</td><td colspan="2">' . html_escape(topx_human_readable($avg, true)) . '</td></tr>';
	print '</table>';
}

/* chart - rendered with billboard.js (bundled by Cacti's general_header) */
if ($limit > 0) {
	$payload = [
		'bindto'       => '#topx_chart',
		'series'       => __('Value', 'topx'),
		'labels'       => array_values($label),
		'values'       => array_map('floatval', array_values($graph)),
		'title'        => isset($pie_title) ? $pie_title : '',
		'average'      => (float) $avg,
		'averageLabel' => __('Average', 'topx'),
		'width'        => 800,
		'height'       => 40 + $limit * 28,
	];

	print '<div class="topx_graph"><br><br><div id="topx_chart"></div>';
	print '<script type="application/json" id="topx-chart-data" ' . plugin_topx_csp_nonce() . '>' . json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
	print '</div>';
} else {
	print '<br><br>' . __('Displaying all records, the chart will not be displayed. It is only shown for 50 or fewer records.', 'topx') . '<br>';
}

print '<br><br>';
print __('DS Stats last major run time: %s', html_escape(read_config_option('dsstats_last_major_run_time')), 'topx') . '<br>';
print __('DS Stats last daily run time: %s', html_escape(read_config_option('dsstats_last_daily_run_time')), 'topx') . '<br>';

bottom_footer();

/**
 * Renders the TopX filter box containing the data source, age, record count,
 * order and statistic selectors. Uses Cacti's standard HTML box/table helpers
 * so the form inherits the active theme styling and Cacti's CSRF protection.
 *
 * @param array $ar_ds   The keyed data source list from topx_get_ds_list().
 * @param array $filters The translated filter arrays from topx_filter_arrays().
 *
 * @return void
 */
function topx_render_filter(array $ar_ds, array $filters): void {
	html_start_box(__('TopX', 'topx'), '100%', '', '3', 'center', '');

	?>
	<tr>
		<td>
			<form name='form_topx' id='form_topx' action='topx.php' method='get'>
				<table class='filterTable'>
					<tr>
						<td><?php print __('Data Source', 'topx'); ?></td>
						<td>
							<select name='ds' id='ds' onChange='applyTopxFilter(document.form_topx)'>
								<?php
								foreach ($ar_ds as $ds) {
									$selected = ($_SESSION['sess_topx_ds'] == $ds['key']) ? ' selected' : '';
									print '<option value="' . html_escape($ds['key']) . '"' . $selected . '>' . html_escape($ds['name'] . ' (' . $ds['count'] . ')') . '</option>';
								}
								?>
							</select>
						</td>
						<td><?php print __('Age', 'topx'); ?></td>
						<td><?php topx_render_select('age', $filters['age'], $_SESSION['sess_topx_age']); ?></td>
						<td><?php print __('Records', 'topx'); ?></td>
						<td><?php topx_render_select('topx', $filters['records'], $_SESSION['sess_topx_records']); ?></td>
						<td><?php print __('Order', 'topx'); ?></td>
						<td><?php topx_render_select('sort', $filters['sort'], $_SESSION['sess_topx_sort']); ?></td>
						<td><?php print __('What', 'topx'); ?></td>
						<td><?php topx_render_select('what', $filters['what'], $_SESSION['sess_topx_what']); ?></td>
						<td>
							<input type='submit' value='<?php print __esc('Go', 'topx'); ?>' title='<?php print __esc('Set/Refresh Filters', 'topx'); ?>'>
							<input type='submit' name='clear_x' value='<?php print __esc('Clear', 'topx'); ?>' title='<?php print __esc('Clear Filters', 'topx'); ?>'>
						</td>
					</tr>
				</table>
			</form>
		</td>
	</tr>
	<?php

	html_end_box();
}

/**
 * Renders a single <select> element for a filter, marking the current session
 * value as selected and escaping all option values and labels.
 *
 * @param string     $name     The form field name.
 * @param array      $options  Option token => translated label pairs.
 * @param int|string $selected The currently selected option token.
 *
 * @return void
 */
function topx_render_select(string $name, array $options, $selected): void {
	print '<select name="' . html_escape($name) . '" onChange="applyTopxFilter(document.form_topx)">';

	foreach ($options as $key => $value) {
		$is_selected = ($selected == $key) ? ' selected' : '';
		print '<option value="' . html_escape($key) . '"' . $is_selected . '>' . html_escape($value) . '</option>';
	}

	print '</select>';
}
