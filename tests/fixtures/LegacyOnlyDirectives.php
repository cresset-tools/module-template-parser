<?php
declare(strict_types=1);

// Filters whose directives only the filter itself can render: Magento's own CMS filter, whose
// {{media}} returns a filesystem path, the Widget filter that restores the URL one, a module's
// subclass that adds a directive, and a generated Interceptor - which redeclares a stock
// directive a module has put a plugin on.

namespace Magento\Cms\Model\Template {
    if (!class_exists(Filter::class)) {
        class Filter extends \Magento\Framework\Filter\Template
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
