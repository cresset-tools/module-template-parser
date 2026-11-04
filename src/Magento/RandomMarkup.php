<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

/**
 * Markup Magento randomises per render, which no two renders can agree on.
 *
 * Only for COMPARING renders - Shadow, Parser's sampled comparisons and `template:diff`.
 * Nothing served is ever changed.
 *
 * - `Framework\View\Element\Html\Link::_toHtml()` without a template - the CMS Page Link
 *   widget inserted with none - gives the link `id="id" . Random::getRandomString(8)`, so
 *   every render of a page with one differed from every other.
 *
 * Narrow on purpose: the exact shape that method writes, `<li><a ... id="idXXXXXXXX"`, and
 * nothing that merely looks like an id.
 */
final class RandomMarkup
{
    private const LINK_ID = '~(<li><a\s(?:[^<>]*?\s)?id=")id[A-Za-z0-9]{8}(")~';

    /** Whether two renders differ only in what Magento randomises. */
    public static function sameRender(string $a, string $b): bool
    {
        return $a === $b || self::normalized($a) === self::normalized($b);
    }

    public static function normalized(string $html): string
    {
        return str_contains($html, '<li><a ')
            ? (string)preg_replace(self::LINK_ID, '$1id--------$2', $html)
            : $html;
    }
}
