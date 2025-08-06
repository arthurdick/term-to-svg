<?php

declare(strict_types=1);

namespace ArthurDick\TermToSvg;

/**
 * Parses terminal output streams, including ANSI escape codes.
 *
 * This class uses a state machine to process chunks of terminal data,
 * interpreting ANSI commands for cursor movement, color changes, screen clearing,
 * and other virtual terminal operations. It updates the TerminalState accordingly.
 */
class AnsiParser
{
    private const STATE_GROUND = 0;
    private const STATE_ESCAPE = 1;
    private const STATE_CSI_PARAM = 2;
    private const STATE_OSC_STRING = 3;
    private const STATE_CHARSET = 4;

    private const WONT_IMPLEMENT_CSI = ['n', 't'];
    private const WONT_IMPLEMENT_DEC = [
        '1h', '1l', '12h', '12l', '1000h', '1000l', '1002h', '1002l',
        '1003h', '1003l', '1004h', '1004l', '1005h', '1005l', '1006h', '1006l',
        '2004h', '2004l',
    ];
    private const WONT_IMPLEMENT_ESC = [];
    private const WONT_IMPLEMENT_OSC = [0, 1, 2, 9, 52, 777];

    /** @var array<int, string> The 16 standard ANSI color hex codes. */
    public const ANSI_16_COLORS = [
        30 => '#2e3436', 31 => '#cc0000', 32 => '#4e9a06', 33 => '#c4a000',
        34 => '#3465a4', 35 => '#75507b', 36 => '#06989a', 37 => '#d3d7cf',
        90 => '#555753', 91 => '#ef2929', 92 => '#8ae234', 93 => '#fce94f',
        94 => '#729fcf', 95 => '#ad7fa8', 96 => '#34e2e2', 97 => '#eeeeec',
    ];

    private TerminalState $state;
    private array $config;
    private float $currentTime = 0.0;
    private string $oscBuffer = '';


    /**
     * @param TerminalState $state The terminal state object to manipulate.
     * @param array<string, mixed> $config The application configuration.
     */
    public function __construct(TerminalState $state, array $config)
    {
        $this->state = $state;
        $this->config = $config;
    }

    /**
     * Processes a chunk of data from the terminal output stream.
     *
     * @param string $chunk The chunk of data to process.
     * @param float $time The timestamp of this chunk.
     */
    public function processChunk(string $chunk, float $time): void
    {
        $this->currentTime = $time;
        static $state = self::STATE_GROUND;
        static $params = '';
        static $isDecPrivate = false;

        $characters = mb_str_split($chunk, 1, 'UTF-8');
        $charCount = count($characters);

        for ($i = 0; $i < $charCount; $i++) {
            $char = $characters[$i];

            switch ($state) {
                case self::STATE_GROUND:
                    if ($char === "\x1b") {
                        $state = self::STATE_ESCAPE;
                    } else {
                        $this->handleCharacter($char);
                    }
                    break;
                case self::STATE_ESCAPE:
                    if ($char === '[') {
                        $params = '';
                        $isDecPrivate = false;
                        $state = self::STATE_CSI_PARAM;
                    } elseif ($char === ']') {
                        $this->oscBuffer = '';
                        $state = self::STATE_OSC_STRING;
                    } elseif ($char === '(') {
                        $state = self::STATE_CHARSET;
                    } elseif ($char === 'D') {
                        $this->moveCursorDownAndScroll();
                        $this->state->recordCursorState($this->currentTime);
                        $state = self::STATE_GROUND;
                    } elseif ($char === 'M') {
                        $this->state->cursorY--;
                        if ($this->state->cursorY < $this->state->scrollTop) {
                            $this->doScrollDown(1);
                            $this->state->cursorY = $this->state->scrollTop;
                        }
                        $this->state->recordCursorState($this->currentTime);
                        $state = self::STATE_GROUND;
                    } elseif ($char === 'E') {
                        $this->state->cursorX = 0;
                        $this->moveCursorDownAndScroll();
                        $this->state->recordCursorState($this->currentTime);
                        $state = self::STATE_GROUND;
                    } elseif ($char === '7') {
                        $this->state->savedCursorX = $this->state->cursorX;
                        $this->state->savedCursorY = $this->state->cursorY;
                        $this->state->savedStyle = $this->state->currentStyle;
                        $state = self::STATE_GROUND;
                    } elseif ($char === '8') {
                        $this->state->cursorX = $this->state->savedCursorX;
                        $this->state->cursorY = $this->state->savedCursorY;
                        $this->state->currentStyle = $this->state->savedStyle;
                        $this->state->recordCursorState($this->currentTime);
                        $state = self::STATE_GROUND;
                    } else {
                        if (!in_array($char, self::WONT_IMPLEMENT_ESC)) {
                            $this->logWarning("Unsupported escape sequence: ESC {$char}");
                        }
                        $state = self::STATE_GROUND;
                    }
                    break;
                case self::STATE_CHARSET:
                    $state = self::STATE_GROUND;
                    break;
                case self::STATE_OSC_STRING:
                    if ($char === "\x07") {
                        $this->handleOscCommand($this->oscBuffer);
                        $state = self::STATE_GROUND;
                    } elseif ($char === "\x1b" && ($i + 1 < $charCount) && $characters[$i + 1] === '\\') {
                        $this->handleOscCommand($this->oscBuffer);
                        $i++; // Consume the backslash as well
                        $state = self::STATE_GROUND;
                    } else {
                        $this->oscBuffer .= $char;
                    }
                    break;
                case self::STATE_CSI_PARAM:
                    if ($params === '' && $char === '?') {
                        $isDecPrivate = true;
                        continue 2;
                    }

                    if (ctype_digit($char) || $char === ';') {
                        $params .= $char;
                    } else {
                        if ($isDecPrivate) {
                            $this->handleDecPrivateMode($params . $char);
                        } else {
                            $paramArray = ($params === '') ? [] : explode(';', $params);
                            $this->handleAnsiCommand($char, $paramArray);
                        }
                        $state = self::STATE_GROUND;
                    }
                    break;
            }
        }
    }

