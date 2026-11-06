<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/sources.php';
require_once dirname(__DIR__, 2) . '/includes/dashboard.php';

describe('topx_drilldown_card_body chart', function (): void {
	afterEach(function (): void {
		unset(
			$GLOBALS['__test_db_fetch_row_prepared'],
			$GLOBALS['__test_db_fetch_cell_prepared'],
			$GLOBALS['__test_db_fetch_assoc_prepared']
		);
	});

	it('names bars via the chart_label pattern and colours them by threshold', function (): void {
		$GLOBALS['__test_db_fetch_row_prepared'] = function ($sql, $p) {
			if (strpos($sql, 'plugin_topx_source') !== false) {
				return [
					'id'              => 7,
					'dt_name'         => 'CPU',
					'hash'            => 'f6e7d21c19434666bbdac00ccef9932f',
					'operation'       => 'cpu=cpu',
					'unit'            => 'Utilization',
					'final_operation' => 'strip',
					'final_unit'      => '%',
					'final_number'    => '1',
					'system'          => 'decimal',
					'sorting'         => 'desc',
					'range_type'      => 0,
					'range_normal'    => 25,
					'range_warning'   => 50,
					'range_alert'     => 90,
					'chart_label'     => '|host_description|',
				];
			}

			if (strpos($sql, 'data_local') !== false) {
				return [
					'host_description' => 'Router1',
					'host_hostname'    => '10.0.0.1',
					'host_id'          => 7,
					'snmp_query_id'    => 0,
					'snmp_index'       => '',
				];
			}

			return false;
		};

		$GLOBALS['__test_db_fetch_cell_prepared'] = function ($sql, $p) {
			return 20;
		};

		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) {
			if (strpos($sql, 'host_snmp_cache') !== false) {
				return [];
			}

			return [
				['ldid' => 1, 'name' => 'Device - CPU - cpu', 'xvalue' => 80, 'xpeak' => 90],
				['ldid' => 2, 'name' => 'Device2 - CPU - cpu', 'xvalue' => 30, 'xpeak' => 40],
			];
		};

		$ds = ['key' => -7, 'supported' => true, 'entry' => []];

		$html = topx_drilldown_card_body('chart', $ds);

		expect($html)->toBeString();
		expect($html)->toContain('topx-bb-chart');
		// The chart_label pattern resolved to the host description.
		expect($html)->toContain('Router1');
		// A threshold-based bar colour was emitted (warning band for 80).
		expect($html)->toContain(topx_range_color('warning'));
	});
});

describe('topx_drilldown_card_body rankings', function (): void {
	afterEach(function (): void {
		unset(
			$GLOBALS['__test_db_fetch_row_prepared'],
			$GLOBALS['__test_db_fetch_cell_prepared'],
			$GLOBALS['__test_db_fetch_assoc_prepared']
		);
	});

	it('renders a ranked table with per-row graph links', function (): void {
		$GLOBALS['__test_db_fetch_row_prepared'] = function ($sql, $p) {
			if (strpos($sql, 'plugin_topx_source') !== false) {
				return [
					'id'              => 7,
					'operation'       => 'cpu=cpu',
					'final_operation' => 'strip',
					'final_unit'      => '%',
					'final_number'    => '1',
				];
			}

			return [];
		};

		$GLOBALS['__test_db_fetch_cell_prepared'] = function ($sql, $p) {
			// hash -> template id, AVG(), and the per-row graph id lookup.
			if (strpos($sql, 'local_graph_id') !== false) {
				return 42;
			}

			return strpos($sql, 'FROM data_template WHERE hash') !== false ? 12 : 3;
		};

		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) {
			return [['ldid' => 1, 'name' => 'vmhost10 - CPU5', 'xvalue' => 29, 'xpeak' => 30]];
		};

		$ds = ['key' => -7, 'supported' => true, 'entry' => []];

		$html = topx_drilldown_card_body('rankings', $ds);

		expect($html)->toContain('topxDashTable');
		expect($html)->toContain('vmhost10 - CPU5');
		expect($html)->toContain('graphs.php?action=graph_edit&id=42');
	});

	it('shows an empty-state message when there are no rows', function (): void {
		$GLOBALS['__test_db_fetch_row_prepared'] = function ($sql, $p) {
			return ['operation' => 'cpu=cpu'];
		};

		$GLOBALS['__test_db_fetch_cell_prepared'] = function ($sql, $p) {
			return 0;
		};

		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) {
			return [];
		};

		$ds = ['key' => -7, 'supported' => true, 'entry' => []];

		$html = topx_drilldown_card_body('rankings', $ds);

		expect($html)->toContain('No data for this source');
	});
});

describe('topx_dashboard_toolbar_edit', function (): void {
	afterEach(function (): void {
		unset(
			$GLOBALS['__test_is_realm_allowed'],
			$GLOBALS['__test_api_user_realm_auth'],
			$GLOBALS['__test_db_fetch_cell_prepared']
		);
	});

	it('renders nothing for a user without the management realm', function (): void {
		$GLOBALS['__test_is_realm_allowed']    = function ($r) { return false; };
		$GLOBALS['__test_api_user_realm_auth'] = function ($f) { return false; };

		ob_start();
		topx_dashboard_toolbar_edit(['key' => -5, 'supported' => true]);
		$html = (string) ob_get_clean();

		expect($html)->toBe('');
	});

	it('links to the definition editor for a supported source', function (): void {
		$GLOBALS['__test_is_realm_allowed'] = function ($r) { return true; };

		ob_start();
		topx_dashboard_toolbar_edit(['key' => -5, 'supported' => true]);
		$html = (string) ob_get_clean();

		expect($html)->toContain('action=edit&amp;id=5');
		expect($html)->toContain('Edit Rule');
	});

	it('links to the template editor to add a rule for an unsupported source', function (): void {
		$GLOBALS['__test_is_realm_allowed']       = function ($r) { return true; };
		$GLOBALS['__test_db_fetch_cell_prepared'] = function ($sql, $p) { return 'abc123'; };

		ob_start();
		topx_dashboard_toolbar_edit(['key' => 9, 'supported' => false]);
		$html = (string) ob_get_clean();

		expect($html)->toContain('action=edit&amp;hash=abc123');
		expect($html)->toContain('Add Rule');
	});

	it('renders nothing for an unsupported source whose template is gone', function (): void {
		$GLOBALS['__test_is_realm_allowed']       = function ($r) { return true; };
		$GLOBALS['__test_db_fetch_cell_prepared'] = function ($sql, $p) { return ''; };

		ob_start();
		topx_dashboard_toolbar_edit(['key' => 9, 'supported' => false]);
		$html = (string) ob_get_clean();

		expect($html)->toBe('');
	});
});
