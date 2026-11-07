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

/*
 * TopX card dashboards. A draggable grid of panel/cards with per-card tools, an
 * "Add card" catalogue, a Cacti timespan selector and an auto-refresh interval,
 * mirroring the card interaction model used by the apcupsd, hmib, mactrack and
 * syslog dashboards. Two scopes share the same engine: an all-data-source
 * "Overview" and a per-data-source "Drilldown". Card order, expanded state and
 * the refresh interval persist per user in settings_user.
 */

include_once(__DIR__ . '/functions.php');
include_once(__DIR__ . '/arrays.php');

/**
 * Emit the Dashboard stylesheets (the theme-neutral base plus the card engine).
 *
 * @return void
 */
function topx_dashboard_print_css(): void {
	print get_md5_include_css('plugins/topx/css/topx.css');
	print get_md5_include_css('plugins/topx/css/topx_dashboard.css');
}

/**
 * Whether DS Stats is enabled; prints a guidance message (with a link to the
 * settings page) when it is not, since every card relies on the dsstats tables.
 *
 * @return bool True when DS Stats is enabled.
 *
 * @global array $config Cacti global configuration array.
 */
function topx_dashboard_dsstats_ready(): bool {
	global $config;

	if (read_config_option('dsstats_enable') == 'on') {
		return true;
	}

	print '<div class="topxDashEmpty" style="padding:16px">'
		. __('Please enable and configure %sDS Stats%s to use TopX.', '<a href="' . html_escape($config['url_path']) . 'settings.php?tab=data">', '</a>', 'topx')
		. '</div>';

	return false;
}

/**
 * Restore Cacti's standard graph timespan from the session/request so every card
 * shares one selected window. Safe to call more than once per request.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array.
 */
function topx_dashboard_init_timespan(): void {
	static $done = false;

	if ($done) {
		return;
	}

	$done = true;

	global $config;

	include($config['base_path'] . '/lib/timespan_settings.php');
}

/**
 * The dsstats rollup table matching the currently selected Cacti timespan.
 *
 * @return string The whitelisted data_source_stats_* table name.
 */
function topx_dashboard_bucket(): string {
	// The page render (and the card AJAX path) initialises Cacti's session
	// timespan; read it here rather than re-including the timespan library on
	// every card so card rendering stays self-contained.
	$start = function_exists('get_current_graph_start') ? (int) get_current_graph_start() : 0;
	$end   = function_exists('get_current_graph_end') ? (int) get_current_graph_end() : 0;
	$span  = ($end > $start) ? $end - $start : 7200;

	return topx_timespan_bucket($span);
}

/**
 * Render a card shell (section, header toolbar and body).
 *
 * @param string $key        The card identifier.
 * @param string $title      The card title.
 * @param int    $span       The grid span (2, 4 or 8).
 * @param bool   $expandable Whether the card offers an expand toggle.
 * @param bool   $expanded   Whether the card opens expanded.
 * @param string $body       The card body HTML.
 *
 * @return string The card HTML.
 */
function topx_dashboard_card_chrome(string $key, string $title, int $span, bool $expandable, bool $expanded, string $body): string {
	$tools = '';

	if ($expandable) {
		$tools .= '<button type="button" class="topxDashCardTool" data-tool="expand" aria-label="' . __esc('Show more', 'topx') . '" title="' . __esc('Show more', 'topx') . '"><i class="fas fa-chevron-down" aria-hidden="true"></i></button>';
		$tools .= '<button type="button" class="topxDashCardTool" data-tool="collapse" aria-label="' . __esc('Show less', 'topx') . '" title="' . __esc('Show less', 'topx') . '"><i class="fas fa-chevron-up" aria-hidden="true"></i></button>';
	}

	$tools .= '<button type="button" class="topxDashCardTool" data-tool="maximize" aria-label="' . __esc('Open in a dialog', 'topx') . '" title="' . __esc('Open in a dialog', 'topx') . '"><i class="fas fa-window-maximize" aria-hidden="true"></i></button>';
	$tools .= '<button type="button" class="topxDashCardTool" data-tool="refresh" aria-label="' . __esc('Refresh', 'topx') . '" title="' . __esc('Refresh', 'topx') . '"><i class="fas fa-sync-alt" aria-hidden="true"></i></button>';
	$tools .= '<button type="button" class="topxDashCardTool" data-tool="remove" aria-label="' . __esc('Remove from page', 'topx') . '" title="' . __esc('Remove from page', 'topx') . '"><i class="fas fa-times" aria-hidden="true"></i></button>';

	$classes = 'topxDashCard topxDashSpan' . $span;

	if ($expandable) {
		$classes .= ' topxDashCardExpandable';
	}

	if ($expanded) {
		$classes .= ' topxDashCardExpanded';
	}

	return '<section class="' . $classes . '" data-card="' . html_escape($key) . '">'
		. '<header class="topxDashCardHeader">'
		. '<button type="button" class="topxDashCardDrag" aria-label="' . __esc('Drag to reorder card', 'topx') . '"><i class="fas fa-bars" aria-hidden="true"></i></button>'
		. '<h2 class="topxDashCardTitle">' . html_escape($title) . '</h2>'
		. '<span class="topxDashCardTools">' . $tools . '</span>'
		. '</header>'
		. '<div class="topxDashCardBody">' . $body . '</div>'
		. '</section>';
}

/**
 * Render a single stat tile.
 *
 * @param string $value    The already-formatted value.
 * @param string $label    The tile label.
 * @param string $modifier An optional accent modifier ('up', 'down', 'rec').
 *
 * @return string The tile HTML.
 */
function topx_dashboard_tile(string $value, string $label, string $modifier = ''): string {
	return '<div class="topxFleetTile' . ($modifier !== '' ? ' topxFleetTile--' . $modifier : '') . '">'
		. '<span class="topxFleetTileValue">' . html_escape($value) . '</span>'
		. '<span class="topxFleetTileLabel">' . html_escape($label) . '</span>'
		. '</div>';
}

/**
 * Render a 180-degree SVG gauge showing a value against a scale.
 *
 * @param float|null $current      The value the arc fills to.
 * @param float|null $min          Optional min tick.
 * @param float|null $max          Optional max tick.
 * @param float      $scale        The full-scale value.
 * @param string     $center_label The large label inside the gauge.
 * @param string     $sub_label    An optional caption below the label.
 *
 * @return string The gauge HTML.
 */
