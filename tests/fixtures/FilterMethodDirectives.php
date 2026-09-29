<?php
declare(strict_types=1);

// A module's filter subclass, and the core class it extends. The base stands in for Magento's
// filter: detection excludes whatever a `Magento\` class declares, so it lives in that namespace.

namespace Magento\Email\Model\Template {
    if (!class_exists(StubCoreFilter::class)) {
        class StubCoreFilter
        {
            public function varDirective($construction) { return ''; }
            public function storeDirective($construction) { return ''; }
        }
    }
}

namespace Acme\Coupons {
    if (!class_exists(Filter::class)) {
        class Filter extends \Magento\Email\Model\Template\StubCoreFilter
        {
            /** Added: legacy dispatches {{coupon}} here by reflection. */
            public function couponDirective($construction) { return ''; }

            /** Overrides a stock directive: legacy renders {{var}} through this. */
            public function varDirective($construction) { return ''; }

            /** Thirteen letters: legacy's name capture stops at ten, so no template reaches it. */
            public function somethinglongDirective($construction) { return ''; }

            public static function staticDirective() { return ''; }

            protected function hiddenDirective() { return ''; }

            public function helper() { return ''; }
        }
    }
}
