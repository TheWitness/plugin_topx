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

describe('topx_host_replacements', function (): void {
	it('returns the static host token names', function (): void {
		expect(topx_host_replacements())->toBe(['host_description', 'host_hostname', 'host_id']);
	});
});

describe('topx_source_rrd_fields', function (): void {
	it('returns an empty list for an invalid hash without querying', function (): void {
		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) {
			throw new RuntimeException('should not query');
		};

		expect(topx_source_rrd_fields('not-a-hash'))->toBe([]);
	});

	it('returns the template RRD field names', function (): void {
		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) {
			return [['field' => 'traffic_in'], ['field' => 'traffic_out']];
		};

		expect(topx_source_rrd_fields('6632e1e0b58a565c135d7ff90440c335'))->toBe(['traffic_in', 'traffic_out']);
	});
});

describe('topx_source_query_fields', function (): void {
	it('returns the data query field names', function (): void {
		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) {
			return [['field' => 'ifAlias'], ['field' => 'ifName']];
		};

		expect(topx_source_query_fields('6632e1e0b58a565c135d7ff90440c335'))->toBe(['ifAlias', 'ifName']);
	});

	it('returns an empty list for an invalid hash', function (): void {
		expect(topx_source_query_fields('xx'))->toBe([]);
	});
});

describe('topx_token_help', function (): void {
	it('combines rrd, host and query token sets', function (): void {
		$calls = 0;

		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) use (&$calls) {
			$calls++;

			if (strpos($sql, 'data_template_rrd') !== false) {
				return [['field' => 'cpu']];
			}

			return [['field' => 'ifName']];
		};

		$help = topx_token_help('6632e1e0b58a565c135d7ff90440c335');

		expect($help['rrd'])->toBe(['cpu']);
		expect($help['host'])->toBe(['host_description', 'host_hostname', 'host_id']);
		expect($help['query'])->toBe(['ifName']);
	});
});

describe('topx_default_operation', function (): void {
	it('defaults a single-field template to field=field', function (): void {
		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) {
			return [['field' => 'users']];
		};

		expect(topx_default_operation('6632e1e0b58a565c135d7ff90440c335'))->toBe('users=users');
	});

	it('returns an empty string for a multi-field template', function (): void {
		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) {
			return [['field' => 'traffic_in'], ['field' => 'traffic_out']];
		};

		expect(topx_default_operation('6632e1e0b58a565c135d7ff90440c335'))->toBe('');
	});
});

describe('topx_range_validation_error', function (): void {
	it('accepts a valid increasing set', function (): void {
		expect(topx_range_validation_error([
			'range_type' => 0, 'range_normal' => 50, 'range_warning' => 75, 'range_alert' => 90,
		]))->toBe('');
	});

	it('rejects an increasing set that is not ascending', function (): void {
		expect(topx_range_validation_error([
			'range_type' => 0, 'range_normal' => 50, 'range_warning' => 40, 'range_alert' => 90,
		]))->not->toBe('');
	});

	it('accepts a valid decreasing set', function (): void {
		expect(topx_range_validation_error([
			'range_type' => 1, 'range_normal' => 90, 'range_warning' => 50, 'range_alert' => 10,
		]))->toBe('');
	});

	it('rejects a decreasing set that is not descending', function (): void {
		expect(topx_range_validation_error([
			'range_type' => 1, 'range_normal' => 10, 'range_warning' => 50, 'range_alert' => 90,
		]))->not->toBe('');
	});

	it('skips blank thresholds and orders only the set ones', function (): void {
		// normal and alert only: 50 < 90 is valid for increasing.
		expect(topx_range_validation_error([
			'range_type' => 0, 'range_normal' => 50, 'range_warning' => '', 'range_alert' => 90,
		]))->toBe('');

		// normal and alert only: 90 then 50 violates increasing.
		expect(topx_range_validation_error([
			'range_type' => 0, 'range_normal' => 90, 'range_warning' => null, 'range_alert' => 50,
		]))->not->toBe('');
	});

	it('accepts a set with no thresholds', function (): void {
		expect(topx_range_validation_error(['range_type' => 0]))->toBe('');
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

		unset($GLOBALS['__test_sql_save']);
	});

	it('restores blank range thresholds to NULL after sql_save', function (): void {
		$captured = null;

		$GLOBALS['__test_sql_save'] = function ($a, $t, $k) {
			return 42;
		};

		$GLOBALS['__test_db_execute_prepared'] = function ($sql, $p) use (&$captured) {
			$captured = ['sql' => $sql, 'params' => $p];

			return true;
		};

		topx_source_save([
			'id'            => 0,
			'hash'          => 'x',
			'range_normal'  => '',
			'range_warning' => 50,
			'range_alert'   => '',
		]);

		// Only the blank columns are NULLed, scoped to the saved id.
		expect($captured['sql'])->toContain('`range_normal` = NULL');
		expect($captured['sql'])->toContain('`range_alert` = NULL');
		expect($captured['sql'])->not->toContain('range_warning');
		expect($captured['params'])->toBe([42]);

		unset($GLOBALS['__test_sql_save'], $GLOBALS['__test_db_execute_prepared']);
	});

	it('does not issue a NULL-restore update when all thresholds are set', function (): void {
		$called = false;

		$GLOBALS['__test_sql_save'] = function ($a, $t, $k) {
			return 42;
		};

		$GLOBALS['__test_db_execute_prepared'] = function ($sql, $p) use (&$called) {
			$called = true;

			return true;
		};

		topx_source_save([
			'id'            => 0,
			'hash'          => 'x',
			'range_normal'  => 25,
			'range_warning' => 50,
			'range_alert'   => 90,
		]);

		expect($called)->toBeFalse();

		unset($GLOBALS['__test_sql_save'], $GLOBALS['__test_db_execute_prepared']);
	});
});

