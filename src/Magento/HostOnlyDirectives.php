<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

/**
 * Whether a template spells a directive only the rendering filter itself can render.
 *
 * One rule for both places that ask: TemplateFilterPlugin, of the template it was handed, and
 * ConfigTemplateLoader, of each `{{template}}` include - which this engine loads and renders
 * itself, so a check of the top level alone served an include's `{{coupon}}` as escaped text.
 * The match is the store's: its directive name capture is `[a-z]{0,10}`, greedy over letters
 * only, so `{{coupon2}}` and `{{coupon_x}}` are both `coupon`.
 */
final class HostOnlyDirectives
{
    /**
     * @param list<string> $names
     * @return ?string the first such directive's name, lowercased, or null
     */
    public static function firstIn(array $names, string $source): ?string
    {
        if ($names === []) {
            return null;
        }
        $alternatives = implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), $names));

        return preg_match('/\{\{(' . $alternatives . ')(?![a-z])/i', $source, $m) === 1 ? strtolower($m[1]) : null;
    }
}
