<?php

declare(strict_types=1);

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
    private string $uniqueId;

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

        $interactiveControls = '';
        if ($this->config['interactive']) {
            $interactiveControls = $this->getInteractiveControls($svgHeight, $svgWidth, $this->totalDuration);
            $svgHeight += 50;
        }

        $mainAnims = '';
        $altAnims = '';

        foreach ($this->state->screenSwitchEvents as $event) {
            if ($event['type'] === 'to_alt') {
                $mainAnims .= sprintf('        <set attributeName="display" to="none" begin="%s_loop.begin+%.4fs" />' . "\n", $this->uniqueId, $event['time']);
                $altAnims .= sprintf('        <set attributeName="display" to="inline" begin="%s_loop.begin+%.4fs" />' . "\n", $this->uniqueId, $event['time']);
            } else {
                $mainAnims .= sprintf('        <set attributeName="display" to="inline" begin="%s_loop.begin+%.4fs" />' . "\n", $this->uniqueId, $event['time']);
                $altAnims .= sprintf('        <set attributeName="display" to="none" begin="%s_loop.begin+%.4fs" />' . "\n", $this->uniqueId, $event['time']);
            }
        }

        $mainAnims .= sprintf('        <set attributeName="display" to="inline" begin="%s_loop.begin" />' . "\n", $this->uniqueId);
        $altAnims .= sprintf('        <set attributeName="display" to="none" begin="%s_loop.begin" />' . "\n", $this->uniqueId);

        return $this->getSvgTemplate($svgWidth, $svgHeight, $mainText, $mainRects, $mainScroll, $altText, $altRects, $altScroll, $mainAnims, $altAnims, $cursorAnims, $interactiveControls);
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

    private function getSvgTemplate(float $width, float $height, string $mainText, string $mainRects, string $mainScroll, string $altText, string $altRects, string $altScroll, string $mainAnims, string $altAnims, string $cursorAnims, string $interactiveControls): string
    {
        $fgColor = $this->config['default_fg'];
        $cursorWidth = $this->config['font_size'] * 0.6;
        $cursorHeight = $this->config['font_size'] * $this->config['line_height_factor'];
        $loopDuration = $this->totalDuration + $this->config['animation_pause_seconds'];
        $resetScroll = sprintf('        <animateTransform attributeName="transform" type="translate" to="0,0" dur="0.001s" begin="%s_loop.begin" fill="freeze" />' . "\n", $this->uniqueId);

        $playerDefs = '';
        if ($this->config['interactive']) {
            $playerDefs = <<<DEFS
    <defs>
        <g id="{$this->uniqueId}_play-icon"><path d="M4 2 L12 8 L4 14 Z" fill="white" /></g>
        <g id="{$this->uniqueId}_pause-icon"><path d="M4 2 H6 V14 H4 Z M10 2 H12 V14 H10 Z" fill="white" /></g>
    </defs>
DEFS;
        }

        $animatedContent = <<<XML
    {$playerDefs}
    <rect width="100%" height="100%" fill="{$this->config['default_bg']}" />
    <g id="{$this->uniqueId}_master">
        <animate id="{$this->uniqueId}_loop" attributeName="visibility" from="hidden" to="visible" begin="0;{$this->uniqueId}_loop.end" dur="{$loopDuration}s" />
        <g id="{$this->uniqueId}_main-screen" display="inline">
{$mainAnims}
            <g class="terminal-screen" transform="translate(0, 0)" visibility="hidden" text-rendering="geometricPrecision">
                <set attributeName="visibility" to="visible" begin="{$this->uniqueId}_loop.begin" />
{$resetScroll}{$mainScroll}{$mainRects}{$mainText}
            </g>
        </g>
        <g id="{$this->uniqueId}_alt-screen" display="none">
{$altAnims}
            <g class="terminal-screen" transform="translate(0, 0)" visibility="hidden" text-rendering="geometricPrecision">
                <set attributeName="visibility" to="visible" begin="{$this->uniqueId}_loop.begin" />
{$resetScroll}{$altScroll}{$altRects}{$altText}
            </g>
        </g>
        <rect id="{$this->uniqueId}_cursor" width="{$cursorWidth}" height="{$cursorHeight}" fill="{$fgColor}" opacity="0.7" visibility="visible">
{$cursorAnims}
        </rect>
    </g>
    {$interactiveControls}
XML;

        return $this->_getSvgWrapper($width, $height, $animatedContent);
    }


    private function getInteractiveControls(float $y, float $width, float $totalDuration): string
    {
        $barHeight = 50;
        $buttonWidth = 40;
        $padding = 10;
        $animationPauseSeconds = $this->config['animation_pause_seconds'];
        $scrubBarStartX = $padding + $buttonWidth + $padding;
        $timeDisplayWidth = 120;
        $scrubBarWidth = $width - $scrubBarStartX - $timeDisplayWidth - $padding;
        $iconTranslateX = $padding + 12;
        $iconTranslateY = $padding + 7;
        $timeDisplayTextX = $scrubBarStartX + $scrubBarWidth + $padding;
        $initialTimeText = sprintf("0.00s / %.2fs", $totalDuration);

        $scriptTemplate = <<<JS
//<![CDATA[
        (function() {
            const svg = document.getElementById('%s');
            if (!svg) return;

            const player = {
                svg: svg,
                playPauseBtn: svg.querySelector('.play-pause-btn'),
                playerIcon: svg.querySelector('.player-icon'),
                timeDisplay: svg.querySelector('.time-display'),
                scrubThumb: svg.querySelector('.scrub-thumb'),
                controls: svg.querySelector('.player-controls'),

                totalDuration: %.4F,
                animationPause: %.4F,
                scrubBarWidth: %.4F,
                scrubBarStartX: %.4F,
                isPlaying: true,
                isScrubbing: false,

                init: function() {
                    this.loopDuration = this.totalDuration + this.animationPause;
                    this.playPauseBtn.addEventListener('click', () => this.togglePlayPause());
                    this.controls.addEventListener('mousedown', (e) => this.handleScrubStart(e));
                    window.addEventListener('mousemove', (e) => this.handleScrubMove(e));
                    window.addEventListener('mouseup', () => this.handleScrubEnd());
                    window.addEventListener('load', () => requestAnimationFrame(() => this.animationLoop()), { once: true });
                },

                updatePlayerVisuals: function() {
                    const masterTime = this.svg.getCurrentTime();
                    const effectiveTime = masterTime %% this.loopDuration;
                    const displayTime = Math.min(effectiveTime, this.totalDuration);

                    let percentage = displayTime / this.totalDuration;
                    const thumbX = this.scrubBarStartX + (percentage * this.scrubBarWidth);

                    this.scrubThumb.setAttribute('x', thumbX);
                    this.timeDisplay.textContent = `\${displayTime.toFixed(2)}s / \${this.totalDuration.toFixed(2)}s`;

                    if (effectiveTime > this.totalDuration && this.isPlaying) {
                        this.playerIcon.setAttribute('href', '#%s_play-icon');
                    } else if (this.isPlaying) {
                        this.playerIcon.setAttribute('href', '#%s_pause-icon');
                    }
                },

                animationLoop: function() {
                    if (!this.isPlaying || this.isScrubbing) return;
                    this.updatePlayerVisuals();
                    requestAnimationFrame(() => this.animationLoop());
                },

                togglePlayPause: function() {
                    this.isPlaying = !this.isPlaying;
                    if (this.isPlaying) {
                        this.svg.unpauseAnimations();
                        this.playerIcon.setAttribute('href', '#%s_pause-icon');
                        requestAnimationFrame(() => this.animationLoop());
                    } else {
                        this.svg.pauseAnimations();
                        this.playerIcon.setAttribute('href', '#%s_play-icon');
                    }
                },

                handleScrubStart: function(e) {
                    const targetClass = e.target.getAttribute('class');
                    if (targetClass && (targetClass.includes('scrub-bar-track') || targetClass.includes('scrub-thumb'))) {
                        this.isScrubbing = true;
                        if(this.isPlaying) this.svg.pauseAnimations();
                        this.handleScrub(e);
                    }
                },

                handleScrubMove: function(e) {
                    if (!this.isScrubbing) return;
                    this.handleScrub(e);
                },

                handleScrubEnd: function() {
                    if (!this.isScrubbing) return;
                    this.isScrubbing = false;
                    if (this.isPlaying) {
                        this.svg.unpauseAnimations();
                        requestAnimationFrame(() => this.animationLoop());
                    }
                },

                handleScrub: function(e) {
                    const clickX = e.clientX - this.svg.getBoundingClientRect().left;
                    let percentage = (clickX - this.scrubBarStartX) / this.scrubBarWidth;
                    percentage = Math.max(0, Math.min(1, percentage));
                    const currentLoop = Math.floor(this.svg.getCurrentTime() / this.loopDuration);
                    const time = (currentLoop * this.loopDuration) + (percentage * this.totalDuration);
                    this.svg.setCurrentTime(time);
                    this.updatePlayerVisuals();
                }
            };

            player.init();
        })();
//]]>
JS;
        $script = sprintf($scriptTemplate, $this->uniqueId, $totalDuration, $animationPauseSeconds, $scrubBarWidth, $scrubBarStartX, $this->uniqueId, $this->uniqueId, $this->uniqueId, $this->uniqueId);

        $playerTemplate = <<<SVG
    <g class="player-controls" transform="translate(0, %.2F)" style="font-family: sans-serif; font-size: 14px;">
        <rect class="player-background" x="0" y="0" width="%.2F" height="%.2F" fill="#222" stroke="#444" stroke-width="1" />
        <g class="play-pause-btn" style="cursor: pointer;">
            <rect x="%.2F" y="%.2F" width="%.2F" height="30" fill="#444" rx="5" />
            <use class="player-icon" href="#{$this->uniqueId}_pause-icon" transform="translate(%.2F, %.2F)" />
        </g>
        <g class="scrub-bar" style="cursor: pointer;">
            <rect class="scrub-bar-track" x="%.2F" y="20" width="%.2F" height="10" fill="#111" rx="5" />
            <rect class="scrub-thumb" x="%.2F" y="18" width="10" height="14" fill="#666" rx="3" />
        </g>
        <text class="time-display" x="%.2F" y="29" fill="white" text-anchor="start">%s</text>
    </g>
    <script>%s</script>
SVG;
        return sprintf($playerTemplate, $y, $width, $barHeight, $padding, $padding, $buttonWidth, $iconTranslateX, $iconTranslateY, $scrubBarStartX, $scrubBarWidth, $scrubBarStartX, $timeDisplayTextX, $initialTimeText, $script);
    }

    private function getPosterSvgTemplate(float $width, float $height, string $content, string $cursor): string
    {
        $fontFamily = $this->config['font_family'];
        $fontSize = $this->config['font_size'];
        $bgColor = $this->config['default_bg'];

        $cssStyles = '';
        if (!empty($this->cssRules)) {
            $cssStyles .= "    <style>\n";
            $sortedRules = $this->cssRules;
            ksort($sortedRules);
            foreach ($sortedRules as $rule => $className) {
                $cssStyles .= "      .{$className} { {$rule} }\n";
            }
            $cssStyles .= "    </style>\n";
        }

        return <<<SVG
<svg id="{$this->uniqueId}" width="{$width}" height="{$height}" xmlns="http://www.w3.org/2000/svg" font-family='{$fontFamily}' font-size="{$fontSize}" text-rendering="geometricPrecision">
    <title>Terminal Session Recording</title>
{$cssStyles}
    <rect width="100%" height="100%" fill="{$bgColor}" />
    {$content}
    {$cursor}
</svg>
SVG;
    }

    /**
     * Wraps the given content in the main SVG structure.
     */
    private function _getSvgWrapper(float $width, float $height, string $content): string
    {
        $fontFamily = $this->config['font_family'];
        $fontSize = $this->config['font_size'];
        $cssStyles = $this->_generateCssStyles();
        $bgColor = $this->config['default_bg'];

        // The viewBox defines the internal coordinate system, allowing the SVG to scale.
        return <<<SVG
<svg id="{$this->uniqueId}" viewBox="0 0 {$width} {$height}" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" font-family='{$fontFamily}' font-size="{$fontSize}" text-rendering="geometricPrecision">
    <title>Terminal Session Recording</title>
{$cssStyles}
    <rect width="100%" height="100%" fill="{$bgColor}" />
    {$content}
</svg>
SVG;
    }

    /**
     * Generates the <style> block from the collected CSS rules.
     */
    private function _generateCssStyles(): string
    {
        if (empty($this->cssRules)) {
            return '';
        }
        $cssStyles = "    <style>\n";
        $sortedRules = $this->cssRules;
        ksort($sortedRules);
        foreach ($sortedRules as $rule => $className) {
            $cssStyles .= "      .{$className} { {$rule} }\n";
        }
        $cssStyles .= "    </style>\n";
        return $cssStyles;
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
                        '<set attributeName="visibility" to="visible" begin="%s_loop.begin+%.4fs" />',
                        $this->uniqueId,
                        $cell['startTime']
                    );
                    if (isset($cell['endTime'])) {
                        $visibilityAnims .= sprintf(
                            '<set attributeName="visibility" to="hidden" begin="%s_loop.begin+%.4fs" />',
                            $this->uniqueId,
                            $cell['endTime']
                        );
                    }
                    $visibilityAnims .= sprintf('<set attributeName="visibility" to="hidden" begin="%s_loop.begin" />', $this->uniqueId);

                    $chunkWidth = ($current_x - $x) * $charWidth;

                    if ($bgHex !== $this->config['default_bg']) {
                        $rectX = $x * $charWidth;
                        $rectY = $y * $charHeight;
                        $bgRule = sprintf('fill:%s;', $bgHex);
                        $bgClass = $this->getClassName($bgRule);

                        $rectElements .= sprintf(
                            '<rect class="%s" x="%.2F" y="%.2F" width="%.2F" height="%.2F">%s</rect>',
                            $bgClass,
                            $rectX,
                            $rectY,
                            $chunkWidth,
                            $charHeight,
                            $visibilityAnims
                        );
                    }

                    if ($textChunk !== '') {
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

                        $blinkAnims = '';
                        if ($style['blink']) {
                            $blinkAnims = '<animate attributeName="visibility" from="visible" to="hidden" dur="1s" repeatCount="indefinite" />';
                        }

                        $spacePreserveAttr = '';
                        if (str_starts_with($textChunk, ' ') || str_ends_with($textChunk, ' ') || strpos($textChunk, '  ') !== false) {
                            $spacePreserveAttr = ' xml:space="preserve"';
                        }

                        $textElement = sprintf(
                            '<text class="%s" x="%.2F" y="%.2F"%s>%s%s%s</text>',
                            $textClass,
                            $textX,
                            $textY,
                            $spacePreserveAttr,
                            $textChunk,
                            $visibilityAnims,
                            $blinkAnims
                        );

                        if (!empty($style['link'])) {
                            $textElements .= sprintf(
                                '<a href="%s" target="_blank">%s</a>',
                                htmlspecialchars($style['link'], ENT_XML1),
                                $textElement
                            );
                        } else {
                            $textElements .= $textElement;
                        }
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
                '        <animateTransform attributeName="transform" type="translate" from="0 %.2F" to="0 %.2F" begin="%s_loop.begin+%.4fs" dur="0.001s" fill="freeze" />' . "\n",
                $fromY,
                $toY,
                $this->uniqueId,
                $time
            );
        }

        return [$textElements, $rectElements, $scrollAnimations];
    }

    private function renderPosterFrame(array &$buffer, float $time, float $charHeight, float $charWidth): array
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

    private function findActiveCell(array $lifespans, float $time): ?array
    {
        foreach (array_reverse($lifespans) as $cell) {
            if ($cell['startTime'] <= $time && (!isset($cell['endTime']) || $cell['endTime'] > $time)) {
                return $cell;
            }
        }
        return null;
    }

    private function generateCursorAnimations(float $charWidth, float $charHeight): string
    {
        $anims = '';
        $lastX = null;
        $lastY = null;
        $lastVisibility = null;

        foreach ($this->state->cursorEvents as $event) {
            if (isset($event['visible'])) {
                $to = $event['visible'] ? 'visible' : 'hidden';
                if ($to !== $lastVisibility) {
                    $anims .= sprintf('        <set attributeName="visibility" to="%s" begin="%s_loop.begin+%.4fs" />' . "\n", $to, $this->uniqueId, $event['time']);
                    $lastVisibility = $to;
                }
            } else {
                $toX = $event['x'] * $charWidth;
                $toY = $event['y'] * $charHeight;

                if ($toX !== $lastX) {
                    $anims .= sprintf('        <set attributeName="x" to="%.2F" begin="%s_loop.begin+%.4fs" />' . "\n", $toX, $this->uniqueId, $event['time']);
                    $lastX = $toX;
                }
                if ($toY !== $lastY) {
                    $anims .= sprintf('        <set attributeName="y" to="%.2F" begin="%s_loop.begin+%.4fs" />' . "\n", $toY, $this->uniqueId, $event['time']);
                    $lastY = $toY;
                }
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

        $anims .= sprintf('        <set attributeName="x" to="%.2F" begin="%s_loop.begin"/>' . "\n", $initialX, $this->uniqueId);
        $anims .= sprintf('        <set attributeName="y" to="%.2F" begin="%s_loop.begin"/>' . "\n", $initialY, $this->uniqueId);
        $anims .= sprintf('        <set attributeName="visibility" to="%s" begin="%s_loop.begin"/>' . "\n", $initialVisibility, $this->uniqueId);

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
            // Append the unique ID to the class name to avoid conflicts
            $this->cssRules[$rule] = 'c' . $this->classCounter . '_' . $this->uniqueId;
        }
        return $this->cssRules[$rule];
    }
}
