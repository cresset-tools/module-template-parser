<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

use Magento\Variable\Model\Variable;
use Magento\Variable\Model\VariableFactory;
use MageOS\TemplateParser\Port\CustomVariableReader;

/** {{customvar}} through Magento's custom variable model. */
final class VariableCustomVariableReader implements CustomVariableReader
{
    public function __construct(
        private readonly VariableFactory $variableFactory,
        private readonly ?int $storeId = null
    ) {
    }

    public function value(string $code, bool $plainText): ?string
    {
        $variable = $this->variableFactory->create()
            ->setStoreId($this->storeId)
            ->loadByCode($code);

        $value = $variable->getValue($plainText ? Variable::TYPE_TEXT : Variable::TYPE_HTML);

        return $value === '' ? null : (string)$value;
    }
}
