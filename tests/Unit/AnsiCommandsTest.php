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
}