function topx_dashboard_gauge(?float $current, ?float $min, ?float $max, float $scale, string $center_label, string $sub_label = ''): string {
	$frac = ($current !== null && $scale > 0) ? max(0.0, min(1.0, $current / $scale)) : 0.0;
	$dash = number_format($frac * 100, 2, '.', '');

	$tick = function (?float $value) use ($scale): string {
		if ($value === null || $scale <= 0) {
			return '';
		}

		$frac  = max(0.0, min(1.0, $value / $scale));
		$angle = M_PI * (1 - $frac);
		$ix    = 60 + 44 * cos($angle);
		$iy    = 60 - 44 * sin($angle);
		$ox    = 60 + 56 * cos($angle);
		$oy    = 60 - 56 * sin($angle);

		return '<line class="topxGaugeTick" x1="' . number_format($ix, 2, '.', '') . '" y1="' . number_format($iy, 2, '.', '')
			. '" x2="' . number_format($ox, 2, '.', '') . '" y2="' . number_format($oy, 2, '.', '') . '" />';
	};

	return '<div class="topxGauge">'
		. '<svg class="topxGaugeSvg" viewBox="0 0 120 68" role="img" aria-label="' . html_escape($center_label) . '">'
		. '<path class="topxGaugeTrack" d="M10 60 A50 50 0 0 1 110 60" fill="none" pathLength="100" />'
		. '<path class="topxGaugeValue" d="M10 60 A50 50 0 0 1 110 60" fill="none" pathLength="100" stroke-dasharray="' . $dash . ' 100" />'
		. $tick($min) . $tick($max)
		. '</svg>'
		. '<div class="topxGaugeText">'
		. '<span class="topxGaugeValueText">' . html_escape($center_label) . '</span>'
		. ($sub_label !== '' ? '<span class="topxGaugeSub">' . html_escape($sub_label) . '</span>' : '')
		. '</div></div>';
}

/**
 * Render a billboard chart placeholder. The JSON payload is read by
 * js/topx_dashboard.js after the card is inserted so it survives AJAX refresh.
 *
 * @param array  $labels     The category labels.
 * @param array  $values     The numeric values.
 * @param string $title      The value-axis label.
 * @param bool   $rotated    Whether to render horizontal bars.
 * @param float  $average    Optional average annotation line (0 to omit).
 * @param array  $bar_colors Optional ordered per-bar colours (a "range"); when
 *                           empty js/topx_dashboard.js uses its default palette.
 * @param array  $full_labels Optional full labels shown on hover when the axis
 *                            labels are shortened (same order as $labels).
 * @param array  $peaks       Optional per-bar peak values (same order as
 *                            $values); when present a rotated chart renders a
 *                            bullet-style bar (a thin average measure bar nested
 *                            inside a thicker peak band).
 * @param array  $peak_colors Optional per-bar colours for the peak range band
 *                            (same order as $peaks); a muted tint of the peak's
 *                            own health band.
 * @param array  $legend      Optional custom legend entries ([{label, color}]);
 *                            when present js/topx_dashboard.js renders its own
 *                            swatch legend instead of billboard's series legend.
 *
 * @return string The chart placeholder HTML.
 */
function topx_dashboard_chart(array $labels, array $values, string $title, bool $rotated, float $average = 0.0, array $bar_colors = [], array $full_labels = [], array $peaks = [], array $peak_colors = [], array $legend = []): string {
	$payload = [
		'series'  => __('Value', 'topx'),
		'labels'  => array_values($labels),
		'values'  => array_map('floatval', array_values($values)),
		'peaks'   => array_map('floatval', array_values($peaks)),
		'title'   => $title,
		'rotated' => $rotated,
		'average' => $average,
		'averageLabel' => __('Average', 'topx'),
		'peakLabel'    => __('Peak', 'topx'),
		'barColors'    => array_values($bar_colors),
		'peakColors'   => array_values($peak_colors),
		'legend'       => array_values($legend),
		'fullLabels'   => array_values($full_labels),
	];

	if (cacti_count($labels) === 0) {
		return '<p class="topxDashEmpty">' . __esc('No data to chart yet.', 'topx') . '</p>';
	}

	return '<div class="topx-bb-chart" data-chart="' . html_escape(json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) . '"></div>';
}

/**
 * Arm automatic refresh for a dashboard, the same way graph_view.php does, then
 * let js/topx_dashboard.js pick how to use it (in-place card refresh on Cacti
 * 1.3+, an own timer on 1.2.x).
 *
 * @param int    $seconds The interval; 0 or less leaves Cacti's default timer.
 * @param string $page    The URL Cacti re-fetches on each tick.
 *
 * @return void
 */
function topx_dashboard_print_refresh(int $seconds, string $page): void {
	if ($seconds <= 0) {
		return;
	}

	print '<script type="text/javascript" ' . plugin_topx_csp_nonce() . '>'
		. '$(function() {'
		. 'if (!document.getElementById("topx_dashboard") || typeof setupPageTimeout !== "function") { return; }'
		. 'refreshIsLogout = false;'
		. 'refreshPage = ' . json_encode($page, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'
		. 'refreshMSeconds = ' . ($seconds * 1000) . ';'
		. 'if (typeof topxDashStartRefresh === "function") { topxDashStartRefresh(); } else { setupPageTimeout(); }'
		. '});</script>';
}

/**
 * The settings_user key the layout for a scope is stored under.
 *
 * @param string $scope 'overview' or 'drill'.
 *
 * @return string The settings key.
 */
function topx_dashboard_layout_key(string $scope): string {
	return 'topx_dashboard_layout_' . ($scope === 'drill' ? 'drill' : 'overview');
}

/**
 * Persist a scope's card order and expanded state for the current user.
 *
 * @param string $scope    'overview' or 'drill'.
 * @param array  $order    The card keys in display order.
 * @param array  $expanded The expanded flags keyed by card.
 *
 * @return bool True on success, false when the layout is too large to store.
 */
function topx_dashboard_layout_store(string $scope, array $order, array $expanded): bool {
	$json = json_encode(['order' => array_values($order), 'expanded' => $expanded], JSON_UNESCAPED_SLASHES);

	if ($json === false || strlen($json) > 60000) {
		return false;
	}

	set_user_setting(topx_dashboard_layout_key($scope), $json);

	return true;
}

/**
 * The current user's saved layout for a scope: present cards in order plus their
 * expanded state. With no saved layout every card is present in the default
 * order; cards absent from a saved order live in the "Add" catalogue.
 *
 * @param string $scope     'overview' or 'drill'.
 * @param array  $available The default card order for the scope.
 *
 * @return array{order: string[], expanded: array<string, bool>}
 */
function topx_dashboard_layout(string $scope, array $available): array {
	$saved = json_decode((string) read_user_setting(topx_dashboard_layout_key($scope), '', true), true);

	if (!is_array($saved) || !isset($saved['order']) || !is_array($saved['order'])) {
		return ['order' => $available, 'expanded' => []];
	}

	$order = [];

	foreach ($saved['order'] as $key) {
		if (is_string($key) && in_array($key, $available, true) && !in_array($key, $order, true)) {
			$order[] = $key;
		}
	}

	$expanded = [];

	if (isset($saved['expanded']) && is_array($saved['expanded'])) {
		foreach ($saved['expanded'] as $key => $on) {
			if ($on && is_string($key) && in_array($key, $available, true)) {
				$expanded[$key] = true;
			}
		}
	}

	return ['order' => $order, 'expanded' => $expanded];
}

/**
 * Persist the posted layout for a scope, validated to that scope's known cards.
 *
 * @param string $scope     'overview' or 'drill'.
 * @param array  $available The scope's known card keys.
 *
 * @return string A JSON status document.
 */
function topx_dashboard_layout_save(string $scope, array $available): string {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !function_exists('csrf_check') || !csrf_check(false)) {
		return (string) json_encode(['error' => __('Invalid request. Please try again.', 'topx')]);
	}

	$posted = json_decode(isset_request_var('layout') ? (string) get_nfilter_request_var('layout') : '', true);

	$order    = [];
	$expanded = [];

	if (is_array($posted)) {
		if (isset($posted['order']) && is_array($posted['order'])) {
			foreach ($posted['order'] as $key) {
				if (is_string($key) && in_array($key, $available, true) && !in_array($key, $order, true)) {
					$order[] = $key;
				}
			}
		}

		if (isset($posted['expanded']) && is_array($posted['expanded'])) {
			foreach ($posted['expanded'] as $key => $on) {
				if ($on && is_string($key) && in_array($key, $available, true)) {
					$expanded[$key] = true;
				}
			}
		}
	}

	if (!topx_dashboard_layout_store($scope, $order, $expanded)) {
		return (string) json_encode(['error' => __('This dashboard has too many cards to save its layout.', 'topx')]);
	}

	return (string) json_encode(['ok' => true]);
}

