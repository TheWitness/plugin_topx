<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/sources.php';

afterEach(function (): void {
	unset(
		$GLOBALS['__test_db_fetch_row_prepared'],
		$GLOBALS['__test_db_fetch_assoc'],
		$GLOBALS['__test_db_fetch_assoc_prepared'],
		$GLOBALS['__test_db_fetch_cell_prepared'],
		$GLOBALS['__test_sql_save']
	);

	$GLOBALS['__test_db_calls'] = [];
});

describe('topx_range_type_labels', function (): void {
	it('returns the increasing and decreasing labels', function (): void {
		$labels = topx_range_type_labels();

		expect($labels)->toHaveKey(0);
		expect($labels)->toHaveKey(1);
	});
});

describe('topx_get_source', function (): void {
	it('returns the row when found', function (): void {
		$GLOBALS['__test_db_fetch_row_prepared'] = function ($sql, $p) {
			return ['id' => 5, 'dt_name' => 'Test'];
		};

		expect(topx_get_source(5))->toBe(['id' => 5, 'dt_name' => 'Test']);
	});

	it('returns an empty array when not found', function (): void {
		$GLOBALS['__test_db_fetch_row_prepared'] = function ($sql, $p) {
			return false;
		};

		expect(topx_get_source(99))->toBe([]);
	});
});

describe('topx_source_save', function (): void {
	it('delegates to sql_save for the source table', function (): void {
		$captured = null;

		$GLOBALS['__test_sql_save'] = function ($a, $t, $k) use (&$captured) {
			$captured = $t;

			return 42;
		};

		expect(topx_source_save(['id' => 0, 'hash' => 'x']))->toBe(42);
		expect($captured)->toBe('plugin_topx_source');
	});
});

describe('topx_source_remove', function (): void {
	it('issues a bound delete for the given ids', function (): void {
		$GLOBALS['__test_db_calls'] = [];

		topx_source_remove(['3', '4', 'x']);

		$deletes = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
			return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'DELETE') !== false;
		}));

		expect($deletes)->toHaveCount(1);
		expect($deletes[0]['params'])->toBe([3, 4]);
	});

	it('does nothing when no ids are given', function (): void {
		$GLOBALS['__test_db_calls'] = [];

		topx_source_remove([]);

		expect($GLOBALS['__test_db_calls'])->toBe([]);
	});
});

describe('topx_source_template_choices', function (): void {
	it('annotates templates with their in-use count', function (): void {
		$GLOBALS['__test_db_fetch_assoc'] = function ($sql) {
			return [
				['hash' => 'aaa', 'name' => 'Used Template', 'in_use' => 3],
				['hash' => 'bbb', 'name' => 'Unused Template', 'in_use' => 0],
			];
		};

		$choices = topx_source_template_choices();

		expect($choices['aaa'])->toContain('Used Template');
		expect($choices['aaa'])->toContain('3');
		expect($choices['bbb'])->toContain('Unused Template');
	});
});

describe('topx_chart_label_tokens', function (): void {
	it('returns an empty map when the data source is unknown', function (): void {
		$GLOBALS['__test_db_fetch_row_prepared'] = function ($sql, $p) {
			return false;
		};

		expect(topx_chart_label_tokens(1))->toBe([]);
	});

	it('returns host tokens without query fields when there is no data query', function (): void {
		$GLOBALS['__test_db_fetch_row_prepared'] = function ($sql, $p) {
			return [
				'host_description' => 'Router1',
				'host_hostname'    => '10.0.0.1',
				'host_id'          => 7,
				'snmp_query_id'    => 0,
				'snmp_index'       => '',
			];
		};

		$tokens = topx_chart_label_tokens(1);

		expect($tokens['host_description'])->toBe('Router1');
		expect($tokens['host_hostname'])->toBe('10.0.0.1');
		expect($tokens['host_id'])->toBe('7');
		expect($tokens)->not->toHaveKey('query_ifName');
	});

	it('adds a query_<field> token per cached data-query field', function (): void {
		$GLOBALS['__test_db_fetch_row_prepared'] = function ($sql, $p) {
			return [
				'host_description' => 'Router1',
				'host_hostname'    => '10.0.0.1',
				'host_id'          => 7,
				'snmp_query_id'    => 5,
				'snmp_index'       => '2',
			];
		};

		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) {
			return [
				['field_name' => 'ifName', 'field_value' => 'eth0'],
			];
		};

		$tokens = topx_chart_label_tokens(1);

		expect($tokens['query_ifName'])->toBe('eth0');
	});
});

describe('topx_import_apply', function (): void {
	it('inserts, updates and skips rows by template hash', function (): void {
		$GLOBALS['__test_db_fetch_cell_prepared'] = function ($sql, $p) {
			if (strpos($sql, 'FROM data_template') !== false) {
				return $p[0] === 'goodhash' ? 10 : '';
			}

			if (strpos($sql, 'FROM plugin_topx_source') !== false) {
				return ($p[0] === 'goodhash' && $p[1] === 'existing_op') ? 7 : '';
			}

			return '';
		};

		$summary = topx_import_apply([
			['hash' => '', 'operation' => 'x'],
			['hash' => 'badhash', 'operation' => 'x'],
			['hash' => 'goodhash', 'operation' => 'new_op'],
			['hash' => 'goodhash', 'operation' => 'existing_op'],
		]);

		expect($summary['inserted'])->toBe(1);
		expect($summary['updated'])->toBe(1);
		expect($summary['skipped'])->toBe(2);
	});
});
