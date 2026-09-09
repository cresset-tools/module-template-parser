<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

use Cresset\TemplateParser\Options;

/**
 * The three engine postures, as a CLI-facing choice.
 *
 * `legacy` is accepted as a spelling of `compatible`, because that is what people call it
 * when they mean "behave like the old filter".
 */
enum Mode: string
{
    case Strict = 'strict';
    case Lenient = 'lenient';
    case Compatible = 'compatible';

    public static function parse(string $value): self
    {
        return match (strtolower(trim($value))) {
            'strict' => self::Strict,
            'lenient', 'permissive' => self::Lenient,
            'compatible', 'legacy', 'compat' => self::Compatible,
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown mode "%s". Use strict, lenient or compatible (legacy is a spelling of compatible).',
                $value
            )),
        };
    }

    public function options(): Options
    {
        return match ($this) {
            self::Strict => Options::strict(),
            self::Lenient => Options::lenient(),
            self::Compatible => Options::compatible(),
        };
    }

    public function describe(): string
    {
        return match ($this) {
            self::Strict => 'strict - unknown directives and variables are errors',
            self::Lenient => 'lenient - recovers, unknown constructs render verbatim',
            self::Compatible => 'compatible - reproduces the legacy filter, refuses what it could not render',
        };
    }

    /** @return string[] */
    public static function names(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }
}
