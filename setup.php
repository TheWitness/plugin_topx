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

include_once(__DIR__ . '/includes/functions.php');
include_once(__DIR__ . '/includes/arrays.php');

/**
 * Plugin install hook. Registers this plugin's hooks and realm with Cacti and
 * provisions its database schema. Called by Cacti's plugin architecture when
 * the plugin is installed.
 *
 * @return void
 */
function plugin_topx_install(): void {
	api_plugin_register_hook('topx', 'config_arrays',          'topx_config_arrays',           'setup.php');
	api_plugin_register_hook('topx', 'top_header_tabs',        'topx_show_tab',                'setup.php');
	api_plugin_register_hook('topx', 'top_graph_header_tabs',  'topx_show_tab',                'setup.php');
	api_plugin_register_hook('topx', 'draw_navigation_text',   'topx_draw_navigation_text',    'setup.php');

	api_plugin_register_realm('topx', 'topx.php', 'Plugin TopX - View', 1);
	api_plugin_register_realm('topx', 'topx_sources.php', 'Manage TopX Data Sources', 1);

	topx_setup_database();
}

/**
 * Creates (idempotently) the plugin's database tables and seeds the default
 * set of data source definitions. Safe to call from both the install and
 * upgrade paths. Called by plugin_topx_install() and plugin_topx_upgrade().
 *
 * @return void
 */
function topx_setup_database(): void {
	$data = [
		'columns' => [
			['name' => 'sorting',         'type' => "enum('asc','desc')", 'default' => 'asc',   'NULL' => false],
			['name' => 'dt_name',         'type' => 'varchar(200)',       'default' => '',      'NULL' => false],
			['name' => 'hash',            'type' => 'varchar(32)',        'default' => '',      'NULL' => false],
			['name' => 'operation',       'type' => 'varchar(100)',       'default' => '',      'NULL' => false],
			['name' => 'unit',            'type' => 'varchar(100)',       'default' => '',      'NULL' => false],
			['name' => 'final_operation', 'type' => 'varchar(100)',       'default' => '',      'NULL' => false],
			['name' => 'final_unit',      'type' => 'varchar(100)',       'default' => '',      'NULL' => false],
			['name' => 'final_number',    'type' => 'varchar(100)',       'default' => '',      'NULL' => false],
			['name' => 'system',          'type' => 'varchar(20)',        'default' => 'bits',  'NULL' => false],
			['name' => 'range_type',      'type' => 'tinyint(1)',         'default' => '0',     'NULL' => false],
			['name' => 'range_normal',    'type' => 'double',             'default' => null,    'NULL' => true],
			['name' => 'range_warning',   'type' => 'double',             'default' => null,    'NULL' => true],
			['name' => 'range_alert',     'type' => 'double',             'default' => null,    'NULL' => true],
			['name' => 'chart_label',     'type' => 'varchar(128)',       'default' => '',      'NULL' => false],
		],
		'type'    => 'InnoDB',
		'comment' => 'sources',
	];

	api_plugin_db_table_create('topx', 'plugin_topx_source', $data);

	// Add the range/label columns on installs that predate them. Each call is a
	// safe no-op when the column already exists, so this runs unconditionally
	// from both the install and upgrade paths.
	api_plugin_db_add_column('topx', 'plugin_topx_source',
		['name' => 'range_type',    'type' => 'tinyint(1)',   'default' => '0',  'NULL' => false, 'after' => 'system']);
	api_plugin_db_add_column('topx', 'plugin_topx_source',
		['name' => 'range_normal',  'type' => 'double',       'default' => null, 'NULL' => true,  'after' => 'range_type']);
	api_plugin_db_add_column('topx', 'plugin_topx_source',
		['name' => 'range_warning', 'type' => 'double',       'default' => null, 'NULL' => true,  'after' => 'range_normal']);
	api_plugin_db_add_column('topx', 'plugin_topx_source',
		['name' => 'range_alert',   'type' => 'double',       'default' => null, 'NULL' => true,  'after' => 'range_warning']);
	api_plugin_db_add_column('topx', 'plugin_topx_source',
		['name' => 'chart_label',   'type' => 'varchar(128)', 'default' => '',   'NULL' => false, 'after' => 'range_alert']);

	$data = [
		'columns' => [
			['name' => 'age',              'type' => "enum('quarter','hour','day','week','month')", 'default' => 'quarter', 'NULL' => false],
			['name' => 'value1',           'type' => 'decimal(20,6)', 'default' => '0',  'NULL' => false],
			['name' => 'value2',           'type' => 'decimal(20,6)', 'default' => '0',  'NULL' => false],
			['name' => 'value1_dsname',    'type' => 'varchar(50)',   'default' => '',   'NULL' => false],
			['name' => 'value2_dsname',    'type' => 'varchar(50)',   'default' => '',   'NULL' => false],
			['name' => 'result_value',     'type' => 'decimal(20,6)', 'default' => '0',  'NULL' => false],
			['name' => 'local_data_id',    'type' => 'int(11)',       'default' => null, 'NULL' => true],
			['name' => 'data_template_id', 'type' => 'int(11)',       'default' => null, 'NULL' => true],
			['name' => 'number_of_cycles', 'type' => 'int(11)',       'default' => '0',  'NULL' => false],
		],
		'keys' => [
			['name' => 'local_data_id',    'columns' => 'local_data_id'],
			['name' => 'data_template_id', 'columns' => 'data_template_id'],
			['name' => 'value1_dsname',    'columns' => 'value1_dsname'],
			['name' => 'value2_dsname',    'columns' => 'value2_dsname'],
		],
		'type'    => 'InnoDB',
		'comment' => 'average',
	];

	api_plugin_db_table_create('topx', 'plugin_topx_average', $data);

	topx_seed_sources();
}

