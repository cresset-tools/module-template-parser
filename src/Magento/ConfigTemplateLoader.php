<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Cresset\TemplateParser\LegacyReading;
use Cresset\TemplateParser\Port\RefusedByPort;
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

    /**
     * Every way this can fail to produce the include is a refusal, not a null.
     *
     * The filter's AbstractTemplate::getTemplateContent() loads ANY config path, and when the
     * path holds nothing it calls loadDefault() on that nothing, which raises - so for none of
     * these does legacy render the empty string a null would turn into here. Thrown, each is
     * recorded and the render declined; in Parser mode the filter renders it as it always has.
     * Only a template that loaded and is genuinely empty renders as empty.
     */
    public function load(string $configPath): ?string
    {
        if (!$this->isAllowed($configPath)) {
            throw new RefusedByPort('template config path', $configPath);
        }

        $identifier = $this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_STORE, $this->storeId);
        if (!is_string($identifier) && !is_numeric($identifier)) {
            throw new RefusedByPort('template config path', $configPath);
        }
        $identifier = (string)$identifier;
        if ($identifier === '') {
            throw new RefusedByPort('template config path', $configPath);
        }

        if ($this->templateFactory === null) {
            // Without the factory there is no way to turn an identifier into text, and
            // handing back the identifier would put it in the email.
            throw new RefusedByPort('template include (no template factory wired)', $configPath);
        }

        try {
            $template = $this->templateFactory->create();
            is_numeric($identifier)
                ? $template->load($identifier)
                : $template->loadDefault($identifier);

            $text = $template->getTemplateText();
        } catch (\Throwable) {
            throw new RefusedByPort('template include', $configPath);
        }

        $text = is_string($text) ? $text : '';

        // The include is rendered by this engine as part of its parent, so a construct the
        // filter reads differently declines the parent - as it would at the top level.
        if (LegacyReading::firstDifference($text) !== null) {
            throw new RefusedByPort('template include the filter reads differently', $configPath);
        }

        return $text;
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
