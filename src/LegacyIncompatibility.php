<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

/**
 * A construct this engine renders but the legacy filter cannot.
 *
 * Recorded rather than refused. A template using one of these is not broken - it is broken
 * *on legacy*, where it raises a TypeError and the mail never goes out. Rendering it is an
 * improvement; the reason to surface it is that such a template no longer runs on the old
 * engine, so a rollback would stop working.
 */
final class LegacyIncompatibility
{
    public const SAME_NAME_NESTING = 'same_name_nesting';
    public const NESTING_DEPTH = 'nesting_depth';

    public function __construct(
        public readonly string $kind,
        public readonly string $message,
        public readonly int $offset,
        public readonly int $line,
        public readonly int $column
    ) {
    }

    public static function at(string $source, int $offset, string $kind, string $message): self
    {
        ['line' => $line, 'column' => $column] = Diagnostics::locate($source, $offset);
        return new self($kind, $message, $offset, $line, $column);
    }

    public function describe(): string
    {
        return sprintf('%s (line %d, column %d)', $this->message, $this->line, $this->column);
    }
}