    private function moveCursorDownAndScroll(): void
    {
        if ($this->state->cursorY === $this->state->scrollBottom) {
            if ($this->state->scrollBottom === $this->config['rows'] - 1 && $this->state->scrollTop === 0) {
                $this->doStreamScroll(1);
            } else {
                $this->doScrollUp(1);
            }
        } else {
            $this->state->cursorY++;
        }
    }

    private function handleCharacter(string $char): void
    {
        switch ($char) {
            case "\r":
                $this->state->cursorX = 0;
                break;
            case "\n":
                $this->moveCursorDownAndScroll();
                break;
            case "\x08":
            case "\x7f":
                $this->state->cursorX = max(0, $this->state->cursorX - 1);
                break;
            case "\t":
                $this->state->cursorX = min($this->config['cols'] - 1, (int)($this->state->cursorX / 8 + 1) * 8);
                break;
            default:
                if (mb_check_encoding($char, 'UTF-8') && preg_match('/[[:print:]]/u', $char)) {
                    if ($this->state->cursorX >= $this->config['cols']) {
                        if ($this->state->autoWrapMode) {
                            $this->state->cursorX = 0;
                            $this->moveCursorDownAndScroll();
                        } else {
                            $this->state->cursorX = $this->config['cols'] - 1;
                        }
                    }

                    $this->writeCharToHistory($char);

                    if ($this->state->autoWrapMode) {
                        $this->state->cursorX++;
                    } else {
                        if ($this->state->cursorX < $this->config['cols'] - 1) {
                            $this->state->cursorX++;
                        }
                    }
                }
                break;
        }
        $this->state->recordCursorState($this->currentTime);
    }

    private function handleOscCommand(string $sequence): void
    {
        $parts = explode(';', $sequence);
        $command = (int)array_shift($parts);

        if ($command === 8) {
            $uri = end($parts);
            $this->state->currentStyle['link'] = $uri ?: null;
        } elseif (in_array($command, self::WONT_IMPLEMENT_OSC)) {
            $this->logWarning("Unsupported OSC command: {$command}");
        }
    }