describe('topx_operation_is_executable', function (): void {
	it('accepts the executable single-field shapes', function (): void {
		expect(topx_operation_is_executable('cpu'))->toBeTrue();
		expect(topx_operation_is_executable('cpu=cpu'))->toBeTrue();
		expect(topx_operation_is_executable('uptime/8640000'))->toBeTrue();
		expect(topx_operation_is_executable('uptime=uptime/8640000'))->toBeTrue();
	});

	it('accepts the two-field sum and percentage shapes', function (): void {
		expect(topx_operation_is_executable('reads+writes'))->toBeTrue();
		expect(topx_operation_is_executable('used%total'))->toBeTrue();
	});

	it('rejects empty or unparseable expressions', function (): void {
		expect(topx_operation_is_executable(''))->toBeFalse();
		expect(topx_operation_is_executable('   '))->toBeFalse();
		expect(topx_operation_is_executable('a+b+c'))->toBeFalse();
		expect(topx_operation_is_executable('a-b'))->toBeFalse();
		expect(topx_operation_is_executable('not an expression'))->toBeFalse();
	});
});

describe('topx_chart_label_tokens_bulk', function (): void {
	it('returns an empty map for no ids without querying', function (): void {
		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) {
			throw new RuntimeException('should not query');
		};

		expect(topx_chart_label_tokens_bulk([]))->toBe([]);

		unset($GLOBALS['__test_db_fetch_assoc_prepared']);
	});

	it('resolves host and data-query tokens for many ids in two queries', function (): void {
		$queries = 0;

		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) use (&$queries) {
			$queries++;

			if (strpos($sql, 'data_local') !== false) {
				return [
					['ldid' => 1, 'host_description' => 'R1', 'host_hostname' => '10.0.0.1', 'host_id' => 7, 'snmp_query_id' => 5, 'snmp_index' => '2'],
					['ldid' => 2, 'host_description' => 'R2', 'host_hostname' => '10.0.0.2', 'host_id' => 8, 'snmp_query_id' => 0, 'snmp_index' => ''],
				];
			}

			return [
				['host_id' => 7, 'snmp_query_id' => 5, 'snmp_index' => '2', 'field_name' => 'ifName', 'field_value' => 'eth0'],
			];
		};

		$map = topx_chart_label_tokens_bulk([1, 2, 1]);

		expect($queries)->toBe(2);
		expect($map[1]['host_description'])->toBe('R1');
		expect($map[1]['query_ifName'])->toBe('eth0');
		expect($map[2]['host_description'])->toBe('R2');
		expect($map[2])->not->toHaveKey('query_ifName');

		unset($GLOBALS['__test_db_fetch_assoc_prepared']);
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
		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) {
			return [];
		};

		expect(topx_chart_label_tokens(1))->toBe([]);
	});

	it('returns host tokens without query fields when there is no data query', function (): void {
		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) {
			if (strpos($sql, 'data_local') !== false) {
				return [
					[
						'ldid'             => 1,
						'host_description' => 'Router1',
						'host_hostname'    => '10.0.0.1',
						'host_id'          => 7,
						'snmp_query_id'    => 0,
						'snmp_index'       => '',
					],
				];
			}

			return [];
		};

		$tokens = topx_chart_label_tokens(1);

		expect($tokens['host_description'])->toBe('Router1');
		expect($tokens['host_hostname'])->toBe('10.0.0.1');
		expect($tokens['host_id'])->toBe('7');
		expect($tokens)->not->toHaveKey('query_ifName');
	});

	it('adds a query_<field> token per cached data-query field', function (): void {
		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) {
			if (strpos($sql, 'data_local') !== false) {
				return [
					[
						'ldid'             => 1,
						'host_description' => 'Router1',
						'host_hostname'    => '10.0.0.1',
						'host_id'          => 7,
						'snmp_query_id'    => 5,
						'snmp_index'       => '2',
					],
				];
			}

			return [
				['host_id' => 7, 'snmp_query_id' => 5, 'snmp_index' => '2', 'field_name' => 'ifName', 'field_value' => 'eth0'],
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
