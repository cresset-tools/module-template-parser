<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Cresset\TemplateParser\Port\TemplateLoader;

/**
 * Resolves {{template config_path="..."}} to template source.
 *
 * Only paths under a configured prefix are readable, so a template cannot name an arbitrary
 * configuration path.
 */
final class ConfigTemplateLoader implements TemplateLoader
{
    /** @param string[] $allowedPathPrefixes */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly array $allowedPathPrefixes = ['design/email/']
    ) {
    }

    public function load(string $configPath): ?string
    {
        foreach ($this->allowedPathPrefixes as $prefix) {
            if (str_starts_with($configPath, $prefix)) {
                $value = $this->scopeConfig->getValue($configPath);
                return is_string($value) ? $value : null;
            }
        }

        return null;
    }
}
