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

/**
 * Returns the translated filter option arrays used by the TopX view. The keys
 * form the allowlist of accepted request values; the values are the
 * human readable labels shown in the dropdowns. Built at call time so the
 * labels pick up the active locale.
 *
 * @return array An array with 'age', 'records', 'sort' and 'what' keys, each
 *               mapping option tokens to translated labels.
 */
function topx_filter_arrays(): array {
	return [
		'age' => [
			'hour'  => __('Last Hour', 'topx'),
			'day'   => __('Last Day', 'topx'),
			'week'  => __('Last Week', 'topx'),
			'month' => __('Last Month', 'topx'),
			'year'  => __('Last Year', 'topx'),
		],
		'records' => [
			'5'  => __('Top 5', 'topx'),
			'10' => __('Top 10', 'topx'),
			'20' => __('Top 20', 'topx'),
			'50' => __('Top 50', 'topx'),
			'0'  => __('All', 'topx'),
		],
		'sort' => [
			'asc'  => __('Normal', 'topx'),
			'desc' => __('Reverse', 'topx'),
		],
		'what' => [
			'average' => __('Average', 'topx'),
			'peak'    => __('Peak', 'topx'),
		],
	];
}

/**
 * Config_arrays plugin hook. Publishes the TopX filter option arrays as
 * globals so they are available to any Cacti code that consumes plugin
 * configuration arrays, and registers the plugin's Console > Management menu
 * entry. Called by Cacti's plugin architecture via the 'config_arrays' hook.
 *
 * @return void
 *
 * @global array $topx_filter_arrays Populated with the TopX filter arrays.
 * @global array $menu              Cacti's admin menu registry; appended with
 *                                  the TopX Data Sources management entry.
 */
function topx_config_arrays(): void {
	global $topx_filter_arrays, $menu;

	// Keep the plugin's schema/realm registration current on every page load
	// (not just at install/enable time), so upgrades apply promptly. Guarded so
	// the pure arrays can still be unit tested without loading setup.php.
	if (function_exists('plugin_topx_upgrade')) {
		plugin_topx_upgrade();
	}

	$topx_filter_arrays = topx_filter_arrays();

	if (is_realm_allowed(1) || api_user_realm_auth('topx_sources.php')) {
		// 'Management' is a Cacti-core menu label, so it is intentionally left
		// in the default domain to merge into the existing Management section.
		$menu[__('Management')]['plugins/topx/topx_sources.php'] = __('TopX Data Sources', 'topx');
	}
}
