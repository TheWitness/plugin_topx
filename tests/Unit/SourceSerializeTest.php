<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/sources.php';

describe('topx_source_prepare_save', function (): void {
	it('coerces enumerated fields to their allowlists', function (): void {
		$save = topx_source_prepare_save([
			'id'              => '7',
			'sorting'         => 'bogus',
			'final_operation' => 'bogus',
			'system'          => 'bogus',
			'range_type'      => '2',
		]);

		expect($save['id'])->toBe(7);
		expect($save['sorting'])->toBe('desc');
		expect($save['final_operation'])->toBe('strip');
		expect($save['system'])->toBe('decimal');
		expect($save['range_type'])->toBe(0);
	});

	it('keeps valid enumerated values', function (): void {
		$save = topx_source_prepare_save([
			'sorting'         => 'asc',
			'final_operation' => '/',
			'system'          => 'bits',
			'range_type'      => '1',
		]);

		expect($save['sorting'])->toBe('asc');
		expect($save['final_operation'])->toBe('/');
		expect($save['system'])->toBe('bits');
		expect($save['range_type'])->toBe(1);
	});

	it('converts blank thresholds to null and numeric to float', function (): void {
		$save = topx_source_prepare_save([
			'range_normal'  => '',
			'range_warning' => '75',
			'range_alert'   => '90.5',
		]);

		expect($save['range_normal'])->toBeNull();
		expect($save['range_warning'])->toBe(75.0);
		expect($save['range_alert'])->toBe(90.5);
	});

	it('clamps final_decimals to the allowed set, Auto otherwise', function (): void {
		expect(topx_source_prepare_save(['final_decimals' => '2'])['final_decimals'])->toBe('2');
		expect(topx_source_prepare_save(['final_decimals' => '0'])['final_decimals'])->toBe('0');
		expect(topx_source_prepare_save(['final_decimals' => '9'])['final_decimals'])->toBe('');
		expect(topx_source_prepare_save(['final_decimals' => 'x'])['final_decimals'])->toBe('');
		expect(topx_source_prepare_save([])['final_decimals'])->toBe('');
	});
});

describe('topx_source_decimal_labels', function (): void {
	it('offers Auto plus 0 to 4', function (): void {
		$labels = topx_source_decimal_labels();

		expect($labels)->toHaveKey('');
		// PHP coerces the numeric string keys '0'-'4' to integers.
		expect(array_keys($labels))->toBe(['', 0, 1, 2, 3, 4]);
	});
});

describe('topx_source export/import round-trip', function (): void {
	it('round-trips a source definition through XML keyed on hash', function (): void {
		$row = [
			'id'              => 99,
			'sorting'         => 'desc',
			'dt_name'         => 'Interface - Traffic',
			'hash'            => '6632e1e0b58a565c135d7ff90440c335',
			'operation'       => 'traffic_in+traffic_out',
			'unit'            => 'Bits',
			'final_operation' => '/',
			'final_unit'      => 'bit/Kbit/Mbit',
			'final_number'    => '1/1024/1048576',
			'final_decimals'  => '2',
			'system'          => 'bits',
			'range_type'      => 0,
			'range_normal'    => null,
			'range_warning'   => null,
			'range_alert'     => null,
			'chart_label'     => '|host_description| - |query_ifName|',
		];

		$xml    = topx_source_export_xml([$row]);
		$parsed = topx_source_import_xml($xml);

		expect($parsed['error'])->toBe('');
		expect($parsed['sources'])->toHaveCount(1);

		$imported = $parsed['sources'][0];

		expect($imported['hash'])->toBe($row['hash']);
		expect($imported['operation'])->toBe($row['operation']);
		expect($imported['chart_label'])->toBe($row['chart_label']);
		expect($imported['final_unit'])->toBe($row['final_unit']);
		expect($imported['final_decimals'])->toBe($row['final_decimals']);
		// The install-local id is never exported.
		expect($imported['id'])->toBe(0);
	});

	it('rejects a non-TopX document', function (): void {
		$parsed = topx_source_import_xml('<something><else/></something>');

		expect($parsed['sources'])->toBe([]);
		expect($parsed['error'])->not->toBe('');
	});

	it('rejects a malformed XML document', function (): void {
		$parsed = topx_source_import_xml('<topx_data_sources><source></broken>');

		expect($parsed['sources'])->toBe([]);
		expect($parsed['error'])->not->toBe('');
	});

	it('rejects an empty document', function (): void {
		$parsed = topx_source_import_xml('   ');

		expect($parsed['error'])->not->toBe('');
	});

	it('skips rows without a hash', function (): void {
		$xml = "<topx_data_sources version=\"1.1\"><source><dt_name>No Hash</dt_name></source></topx_data_sources>";

		$parsed = topx_source_import_xml($xml);

		expect($parsed['sources'])->toBe([]);
		expect($parsed['error'])->not->toBe('');
	});
});

describe('topx_source_columns', function (): void {
	it('excludes the install-local id', function (): void {
		expect(topx_source_columns())->not->toContain('id');
		expect(topx_source_columns())->toContain('chart_label');
		expect(topx_source_columns())->toContain('range_type');
	});

	it('blank row carries every managed column', function (): void {
		$blank = topx_source_blank();

		foreach (topx_source_columns() as $column) {
			expect($blank)->toHaveKey($column);
		}
	});
});
