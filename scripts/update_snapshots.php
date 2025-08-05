<?php

// scripts/update_snapshots.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ArthurDick\TermToSvg\Config;
use ArthurDick\TermToSvg\TerminalToSvgConverter;

$dataDir = __DIR__ . '/../tests/_data';
$testCaseDirs = new DirectoryIterator($dataDir);

echo "Updating integration test snapshots...\n";

foreach ($testCaseDirs as $fileInfo) {
    if (!$fileInfo->isDir() || $fileInfo->isDot()) {
        continue;
    }

    $testCaseName = $fileInfo->getFilename();
    $caseDir = $fileInfo->getPathname();
    $typescriptFile = $caseDir . '/rec.log';
    $timingFile = $caseDir . '/rec.time';
    $snapshotFile = $caseDir . '/snapshot.svg';

    if (!file_exists($typescriptFile) || !file_exists($timingFile)) {
        echo "Skipping {$testCaseName}: missing rec.log or rec.time.\n";
        continue;
    }

    try {
        echo "Processing {$testCaseName}...\n";
        $converter = new TerminalToSvgConverter($typescriptFile, $timingFile, Config::DEFAULTS);
        $svgContent = $converter->convert();
        file_put_contents($snapshotFile, trim($svgContent));
    } catch (Exception $e) {
        echo "Error processing {$testCaseName}: " . $e->getMessage() . "\n";
    }
}

echo "✅ Snapshots updated successfully.\n";
