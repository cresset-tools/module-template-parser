<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

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
 */
class LayoutBlockRenderer implements BlockRenderer
{
    private const DEFAULT_OUTPUT_METHODS = ['toHtml', 'toString'];

    /** @param string[] $allowedOutputMethods */
    public function __construct(
        private readonly LayoutInterface $layout,
        private readonly ConfigInterface $objectManagerConfig,
        private readonly array $allowedOutputMethods = self::DEFAULT_OUTPUT_METHODS,
        private readonly ?array $allowedClasses = null
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

        if (!$this->isBlockType($class)) {
            return '';
        }

        $block = $this->layout->createBlock($class, '', ['data' => $data]);
        if (!$block instanceof BlockInterface || !method_exists($block, $method)) {
            return '';
        }

        return (string)$block->{$method}();
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
