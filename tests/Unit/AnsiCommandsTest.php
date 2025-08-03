<?php

namespace Tests\Unit;

use ArthurDick\TermToSvg\AnsiParser;
use ArthurDick\TermToSvg\TerminalState;
use PHPUnit\Framework\TestCase;

class AnsiCommandsTest extends TestCase
{
    private AnsiParser $parser;
    private TerminalState $state;
    private float $time = 0.0;

    protected function setUp(): void
    {
        $config = [
            'rows' => 24,
            'cols' => 80,
            'font_size' => 14,
            'line_height_factor' => 1.2,
            'font_family' => 'monospace',
            'default_fg' => '#e0e0e0',
            'default_bg' => '#1a1a1a',
        ];

        $this->state = new TerminalState($config);
        $this->parser = new AnsiParser($this->state, $config);
    }

    private function process(string $chunk): void
    {
        $this->time += 0.1;
        $this->parser->processChunk($chunk, $this->time);
    }

    /**
     * Test CSI J (Erase in Display) with parameter 2 (clear entire screen).
     */
    public function testEraseInDisplayClearsEntireScreen(): void
    {
        $this->process("Hello\nWorld");
        $this->process("\x1b[2J"); // ESC[2J

        $buffer = $this->state->mainBuffer;

        for ($y = 0; $y < 24; $y++) {
            for ($x = 0; $x < 80; $x++) {
                $this->assertArrayHasKey($y, $buffer);
                $this->assertArrayHasKey($x, $buffer[$y]);
                $lastCellState = end($buffer[$y][$x]);
                $this->assertEquals('&#160;', $lastCellState['char']);
            }
        }
    }

    public function testCursorForwardMovesCursor(): void
    {
        $this->assertEquals(0, $this->state->cursorX);
        $this->process("\x1b[5C"); // ESC[5C
        $this->assertEquals(5, $this->state->cursorX);
    }

    public function testCursorUpMovesCursor(): void
    {
        $this->process("\n\n\n");
        $this->assertEquals(3, $this->state->cursorY);
        $this->process("\x1b[2A"); // ESC[2A
        $this->assertEquals(1, $this->state->cursorY);
    }

    public function testCursorDownMovesCursor(): void
    {
        $this->state->cursorY = 5;
        $this->process("\x1b[3B");
        $this->assertEquals(8, $this->state->cursorY);
    }

    public function testCursorBackwardMovesCursor(): void
    {
        $this->state->cursorX = 10;
        $this->process("\x1b[4D");
        $this->assertEquals(6, $this->state->cursorX);
    }

    public function testCursorPosition(): void
    {
        $this->process("\x1b[10;20H");
        $this->assertEquals(9, $this->state->cursorY);
        $this->assertEquals(19, $this->state->cursorX);
    }

    public function testCursorCharacterAbsolute(): void
    {
        $this->process("\x1b[15G");
        $this->assertEquals(14, $this->state->cursorX);
    }

    public function testVerticalLinePositionAbsolute(): void
    {
        $this->process("\x1b[12d");
        $this->assertEquals(11, $this->state->cursorY);
    }

    public function testIndexCommand(): void
    {
        $this->state->cursorY = 10;
        $this->process("\x1bD");
        $this->assertEquals(11, $this->state->cursorY);
    }

    public function testReverseIndexCommand(): void
    {
        $this->state->cursorY = 10;
        $this->process("\x1bM");
        $this->assertEquals(9, $this->state->cursorY);
    }

    public function testNextLineCommand(): void
    {
        $this->state->cursorX = 15;
        $this->state->cursorY = 10;
        $this->process("\x1bE");
        $this->assertEquals(0, $this->state->cursorX);
        $this->assertEquals(11, $this->state->cursorY);
    }

    public function testEraseInLineFromCursorToEnd(): void
    {
        $this->process("some text");
        $this->state->cursorX = 5;
        $this->process("\x1b[0K");
        $buffer = $this->state->mainBuffer;
        for ($x = 5; $x < 9; $x++) {
            $lastCellState = end($buffer[0][$x]);
            $this->assertEquals('&#160;', $lastCellState['char']);
        }
    }

    public function testDeleteCharacters(): void
    {
        $this->process("abcdef");
        $this->state->cursorX = 2; // cursor on 'c'
        $this->process("\x1b[2P"); // delete 2 chars
        $buffer = $this->state->mainBuffer;
        $this->assertEquals('e', end($buffer[0][2])['char']);
        $this->assertEquals('f', end($buffer[0][3])['char']);
    }

    public function testInsertCharacters(): void
    {
        $this->process("abcdef");
        $this->state->cursorX = 2; // cursor on 'c'
        $this->process("\x1b[2@"); // insert 2 chars
        $buffer = $this->state->mainBuffer;
        $this->assertEquals('&#160;', end($buffer[0][2])['char']);
        $this->assertEquals('&#160;', end($buffer[0][3])['char']);
        $this->assertEquals('c', end($buffer[0][4])['char']);
        $this->assertEquals('d', end($buffer[0][5])['char']);
    }

    public function testSetScrollRegion(): void
    {
        $this->process("\x1b[5;15r");
        $this->assertEquals(4, $this->state->scrollTop);
        $this->assertEquals(14, $this->state->scrollBottom);
    }