    private function handleAnsiCommand(string $command, array $params): void
    {
        $p = array_map('intval', $params);
        $moved = false;
        switch ($command) {
            case 'm':
                $this->setGraphicsMode($params);
                break;
            case 'H':
            case 'f':
                $this->state->cursorY = max(0, ($p[0] ?? 1) - 1);
                $this->state->cursorX = max(0, ($p[1] ?? 1) - 1);
                $moved = true;
                break;
            case 'A':
                $this->state->cursorY = max(0, $this->state->cursorY - ($p[0] ?? 1));
                $moved = true;
                break;
            case 'B':
                $this->state->cursorY = min($this->config['rows'] - 1, $this->state->cursorY + ($p[0] ?? 1));
                $moved = true;
                break;
            case 'C':
                $this->state->cursorX = min($this->config['cols'] - 1, $this->state->cursorX + ($p[0] ?? 1));
                $moved = true;
                break;
            case 'D':
                $this->state->cursorX = max(0, $this->state->cursorX - ($p[0] ?? 1));
                $moved = true;
                break;
            case 'G':
                $this->state->cursorX = max(0, ($p[0] ?? 1) - 1);
                $moved = true;
                break;
            case 'd':
                $this->state->cursorY = max(0, ($p[0] ?? 1) - 1);
                $moved = true;
                break;
            case 'J':
                $this->eraseInDisplay($p[0] ?? 0);
                break;
            case 'K':
                $this->eraseInLine($p[0] ?? 0);
                break;
            case 'X':
                $this->eraseCharacters($p[0] ?? 1);
                break;
            case '@':
                $this->insertCharacters($p[0] ?? 1);
                break;
            case 'P':
                $this->deleteCharacters($p[0] ?? 1);
                break;
            case 'r':
                $this->setScrollRegion($p);
                break;
            case 'L':
                $this->insertLines($p[0] ?? 1);
                break;
            case 'M':
                $this->deleteLines($p[0] ?? 1);
                break;
            case 'S':
                $this->doScrollUp($p[0] ?? 1);
                break;
            case 'T':
                $this->doScrollDown($p[0] ?? 1);
                break;
            case 's':
                $this->state->savedCursorX = $this->state->cursorX;
                $this->state->savedCursorY = $this->state->cursorY;
                break;
            case 'u':
                $this->state->cursorX = $this->state->savedCursorX;
                $this->state->cursorY = $this->state->savedCursorY;
                break;
            default:
                if (!in_array($command, self::WONT_IMPLEMENT_CSI)) {
                    $this->logWarning("Unsupported CSI command: '" . implode(';', $params) . "{$command}'");
                }
                break;
        }

        if ($moved) {
            $this->state->recordCursorState($this->currentTime);
        }
    }

    private function handleDecPrivateMode(string $command): void
    {
        if ($command === '1049h') {
            $this->state->savedCursorX = $this->state->cursorX;
            $this->state->savedCursorY = $this->state->cursorY;

            $this->state->altScreenActive = true;
            $this->state->screenSwitchEvents[] = ['time' => $this->currentTime, 'type' => 'to_alt'];

            $this->state->cursorX = 0;
            $this->state->cursorY = 0;
            $this->state->recordCursorState($this->currentTime);
            $this->setScrollRegion([]);
        } elseif ($command === '1049l') {
            $this->state->altScreenActive = false;
            $this->state->screenSwitchEvents[] = ['time' => $this->currentTime, 'type' => 'to_main'];

            $this->setScrollRegion([]);

            $this->state->cursorX = $this->state->savedCursorX;
            $this->state->cursorY = $this->state->savedCursorY;
            $this->state->recordCursorState($this->currentTime);
        } elseif ($command === '25l') {
            $this->state->setCursorVisibility(false, $this->currentTime);
        } elseif ($command === '25h') {
            $this->state->setCursorVisibility(true, $this->currentTime);
        } elseif ($command === '7h') {
            $this->state->autoWrapMode = true;
        } elseif ($command === '7l') {
            $this->state->autoWrapMode = false;
        } else {
            if (!in_array($command, self::WONT_IMPLEMENT_DEC)) {
                $this->logWarning("Unsupported DEC Private Mode command: ?{$command}");
            }
        }
    }

    private function writeCharToHistory(string $char): void
    {
        $this->writeCharToHistoryAt($this->state->cursorX, $this->state->cursorY, htmlspecialchars($char, ENT_XML1), $this->state->currentStyle);
    }

