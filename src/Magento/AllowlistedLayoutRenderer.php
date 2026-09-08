<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

use Magento\Framework\App\State;
use Magento\Framework\View\LayoutFactory;
use MageOS\TemplateParser\Port\LayoutRenderer;

/**
 * {{layout}} restricted to handles the integrator has declared.
 *
 * A layout handle is a capability - it decides which blocks get built - and template text is
 * not a trustworthy source for one. The allowlist is required rather than optional: passing
 * null would mean any handle in the application is reachable from a template.
 */
final class AllowlistedLayoutRenderer implements LayoutRenderer
{
    /** @param string[] $allowedHandles */
    public function __construct(
        private readonly LayoutFactory $layoutFactory,
        private readonly State $appState,
        private readonly array $allowedHandles
    ) {
    }

    /** @param array<string,string> $parameters */
    public function render(string $handle, string $area, array $parameters): string
    {
        if (!in_array($handle, $this->allowedHandles, true)) {
            return '';
        }

        $render = function () use ($handle): string {
            $layout = $this->layoutFactory->create();
            $layout->getUpdate()->addHandle($handle)->load();
            $layout->generateXml();
            $layout->generateElements();

            return (string)$layout->getOutput();
        };

        return $area === $this->appState->getAreaCode()
            ? $render()
            : (string)$this->appState->emulateAreaCode($area, $render);
    }
}
