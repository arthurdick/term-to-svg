<?php

namespace ArthurDick\TermToSvg;

/**
 * Generates an animated SVG from a processed TerminalState object.
 *
 * This class takes the final state of the terminal buffers, styles, and animation
 * events, and renders them into a self-contained, animated SVG file using SMIL.
 */
class SvgGenerator
{
    private TerminalState $state;
    private array $config;
    private array $cssRules = [];
    private int $classCounter = 0;
    private float $totalDuration;

    /**
     * @param TerminalState $state The fully processed terminal state.
     * @param array<string, mixed> $config The rendering configuration array.
     * @param float $totalDuration The total duration of the terminal session recording.
     */
    public function __construct(TerminalState $state, array $config, float $totalDuration)
    {
        $this->state = $state;
        $this->config = $config;
        $this->totalDuration = $totalDuration;
    }

    /**
     * Renders the complete SVG string.
     *
     * @return string The generated SVG content.
     */
    public function generate(): string
    {
        $charHeight = $this->config['font_size'] * $this->config['line_height_factor'];
        $charWidth = $this->config['font_size'] * $this->config['font_width_factor'];
        $svgWidth = $charWidth * $this->config['cols'];
        $svgHeight = ($charHeight * $this->config['rows']) + ($this->config['font_size'] * 0.2);

        list($mainText, $mainRects, $mainScroll) = $this->renderBuffer($this->state->mainBuffer, $this->state->mainScrollEvents, $charHeight, $charWidth);
        list($altText, $altRects, $altScroll) = $this->renderBuffer($this->state->altBuffer, $this->state->altScrollEvents, $charHeight, $charWidth);

        $cursorAnims = $this->generateCursorAnimations($charWidth, $charHeight);
        $mainAnims = '';
        $altAnims = '';

        foreach ($this->state->screenSwitchEvents as $event) {
            if ($event['type'] === 'to_alt') {
                $mainAnims .= sprintf('        <set attributeName="display" to="none" begin="loop.begin+%.4fs" />' . "\n", $event['time']);
                $altAnims .= sprintf('        <set attributeName="display" to="inline" begin="loop.begin+%.4fs" />' . "\n", $event['time']);
            } else {
                $mainAnims .= sprintf('        <set attributeName="display" to="inline" begin="loop.begin+%.4fs" />' . "\n", $event['time']);
                $altAnims .= sprintf('        <set attributeName="display" to="none" begin="loop.begin+%.4fs" />' . "\n", $event['time']);
            }
        }

        $mainAnims .= '        <set attributeName="display" to="inline" begin="loop.begin" />' . "\n";
        $altAnims .= '        <set attributeName="display" to="none" begin="loop.begin" />' . "\n";

        return $this->getSvgTemplate($svgWidth, $svgHeight, $mainText, $mainRects, $mainScroll, $altText, $altRects, $altScroll, $mainAnims, $altAnims, $cursorAnims);
    }

    private function getSvgTemplate(float $width, float $height, string $mainText, string $mainRects, string $mainScroll, string $altText, string $altRects, string $altScroll, string $mainAnims, string $altAnims, string $cursorAnims): string
    {
        $fontFamily = $this->config['font_family'];
        $fontSize = $this->config['font_size'];
        $bgColor = $this->config['default_bg'];
        $fgColor = $this->config['default_fg'];
        $cursorWidth = $this->config['font_size'] * 0.6;
        $cursorHeight = $this->config['font_size'] * $this->config['line_height_factor'];
        $loopDuration = $this->totalDuration + $this->config['animation_pause_seconds'];
        $resetScroll = '        <animateTransform attributeName="transform" type="translate" to="0,0" dur="0.001s" begin="loop.begin" fill="freeze" />' . "\n";

        $cssStyles = '';
        if (!empty($this->cssRules)) {
            $cssStyles .= "    <style>\n";
            $flippedRules = array_flip($this->cssRules);
            ksort($flippedRules);
            foreach ($flippedRules as $className => $rule) {
                $cssStyles .= "      .{$className} { {$rule} }\n";
            }
            $cssStyles .= "    </style>\n";
        }

        return <<<SVG
<svg width="{$width}" height="{$height}" xmlns="http://www.w3.org/2000/svg" font-family='{$fontFamily}' font-size="{$fontSize}">
    <title>Terminal Session Recording</title>
{$cssStyles}
    <rect width="100%" height="100%" fill="{$bgColor}" />
    <g id="master">
        <animate id="loop" attributeName="visibility" from="hidden" to="visible" begin="0;loop.end" dur="{$loopDuration}s" />
        <g id="main-screen" display="inline">
{$mainAnims}
            <g class="terminal-screen" transform="translate(0, 0)" visibility="hidden" text-rendering="geometricPrecision">
                <set attributeName="visibility" to="visible" begin="loop.begin" />
{$resetScroll}{$mainScroll}{$mainRects}{$mainText}
            </g>
        </g>
        <g id="alt-screen" display="none">
{$altAnims}
            <g class="terminal-screen" transform="translate(0, 0)" visibility="hidden" text-rendering="geometricPrecision">
                <set attributeName="visibility" to="visible" begin="loop.begin" />
{$resetScroll}{$altScroll}{$altRects}{$altText}
            </g>
        </g>
        <rect id="cursor" width="{$cursorWidth}" height="{$cursorHeight}" fill="{$fgColor}" opacity="0.7" visibility="visible">
{$cursorAnims}
        </rect>
    </g>
</svg>
SVG;
    }

