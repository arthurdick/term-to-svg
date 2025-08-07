<?php

declare(strict_types=1);

namespace ArthurDick\TermToSvg;

/**
 * Main orchestrator for converting terminal recordings to animated SVG.
 *
 * This class coordinates the process:
 * 1. Reads the typescript and timing files.
 * 2. Initializes the terminal state and parser.
 * 3. Processes the recording chunk by chunk.
 * 4. Triggers the final SVG generation.
 */
class TerminalToSvgConverter
{
    /** @var string The current version of the tool. */
    public const VERSION = '4.3.1';

    /** @var resource|false The file handle for the typescript recording. */
    private $typescriptHandle;

    /** @var array<int, array<string, mixed>> The parsed timing data. */
    private array $timingData;

    /** @var array<string, mixed> The configuration for the conversion. */
    private array $config;

    private TerminalState $state;
    private AnsiParser $parser;
    private float $currentTime = 0.0;

    /**
     * @param string $typescriptPath Path to the typescript file.
     * @param string $timingPath Path to the timing file.
     * @param array<string, mixed> $config The configuration array.
     */
    public function __construct(string $typescriptPath, string $timingPath, array $config)
    {
        $this->config = $config;
        $this->state = new TerminalState($this->config);
        $this->parser = new AnsiParser($this->state, $this->config);
        $this->typescriptHandle = @fopen($typescriptPath, 'r');
        if (!$this->typescriptHandle) {
            return;
        }

        $firstLine = fgets($this->typescriptHandle);
        if ($firstLine && preg_match('/COLUMNS="(\d+)".*?LINES="(\d+)"/', $firstLine, $matches)) {
            $this->config['cols'] = (int)$matches[1];
            $this->config['rows'] = (int)$matches[2];
        }

        $this->timingData = $this->parseTimingFile($timingPath);
    }

    public function __destruct()
    {
        if ($this->typescriptHandle) {
            fclose($this->typescriptHandle);
        }
    }

    /**
     * Starts the conversion process and returns the final SVG content.
     *
     * @return string The animated SVG content.
     */
    public function convert(): string
    {
        $this->state->cursorEvents[] = ['time' => 0.0, 'x' => $this->state->cursorX, 'y' => $this->state->cursorY, 'visible' => $this->state->cursorVisible];

        while (($timingLine = array_shift($this->timingData)) !== null) {
            $this->currentTime += $timingLine['delay'];
            if ($timingLine['bytes'] > 0 && $this->typescriptHandle) {
                $chunk = fread($this->typescriptHandle, $timingLine['bytes']);
                $this->parser->processChunk($chunk, $this->currentTime);
            }
        }

        $generator = new SvgGenerator($this->state, $this->config, $this->currentTime);
        if ($this->config['poster_at'] !== null) {
            $time = $this->config['poster_at'] === 'end' ? $this->currentTime : (float)$this->config['poster_at'];
            return $generator->generatePoster($time);
        } else {
            return $generator->generate();
        }
    }

    private function parseTimingFile(string $path): array
    {
        if (!file_exists($path) || !is_readable($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $data = [];
        foreach ($lines as $line) {
            $parts = explode(' ', $line, 2);
            if (count($parts) === 2) {
                list($delay, $bytes) = $parts;
                $data[] = ['delay' => (float)$delay, 'bytes' => (int)$bytes];
            }
        }
        return $data;
    }
}
