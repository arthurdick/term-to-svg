<?php

declare(strict_types=1);

namespace ArthurDick\TermToSvg;

/**
 * Manages the state of the virtual terminal throughout the conversion process.
 *
 * This class tracks everything from cursor position and styling to the content
 * of the screen buffers (both main and alternate) and animation events.
 */
class TerminalState
{
    /** @var int The horizontal position of the cursor (0-indexed). */
    public int $cursorX = 0;

    /** @var int The vertical position of the cursor (0-indexed). */
    public int $cursorY = 0;

    /** @var int The saved horizontal cursor position, used for DEC VTE sequences. */
    public int $savedCursorX = 0;

    /** @var int The saved vertical cursor position, used for DEC VTE sequences. */
    public int $savedCursorY = 0;

    /** @var array<string, mixed> The current text style attributes (color, bold, etc.). */
    public array $currentStyle;

    /** @var array<string, mixed> The saved text style attributes. */
    public array $savedStyle;

    /** @var bool Whether the cursor is currently visible. */
    public bool $cursorVisible = true;

    /** @var bool Whether auto-wrap mode is enabled. */
    public bool $autoWrapMode = true;

    /** @var array<int, array<string, mixed>> A log of cursor movement and visibility change events. */
    public array $cursorEvents = [];

    /** @var array<int, array<int, mixed>> The main screen buffer, holding character and style history. */
    public array $mainBuffer = [];

    /** @var array<int, array<int, mixed>> The alternate screen buffer. */
    public array $altBuffer = [];

    /** @var bool Whether the alternate screen buffer is currently active. */
    public bool $altScreenActive = false;

    /** @var int The vertical scroll offset for the main buffer. */
    public int $mainScrollOffset = 0;

    /** @var int The vertical scroll offset for the alternate buffer. */
    public int $altScrollOffset = 0;

    /** @var array<int, array<string, mixed>> A log of scroll events for the main buffer. */
    public array $mainScrollEvents = [];

    /** @var array<int, array<string, mixed>> A log of scroll events for the alternate buffer. */
    public array $altScrollEvents = [];

    /** @var array<int, array<string, mixed>> A log of events switching between main and alternate screens. */
    public array $screenSwitchEvents = [];

    /** @var int The top boundary of the scrolling region. */
    public int $scrollTop = 0;

    /** @var int The bottom boundary of the scrolling region. */
    public int $scrollBottom;

    /** @var array<string, mixed> The configuration array. */
    private array $config;

    /**
     * @param array<string, mixed> $config The application configuration.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->scrollBottom = $this->config['rows'] - 1;
        $this->resetStyle();
        $this->savedStyle = $this->currentStyle;
    }

    /**
     * Resets the current text style to its default state.
     */
    public function resetStyle(): void
    {
        $this->currentStyle = [
            'fg' => 'fg-default',
            'bg' => 'bg-default',
            'bold' => false,
            'dim' => false,
            'italic' => false,
            'underline' => false,
            'inverse' => false,
            'strikethrough' => false,
            'invisible' => false,
            'fg_hex' => null,
            'bg_hex' => null,
        ];
    }

    /**
     * Returns a reference to the currently active screen buffer.
     * @return array<int, array<int, mixed>>
     */
    public function &getActiveBuffer(): array
    {
        if ($this->altScreenActive) {
            return $this->altBuffer;
        }
        return $this->mainBuffer;
    }

    /**
     * Returns a reference to the scroll offset of the active buffer.
     */
    public function &getActiveScrollOffsetRef(): int
    {
        if ($this->altScreenActive) {
            return $this->altScrollOffset;
        }
        return $this->mainScrollOffset;
    }

    /**
     * Returns a reference to the scroll events array of the active buffer.
     * @return array<int, array<string, mixed>>
     */
    public function &getActiveScrollEventsRef(): array
    {
        if ($this->altScreenActive) {
            return $this->altScrollEvents;
        }
        return $this->mainScrollEvents;
    }

    /**
     * Sets the cursor's visibility and records the event.
     * @param bool $visible The new visibility state.
     * @param float $time The timestamp of the event.
     */
    public function setCursorVisibility(bool $visible, float $time): void
    {
        if ($this->cursorVisible !== $visible) {
            $this->cursorVisible = $visible;
            $this->cursorEvents[] = ['time' => $time, 'visible' => $this->cursorVisible];
        }
    }

    /**
     * Records the current cursor position if it has changed since the last recording.
     * @param float $time The timestamp of the event.
     */
    public function recordCursorState(float $time): void
    {
        $lastPositionEvent = null;
        foreach (array_reverse($this->cursorEvents) as $event) {
            if (isset($event['x'])) {
                $lastPositionEvent = $event;
                break;
            }
        }

        if ($lastPositionEvent !== null && $lastPositionEvent['x'] === $this->cursorX && $lastPositionEvent['y'] === $this->cursorY) {
            return;
        }

        $newEvent = ['time' => $time, 'x' => $this->cursorX, 'y' => $this->cursorY];

        $lastKey = array_key_last($this->cursorEvents);
        if ($lastKey !== null) {
            $lastEvent = $this->cursorEvents[$lastKey];
            if (isset($lastEvent['x']) && $lastEvent['time'] === $time) {
                $this->cursorEvents[$lastKey] = $newEvent;
                return;
            }
        }

        $this->cursorEvents[] = $newEvent;
    }
}
