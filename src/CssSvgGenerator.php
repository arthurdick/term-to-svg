<?php

declare(strict_types=1);

namespace ArthurDick\TermToSvg;

/**
 * Generates an animated SVG from a processed TerminalState object.
 *
 * This class takes the final state of the terminal buffers, styles, and animation
 * events, and renders them into a self-contained, animated SVG file using CSS animations.
 */
class CssSvgGenerator extends AbstractSvgGenerator
{
    private array $generatedAnimClasses = [];
    private string $cssAnimations = '';
    private int $visibilityClassCounter = 0;
    private int $blinkClassCounter = 0;

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
        list($mainText, $mainRects) = $this->renderBuffer($this->state->mainBuffer, $charHeight, $charWidth);
        list($altText, $altRects) = $this->renderBuffer($this->state->altBuffer, $charHeight, $charWidth);
        $this->generateCssAnimations($charWidth, $charHeight);
        $interactiveControls = '';
        if ($this->config['interactive']) {
            $interactiveControls = $this->getInteractiveControls($svgHeight, $svgWidth, $this->totalDuration);
            $svgHeight += 50;
        }
        return $this->getSvgTemplate($svgWidth, $svgHeight, $mainText, $mainRects, $altText, $altRects, $interactiveControls);
    }

    private function getSvgTemplate(float $width, float $height, string $mainText, string $mainRects, string $altText, string $altRects, string $interactiveControls): string
    {
        $fgColor = $this->config['default_fg'];
        $cursorWidth = $this->config['font_size'] * 0.6;
        $cursorHeight = $this->config['font_size'] * $this->config['line_height_factor'];
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
        <g id="{$this->uniqueId}_main-screen">
            <g class="terminal-screen" id="{$this->uniqueId}_main-scroll" text-rendering="geometricPrecision">
                {$mainRects}{$mainText}
            </g>
        </g>
        <g id="{$this->uniqueId}_alt-screen">
            <g class="terminal-screen" id="{$this->uniqueId}_alt-scroll" text-rendering="geometricPrecision">
                {$altRects}{$altText}
            </g>
        </g>
        <rect id="{$this->uniqueId}_cursor" width="{$cursorWidth}" height="{$cursorHeight}" fill="{$fgColor}" opacity="0.7">
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

        const animatedElements = Array.from(svg.querySelectorAll('#' + svg.id + '_cursor, #' + svg.id + '_main-screen, #' + svg.id + '_alt-screen, #' + svg.id + '_main-scroll, #' + svg.id + '_alt-scroll, [class*="' + svg.id + '"]'));

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
            startTime: 0,
            currentTime: 0,
            thumbWidth: 10,
            seekTime: -1,

            init: function() {
                this.loopDuration = this.totalDuration + this.animationPause;
                this.effectiveScrubWidth = this.scrubBarWidth - this.thumbWidth;

                this.animations = animatedElements.flatMap(el => el.getAnimations());

                this.playPauseBtn.addEventListener('click', () => this.togglePlayPause());
                this.controls.addEventListener('mousedown', (e) => this.handleScrubStart(e));
                window.addEventListener('mousemove', (e) => this.handleScrubMove(e));
                window.addEventListener('mouseup', () => this.handleScrubEnd());

                this.startTime = performance.now();
                requestAnimationFrame(() => this.animationLoop());
            },

            animationLoop: function() {
                if (this.isPlaying && !this.isScrubbing) {
                    const elapsed = (performance.now() - this.startTime) / 1000;
                    this.currentTime = elapsed %% this.loopDuration;
                    this.updatePlayerVisuals(this.currentTime);
                }
                requestAnimationFrame(() => this.animationLoop());
            },

            updatePlayerVisuals: function(time) {
                const displayTime = Math.min(time, this.totalDuration);
                let percentage = this.totalDuration > 0 ? (displayTime / this.totalDuration) : 0;
                percentage = Math.max(0, Math.min(1, percentage));

                const thumbX = this.scrubBarStartX + (percentage * this.effectiveScrubWidth);
                this.scrubThumb.setAttribute('x', thumbX);
                this.timeDisplay.textContent = displayTime.toFixed(2) + 's / ' + this.totalDuration.toFixed(2) + 's';

                if (time >= this.totalDuration && this.isPlaying) {
                    this.playerIcon.setAttribute('href', '#%s_play-icon');
                } else if (this.isPlaying) {
                    this.playerIcon.setAttribute('href', '#%s_pause-icon');
                }
            },

            togglePlayPause: function() {
                this.isPlaying = !this.isPlaying;
                if (this.isPlaying) {
                    this.startTime = performance.now() - this.currentTime * 1000;
                    this.animations.forEach(anim => anim.play());
                    requestAnimationFrame(() => this.animationLoop());
                } else {
                    this.animations.forEach(anim => anim.pause());
                }
                this.playerIcon.setAttribute('href', this.isPlaying ? '#%s_pause-icon' : '#%s_play-icon');
            },

            getSVGPoint: function(e) {
                let point = this.svg.createSVGPoint();
                point.x = e.clientX;
                point.y = e.clientY;
                return point.matrixTransform(this.svg.getScreenCTM().inverse());
            },

            handleScrubStart: function(e) {
                const targetClass = e.target.getAttribute('class');
                if (targetClass && (targetClass.includes('scrub-bar-track') || targetClass.includes('scrub-thumb'))) {
                    this.isScrubbing = true;
                    this.animations.forEach(anim => anim.pause());
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
                if (this.seekTime !== -1) {
                    this.currentTime = this.seekTime;
                    this.startTime = performance.now() - this.currentTime * 1000;
                    this.seekTime = -1;
                }
                if(this.isPlaying) {
                    this.animations.forEach(anim => anim.play());
                    requestAnimationFrame(() => this.animationLoop());
                }
            },

            handleScrub: function(e) {
                const svgPoint = this.getSVGPoint(e);
                const scrubX = svgPoint.x - this.scrubBarStartX;
                const clampedX = Math.max(0, Math.min(scrubX, this.effectiveScrubWidth));
                const newTime = (clampedX / this.effectiveScrubWidth) * this.totalDuration;

                this.seekTime = newTime;
                this.updatePlayerVisuals(newTime);

                // Set the animation's time directly (in milliseconds)
                this.animations.forEach(anim => {
                    anim.currentTime = newTime * 1000;
                });
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

    /**
     * Wraps the given content in the main SVG structure.
     */
    protected function _getSvgWrapper(float $width, float $height, string $content): string
    {
        $fontFamily = $this->config['font_family'];
        $fontSize = $this->config['font_size'];
        $cssStyles = $this->_generateCssStyles();
        $bgColor = $this->config['default_bg'];
        // The viewBox defines the internal coordinate system, allowing the SVG to scale.
        return <<<SVG
<svg id="{$this->uniqueId}" viewBox="0 0 {$width} {$height}" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" font-family='{$fontFamily}' font-size="{$fontSize}" text-rendering="geometricPrecision">
    <title>Terminal Session Recording</title>
    <style>
      {$cssStyles}
      {$this->cssAnimations}
    </style>
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
        $cssStyles = "";
        $sortedRules = $this->cssRules;
        ksort($sortedRules);
        foreach ($sortedRules as $rule => $className) {
            $cssStyles .= "      .{$className} { {$rule} }\n";
        }
        return $cssStyles;
    }

    private function renderBuffer(array $buffer, float $charHeight, float $charWidth): array
    {
        $rectElements = '';
        $textElements = '';
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
                    $animClass = $this->getVisibilityAnimClass($cell['startTime'], $cell['endTime'] ?? null);
                    $chunkWidth = ($current_x - $x) * $charWidth;
                    if ($bgHex !== $this->config['default_bg']) {
                        $rectX = $x * $charWidth;
                        $rectY = $y * $charHeight;
                        $bgRule = sprintf('fill:%s;', $bgHex);
                        $bgClass = $this->getClassName($bgRule);
                        $rectElements .= sprintf(
                            '<rect class="%s %s" x="%.2F" y="%.2F" width="%.2F" height="%.2F"></rect>',
                            $bgClass,
                            $animClass,
                            $rectX,
                            $rectY,
                            $chunkWidth,
                            $charHeight
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
                        $blinkClass = '';
                        if ($style['blink']) {
                            $blinkClass = $this->getBlinkAnimClass($cell['startTime']);
                        }
                        $spacePreserveAttr = '';
                        if (str_starts_with($textChunk, ' ') || str_ends_with($textChunk, ' ') || strpos($textChunk, '  ') !== false) {
                            $spacePreserveAttr = ' xml:space="preserve"';
                        }
                        $textElement = sprintf(
                            '<text class="%s %s %s" x="%.2F" y="%.2F"%s>%s</text>',
                            $textClass,
                            $animClass,
                            $blinkClass,
                            $textX,
                            $textY,
                            $spacePreserveAttr,
                            $textChunk
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
        return [$textElements, $rectElements];
    }

    private function generateCssAnimations(float $charWidth, float $charHeight): void
    {
        $totalDuration = $this->totalDuration + $this->config['animation_pause_seconds'];
        if ($totalDuration == 0) {
            return;
        }
        // Cursor Animations
        $cursorKeyframes = $this->generateKeyframes('cursor-pos', $this->state->cursorEvents, function ($event) use ($charWidth, $charHeight) {
            if (isset($event['x'])) {
                return sprintf('transform: translate(%.2Fpx, %.2Fpx);', $event['x'] * $charWidth, $event['y'] * $charHeight);
            }
            return null;
        });
        $cursorVisibilityKeyframes = $this->generateKeyframes('cursor-vis', $this->state->cursorEvents, function ($event) {
            if (isset($event['visible'])) {
                return 'visibility: ' . ($event['visible'] ? 'visible' : 'hidden') . ';';
            }
            return null;
        });
        // Screen Switch Animations
        $mainScreenKeyframes = $this->generateKeyframes('main-screen', $this->state->screenSwitchEvents, function ($event) {
            return 'opacity: ' . ($event['type'] === 'to_main' ? '1' : '0') . '; pointer-events: ' . ($event['type'] === 'to_main' ? 'auto' : 'none') . ';';
        }, 'opacity: 1; pointer-events: auto;');
        $altScreenKeyframes = $this->generateKeyframes('alt-screen', $this->state->screenSwitchEvents, function ($event) {
            return 'opacity: ' . ($event['type'] === 'to_alt' ? '1' : '0') . '; pointer-events: ' . ($event['type'] === 'to_alt' ? 'auto' : 'none') . ';';
        }, 'opacity: 0; pointer-events: none;');
        // Scroll Animations
        $mainScrollKeyframes = $this->generateKeyframes('main-scroll', $this->state->mainScrollEvents, function ($event) use ($charHeight) {
            return sprintf('transform: translateY(-%.2Fpx);', ($event['offset'] + 1) * $charHeight);
        });
        $altScrollKeyframes = $this->generateKeyframes('alt-scroll', $this->state->altScrollEvents, function ($event) use ($charHeight) {
            return sprintf('transform: translateY(-%.2Fpx);', ($event['offset'] + 1) * $charHeight);
        });
        $animationProps = "{$totalDuration}s steps(1, end) infinite";
        $this->cssAnimations .= <<<CSS
      #{$this->uniqueId}_cursor { animation: {$animationProps} {$this->uniqueId}-cursor-pos, {$animationProps} {$this->uniqueId}-cursor-vis; }
      #{$this->uniqueId}_main-screen { animation: {$animationProps} {$this->uniqueId}-main-screen; opacity: 1; pointer-events: auto; }
      #{$this->uniqueId}_alt-screen { animation: {$animationProps} {$this->uniqueId}-alt-screen; opacity: 0; pointer-events: none; }
      #{$this->uniqueId}_main-scroll { animation: {$animationProps} {$this->uniqueId}-main-scroll; }
      #{$this->uniqueId}_alt-scroll { animation: {$animationProps} {$this->uniqueId}-alt-scroll; }
      @keyframes {$this->uniqueId}-cursor-pos { {$cursorKeyframes} }
      @keyframes {$this->uniqueId}-cursor-vis { {$cursorVisibilityKeyframes} }
      @keyframes {$this->uniqueId}-main-screen { {$mainScreenKeyframes} }
      @keyframes {$this->uniqueId}-alt-screen { {$altScreenKeyframes} }
      @keyframes {$this->uniqueId}-main-scroll { {$mainScrollKeyframes} }
      @keyframes {$this->uniqueId}-alt-scroll { {$altScrollKeyframes} }
CSS;
    }

    private function generateKeyframes(string $name, array $events, callable $formatter, ?string $initialValue = null): string
    {
        $frames = [];
        $totalDuration = $this->totalDuration + $this->config['animation_pause_seconds'];
        if ($totalDuration == 0) {
            return '';
        }
        $lastFrame = $initialValue;
        if ($initialValue !== null) {
            $frames[] = "0% { {$initialValue} }";
        }
        foreach ($events as $event) {
            $css = $formatter($event);
            if ($css === null || $css === $lastFrame) {
                continue;
            }
            $percentage = ($event['time'] / $totalDuration) * 100;
            $frames[] = sprintf("%.4F%% { %s }", $percentage, $css);
            $lastFrame = $css;
        }
        return implode(' ', $frames);
    }

    private function getVisibilityAnimClass(float $startTime, ?float $endTime): string
    {
        $totalDuration = $this->totalDuration + $this->config['animation_pause_seconds'];
        if ($totalDuration == 0) {
            return '';
        }
        $key = sprintf("vis_%.4F_%.4F", $startTime, $endTime ?? -1);
        if (!isset($this->generatedAnimClasses[$key])) {
            $this->visibilityClassCounter++;
            $className = 'v' . $this->visibilityClassCounter . '_' . $this->uniqueId;
            $this->generatedAnimClasses[$key] = $className;
            $keyframes = $this->generateKeyframes($className . '-anim', [
                ['time' => 0, 'visibility' => 'hidden'],
                ['time' => $startTime, 'visibility' => 'visible'],
                ['time' => $endTime ?? $totalDuration, 'visibility' => ($endTime === null ? 'visible' : 'hidden')]
            ], function ($event) {
                return 'visibility: ' . $event['visibility'] . ';';
            });
            $this->cssAnimations .= <<<CSS
      .{$className} {
        animation: {$totalDuration}s steps(1, end) infinite {$this->uniqueId}-{$className}-anim;
        visibility: hidden;
      }
      @keyframes {$this->uniqueId}-{$className}-anim {
        {$keyframes}
      }
CSS;
        }
        return $this->generatedAnimClasses[$key];
    }

    private function getBlinkAnimClass(float $startTime): string
    {
        $totalDuration = $this->totalDuration + $this->config['animation_pause_seconds'];
        if ($totalDuration == 0) {
            return '';
        }
        $key = "blink_{$startTime}";
        if (!isset($this->generatedAnimClasses[$key])) {
            $this->blinkClassCounter++;
            $className = 'b' . $this->blinkClassCounter . '_' . $this->uniqueId;
            $this->generatedAnimClasses[$key] = $className;
            $this->cssAnimations .= <<<CSS
        .{$className} {
            animation-name: {$this->uniqueId}-blink-anim;
            animation-duration: 1s;
            animation-iteration-count: infinite;
            animation-timing-function: steps(1, end);
            animation-delay: {$startTime}s;
        }
        @keyframes {$this->uniqueId}-blink-anim {
            50% { visibility: hidden; }
        }
CSS;
        }
        return $this->generatedAnimClasses[$key];
    }
}
