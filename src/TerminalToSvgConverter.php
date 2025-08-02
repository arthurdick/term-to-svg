<?php

namespace ArthurDick\TermToSvg;

class TerminalToSvgConverter
{
    public const VERSION = '3.0.0';
    private $typescriptHandle;
    private array $timingData;
    private array $config;
    private TerminalState $state;
    private AnsiParser $parser;
    private float $currentTime = 0.0;

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
        return $generator->generate();
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
