<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

/**
 * Whether the store refuses to build a block class for {{block}}.
 *
 * A port of this module's own, so that no constructor here names
 * `Magento\Email\Model\Template\Filter\BlockDirectivePolicy`: that class exists only on Mage-OS
 * trees hardened after 2026-09-08, and Magento's DI reflects every constructor parameter's type
 * before it builds anything. Typing against it made LayoutBlockRenderer - and with it the
 * plugin on every template filter - unbuildable on Magento Open Source 2.4.x and older Mage-OS,
 * including in Legacy mode.
 */
interface BlockClassPolicy
{
    public function isRestricted(string $class): bool;
}