    private function renderBuffer(array $buffer, array $scrollEvents, float $charHeight, float $charWidth): array
    {
        $rectElements = '';
        $textElements = '';
        $scrollAnimations = '';

        foreach ($buffer as $y => $row) {
            $x = 0;
            while ($x < $this->config['cols']) {
                if (!isset($row[$x]) || empty($row[$x])) {
                    $x++;
                    continue;
                }

                foreach ($row[$x] as $cell) {
                    if (!isset($cell['startTime'])) {
                        continue;
                    }

                    $current_x = $x;
                    $textChunk = '';
                    $style = $cell['style'];

                    while (
                        $current_x < $this->config['cols'] &&
                        isset($row[$current_x]) &&
                        ($foundCell = $this->findCellMatching($row[$current_x], $cell)) !== null
                    ) {
                        $textChunk .= $foundCell['char'];
                        $this->markCellProcessed($row[$current_x], $foundCell);
                        $current_x++;
                    }

                    if ($textChunk === '') {
                        continue;
                    }

                    $fgHex = $this->getHexForColor('fg', $style);
                    $bgHex = $this->getHexForColor('bg', $style);

                    if (!empty($style['inverse'])) {
                        list($fgHex, $bgHex) = [$bgHex, $fgHex];
                    }

                    $visibilityAnims = sprintf(
                        '<set attributeName="visibility" to="visible" begin="loop.begin+%.4fs" />',
                        $cell['startTime']
                    );
                    if (isset($cell['endTime'])) {
                        $visibilityAnims .= sprintf(
                            '<set attributeName="visibility" to="hidden" begin="loop.begin+%.4fs" />',
                            $cell['endTime']
                        );
                    }
                    $visibilityAnims .= '<set attributeName="visibility" to="hidden" begin="loop.begin" />';

                    $chunkWidth = ($current_x - $x) * $charWidth;

                    if ($bgHex !== $this->config['default_bg']) {
                        $rectX = $x * $charWidth;
                        $rectY = $y * $charHeight;
                        $bgRule = sprintf('fill:%s;', $bgHex);
                        $bgClass = $this->getClassName($bgRule);

                        $rectElements .= sprintf(
                            '        <rect class="%s" x="%.2F" y="%.2F" width="%.2F" height="%.2F">%s</rect>' . "\n",
                            $bgClass,
                            $rectX,
                            $rectY,
                            $chunkWidth,
                            $charHeight,
                            $visibilityAnims
                        );
                    }

                    $trimmedChunk = trim(str_replace('&#160;', ' ', $textChunk));
                    if ($trimmedChunk !== '') {
                        $textX = $x * $charWidth;
                        $textY = ($y + 1) * $charHeight - ($charHeight - $this->config['font_size']) / 2;
                        $textCss = sprintf('fill:%s;', $fgHex);
                        if ($style['bold']) {
                            $textCss .= 'font-weight:bold;';
                        }
                        if ($style['italic']) {
                            $textCss .= 'font-style:italic;';
                        }
                        if ($style['underline']) {
                            $textCss .= 'text-decoration:underline;';
                        }
                        if ($style['strikethrough']) {
                            $textCss .= 'text-decoration:line-through;';
                        }
                        if ($style['dim']) {
                            $textCss .= 'opacity:0.5;';
                        }

                        $textClass = $this->getClassName($textCss);
                        $spacePreserveAttr = '';
                        if (str_starts_with($textChunk, ' ') || str_ends_with($textChunk, ' ')) {
                            $spacePreserveAttr = ' xml:space="preserve"';
                        }
                        $textElements .= sprintf(
                            '        <text class="%s" x="%.2F" y="%.2F"%s>%s%s</text>' . "\n",
                            $textClass,
                            $textX,
                            $textY,
                            $spacePreserveAttr,
                            $textChunk,
                            $visibilityAnims
                        );
                    }
                }
                $x++;
            }
        }

        foreach ($scrollEvents as $event) {
            $time = $event['time'];
            $fromY = -($event['offset'] * $charHeight);
            $toY = -($event['offset'] + 1) * $charHeight;

            $scrollAnimations .= sprintf(
                '        <animateTransform attributeName="transform" type="translate" from="0 %.2F" to="0 %.2F" begin="loop.begin+%.4fs" dur="0.001s" fill="freeze" />' . "\n",
                $fromY,
                $toY,
                $time
            );
        }

        return [$textElements, $rectElements, $scrollAnimations];
    }

