<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArthurDick\TermToSvg\AnsiParser;
use ArthurDick\TermToSvg\Config;
use ArthurDick\TermToSvg\TerminalState;
use PHPUnit\Framework\TestCase;

class ScrollingTest extends TestCase
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

    public function testScrollUpInRegionMovesTextCorrectly(): void
    {
        // -- Arrange --
        // Write content outside the scroll region to ensure it's not affected
        $this->process("Header\n"); // Line 0

        // Set scroll region from line 3 to 7 (0-indexed: 2 to 6)
        $this->process("\x1b[3;7r");
        $this->assertEquals(2, $this->state->scrollTop);
        $this->assertEquals(6, $this->state->scrollBottom);

        // Fill the scroll region with distinct text
        $this->process("\x1b[3;1HLine 3");
        $this->process("\x1b[4;1HLine 4");
        $this->process("\x1b[5;1HLine 5");
        $this->process("\x1b[6;1HLine 6");
        $this->process("\x1b[7;1HLine 7");

        // Write content below the scroll region
        $this->process("\x1b[10;1HFooter");

        // -- Act --
        // Move cursor to the bottom of the scroll region and trigger a scroll
        $this->process("\x1b[7;1H\n");


        // -- Assert --
        // Check that the header is untouched
        $this->assertEquals('H', $this->findActiveCellAt(0, 0)['char']);

        // Check that the footer is untouched
        $this->assertEquals('F', $this->findActiveCellAt(9, 0)['char']);

        // Check that "Line 3" is gone (scrolled out of view) and "Line 4" has moved up to its place (row 2)
        $this->assertEquals('4', $this->findActiveCellAt(2, 5)['char']);

        // Check that "Line 7" has moved up to row 5.
        $this->assertEquals('7', $this->findActiveCellAt(5, 5)['char']);

        // Check that the last line of the scroll region is now blank
        $this->assertNull($this->findActiveCellAt(6, 0));
    }

    public function testScrollDownInRegionMovesTextCorrectly(): void
    {
        // -- Arrange --
        $this->process("Header\n");
        $this->process("\x1b[3;7r");
        $this->process("\x1b[3;1HLine 3");
        $this->process("\x1b[4;1HLine 4");
        $this->process("\x1b[5;1HLine 5");
        $this->process("\x1b[6;1HLine 6");
        $this->process("\x1b[7;1HLine 7");
        $this->process("\x1b[10;1HFooter");

        // -- Act --
        // Move cursor to the top of the scroll region and trigger a reverse scroll (scroll down)
        $this->process("\x1b[3;1H\x1bM"); // ESC M is Reverse Index

        // -- Assert --
        // Header and Footer should be untouched
        $this->assertEquals('H', $this->findActiveCellAt(0, 0)['char']);
        $this->assertEquals('F', $this->findActiveCellAt(9, 0)['char']);

        // Line 3 should have moved down to line 4's original position
        $this->assertEquals('3', $this->findActiveCellAt(3, 5)['char']);

        // Line 6 should have moved down to line 7's original position
        $this->assertEquals('6', $this->findActiveCellAt(6, 5)['char']);

        // The top line of the scroll region (line 3's original position) should now be blank
        $this->assertNull($this->findActiveCellAt(2, 0));
    }
}
