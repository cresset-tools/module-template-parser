<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

/**
 * What a single render is allowed to do.
 *
 * Capability is not a property of the engine, it is a property of the template: a stock
 * transactional email and a merchant-edited CMS block reach the same filter but deserve
 * different trust. A DI-time allowlist cannot express that, because it is fixed for the
 * whole application.
 *
 * Unrestricted by default, so adding a policy is opt-in and nothing changes for callers that
 * do not set one.
 */
final class RenderPolicy
{
    /** @param string[]|null $directives @param string[]|null $blocks */
    private function __construct(
        private readonly ?array $directives = null,
        private readonly ?array $blocks = null
    ) {
    }

    public static function unrestricted(): self
    {
        return new self();
    }

    /**
     * Only these directives may run. Everything else renders as nothing.
     *
     * `RenderPolicy::allowing('var', 'if', 'depend')` is a reasonable posture for content a
     * merchant can edit: substitution and conditionals, no capability to reach the host.
     */
    public static function allowing(string ...$directives): self
    {
        return new self(array_values($directives), null);
    }

    /** Only these classes may be instantiated by {{block}} and {{widget}}. */
    public function withAllowedBlocks(string ...$classes): self
    {
        return new self($this->directives, array_map(
            static fn (string $c): string => ltrim($c, '\\'),
            array_values($classes)
        ));
    }

    /** @param string[] $directives */
    public function withAllowedDirectives(array $directives): self
    {
        return new self(array_values($directives), $this->blocks);
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