/**
 * Persist the posted auto-refresh interval for a scope. Accepts only a
 * CSRF-validated POST carrying a value the refresh picker offers.
 *
 * @param string $scope 'overview' or 'drill'.
 *
 * @return string A JSON status document.
 *
 * @global array $page_refresh_interval Cacti's allowed refresh intervals.
 */
function topx_dashboard_refresh_save(string $scope): string {
	global $page_refresh_interval;

	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !function_exists('csrf_check') || !csrf_check(false)) {
		return (string) json_encode(['error' => __('Invalid request. Please try again.', 'topx')]);
	}

	$posted    = isset_request_var('refresh') ? (string) get_nfilter_request_var('refresh') : '';
	$intervals = (is_array($page_refresh_interval) && cacti_sizeof($page_refresh_interval)) ? $page_refresh_interval : [30 => '', 60 => '', 300 => ''];
	$allowed   = array_map('intval', array_keys($intervals));
	$allowed[] = 0;

	if (!preg_match('/^[0-9]+$/', $posted) || !in_array((int) $posted, $allowed, true)) {
		return (string) json_encode(['error' => __('Invalid refresh interval.', 'topx')]);
	}

	set_user_setting('topx_dashboard_refresh_' . ($scope === 'drill' ? 'drill' : 'overview'), (int) $posted);

	return (string) json_encode(['ok' => true]);
}

/**
 * Render the TopX tab bar.
 *
 * @param string $current The current tab key ('overview' or 'drill').
 *
 * @return void
 */
function topx_dashboard_tabs(string $current): void {
	$tabs = [
		'overview' => ['label' => __('Overview', 'topx'),    'url' => 'topx.php?action=dashboard'],
		'drill'    => ['label' => __('Data Source', 'topx'), 'url' => 'topx.php?action=view'],
	];

	print "<div class='tabs'><nav><ul>";

	foreach ($tabs as $key => $tab) {
		print "<li><a class='pic" . ($key === $current ? " selected'" : "'") . " href='" . html_escape($tab['url']) . "'>" . html_escape($tab['label']) . '</a></li>';
	}

	print '</ul></nav></div>';
}

/**
 * Render the shared toolbar refresh control + save-status region.
 *
 * @param int $current_refresh The persisted refresh interval in seconds.
 *
 * @return void
 *
 * @global array $page_refresh_interval Cacti's allowed refresh intervals.
 */
function topx_dashboard_toolbar_refresh(int $current_refresh): void {
	global $page_refresh_interval;

	if (empty($page_refresh_interval) || !is_array($page_refresh_interval)) {
		$page_refresh_interval = [0 => __('No Refresh', 'topx'), 30 => __('30 Seconds', 'topx'), 60 => __('1 Minute', 'topx'), 300 => __('5 Minutes', 'topx')];
	}

	print '<div class="topxDashRefresh">';
	print '<label class="topxDashToolbarLabel" for="topx_dashboard_refresh">' . __esc('Refresh', 'topx') . '</label>';
	print '<select id="topx_dashboard_refresh" class="topxDashRefreshInterval">';
	print '<option value="0"' . ($current_refresh == 0 ? ' selected' : '') . '>' . __esc('No Refresh', 'topx') . '</option>';

	foreach ($page_refresh_interval as $seconds => $display_text) {
		if ((int) $seconds === 0) {
			continue;
		}

		print '<option value="' . (int) $seconds . '"' . ($current_refresh == $seconds ? ' selected' : '') . '>' . html_escape($display_text) . '</option>';
	}

	print '</select>';
	print '<button type="button" id="topx_dashboard_refresh_now" class="topxDashCardTool topxDashRefreshNow" aria-label="' . __esc('Refresh', 'topx') . '" title="' . __esc('Refresh', 'topx') . '"><i class="fas fa-sync-alt" aria-hidden="true"></i></button>';
	print '</div>';
	print '<span class="topxDashSaveStatus" role="status" aria-live="polite" hidden></span>';
}

/**
 * Render a Cacti timespan selector into the toolbar.
 *
 * @return void
 *
 * @global array $graph_timespans Cacti's predefined timespan labels.
 */
function topx_dashboard_toolbar_timespan(): void {
	global $graph_timespans;

	print '<label class="topxDashToolbarLabel" for="topx_dashboard_timespan">' . __esc('Timespan', 'topx') . '</label>';
	print '<select id="topx_dashboard_timespan" class="topxDashTimespan">';

	$timespans    = is_array($graph_timespans) ? $graph_timespans : [];
	$current_span = isset($_SESSION['sess_current_timespan']) ? (int) $_SESSION['sess_current_timespan'] : 0;

	foreach ($timespans as $key => $display_text) {
		print '<option value="' . (int) $key . '"' . ($current_span === (int) $key ? ' selected' : '') . '>' . html_escape($display_text) . '</option>';
	}

	print '</select>';
}

/**
 * Render the "Add card" selector listing cards not already on the page.
 *
 * @param array $absent The absent card keys.
 * @param array $meta   The card metadata (title per key).
 *
 * @return void
 */
