<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Magento\Framework\ObjectManager\ConfigInterface;
use Magento\Widget\Block\BlockInterface as WidgetBlockInterface;
use Cresset\TemplateParser\Port\WidgetRenderer;

/**
 * {{widget}} with the type validated BEFORE instantiation.
 *
 * Same discipline as LayoutBlockRenderer, and for the same reason: checking the constructed
 * instance is too late, because an arbitrary constructor has already run by then. An
 * optional allowlist narrows it further, since a widget type named in template text is
 * attacker-influenced wherever the template is.
 *
 * @see LayoutBlockRenderer
 */
final class TypeCheckedWidgetRenderer implements WidgetRenderer
{
    /** @param string[]|null $allowedTypes */
    public function __construct(
        private readonly \Magento\Framework\View\LayoutInterface $layout,
        private readonly ConfigInterface $objectManagerConfig,
        private readonly ?array $allowedTypes = null
    ) {
    }

    /** @param array<string,string> $parameters */
    public function render(string $type, array $parameters): string
    {
        $type = ltrim($type, '\\');

        if ($this->allowedTypes !== null && !in_array($type, $this->allowedTypes, true)) {
            return '';
        }

        if (!$this->isWidgetType($type)) {
            return '';
        }

        $widget = $this->layout->createBlock($type, '', ['data' => $parameters]);

        return $widget instanceof WidgetBlockInterface ? (string)$widget->toHtml() : '';
    }

    private function isWidgetType(string $type): bool
    {
        try {
            $resolved = $this->objectManagerConfig->getInstanceType(
                $this->objectManagerConfig->getPreference($type)
            );
        } catch (\Throwable) {
            return false;
        }

        return is_string($resolved)
            && (class_exists($resolved) || interface_exists($resolved))
            && is_a($resolved, WidgetBlockInterface::class, true);
    }
}
