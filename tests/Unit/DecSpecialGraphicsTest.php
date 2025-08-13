<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArthurDick\TermToSvg\AnsiParser;
use ArthurDick\TermToSvg\Config;
use ArthurDick\TermToSvg\TerminalState;
use PHPUnit\Framework\TestCase;

class DecSpecialGraphicsTest extends TestCase
{
    private AnsiParser $parser;
    private TerminalState $state;
    private float $time = 0.0;

    protected function setUp(): void
    {
        $config = Config::DEFAULTS;
        $this->state = new TerminalState($config);
        $this->parser = new AnsiParser($this->state, $config);
    }

    private function process(string $chunk): void
    {
        $this->time += 0.1;
        $this->parser->processChunk($chunk, $this->time);
    }

    private function findActiveCellAt(int $y, int $x): ?array
    {
        $buffer = $this->state->mainBuffer;
        if (!isset($buffer[$y][$x]) || empty($buffer[$y][$x])) {
            return null;
        }

        foreach (array_reverse($buffer[$y][$x]) as $cell) {
            if ($cell['startTime'] <= $this->time && (!isset($cell['endTime']) || $cell['endTime'] > $this->time)) {
                return $cell;
            }
        }
        return null;
    }

    public function testDecSpecialGraphicsCharacterSet(): void
    {
        // -- Arrange --
        $testString = '`abcdefghijklmnopqrstuvwxyz{|}~';
        $expectedChars = [
            '◆', '▒', '␉', '␌', '␍', '␊', '°', '±', '␤', '␋', '┘', '┐', '┌', '└', '┼', '⎺', '⎻', '─',
            '⎼', '⎽', '├', '┤', '┴', '┬', '│', '≤', '≥', 'π', '≠', '£', '·'
        ];

        // -- Act --
        // Enable DEC Special Graphics Character Set
        $this->process("\x1b(0");
        $this->process($testString);

        // Disable DEC Special Graphics Character Set
        $this->process("\x1b(B");
        $this->process("\r\n"); // Move to the start of the next line
        $this->process($testString);

        // -- Assert --
        // Verify Special Graphics Characters on the first line
        foreach ($expectedChars as $index => $char) {
            $cell = $this->findActiveCellAt(0, $index);
            $this->assertNotNull($cell, "No cell found for special character at (0, $index)");
            $this->assertEquals($char, $cell['char']);
        }

        // Verify standard ASCII characters on the second line
        foreach (mb_str_split($testString) as $index => $char) {
            $cell = $this->findActiveCellAt(1, $index);
            $this->assertNotNull($cell, "No cell found for ASCII character at (1, $index)");
            $this->assertEquals($char, $cell['char']);
        }
    }
}
