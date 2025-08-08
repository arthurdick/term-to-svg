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
        'generator' => 'smil',
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
    ];
}
