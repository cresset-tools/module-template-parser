<?php
declare(strict_types=1);

/**
 * Runs DirectiveMethods and TemplateFilterPlugin against Magento's own PluginList, built by
 * its own PluginListGenerator from plugin declarations, with its own Interceptor trait.
 *
 * A separate process, because the unit suite stands Magento's interfaces in with stubs and
 * these are the real ones. Run by RealPluginListTest; prints JSON.
 *
 * Usage: MAGENTO_ROOT=/path/to/magento php tests/fixtures/real-plugin-list.php
 */

namespace RealPluginListFixture {
    $root = rtrim((string)getenv('MAGENTO_ROOT'), '/');
    require $root . '/vendor/autoload.php';
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'MageOS\\TemplateParser\\')) {
            $file = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, 22)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, true); // ahead of the store's own copy of this module, if it has one

    class Filter extends \Magento\Framework\Filter\Template
    {
    }

    class BeforeOutside
    {
        public function beforeFilter($subject, $value) { return null; }
    }

    class AfterInside
    {
        public function afterFilter($subject, $result, $value) { return $result; }
    }

    class AroundInside
    {
        public function aroundFilter($subject, callable $proceed, $value) { return $proceed($value); }
    }

    class AfterVar
    {
        public function afterVarDirective($subject, $result, $construction) { return $result; }
    }

    class AroundVar
    {
        public function aroundVarDirective($subject, callable $proceed, $construction) { return $proceed($construction); }
    }

    /** What the store would give each plugin type: one shared instance. */
    final class Objects implements \Magento\Framework\ObjectManagerInterface
    {
        /** @var array<string,object> */
        public array $shared = [];
        public function create($type, array $arguments = []) { return new $type(); }
        public function get($type) { return $this->shared[$type] ??= new $type(); }
        public function configure(array $configuration) {}
    }

    final class Scope implements \Magento\Framework\Config\ScopeInterface
    {
        public function getCurrentScope() { return 'global'; }
        public function setCurrentScope($scope) {}
    }

    /**
     * Magento's PluginList over `$plugins` - what di.xml declares on
     * `Magento\Framework\Filter\Template` - without the config reader and cache, which are
     * how a store gets the declarations, not what it does with them.
     *
     * @param array<string,array<string,mixed>> $plugins
     */
    function pluginList(array $plugins, Objects $objects): \Magento\Framework\Interception\PluginList\PluginList
    {
        $omConfig = new \Magento\Framework\Interception\ObjectManager\Config\Developer();
        $generator = (new \ReflectionClass(\Magento\Framework\Interception\PluginListGenerator::class))
            ->newInstanceWithoutConstructor();
        set($generator, [
            'omConfig' => $omConfig,
            'relations' => new \Magento\Framework\ObjectManager\Relations\Runtime(),
            'definitions' => new \Magento\Framework\Interception\Definition\Runtime(),
            'logger' => new \Psr\Log\NullLogger(),
            'appMode' => \Magento\Framework\App\State::MODE_DEFAULT,
        ]);

        $list = (new \ReflectionClass(\Magento\Framework\Interception\PluginList\PluginList::class))
            ->newInstanceWithoutConstructor();
        set($list, [
            '_data' => [\Magento\Framework\Filter\Template::class => $plugins],
            '_inherited' => [],
            '_processed' => [],
            '_loadedScopes' => ['global' => true],
            '_configScope' => new Scope(),
            '_scopePriorityScheme' => ['global'],
            '_omConfig' => $omConfig,
            '_objectManager' => $objects,
            '_pluginInstances' => [],
            'pluginListGenerator' => $generator,
        ]);

        return $list;
    }

    /** @param array<string,mixed> $values */
    function set(object $object, array $values): void
    {
        foreach ($values as $name => $value) {
            for ($class = new \ReflectionClass($object); $class !== false; $class = $class->getParentClass()) {
                if ($class->hasProperty($name) && $class->getProperty($name)->getDeclaringClass()->getName() === $class->getName()) {
                    $class->getProperty($name)->setValue($object, $value);
                    continue 2;
                }
            }
            throw new \LogicException(sprintf('%s has no property %s', $object::class, $name));
        }
    }

    function interceptor(\Magento\Framework\Interception\PluginListInterface $list): Filter\Interceptor
    {
        $subject = (new \ReflectionClass(Filter\Interceptor::class))->newInstanceWithoutConstructor();
        (function () use ($list): void {
            $this->pluginList = $list;
            $this->subjectType = get_parent_class($this);
        })->call($subject);

        return $subject;
    }
}