function topx_dashboard_toolbar_add(array $absent, array $meta): void {
	print '<label class="topxDashToolbarLabel" for="topx_dashboard_add">' . __esc('Add card', 'topx') . '</label>';
	print '<select id="topx_dashboard_add" class="topxDashAdd"><option value="">' . __esc('Add a card…', 'topx') . '</option>';

	foreach ($absent as $key) {
		print '<option value="' . html_escape($key) . '">' . html_escape($meta[$key]['title']) . '</option>';
	}

	print '</select>';
}

/**
 * Renders an Edit/Add Rule link in the drilldown toolbar for users who may
 * manage the TopX Data Sources. For a supported source it links to the editor
 * for that definition; for an unsupported template it links to the editor
 * pre-pointed at the template so a rule can be added. Hidden for users without
 * the management realm.
 *
 * @param array $ds The resolved data source (key, supported).
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used for URL paths.
 */
function topx_dashboard_toolbar_edit(array $ds): void {
	global $config;

	if (!is_realm_allowed(1) && !api_user_realm_auth('topx_sources.php')) {
		return;
	}

	if (!empty($ds['supported'])) {
		$url   = 'topx_sources.php?action=edit&id=' . (-1 * (int) $ds['key']);
		$label = __('Edit Rule', 'topx');
	} else {
		$hash = db_fetch_cell_prepared('SELECT hash FROM data_template WHERE id = ?', array((int) $ds['key']));

		if (empty($hash)) {
			return;
		}

		$url   = 'topx_sources.php?action=edit&hash=' . $hash;
		$label = __('Add Rule', 'topx');
	}

	print '<a class="ui-button ui-corner-all ui-widget ui-state-active topxDashEdit" role="button" href="' . html_escape($config['url_path'] . 'plugins/topx/' . $url) . '">' . html_escape($label) . '</a>';
}

/* ============================================================================
 * Overview scope: aggregate across every TopX data source.
 * ========================================================================== */

/**
 * The Overview card metadata keyed by card, in default order.
 *
 * @return array
 */
function topx_overview_card_meta(): array {
	return [
		'summary'      => ['title' => __('Catalogue Summary', 'topx'),  'span' => 4, 'expandable' => false],
		'distribution' => ['title' => __('Source Distribution', 'topx'), 'span' => 4, 'expandable' => false],
		'uptime'       => ['title' => __('Uptime by Host', 'topx'),      'span' => 4, 'expandable' => false],
		'types'        => ['title' => __('Data Sources', 'topx'),        'span' => 4, 'expandable' => true],
	];
}

/**
 * The Overview card keys in default order.
 *
 * @return string[]
 */
function topx_overview_available_cards(): array {
	return array_keys(topx_overview_card_meta());
}

/**
 * Render the body of an Overview card.
 *
 * @param string $key The card key.
 *
 * @return string The card body HTML.
 */
function topx_overview_card_body(string $key): string {
	global $config;

	ob_start();

	switch ($key) {
		case 'summary':
			$stats = topx_overview_stats();

			print '<div class="topxFleetTiles">';
			print topx_dashboard_tile(number_format_i18n($stats['total_types'], 0), __('Source Types', 'topx'));
			print topx_dashboard_tile(number_format_i18n($stats['supported_types'], 0), __('Supported', 'topx'), $stats['supported_types'] > 0 ? 'up' : '');
			print topx_dashboard_tile(number_format_i18n($stats['unsupported_types'], 0), __('Unsupported', 'topx'));
			print topx_dashboard_tile(number_format_i18n($stats['total_sources'], 0), __('Data Sources', 'topx'));
			print '</div>';

			break;
		case 'distribution':
			$stats = topx_overview_stats();

			$segments = [
				['count' => $stats['supported_types'],   'class' => 'up',  'label' => __('Supported', 'topx')],
				['count' => $stats['unsupported_types'], 'class' => 'dis', 'label' => __('Unsupported', 'topx')],
			];

			$bar_total = array_sum(array_column($segments, 'count'));

			if ($bar_total === 0) {
				print '<p class="topxDashEmpty">' . __esc('No data sources are available yet.', 'topx') . '</p>';

				break;
			}

			print '<div class="topxStackBar">';

			foreach ($segments as $segment) {
				if ($segment['count'] > 0) {
					$width = $segment['count'] / $bar_total * 100;
					print '<div class="topxStackSeg topxStackSeg--' . $segment['class'] . '" style="width:' . number_format($width, 2, '.', '') . '%" title="' . html_escape($segment['label'] . ': ' . $segment['count']) . '"></div>';
				}
			}

			print '</div>';
			print '<div class="topxStackLegend">';

			foreach ($segments as $segment) {
				print '<span><span class="topxStackChip topxStackSeg--' . $segment['class'] . '"></span>' . html_escape($segment['label'] . ' (' . $segment['count'] . ')') . '</span>';
			}

			print '</div>';

			break;
		case 'uptime':
			$buckets = topx_uptime_bucket_days(topx_uptime_host_days());

			// Avoid '<'/'>' in labels: billboard injects the tooltip title as
			// HTML, so a literal angle bracket leaks markup (e.g. "</th>").
			$labels = [
				__('Up to 1 day', 'topx'),
				__('1 day – 1 week', 'topx'),
				__('1 week – 1 month', 'topx'),
				__('1 – 6 months', 'topx'),
				__('Over 6 months', 'topx'),
			];

			$values = [
				$buckets['lt_day'],
				$buckets['day_week'],
				$buckets['week_month'],
				$buckets['month_six'],
				$buckets['gt_six'],
			];

			if (array_sum($values) === 0) {
				print '<p class="topxDashEmpty">' . __esc('No host uptime data sources were found.', 'topx') . '</p>';

				break;
			}

			// A distinct multi-hue "colour wheel" across the ranges (warm ->
			// cool, short uptime -> long) so the chart is not monotone.
			$uptime_colors = ['#e63946', '#f3722c', '#f9c74f', '#90be6d', '#2a9d8f'];

			print topx_dashboard_chart($labels, $values, __('Hosts', 'topx'), false, 0.0, $uptime_colors);

			break;
		case 'types':
			$list = topx_get_ds_list();

			if (cacti_sizeof($list) === 0) {
				print '<p class="topxDashEmpty">' . __esc('No data sources are available yet.', 'topx') . '</p>';

				break;
			}

			print '<table class="topxDashTable">';
			print '<thead><tr>'
				. '<th class="topxDashSortable">' . __esc('Data Source', 'topx') . '</th>'
				. '<th class="topxDashSortable topxDashCenter">' . __esc('Supported', 'topx') . '</th>'
				. '<th class="topxDashSortable topxDashNum" data-sort="num">' . __esc('Count', 'topx') . '</th>'
				. '<th class="topxDashCenter">' . __esc('Drilldown', 'topx') . '</th>'
				. '</tr></thead><tbody>';

			foreach ($list as $entry) {
				$supported = ($entry['supported'] === 'true');
				$url       = html_escape($config['url_path']) . 'plugins/topx/topx.php?action=view&ds=' . html_escape($entry['key']);

				print '<tr>'
					. '<td>' . html_escape($entry['name']) . '</td>'
					. '<td class="topxDashCenter">' . ($supported ? __esc('Yes', 'topx') : __esc('No', 'topx')) . '</td>'
					. '<td class="topxDashNum" data-sort-value="' . (int) $entry['count'] . '">' . number_format_i18n((int) $entry['count'], 0) . '</td>'
					. '<td class="topxDashCenter"><a class="pic topxDashGlyph" href="' . $url . '" title="' . __esc('Open drilldown', 'topx') . '"><i class="fas fa-chart-bar" aria-hidden="true"></i></a></td>'
					. '</tr>';
			}

			print '</tbody></table>';

			break;
	}

	return (string) ob_get_clean();
}

