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

    if (!file_exists($typescriptFile) || !file_exists($timingFile)) {
        echo "Skipping {$testCaseName}: missing rec.log or rec.time.\n";
        continue;
    }

    try {
        if ($testCaseName === 'poster') {
            echo "Processing poster snapshots...\n";
            $posterTests = [
                '0.5' => 'poster_start.svg',
                '2.5' => 'poster_middle.svg',
                '5.5' => 'poster_alt.svg',
                'end' => 'poster_end.svg',
            ];

            foreach ($posterTests as $time => $filename) {
                echo "  - Generating poster at time '{$time}' -> {$filename}\n";
                $config = Config::DEFAULTS;
                $config['poster_at'] = $time;
                $converter = new TerminalToSvgConverter($typescriptFile, $timingFile, $config);
                $svgContent = $converter->convert();
                file_put_contents($caseDir . '/' . $filename, trim($svgContent));
            }
        } else {
            echo "Processing animated snapshot for {$testCaseName}...\n";
            $snapshotFile = $caseDir . '/snapshot.svg';
            $converter = new TerminalToSvgConverter($typescriptFile, $timingFile, Config::DEFAULTS);
            $svgContent = $converter->convert();
            file_put_contents($snapshotFile, trim($svgContent));
        }
    } catch (Exception $e) {
        echo "Error processing {$testCaseName}: " . $e->getMessage() . "\n";
    }
}

echo "✅ Snapshots updated successfully.\n";