/**
 * Seeds the default data source definitions, but only when the source table is
 * empty, so re-running install/upgrade never duplicates rows or clobbers the
 * administrator's own definitions.
 *
 * @return void
 */
function topx_seed_sources(): void {
	if (db_fetch_cell('SELECT COUNT(*) FROM plugin_topx_source') > 0) {
		return;
	}

	$defaults = [
		['desc', 'ucd/net - Load Average - 1 Minute', '9b82d44eb563027659683765f92c9757', 'load_1min=load_1min', 'Load', 'strip', 'load', '2', 'decimal', 0, 1, 2, 4, '|host_description|'],
		['desc', 'Host MIB - CPU Utilization', 'f6e7d21c19434666bbdac00ccef9932f', 'cpu=cpu', 'Utilization', 'strip', '%', '1', 'decimal', 0, 50, 75, 90, '|host_description|'],
		['desc', 'Host MIB - Hard Drive Space', 'd814fa3b79bd0f8933b6e0834d3f16d0', 'hdd_total%hdd_used', 'Used', 'strip', '%', '1', 'decimal', 0, 70, 85, 95, '|host_description|'],
		['desc', 'Interface - Errors_in + Discards_in', '36335cd98633963a575b70639cd2fdad', 'discards_in+errors_in', 'Errors+Discard', '/', 'errors/Kerrors/Merrors', '1/1000/1000000', 'decimal', 0, null, null, null, '|host_description| - |query_ifName|'],
		['desc', 'Interface - Errors_out + Discards_out', '36335cd98633963a575b70639cd2fdad', 'discards_out+errors_out', 'Errors+Discard', '/', 'errors/Kerrors/Merrors', '1/1000/1000000', 'decimal', 0, null, null, null, '|host_description| - |query_ifName|'],
		['desc', 'Mikrotik - System - Uptime', '0d8804fbc44a6ab9db89f8d83d050627', 'uptime/100', 'Uptime in seconds', '/', 'sec/min/hour/days', '1/60/3600/86400', 'decimal', 1, null, null, null, '|host_description|'],
		['desc', 'APC load', 'be63bc4946561a274ace2c982548f255', 'load=load', 'Load', 'strip', '%', '0', 'decimal', 0, 50, 75, 90, '|host_description|'],
		['desc', 'MultiCPU AVG', '9561518147a2423734394410bcd241b4', 'load=load', 'Utilization', 'strip', '%', '0', 'decimal', 0, 50, 75, 90, '|host_description|'],
		['desc', 'Interface - Traffic_in+Traffic_out', '6632e1e0b58a565c135d7ff90440c335', 'traffic_in+traffic_out', 'Bits', '/', 'bit/Kbit/Mbit/Gbit/Tbit', '1/1024/1048576/1073741824/1099511627776', 'bits', 0, null, null, null, '|host_description| - |query_ifName|'],
	];

	foreach ($defaults as $row) {
		db_execute_prepared('INSERT INTO plugin_topx_source
			(sorting, dt_name, hash, operation, unit, final_operation, final_unit, final_number, system,
			range_type, range_normal, range_warning, range_alert, chart_label)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			$row);
	}
}

