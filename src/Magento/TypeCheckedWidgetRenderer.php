<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

use Magento\Framework\ObjectManager\ConfigInterface;
use Magento\Widget\Block\BlockInterface as WidgetBlockInterface;
use Magento\Widget\Model\Widget;
use MageOS\TemplateParser\Port\WidgetRenderer;
use MageOS\TemplateParser\Port\RefusedByPort;

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
    /**
     * @param string[]|null $allowedTypes
     * @param ?Widget $widgets the store's widget.xml declarations; generateWidget renders only
     *        a type one of them declares
     */
    public function __construct(
        private readonly \Magento\Framework\View\LayoutInterface $layout,
        private readonly ConfigInterface $objectManagerConfig,
        private readonly ?array $allowedTypes = null,
        private readonly ?RenderScope $scope = null,
        private readonly ?Widget $widgets = null
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

        // generateWidget renders nothing for a type no widget.xml declares - looked up as
        // written, as it does - nor for one it cannot build as a widget.
        if ($this->widgets !== null && $this->widgets->getWidgetByClassType($written) === null) {
            return '';
        }
        if (!$this->isWidgetType($type)) {
            return '';
        }

        // Only `frontend`, as {{block}} allows. generateWidget forwards any area, which made the
        // block resolve its template out of the adminhtml theme: `{{widget ... area="adminhtml"
        // template="Magento_Backend::..."}}` rendered an admin .phtml.
        foreach (array_keys($parameters) as $key) {
            if (DataKey::is($key, 'area') && strcasecmp(trim((string)$parameters[$key]), 'frontend') !== 0) {
                unset($parameters[$key]);
            }
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
