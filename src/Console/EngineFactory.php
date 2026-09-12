<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\Magento\AllowlistedConfigReader;
use Cresset\TemplateParser\Magento\AllowlistedLayoutRenderer;
use Cresset\TemplateParser\Magento\AssetStylesheetLoader;
use Cresset\TemplateParser\Magento\TemplateModelUrlBuilder;
use Cresset\TemplateParser\Magento\ConfigTemplateLoader;
use Cresset\TemplateParser\Magento\LayoutBlockRenderer;
use Cresset\TemplateParser\Magento\PhraseTranslator;
use Cresset\TemplateParser\Magento\PoolCustomDirectiveRenderer;
use Cresset\TemplateParser\Magento\StoreUrlBuilder;
use Cresset\TemplateParser\Magento\TypeCheckedWidgetRenderer;
use Cresset\TemplateParser\Magento\VariableCustomVariableReader;
use Cresset\TemplateParser\TemplateEngine;

/**
 * Builds an engine for a mode, wiring whatever ports the host can supply.
 *
 * Without a store this yields the built-in surface only - var, if, depend, for, else, trans
 * and inlinecss - which is enough to check syntax and to try things in the REPL. With one, every port this package
 * has an adapter for is connected, so {{block}}, {{layout}}, {{config}} and the rest resolve
 * against the real application and a merchant can see what their template will actually do.
 *
 * A port that cannot be constructed is skipped rather than fatal. A store missing
 * Magento_Variable should cost you {{customvar}}, not the tool.
 */
class EngineFactory
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
        return TemplateEngine::forHost($this->hostServices($storeId), $mode->options());
    }

    /** Which directives the current host can actually resolve, for reporting. */
    public function wiredDirectives(?int $storeId = null): array
    {
        return $this->create(Mode::Lenient, $storeId)->evaluator()->registered();
    }

    /**
     * The ports this host can supply, exposed so a tool can wrap them.
     *
     * `tools/record-store-ports.php` decorates each one to record what the engine asks for,
     * which is the only way to capture the guard decisions offline - the ports themselves
     * need a store, and the fixtures they produce must not.
     */
    public function hostServices(?int $storeId = null): HostServices
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
                    ? new LayoutBlockRenderer(
                        $layout,
                        $config,
                        // The narrowing etc/di.xml applies, restated: constructing the port
                        // directly bypasses DI, and the wider default let {{block output=}}
                        // reach toString as well.
                        ['toHtml'],
                        null,
                        // Mage-OS's deny list for exactly this directive. Null on a tree that
                        // predates it, which is the behaviour that tree has anyway.
                        $m->get(\Magento\Email\Model\Template\Filter\BlockDirectivePolicy::class),
                    )
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
            // Design and Filesystem are optional only in the sense that the loader still
            // works without them; with them it resolves the asset against the store's own
            // theme and prefers the deployed file, which is what the store's filter does.
            stylesheets: $this->build(static fn (MagentoContext $m): ?object
                => ($css = $m->get(\Magento\Email\Model\Template\Css\Processor::class))
                    && ($assets = $m->get(\Magento\Framework\View\Asset\Repository::class))
                    ? new AssetStylesheetLoader(
                        $css,
                        $assets,
                        $m->get(\Magento\Framework\View\DesignInterface::class),
                        $m->get(\Magento\Framework\Filesystem::class),
                    )
                    : null),
            config: $this->build(fn (MagentoContext $m): ?object
                => ($scope = $m->get(\Magento\Framework\App\Config\ScopeConfigInterface::class))
                    && ($vars = $m->get(\Magento\Variable\Model\Source\Variables::class))
                    ? new AllowlistedConfigReader(
                        $scope,
                        $vars,
                        $storeId,
                        $m->get(\Magento\Store\Model\Information::class),
                        $m->get(\Magento\Store\Model\StoreManagerInterface::class),
                    )
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
            customDirectives: $this->build(function (MagentoContext $m): ?object {
                $pool = $m->get(\Magento\Framework\Filter\SimpleDirective\ProcessorPool::class);
                if ($pool === null) {
                    return null;
                }

                $renderer = new PoolCustomDirectiveRenderer(
                    $pool,
                    $m->get(\Magento\Framework\Filter\DirectiveProcessor\Filter\FilterPool::class)
                );

                // A store that registered nothing gets no port at all. Equivalent to handing
                // back a renderer with no names - the spec learns nothing and the handler loop
                // registers nothing either way - so this is for the reader, not the behaviour.
                return $renderer->names() === [] ? null : $renderer;
            }),
            // No dependencies of its own: it calls getUrl() on the template model already
            // in scope, which is exactly what the legacy resolver does.
            templateUrls: new TemplateModelUrlBuilder(),
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
