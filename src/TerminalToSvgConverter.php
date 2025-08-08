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
    public const VERSION = '5.0.0-dev';

    /** @var resource|false The file handle for the typescript recording. */
    private $typescriptHandle;

    /** @var array<int, array<string, mixed>> The parsed timing data. */
    private array $timingData;

    /** @var array<string, mixed> The configuration for the conversion. */
    private array $config;
    private TerminalState $state;
    private AnsiParser $parser;
    private float $currentTime = 0.0;
    private SvgGeneratorInterface $generator;

    /**
     * @param string $typescriptPath Path to the typescript file.
     * @param string $timingPath Path to the timing file.
     * @param array<string, mixed> $config The configuration array.
     */
    public function __construct(string $typescriptPath, string $timingPath, array $config)
    {
        $this->config = array_merge(Config::DEFAULTS, $config);
        $this->state = new TerminalState($this->config);
        $this->parser = new AnsiParser($this->state, $this->config);

        if (!($this->typescriptHandle = @fopen($typescriptPath, 'r'))) {
            throw new \RuntimeException("Cannot open typescript file: {$typescriptPath}");
        }

        $firstLine = fgets($this->typescriptHandle);
        if ($firstLine && preg_match('/COLUMNS="(\d+)".*?LINES="(\d+)"/', $firstLine, $matches)) {
            $this->config['cols'] = (int)$matches[1];
            $this->config['rows'] = (int)$matches[2];
        }

        $this->timingData = $this->parseTimingFile($timingPath);

        $this->processRecording();

        $generatorClass = $this->config['generator'] === 'css' ? CssSvgGenerator::class : SmilSvgGenerator::class;
        $this->generator = new $generatorClass($this->state, $this->config, $this->currentTime);
    }

    public function __destruct()
    {
        if ($this->typescriptHandle) {
            fclose($this->typescriptHandle);
        }
    }

    private function processRecording(): void
    {
        $this->state->cursorEvents[] = ['time' => 0.0, 'x' => $this->state->cursorX, 'y' => $this->state->cursorY, 'visible' => $this->state->cursorVisible];

        foreach ($this->timingData as $timingLine) {
            $this->currentTime += $timingLine['delay'];
            if ($timingLine['bytes'] > 0) {
                $chunk = fread($this->typescriptHandle, $timingLine['bytes']);
                $this->parser->processChunk($chunk, $this->currentTime);
            }
        }
    }

    /**
     * Starts the conversion process and returns the final SVG content.
     *
     * @return string The animated SVG content.
     */
    public function convert(): string
    {
        if ($this->config['poster_at'] !== null) {
            $time = $this->config['poster_at'] === 'end' ? $this->currentTime : (float)$this->config['poster_at'];
            return $this->generator->generatePoster($time);
        }

        return $this->generator->generate();
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