namespace RealPluginListFixture\Filter {
    /** The shape `Interception\Code\Generator\Interceptor` writes, for the two methods used. */
    class Interceptor extends \RealPluginListFixture\Filter implements \Magento\Framework\Interception\InterceptorInterface
    {
        use \Magento\Framework\Interception\Interceptor;

        public function filter($value)
        {
            $pluginInfo = $this->pluginList->getNext($this->subjectType, 'filter');
            return $pluginInfo ? $this->___callPlugins('filter', func_get_args(), $pluginInfo) : parent::filter($value);
        }

        public function varDirective($construction)
        {
            $pluginInfo = $this->pluginList->getNext($this->subjectType, 'varDirective');
            return $pluginInfo ? $this->___callPlugins('varDirective', func_get_args(), $pluginInfo) : parent::varDirective($construction);
        }
    }
}

namespace {
    use MageOS\TemplateParser\Magento\DirectiveMethods;
    use MageOS\TemplateParser\Magento\Plugin\TemplateFilterPlugin;
    use RealPluginListFixture as F;

    $innerChain = static function (array $plugins): ?array {
        $objects = new F\Objects();
        $plugin = (new \ReflectionClass(TemplateFilterPlugin::class))->newInstanceWithoutConstructor();
        (function (): void {
            $this->pluginList = null; // the subject's own list is the one asked
        })->call($plugin);
        $objects->shared[TemplateFilterPlugin::class] = $plugin;
        $subject = F\interceptor(F\pluginList($plugins, $objects));
        $chain = (new \ReflectionMethod(TemplateFilterPlugin::class, 'findInnerChain'))->invoke($plugin, $subject);
        if ($chain !== null) {
            unset($chain['list']);
        }

        return $chain;
    };

    $ours = ['mageos_template_parser' => ['sortOrder' => 10, 'instance' => TemplateFilterPlugin::class]];
    $varPlugins = static fn (array $plugins): array => DirectiveMethods::of(
        F\Filter\Interceptor::class,
        null,
        F\interceptor(F\pluginList($plugins, new F\Objects()))
    );

    echo json_encode([
        'constants' => [
            'before' => \Magento\Framework\Interception\DefinitionInterface::LISTENER_BEFORE,
            'around' => \Magento\Framework\Interception\DefinitionInterface::LISTENER_AROUND,
            'after' => \Magento\Framework\Interception\DefinitionInterface::LISTENER_AFTER,
        ],
        'inside' => $innerChain($ours + [
            'outside_before' => ['sortOrder' => 5, 'instance' => F\BeforeOutside::class],
            'inside_after' => ['sortOrder' => 20, 'instance' => F\AfterInside::class],
        ]),
        'around_inside' => $innerChain($ours + [
            'inside_around' => ['sortOrder' => 20, 'instance' => F\AroundInside::class],
        ]),
        'nothing_inside' => $innerChain($ours + [
            'outside_before' => ['sortOrder' => 5, 'instance' => F\BeforeOutside::class],
        ]),
        'var' => $varPlugins([
            'var_after' => ['sortOrder' => 1, 'instance' => F\AfterVar::class],
            'var_around' => ['sortOrder' => 2, 'instance' => F\AroundVar::class],
            'var_after_inside' => ['sortOrder' => 3, 'instance' => F\AfterVar::class],
        ]),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
}
