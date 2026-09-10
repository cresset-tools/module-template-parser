<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

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

    /**
     * Total includes spent in this render, shared by reference with every child scope.
     *
     * An object, not an int, precisely so the clone in withVariables() keeps pointing at the
     * same counter: a budget each scope could reset would not be a budget.
     */
    private \stdClass $includeBudget;

    /** @var \Cresset\TemplateParser\LegacyIncompatibility[] */
    private array $incompatibilities = [];

    /** @var \Cresset\TemplateParser\PolicyViolation[] */
    private array $violations = [];

    private RenderPolicy $policy;

    /**
     * @param array<string,mixed> $variables
     * @param bool $plainText the template is being rendered as the PLAIN part of an email
     */
    public function __construct(
        array $variables = [],
        ?RenderPolicy $policy = null,
        private readonly bool $plainText = false
    ) {
        $this->variables = $variables;
        $this->policy = $policy ?? RenderPolicy::restricted();
        $this->includeBudget = (object)['spent' => 0];
    }

    /**
     * Whether this render is the plain-text part of an email.
     *
     * Three directives change behaviour on it in the filter: {{customvar}} reads the variable's
     * TEXT value rather than its HTML one, and {{css}} and {{inlinecss}} render nothing at all,
     * a stylesheet in a text/plain body being noise at best. A property of the template being
     * rendered rather than of the engine's posture, so it lives here and not in Options.
     */
    public function plainText(): bool
    {
        return $this->plainText;
    }

    public function policy(): RenderPolicy
    {
        return $this->policy;
    }

    public function recordViolation(PolicyViolation $violation): void
    {
        $this->violations[] = $violation;
    }

    /** @return \Cresset\TemplateParser\PolicyViolation[] */
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
        // Plain-text mode is inherited for the same reason the policy is: it describes the
        // document being produced, and an included template is part of that same document.
        $clone = new self($this->variables + [], null, $this->plainText);
        foreach ($variables as $k => $v) {
            $clone->variables[$k] = $v;
        }
        // The include stack and the policy are properties of the render, not the scope, so
        // a child scope inherits both - a nested template cannot escape its parent's policy.
        $clone->includeStack = $this->includeStack;
        $clone->policy = $this->policy;
        $clone->includeBudget = $this->includeBudget;
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
        $this->includeBudget->spent++;
        return true;
    }

    /** Whether this render has used up its total include allowance. */
    public function includeBudgetExhausted(): bool
    {
        return $this->includeBudget->spent >= Options::DEFAULT_MAX_INCLUDES;
    }

    public function includesSpent(): int
    {
        return $this->includeBudget->spent;
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
     * @param \Cresset\TemplateParser\LegacyIncompatibility[] $found
     */
    public function noteIncompatibilities(array $found): void
    {
        foreach ($found as $item) {
            $this->incompatibilities[] = $item;
        }
    }

    /** @return \Cresset\TemplateParser\LegacyIncompatibility[] */
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
