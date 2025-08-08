<?php

declare(strict_types=1);

namespace ArthurDick\TermToSvg;

/**
 * Abstract base class for SVG generators, containing shared logic.
 */
abstract class AbstractSvgGenerator implements SvgGeneratorInterface
{
    protected TerminalState $state;
    protected array $config;
    protected float $totalDuration;
    protected string $uniqueId;
    protected array $cssRules = [];
    protected int $styleClassCounter = 0;

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

        // Use the provided ID from config, otherwise generate a dynamic one.
        if (!empty($this->config['id'])) {
            $this->uniqueId = $this->config['id'];
        } else {
            $this->uniqueId = 'svg' . substr(md5(serialize($state)), 0, 8);
        }
    }

    /**
     * Renders a non-animated SVG of the terminal at a specific time.
     *
     * @param float $time The time at which to capture the poster frame.
     * @return string The generated SVG content.
     */
    public function generatePoster(float $time): string
    {
        $charHeight = $this->config['font_size'] * $this->config['line_height_factor'];
        $charWidth = $this->config['font_size'] * $this->config['font_width_factor'];
        $svgWidth = $charWidth * $this->config['cols'];
        $svgHeight = ($charHeight * $this->config['rows']) + ($this->config['font_size'] * 0.2);

        $isAltScreenActive = false;
        foreach ($this->state->screenSwitchEvents as $event) {
            if ($event['time'] > $time) {
                break;
            }
            $isAltScreenActive = ($event['type'] === 'to_alt');
        }

        $activeBuffer = $isAltScreenActive ? $this->state->altBuffer : $this->state->mainBuffer;
        $activeScrollEvents = $isAltScreenActive ? $this->state->altScrollEvents : $this->state->mainScrollEvents;

        $scrollOffset = 0;
        foreach ($activeScrollEvents as $event) {
            if ($event['time'] <= $time) {
                $scrollOffset++;
            }
        }

        $transform = sprintf('transform="translate(0, -%.2F)"', $scrollOffset * $charHeight);
        list($textElements, $rectElements) = $this->renderPosterFrame($activeBuffer, $time, $charHeight, $charWidth);
        $posterContent = "<g {$transform}>" . $rectElements . $textElements . '</g>';

        $cursorX = 0;
        $cursorY = 0;
        $cursorVisible = true;
        foreach ($this->state->cursorEvents as $event) {
            if ($event['time'] > $time) {
                break;
            }
            if (isset($event['x'])) {
                $cursorX = $event['x'];
                $cursorY = $event['y'];
            }
            if (isset($event['visible'])) {
                $cursorVisible = $event['visible'];
            }
        }

        $cursorRect = '';
        if ($cursorVisible) {
            $cursorRect = sprintf(
                '<rect id="%s_cursor" width="%.2F" height="%.2F" fill="%s" opacity="0.7" x="%.2F" y="%.2F" />',
                $this->uniqueId,
                $this->config['font_size'] * 0.6,
                $charHeight,
                $this->config['default_fg'],
                $cursorX * $charWidth,
                $cursorY * $charHeight
            );
        }

        $content = $posterContent . $cursorRect;
        return $this->_getSvgWrapper($svgWidth, $svgHeight, $content);
    }

    protected function renderPosterFrame(array &$buffer, float $time, float $charHeight, float $charWidth): array
    {
        $rectElements = '';
        $textElements = '';

        foreach ($buffer as $y => $row) {
            $x = 0;
            while ($x < $this->config['cols']) {
                $firstActiveCell = $this->findActiveCell($row[$x] ?? [], $time);
                if ($firstActiveCell === null) {
                    $x++;
                    continue;
                }

                $textChunk = '';
                $style = $firstActiveCell['style'];
                $current_x = $x;

                while ($current_x < $this->config['cols']) {
                    $cellToCompare = $this->findActiveCell($row[$current_x] ?? [], $time);
                    if ($cellToCompare !== null && $cellToCompare['style'] === $style) {
                        $textChunk .= $cellToCompare['char'];
                        $current_x++;
                    } else {
                        break;
                    }
                }

                if ($textChunk !== '') {
                    $fgHex = $this->getHexForColor('fg', $style);
                    $bgHex = $this->getHexForColor('bg', $style);
                    if (!empty($style['inverse'])) {
                        list($fgHex, $bgHex) = [$bgHex, $fgHex];
                    }

                    $chunkWidth = ($current_x - $x) * $charWidth;

                    if ($bgHex !== $this->config['default_bg']) {
                        $rectX = $x * $charWidth;
                        $rectY = $y * $charHeight;
                        $bgRule = sprintf('fill:%s;', $bgHex);
                        $bgClass = $this->getClassName($bgRule);
                        $rectElements .= sprintf('<rect class="%s" x="%.2F" y="%.2F" width="%.2F" height="%.2F" />', $bgClass, $rectX, $rectY, $chunkWidth, $charHeight);
                    }

                    $trimmedTextChunk = rtrim($textChunk);
                    if ($trimmedTextChunk !== '') {
                        $textX = $x * $charWidth;
                        $textY = $y * $charHeight + $this->config['font_size'];
                        $textCss = sprintf('fill:%s;', $fgHex);
                        if ($style['bold']) {
                            $textCss .= 'font-weight:bold;';
                        }
                        if ($style['italic']) {
                            $textCss .= 'font-style:italic;';
                        }
                        if ($style['underline'] || !empty($style['link'])) {
                            $textCss .= 'text-decoration:underline;';
                        }
                        if ($style['strikethrough']) {
                            $textCss .= 'text-decoration:line-through;';
                        }
                        if ($style['dim']) {
                            $textCss .= 'opacity:0.5;';
                        }
                        if ($style['invisible']) {
                            $textCss .= 'opacity:0;';
                        }

                        $textClass = $this->getClassName($textCss);

                        $spacePreserveAttr = '';
                        if (str_starts_with($textChunk, ' ') || str_ends_with($textChunk, ' ') || strpos($textChunk, '  ') !== false) {
                            $spacePreserveAttr = ' xml:space="preserve"';
                        }

                        $textElement = sprintf('<text class="%s" x="%.2F" y="%.2F"%s>%s</text>', $textClass, $textX, $textY, $spacePreserveAttr, $trimmedTextChunk);

                        if (!empty($style['link'])) {
                            $textElements .= sprintf('<a href="%s" target="_blank">%s</a>', htmlspecialchars($style['link'], ENT_XML1), $textElement);
                        } else {
                            $textElements .= $textElement;
                        }
                    }
                }

                $x = $current_x;
            }
        }
        return [$textElements, $rectElements];
    }

    protected function findActiveCell(array $lifespans, float $time): ?array
    {
        foreach (array_reverse($lifespans) as $cell) {
            if ($cell['startTime'] <= $time && (!isset($cell['endTime']) || $cell['endTime'] > $time)) {
                return $cell;
            }
        }
        return null;
    }

    protected function getHexForColor(string $type, array $style): string
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

    protected function findCellMatching(array &$lifespans, array $targetCell): ?array
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

    protected function markCellProcessed(array &$lifespans, array $targetCell): void
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

    protected function getClassName(string $rule): string
    {
        if (empty($rule)) {
            return '';
        }
        if (!isset($this->cssRules[$rule])) {
            $this->styleClassCounter++;
            // Append the unique ID to the class name to avoid conflicts
            $this->cssRules[$rule] = 'c' . $this->styleClassCounter . '_' . $this->uniqueId;
        }
        return $this->cssRules[$rule];
    }

    abstract protected function _getSvgWrapper(float $width, float $height, string $content): string;
}
