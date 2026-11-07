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

	it('keeps the band green between Normal and Warning for an increasing source', function (): void {
		// A value past the Normal target but short of Warning is still healthy
		// (the CPU 10/28/80 case: 26% must read green, not warning).
		$source = ['range_type' => 0, 'range_normal' => 10, 'range_warning' => 28, 'range_alert' => 80];

		expect(topx_range_class(26.0, $source))->toBe('normal');
		expect(topx_range_class(5.0, $source))->toBe('normal');
		expect(topx_range_class(28.0, $source))->toBe('warning');
		expect(topx_range_class(80.0, $source))->toBe('alert');
	});

	it('keeps the band green between Normal and Warning for a decreasing source', function (): void {
		$source = ['range_type' => 1, 'range_normal' => 50, 'range_warning' => 25, 'range_alert' => 10];

		expect(topx_range_class(40.0, $source))->toBe('normal');
		expect(topx_range_class(60.0, $source))->toBe('normal');
		expect(topx_range_class(25.0, $source))->toBe('warning');
		expect(topx_range_class(10.0, $source))->toBe('alert');
	});

	it('uses Normal as the warning boundary only when no Warning threshold is set', function (): void {
		$inc = ['range_type' => 0, 'range_normal' => 10];

		expect(topx_range_class(26.0, $inc))->toBe('warning');
		expect(topx_range_class(5.0, $inc))->toBe('normal');

		$dec = ['range_type' => 1, 'range_normal' => 50];

		expect(topx_range_class(40.0, $dec))->toBe('warning');
		expect(topx_range_class(60.0, $dec))->toBe('normal');
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

describe('topx_range_peak_color', function (): void {
	it('maps each band to a muted, darker tint', function (): void {
		expect(topx_range_peak_color('normal'))->toBe('#19703e');
		expect(topx_range_peak_color('warning'))->toBe('#845118');
		expect(topx_range_peak_color('alert'))->toBe('#812a29');
	});

	it('returns an empty string for an unknown band', function (): void {
		expect(topx_range_peak_color('nope'))->toBe('');
	});
});
