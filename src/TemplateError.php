<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

/**
 * Base for every error the engine reports against template source.
 *
 * Carries the position and a rendered excerpt so the message can be shown directly to
 * whoever is editing the template, rather than being a stack trace.
 */
class TemplateError extends \RuntimeException
{
    public function __construct(
        public readonly string $problem,
        public readonly int $offset,
        public readonly int $sourceLine,
        public readonly int $sourceColumn,
        public readonly string $excerpt,
        public readonly ?string $hint = null
    ) {
        $message = sprintf("%s\n  on line %d, column %d:\n\n%s", $problem, $sourceLine, $sourceColumn, $excerpt);
        if ($hint !== null) {
            $message .= "\n\n  hint: " . $hint;
        }
        parent::__construct($message);
    }

    public static function at(string $source, int $offset, string $problem, ?string $hint = null): static
    {
        ['line' => $line, 'column' => $column] = Diagnostics::locate($source, $offset);

        return new static(
            $problem,
            $offset,
            $line,
            $column,
            Diagnostics::excerpt($source, $offset),
            $hint
        );
    }
}
