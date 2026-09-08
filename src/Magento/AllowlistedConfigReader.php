<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Variable\Model\Source\Variables;
use MageOS\TemplateParser\Port\ConfigReader;

/**
 * {{config}} restricted to the variables Magento declares template-readable.
 *
 * This allowlist is the whole security of the directive: without it a template can read any
 * configuration value the store holds. Magento gates it with
 * Variables::getAvailableVars(); the same gate is applied here, plus a fallback deny if the
 * list cannot be obtained - failing closed rather than open.
 */
final class AllowlistedConfigReader implements ConfigReader
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Variables $availableVariables,
        private readonly ?int $storeId = null
    ) {
    }

    public function value(string $path): ?string
    {
        try {
            $allowed = $this->availableVariables->getAvailableVars();
        } catch (\Throwable) {
            return null;
        }

        if (!in_array($path, $allowed, true)) {
            return null;
        }

        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $this->storeId);

        return is_scalar($value) ? (string)$value : null;
    }
}
