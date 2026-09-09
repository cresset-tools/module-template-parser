<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Magerun;

use Cresset\TemplateParser\Console\MagentoContext;

/**
 * Hands magerun's already-booted Magento to the commands.
 *
 * magerun finds the installation, boots it, and injects the ObjectManager into any command
 * that asks - so detecting and booting again would build a second application inside the
 * first. The commands are otherwise identical to the standalone ones; only where the store
 * comes from differs, which is the whole reason MagentoContext has two constructors.
 */
trait MagerunBridge
{
    /**
     * magerun calls this on every command that declares it, before run().
     *
     * @param \Magento\Framework\ObjectManagerInterface|object $objectManager
     */
    public function inject(object $objectManager): void
    {
        $this->setMagentoContext(MagentoContext::fromObjectManager($objectManager, defined('BP') ? BP : null));
    }
}
