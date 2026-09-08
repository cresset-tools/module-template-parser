<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

/**
 * Evaluation scope for one render.
 *
 * Deferred work is recorded as structured entries, not as text spliced back into the
 * output. A child render hands its entries to its caller, so nothing needs to survive in
 * the output stream and therefore nothing needs to be signed. That removes the mechanism
 * the signature-smuggling class of bug depends on.
 */
final class Context
{
    /** @var array<string,mixed> */
    private array $variables;

    /** @var array<int,array{kind:string,payload:array}> */
    private array $deferred = [];

    /** @var string[] template paths currently being rendered, outermost first */
    private array $includeStack = [];

    /** @var \MageOS\TemplateParser\LegacyIncompatibility[] */
    private array $incompatibilities = [];

    /** @var \MageOS\TemplateParser\PolicyViolation[] */
    private array $violations = [];

    private RenderPolicy $policy;

    /** @param array<string,mixed> $variables */
    public function __construct(array $variables = [], ?RenderPolicy $policy = null)
    {
        $this->variables = $variables;
        $this->policy = $policy ?? RenderPolicy::unrestricted();
    }

    public function policy(): RenderPolicy
    {
        return $this->policy;
    }

    public function recordViolation(PolicyViolation $violation): void
    {
        $this->violations[] = $violation;
    }

    /** @return \MageOS\TemplateParser\PolicyViolation[] */
    public function violations(): array
    {
        return $this->violations;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->variables);
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->variables);
    }

    public function get(string $name): mixed
    {
        return $this->variables[$name] ?? null;
    }

    /** @param array<string,mixed> $variables */
    public function withVariables(array $variables): self
    {
        $clone = new self($this->variables + []);
        foreach ($variables as $k => $v) {
            $clone->variables[$k] = $v;
        }
        // The include stack and the policy are properties of the render, not the scope, so
        // a child scope inherits both - a nested template cannot escape its parent's policy.
        $clone->includeStack = $this->includeStack;
        $clone->policy = $this->policy;
        return $clone;
    }

    /**
     * Marks a template include as in progress.
     *
     * Returns false when that path is already being rendered further up the stack, which is
     * a cycle: without this a template that includes itself recurses until the process dies.
     */
    public function enterInclude(string $path): bool
    {
        if (in_array($path, $this->includeStack, true)) {
            return false;
        }
        $this->includeStack[] = $path;
        return true;
    }

    public function leaveInclude(): void
    {
        array_pop($this->includeStack);
    }

    /** @return string[] */
    public function includeStack(): array
    {
        return $this->includeStack;
    }

    public function includeDepth(): int
    {
        return count($this->includeStack);
    }

    /**
     * Records a construct that renders here but not on the legacy filter.
     *
     * @param \MageOS\TemplateParser\LegacyIncompatibility[] $found
     */
    public function noteIncompatibilities(array $found): void
    {
        foreach ($found as $item) {
            $this->incompatibilities[] = $item;
        }
    }

    /** @return \MageOS\TemplateParser\LegacyIncompatibility[] */
    public function incompatibilities(): array
    {
        return $this->incompatibilities;
    }

    public function defer(string $kind, array $payload): void
    {
        $this->deferred[] = ['kind' => $kind, 'payload' => $payload];
    }

    /** @return array<int,array{kind:string,payload:array}> */
    public function deferred(): array
    {
        return $this->deferred;
    }

    /**
     * Merge a child render's deferred work into this scope. Explicit hand-back up one
     * level; composes recursively without any shared or request-scoped state.
     */
    public function absorb(self $child): void
    {
        foreach ($child->deferred as $entry) {
            $this->deferred[] = $entry;
        }
        foreach ($child->incompatibilities as $item) {
            $this->incompatibilities[] = $item;
        }
        foreach ($child->violations as $violation) {
            $this->violations[] = $violation;
        }
    }
}
