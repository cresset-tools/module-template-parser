<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Magento\Framework\ObjectManager\ConfigInterface;
use Magento\Widget\Block\BlockInterface as WidgetBlockInterface;
use Cresset\TemplateParser\Port\WidgetRenderer;
use Cresset\TemplateParser\Port\RefusedByPort;

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
class TypeCheckedWidgetRenderer implements WidgetRenderer
{
    /** @param string[]|null $allowedTypes */
    public function __construct(
        private readonly \Magento\Framework\View\LayoutInterface $layout,
        private readonly ConfigInterface $objectManagerConfig,
        private readonly ?array $allowedTypes = null,
        private readonly ?RenderScope $scope = null
    ) {
    }

    /** @param array<string,string|null> $parameters */
    public function render(string $type, array $parameters): string
    {
        $written = $type;
        $type = ltrim($type, '\\');

        // An integrator's allowlist is stricter than generateWidget, which renders any
        // declared widget.
        if ($this->allowedTypes !== null && !in_array($type, $this->allowedTypes, true)) {
            throw new RefusedByPort('widget type', $type);
        }

        // generateWidget renders nothing for a type it cannot build as a widget either.
        if (!$this->isWidgetType($type)) {
            return '';
        }

        // generateWidget's data, exactly: the parameters as written, `type` included; the
        // filter's store as `store_id` when it has one and the template did not give one;
        // and `name` as the block's name in the layout.
        $data = ['type' => $written] + $parameters;
        $storeId = $this->scope?->storeId();
        if ($storeId !== null && !isset($data['store_id'])) {
            $data['store_id'] = $storeId;
        }
        $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : null;

        $widget = $this->layout->createBlock($type, $name, ['data' => $data]);

        return $widget instanceof WidgetBlockInterface ? (string)$widget->toHtml() : '';
    }

    private function isWidgetType(string $type): bool
    {
        return DeclaredType::resolvesTo($this->objectManagerConfig, $type, WidgetBlockInterface::class);
    }
}
