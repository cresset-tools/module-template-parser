<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Cresset\TemplateParser\Port\TemplateLoader;
use Magento\Email\Model\TemplateFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Resolves {{template config_path="..."}} to template source.
 *
 * The config value is an IDENTIFIER, not template text. `design/email/header_template` holds
 * something like `design_email_header_template`, which Magento then resolves - numerically to
 * a row in email_template when a merchant has customised it, and otherwise to a file
 * registered in email_templates.xml. Returning the config value itself renders the identifier
 * into the email as literal text.
 *
 * Only paths under a configured prefix are readable, so a template cannot name an arbitrary
 * configuration path.
 */
class ConfigTemplateLoader implements TemplateLoader
{
    /** @param string[] $allowedPathPrefixes */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly array $allowedPathPrefixes = ['design/email/'],
        private readonly ?TemplateFactory $templateFactory = null,
        private readonly ?int $storeId = null,
    ) {
    }

    public function load(string $configPath): ?string
    {
        if (!$this->isAllowed($configPath)) {
            return null;
        }

        $identifier = $this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_STORE, $this->storeId);
        if (!is_string($identifier) && !is_numeric($identifier)) {
            return null;
        }
        $identifier = (string)$identifier;
        if ($identifier === '') {
            return null;
        }

        if ($this->templateFactory === null) {
            // Without the factory there is no way to turn an identifier into text, and
            // handing back the identifier would put it in the email.
            return null;
        }

        try {
            $template = $this->templateFactory->create();
            is_numeric($identifier)
                ? $template->load($identifier)
                : $template->loadDefault($identifier);

            $text = $template->getTemplateText();
        } catch (\Throwable) {
            return null;
        }

        return is_string($text) && $text !== '' ? $text : null;
    }

    private function isAllowed(string $configPath): bool
    {
        foreach ($this->allowedPathPrefixes as $prefix) {
            if (str_starts_with($configPath, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