/**
 * Render a complete Overview card.
 *
 * @param string $key      The card key.
 * @param bool   $expanded Whether the card opens expanded.
 *
 * @return string The card HTML, or '' for an unknown key.
 */
function topx_overview_render_card(string $key, bool $expanded = false): string {
	$meta = topx_overview_card_meta();

	if (!isset($meta[$key])) {
		return '';
	}

	$def = $meta[$key];

	return topx_dashboard_card_chrome($key, (string) $def['title'], (int) $def['span'], (bool) $def['expandable'], $expanded, topx_overview_card_body($key));
}

/**
 * AJAX: render a single Overview card.
 *
 * @return string A JSON document with the card key and rendered HTML.
 */
function topx_overview_card_ajax(): string {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !function_exists('csrf_check') || !csrf_check(false)) {
		return (string) json_encode(['error' => __('Invalid request. Please try again.', 'topx')]);
	}

	$key = isset_request_var('card') ? (string) get_nfilter_request_var('card') : '';

	if (!in_array($key, topx_overview_available_cards(), true)) {
		return (string) json_encode(['error' => __('Unknown card.', 'topx')]);
	}

	$expanded = isset_request_var('expanded') && get_nfilter_request_var('expanded') === '1';

	return (string) json_encode(['card' => $key, 'html' => topx_overview_render_card($key, $expanded)]);
}

/**
 * Render the Overview dashboard: a draggable grid of aggregate cards across the
 * whole TopX catalogue, with a timespan selector and auto-refresh.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array.
 */
function topx_overview_dashboard(): void {
	global $config;

	topx_dashboard_print_css();
	topx_dashboard_tabs('overview');

	if (!topx_dashboard_dsstats_ready()) {
		return;
	}

	topx_dashboard_init_timespan();

	$current_refresh = (int) read_user_setting('topx_dashboard_refresh_overview', 0);
	$refresh_page    = $config['url_path'] . 'plugins/topx/topx.php?action=dashboard&header=false';

	print '<script type="text/javascript" ' . plugin_topx_csp_nonce() . ' src="' . $config['url_path'] . 'plugins/topx/js/topx_dashboard.js?v=' . filemtime($config['base_path'] . '/plugins/topx/js/topx_dashboard.js') . '"></script>';

	topx_dashboard_print_refresh($current_refresh, $refresh_page);

	$meta   = topx_overview_card_meta();
	$layout = topx_dashboard_layout('overview', topx_overview_available_cards());
	$absent = array_values(array_diff(topx_overview_available_cards(), $layout['order']));

	print '<div class="topxDashToolbar">';
	topx_dashboard_toolbar_timespan();
	topx_dashboard_toolbar_add($absent, $meta);
	topx_dashboard_toolbar_refresh($current_refresh);
	print '</div>';

	print '<div id="topx_dashboard" class="topxDashGrid"'
		. ' data-card-action="overview_card" data-layout-action="overview_layout" data-refresh-action="overview_refresh"'
		. ' data-refresh-page="' . html_escape($refresh_page) . '"'
		. ' data-reload="topx.php?action=dashboard">';

	foreach ($layout['order'] as $key) {
		print topx_overview_render_card($key, !empty($layout['expanded'][$key]));
	}

	print '</div>';
	print '<div id="topx_dashboard_dialog" class="topxDashDialog" style="display:none"></div>';

	$catalog = [];

	foreach (topx_overview_available_cards() as $key) {
		$catalog[$key] = $meta[$key]['title'];
	}

	print '<script type="text/javascript" ' . plugin_topx_csp_nonce() . '>var topxDashCatalog = ' . json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '; initTopxDashboard();</script>';
}

/* ============================================================================
 * Drilldown scope: one data source in depth.
 * ========================================================================== */

/**
 * Resolve the data source key the drilldown should display: a validated 'ds'
 * request variable wins and is remembered; otherwise the remembered key is used,
 * falling back to the first catalogue entry.
 *
 * @return array{key: int, supported: bool, entry: array}|null The resolved data
 *         source, or null when the catalogue is empty.
 */
function topx_dashboard_resolve_ds(): ?array {
	$list = topx_get_ds_list();

	if (cacti_sizeof($list) === 0) {
		return null;
	}

	$key = null;

	if (isset_request_var('ds') && array_key_exists(get_nfilter_request_var('ds'), $list)) {
		$key = (int) get_nfilter_request_var('ds');
		set_user_setting('topx_dashboard_ds', $key);
	}

	if ($key === null) {
		$stored = (int) read_user_setting('topx_dashboard_ds', 0);

		if (array_key_exists($stored, $list)) {
			$key = $stored;
		}
	}

	if ($key === null) {
		$key = (int) array_key_first($list);
	}

	return [
		'key'       => $key,
		'supported' => ($list[$key]['supported'] === 'true'),
		'entry'     => $list[$key],
	];
}

/**
 * The persisted drilldown filter (records, sort, what), validated and stored in
 * the session. A validated request value wins and is remembered. When no sort
 * has been chosen yet, the source's own Default Sort is used so a decreasing
 * source opens lowest-first rather than always defaulting to descending.
 *
 * @param string $default_sort The source's Default Sort ('asc' or 'desc') used
 *                             when the session has no stored sort yet.
 *
 * @return array{records: int, sort: string, what: string}
 */
