<?php

namespace ArthurDick\TermToSvg;

class Config
{
    public const DEFAULTS = [
        'rows' => 24,
        'cols' => 80,
        'font_size' => 14,
        'line_height_factor' => 1.2,
        'font_family' => 'Menlo, Monaco, "Courier New", monospace',
        'default_fg' => '#e0e0e0', // Default text color
        'default_bg' => '#1a1a1a', // Terminal background color
    ];
}
