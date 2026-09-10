<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Magento\Variable\Model\Variable;
use Magento\Variable\Model\VariableFactory;
use Cresset\TemplateParser\Port\CustomVariableReader;

/** {{customvar}} through Magento's custom variable model. */
class VariableCustomVariableReader implements CustomVariableReader
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

        // customVarDirective keeps the value only `if ($value)`, so PHP truthiness decides -
        // and a variable whose value is the string "0" renders as nothing. Reproduced rather
        // than corrected: it is the same truthiness quirk {{if}} has, it is what merchants'
        // templates have been rendering, and there is no safety argument for diverging.
        return $value ? (string)$value : null;
    }
}
