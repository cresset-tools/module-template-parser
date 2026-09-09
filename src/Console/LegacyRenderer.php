<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

/**
 * Renders through the filter the store is running today.
 *
 * `diff` is only meaningful against the real thing, so this asks the object manager for
 * Email\Model\Template\Filter - the filter emails and CMS content actually render through,
 * not the Framework base class, whose `{{var x|modifier}}` defect would show up as a
 * divergence that does not exist in production.
 *
 * Returns null when there is no store, or when the filter raised: a template the current
 * filter cannot render has no output to compare against, which is itself worth reporting.
 */
final class LegacyRenderer
{
    public function __construct(private readonly MagentoContext $magento)
    {
    }

    public function isAvailable(): bool
    {
        return $this->magento->isAvailable()
            && $this->magento->get(\Magento\Email\Model\Template\Filter::class) !== null;
    }

    /**
     * Resolves a {{template}} include the way Magento does, for the legacy side.
     *
     * @return callable(string,array):string
     */
    private function includeResolver(?int $storeId): callable
    {
        $magento = $this->magento;

        return static function (string $configPath, array $variables) use ($magento, $storeId): string {
            $scope = $magento->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
            $factory = $magento->get(\Magento\Email\Model\TemplateFactory::class);
            if ($scope === null || $factory === null) {
                return '';
            }

            try {
                $identifier = $scope->getValue($configPath, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
                if (!is_string($identifier) && !is_numeric($identifier)) {
                    return '';
                }
                $template = $factory->create();
                is_numeric($identifier) ? $template->load($identifier) : $template->loadDefault((string)$identifier);

                return (string)$template->getTemplateText();
            } catch (\Throwable) {
                return '';
            }
        };
    }

    /** @param array<string,mixed> $variables */
    public function render(string $template, array $variables, ?int $storeId = null): ?string
    {
        $filter = $this->magento->get(\Magento\Email\Model\Template\Filter::class);
        if ($filter === null) {
            return null;
        }

        // The legacy filter emits notices for exactly the constructs this tool exists to find,
        // so letting them through would bury the report in PHP warnings about templates the
        // report is already telling you about.
        set_error_handler(static fn (): bool => true);

        try {
            // A fresh filter per render: Template::$templateVars accumulates, so a reused one
            // would let an earlier subject's variables resolve in a later one.
            $filter = clone $filter;
            if ($storeId !== null && method_exists($filter, 'setStoreId')) {
                $filter->setStoreId($storeId);
            }
            $filter->setVariables($variables);

            // Magento sets this from AbstractTemplate::getTemplateFilter(); a Filter taken
            // straight from the object manager has none, and TemplateDirective then returns
            // "{Error in template processing}" for every {{template}} include. Comparing
            // against that would report a divergence on every stock email that has a header,
            // caused entirely by how this tool constructed the filter.
            if (method_exists($filter, 'setTemplateProcessor')) {
                $filter->setTemplateProcessor($this->includeResolver($storeId));
            }

            return (string)$filter->filter($template);
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }
}
