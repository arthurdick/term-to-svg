<?php

namespace ArthurDick\TermToSvg;

class TerminalState
{
    public int $cursorX = 0;
    public int $cursorY = 0;
    public int $savedCursorX = 0;
    public int $savedCursorY = 0;
    public array $currentStyle;
    public bool $cursorVisible = true;
    public array $cursorEvents = [];
    public array $mainBuffer = [];
    public array $altBuffer = [];
    public bool $altScreenActive = false;
    public int $mainScrollOffset = 0;
    public int $altScrollOffset = 0;
    public array $mainScrollEvents = [];
    public array $altScrollEvents = [];
    public array $screenSwitchEvents = [];
    public int $scrollTop = 0;
    public int $scrollBottom;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->scrollBottom = $this->config['rows'] - 1;
        $this->resetStyle();
    }

    public function resetStyle(): void
    {
        $this->currentStyle = [
            'fg' => 'fg-default',
            'bg' => 'bg-default',
            'bold' => false,
            'inverse' => false,
            'fg_hex' => null,
            'bg_hex' => null,
        ];
    }

    public function &getActiveBuffer(): array
    {
        if ($this->altScreenActive) {
            return $this->altBuffer;
        }
        return $this->mainBuffer;
    }

    public function &getActiveScrollOffsetRef(): int
    {
        if ($this->altScreenActive) {
            return $this->altScrollOffset;
        }
        return $this->mainScrollOffset;
    }

    public function &getActiveScrollEventsRef(): array
    {
        if ($this->altScreenActive) {
            return $this->altScrollEvents;
        }
        return $this->mainScrollEvents;
    }

    public function setCursorVisibility(bool $visible, float $time): void
    {
        if ($this->cursorVisible !== $visible) {
            $this->cursorVisible = $visible;
            $this->cursorEvents[] = ['time' => $time, 'visible' => $this->cursorVisible];
        }
    }

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
