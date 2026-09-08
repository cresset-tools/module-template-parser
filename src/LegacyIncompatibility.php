<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

/**
 * A construct this engine renders but the legacy filter cannot.
 *
 * Compatible mode refuses these by default, because compatible means bug-for-bug and an
 * engine that renders what the old one crashes on is not compatible with it.
 *
 * With Options::withRefuseLegacyIncompatible(false) the construct renders and is recorded
 * here instead - useful when you want the improvement but still need to know which templates
 * have stopped being runnable on the legacy filter.
 */
final class LegacyIncompatibility
{
    public const SAME_NAME_NESTING = 'same_name_nesting';
    public const NESTING_DEPTH = 'nesting_depth';
    public const DEGENERATE_CONSTRUCT = 'degenerate_construct';
    public const STRAY_CLOSING_TAG = 'stray_closing_tag';
    public const UNCLOSED_BLOCK = 'unclosed_block';
    public const NAME_PREFIX_SPLIT = 'name_prefix_split';
    public const PADDED_CLOSING_TAG = 'padded_closing_tag';
    public const MODIFIER_ARGUMENTS = 'modifier_arguments';
    public const MEMBER_ON_ARRAY = 'member_on_array';

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