    private function writeCharToHistoryAt(int $x, int $y, string $char, array $style): void
    {
        $buffer = &$this->state->getActiveBuffer();
        $scrollOffset = $this->state->altScreenActive ? $this->state->altScrollOffset : $this->state->mainScrollOffset;
        $absoluteY = $y + $scrollOffset;

        if (isset($buffer[$absoluteY][$x])) {
            $lastIndex = count($buffer[$absoluteY][$x]) - 1;
            if ($lastIndex >= 0 && !isset($buffer[$absoluteY][$x][$lastIndex]['endTime'])) {
                $buffer[$absoluteY][$x][$lastIndex]['endTime'] = $this->currentTime;
            }
        }
        $buffer[$absoluteY][$x][] = [
            'char'      => $char,
            'style'     => $style,
            'startTime' => $this->currentTime,
        ];
    }

    private function setGraphicsMode(array $params): void
    {
        if (empty($params)) {
            $params = [0];
        }

        $i = 0;
        while ($i < count($params)) {
            $p = intval($params[$i]);
            $handled = false;

            if ($p === 0) {
                $this->state->resetStyle();
                $handled = true;
            } elseif ($p === 1) {
                $this->state->currentStyle['bold'] = true;
                $handled = true;
            } elseif ($p === 2) {
                $this->state->currentStyle['dim'] = true;
                $handled = true;
            } elseif ($p === 3) {
                $this->state->currentStyle['italic'] = true;
                $handled = true;
            } elseif ($p === 4) {
                $this->state->currentStyle['underline'] = true;
                $handled = true;
            } elseif ($p === 7) {
                $this->state->currentStyle['inverse'] = true;
                $handled = true;
            } elseif ($p === 8) {
                $this->state->currentStyle['invisible'] = true;
                $handled = true;
            } elseif ($p === 9) {
                $this->state->currentStyle['strikethrough'] = true;
                $handled = true;
            } elseif ($p === 22) {
                $this->state->currentStyle['bold'] = false;
                $this->state->currentStyle['dim'] = false;
                $handled = true;
            } elseif ($p === 23) {
                $this->state->currentStyle['italic'] = false;
                $handled = true;
            } elseif ($p === 24) {
                $this->state->currentStyle['underline'] = false;
                $handled = true;
            } elseif ($p === 27) {
                $this->state->currentStyle['inverse'] = false;
                $handled = true;
            } elseif ($p === 28) {
                $this->state->currentStyle['invisible'] = false;
                $handled = true;
            } elseif ($p === 29) {
                $this->state->currentStyle['strikethrough'] = false;
                $handled = true;
            } elseif (array_key_exists($p, self::ANSI_16_COLORS)) {
                $this->state->currentStyle['fg'] = 'fg-' . $p;
                $this->state->currentStyle['fg_hex'] = null;
                $handled = true;
            } elseif (array_key_exists($p - 10, self::ANSI_16_COLORS)) {
                $this->state->currentStyle['bg'] = 'bg-' . $p;
                $this->state->currentStyle['bg_hex'] = null;
                $handled = true;
            } elseif ($p === 39) {
                $this->state->currentStyle['fg'] = 'fg-default';
                $this->state->currentStyle['fg_hex'] = null;
                $handled = true;
            } elseif ($p === 49) {
                $this->state->currentStyle['bg'] = 'bg-default';
                $this->state->currentStyle['bg_hex'] = null;
                $handled = true;
            } elseif ($p === 38 || $p === 48) {
                $colorType = ($p === 38) ? 'fg' : 'bg';
                if (isset($params[$i + 1]) && intval($params[$i + 1]) === 5 && isset($params[$i + 2])) {
                    $colorCode = intval($params[$i + 2]);
                    $this->state->currentStyle[$colorType . '_hex'] = $this->mapAnsi256ToHex($colorCode);
                    $this->state->currentStyle[$colorType] = 'fg-default';
                    $i += 2;
                    $handled = true;
                } elseif (isset($params[$i + 1]) && intval($params[$i + 1]) === 2) {
                    if (isset($params[$i + 2]) && isset($params[$i + 3]) && isset($params[$i + 4])) {
                        $this->state->currentStyle[$colorType . '_hex'] = sprintf("#%02x%02x%02x", intval($params[$i + 2]), intval($params[$i + 3]), intval($params[$i + 4]));
                        $i += 4;
                        $handled = true;
                    }
                }
            }

            if (!$handled) {
                $this->logWarning("Unsupported SGR parameter: {$p}");
            }
            $i++;
        }
    }

