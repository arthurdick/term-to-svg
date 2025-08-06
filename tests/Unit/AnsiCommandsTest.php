<?php

declare(strict_types=1);

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
     * Finds the active cell at a given coordinate in the main buffer.
     * An active cell is one whose startTime is before or at the current time,
     * and whose endTime is not set or is after the current time.
     */
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


    /**
     * Test CSI J (Erase in Display) with parameter 2 (clear entire screen).
     */
    public function testEraseInDisplayClearsEntireScreen(): void
    {
        $this->process("Hello\nWorld");
        $this->process("\x1b[2J"); // ESC[2J

        // 'H' at (0,0) and 'W' at (1,0) should now be inactive.
        $this->assertNull($this->findActiveCellAt(0, 0));
        $this->assertNull($this->findActiveCellAt(1, 0));
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
        $this->state->cursorX = 5; // On the 't' of 'text'
        $this->process("\x1b[0K");

        // "some " should be untouched
        $this->assertEquals('s', $this->findActiveCellAt(0, 0)['char']);
        $this->assertEquals(' ', $this->findActiveCellAt(0, 4)['char']);

        // "text" should be erased
        for ($x = 5; $x < 9; $x++) {
            $this->assertNull($this->findActiveCellAt(0, $x));
        }
    }

    public function testDeleteCharacters(): void
    {
        $this->process("abcdef");
        $this->state->cursorX = 2; // cursor on 'c'
        $this->process("\x1b[2P"); // delete 2 chars ('c', 'd')

        $this->assertEquals('a', $this->findActiveCellAt(0, 0)['char']);
        $this->assertEquals('b', $this->findActiveCellAt(0, 1)['char']);
        $this->assertEquals('e', $this->findActiveCellAt(0, 2)['char']);
        $this->assertEquals('f', $this->findActiveCellAt(0, 3)['char']);
        $this->assertNull($this->findActiveCellAt(0, 4));
        $this->assertNull($this->findActiveCellAt(0, 5));
    }

    public function testInsertCharacters(): void
    {
        $this->process("abcdef");
        $this->state->cursorX = 2; // cursor on 'c'
        $this->process("\x1b[2@"); // insert 2 chars

        $this->assertEquals('a', $this->findActiveCellAt(0, 0)['char']);
        $this->assertEquals('b', $this->findActiveCellAt(0, 1)['char']);
        $this->assertNull($this->findActiveCellAt(0, 2));
        $this->assertNull($this->findActiveCellAt(0, 3));
        $this->assertEquals('c', $this->findActiveCellAt(0, 4)['char']);
        $this->assertEquals('d', $this->findActiveCellAt(0, 5)['char']);
    }


    public function testSetScrollRegion(): void
    {
        $this->process("\x1b[5;15r");
        $this->assertEquals(4, $this->state->scrollTop);
        $this->assertEquals(14, $this->state->scrollBottom);
    }

    public function testInsertLines(): void
    {
        $this->process("line1\nline2\nline3");
        $this->state->cursorY = 1; // On line2
        $this->process("\x1b[2L"); // Insert 2 lines

        $this->assertEquals('l', $this->findActiveCellAt(0, 0)['char']); // line1 is untouched
        $this->assertNull($this->findActiveCellAt(1, 0)); // New line
        $this->assertNull($this->findActiveCellAt(2, 0)); // New line
        $this->assertEquals('l', $this->findActiveCellAt(3, 0)['char']); // line2 is shifted down
    }

    public function testDeleteLines(): void
    {
        $this->process("line1\r\nline2\r\nline3");
        $this->state->cursorY = 1;
        $this->process("\x1b[1M"); // Delete line at cursor (line2)

        // Assert that line 1 is untouched.
        $this->assertEquals('l', $this->findActiveCellAt(0, 0)['char']);
        // Assert that line 3 has moved up to line 2's original position.
        $this->assertEquals('l', $this->findActiveCellAt(1, 0)['char']);
        $this->assertEquals('3', $this->findActiveCellAt(1, 4)['char']);
        // Assert that the line where line 3 used to be is now empty.
        $this->assertNull($this->findActiveCellAt(2, 0));
    }


    public function testScrollUp(): void
    {
        $this->process("line1\r\nline2");
        $this->process("\x1b[1S");

        $this->assertEquals('l', $this->findActiveCellAt(0, 0)['char']);
        $this->assertEquals('2', $this->findActiveCellAt(0, 4)['char']);
        $this->assertNull($this->findActiveCellAt(1, 0)); // Line 2 should be empty
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


    public function testScrollDown(): void
    {
        $this->process("line1\nline2");
        $this->process("\x1b[1T");

        $this->assertNull($this->findActiveCellAt(0, 0));
        $this->assertEquals('l', $this->findActiveCellAt(1, 0)['char']);
        $this->assertEquals('1', $this->findActiveCellAt(1, 4)['char']);
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

    public function testSetGraphicsMode24BitColor(): void
    {
        $this->process("\x1b[38;2;10;20;30m");
        $this->assertEquals('#0a141e', $this->state->currentStyle['fg_hex']);
        $this->process("\x1b[48;2;40;50;60m");
        $this->assertEquals('#28323c', $this->state->currentStyle['bg_hex']);
    }

    public function testSetGraphicsModeReset(): void
    {
        $this->process("\x1b[1;31;42m");
        $this->process("\x1b[0m");
        $this->assertFalse($this->state->currentStyle['bold']);
        $this->assertEquals('fg-default', $this->state->currentStyle['fg']);
        $this->assertEquals('bg-default', $this->state->currentStyle['bg']);
    }

    /**
     * Test CSI X (Erase Character).
     */
    public function testEraseCharacters(): void
    {
        $this->process("abcdefgh");
        $this->state->cursorX = 2; // Cursor on 'c'
        $this->process("\x1b[3X"); // Erase 3 characters (c, d, e)

        // 'a' and 'b' should be unchanged
        $this->assertEquals('a', $this->findActiveCellAt(0, 0)['char']);
        $this->assertEquals('b', $this->findActiveCellAt(0, 1)['char']);

        // 'c', 'd', 'e' should be erased
        $this->assertNull($this->findActiveCellAt(0, 2));
        $this->assertNull($this->findActiveCellAt(0, 3));
        $this->assertNull($this->findActiveCellAt(0, 4));

        // 'f', 'g', 'h' should be unchanged
        $this->assertEquals('f', $this->findActiveCellAt(0, 5)['char']);
        $this->assertEquals('g', $this->findActiveCellAt(0, 6)['char']);
        $this->assertEquals('h', $this->findActiveCellAt(0, 7)['char']);
    }

    public function testSaveCursorPosition(): void
    {
        $this->state->cursorX = 42;
        $this->state->cursorY = 21;
        $this->process("\x1b[s"); // Save cursor
        $this->assertEquals(42, $this->state->savedCursorX);
        $this->assertEquals(21, $this->state->savedCursorY);
    }

    public function testRestoreCursorPosition(): void
    {
        $this->state->cursorX = 10;
        $this->state->cursorY = 5;
        $this->process("\x1b[s"); // Save cursor
        $this->process("\x1b[20;1H"); // Move cursor
        $this->process("\x1b[u"); // Restore cursor
        $this->assertEquals(10, $this->state->cursorX);
        $this->assertEquals(5, $this->state->cursorY);
    }
}
