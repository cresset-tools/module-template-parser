<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Variable\Model\Variable;
use Magento\Variable\Model\VariableFactory;
use MageOS\TemplateParser\Port\CustomVariableReader;

/**
 * {{customvar}} through Magento's custom variable model, for the rendering filter's store.
 *
 * customvarDirective loads with `setStoreId($this->getStoreId())`: the filter's own store, or
 * the current one when it holds none. Without a store, Magento's resource model quotes null as
 * '' and MySQL matches store 0 - the default-scope value - so a store view's own value was
 * silently replaced by the default one.
 */
class VariableCustomVariableReader implements CustomVariableReader
{
    /** @param ?int $storeId a fixed store, as the CLI renders for; otherwise the filter's */
    public function __construct(
        private readonly VariableFactory $variableFactory,
        private readonly ?int $storeId = null,
        private readonly ?RenderScope $scope = null,
        private readonly ?StoreManagerInterface $stores = null
    ) {
    }

    public function value(string $code, bool $plainText): ?string
    {
        $variable = $this->variableFactory->create()
            ->setStoreId($this->storeId())
            ->loadByCode($code);

        $value = $variable->getValue($plainText ? Variable::TYPE_TEXT : Variable::TYPE_HTML);

        // customVarDirective keeps the value only `if ($value)`, so PHP truthiness decides -
        // and a variable whose value is the string "0" renders as nothing. Reproduced rather
        // than corrected: it is the same truthiness quirk {{if}} has, it is what merchants'
        // templates have been rendering, and there is no safety argument for diverging.
        return $value ? (string)$value : null;
    }

    private function storeId(): int|string|null
    {
        return $this->storeId
            ?? $this->scope?->storeId()
            ?? $this->stores?->getStore()->getId();
    }
}
