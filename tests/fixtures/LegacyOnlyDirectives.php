<?php
declare(strict_types=1);

// Filters whose directives only the filter itself can render: Magento's own CMS filter, whose
// {{media}} returns a filesystem path, the Widget filter that restores the URL one, a module's
// subclass that adds a directive, and a generated Interceptor - which redeclares a stock
// directive a module has put a plugin on.

namespace Magento\Cms\Model\Template {
    if (!class_exists(Filter::class)) {
        class Filter extends \Magento\Email\Model\Template\Filter
        {
            protected $_storeId = 1;
            public function mediaDirective($construction) { return '/var/www/pub/media/'; }
        }
    }
}

namespace Magento\Widget\Model\Template {
    if (!class_exists(Filter::class)) {
        class Filter extends \Magento\Cms\Model\Template\Filter
        {
            public function mediaDirective($construction) { return 'https://shop.example/media/'; }
        }
    }
}

namespace Acme\Promo {
    if (!class_exists(Filter::class)) {
        class Filter extends \Magento\Widget\Model\Template\Filter
        {
            /** Added: the filter dispatches {{coupon}} here by reflection. */
            public function couponDirective($construction) { return 'SAVE10'; }
        }
    }
}

namespace Magento\Widget\Model\Template\Filter {
    if (!class_exists(Interceptor::class)) {
        /** What setup:di:compile generates when a module plugs into varDirective. */
        class Interceptor extends \Magento\Widget\Model\Template\Filter
        {
            public function varDirective($construction) { return ''; }
        }
    }
}

namespace Magento\Widget\Model\Template {
    if (!class_exists(FilterEmulate::class)) {
        /** The newsletter filter's parent: every {{widget}} inside a frontend area emulation. */
        class FilterEmulate extends Filter
        {
            public function widgetDirective($construction) { return 'EMULATED'; }
        }
    }
}

namespace Magento\Email\Model\Template\Filter {
    if (!class_exists(Interceptor::class)) {
        /**
         * What the ON-DEMAND generator writes - developer mode, or default mode without
         * setup:di:compile: every public method redeclared, plugged or not, dispatching through
         * the plugin list `___init()` stores in private properties. Only the plugin list says
         * which methods are really plugged.
         */
        class Interceptor extends \Magento\Email\Model\Template\Filter
        {
            private $pluginList;
            private $subjectType;

            /** @param list<string> $plugged directive methods a plugin is configured on */
            public function __construct(array $plugged = [])
            {
                $this->subjectType = get_parent_class($this);
                $this->pluginList = new class ($plugged) implements \Magento\Framework\Interception\PluginListInterface {
                    public function __construct(private array $plugged) {}
                    public function getPlugin($type, $code) { return null; }
                    public function getNext($type, $method, $code = '__self')
                    {
                        return $type === \Magento\Email\Model\Template\Filter::class && in_array($method, $this->plugged, true)
                            ? ['after' => ['some_module_plugin']]
                            : null;
                    }
                };
            }

            public function varDirective($construction) { return ''; }
            public function transDirective($construction) { return ''; }
            public function ifDirective($construction) { return ''; }
            public function dependDirective($construction) { return ''; }
            public function forDirective($construction) { return ''; }
            public function templateDirective($construction) { return ''; }
            public function cssDirective($construction) { return ''; }
            public function inlinecssDirective($construction) { return ''; }
            public function blockDirective($construction) { return ''; }
            public function layoutDirective($construction) { return ''; }
            public function configDirective($construction) { return ''; }
            public function customvarDirective($construction) { return ''; }
            public function storeDirective($construction) { return ''; }
            public function mediaDirective($construction) { return ''; }
            public function viewDirective($construction) { return ''; }
            public function protocolDirective($construction) { return ''; }
        }
    }
}

namespace Acme\Promo\Filter {
    if (!class_exists(Interceptor::class)) {
        /** The on-demand shape over a module's subclass: its own directive stays its own. */
        class Interceptor extends \Acme\Promo\Filter
        {
            private $pluginList;
            private $subjectType;

            public function __construct()
            {
                $this->subjectType = get_parent_class($this);
                $this->pluginList = new class implements \Magento\Framework\Interception\PluginListInterface {
                    public function getPlugin($type, $code) { return null; }
                    public function getNext($type, $method, $code = '__self') { return null; }
                };
            }

            public function varDirective($construction) { return ''; }
            public function couponDirective($construction) { return 'SAVE10'; }
        }
    }
}
