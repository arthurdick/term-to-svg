<?php

declare(strict_types=1);

namespace ArthurDick\TermToSvg;

/**
 * Holds the default configuration options for the SVG converter.
 *
 * This class contains a constant array with default values for terminal
 * dimensions, fonts, and colors, which can be overridden by the user.
 */
class Config
{
    /**
     * @var array<string, mixed> Default configuration values.
     */
    public const DEFAULTS = [
        'id' => null,
        'generator' => 'css',
        'rows' => 24,
        'cols' => 80,
        'font_size' => 14,
        'line_height_factor' => 1.2,
        'font_width_factor' => 0.6,
        'font_family' => 'Menlo, Monaco, "Courier New", monospace',
        'default_fg' => '#e0e0e0', // Default text color
        'default_bg' => '#1a1a1a', // Terminal background color
        'animation_pause_seconds' => 5,
        'interactive' => false,
        'poster_at' => null,
        'theme' => null,
        'ansi_16_colors' => [
            30 => '#2e3436', 31 => '#cc0000', 32 => '#4e9a06', 33 => '#c4a000',
            34 => '#3465a4', 35 => '#75507b', 36 => '#06989a', 37 => '#d3d7cf',
            90 => '#555753', 91 => '#ef2929', 92 => '#8ae234', 93 => '#fce94f',
            94 => '#729fcf', 95 => '#ad7fa8', 96 => '#34e2e2', 97 => '#eeeeec',
        ]
    ];

    public static function loadTheme(string $themePath, array &$config): void
    {
        if (!file_exists($themePath) || !is_readable($themePath)) {
            throw new \RuntimeException("Theme file not found or is not readable: {$themePath}");
        }

        $themeConfig = json_decode(file_get_contents($themePath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Error parsing theme file: " . json_last_error_msg());
        }

        $config = array_merge($config, $themeConfig);
    }
}