    private function mapAnsi256ToHex(int $code): string
    {
        if ($code < 8) {
            return self::ANSI_16_COLORS[$code + 30];
        }
        if ($code < 16) {
            return self::ANSI_16_COLORS[$code - 8 + 90];
        }
        if ($code >= 16 && $code <= 231) {
            $code -= 16;
            $r = floor($code / 36);
            $g = floor(($code % 36) / 6);
            $b = $code % 6;
            $levels = [0, 95, 135, 175, 215, 255];
            return sprintf("#%02x%02x%02x", $levels[$r], $levels[$g], $levels[$b]);
        }
        if ($code >= 232 && $code <= 255) {
            $level = ($code - 232) * 10 + 8;
            return sprintf("#%02x%02x%02x", $level, $level, $level);
        }
        return $this->config['default_fg'];
    }

    private function eraseInDisplay(int $mode): void
    {
        $scrollOffset = $this->state->altScreenActive ? $this->state->altScrollOffset : $this->state->mainScrollOffset;
        if ($mode === 0) { // From cursor to end of screen
            $this->eraseInLine(0);
            for ($y = $this->state->cursorY + 1; $y <= $this->state->scrollBottom; $y++) {
                $this->endLifespanForLine($y + $scrollOffset, 0);
            }
        } elseif ($mode === 1) { // From start of screen to cursor
            for ($y = $this->state->scrollTop; $y < $this->state->cursorY; $y++) {
                $this->endLifespanForLine($y + $scrollOffset, 0);
            }
            $this->eraseInLine(1);
        } elseif ($mode === 2 || $mode === 3) { // Entire screen
            for ($y = $this->state->scrollTop; $y <= $this->state->scrollBottom; $y++) {
                $this->endLifespanForLine($y + $scrollOffset, 0);
            }
        }
    }

    private function eraseInLine(int $mode): void
    {
        $scrollOffset = $this->state->altScreenActive ? $this->state->altScrollOffset : $this->state->mainScrollOffset;
        $y = $this->state->cursorY + $scrollOffset;
        $startX = 0;
        $count = $this->config['cols'];

        if ($mode === 0) { // From cursor to end of line
            $startX = $this->state->cursorX;
            $count = $this->config['cols'] - $startX;
        } elseif ($mode === 1) { // From start of line to cursor
            $startX = 0;
            $count = $this->state->cursorX + 1;
        }

        $this->endLifespanForLine($y, $startX, $count);
    }

    private function eraseCharacters(int $n = 1): void
    {
        $n = max(1, $n);
        $scrollOffset = $this->state->altScreenActive ? $this->state->altScrollOffset : $this->state->mainScrollOffset;
        $y = $this->state->cursorY + $scrollOffset;
        $this->endLifespanForLine($y, $this->state->cursorX, $n);
    }

    private function deleteCharacters(int $n = 1): void
    {
        $n = max(1, $n);
        $buffer = &$this->state->getActiveBuffer();
        $scrollOffset = $this->state->altScreenActive ? $this->state->altScrollOffset : $this->state->mainScrollOffset;
        $y = $this->state->cursorY + $scrollOffset;
        $x_start = $this->state->cursorX;
        $cols = $this->config['cols'];

        if (!isset($buffer[$y])) {
            return;
        }

        // Shift characters from right to left
        for ($x = $x_start; $x < $cols; $x++) {
            $x_source = $x + $n;
            $this->endLifespanForLine($y, $x, 1);
            if ($x_source < $cols && isset($buffer[$y][$x_source]) && !empty($buffer[$y][$x_source])) {
                $lastIndex = count($buffer[$y][$x_source]) - 1;
                $cellToMove = $buffer[$y][$x_source][$lastIndex];
                if (!isset($cellToMove['endTime']) || $cellToMove['endTime'] > $this->currentTime) {
                    $buffer[$y][$x][] = [
                        'char' => $cellToMove['char'],
                        'style' => $cellToMove['style'],
                        'startTime' => $this->currentTime,
                    ];
                }
            }
        }
    }

