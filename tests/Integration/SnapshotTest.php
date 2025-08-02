<?php

namespace Tests\Integration;

use ArthurDick\TermToSvg\TerminalToSvgConverter;
use PHPUnit\Framework\TestCase;

class SnapshotTest extends TestCase
{
    private const DATA_DIR = __DIR__ . '/../_data/';

    /**
     * @dataProvider snapshotProvider
     */
    public function testSvgOutputMatchesSnapshot(string $testCaseName): void
    {
        $caseDir = self::DATA_DIR . $testCaseName;
        $typescriptFile = $caseDir . '/rec.log';
        $timingFile = $caseDir . '/rec.time';
        $snapshotFile = $caseDir . '/snapshot.svg';

        $this->assertFileExists($typescriptFile, "Log file missing for test case: $testCaseName");
        $this->assertFileExists($timingFile, "Timing file missing for test case: $testCaseName");
        $this->assertFileExists($snapshotFile, "Snapshot SVG missing for test case: $testCaseName");

        $expected = trim(file_get_contents($snapshotFile));

        $converter = new TerminalToSvgConverter($typescriptFile, $timingFile, TerminalToSvgConverter::CONFIG);
        $svgContent = trim($converter->convert());

        $this->assertEquals($expected, $svgContent, "Generated SVG does not match snapshot for test case: $testCaseName");
    }

    public static function snapshotProvider(): array
    {
        return [
            'simple text' => ['simple_text'],
            'clear screen command' => ['clear_screen']
        ];
    }
}
