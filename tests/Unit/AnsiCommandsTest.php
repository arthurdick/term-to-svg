<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TerminalToSvgConverter;

class AnsiCommandsTest extends TestCase
{
    private $converter;
    private $reflection;

    protected function setUp(): void
    {
        // Mock file paths since we are not reading from actual files for unit tests
        $typescriptFile = 'php://memory';
        $timingFile = 'php://memory';

        $config = [
            'rows' => 24,
            'cols' => 80,
            'font_size' => 14,
            'line_height_factor' => 1.2,
            'font_family' => 'monospace',
            'default_fg' => '#e0e0e0',
            'default_bg' => '#1a1a1a',
        ];

        $this->converter = new TerminalToSvgConverter($typescriptFile, $timingFile, $config);
        $this->reflection = new ReflectionClass($this->converter);
    }

    /**
     * Helper to call private methods on the converter instance.
     */
    private function invokeMethod($methodName, array $parameters = [])
    {
        $method = $this->reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->converter, $parameters);
    }

    /**
     * Helper to get private property values.
     */
    private function getProperty($propertyName)
    {
        $property = $this->reflection->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($this->converter);
    }

    /**
     * Helper to set private property values.
     */
    private function setProperty($propertyName, $value)
    {
        $property = $this->reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($this->converter, $value);
    }

    /**
     * Test CSI J (Erase in Display) with parameter 2 (clear entire screen).
     */
    public function testEraseInDisplayClearsEntireScreen(): void
    {
        // Write some text to the screen first
        $this->invokeMethod('processChunk', ["Hello\nWorld"]);

        // Now, send the command to clear the screen (ESC[2J)
        $this->invokeMethod('handleAnsiCommand', ['J', [2]]);

        // Get the active screen buffer
        $buffer = $this->getProperty('mainBuffer');

        // Assert that the screen buffer is now full of blank characters
        for ($y = 0; $y < 24; $y++) {
            for ($x = 0; $x < 80; $x++) {
                $this->assertArrayHasKey($y, $buffer);
                $this->assertArrayHasKey($x, $buffer[$y]);
                $lastCellState = end($buffer[$y][$x]);
                // Check if the character is a non-breaking space, which represents a cleared cell
                $this->assertEquals('&#160;', $lastCellState['char']);
            }
        }
    }

    /**
     * Test cursor movement command CSI C (Cursor Forward).
     */
    public function testCursorForwardMovesCursor(): void
    {
        // Initial cursor X should be 0
        $this->assertEquals(0, $this->getProperty('cursorX'));

        // Move cursor forward 5 columns (ESC[5C)
        $this->invokeMethod('handleAnsiCommand', ['C', [5]]);

        $this->assertEquals(5, $this->getProperty('cursorX'));
    }

    /**
     * Test cursor movement command CSI A (Cursor Up).
     */
    public function testCursorUpMovesCursor(): void
    {
        // Move cursor down first to have a baseline
        $this->invokeMethod('processChunk', ["\n\n\n"]);
        $this->assertEquals(3, $this->getProperty('cursorY'));

        // Move cursor up 2 lines (ESC[2A)
        $this->invokeMethod('handleAnsiCommand', ['A', [2]]);

        $this->assertEquals(1, $this->getProperty('cursorY'));
    }

    public function testCursorDownMovesCursor(): void
    {
        $this->setProperty('cursorY', 5);
        $this->invokeMethod('handleAnsiCommand', ['B', [3]]);
        $this->assertEquals(8, $this->getProperty('cursorY'));
    }

    public function testCursorBackwardMovesCursor(): void
    {
        $this->setProperty('cursorX', 10);
        $this->invokeMethod('handleAnsiCommand', ['D', [4]]);
        $this->assertEquals(6, $this->getProperty('cursorX'));
    }

    public function testCursorPosition(): void
    {
        $this->invokeMethod('handleAnsiCommand', ['H', [10, 20]]);
        $this->assertEquals(9, $this->getProperty('cursorY'));
        $this->assertEquals(19, $this->getProperty('cursorX'));
    }

    public function testCursorCharacterAbsolute(): void
    {
        $this->invokeMethod('handleAnsiCommand', ['G', [15]]);
        $this->assertEquals(14, $this->getProperty('cursorX'));
    }

    public function testVerticalLinePositionAbsolute(): void
    {
        $this->invokeMethod('handleAnsiCommand', ['d', [12]]);
        $this->assertEquals(11, $this->getProperty('cursorY'));
    }

    public function testIndexCommand(): void
    {
        $this->setProperty('cursorY', 10);
        $this->invokeMethod('processChunk', ["\x1bD"]);
        $this->assertEquals(11, $this->getProperty('cursorY'));
    }

    public function testReverseIndexCommand(): void
    {
        $this->setProperty('cursorY', 10);
        $this->invokeMethod('processChunk', ["\x1bM"]);
        $this->assertEquals(9, $this->getProperty('cursorY'));
    }

    public function testNextLineCommand(): void
    {
        $this->setProperty('cursorX', 15);
        $this->setProperty('cursorY', 10);
        $this->invokeMethod('processChunk', ["\x1bE"]);
        $this->assertEquals(0, $this->getProperty('cursorX'));
        $this->assertEquals(11, $this->getProperty('cursorY'));
    }

    // -- Erasing Text --

    public function testEraseInLineFromCursorToEnd(): void
    {
        $this->invokeMethod('processChunk', ["some text"]);
        $this->setProperty('cursorX', 5);
        $this->invokeMethod('handleAnsiCommand', ['K', [0]]);
        $buffer = $this->getProperty('mainBuffer');
        // "text" should be cleared
        for ($x = 5; $x < 9; $x++) {
            $lastCellState = end($buffer[0][$x]);
            $this->assertEquals('&#160;', $lastCellState['char']);
        }
    }

    public function testDeleteCharacters(): void
    {
        $this->invokeMethod('processChunk', ["abcdef"]);
        $this->setProperty('cursorX', 2); // cursor on 'c'
        $this->invokeMethod('handleAnsiCommand', ['P', [2]]); // delete 2 chars
        $buffer = $this->getProperty('mainBuffer');
        $this->assertEquals('e', end($buffer[0][2])['char']);
        $this->assertEquals('f', end($buffer[0][3])['char']);
    }

    public function testInsertCharacters(): void
    {
        $this->invokeMethod('processChunk', ["abcdef"]);
        $this->setProperty('cursorX', 2); // cursor on 'c'
        $this->invokeMethod('handleAnsiCommand', ['@', [2]]); // insert 2 chars
        $buffer = $this->getProperty('mainBuffer');
        // Blanks at 2 and 3
        $this->assertEquals('&#160;', end($buffer[0][2])['char']);
        $this->assertEquals('&#160;', end($buffer[0][3])['char']);
        // 'c' and 'd' shifted
        $this->assertEquals('c', end($buffer[0][4])['char']);
        $this->assertEquals('d', end($buffer[0][5])['char']);
    }

    // -- Screen and Scrolling --

    public function testSetScrollRegion(): void
    {
        $this->invokeMethod('handleAnsiCommand', ['r', [5, 15]]);
        $this->assertEquals(4, $this->getProperty('scrollTop'));
        $this->assertEquals(14, $this->getProperty('scrollBottom'));
    }

    public function testInsertLines(): void
    {
        $this->setProperty('cursorY', 10);
        $this->invokeMethod('handleAnsiCommand', ['L', [2]]);
        $buffer = $this->getProperty('mainBuffer');
        // Check that lines 10 and 11 are blank
        for ($x = 0; $x < 80; $x++) {
            $this->assertEquals('&#160;', end($buffer[10][$x])['char']);
            $this->assertEquals('&#160;', end($buffer[11][$x])['char']);
        }
    }

    public function testDeleteLines(): void
    {
        $this->invokeMethod('processChunk', ["line1\r\nline2\r\nline3"]);
        $this->setProperty('cursorY', 1);
        $this->invokeMethod('handleAnsiCommand', ['M', [1]]);
        $buffer = $this->getProperty('mainBuffer');
        // "line3" should now be on line 1
        $this->assertEquals('l', end($buffer[1][0])['char']);
        $this->assertEquals('i', end($buffer[1][1])['char']);
        $this->assertEquals('n', end($buffer[1][2])['char']);
        $this->assertEquals('e', end($buffer[1][3])['char']);
        $this->assertEquals('3', end($buffer[1][4])['char']);
    }

    public function testScrollUp(): void
    {
        $this->invokeMethod('processChunk', ["line1\r\nline2"]);
        $this->invokeMethod('handleAnsiCommand', ['S', [1]]);
        $buffer = $this->getProperty('mainBuffer');
        // "line2" should be on line 0
        $this->assertEquals('l', end($buffer[0][0])['char']);
        $this->assertEquals('2', end($buffer[0][4])['char']);
    }

    public function testScrollDown(): void
    {
        $this->invokeMethod('processChunk', ["line1\nline2"]);
        $this->invokeMethod('handleAnsiCommand', ['T', [1]]);
        $buffer = $this->getProperty('mainBuffer');
        // "line1" should be on line 1
        $this->assertEquals('l', end($buffer[1][0])['char']);
        $this->assertEquals('1', end($buffer[1][4])['char']);
    }

    public function testAlternateScreenBuffer(): void
    {
        $this->invokeMethod('handleDecPrivateMode', ['1049h']);
        $this->assertTrue($this->getProperty('altScreenActive'));
    }

    public function testMainScreenBuffer(): void
    {
        // First switch to alt screen
        $this->invokeMethod('handleDecPrivateMode', ['1049h']);
        $this->assertTrue($this->getProperty('altScreenActive'));
        // Now switch back
        $this->invokeMethod('handleDecPrivateMode', ['1049l']);
        $this->assertFalse($this->getProperty('altScreenActive'));
    }

    // -- Cursor Visibility --

    public function testCursorVisibility(): void
    {
        $this->invokeMethod('handleDecPrivateMode', ['25l']);
        $this->assertFalse($this->getProperty('cursorVisible'));
        $this->invokeMethod('handleDecPrivateMode', ['25h']);
        $this->assertTrue($this->getProperty('cursorVisible'));
    }

    // -- Character Handling --

    public function testCarriageReturn(): void
    {
        $this->setProperty('cursorX', 20);
        $this->invokeMethod('handleCharacter', ["\r"]);
        $this->assertEquals(0, $this->getProperty('cursorX'));
    }

    public function testNewline(): void
    {
        $this->setProperty('cursorY', 5);
        $this->invokeMethod('handleCharacter', ["\n"]);
        $this->assertEquals(6, $this->getProperty('cursorY'));
    }

    public function testBackspace(): void
    {
        $this->setProperty('cursorX', 10);
        $this->invokeMethod('handleCharacter', ["\x08"]);
        $this->assertEquals(9, $this->getProperty('cursorX'));
    }

    public function testTab(): void
    {
        $this->setProperty('cursorX', 3);
        $this->invokeMethod('handleCharacter', ["\t"]);
        $this->assertEquals(8, $this->getProperty('cursorX'));
        $this->invokeMethod('handleCharacter', ["\t"]);
        $this->assertEquals(16, $this->getProperty('cursorX'));
    }

    /**
     * Test SGR 7 (inverse video).
     */
    public function testSetGraphicsModeInverse(): void
    {
        // SGR 7 should enable inverse mode
        $this->invokeMethod('setGraphicsMode', [[7]]);
        $style = $this->getProperty('currentStyle');
        $this->assertTrue($style['inverse']);

        // SGR 27 should disable inverse mode
        $this->invokeMethod('setGraphicsMode', [[27]]);
        $style = $this->getProperty('currentStyle');
        $this->assertFalse($style['inverse']);
    }

    public function testSetGraphicsModeBold(): void
    {
        $this->invokeMethod('setGraphicsMode', [[1]]);
        $style = $this->getProperty('currentStyle');
        $this->assertTrue($style['bold']);
        $this->invokeMethod('setGraphicsMode', [[22]]);
        $style = $this->getProperty('currentStyle');
        $this->assertFalse($style['bold']);
    }

    public function testSetGraphicsModeColor(): void
    {
        // Red foreground
        $this->invokeMethod('setGraphicsMode', [[31]]);
        $style = $this->getProperty('currentStyle');
        $this->assertEquals('fg-31', $style['fg']);

        // Green background
        $this->invokeMethod('setGraphicsMode', [[42]]);
        $style = $this->getProperty('currentStyle');
        $this->assertEquals('bg-42', $style['bg']);
    }

    public function testSetGraphicsModeReset(): void
    {
        $this->invokeMethod('setGraphicsMode', [[1, 31, 42]]);
        $this->invokeMethod('setGraphicsMode', [[0]]);
        $style = $this->getProperty('currentStyle');
        $this->assertFalse($style['bold']);
        $this->assertEquals('fg-default', $style['fg']);
        $this->assertEquals('bg-default', $style['bg']);
    }
}