function topx_dashboard_drill_filter(string $default_sort = 'desc'): array {
	$filters = topx_filter_arrays();

	if (isset_request_var('records') && array_key_exists(get_nfilter_request_var('records'), $filters['records'])) {
		$_SESSION['sess_topx_records'] = get_nfilter_request_var('records');
	}

	if (!isset($_SESSION['sess_topx_records'])) {
		$_SESSION['sess_topx_records'] = 5;
	}

	if (isset_request_var('sort') && array_key_exists(get_nfilter_request_var('sort'), $filters['sort'])) {
		$_SESSION['sess_topx_sort'] = get_nfilter_request_var('sort');
	}

	if (!isset($_SESSION['sess_topx_sort'])) {
		$_SESSION['sess_topx_sort'] = ($default_sort === 'asc') ? 'asc' : 'desc';
	}

	if (isset_request_var('what') && array_key_exists(get_nfilter_request_var('what'), $filters['what'])) {
		$_SESSION['sess_topx_what'] = get_nfilter_request_var('what');
	}

	if (!isset($_SESSION['sess_topx_what'])) {
		$_SESSION['sess_topx_what'] = 'average';
	}

	return [
		'records' => topx_record_limit($_SESSION['sess_topx_records']),
		'sort'    => (string) $_SESSION['sess_topx_sort'],
		'what'    => (string) $_SESSION['sess_topx_what'],
	];
}

/**
 * The Drilldown card metadata keyed by card, in default order.
 *
 * @return array
 */
function topx_drilldown_card_meta(): array {
	return [
		'rankings'     => ['title' => __('Rankings', 'topx'),          'span' => 8, 'expandable' => true],
		'chart'        => ['title' => __('Ranking Chart', 'topx'),     'span' => 4, 'expandable' => false],
		'tiles'        => ['title' => __('Summary', 'topx'),           'span' => 4, 'expandable' => false],
		'gauges'       => ['title' => __('Average and Peak', 'topx'),  'span' => 4, 'expandable' => false],
		'distribution' => ['title' => __('Values by % of Peak', 'topx'), 'span' => 4, 'expandable' => false],
	];
}

/**
 * The Drilldown card keys in default order.
 *
 * @return string[]
 */
function topx_drilldown_available_cards(): array {
	return array_keys(topx_drilldown_card_meta());
}

/**
 * Compute the ranking payload shared by the drilldown cards for the resolved
 * data source over the selected timespan.
 *
 * @param array $ds     The resolved data source (key, supported, entry).
 * @param array $filter The drilldown filter (records, sort, what).
 *
 * @return array{source: array, rows: array, avg: float, peak: float, unit: string}
 */
function topx_drilldown_payload(array $ds, array $filter): array {
	$table          = topx_dashboard_bucket();
	$order          = topx_order_column($filter['what']);
	$dir            = topx_sort_direction($filter['sort']);
	$computed_order = ($filter['what'] === 'peak') ? 'xpeak' : 'xvalue';

	$ranking = topx_compute_ranking($ds['key'], $ds['supported'], $table, $order, $dir, $filter['records'], $computed_order);

	$peak = 0.0;

	foreach ($ranking['rows'] as $row) {
		$peak = max($peak, (float) ($row['xpeak'] ?? 0));
	}

	$unit = $ds['supported'] && isset($ranking['source']['final_unit']) ? (string) $ranking['source']['final_unit'] : '';

	return [
		'source' => $ranking['source'],
		'rows'   => $ranking['rows'],
		'avg'    => (float) $ranking['avg'],
		'peak'   => $peak,
		'unit'   => $unit,
	];
}

/**
 * Format a single drilldown value for display using the source's final
 * operation (supported) or the human-readable scaler (unsupported).
 *
 * @param float $value     The value to format.
 * @param array $source    The source definition (empty for unsupported).
 * @param bool  $supported Whether the source is supported.
 *
 * @return string The formatted value.
 */
function topx_drilldown_format(float $value, array $source, bool $supported): string {
	if ($supported && isset($source['final_operation'])) {
		return topx_final_operation($value, (string) $source['final_operation'], (string) $source['final_unit'], (string) $source['final_number'], (string) ($source['final_decimals'] ?? ''));
	}

	return topx_human_readable($value);
}

/**
 * Render the body of a Drilldown card.
 *
 * @param string $key The card key.
 * @param array  $ds  The resolved data source.
 *
 * @return string The card body HTML.
 */
