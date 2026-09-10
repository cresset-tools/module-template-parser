<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

/**
 * Runs a callback in a store's context, so translations and config resolve as they would.
 *
 * {{trans}} and {{config}} are store-scoped: the same template renders different words and
 * different URLs per store view, and a merchant checking a German store view needs the German
 * strings, not the fallback. Magento already has the machinery for this in
 * Store\Model\App\Emulation, which swaps locale, design and translations together; doing it
 * by hand with setCurrentStore gets the config right and the translations wrong.
 *
 * With no store named, the current one is emulated rather than nothing being emulated. A CLI
 * process has a store but no design - Bootstrap loads neither a theme nor a locale - so
 * {{css}} and {{view}} resolve their assets against a theme whose code is the empty string
 * and come back as LESS compilation errors. Emulating is what gives the process the design
 * a request would have had.
 */
class StoreEmulator
{
    /**
     * How many emulations deep this is.
     *
     * Magento's own Emulation refuses to NEST - a second startEnvironmentEmulation() is a
     * no-op - but stopEnvironmentEmulation() restores unconditionally, so an inner stop tears
     * down the outer emulation and everything after it runs unemulated. That is not
     * hypothetical: the legacy renderer emulates the store the way processTemplate() does, the
     * Auditor calls it from INSIDE this, and the result was `{{css}}` and `{{view}}` resolving
     * against `_view` instead of the store's theme for every case after the first.
     *
     * So this counts, and only the outermost call actually starts or stops. Which means every
     * emulation in the package has to come through here - the counter is per-instance and the
     * thing it guards is process-wide, so callers share one of these.
     */
    private int $depth = 0;

    public function __construct(private readonly MagentoContext $magento)
    {
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function around(?int $storeId, callable $work): mixed
    {
        if (!$this->magento->isAvailable()) {
            return $work();
        }

        $emulation = $this->magento->get(\Magento\Store\Model\App\Emulation::class);
        $storeId ??= $this->currentStoreId();
        if ($emulation === null || $storeId === null) {
            return $work();
        }

        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $work();
            } finally {
                $this->depth--;
            }
        }

        try {
            $emulation->startEnvironmentEmulation($storeId, \Magento\Framework\App\Area::AREA_FRONTEND, true);
        } catch (\Throwable) {
            return $work();              // an un-emulatable store should not stop the check
        }

        $this->depth = 1;

        try {
            return $work();
        } finally {
            $this->depth = 0;
            try {
                $emulation->stopEnvironmentEmulation();
            } catch (\Throwable) {
                // nothing useful to do; the process is about to end either way
            }
        }
    }

    private function currentStoreId(): ?int
    {
        $manager = $this->magento->get(\Magento\Store\Model\StoreManagerInterface::class);

        try {
            return $manager === null ? null : (int)$manager->getStore()->getId();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int,array{id:int,code:string,name:string}> */
    public function stores(): array
    {
        $manager = $this->magento->get(\Magento\Store\Model\StoreManagerInterface::class);
        if ($manager === null) {
            return [];
        }

        $stores = [];
        try {
            foreach ($manager->getStores(true) as $store) {
                $stores[] = [
                    'id' => (int)$store->getId(),
                    'code' => (string)$store->getCode(),
                    'name' => (string)$store->getName(),
                ];
            }
        } catch (\Throwable) {
            return [];
        }

        return $stores;
    }
}
