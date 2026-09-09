<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

use Cresset\TemplateParser\DirectiveSpec;
use Cresset\TemplateParser\Evaluator;
use Cresset\TemplateParser\HostDirectives;
use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\Magento\AllowlistedConfigReader;
use Cresset\TemplateParser\Magento\AllowlistedLayoutRenderer;
use Cresset\TemplateParser\Magento\AssetStylesheetLoader;
use Cresset\TemplateParser\Magento\ConfigTemplateLoader;
use Cresset\TemplateParser\Magento\LayoutBlockRenderer;
use Cresset\TemplateParser\Magento\PhraseTranslator;
use Cresset\TemplateParser\Magento\StoreUrlBuilder;
use Cresset\TemplateParser\Magento\TypeCheckedWidgetRenderer;
use Cresset\TemplateParser\Magento\VariableCustomVariableReader;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\Parser;
use Cresset\TemplateParser\TemplateEngine;

/**
 * Builds an engine for a mode, wiring whatever ports the host can supply.
 *
 * Without a store this yields the built-in surface only - var, if, depend, for - which is
 * enough to check syntax and to try things in the REPL. With one, every port this package
 * has an adapter for is connected, so {{block}}, {{layout}}, {{config}} and the rest resolve
 * against the real application and a merchant can see what their template will actually do.
 *
 * A port that cannot be constructed is skipped rather than fatal. A store missing
 * Magento_Variable should cost you {{customvar}}, not the tool.
 */
final class EngineFactory
{
    /** @param string[] $allowedLayoutHandles */
    public function __construct(
        private readonly MagentoContext $magento,
        private readonly array $allowedLayoutHandles = [],
        private readonly string $area = 'frontend',
    ) {
    }

    public function create(Mode $mode, ?int $storeId = null): TemplateEngine
    {
        $options = $mode->options();
        $spec = new DirectiveSpec();
        $evaluator = new Evaluator(spec: $spec, options: $options);

        HostDirectives::register($evaluator, $this->services($storeId), new Parser($spec, $options));

        return new TemplateEngine(new Parser($spec, $options), $evaluator);
    }

    /** Which directives the current host can actually resolve, for reporting. */
    public function wiredDirectives(?int $storeId = null): array
    {
        return $this->create(Mode::Lenient, $storeId)->evaluator()->registered();
    }

    private function services(?int $storeId): HostServices
    {
        if (!$this->magento->isAvailable()) {
            return new HostServices();
        }

        // Layout-backed ports need one; without it they silently fail to wire.
        $this->magento->ensureAreaCode($this->area);

        return new HostServices(
            blocks: $this->build(static fn (MagentoContext $m): ?object
                => ($layout = $m->get(\Magento\Framework\View\LayoutInterface::class))
                    && ($config = $m->get(\Magento\Framework\ObjectManager\ConfigInterface::class))
                    ? new LayoutBlockRenderer($layout, $config)
                    : null),
            translator: $this->build(static fn (MagentoContext $m): ?object => new PhraseTranslator()),
            templates: $this->build(fn (MagentoContext $m): ?object
                => ($scope = $m->get(\Magento\Framework\App\Config\ScopeConfigInterface::class))
                    ? new ConfigTemplateLoader(
                        $scope,
                        ['design/email/'],
                        $m->get(\Magento\Email\Model\TemplateFactory::class),
                        $storeId
                    )
                    : null),
            urls: $this->build(static fn (MagentoContext $m): ?object
                => ($url = $m->get(\Magento\Framework\UrlInterface::class))
                    && ($stores = $m->get(\Magento\Store\Model\StoreManagerInterface::class))
                    && ($assets = $m->get(\Magento\Framework\View\Asset\Repository::class))
                    ? new StoreUrlBuilder($url, $stores, $assets)
                    : null),
            stylesheets: $this->build(static fn (MagentoContext $m): ?object
                => ($css = $m->get(\Magento\Email\Model\Template\Css\Processor::class))
                    && ($assets = $m->get(\Magento\Framework\View\Asset\Repository::class))
                    ? new AssetStylesheetLoader($css, $assets)
                    : null),
            config: $this->build(fn (MagentoContext $m): ?object
                => ($scope = $m->get(\Magento\Framework\App\Config\ScopeConfigInterface::class))
                    && ($vars = $m->get(\Magento\Variable\Model\Source\Variables::class))
                    ? new AllowlistedConfigReader($scope, $vars, $storeId)
                    : null),
            customVariables: $this->build(fn (MagentoContext $m): ?object
                => ($factory = $m->get(\Magento\Variable\Model\VariableFactory::class))
                    ? new VariableCustomVariableReader($factory, $storeId)
                    : null),
            widgets: $this->build(static fn (MagentoContext $m): ?object
                => ($layout = $m->get(\Magento\Framework\View\LayoutInterface::class))
                    && ($config = $m->get(\Magento\Framework\ObjectManager\ConfigInterface::class))
                    ? new TypeCheckedWidgetRenderer($layout, $config)
                    : null),
            layouts: $this->build(fn (MagentoContext $m): ?object
                => $this->allowedLayoutHandles !== []
                    && ($factory = $m->get(\Magento\Framework\View\LayoutFactory::class))
                    && ($state = $m->get(\Magento\Framework\App\State::class))
                    ? new AllowlistedLayoutRenderer($factory, $state, $this->allowedLayoutHandles)
                    : null),
        );
    }

    /** @param callable(MagentoContext):?object $make */
    private function build(callable $make): ?object
    {
        try {
            return $make($this->magento);
        } catch (\Throwable) {
            return null;                 // a missing module costs one directive, not the tool
        }
    }
}