function topx_drilldown_card_body(string $key, array $ds): string {
	global $config;

	$filter  = topx_dashboard_drill_filter((string) ($ds['entry']['sorting'] ?? 'desc'));
	$payload = topx_drilldown_payload($ds, $filter);
	$source  = $payload['source'];
	$rows    = $payload['rows'];

	ob_start();

	switch ($key) {
		case 'rankings':
			if (cacti_sizeof($rows) === 0) {
				print '<p class="topxDashEmpty">' . __esc('No data for this source over the selected timespan.', 'topx') . '</p>';

				break;
			}

			print '<table class="topxDashTable">';
			print '<thead><tr>'
				. '<th class="topxDashSortable">' . __esc('Data Source', 'topx') . '</th>'
				. '<th class="topxDashSortable topxDashNum" data-sort="num">' . __esc('Average', 'topx') . '</th>'
				. '<th class="topxDashSortable topxDashNum" data-sort="num">' . __esc('Peak', 'topx') . '</th>'
				. '</tr></thead><tbody>';

			foreach ($rows as $row) {
				$xvalue = is_null($row['xvalue']) ? 0 : (float) $row['xvalue'];
				$xpeak  = is_null($row['xpeak']) ? 0 : (float) $row['xpeak'];

				$graph_id = db_fetch_cell_prepared('SELECT DISTINCT(local_graph_id)
					FROM graph_templates_item
					LEFT JOIN data_template_rrd ON graph_templates_item.task_item_id = data_template_rrd.id
					LEFT JOIN data_local ON data_template_rrd.local_data_id = data_local.id
					LEFT JOIN data_template_data ON data_local.id = data_template_data.local_data_id
					WHERE data_template_data.local_data_id = ?',
					array($row['ldid']));

				print '<tr>'
					. '<td><a href="' . html_escape($config['url_path']) . 'graphs.php?action=graph_edit&id=' . (int) $graph_id . '">' . html_escape($row['name']) . '</a></td>'
					. '<td class="topxDashNum" data-sort-value="' . html_escape($xvalue) . '">' . html_escape(topx_drilldown_format($xvalue, $source, $ds['supported'])) . '</td>'
					. '<td class="topxDashNum" data-sort-value="' . html_escape($xpeak) . '">' . html_escape(topx_drilldown_format($xpeak, $source, $ds['supported'])) . '</td>'
					. '</tr>';
			}

			print '</tbody></table>';

			break;
		case 'chart':
			$labels = [];
			$full   = [];
			$values = [];
			$peaks  = [];
			$colors = [];
			$peak_colors = [];
			$legend = [];

			$pattern = ($ds['supported'] && isset($source['chart_label'])) ? (string) $source['chart_label'] : '';

			// Resolve all chart-label tokens in bulk (two queries) rather than
			// one query per bar, so an All-records ranking with many rows does
			// not fan out into thousands of per-row lookups.
			$token_map = [];

			if ($pattern !== '') {
				$token_map = topx_chart_label_tokens_bulk(array_map(static function ($row) {
					return isset($row['ldid']) ? (int) $row['ldid'] : 0;
				}, $rows));
			}

			foreach ($rows as $row) {
				$value = is_null($row['xvalue']) ? 0 : (float) $row['xvalue'];
				$peak  = is_null($row['xpeak']) ? 0 : (float) $row['xpeak'];
				$label = '';

				if ($pattern !== '' && isset($row['ldid'])) {
					$label = topx_apply_chart_label($pattern, $token_map[(int) $row['ldid']] ?? []);
				}

				if ($label === '') {
					$label = topx_short_label((string) $row['name']);
				}

				$labels[] = $label;
				$full[]   = (string) $row['name'];
				$values[] = $value;
				$peaks[]  = $peak;

				if ($ds['supported']) {
					$avg_class  = topx_range_class($value, $source);
					$peak_class = topx_range_class($peak, $source);

					if ($avg_class !== '' && $peak_class !== '') {
						$colors[]      = topx_range_color($avg_class);
						$peak_colors[] = topx_range_peak_color($peak_class);
					}
				}
			}

			// Only colour the bars when every bar resolved a band, so the
			// per-bar colour arrays stay aligned with the values.
			if (cacti_sizeof($colors) !== cacti_sizeof($values)) {
				$colors      = [];
				$peak_colors = [];
			}

			// With thresholds in play, show a six-swatch legend keyed to the two
			// series: the solid average bar (full band colour) and the muted
			// peak range band behind it (a darker tint of the same band).
			if (cacti_sizeof($colors) > 0) {
				$legend = [
					['label' => __('Peak Normal', 'topx'),  'color' => topx_range_peak_color('normal')],
					['label' => __('Peak Warning', 'topx'), 'color' => topx_range_peak_color('warning')],
					['label' => __('Peak Alert', 'topx'),   'color' => topx_range_peak_color('alert')],
					['label' => __('Avg Normal', 'topx'),    'color' => topx_range_color('normal')],
					['label' => __('Avg Warning', 'topx'),   'color' => topx_range_color('warning')],
					['label' => __('Avg Alert', 'topx'),     'color' => topx_range_color('alert')],
				];
			}

			// The value axis is labelled with the data source's own unit
			// (e.g. Bits, Utilization). Unsupported sources have no unit, so the
			// axis is left unlabelled rather than repeating a generic word.
			$axis = ($ds['supported'] && isset($source['unit']) && $source['unit'] !== '') ? (string) $source['unit'] : '';

			print topx_dashboard_chart($labels, $values, $axis, true, $payload['avg'], $colors, $full, $peaks, $peak_colors, $legend);

			break;
		case 'tiles':
			print '<div class="topxFleetTiles">';
			print topx_dashboard_tile(number_format_i18n(cacti_sizeof($rows), 0), __('Data Sources', 'topx'));
			print topx_dashboard_tile(topx_drilldown_format($payload['avg'], $source, $ds['supported']), __('Average', 'topx'), 'up');
			print topx_dashboard_tile(topx_drilldown_format($payload['peak'], $source, $ds['supported']), __('Peak', 'topx'), 'down');
			print '</div>';

			break;
		case 'gauges':
			$scale = max($payload['peak'], $payload['avg'], 1.0) * 1.05;

			print '<div class="topxGaugeGrid">';
			print '<div class="topxGaugeCell"><span class="topxGaugeCellLabel">' . __esc('Average', 'topx') . '</span>'
				. topx_dashboard_gauge($payload['avg'], null, $payload['peak'], $scale, topx_drilldown_format($payload['avg'], $source, $ds['supported']))
				. '</div>';
			print '<div class="topxGaugeCell"><span class="topxGaugeCellLabel">' . __esc('Peak', 'topx') . '</span>'
				. topx_dashboard_gauge($payload['peak'], null, $payload['peak'], $scale, topx_drilldown_format($payload['peak'], $source, $ds['supported']))
				. '</div>';
			print '</div>';

			break;
		case 'distribution':
			$buckets = [0, 0, 0, 0];
			$scale   = max($payload['peak'], 1.0);

			foreach ($rows as $row) {
				$v   = is_null($row['xvalue']) ? 0 : (float) $row['xvalue'];
				$pct = $scale > 0 ? ($v / $scale) : 0;

				if ($pct < 0.25) {
					$buckets[0]++;
				} elseif ($pct < 0.5) {
					$buckets[1]++;
				} elseif ($pct < 0.75) {
					$buckets[2]++;
				} else {
					$buckets[3]++;
				}
			}

			$segments = [
				['count' => $buckets[0], 'class' => 'up',  'label' => __('0–25%', 'topx')],
				['count' => $buckets[1], 'class' => 'rec', 'label' => __('25–50%', 'topx')],
				['count' => $buckets[2], 'class' => 'dis', 'label' => __('50–75%', 'topx')],
				['count' => $buckets[3], 'class' => 'down', 'label' => __('75–100%', 'topx')],
			];

			$bar_total = array_sum(array_column($segments, 'count'));

			if ($bar_total === 0) {
				print '<p class="topxDashEmpty">' . __esc('No data for this source over the selected timespan.', 'topx') . '</p>';

				break;
			}

			print '<div class="topxStackBar">';

			foreach ($segments as $segment) {
				if ($segment['count'] > 0) {
					$width = $segment['count'] / $bar_total * 100;
					print '<div class="topxStackSeg topxStackSeg--' . $segment['class'] . '" style="width:' . number_format($width, 2, '.', '') . '%" title="' . html_escape($segment['label'] . ': ' . $segment['count']) . '"></div>';
				}
			}

			print '</div><div class="topxStackLegend">';

			foreach ($segments as $segment) {
				print '<span><span class="topxStackChip topxStackSeg--' . $segment['class'] . '"></span>' . html_escape($segment['label'] . ' (' . $segment['count'] . ')') . '</span>';
			}

			print '</div>';
			print '<p class="topxDashNote">' . __esc('How the selected data sources spread across value bands, measured against the highest value (100% = the peak).', 'topx') . '</p>';

			break;
	}

	return (string) ob_get_clean();
}

/**
 * Render a complete Drilldown card.
 *
 * @param string $key      The card key.
 * @param array  $ds       The resolved data source.
 * @param bool   $expanded Whether the card opens expanded.
 *
 * @return string The card HTML, or '' for an unknown key.
 */