    private function insertCharacters(int $n = 1): void
    {
        $n = max(1, $n);
        $buffer = &$this->state->getActiveBuffer();
        $scrollOffset = $this->state->altScreenActive ? $this->state->altScrollOffset : $this->state->mainScrollOffset;
        $y = $this->state->cursorY + $scrollOffset;
        $x_start = $this->state->cursorX;
        $cols = $this->config['cols'];

        if (!isset($buffer[$y])) {
            return;
        }

        // Shift characters to the right
        for ($x = $cols - 1; $x >= $x_start + $n; $x--) {
            $x_source = $x - $n;
            if (isset($buffer[$y][$x_source]) && !empty($buffer[$y][$x_source])) {
                $this->endLifespanForLine($y, $x, 1);
                $lastIndex = count($buffer[$y][$x_source]) - 1;
                $cellToMove = $buffer[$y][$x_source][$lastIndex];
                if (!isset($cellToMove['endTime']) || $cellToMove['endTime'] > $this->currentTime) {
                    $buffer[$y][$x][] = [
                        'char' => $cellToMove['char'],
                        'style' => $cellToMove['style'],
                        'startTime' => $this->currentTime,
                    ];
                }
            } else {
                // If the source is empty, ensure the destination is also empty
                $this->endLifespanForLine($y, $x, 1);
            }
        }

        // Clear the space that was opened up
        $this->endLifespanForLine($y, $x_start, $n);
    }

    private function setScrollRegion(array $params): void
    {
        $top = (isset($params[0]) && $params[0] > 0) ? $params[0] - 1 : 0;
        $bottom = (isset($params[1]) && $params[1] > 0) ? $params[1] - 1 : $this->config['rows'] - 1;

        if ($top < $bottom) {
            $this->state->scrollTop = $top;
            $this->state->scrollBottom = $bottom;
        } else {
            $this->state->scrollTop = 0;
            $this->state->scrollBottom = $this->config['rows'] - 1;
        }
        $this->state->cursorX = 0;
        $this->state->cursorY = 0;
        $this->state->recordCursorState($this->currentTime);
    }

    private function insertLines(int $n): void
    {
        $buffer = &$this->state->getActiveBuffer();
        $scrollOffset = $this->state->altScreenActive ? $this->state->altScrollOffset : $this->state->mainScrollOffset;

        if ($this->state->cursorY < $this->state->scrollTop || $this->state->cursorY > $this->state->scrollBottom) {
            return;
        }

        // End lifespan for lines that are pushed out of the scroll region
        for ($i = 0; $i < $n; $i++) {
            $y_to_kill = $this->state->scrollBottom - $i;
            if ($y_to_kill >= $this->state->cursorY) {
                $this->endLifespanForLine($y_to_kill + $scrollOffset, 0);
            }
        }

        // Shift existing lines down
        for ($y = $this->state->scrollBottom; $y >= $this->state->cursorY + $n; $y--) {
            $src_y = $y - $n + $scrollOffset;
            $dest_y = $y + $scrollOffset;
            $buffer[$dest_y] = $buffer[$src_y] ?? [];
        }

        // Insert new empty lines
        for ($y = $this->state->cursorY; $y < $this->state->cursorY + $n; $y++) {
            $absY = $y + $scrollOffset;
            $buffer[$absY] = [];
        }
    }

    private function deleteLines(int $n): void
    {
        $buffer = &$this->state->getActiveBuffer();
        $scrollOffset = $this->state->altScreenActive ? $this->state->altScrollOffset : $this->state->mainScrollOffset;

        if ($this->state->cursorY < $this->state->scrollTop || $this->state->cursorY > $this->state->scrollBottom) {
            return;
        }

        // End lifespan for the deleted lines
        for ($i = 0; $i < $n; $i++) {
            $this->endLifespanForLine($this->state->cursorY + $i + $scrollOffset, 0);
        }

        // Shift subsequent lines up
        for ($y = $this->state->cursorY; $y <= $this->state->scrollBottom - $n; $y++) {
            $src_y = $y + $n + $scrollOffset;
            $dest_y = $y + $scrollOffset;
            $buffer[$dest_y] = $buffer[$src_y] ?? [];
        }

        // Add new empty lines at the bottom of the scroll region
        for ($y = $this->state->scrollBottom - $n + 1; $y <= $this->state->scrollBottom; $y++) {
            $absY = $y + $scrollOffset;
            $buffer[$absY] = [];
        }
    }

