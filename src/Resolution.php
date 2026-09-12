<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/**
 * Result of resolving a variable expression.
 *
 * Distinguishes "resolved to null" from "does not resolve at all", which is what lets
 * strict mode report an unknown variable instead of silently rendering nothing.
 *
 * $failedAt names the segment the walk stopped on, so a missing `b` in `{{var a.b.c}}` is
 * reported as `b` rather than as the whole expression. Left empty, the error names the
 * expression instead.
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