/**
 * Plugin uninstall hook. Clears the recorded version and drops the plugin's
 * tables. Called by Cacti's plugin architecture when the plugin is removed.
 *
 * @return void
 */
function plugin_topx_uninstall(): void {
	set_config_option('topx_version', '');

	db_execute('DROP TABLE IF EXISTS plugin_topx_source');
	db_execute('DROP TABLE IF EXISTS plugin_topx_average');
}

/**
 * Reads and returns this plugin's metadata from its INFO file.
 *
 * @return array The plugin's info array parsed from the INFO '[info]' section.
 *
 * @global array $config Cacti global configuration array; used to locate the
 *                       plugin's INFO file.
 */
function plugin_topx_version(): array {
	global $config;

	$info = parse_ini_file($config['base_path'] . '/plugins/topx/INFO', true) ?: [];

	return $info['info'] ?? [];
}

/**
 * Plugin config-check hook. Ensures the plugin schema and registration are up
 * to date by delegating to plugin_topx_upgrade(). Called by Cacti's plugin
 * architecture on relevant page loads.
 *
 * @return bool Always true.
 */
function plugin_topx_check_config(): bool {
	plugin_topx_upgrade();

	return true;
}

/**
 * Plugin upgrade hook. When the recorded database version differs from the
 * INFO version, refreshes the plugin_config and plugin_realms records, prunes
 * files tombstoned in manifest.json, and re-runs the idempotent schema setup.
 * Only acts on index.php/plugins.php or topx.php page loads.
 *
 * @return bool|null Null when this is not a relevant page; otherwise false
 *                   after completing the upgrade checks.
 */
function plugin_topx_upgrade() {
	$files = ['index.php', 'plugins.php'];

	if (!in_array(get_current_page(), $files, true) && strpos(get_current_page(), 'topx') === false) {
		return null;
	}

	$info    = plugin_topx_version();
	$current = $info['version'] ?? '';
	$old     = db_fetch_cell("SELECT version FROM plugin_config WHERE directory = 'topx'");

	if ($current != $old) {
		db_execute_prepared('UPDATE plugin_realms
			SET display = ? WHERE file = ?',
			['Plugin TopX - View', 'topx.php']);

		$id = db_fetch_cell("SELECT id FROM plugin_config WHERE directory = 'topx'");

		db_execute_prepared('UPDATE plugin_config
			SET name = ?, author = ?, webpage = ?, version = ?
			WHERE id = ?',
			[
				$info['longname'] ?? 'TopX',
				$info['author']   ?? '',
				$info['homepage'] ?? '',
				$current,
				$id,
			]);

		topx_setup_database();

		topx_prune_files();
	}

	// Register the navigation hook for installations that predate it.
	if (!db_fetch_cell("SELECT id FROM plugin_hooks WHERE name = 'topx' AND hook = 'draw_navigation_text' AND `function` = 'topx_draw_navigation_text'")) {
		api_plugin_register_hook('topx', 'draw_navigation_text', 'topx_draw_navigation_text', 'setup.php', true);
	}

	// Make sure the management realm exists and that everyone who can already
	// view TopX can also manage its Data Sources. Without this, administrators
	// upgrading from a version before the management UI existed cannot reach the
	// new page until someone grants the realm by hand.
	topx_upgrade_management_realm();

	return false;
}

/**
 * Ensures the management realm exists and grants it to every user and group
 * that already holds the TopX view realm. api_plugin_register_realm() only
 * grants the admin when it first inserts a realm, so on an in-place upgrade the
 * newly added management realm would otherwise be registered but ungranted,
 * giving a "401 Permission Denied" to admins who upgraded. This reconciles the
 * grant and is idempotent (INSERT IGNORE), so it is safe to run on every
 * relevant page load. Called from plugin_topx_upgrade().
 *
 * The 'upgrade' in the name is required: Cacti's api_plugin_valid_entrypoint()
 * only permits api_plugin_register_realm() when the directly-calling function's
 * name matches /(install|upgrade|setup)/i, so renaming this function would make
 * the realm registration silently fail.
 *
 * @return void
 */
