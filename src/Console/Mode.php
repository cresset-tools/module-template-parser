<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

use Cresset\TemplateParser\Options;

/**
 * The three engine postures, as a CLI-facing choice - `--posture` on the commands.
 *
 * Named Mode in code for history; the CLI says posture, because the rollout stage
 * (Legacy/Shadow/Parser) is what "mode" means to anyone configuring a store.
 *
 * `legacy` is NOT a spelling of compatible any more. Under bin/magento "Legacy" is the rollout
 * stage that does not run this engine at all, so `--posture=legacy` reads as "use the old
 * filter" while measuring this one. It is refused with a pointer to the name that means what
 * was intended. Only the deprecated `--mode` still accepts it, so 0.2 scripts keep running.
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
            'compatible', 'compat' => self::Compatible,
            'legacy' => throw new \InvalidArgumentException(
                'There is no "legacy" posture. "Legacy" is the rollout stage that renders with Magento\'s'
                . ' own filter only; to have this engine reproduce that filter, use --posture=compatible.'
            ),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown posture "%s". Use strict, lenient or compatible.',
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
