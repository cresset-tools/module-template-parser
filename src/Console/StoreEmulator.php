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
 * Without a store, or without an emulation service, the callback simply runs.
 */
final class StoreEmulator
{
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
        if ($storeId === null || !$this->magento->isAvailable()) {
            return $work();
        }

        $emulation = $this->magento->get(\Magento\Store\Model\App\Emulation::class);
        if ($emulation === null) {
            return $work();
        }

        try {
            $emulation->startEnvironmentEmulation($storeId, \Magento\Framework\App\Area::AREA_FRONTEND, true);
        } catch (\Throwable) {
            return $work();              // an un-emulatable store should not stop the check
        }

        try {
            return $work();
        } finally {
            try {
                $emulation->stopEnvironmentEmulation();
            } catch (\Throwable) {
                // nothing useful to do; the process is about to end either way
            }
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
