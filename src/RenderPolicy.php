<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/**
 * What a single render is allowed to do.
 *
 * Capability is not a property of the engine, it is a property of the template: a stock
 * transactional email and a merchant-edited CMS block reach the same filter but deserve
 * different trust. A DI-time allowlist cannot express that, because it is fixed for the
 * whole application.
 *
 * RESTRICTED by default: `new Context()` with no policy refuses {{block}}, {{widget}} and
 * {{layout}}, the directives that turn template text into a PHP class being constructed. The
 * safe set is enumerated rather than derived, so a directive added later defaults to denied.
 *
 * The Magento adapter is the deliberate exception - it replaces a filter that has no policy
 * at all, so imposing one silently would empty the item table of every stock order email.
 * See TemplateFilterAdapter.
 */
final class RenderPolicy
{
    /** @param string[]|null $directives @param string[]|null $blocks */
    private function __construct(
        private readonly ?array $directives = null,
        private readonly ?array $blocks = null,
        private readonly ?int $maxNestingDepth = null
    ) {
    }

    /**
     * Overrides the engine's nesting bound for this render.
     *
     * Depth is a property of the content, not the installation: a stock transactional
     * template and a merchant-edited CMS block have different shapes and deserve different
     * limits. Null leaves the engine's Options default in force.
     */
    public function withMaxNestingDepth(?int $depth): self
    {
        if ($depth !== null && $depth < 1) {
            throw new \InvalidArgumentException('maxNestingDepth must be at least 1');
        }

        return new self($this->directives, $this->blocks, $depth);
    }

    /** @return int|null null means "use the engine default" */
    public function maxNestingDepth(): ?int
    {
        return $this->maxNestingDepth;
    }

    /**
     * Directives that turn template text into a PHP class being loaded and constructed.
     *
     * {{block}} and {{widget}} name a class outright; {{layout}} names a handle, which
     * decides which blocks get built - the same capability at one remove.
     */
    public const INSTANTIATING = ['block', 'widget', 'layout'];

    /**
     * Everything else the engine implements. Enumerated rather than derived, so a directive
     * added later defaults to denied - the right direction for a security default.
     */
    public const NON_INSTANTIATING = [
        'var', 'if', 'depend', 'for', 'else', 'trans', 'inlinecss',
        'template', 'config', 'customvar', 'store', 'media', 'view', 'css', 'protocol',
    ];

    /**
     * The default: substitution, conditionals and the host lookups, but nothing that loads
     * and constructs a PHP class from a name written in template text.
     *
     * Grant those deliberately:
     *
     *     RenderPolicy::restricted()
     *         ->alsoAllowing(['block'])
     *         ->withAllowedBlocks([Order\Items::class]);
     */
    public static function restricted(): self
    {
        return new self(self::NON_INSTANTIATING, null);
    }

    /**
     * No restrictions at all - the legacy filter's posture.
     *
     * Every directive whose port the host supplied is reachable from template text,
     * including the ones that instantiate classes.
     */
    public static function unrestricted(): self
    {
        return new self();
    }

    /**
     * Only these directives may run. Everything else renders as nothing.
     *
     * `RenderPolicy::allowing(['var', 'if', 'depend'])` is a reasonable posture for content
     * a merchant can edit: substitution and conditionals, no capability to reach the host.
     *
     * An empty list refuses every directive, leaving the template as plain text.
     *
     * @param string[] $directives
     */
    public static function allowing(array $directives): self
    {
        return new self(array_values($directives), null);
    }

    /**
     * Only these classes may be instantiated by {{block}} and {{widget}}.
     *
     * An empty list refuses every block. Leading backslashes are normalised, so
     * `\Vendor\Block` and `Vendor\Block` are the same entry.
     *
     * @param string[] $classes
     */
    public function withAllowedBlocks(array $classes): self
    {
        return new self($this->directives, array_map(
            static fn (string $class): string => ltrim($class, '\\'),
            array_values($classes)
        ), $this->maxNestingDepth);
    }

    /** @param string[] $directives */
    public function withAllowedDirectives(array $directives): self
    {
        return new self(array_values($directives), $this->blocks, $this->maxNestingDepth);
    }

    /**
     * Widens an existing allowlist.
     *
     * @param string[] $directives
     */
    public function alsoAllowing(array $directives): self
    {
        if ($this->directives === null) {
            return $this;   // already unrestricted
        }

        return new self(
            array_values(array_unique([...$this->directives, ...$directives])),
            $this->blocks,
            $this->maxNestingDepth
        );
    }

    public function permitsDirective(string $name): bool
    {
        return $this->directives === null || in_array($name, $this->directives, true);
    }

    public function permitsBlock(string $class): bool
    {
        return $this->blocks === null || in_array(ltrim($class, '\\'), $this->blocks, true);
    }

    public function restrictsDirectives(): bool
    {
        return $this->directives !== null;
    }

    public function restrictsBlocks(): bool
    {
        return $this->blocks !== null;
    }
}
