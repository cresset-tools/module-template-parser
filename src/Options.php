<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

/**
 * What the engine refuses to guess at.
 *
 * Strict is the default: a template that cannot be parsed, names a directive that does not
 * exist, or reads a variable that is not in scope is a mistake worth surfacing while it is
 * still being edited. Lenient exists for rendering the corpus already sitting in merchant
 * databases, and for shadow-mode comparison against the legacy filter.
 */
final class Options
{
    /** Stock Magento templates reach depth 2; 3 leaves headroom without allowing abuse. */
    public const DEFAULT_MAX_NESTING_DEPTH = 3;

    public function __construct(
        public readonly bool $strictSyntax = true,
        public readonly bool $strictDirectives = true,
        public readonly bool $strictVariables = true,
        public readonly int $maxNestingDepth = self::DEFAULT_MAX_NESTING_DEPTH,
        public readonly bool $legacyQuirks = false
    ) {
        if ($this->maxNestingDepth < 1) {
            throw new \InvalidArgumentException('maxNestingDepth must be at least 1');
        }
    }

    public static function strict(): self
    {
        return new self();
    }

    public static function lenient(): self
    {
        return new self(false, false, false);
    }

    /**
     * Bug-for-bug rendering compatibility with the legacy filter.
     *
     * Reproduces the observable quirks real templates may unknowingly depend on:
     * legacy truthiness, partially-resolved variable paths, non-scalar values rendering
     * empty, and directives passing through verbatim when no variables are set.
     *
     * It deliberately does NOT reproduce:
     *  - the security behaviour (a value is still never re-parsed as source);
     *  - the fatals (same-name nesting, empty directive names) — no template can depend
     *    on crashing;
     *  - reflection-based dispatch of arbitrary filter methods.
     *
     * This is the mode to run in production first: same output, fewer ways to be exploited.
     */
    public static function compatible(): self
    {
        return new self(false, false, false, self::DEFAULT_MAX_NESTING_DEPTH, true);
    }

    public function withLegacyQuirks(bool $enabled): self
    {
        return new self(
            $this->strictSyntax,
            $this->strictDirectives,
            $this->strictVariables,
            $this->maxNestingDepth,
            $enabled
        );
    }

    public function withMaxNestingDepth(int $depth): self
    {
        return new self($this->strictSyntax, $this->strictDirectives, $this->strictVariables, $depth, $this->legacyQuirks);
    }

    public function withSyntax(bool $strict): self
    {
        return new self($strict, $this->strictDirectives, $this->strictVariables, $this->maxNestingDepth, $this->legacyQuirks);
    }

    public function withDirectives(bool $strict): self
    {
        return new self($this->strictSyntax, $strict, $this->strictVariables, $this->maxNestingDepth, $this->legacyQuirks);
    }

    public function withVariables(bool $strict): self
    {
        return new self($this->strictSyntax, $this->strictDirectives, $strict, $this->maxNestingDepth, $this->legacyQuirks);
    }
}
