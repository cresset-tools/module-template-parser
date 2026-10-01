<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Magento\Email\Model\Template\Filter\BlockDirectivePolicy;
use Magento\Framework\ObjectManager\ConfigInterface;
use Magento\Framework\View\Element\BlockInterface;
use Magento\Framework\View\LayoutInterface;
use Cresset\TemplateParser\Port\BlockRenderer;
use Cresset\TemplateParser\Port\RefusedByPort;

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
 * And the three the store's own filter applies. Skipping them would make this class strictly
 * MORE permissive than the thing it replaces - the wrong way round for a package whose
 * argument is safety:
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
    /**
     * `toHtml` and nothing else, unless a store says otherwise in its own di.xml.
     *
     * The default was `['toHtml', 'toString']` and both wirings overrode it to drop
     * `toString` - a default whose only use was being undone, and a footgun for anyone
     * constructing this port directly. Legacy accepts any public no-argument method on the
     * block; this is deliberately narrower, and now narrow by default.
     */
    private const DEFAULT_OUTPUT_METHODS = ['toHtml'];

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

    /** @param array<string,string|null> $data */
    public function render(string $class, array $data, string $method): string
    {
        $class = ltrim($class, '\\');

        // Case-INSENSITIVELY, and with the leading separator off both sides. PHP class names
        // are case-insensitive, so `magento\Cms\Block\Block` and `Magento\Cms\Block\Block`
        // are one class; a strict in_array made them two, and an integrator who wrote either
        // the wrong case or a leading `\` in di.xml got a directive that silently rendered
        // nothing forever. Fail-closed either way - this list only ever permits a spelling of
        // a class the integrator has already named - and the deny list still runs after it.
        // An integrator's allowlist is stricter than the filter, which builds any class.
        if ($this->allowedClasses !== null && !$this->isAllowedClass($class)) {
            throw new RefusedByPort('block class', $class);
        }

        // blockDirective calls ANY public method `output=` names, and falls back to toHtml()
        // for one that does not exist. This calls only the allowed ones - so where legacy
        // would have rendered something, this refuses out loud rather than render nothing.
        if (!in_array($method, $this->allowedOutputMethods, true)) {
            throw new RefusedByPort('block output method', $method);
        }

        // The deny list is legacy's own (BlockDirectivePolicy), and legacy renders '' for it
        // too - so that one stays quiet. A class that is not a block, or does not exist,
        // makes the filter's createBlock() raise, which its catch turns into an error page:
        // not this ''. Refused, so Parser leaves that to the filter.
        if ($this->isRestricted($class)) {
            return '';
        }
        if (!$this->isBlockType($class)) {
            throw new RefusedByPort('block class', $class);
        }

        // Only `frontend`, trimmed and case-insensitively, exactly as blockDirective() does -
        // and dropped rather than refused, so the block still renders in its proper area.
        if (isset($data['area']) && strcasecmp(trim((string)$data['area']), 'frontend') !== 0) {
            unset($data['area']);
        }

        $block = $this->layout->createBlock($class, '', ['data' => $data]);
        if (!$block instanceof BlockInterface) {
            return '';
        }
        // Legacy falls back to toHtml() for a method the block does not have.
        if (!method_exists($block, $method)) {
            throw new RefusedByPort('block output method', $method);
        }

        // Again on what was actually built. DI preferences and virtual types mean the written
        // name and the constructed class need not be the same, and only the second one is
        // the truth about what is about to run.
        if ($this->isRestricted($block::class)) {
            return '';
        }

        return (string)$block->{$method}();
    }

    private function isAllowedClass(string $class): bool
    {
        foreach ($this->allowedClasses ?? [] as $allowed) {
            if (strcasecmp($class, ltrim((string)$allowed, '\\')) === 0) {
                return true;
            }
        }

        return false;
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
        return DeclaredType::resolvesTo($this->objectManagerConfig, $class, BlockInterface::class);
    }
}