    private function generateCursorAnimations(float $charWidth, float $charHeight): string
    {
        $anims = '';
        foreach ($this->state->cursorEvents as $event) {
            if (isset($event['visible'])) {
                $to = $event['visible'] ? 'visible' : 'hidden';
                $anims .= sprintf('        <set attributeName="visibility" to="%s" begin="loop.begin+%.4fs" />' . "\n", $to, $event['time']);
            } else {
                $toX = $event['x'] * $charWidth;
                $toY = $event['y'] * $charHeight;
                $anims .= sprintf('        <set attributeName="x" to="%.2F" begin="loop.begin+%.4fs" />' . "\n", $toX, $event['time']);
                $anims .= sprintf('        <set attributeName="y" to="%.2F" begin="loop.begin+%.4fs" />' . "\n", $toY, $event['time']);
            }
        }

        $initialX = 0.0;
        $initialY = 0.0;
        $initialVisibility = 'visible';

        foreach ($this->state->cursorEvents as $event) {
            if (isset($event['x'])) {
                $initialX = $event['x'] * $charWidth;
                $initialY = $event['y'] * $charHeight;
                break;
            }
        }
        foreach ($this->state->cursorEvents as $event) {
            if (isset($event['visible'])) {
                $initialVisibility = $event['visible'] ? 'visible' : 'hidden';
                break;
            }
        }

        $anims .= sprintf('        <set attributeName="x" to="%.2F" begin="loop.begin"/>' . "\n", $initialX);
        $anims .= sprintf('        <set attributeName="y" to="%.2F" begin="loop.begin"/>' . "\n", $initialY);
        $anims .= sprintf('        <set attributeName="visibility" to="%s" begin="loop.begin"/>' . "\n", $initialVisibility);

        return $anims;
    }

    private function getHexForColor(string $type, array $style): string
    {
        if (!empty($style[$type . '_hex'])) {
            return $style[$type . '_hex'];
        }
        $class = $style[$type];
        if ($class === 'fg-default') {
            return $this->config['default_fg'];
        }
        if ($class === 'bg-default') {
            return $this->config['default_bg'];
        }
        $parts = explode('-', $class);
        $code = (int)end($parts);
        if ($code >= 90 && $code <= 97) {
        } elseif ($code >= 100 && $code <= 107) {
            $code -= 10;
        } elseif ($code >= 40 && $code <= 47) {
            $code -= 10;
        }
        return AnsiParser::ANSI_16_COLORS[$code] ?? ($type === 'fg' ? $this->config['default_fg'] : $this->config['default_bg']);
    }

    private function findCellMatching(array &$lifespans, array $targetCell): ?array
    {
        foreach ($lifespans as $cell) {
            if (
                isset($cell['startTime']) &&
                $cell['startTime'] === $targetCell['startTime'] &&
                (!isset($cell['endTime']) && !isset($targetCell['endTime']) ||
                    (isset($cell['endTime']) && isset($targetCell['endTime']) && $cell['endTime'] === $targetCell['endTime'])) &&
                $cell['style'] === $targetCell['style']
            ) {
                return $cell;
            }
        }
        return null;
    }

    private function markCellProcessed(array &$lifespans, array $targetCell): void
    {
        foreach ($lifespans as $key => &$cell) {
            if (
                isset($cell['startTime']) &&
                $cell['startTime'] === $targetCell['startTime'] &&
                (!isset($cell['endTime']) && !isset($targetCell['endTime']) ||
                    (isset($cell['endTime']) && isset($targetCell['endTime']) && $cell['endTime'] === $targetCell['endTime'])) &&
                $cell['style'] === $targetCell['style']
            ) {
                unset($cell['startTime']);
                return;
            }
        }
    }

    private function getClassName(string $rule): string
    {
        if (empty($rule)) {
            return '';
        }
        if (!isset($this->cssRules[$rule])) {
            $this->classCounter++;
            $this->cssRules[$rule] = 'c' . $this->classCounter;
        }
        return $this->cssRules[$rule];
    }
}