function topx_drilldown_render_card(string $key, array $ds, bool $expanded = false): string {
	$meta = topx_drilldown_card_meta();

	if (!isset($meta[$key])) {
		return '';
	}

	$def = $meta[$key];

	return topx_dashboard_card_chrome($key, (string) $def['title'], (int) $def['span'], (bool) $def['expandable'], $expanded, topx_drilldown_card_body($key, $ds));
}

/**
 * AJAX: render a single Drilldown card for the resolved data source.
 *
 * @return string A JSON document with the card key and rendered HTML.
 */
function topx_drilldown_card_ajax(): string {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !function_exists('csrf_check') || !csrf_check(false)) {
		return (string) json_encode(['error' => __('Invalid request. Please try again.', 'topx')]);
	}

	$ds = topx_dashboard_resolve_ds();

	if ($ds === null) {
		return (string) json_encode(['error' => __('No data source selected.', 'topx')]);
	}

	$key = isset_request_var('card') ? (string) get_nfilter_request_var('card') : '';

	if (!in_array($key, topx_drilldown_available_cards(), true)) {
		return (string) json_encode(['error' => __('Unknown card.', 'topx')]);
	}

	$expanded = isset_request_var('expanded') && get_nfilter_request_var('expanded') === '1';

	return (string) json_encode(['card' => $key, 'html' => topx_drilldown_render_card($key, $ds, $expanded)]);
}

/**
 * Render a drilldown filter <select> that reloads the dashboard in place.
 *
 * @param string     $id       The select element id/field name.
 * @param array      $options  Option token => label pairs.
 * @param int|string $selected The current value.
 *
 * @return void
 */
function topx_dashboard_filter_select(string $id, array $options, $selected): void {
	print '<select id="' . html_escape($id) . '" class="topxDashFilter" data-filter="' . html_escape(str_replace('topx_dashboard_', '', $id)) . '">';

	foreach ($options as $key => $label) {
		print '<option value="' . html_escape($key) . '"' . ($selected == $key ? ' selected' : '') . '>' . html_escape($label) . '</option>';
	}

	print '</select>';
}

/**
 * Render the Drilldown dashboard: a draggable grid of cards for one data source,
 * with data source/records/order/what filters, a timespan selector and
 * auto-refresh.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array.
 */
function topx_drilldown_dashboard(): void {
	global $config;

	topx_dashboard_print_css();
	topx_dashboard_tabs('drill');

	if (!topx_dashboard_dsstats_ready()) {
		return;
	}

	topx_dashboard_init_timespan();

	$ds = topx_dashboard_resolve_ds();

	if ($ds === null) {
		html_start_box(__('Data Source', 'topx'), '100%', false, 3, 'center', '');
		print '<tr class="even"><td class="textArea"><p>' . __esc('No TopX data sources are available yet. Enable DS Stats and poll some data first.', 'topx') . '</p></td></tr>';
		html_end_box();

		return;
	}

	// View glue: the source's Default Sort seeds the session sort on first open.
	// topx_drilldown_dashboard() cannot be unit-tested (it includes Cacti's
	// timespan library); topx_dashboard_drill_filter() is covered directly.
	// @codeCoverageIgnoreStart
	$filter  = topx_dashboard_drill_filter((string) ($ds['entry']['sorting'] ?? 'desc'));
	// @codeCoverageIgnoreEnd
	$filters = topx_filter_arrays();
	$list    = topx_get_ds_list();

	$current_refresh = (int) read_user_setting('topx_dashboard_refresh_drill', 0);
	$refresh_page    = $config['url_path'] . 'plugins/topx/topx.php?action=view&ds=' . $ds['key'] . '&header=false';

	print '<script type="text/javascript" ' . plugin_topx_csp_nonce() . ' src="' . $config['url_path'] . 'plugins/topx/js/topx_dashboard.js?v=' . filemtime($config['base_path'] . '/plugins/topx/js/topx_dashboard.js') . '"></script>';

	topx_dashboard_print_refresh($current_refresh, $refresh_page);

	$meta   = topx_drilldown_card_meta();
	$layout = topx_dashboard_layout('drill', topx_drilldown_available_cards());
	$absent = array_values(array_diff(topx_drilldown_available_cards(), $layout['order']));

	print '<div class="topxDashToolbar">';

	print '<label class="topxDashToolbarLabel" for="topx_dashboard_ds">' . __esc('Data Source', 'topx') . '</label>';
	print '<select id="topx_dashboard_ds" class="topxDashUps">';

	foreach ($list as $entry) {
		print '<option value="' . html_escape($entry['key']) . '"' . ((int) $entry['key'] === $ds['key'] ? ' selected' : '') . '>' . html_escape($entry['name'] . ' (' . $entry['count'] . ')') . '</option>';
	}

	print '</select>';

	print '<label class="topxDashToolbarLabel" for="topx_dashboard_records">' . __esc('Records', 'topx') . '</label>';
	topx_dashboard_filter_select('topx_dashboard_records', $filters['records'], $filter['records']);

	print '<label class="topxDashToolbarLabel" for="topx_dashboard_sort">' . __esc('Order', 'topx') . '</label>';
	topx_dashboard_filter_select('topx_dashboard_sort', $filters['sort'], $filter['sort']);

	print '<label class="topxDashToolbarLabel" for="topx_dashboard_what">' . __esc('What', 'topx') . '</label>';
	topx_dashboard_filter_select('topx_dashboard_what', $filters['what'], $filter['what']);

	topx_dashboard_toolbar_timespan();
	topx_dashboard_toolbar_add($absent, $meta);
	// View glue: topx_drilldown_dashboard() cannot be unit-tested because it
	// includes Cacti's timespan library. The button logic is covered directly by
	// the topx_dashboard_toolbar_edit() unit tests.
	// @codeCoverageIgnoreStart
	topx_dashboard_toolbar_edit($ds);
	// @codeCoverageIgnoreEnd
	topx_dashboard_toolbar_refresh($current_refresh);
	print '</div>';

	print '<div id="topx_dashboard" class="topxDashGrid"'
		. ' data-scope-param="ds" data-scope="' . $ds['key'] . '"'
		. ' data-refresh-page="' . html_escape($refresh_page) . '"'
		. ' data-reload="' . html_escape('topx.php?action=view&ds=' . $ds['key']) . '">';

	foreach ($layout['order'] as $key) {
		print topx_drilldown_render_card($key, $ds, !empty($layout['expanded'][$key]));
	}

	print '</div>';
	print '<div id="topx_dashboard_dialog" class="topxDashDialog" style="display:none"></div>';

	$catalog = [];

	foreach (topx_drilldown_available_cards() as $key) {
		$catalog[$key] = $meta[$key]['title'];
	}

	print '<script type="text/javascript" ' . plugin_topx_csp_nonce() . '>var topxDashCatalog = ' . json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '; initTopxDashboard();</script>';
}
