<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

/**
 * Result of resolving a variable expression.
 *
 * Distinguishes "resolved to null" from "does not resolve at all", which is what lets
 * strict mode report an unknown variable instead of silently rendering nothing.
 */
final class Resolution
{
    private function __construct(
        public readonly bool $found,
        public readonly mixed $value,
        public readonly string $failedAt = ''
    ) {
    }

    public static function of(mixed $value): self
    {
        return new self(true, $value);
    }

    public static function missing(string $failedAt = ''): self
    {
        return new self(false, null, $failedAt);
    }
}
