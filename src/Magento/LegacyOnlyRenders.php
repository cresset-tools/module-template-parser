<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

/**
 * Marks renders that must be the legacy filter's own, whatever stage the store view is in.
 *
 * `template:diff` renders the "today" side through the store's real filter - and that filter
 * carries TemplateFilterPlugin. On a store view in Parser, "today" was therefore this engine,
 * and diff compared the engine with itself; in Shadow, every CLI render was recorded into the
 * live shadow table, its sample variables and all, where it could fail the rollout gate.
 * Shared, so the console and the plugin see the same counter.
 */
class LegacyOnlyRenders
{
    private int $depth = 0;

    /**
     * @template T
     * @param callable():T $render
     * @return T
     */
    public function run(callable $render): mixed
    {
        $this->depth++;
        try {
            return $render();
        } finally {
            $this->depth--;
        }
    }

    public function active(): bool
    {
        return $this->depth > 0;
    }
}
