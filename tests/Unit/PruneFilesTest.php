<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/setup.php';

describe('topx_prune_files', function (): void {
	it('removes tombstoned paths plus tests/ and phpunit.xml, keeping whitelisted and shipped files', function (): void {
		$root    = sys_get_temp_dir() . '/topx_prune_' . uniqid();
		$plugin  = $root . '/plugins/topx';
		mkdir($plugin . '/themes', 0777, true);
		mkdir($plugin . '/tests', 0777, true);
		mkdir($plugin . '/css', 0777, true);
		mkdir($plugin . '/keepme', 0777, true);

		file_put_contents($plugin . '/themes/dark.css', '/* old */');
		file_put_contents($plugin . '/tests/x.php', '<?php');
		file_put_contents($plugin . '/phpunit.xml', '<phpunit/>');
		file_put_contents($plugin . '/css/topx.css', '.topx{}');
		file_put_contents($plugin . '/keepme/data.txt', 'user data');
		file_put_contents($plugin . '/manifest.json', json_encode([
			'tombstones' => ['themes/'],
			'expected'   => ['css/', 'manifest.json'],
			'whitelist'  => ['keepme/'],
		]));

		$GLOBALS['config']['base_path'] = $root;

		topx_prune_files();

		expect(is_dir($plugin . '/themes'))->toBeFalse();
		expect(is_dir($plugin . '/tests'))->toBeFalse();
		expect(file_exists($plugin . '/phpunit.xml'))->toBeFalse();
		expect(is_dir($plugin . '/css'))->toBeTrue();
		expect(file_exists($plugin . '/keepme/data.txt'))->toBeTrue();

		topx_rmtree($root);
	});

	it('refuses a tombstone that escapes the plugin directory', function (): void {
		$root   = sys_get_temp_dir() . '/topx_prune_esc_' . uniqid();
		$plugin = $root . '/plugins/topx';
		mkdir($plugin, 0777, true);
		mkdir($root . '/victim', 0777, true);
		file_put_contents($root . '/victim/keep.txt', 'safe');
		file_put_contents($plugin . '/manifest.json', json_encode([
			'tombstones' => ['../../victim'],
			'expected'   => [],
			'whitelist'  => [],
		]));

		$GLOBALS['config']['base_path'] = $root;

		topx_prune_files();

		expect(file_exists($root . '/victim/keep.txt'))->toBeTrue();

		topx_rmtree($root);
	});
});
