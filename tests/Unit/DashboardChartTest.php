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