function topx_upgrade_management_realm(): void {
	$mgmt_realm = db_fetch_cell("SELECT id FROM plugin_realms WHERE plugin = 'topx' AND file = 'topx_sources.php'");

	if (empty($mgmt_realm)) {
		api_plugin_register_realm('topx', 'topx_sources.php', 'Manage TopX Data Sources', 1);

		$mgmt_realm = db_fetch_cell("SELECT id FROM plugin_realms WHERE plugin = 'topx' AND file = 'topx_sources.php'");
	}

	$view_realm = db_fetch_cell("SELECT id FROM plugin_realms WHERE plugin = 'topx' AND file = 'topx.php'");

	if (empty($mgmt_realm) || empty($view_realm)) {
		return;
	}

	// Realm ids are stored offset by 100 in the user/group grant tables.
	db_execute_prepared('INSERT IGNORE INTO user_auth_realm (user_id, realm_id)
		SELECT user_id, ? FROM user_auth_realm WHERE realm_id = ?',
		[$mgmt_realm + 100, $view_realm + 100]);

	db_execute_prepared('INSERT IGNORE INTO user_auth_group_realm (group_id, realm_id)
		SELECT group_id, ? FROM user_auth_group_realm WHERE realm_id = ?',
		[$mgmt_realm + 100, $view_realm + 100]);
}

/**
 * Renders the TopX console tab. Only shown to users with the plugin's realm
 * permission. Called by Cacti via the 'top_header_tabs' and
 * 'top_graph_header_tabs' hooks.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used for URL paths.
 */
function topx_show_tab(): void {
	global $config;

	if (!is_realm_allowed(1) && !api_user_realm_auth('topx.php')) {
		return;
	}

	$current = (basename($_SERVER['PHP_SELF']) === 'topx.php');

	print '<a href="' . html_escape($config['url_path']) . 'plugins/topx/topx.php?action=dashboard">' .
		'<img src="' . html_escape($config['url_path']) . 'plugins/topx/images/tab_topx' . ($current ? '_down' : '') . '.gif" ' .
		'alt="' . __esc('TopX', 'topx') . '" style="vertical-align:middle;" border="0"></a>';
}

/**
 * Draw_navigation_text hook: registers the TopX dashboard pages so Cacti renders
 * a correct breadcrumb for them. Called by Cacti core via the
 * 'draw_navigation_text' filter.
 *
 * @param array $nav The accumulating navigation map.
 *
 * @return array The navigation map with the TopX pages added.
 */
function topx_draw_navigation_text($nav) {
	$nav['topx.php:dashboard'] = [
		'title'   => __('TopX Overview', 'topx'),
		'mapping' => '',
		'url'     => 'topx.php?action=dashboard',
		'level'   => 0,
	];

	$nav['topx.php:view'] = [
		'title'   => __('TopX Data Source', 'topx'),
		'mapping' => 'topx.php:dashboard',
		'url'     => 'topx.php?action=view',
		'level'   => 1,
	];

	$nav['topx_sources.php:'] = [
		'title'   => __('TopX Data Sources', 'topx'),
		'mapping' => 'index.php:',
		'url'     => 'topx_sources.php',
		'level'   => 1,
	];

	$nav['topx_sources.php:edit'] = [
		'title'   => __('(Edit)', 'topx'),
		'mapping' => 'index.php:,topx_sources.php:',
		'url'     => '',
		'level'   => 2,
	];

	$nav['topx_sources.php:import'] = [
		'title'   => __('Import', 'topx'),
		'mapping' => 'index.php:,topx_sources.php:',
		'url'     => '',
		'level'   => 2,
	];

	$nav['topx_sources.php:actions'] = [
		'title'   => __('Actions', 'topx'),
		'mapping' => 'index.php:,topx_sources.php:',
		'url'     => '',
		'level'   => 2,
	];

	return $nav;
}

/**
 * Removes paths tombstoned in manifest.json plus the dev-only tests/ tree and
 * phpunit.xml during an upgrade, leaving whitelisted (user-data), .git* and
 * .md* paths alone. Refuses any tombstone that resolves outside the plugin
 * directory (a tampered manifest.json) and logs anything it cannot remove.
 * Called from plugin_topx_upgrade().
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used to locate the
 *                       plugin directory.
 */
