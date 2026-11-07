<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

/*
 * plugin_topx_csp_nonce() has two branches that hinge on whether Cacti's
 * CactiSecureHeaders class is present. A single PHP process only ever sees the
 * class as present or absent, so the delegation branch is exercised in an
 * isolated child process that defines a controlled double.
 */

describe('plugin_topx_csp_nonce', function (): void {
	it('returns an empty string when CactiSecureHeaders is unavailable', function (): void {
		if (class_exists('CactiSecureHeaders')) {
			$this->markTestSkipped('CactiSecureHeaders is present; the delegation path is covered by the isolated-process test.');
		}

		expect(plugin_topx_csp_nonce())->toBe('');
	});

	it('is declared to return a string', function (): void {
		$ref = new ReflectionFunction('plugin_topx_csp_nonce');

		expect((string) $ref->getReturnType())->toBe('string');
	});

	it('delegates to CactiSecureHeaders when the class is available', function (): void {
		$functions = realpath(dirname(__DIR__, 2) . '/includes/functions.php');
		$bootstrap = realpath(__DIR__ . '/../bootstrap-unit.php');

		$script = <<<'CHILD'
<?php
error_reporting(0);
ini_set('display_errors', '0');

class CactiSecureHeaders {
	public static function getNonceAttribute(): string {
		return ' nonce="pest-controlled-double"';
	}
}

require $argv[1];
require $argv[2];

echo "<<<" . plugin_topx_csp_nonce() . ">>>";
CHILD;

		$tmp = tempnam(sys_get_temp_dir(), 'topx_csp_');
		expect($tmp)->not->toBeFalse();

		try {
			file_put_contents($tmp, $script);

			$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($bootstrap) . ' ' . escapeshellarg($functions);
			$output  = shell_exec($command);

			preg_match('/<<<(.*)>>>/s', (string) $output, $matches);

			expect($matches)->toHaveKey(1);
			expect($matches[1])->toBe(' nonce="pest-controlled-double"');
		} finally {
			@unlink($tmp);
		}
	});
});