    public function testInsertLines(): void
    {
        $this->state->cursorY = 10;
        $this->process("\x1b[2L");
        $buffer = $this->state->mainBuffer;
        for ($x = 0; $x < 80; $x++) {
            $this->assertEquals('&#160;', end($buffer[10][$x])['char']);
            $this->assertEquals('&#160;', end($buffer[11][$x])['char']);
        }
    }

    public function testDeleteLines(): void
    {
        $this->process("line1\r\nline2\r\nline3");
        $this->state->cursorY = 1;
        $this->process("\x1b[1M");
        $buffer = $this->state->mainBuffer;
        $this->assertEquals('l', end($buffer[1][0])['char']);
        $this->assertEquals('i', end($buffer[1][1])['char']);
        $this->assertEquals('n', end($buffer[1][2])['char']);
        $this->assertEquals('e', end($buffer[1][3])['char']);
        $this->assertEquals('3', end($buffer[1][4])['char']);
    }

    public function testScrollUp(): void
    {
        $this->process("line1\r\nline2");
        $this->process("\x1b[1S");
        $buffer = $this->state->mainBuffer;
        $this->assertEquals('l', end($buffer[0][0])['char']);
        $this->assertEquals('2', end($buffer[0][4])['char']);
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
        $buffer = $this->state->mainBuffer;

        // Check that the header is untouched
        $this->assertEquals('H', end($buffer[0][0])['char']);

        // Check that the footer is untouched
        $this->assertEquals('F', end($buffer[9][0])['char']);

        // Check that "Line 3" is gone (scrolled out of view)
        // The old cell's lifespan should have ended. The original character is at index 0 of the history.
        $this->assertArrayHasKey('endTime', $buffer[2][0][0]);
        $this->assertNotNull($buffer[2][0][0]['endTime']);

        // Check that "Line 4" has moved up to row 2. The character '4' is at index 5.
        $this->assertEquals('4', end($buffer[2][5])['char']);
        $this->assertEquals($this->time, end($buffer[2][5])['startTime']);

        // Check that "Line 7" has moved up to row 5. The character '7' is at index 5.
        $this->assertEquals('7', end($buffer[5][5])['char']);
        $this->assertEquals($this->time, end($buffer[5][5])['startTime']);

        // Check that the last line of the scroll region is now blank where text used to be
        for ($x = 0; $x < 6; $x++) {
            $this->assertEquals('&#160;', end($buffer[6][$x])['char']);
        }
    }


    public function testScrollDown(): void
    {
        $this->process("line1\nline2");
        $this->process("\x1b[1T");
        $buffer = $this->state->mainBuffer;
        $this->assertEquals('l', end($buffer[1][0])['char']);
        $this->assertEquals('1', end($buffer[1][4])['char']);
    }

    public function testAlternateScreenBuffer(): void
    {
        $this->process("\x1b[?1049h");
        $this->assertTrue($this->state->altScreenActive);
    }

    public function testMainScreenBuffer(): void
    {
        $this->process("\x1b[?1049h");
        $this->assertTrue($this->state->altScreenActive);
        $this->process("\x1b[?1049l");
        $this->assertFalse($this->state->altScreenActive);
    }

    public function testCursorVisibility(): void
    {
        $this->process("\x1b[?25l");
        $this->assertFalse($this->state->cursorVisible);
        $this->process("\x1b[?25h");
        $this->assertTrue($this->state->cursorVisible);
    }

    public function testCarriageReturn(): void
    {
        $this->state->cursorX = 20;
        $this->process("\r");
        $this->assertEquals(0, $this->state->cursorX);
    }

    public function testNewline(): void
    {
        $this->state->cursorY = 5;
        $this->process("\n");
        $this->assertEquals(6, $this->state->cursorY);
    }

    public function testBackspace(): void
    {
        $this->state->cursorX = 10;
        $this->process("\x08");
        $this->assertEquals(9, $this->state->cursorX);
    }



    public function testTab(): void
    {
        $this->state->cursorX = 3;
        $this->process("\t");
        $this->assertEquals(8, $this->state->cursorX);
        $this->process("\t");
        $this->assertEquals(16, $this->state->cursorX);
    }

    public function testSetGraphicsModeInverse(): void
    {
        $this->process("\x1b[7m");
        $this->assertTrue($this->state->currentStyle['inverse']);
        $this->process("\x1b[27m");
        $this->assertFalse($this->state->currentStyle['inverse']);
    }

    public function testSetGraphicsModeBold(): void
    {
        $this->process("\x1b[1m");
        $this->assertTrue($this->state->currentStyle['bold']);
        $this->process("\x1b[22m");
        $this->assertFalse($this->state->currentStyle['bold']);
    }

    public function testSetGraphicsModeColor(): void
    {
        $this->process("\x1b[31m");
        $this->assertEquals('fg-31', $this->state->currentStyle['fg']);
        $this->process("\x1b[42m");
        $this->assertEquals('bg-42', $this->state->currentStyle['bg']);
    }

    public function testSetGraphicsModeReset(): void
    {
        $this->process("\x1b[1;31;42m");
        $this->process("\x1b[0m");
        $this->assertFalse($this->state->currentStyle['bold']);
        $this->assertEquals('fg-default', $this->state->currentStyle['fg']);
        $this->assertEquals('bg-default', $this->state->currentStyle['bg']);
    }
}
