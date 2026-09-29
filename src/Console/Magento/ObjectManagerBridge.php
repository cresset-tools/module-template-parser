<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Magento;

use Cresset\TemplateParser\Console\MagentoContext;
use Magento\Framework\ObjectManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Hands bin/magento's ObjectManager to the commands.
 *
 * The same idea as Magerun\MagerunBridge, delivered the way Magento delivers things: through
 * the constructor. That constructor runs on EVERY bin/magento invocation - CommandList
 * builds each registered command to list it, whether or not it is the one being run - so it
 * wraps the ObjectManager and nothing else. No store is resolved, no area set, no connection
 * opened until a command actually executes.
 *
 * The name and description are passed explicitly rather than left to #[AsCommand]. Symfony
 * reads that attribute from `static::class` only, and under bin/magento `static::class` is
 * never this class: Magento_NewRelicReporting declares a plugin on Symfony's Command, so
 * every registered command is instantiated as a generated Interceptor subclass - which
 * carries no attributes. Left to Symfony, setup:upgrade refused to start with "cannot have
 * an empty name". `self::class` in a trait is the class using it, so the attribute is read
 * from there, and the description from the nearest ancestor that declares one.
 */
trait ObjectManagerBridge
{
    public function __construct(ObjectManagerInterface $objectManager)
    {
        $name = null;
        $description = '';
        for ($class = new \ReflectionClass(self::class); $class !== false; $class = $class->getParentClass()) {
            $attribute = ($class->getAttributes(AsCommand::class)[0] ?? null)?->newInstance();
            if ($attribute === null) {
                continue;
            }
            $name ??= $attribute->name;
            if ($description === '' && $attribute->description !== null) {
                $description = $attribute->description;
            }
        }

        parent::__construct($name);
        if ($this->getDescription() === '') {
            $this->setDescription($description);
        }
        $this->setMagentoContext(MagentoContext::fromObjectManager($objectManager, defined('BP') ? BP : null));
    }
}