function topx_prune_files(): void {
	global $config;

	$plugin_dir    = $config['base_path'] . '/plugins/topx';
	$manifest_path = $plugin_dir . '/manifest.json';

	if (!is_readable($manifest_path)) {
		return;
	}

	$manifest = json_decode((string) file_get_contents($manifest_path), true);

	if (!is_array($manifest)) {
		cacti_log('WARNING: topx manifest.json could not be parsed; skipping file prune', false, 'TOPX');

		return;
	}

	$tombstones = isset($manifest['tombstones']) && is_array($manifest['tombstones']) ? $manifest['tombstones'] : [];
	$expected   = isset($manifest['expected'])   && is_array($manifest['expected'])   ? $manifest['expected']   : [];
	$whitelist  = isset($manifest['whitelist'])  && is_array($manifest['whitelist'])  ? $manifest['whitelist']  : [];

	$protected = function (string $rel) use ($whitelist): bool {
		if (strncmp($rel, '.git', 4) === 0 || strncmp($rel, '.md', 3) === 0) {
			return true;
		}

		foreach ($whitelist as $entry) {
			$entry = trim((string) $entry, '/');

			if ($entry !== '' && ($rel === $entry
				|| strncmp($rel, $entry . '/', strlen($entry) + 1) === 0
				|| strncmp($entry, $rel . '/', strlen($rel) + 1) === 0)) {
				return true;
			}
		}

		return false;
	};

	$plugin_real = realpath($plugin_dir);

	$remove   = $tombstones;
	$remove[] = 'tests/';
	$remove[] = 'phpunit.xml';

	foreach ($remove as $rel) {
		$rel = trim((string) $rel, '/');

		if ($rel === '' || $protected($rel)) {
			continue;
		}

		$segments = explode('/', $rel);

		if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
			cacti_log(sprintf('WARNING: topx prune refused to remove %s: path contains a traversal segment (tampered manifest.json?)', $rel), false, 'TOPX');

			continue;
		}

		$path = $plugin_dir . '/' . $rel;

		if (!is_link($path) && !file_exists($path)) {
			continue;
		}

		$anchor = is_link($path) ? dirname($path) : $path;
		$real   = realpath($anchor);

		if ($real === false || ($real !== $plugin_real && strncmp($real, $plugin_real . DIRECTORY_SEPARATOR, strlen((string) $plugin_real) + 1) !== 0)) {
			cacti_log(sprintf('WARNING: topx prune refused to remove %s: path resolves outside the plugin directory (tampered manifest.json?)', $rel), false, 'TOPX');

			continue;
		}

		if (is_dir($path) && !is_link($path)) {
			$removed = topx_rmtree($path);
		} else {
			$removed = @unlink($path);
		}

		if (!$removed) {
			cacti_log(sprintf('WARNING: topx upgrade could not remove %s (check file/directory permissions)', $rel), false, 'TOPX');
		}
	}

	$known = [];

	foreach (array_merge($expected, $tombstones) as $entry) {
		$top = explode('/', trim((string) $entry, '/'))[0];

		if ($top !== '') {
			$known[$top] = true;
		}
	}

	$entries = scandir($plugin_dir);

	foreach (($entries !== false ? $entries : []) as $entry) {
		if ($entry === '.' || $entry === '..' || $entry === 'tests' || $entry === 'phpunit.xml' || $protected($entry) || isset($known[$entry])) {
			continue;
		}

		cacti_log(sprintf('WARNING: topx upgrade found a file/directory not described in manifest.json: %s (left in place)', $entry), false, 'TOPX');
	}
}

/**
 * Recursively deletes a directory and its contents. Symlinks are removed
 * without being followed. Helper for topx_prune_files().
 *
 * @param string $dir Absolute path to the directory to remove.
 *
 * @return bool True if everything under the directory was removed, false if
 *              any entry could not be deleted.
 */
function topx_rmtree(string $dir): bool {
	$entries = scandir($dir);
	$ok      = true;

	foreach (($entries !== false ? $entries : []) as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}

		$path = $dir . '/' . $entry;

		if (is_dir($path) && !is_link($path)) {
			if (!topx_rmtree($path)) {
				$ok = false;
			}
		} elseif (!@unlink($path)) {
			$ok = false;
		}
	}

	if (!@rmdir($dir)) {
		$ok = false;
	}

	return $ok;
}
