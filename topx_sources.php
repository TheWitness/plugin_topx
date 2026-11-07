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
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

chdir('../../');

include_once('./include/auth.php');
include_once($config['base_path'] . '/plugins/topx/includes/functions.php');
include_once($config['base_path'] . '/plugins/topx/includes/arrays.php');
include_once($config['base_path'] . '/plugins/topx/includes/sources.php');
include_once($config['base_path'] . '/plugins/topx/includes/dashboard.php');

$topx_source_actions = [
	1 => __('Delete', 'topx'),
	2 => __('Export', 'topx'),
];

set_default_action();

switch (get_request_var('action')) {
	case 'save':
		topx_sources_require_post();

		if (isset_request_var('save_component_import')) {
			topx_sources_import_save();
		} else {
			topx_sources_form_save();
		}

		break;
	case 'actions':
		topx_sources_require_post();

		topx_sources_form_actions();

		break;
	case 'export':
		topx_sources_export_action();

		break;
	case 'tokens':
		header('Content-Type: application/json; charset=UTF-8');

		print topx_sources_tokens_ajax();

		break;
	case 'import':
		top_header();

		topx_sources_import();

		bottom_footer();

		break;
	case 'import_save':
		topx_sources_require_post();

		topx_sources_import_save();

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
 * Rejects a state-changing request that did not arrive as a POST. Cacti's
 * csrf-magic validates the token on every POST before dispatch, so requiring
 * POST ensures these mutating handlers cannot be driven by a crafted GET link.
 *
 * @return void
 */
function topx_sources_require_post(): void {
	if (function_exists('csrf_require_post')) {
		csrf_require_post();
	} elseif (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
		header('Allow: POST');
		http_response_code(405);

		exit;
	}
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
		'final_decimals'  => get_nfilter_request_var('final_decimals'),
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

	// A single-data-source template needs a "field=field" expression to rank at
	// all; default it so a new mapping produces data without the administrator
	// having to discover the requirement.
	if ($save['operation'] === '') {
		$save['operation'] = topx_default_operation($hash);
	}

	// Reject an expression the ranking engine cannot execute, rather than saving
	// a definition that would silently return no ranked rows.
	if (!topx_operation_is_executable($save['operation'])) {
		raise_message('topx_operation', __('The Metric Expression is not valid. Use a single field ("field=field"), a sum ("A+B"), a percentage ("A%B"), or append "/N" to scale a single field.', 'topx'), MESSAGE_LEVEL_ERROR);

		if ((int) $save['id'] > 0) {
			header('Location: topx_sources.php?action=edit&id=' . (int) $save['id']);
		} else {
			header('Location: topx_sources.php?action=edit&hash=' . $hash);
		}

		return;
	}

	$range_error = topx_range_validation_error($save);

	if ($range_error !== '') {
		raise_message('topx_range', $range_error, MESSAGE_LEVEL_ERROR);

		if ((int) $save['id'] > 0) {
			header('Location: topx_sources.php?action=edit&id=' . (int) $save['id']);
		} else {
			header('Location: topx_sources.php?action=edit&hash=' . $hash);
		}

		return;
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
				topx_sources_export_redirect(get_nfilter_request_var('selected_items'));

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

	// Both actions submit via Cacti's standard AJAX form. Delete applies the
	// change; Export returns a small page that triggers the file download in a
	// hidden iframe and then returns to the list (see topx_sources_export_redirect),
	// so the XML is never consumed as an XHR response.
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
 * GET entry point for the export download (action=export). Streams every
 * definition for the "Export All" button, or only the selected definitions when
 * the download iframe supplies a validated selected_items list. Read-only, so it
 * is reached by a plain GET (the hidden iframe's src), like Cacti's own
 * template exports.
 *
 * @return void
 */
function topx_sources_export_action(): void {
	$ids = null;

	if (isset_request_var('selected_items')) {
		$selected = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected !== false) {
			$ids = $selected;
		}
	}

	topx_sources_export($ids);
}

/**
 * Renders the small page that drives the export download from the bulk-action
 * confirmation, mirroring Cacti's (and plugin_thold's) template export flow:
 * point a hidden iframe at the action=export GET URL so the browser downloads
 * the XML attachment, then return to the list page. This keeps the confirmation
 * form on Cacti's standard AJAX submit while giving a natural flow (the user
 * lands back on the grid with the file downloaded) instead of being left on a
 * raw XML response.
 *
 * @param string $selected_items The serialized selected_items list to export.
 *
 * @return void
 */
function topx_sources_export_redirect(string $selected_items): void {
	top_header();

	$url = 'topx_sources.php?action=export&selected_items=' . urlencode($selected_items);

	print '<script type="text/javascript" ' . plugin_topx_csp_nonce() . '>
		function topxDownloadStart(url) {
			document.getElementById("topx_download_iframe").src = url;

			setTimeout(function() {
				document.location = "topx_sources.php";
			}, 500);
		}

		$(function() {
			topxDownloadStart(' . json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');
		});
	</script>
	<iframe id="topx_download_iframe" style="display:none;"></iframe>';

	bottom_footer();
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
		'save_component_import' => [
			'method' => 'hidden',
			'value'  => '1',
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
		__('Import complete: %1$d inserted, %2$d updated, %3$d skipped (no matching Data Template on this install).',
			$summary['inserted'], $summary['updated'], $summary['skipped'], 'topx'),
		$summary['skipped'] > 0 ? MESSAGE_LEVEL_WARN : MESSAGE_LEVEL_INFO);

	header('Location: topx_sources.php');
}

/**
 * Builds the clickable comma-delimited token links for an editor helper row.
 * Each link carries the target input id and the literal text to append, which
 * the page script inserts into the Metric Expression or Chart Label field on
 * click.
 *
 * @param array  $tokens     The bare token/field names.
 * @param string $target     The id of the input the tokens append to.
 * @param bool   $wrap_pipes Whether to wrap each token in |pipes| (chart label
 *                           replacements) or use it verbatim (RRD field names).
 * @param string $prefix     An optional token prefix (e.g. 'query_').
 *
 * @return string The comma-delimited anchor HTML, or '' when there are none.
 */
function topx_tokens_links(array $tokens, string $target, bool $wrap_pipes, string $prefix = ''): string {
	$links = [];

	foreach ($tokens as $token) {
		$text = $prefix . $token;

		if ($wrap_pipes) {
			$text = '|' . $text . '|';
		}

		$links[] = "<a href='#' class='topxToken' data-target='" . html_escape($target) .
			"' data-token='" . html_escape($text) . "'>" . html_escape($text) . '</a>';
	}

	return implode(', ', $links);
}

/**
 * Renders the Metric Expression helper markup for a template hash: the template's
 * RRD data source field names as clickable building blocks.
 *
 * @param string $hash The Data Template hash.
 *
 * @return string The helper HTML (an empty hint when the hash has no fields).
 */
function topx_tokens_operation_html(string $hash): string {
	$payload = topx_token_help($hash);

	if (cacti_sizeof($payload['rrd']) === 0) {
		return "<em class='topxTokenHint'>" . __esc('Select a Data Template to list its data sources.', 'topx') . '</em>';
	}

	return "<span class='topxTokenGroup'><strong>" . __esc('Data Sources', 'topx') . ':</strong> ' .
		topx_tokens_links($payload['rrd'], 'operation', false) . '</span>';
}

/**
 * Renders the Chart Label helper markup for a template hash: the always-available
 * host replacements plus, for data-query templates, the |query_<field>| tokens.
 *
 * @param string $hash The Data Template hash.
 *
 * @return string The helper HTML.
 */
function topx_tokens_label_html(string $hash): string {
	$payload = topx_token_help($hash);

	$html = "<span class='topxTokenGroup'><strong>" . __esc('Host', 'topx') . ':</strong> ' .
		topx_tokens_links($payload['host'], 'chart_label', true) . '</span>';

	if (cacti_sizeof($payload['query'])) {
		$html .= "<br><span class='topxTokenGroup'><strong>" . __esc('Data Query', 'topx') . ':</strong> ' .
			topx_tokens_links($payload['query'], 'chart_label', true, 'query_') . '</span>';
	}

	return $html;
}

/**
 * AJAX endpoint: returns the editor token helper HTML for the requested Data
 * Template hash as JSON, so the helpers refresh when the template selection
 * changes without reloading the form.
 *
 * @return string A JSON document with 'operation' and 'chart_label' HTML.
 */
function topx_sources_tokens_ajax(): string {
	$hash = get_nfilter_request_var('hash');

	if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
		$hash = '';
	}

	return json_encode([
		'operation'    => topx_tokens_operation_html($hash),
		'chart_label'  => topx_tokens_label_html($hash),
		'default_operation' => topx_default_operation($hash),
	], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
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
	} elseif (isset_request_var('hash') && preg_match('/^[a-f0-9]{32}$/', get_nfilter_request_var('hash'))) {
		// Adding support for a specific Data Template picked from the grid:
		// pre-select its hash and default the display name to the template name
		// so the form is immediately ready to save.
		$hash          = get_nfilter_request_var('hash');
		$template_name = (string) db_fetch_cell_prepared('SELECT name FROM data_template WHERE hash = ?', array($hash));

		if ($template_name !== '') {
			$source['hash']      = $hash;
			$source['dt_name']   = $template_name;
			$source['operation'] = topx_default_operation($hash);
			$header_label        = __esc('Data Source [new: %s]', $template_name, 'topx');
		} else {
			$header_label = __('Data Source [new]', 'topx');
		}
	} else {
		$header_label = __('Data Source [new]', 'topx');
	}

	print get_md5_include_css('plugins/topx/css/topx.css');

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
			'description'   => __('How the RRD data source fields are combined, e.g. "cpu=cpu" (single field), "traffic_in+traffic_out" (sum) or "hdd_total%hdd_used" (percentage). Append "/N" to divide a single field by a constant so the chart and ranking use the scaled value, e.g. "uptime=uptime/8640000" to convert SNMP uptime timeticks to days.', 'topx'),
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
			'friendly_name' => __('Display Format', 'topx'),
			'description'   => __('How a computed value is formatted for display. "Static Unit" rounds to the Decimal Places and appends a single, fixed Display Unit (e.g. "%", "days"). "Division by Unit Ladder" steps a value through a ladder of divisors and units, showing it in the largest unit whose divided value is at least one (e.g. bits → Kbit → Mbit).', 'topx'),
			'value'         => '|arg1:final_operation|',
			'array'         => ['strip' => __('Static Unit', 'topx'), '/' => __('Division by Unit Ladder', 'topx')],
		],
		'final_decimals' => [
			'method'        => 'drop_array',
			'friendly_name' => __('Decimal Places', 'topx'),
			'description'   => __('How many decimal places the displayed value is rounded to. "Auto" uses two places. Applies to both Display Formats.', 'topx'),
			'value'         => '|arg1:final_decimals|',
			'array'         => topx_source_decimal_labels(),
		],
		'final_unit' => [
			'method'        => 'textbox',
			'friendly_name' => __('Display Unit(s)', 'topx'),
			'description'   => __('The unit shown after the value. For "Static Unit" a single label such as "%" or "days". For "Division by Unit Ladder" a slash-delimited list of unit names, from smallest to largest, paired position-for-position with the Display Unit Ladder divisors (e.g. "bit/Kbit/Mbit/Gbit").', 'topx'),
			'value'         => '|arg1:final_unit|',
			'max_length'    => 100,
			'size'          => 40,
		],
		'final_number' => [
			'method'        => 'textbox',
			'friendly_name' => __('Display Unit Ladder', 'topx'),
			'description'   => __('Only used by "Division by Unit Ladder". A slash-delimited list of divisors, one per unit in Display Unit(s) and in the same order, from smallest to largest (e.g. units "bit/Kbit/Mbit" with divisors "1/1000/1000000", or binary "1/1024/1048576"). The value is shown in the largest unit whose divided result is at least one.', 'topx'),
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
		'replacement_objects' => [
			'method'        => 'spacer',
			'friendly_name' => __('Replacement Objects', 'topx'),
		],
		'operation_help' => [
			'method'        => 'custom',
			'friendly_name' => __('Available Data Sources', 'topx'),
			'description'   => __('The data sources in this template\'s RRD files. Click one to append it to the Metric Expression.', 'topx'),
			'value'         => "<div id='operation_tokens' class='topxTokens'>" . topx_tokens_operation_html((string) $source['hash']) . '</div>',
		],
		'chart_label_help' => [
			'method'        => 'custom',
			'friendly_name' => __('Chart Label Replacements', 'topx'),
			'description'   => __('Click a replacement to append it to the Chart Label Pattern.', 'topx'),
			'value'         => "<div id='chart_label_tokens' class='topxTokens'>" . topx_tokens_label_html((string) $source['hash']) . '</div>',
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

	if ((int) $source['id'] > 0) {
		topx_sources_preview($source);
	}

	?>
	<script type='text/javascript' <?php print plugin_topx_csp_nonce(); ?>>
		function topxBindTokens() {
			$('.topxToken').off('click.topx').on('click.topx', function(event) {
				event.preventDefault();

				var field = $('#' + $(this).data('target'));
				var token = $(this).data('token');

				if (field.length) {
					field.val((field.val() || '') + token).focus();
				}
			});
		}

		function topxRefreshTokens() {
			var hash = $('#hash').val();

			$.get('topx_sources.php?action=tokens&hash=' + encodeURIComponent(hash), function(data) {
				$('#operation_tokens').html(data.operation);
				$('#chart_label_tokens').html(data.chart_label);

				// Seed a single-data-source template's expression so it ranks
				// out of the box, without clobbering anything already typed.
				var op = $('#operation');

				if (op.length && (op.val() || '') === '' && data.default_operation) {
					op.val(data.default_operation);
				}

				topxBindTokens();
			}, 'json');
		}

		// Hide the Display Unit Ladder (divisors) unless the Display Format is
		// "Division by Unit Ladder"; it has no meaning for the Static Unit
		// format. Toggles the whole form row drawn by draw_edit_form.
		function topxToggleDisplayFormat() {
			var ladder = ($('#final_operation').val() === '/');
			$('#row_final_number').toggle(ladder);
		}

		$(function() {
			topxBindTokens();
			$('#hash').on('change', function() { topxRefreshTokens(); });
			topxToggleDisplayFormat();
			$('#final_operation').on('change', function() { topxToggleDisplayFormat(); });
		});
	</script>
	<?php
}

/**
 * Renders a live preview of a saved definition's drilldown cards below the edit
 * form, stacked full-width (independent of the dashboard's draggable columnar
 * layout) so the administrator can change a setting, save, and see the effect
 * without navigating away. Degrades quietly when the template is unresolved or
 * DS Stats is not yet available.
 *
 * @param array $source The saved source definition being edited.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used for URL paths.
 */
function topx_sources_preview(array $source): void {
	global $config;

	$template_id = (int) db_fetch_cell_prepared('SELECT id FROM data_template WHERE hash = ?', array($source['hash']));

	if ($template_id === 0) {
		return;
	}

	topx_dashboard_print_css();

	html_start_box(__('Preview', 'topx'), '100%', '', '3', 'center', '');

	if (!topx_dashboard_dsstats_ready()) {
		html_end_box();

		return;
	}

	topx_dashboard_init_timespan();

	$ds = [
		'key'       => -1 * (int) $source['id'],
		'supported' => true,
		'entry'     => [
			'key'         => -1 * (int) $source['id'],
			'name'        => $source['dt_name'],
			'count'       => 0,
			'supported'   => 'true',
			'template_id' => $template_id,
		],
	];

	$meta = topx_drilldown_card_meta();

	print '<tr class="even"><td>';
	print '<p class="topxDashNote">' . __esc('A live preview of this definition over the selected timespan. Save your changes to refresh it.', 'topx') . '</p>';
	print '<div id="topx_preview" class="topxPreviewStack">';

	foreach (topx_drilldown_available_cards() as $key) {
		print '<div class="topxPreviewCard">'
			. '<h3 class="topxPreviewCardTitle">' . html_escape($meta[$key]['title']) . '</h3>'
			. topx_drilldown_card_body($key, $ds)
			. '</div>';
	}

	print '</div></td></tr>';

	html_end_box();

	print '<script type="text/javascript" ' . plugin_topx_csp_nonce() . ' src="' . $config['url_path'] . 'plugins/topx/js/topx_dashboard.js?v=' . filemtime($config['base_path'] . '/plugins/topx/js/topx_dashboard.js') . '"></script>';
	print '<script type="text/javascript" ' . plugin_topx_csp_nonce() . '>$(function() { if (typeof topxDashRenderCharts === "function") { topxDashRenderCharts(document.getElementById("topx_preview")); } });</script>';
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
		'has_data' => [
			'filter'  => FILTER_VALIDATE_REGEXP,
			'options' => ['options' => ['regexp' => '(true|false)']],
			'pageset' => true,
			'default' => read_config_option('default_has') == 'on' ? 'true' : 'false',
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

	print get_md5_include_css('plugins/topx/css/topx.css');

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

	if (get_request_var('has_data') == 'true') {
		$sql_where    .= ($sql_where === '' ? 'WHERE ' : ' AND ') . 'in_use > 0';
	}

	$sql_order = get_order_string();
	$sql_limit = ' LIMIT ' . ($rows * (get_request_var('page') - 1)) . ',' . $rows;

	$total_rows = db_fetch_cell_prepared("SELECT COUNT(*) FROM ($base) AS rs $sql_where", $sql_params);

	$list = db_fetch_assoc_prepared("SELECT * FROM ($base) AS rs $sql_where $sql_order $sql_limit", $sql_params);

	$nav = html_nav_bar('topx_sources.php?filter=' . urlencode(get_request_var('filter')), MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, 8, __('Data Sources', 'topx'), 'page', 'main');

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
			'display' => __('Status', 'topx'),
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

			if ($is_source) {
				$edit_url = 'topx_sources.php?action=edit&id=' . (int) $row['id'];
			} else {
				$edit_url = 'topx_sources.php?action=edit&hash=' . html_escape($row['hash']);
			}

			// A supported definition whose stored hash matches no installed Data
			// Template (e.g. a seeded default for a package that is not present,
			// or an imported definition) is flagged so the administrator can see
			// it needs re-pointing rather than appearing as a blank template.
			$template = (string) $row['template'];

			if ($template === '') {
				$template = $is_source ? __('(template not installed)', 'topx') : '';
			}

			form_alternate_row('line' . ($is_source ? (int) $row['id'] : 't' . html_escape($row['hash'])), true);

			form_selectable_cell(filter_value($row['name'] !== '' ? $row['name'] : $row['template'], get_request_var('filter'), $edit_url), $row['id']);
			form_selectable_cell(html_escape($template), $row['id']);
			form_selectable_cell(
				$row['supported'] === 'Yes'
					? "<span class='deviceStatus deviceUp'>" . __esc('Supported', 'topx') . '</span>'
					: "<span class='deviceStatus deviceDown'>" . __esc('Not Supported', 'topx') . '</span>',
				$row['id'], '', 'text-align:center'
			);
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
							<select id='supported'>
								<option value='-1'<?php print (get_request_var('supported') == '-1' ? ' selected' : ''); ?>><?php print __('All', 'topx'); ?></option>
								<option value='1'<?php print (get_request_var('supported') == '1' ? ' selected' : ''); ?>><?php print __('Yes', 'topx'); ?></option>
								<option value='0'<?php print (get_request_var('supported') == '0' ? ' selected' : ''); ?>><?php print __('No', 'topx'); ?></option>
							</select>
						</td>
						<td><?php print __('Rows', 'topx'); ?></td>
						<td>
							<select id='rows'>
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
								<input type='checkbox' id='has_data' <?php print (get_request_var('has_data') == 'true' ? 'checked' : ''); ?>>
								<label for='has_data'><?php print __('Has Data Sources', 'topx'); ?></label>
							</span>
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
					strURL += '&filter='+encodeURIComponent($('#filter').val());
					strURL += '&supported='+$('#supported').val();
					strURL += '&has_data='+$('#has_data').is(':checked');
					strURL += '&rows='+$('#rows').val();
					loadPageNoHeader(strURL);
				}

				function clearFilter() {
					loadPageNoHeader('topx_sources.php?clear=1&header=false');
				}

				$(function() {
					$('#refresh').click(function() { applyFilter(); });
					$('#clear').click(function() { clearFilter(); });
					$('#supported, #rows').on('change', function() { applyFilter(); });
					$('#has_data').on('click', function() { applyFilter(); });
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
