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

    /**
     * Total includes spent in this render, shared by reference with every child scope.
     *
     * An object, not an int, precisely so the clone in withVariables() keeps pointing at the
     * same counter: a budget each scope could reset would not be a budget.
     */
    private \stdClass $includeBudget;

    /** @var \MageOS\TemplateParser\LegacyIncompatibility[] */
    private array $incompatibilities = [];

    /** @var \MageOS\TemplateParser\PolicyViolation[] */
    private array $violations = [];

    private RenderPolicy $policy;

    /**
     * How many {{if}} or {{depend}} bodies enclose the node being rendered, in this
     * template - an includer's bodies are not counted. The legacy filter renders each body
     * with a filter() call of its own, and where a stylesheet is inlined depends on which
     * call resolves it - see Options::$deferralResolvedAtRoot.
     */
    private int $bodyDepth = 0;

    /** In an included template: the top-level template's body depth at the outermost include. */
    private ?int $includedAtBodyDepth = null;

    /**
     * @param array<string,mixed> $variables
     * @param bool $plainText the template is being rendered as the PLAIN part of an email
     * @param array<string,mixed> $designParams the area, theme and locale {{css}} resolves against
     */
    public function __construct(
        array $variables = [],
        ?RenderPolicy $policy = null,
        private readonly bool $plainText = false,
        private readonly array $designParams = []
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

    /**
     * The area, theme and locale {{css}} resolves against.
     *
     * A property of the render rather than of the engine, and passed rather than looked up -
     * see Port\StylesheetLoader::load() for why a live lookup resolves the wrong theme. Empty
     * when the caller has none to offer, which is the CMS surface.
     *
     * @return array<string,mixed>
     */
    public function designParams(): array
    {
        return $this->designParams;
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
        // Plain-text mode is inherited for the same reason the policy is: it describes the
        // document being produced, and an included template is part of that same document.
        $clone = new self($this->variables, null, $this->plainText, $this->designParams);
        foreach ($variables as $k => $v) {
            $clone->variables[$k] = $v;
        }
        // The include stack and the policy are properties of the render, not the scope, so
        // a child scope inherits both - a nested template cannot escape its parent's policy.
        $clone->includeStack = $this->includeStack;
        $clone->policy = $this->policy;
        $clone->includeBudget = $this->includeBudget;
        // The same template, in a narrower scope - a loop iteration - so the same position.
        $clone->bodyDepth = $this->bodyDepth;
        $clone->includedAtBodyDepth = $this->includedAtBodyDepth;
        return $clone;
    }

    /**
     * A scope for an included template: a document of its own, whose bodies are not the
     * top-level template's, remembering where in the top-level template it was included.
     *
     * @param array<string,mixed> $variables
     */
    public function forInclude(array $variables): self
    {
        $child = $this->withVariables($variables);
        $child->bodyDepth = 0;
        $child->includedAtBodyDepth = $this->topLevelBodyDepth();
        return $child;
    }

    public function enterBody(): void
    {
        $this->bodyDepth++;
    }

    public function leaveBody(): void
    {
        $this->bodyDepth--;
    }

    /**
     * How deep in {{if}} or {{depend}} bodies of the TOP-LEVEL template this point is: its
     * own body depth there, or the depth its outermost include sits at in an include.
     */
    public function topLevelBodyDepth(): int
    {
        return $this->includedAtBodyDepth ?? $this->bodyDepth;
    }

    /** Whether this scope renders an included template rather than the top-level one. */
    public function inInclude(): bool
    {
        return $this->includedAtBodyDepth !== null;
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
     * Merge a child render's deferred work, incompatibilities and policy violations into this
     * scope. A child holds no reference to its parent, so the caller hands all three up one
     * level explicitly rather than into a registry shared across the render.
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
