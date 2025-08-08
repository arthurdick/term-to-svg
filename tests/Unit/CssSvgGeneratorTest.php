<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArthurDick\TermToSvg\Config;
use ArthurDick\TermToSvg\CssSvgGenerator;
use ArthurDick\TermToSvg\TerminalState;
use PHPUnit\Framework\TestCase;

class CssSvgGeneratorTest extends TestCase
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

    private function createGenerator(float $totalDuration = 1.0): CssSvgGenerator
    {
        return new CssSvgGenerator($this->state, $this->config, $totalDuration);
    }

    public function testBasicSvgStructure(): void
    {
        $generator = $this->createGenerator();
        $svg = $generator->generate();

        $expectedWidth = (int)($this->config['cols'] * $this->charWidth);
        $expectedHeight = (int)(($this->config['rows'] * $this->charHeight) + ($this->config['font_size'] * 0.2));

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString(sprintf('viewBox="0 0 %d %d"', $expectedWidth, $expectedHeight), $svg);
        $this->assertStringContainsString('</svg>', $svg);
        $this->assertMatchesRegularExpression('/<g id="svg[a-f0-9]+_main-screen"/', $svg);
        $this->assertMatchesRegularExpression('/<g id="svg[a-f0-9]+_alt-screen"/', $svg);
        $this->assertMatchesRegularExpression('/<rect id="svg[a-f0-9]+_cursor"/', $svg);
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

        $expectedY = ($this->charHeight) - ($this->charHeight - $this->config['font_size']);
        $expectedRegex = sprintf(
            '/<text class="c1_svg[a-f0-9]+ v1_svg[a-f0-9]+ " x="0.00" y="%.2F">Hi<\/text>/',
            $expectedY
        );

        $this->assertMatchesRegularExpression($expectedRegex, $svg);
        $this->assertMatchesRegularExpression('/\.c1_svg[a-f0-9]+ \{ fill:#e0e0e0; \}/', $svg);
    }

    public function testBlinkingTextElementGeneration(): void
    {
        $styleWithBlink = $this->state->currentStyle;
        $styleWithBlink['blink'] = true;

        $this->state->mainBuffer[0][0][] = [
            'char' => 'B',
            'style' => $styleWithBlink,
            'startTime' => 0.1,
        ];

        $generator = $this->createGenerator();
        $svg = $generator->generate();

        $this->assertMatchesRegularExpression('/\.b1_svg[a-f0-9]+ {\s+animation-name: svg[a-f0-9]+-blink-anim;\s+animation-duration: 1s;\s+animation-iteration-count: infinite;\s+animation-timing-function: steps\(1, end\);\s+animation-delay: 0.1s;\s+}/', $svg);
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
            '/<rect class="c1_svg[a-f0-9]+ v1_svg[a-f0-9]+" x="%.2F" y="%.2F" width="%.2F" height="%.2F"><\/rect>/',
            $expectedX,
            $expectedY,
            $this->charWidth,
            $this->charHeight
        );

        $this->assertMatchesRegularExpression($expectedRegex, $svg);
        $this->assertMatchesRegularExpression('/\.c1_svg[a-f0-9]+ \{ fill:#cc0000; \}/', $svg);
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

        $this->assertMatchesRegularExpression('/@keyframes svg[a-f0-9]+-cursor-pos/', $svg);
        $this->assertMatchesRegularExpression('/@keyframes svg[a-f0-9]+-cursor-vis/', $svg);
        $this->assertMatchesRegularExpression('/#svg[a-f0-9]+_cursor { animation: [0-9.]+s steps\(1, end\) infinite svg[a-f0-9]+-cursor-pos, [0-9.]+s steps\(1, end\) infinite svg[a-f0-9]+-cursor-vis; }/', $svg);
    }

    public function testScreenSwitchAnimation(): void
    {
        $this->state->screenSwitchEvents = [
            ['time' => 0.3, 'type' => 'to_alt'],
            ['time' => 0.9, 'type' => 'to_main'],
        ];

        $generator = $this->createGenerator();
        $svg = $generator->generate();

        $this->assertMatchesRegularExpression('/#svg[a-f0-9]+_main-screen { animation: [0-9.]+s steps\(1, end\) infinite svg[a-f0-9]+-main-screen; opacity: 1; pointer-events: auto; }/', $svg);
        $this->assertMatchesRegularExpression('/#svg[a-f0-9]+_alt-screen { animation: [0-9.]+s steps\(1, end\) infinite svg[a-f0-9]+-alt-screen; opacity: 0; pointer-events: none; }/', $svg);
        $this->assertMatchesRegularExpression('/@keyframes svg[a-f0-9]+-main-screen { 0% { opacity: 1; pointer-events: auto; } [0-9.]+% { opacity: 0; pointer-events: none; } [0-9.]+% { opacity: 1; pointer-events: auto; } }/', $svg);
        $this->assertMatchesRegularExpression('/@keyframes svg[a-f0-9]+-alt-screen { 0% { opacity: 0; pointer-events: none; } [0-9.]+% { opacity: 1; pointer-events: auto; } [0-9.]+% { opacity: 0; pointer-events: none; } }/', $svg);
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

        $this->assertMatchesRegularExpression('/\.c1_svg[a-f0-9]+ \{ fill:#e0e0e0; \}/', $svg);
        $this->assertMatchesRegularExpression('/<text class="c1_svg[a-f0-9]+ v1_svg[a-f0-9]+ " x="0.00" y="14.00">A<\/text>/', $svg);

        $this->assertMatchesRegularExpression('/\.c2_svg[a-f0-9]+ \{ fill:#e0e0e0;font-weight:bold; \}/', $svg);
        $this->assertMatchesRegularExpression('/<text class="c2_svg[a-f0-9]+ v2_svg[a-f0-9]+ " x="8.40" y="14.00">B<\/text>/', $svg);

        $this->assertMatchesRegularExpression('/\.c3_svg[a-f0-9]+ \{ fill:#cc0000; \}/', $svg);
        $this->assertMatchesRegularExpression('/<text class="c3_svg[a-f0-9]+ v3_svg[a-f0-9]+ " x="16.80" y="14.00">C<\/text>/', $svg);
    }
}