    private function doScrollUp(int $n): void
    {
        $scrollOffset = $this->state->altScreenActive ? $this->state->altScrollOffset : $this->state->mainScrollOffset;

        for ($i = 0; $i < $n; $i++) {
            // Take a snapshot of the region *before* modification
            $regionSnapshot = [];
            for ($y = $this->state->scrollTop; $y <= $this->state->scrollBottom; $y++) {
                $absY = $y + $scrollOffset;
                $regionSnapshot[$y] = $this->state->getActiveBuffer()[$absY] ?? [];
            }

            // End the lifespan of everything currently in the scroll region
            for ($y = $this->state->scrollTop; $y <= $this->state->scrollBottom; $y++) {
                $this->endLifespanForLine($y + $scrollOffset, 0);
            }

            // Shift lines up using the snapshot
            for ($y = $this->state->scrollTop; $y < $this->state->scrollBottom; $y++) {
                $sourceRow = $regionSnapshot[$y + 1] ?? [];
                $destY = $y;
                foreach ($sourceRow as $x => $lifespans) {
                    if (empty($lifespans)) {
                        continue;
                    }
                    $cell = end($lifespans);
                    // If cell was active, write it to its new position
                    if (!isset($cell['endTime']) || $cell['endTime'] > $this->currentTime) {
                        $this->writeCharToHistoryAt($x, $destY, $cell['char'], $cell['style']);
                    }
                }
            }
        }
    }


    private function doScrollDown(int $n): void
    {
        $buffer = &$this->state->getActiveBuffer();
        $scrollOffset = $this->state->altScreenActive ? $this->state->altScrollOffset : $this->state->mainScrollOffset;

        for ($i = 0; $i < $n; $i++) {
            // End lifespan for the line at the bottom of the region
            $this->endLifespanForLine($this->state->scrollBottom + $scrollOffset, 0);

            // Shift lines down
            for ($y = $this->state->scrollBottom; $y > $this->state->scrollTop; $y--) {
                $src_y = $y - 1 + $scrollOffset;
                $dest_y = $y + $scrollOffset;
                $buffer[$dest_y] = $buffer[$src_y] ?? [];
            }

            // The top line of the scroll region is now a new, empty line
            $top_y = $this->state->scrollTop;
            $buffer[$top_y + $scrollOffset] = [];
        }
    }

    private function doStreamScroll(int $n = 1): void
    {
        $scrollOffsetRef = &$this->state->getActiveScrollOffsetRef();
        $scrollEventsRef = &$this->state->getActiveScrollEventsRef();

        for ($i = 0; $i < $n; $i++) {
            $this->endLifespanForLine($scrollOffsetRef, 0);
            $scrollEventsRef[] = ['time' => $this->currentTime, 'offset' => $scrollOffsetRef];
            $scrollOffsetRef++;
        }
    }

    private function endLifespanForLine(int $y, int $startX, ?int $count = null): void
    {
        $buffer = &$this->state->getActiveBuffer();
        if (!isset($buffer[$y])) {
            return;
        }
        $endX = $count !== null ? $startX + $count : $this->config['cols'];
        for ($x = $startX; $x < $endX; $x++) {
            if (isset($buffer[$y][$x]) && !empty($buffer[$y][$x])) {
                $lastIndex = count($buffer[$y][$x]) - 1;
                if (!isset($buffer[$y][$x][$lastIndex]['endTime'])) {
                    $buffer[$y][$x][$lastIndex]['endTime'] = $this->currentTime;
                }
            }
        }
    }

    private function logWarning(string $message): void
    {
        fwrite(STDERR, "⚠️  Warning: {$message}\n");
    }
}
