<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Magento\Email\Model\Template\Filter\BlockDirectivePolicy;
use Magento\Framework\ObjectManager\ConfigInterface;
use Magento\Framework\View\Element\BlockInterface;
use Magento\Framework\View\LayoutInterface;
use Cresset\TemplateParser\Port\BlockRenderer;

/**
 * Renders {{block}} through Magento's layout, with the two constraints the core factories
 * learned the hard way.
 *
 * 1. The type is validated BEFORE instantiation, resolving DI preferences and virtual types
 *    first. Checking the constructed instance is too late - an arbitrary constructor has
 *    already run.
 * 2. The method the template may invoke is an allowlist. The legacy `output=` parameter
 *    accepted any public no-argument method on the block.
 *
 * And the three the store's own filter applies, which this class did not and which made it
 * strictly MORE permissive than the thing it replaces - the wrong way round for a package
 * whose argument is safety:
 *
 * 3. `Filter\BlockDirectivePolicy`, the deny list Mage-OS added for exactly this directive.
 *    It denies `\Block\Adminhtml\`, `\Block\Backend\` and friends - 919 of the 1480 block
 *    classes in a stock store. Resolved from the object manager rather than reimplemented, so
 *    a store that tightens its di.xml tightens this too.
 * 4. The SAME check again on `get_class($block)`. A class name is a spelling; DI resolves
 *    preferences and virtual types, so the thing built can be a class the written name is not.
 * 5. `area` may only be `frontend`. Forwarding it made Magento resolve the block's template
 *    out of the adminhtml theme, so `{{block class=...Template area=adminhtml
 *    template=Magento_Backend::page/js/require_js.phtml}}` executed an admin .phtml - from a
 *    template, or from a variable's value.
 */
class LayoutBlockRenderer implements BlockRenderer
{
    private const DEFAULT_OUTPUT_METHODS = ['toHtml', 'toString'];

    /**
     * @param string[] $allowedOutputMethods
     * @param ?string[] $allowedClasses
     * @param ?BlockDirectivePolicy $blockDirectivePolicy Mage-OS's deny list for this
     *        directive. Typed concretely because a compiled DI factory decides how to resolve
     *        an argument from its declared type and hands an `object` one the raw descriptor
     *        array; nullable so a tree predating the policy can pass null, which is the
     *        behaviour such a tree has anyway.
     */
    public function __construct(
        private readonly LayoutInterface $layout,
        private readonly ConfigInterface $objectManagerConfig,
        private readonly array $allowedOutputMethods = self::DEFAULT_OUTPUT_METHODS,
        private readonly ?array $allowedClasses = null,
        private readonly ?BlockDirectivePolicy $blockDirectivePolicy = null
    ) {
    }

    /** @param array<string,string> $data */
    public function render(string $class, array $data, string $method): string
    {
        $class = ltrim($class, '\\');

        if ($this->allowedClasses !== null && !in_array($class, $this->allowedClasses, true)) {
            return '';
        }

        if (!in_array($method, $this->allowedOutputMethods, true)) {
            return '';
        }

        if (!$this->isBlockType($class) || $this->isRestricted($class)) {
            return '';
        }

        // Only `frontend`, trimmed and case-insensitively, exactly as blockDirective() does -
        // and dropped rather than refused, so the block still renders in its proper area.
        if (isset($data['area']) && strcasecmp(trim((string)$data['area']), 'frontend') !== 0) {
            unset($data['area']);
        }

        $block = $this->layout->createBlock($class, '', ['data' => $data]);
        if (!$block instanceof BlockInterface || !method_exists($block, $method)) {
            return '';
        }

        // Again on what was actually built. DI preferences and virtual types mean the written
        // name and the constructed class need not be the same, and only the second one is
        // the truth about what is about to run.
        if ($this->isRestricted($block::class)) {
            return '';
        }

        return (string)$block->{$method}();
    }

    /**
     * Whether the store's own policy refuses this class.
     *
     * Deliberately not a reimplementation of the patterns: a store that adds a rule to its
     * di.xml gets it here for free, and a store whose Magento predates the policy gets the
     * behaviour it has today. Any failure is treated as a refusal - a policy that cannot
     * answer is not a licence to instantiate.
     */
    private function isRestricted(string $class): bool
    {
        if ($this->blockDirectivePolicy === null) {
            return false;
        }

        try {
            return (bool)$this->blockDirectivePolicy->isRestricted($class);
        } catch (\Throwable) {
            return true;
        }
    }

    private function isBlockType(string $class): bool
    {
        try {
            $resolved = $this->objectManagerConfig->getInstanceType(
                $this->objectManagerConfig->getPreference($class)
            );
        } catch (\Throwable) {
            return false;
        }

        if (!is_string($resolved) || (!class_exists($resolved) && !interface_exists($resolved))) {
            return false;
        }

        return is_a($resolved, BlockInterface::class, true);
    }
}
