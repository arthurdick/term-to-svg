<?php

declare(strict_types=1);

namespace Tests\Integration;

use ArthurDick\TermToSvg\Config;
use ArthurDick\TermToSvg\TerminalToSvgConverter;
use PHPUnit\Framework\TestCase;

class PosterSnapshotTest extends TestCase
{
    private const DATA_DIR = __DIR__ . '/../_data/poster';

    /**
     * @dataProvider posterSnapshotProvider
     */
    public function testPosterGenerationMatchesSnapshot(string $time, string $snapshotFilename): void
    {
        $typescriptFile = self::DATA_DIR . '/rec.log';
        $timingFile = self::DATA_DIR . '/rec.time';
        $snapshotFile = self::DATA_DIR . '/' . $snapshotFilename;

        $this->assertFileExists($typescriptFile, "Log file missing for poster test case");
        $this->assertFileExists($timingFile, "Timing file missing for poster test case");
        $this->assertFileExists($snapshotFile, "Snapshot SVG missing for poster test case: $snapshotFilename");

        $expected = trim(file_get_contents($snapshotFile));

        $config = Config::DEFAULTS;
        $config['id'] = 't';
        $config['poster_at'] = $time;

        $converter = new TerminalToSvgConverter($typescriptFile, $timingFile, $config);
        $svgContent = trim($converter->convert());

        $this->assertEquals($expected, $svgContent, "Generated poster SVG does not match snapshot for time: '{$time}'");
    }

    public static function posterSnapshotProvider(): array
    {
        return [
            'at start' => ['0.5', 'poster_start.svg'],
            'mid-way on main screen' => ['2.5', 'poster_middle.svg'],
            'on alternate screen' => ['5.5', 'poster_alt.svg'],
            'at the end' => ['end', 'poster_end.svg'],
        ];
    }
}
