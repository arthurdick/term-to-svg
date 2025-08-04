<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArthurDick\TermToSvg\Config;
use ArthurDick\TermToSvg\SvgGenerator;
use ArthurDick\TermToSvg\TerminalState;
use PHPUnit\Framework\TestCase;

class SvgGeneratorTest extends TestCase
{
    private TerminalState $state;
    private array $config;
    private float $charWidth;
    private float $charHeight;

    protected function setUp(): void
    {
        $this->config = Config::DEFAULTS;
        $this->state = new TerminalState($this->config);
        $this->charWidth = $this->config['font_size'] * 0.6;
        $this->charHeight = $this->config['font_size'] * $this->config['line_height_factor'];
    }

    private function createGenerator(float $totalDuration = 1.0): SvgGenerator
    {
        return new SvgGenerator($this->state, $this->config, $totalDuration);
    }

    public function testBasicSvgStructure(): void
    {
        $generator = $this->createGenerator();
        $svg = $generator->generate();

        $expectedWidth = (int)($this->config['cols'] * $this->charWidth);
        $expectedHeight = (int)(($this->config['rows'] * $this->charHeight) + ($this->config['font_size'] * 0.2));

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString(sprintf('width="%d"', $expectedWidth), $svg);
        $this->assertStringContainsString(sprintf('height="%d"', $expectedHeight), $svg);
        $this->assertStringContainsString('</svg>', $svg);
        $this->assertStringContainsString('<g id="main-screen"', $svg);
        $this->assertStringContainsString('<g id="alt-screen"', $svg);
        $this->assertStringContainsString('<rect id="cursor"', $svg);
    }

    public function testTextElementGeneration(): void
    {
        $this->state->mainBuffer[0][0][] = [
            'char' => 'H',
            'style' => $this->state->currentStyle,
            'startTime' => 0.1,
        ];
        $this->state->mainBuffer[0][1][] = [
            'char' => 'i',
            'style' => $this->state->currentStyle,
            'startTime' => 0.1,
        ];

        $generator = $this->createGenerator();
        $svg = $generator->generate();

        $expectedY = ($this->charHeight) - ($this->charHeight - $this->config['font_size']) / 2;
        $expectedRegex = sprintf(
            '/<text class="c1" x="0.00" y="%.2F">Hi<set attributeName="visibility" to="visible" begin="loop.begin\+0.1000s" \/><set attributeName="visibility" to="hidden" begin="loop.begin" \/><\/text>/',
            $expectedY
        );

        $this->assertMatchesRegularExpression($expectedRegex, $svg);
        $this->assertStringContainsString(".c1 { fill:#e0e0e0; }", $svg);
    }

    public function testRectElementForBackgroundGeneration(): void
    {
        $styleWithBg = $this->state->currentStyle;
        $styleWithBg['bg'] = 'bg-41'; // Red background

        $this->state->mainBuffer[1][2][] = [
            'char' => 'X',
            'style' => $styleWithBg,
            'startTime' => 0.2,
        ];

        $generator = $this->createGenerator();
        $svg = $generator->generate();

        $expectedX = 2 * $this->charWidth;
        $expectedY = 1 * $this->charHeight;
        $expectedRegex = sprintf(
            '/<rect class="c1" x="%.2F" y="%.2F" width="%.2F" height="%.2F"><set attributeName="visibility" to="visible" begin="loop.begin\+0.2000s" \/><set attributeName="visibility" to="hidden" begin="loop.begin" \/><\/rect>/',
            $expectedX,
            $expectedY,
            $this->charWidth,
            $this->charHeight
        );

        $this->assertMatchesRegularExpression($expectedRegex, $svg);
        $this->assertStringContainsString(".c1 { fill:#cc0000; }", $svg);
    }

    public function testCursorAnimationGeneration(): void
    {
        $this->state->cursorEvents = [
            ['time' => 0.0, 'x' => 0, 'y' => 0],
            ['time' => 0.5, 'x' => 5, 'y' => 2],
            ['time' => 0.8, 'visible' => false],
        ];

        $generator = $this->createGenerator();
        $svg = $generator->generate();

        $expectedX = 5 * $this->charWidth;
        $expectedY = 2 * $this->charHeight;

        $this->assertStringContainsString(sprintf('<set attributeName="x" to="%.2F" begin="loop.begin+0.5000s" />', $expectedX), $svg);
        $this->assertStringContainsString(sprintf('<set attributeName="y" to="%.2F" begin="loop.begin+0.5000s" />', $expectedY), $svg);
        $this->assertStringContainsString('<set attributeName="visibility" to="hidden" begin="loop.begin+0.8000s" />', $svg);

        // Test reset animations - The initial visibility should be based on the first visibility event in the log
        $this->assertStringContainsString('<set attributeName="x" to="0.00" begin="loop.begin"/>', $svg);
        $this->assertStringContainsString('<set attributeName="y" to="0.00" begin="loop.begin"/>', $svg);
        $this->assertStringContainsString('<set attributeName="visibility" to="hidden" begin="loop.begin"/>', $svg);
    }

    public function testScreenSwitchAnimation(): void
    {
        $this->state->screenSwitchEvents = [
            ['time' => 0.3, 'type' => 'to_alt'],
            ['time' => 0.9, 'type' => 'to_main'],
        ];

        $generator = $this->createGenerator();
        $svg = $generator->generate();

        $this->assertStringContainsString('<g id="main-screen" display="inline">', $svg);
        $this->assertStringContainsString('<set attributeName="display" to="none" begin="loop.begin+0.3000s" />', $svg);
        $this->assertStringContainsString('<set attributeName="display" to="inline" begin="loop.begin+0.9000s" />', $svg);

        $this->assertStringContainsString('<g id="alt-screen" display="none">', $svg);
        $this->assertStringContainsString('<set attributeName="display" to="inline" begin="loop.begin+0.3000s" />', $svg);
        $this->assertStringContainsString('<set attributeName="display" to="none" begin="loop.begin+0.9000s" />', $svg);
    }

    public function testCssClassGeneration(): void
    {
        $this->state->mainBuffer[0][0][] = ['char' => 'A', 'style' => $this->state->currentStyle, 'startTime' => 0.1];

        $boldStyle = $this->state->currentStyle;
        $boldStyle['bold'] = true;
        $this->state->mainBuffer[0][1][] = ['char' => 'B', 'style' => $boldStyle, 'startTime' => 0.2];

        $redStyle = $this->state->currentStyle;
        $redStyle['fg'] = 'fg-31';
        $this->state->mainBuffer[0][2][] = ['char' => 'C', 'style' => $redStyle, 'startTime' => 0.3];

        $generator = $this->createGenerator();
        $svg = $generator->generate();

        $this->assertStringContainsString(".c1 { fill:#e0e0e0; }", $svg);
        $this->assertStringContainsString('<text class="c1"', $svg);

        $this->assertStringContainsString(".c2 { fill:#e0e0e0;font-weight:bold; }", $svg);
        $this->assertStringContainsString('<text class="c2"', $svg);

        $this->assertStringContainsString(".c3 { fill:#cc0000; }", $svg);
        $this->assertStringContainsString('<text class="c3"', $svg);
    }
}
