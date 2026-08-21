<?php

/**
 * @file
 * Runs tests/run-suite.php under a coverage driver and writes reports.
 *
 * The suite is a plain PHP script rather than PHPUnit, so there is no runner to ask for
 * coverage. This wraps it: start collection, require the suite, and write the reports from
 * a shutdown handler because the suite ends with exit().
 *
 * Only src/ is measured. tests/ is the instrument, and measuring the instrument inflates
 * the number without covering anything a consumer installs.
 *
 * Usage:
 *   php tests/coverage.php
 *
 * Exits 2 without running anything when vendor/ or the coverage driver is missing, so a CI
 * job cannot report a pass it did not measure. A driverless run would emit 0%, which is
 * indistinguishable from a real 0% and reads as "no tests yet" rather than "not measured".
 */

declare(strict_types=1);

// this script declares no namespace, so the global classes it uses (FilesystemIterator,
// RecursiveDirectoryIterator, RecursiveIteratorIterator, Throwable) resolve unqualified and
// cannot be imported - `use` from the same namespace is a phpcs error, not a style choice
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Node\Directory;
use SebastianBergmann\CodeCoverage\Report\Clover;
use SebastianBergmann\CodeCoverage\Report\Text;
use SebastianBergmann\CodeCoverage\Report\Thresholds;

// the shutdown handler registered below is what keeps the collector alive, so this returns
// nothing and leaves no unused handle behind
(static function (): void {
	$repo = dirname(__DIR__);

	if (!is_file($repo . '/vendor/autoload.php')) {
		fwrite(STDERR, "Run composer install first; vendor/autoload.php is missing.\n");
		exit(2);
	}
	if (!extension_loaded('xdebug') && !extension_loaded('pcov')) {
		fwrite(
			STDERR,
			"No coverage driver: install xdebug or pcov.\n" .
				"Refusing to write an empty report that would read as 0% rather than as unmeasured.\n",
		);
		exit(2);
	}

	require $repo . '/vendor/autoload.php';

	$filter = new Filter();
	$walk = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($repo . '/src', FilesystemIterator::SKIP_DOTS),
	);
	$measured = [];
	foreach ($walk as $file) {
		if ($file->isFile() && $file->getExtension() === 'php') {
			$measured[] = $file->getPathname();
		}
	}
	sort($measured);
	if ($measured === []) {
		fwrite(STDERR, "No PHP files under $repo/src to measure.\n");
		exit(2);
	}
	$filter->includeFiles($measured);

	$out = $repo . '/coverage';
	if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
		fwrite(STDERR, "Could not create $out.\n");
		exit(2);
	}

	$coverage = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
	$coverage->start('wrapper-suite');

	// the suite ends in exit(), which still runs shutdown handlers, so this is the only place
	// the reports can be written from without editing the suite
	register_shutdown_function(static function () use ($coverage, $out): void {
		$coverage->stop();
		// php-code-coverage 12 takes a Node\Directory where 10 took the collector, and the writers
		// moved at different times -- so ask each writer's own signature rather than the library
		// version. CI failed with "Argument #1 ($report) must be of type Node\Directory,
		// CodeCoverage given"; `rom` already had this shape and this file did not.
		$node = null;
		$reportFor = static function (object $writer) use (
			$coverage,
			&$node,
		): CodeCoverage|Directory {
			$first = (new ReflectionMethod($writer, 'process'))->getParameters()[0] ?? null;
			$type = $first?->getType();
			if ($type instanceof ReflectionNamedType && $type->getName() === Directory::class) {
				// getReport() walks the whole tree, so it is built at most once
				return $node ??= $coverage->getReport();
			}
			return $coverage;
		};

		try {
			$clover = new Clover();
			$text = new Text(Thresholds::default(), false, true);
			$clover->process($reportFor($clover), $out . '/stream-http.clover.xml');
			$summary = $text->process($reportFor($text), false);
		} catch (Throwable $e) {
			fwrite(STDERR, "\nCoverage report failed: " . $e->getMessage() . "\n");
			return;
		}
		file_put_contents($out . '/stream-http.coverage.txt', $summary);
		echo "\n" . $summary;
		echo "wrote $out/stream-http.clover.xml and $out/stream-http.coverage.txt\n";
	});
})();

require __DIR__ . '/run-suite.php';
