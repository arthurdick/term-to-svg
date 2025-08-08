<?php

declare(strict_types=1);

namespace ArthurDick\TermToSvg;

/**
 * Interface for SVG generator classes.
 */
interface SvgGeneratorInterface
{
    /**
     * Renders the complete animated SVG string.
     *
     * @return string The generated SVG content.
     */
    public function generate(): string;

    /**
     * Renders a non-animated SVG of the terminal at a specific time.
     *
     * @param float $time The time at which to capture the poster frame.
     * @return string The generated SVG content.
     */
    public function generatePoster(float $time): string;
}
