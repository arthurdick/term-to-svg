<?php

declare(strict_types=1);

namespace Tests\Integration;

use ArthurDick\TermToSvg\Config;
use ArthurDick\TermToSvg\TerminalToSvgConverter;
use PHPUnit\Framework\TestCase;

class SnapshotTest extends TestCase
{
    private const DATA_DIR = __DIR__ . '/../_data/';

    /**
     * @dataProvider snapshotProvider
     */
    public function testSvgOutputMatchesSnapshot(string $testCaseName, string $generator): void
    {
        $caseDir = self::DATA_DIR . $testCaseName;
        $typescriptFile = $caseDir . '/rec.log';
        $timingFile = $caseDir . '/rec.time';
        $snapshotFile = $caseDir . "/snapshot.{$generator}.svg";

        $this->assertFileExists($typescriptFile, "Log file missing for test case: $testCaseName");
        $this->assertFileExists($timingFile, "Timing file missing for test case: $testCaseName");
        $this->assertFileExists($snapshotFile, "Snapshot SVG missing for test case: $testCaseName with generator: $generator");

        $expected = trim(file_get_contents($snapshotFile));

        $config = Config::DEFAULTS;
        $config['id'] = 't';
        $config['generator'] = $generator;

        $converter = new TerminalToSvgConverter($typescriptFile, $timingFile, $config);
        $svgContent = trim($converter->convert());

        $this->assertEquals($expected, $svgContent, "Generated SVG does not match snapshot for test case: $testCaseName with generator: $generator");
    }

    public static function snapshotProvider(): array
    {
        $testCases = [
            'simple text' => ['simple_text'],
            'clear screen command' => ['clear_screen'],
            'text attributes' => ['text_attributes'],
            'link' => ['link'],
        ];

        $providerData = [];
        foreach ($testCases as $key => $case) {
            $providerData["$key (smil)"] = [$case[0], 'smil'];
            $providerData["$key (css)"] = [$case[0], 'css'];
        }

        return $providerData;
    }
}
