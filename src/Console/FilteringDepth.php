<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Console;

/**
 * Puts the filter's shared nesting depth back where a legacy render found it.
 *
 * `Framework\Filter\Template::filter()` calls `FilteringDepthMeter::descend()` on entry and
 * `ascend()` just before returning, with no `finally` in between - on every version this
 * module supports, Mage-OS 3.6.0 included. The meter is one shared object for the process,
 * and the filter decides by its depth whether a render is the root: the root resolves the
 * directives its includes deferred (`DeferredDirectiveRegistry`'s `:deferred:` placeholders,
 * or the signed copies before it), a child leaves them for the root.
 *
 * So one render that raises part-way leaves the meter a level deep, and every render after
 * it in the process acts as a child and leaves its includes' deferrals unresolved. The Email
 * filter catches the exception itself, and the console renders under the store's error
 * handler, which raises for a warning - so the first legacy failure in a `template:diff` run
 * left an unresolved placeholder in every later email whose include deferred a directive -
 * the stock header's `{{inlinecss}}` - and reported it as a divergence: 112 templates on one
 * 2.4.5 store, none of which differed when rendered on their own.
 *
 * Restored through the meter's own methods, as many steps as it moved: it has no setter.
 */
final class FilteringDepth
{
    public const METER = 'Magento\\Framework\\Filter\\Template\\FilteringDepthMeter';

    public function __construct(private readonly MagentoContext $magento)
    {
    }

    /**
     * Runs `$render`, then returns the meter to the depth it had before - whatever happened.
     *
     * @template T
     * @param callable():T $render
     * @return T
     */
    public function keep(callable $render): mixed
    {
        $meter = $this->meter();
        if ($meter === null) {
            return $render();
        }

        $depth = $meter->showMark();
        try {
            return $render();
        } finally {
            self::restore($meter, $depth);
        }
    }

    /** Ascends or descends `$meter` until it shows `$depth`. */
    public static function restore(object $meter, int $depth): void
    {
        for ($steps = (int)$meter->showMark() - $depth; $steps > 0; $steps--) {
            $meter->ascend();
        }
        for ($steps = $depth - (int)$meter->showMark(); $steps > 0; $steps--) {
            $meter->descend();
        }
    }

    /** The process's meter, or null on a Magento without one. */
    private function meter(): ?object
    {
        if (!class_exists(self::METER)) {
            return null;
        }
        $meter = $this->magento->get(self::METER);

        return is_object($meter) && method_exists($meter, 'showMark') && method_exists($meter, 'ascend') && method_exists($meter, 'descend')
            ? $meter
            : null;
    }
}
