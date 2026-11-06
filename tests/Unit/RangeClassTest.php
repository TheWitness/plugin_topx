<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/sources.php';

describe('topx_range_class', function (): void {
	it('returns an empty band when no thresholds are set', function (): void {
		expect(topx_range_class(42.0, ['range_type' => 0]))->toBe('');
	});

	it('classifies an increasing source by upper bounds', function (): void {
		$source = ['range_type' => 0, 'range_warning' => 75, 'range_alert' => 90];

		expect(topx_range_class(95.0, $source))->toBe('alert');
		expect(topx_range_class(80.0, $source))->toBe('warning');
		expect(topx_range_class(50.0, $source))->toBe('normal');
	});

	it('inverts the sense for a decreasing source', function (): void {
		$source = ['range_type' => 1, 'range_warning' => 10, 'range_alert' => 5];

		expect(topx_range_class(2.0, $source))->toBe('alert');
		expect(topx_range_class(8.0, $source))->toBe('warning');
		expect(topx_range_class(50.0, $source))->toBe('normal');
	});

	it('ignores blank thresholds', function (): void {
		$source = ['range_type' => 0, 'range_warning' => '', 'range_alert' => 90];

		expect(topx_range_class(95.0, $source))->toBe('alert');
		expect(topx_range_class(80.0, $source))->toBe('normal');
	});
});

describe('topx_range_color', function (): void {
	it('maps each band to a colour', function (): void {
		expect(topx_range_color('normal'))->toBe('#2ecc71');
		expect(topx_range_color('warning'))->toBe('#f0932b');
		expect(topx_range_color('alert'))->toBe('#eb4d4b');
	});

	it('returns an empty string for an unknown band', function (): void {
		expect(topx_range_color('nope'))->toBe('');
	});
});
