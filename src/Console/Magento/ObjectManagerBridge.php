<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Magento;

use Cresset\TemplateParser\Console\MagentoContext;
use Magento\Framework\ObjectManagerInterface;

/**
 * Hands bin/magento's ObjectManager to the commands.
 *
 * The same idea as Magerun\MagerunBridge, delivered the way Magento delivers things: through
 * the constructor. That constructor runs on EVERY bin/magento invocation - CommandList
 * builds each registered command to list it, whether or not it is the one being run - so it
 * wraps the ObjectManager and nothing else. No store is resolved, no area set, no connection
 * opened until a command actually executes.
 */
trait ObjectManagerBridge
{
    public function __construct(ObjectManagerInterface $objectManager)
    {
        parent::__construct();
        $this->setMagentoContext(MagentoContext::fromObjectManager($objectManager, defined('BP') ? BP : null));
    }
}
