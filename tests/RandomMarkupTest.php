<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Magento\RandomMarkup;
use PHPUnit\Framework\TestCase;

/**
 * The CMS Page Link widget without a template renders through Html\Link::_toHtml(), which
 * draws the link's id at random per render - so Shadow and diff counted every page with one
 * as a divergence. Only the comparison looks past it.
 */
final class RandomMarkupTest extends TestCase
{
    private static function link(string $id, string $label = 'About us'): string
    {
        return '<p>See <li><a href="https://shop.example/about" title="About" id="' . $id . '" >' . $label . '</a></li></p>';
    }

    public function testTwoRendersDifferingOnlyInTheLinksRandomIdAreTheSame(): void
    {
        self::assertTrue(RandomMarkup::sameRender(self::link('idAb3dE5fG'), self::link('idZz9yX8wV')));
    }

    public function testAnythingElseStillDiffers(): void
    {
        self::assertFalse(RandomMarkup::sameRender(self::link('idAb3dE5fG'), self::link('idZz9yX8wV', 'Contact')));
        // Not the shape Html\Link writes: an id an author chose, outside a link item.
        self::assertFalse(RandomMarkup::sameRender('<a id="idAb3dE5fG">x</a>', '<a id="idZz9yX8wV">x</a>'));
        self::assertFalse(RandomMarkup::sameRender(self::link('idAb3dE5fG'), self::link('menu-about')));
    }

    public function testTheRenderItselfIsNotChanged(): void
    {
        self::assertSame('<p>plain</p>', RandomMarkup::normalized('<p>plain</p>'));
        self::assertStringNotContainsString('idAb3dE5fG', RandomMarkup::normalized(self::link('idAb3dE5fG')));
    }
}
