<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

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

    /**
     * How deep {{template}} includes may go.
     *
     * Cycle detection alone is not a bound: a chain of DISTINCT paths never repeats, and a
     * fan-out of distinct paths multiplies. Stock templates include a header and a footer,
     * one level deep.
     */
    public const DEFAULT_MAX_INCLUDE_DEPTH = 5;

    /**
     * Total {{template}} loads allowed in one render.
     *
     * The depth bound alone does not bound work: a body may hold any number of includes,
     * so five levels of B-way fan-out is B^5 renders with every path distinct, which
     * cycle detection never sees. This caps the total instead of the depth.
     */
    public const DEFAULT_MAX_INCLUDES = 64;

    public function __construct(
        public readonly bool $strictSyntax = true,
        public readonly bool $strictDirectives = true,
        public readonly bool $strictVariables = true,
        public readonly int $maxNestingDepth = self::DEFAULT_MAX_NESTING_DEPTH,
        public readonly bool $legacyQuirks = false,
        public readonly bool $refuseLegacyIncompatible = false,
        public readonly bool $failOnPolicyViolation = false,
        /**
         * Encode `{{` in resolved directive output, as Mage-OS's
         * Template\DirectiveOutputNeutralizer does.
         *
         * Added upstream with the StyleSmuggler hardening, and it changes observable
         * rendering: a variable whose value contains `{{` comes back with the braces encoded.
         * On by default in compatible mode, because that is the filter merchants run once
         * they update; turn it off to target a tree from before the hardening.
         *
         * This engine needs none of it - a value is never re-parsed here whatever the
         * setting - so outside compatible mode it does nothing.
         */
        public readonly bool $neutralizeDirectiveOutput = true
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
     * Reproduces the observable quirks real templates may unknowingly depend on: legacy
     * truthiness, partially-resolved variable paths, arrays rendering as the literal string
     * "Array", directives passing through verbatim when no variables are set, member access
     * only through getData(), and fail-open unknown modifiers and escape types.
     *
     * It REFUSES what the legacy filter cannot render rather than reproducing the crash -
     * see refuseLegacyIncompatible, which this mode turns on. Same-name nesting, a name not
     * starting with a letter, a modifier given arguments and the rest all raise a
     * LegacyIncompatibleError with a diagnostic instead of a TypeError. Nesting is bounded
     * by repeated names, not by depth: three distinct names nest fine on the real filter.
     *
     * It deliberately does NOT reproduce:
     *  - the security behaviour (a value is still never re-parsed as source, and {{trans}}
     *    arguments are still escaped);
     *  - reflection-based dispatch of arbitrary filter methods.
     *
     * This is the mode to run in production first: same output, fewer ways to be exploited.
     */
    public static function compatible(): self
    {
        return new self(false, false, false, self::DEFAULT_MAX_NESTING_DEPTH, true, true, false, true);
    }

    public function withLegacyQuirks(bool $enabled): self
    {
        return new self($this->strictSyntax, $this->strictDirectives, $this->strictVariables,
            $this->maxNestingDepth, $enabled, $this->refuseLegacyIncompatible,
            $this->failOnPolicyViolation, $this->neutralizeDirectiveOutput);
    }

    /**
     * Refuse constructs the legacy filter cannot render, instead of rendering them.
     *
     * On by default in compatible mode. Compatible means bug-for-bug: an engine that renders
     * what the old one crashes on is not a compatible engine, it is a better one - and the
     * modes for wanting that are `lenient` and `strict`. Keeping the capability identical
     * also means a rollback to the legacy filter stays possible.
     *
     * Turn it off for a compatible-plus mode, which renders those constructs and records
     * them on the Context instead:
     *
     *     Options::compatible()->withRefuseLegacyIncompatible(false)
     */
    public function withRefuseLegacyIncompatible(bool $refuse): self
    {
        return new self($this->strictSyntax, $this->strictDirectives, $this->strictVariables,
            $this->maxNestingDepth, $this->legacyQuirks, $refuse, $this->failOnPolicyViolation,
            $this->neutralizeDirectiveOutput);
    }

    /**
     * Raise on a RenderPolicy violation instead of rendering nothing and recording it.
     *
     * Off by default: a policy violation should not take down an order email. Turn it on
     * where a violation means the template is wrong and should be caught - template
     * validation, tests, CI.
     */
    public function withFailOnPolicyViolation(bool $fail): self
    {
        return new self($this->strictSyntax, $this->strictDirectives, $this->strictVariables,
            $this->maxNestingDepth, $this->legacyQuirks, $this->refuseLegacyIncompatible, $fail,
            $this->neutralizeDirectiveOutput);
    }

    public function withMaxNestingDepth(int $depth): self
    {
        return new self($this->strictSyntax, $this->strictDirectives, $this->strictVariables, $depth, $this->legacyQuirks, $this->refuseLegacyIncompatible, $this->failOnPolicyViolation, $this->neutralizeDirectiveOutput);
    }

    public function withSyntax(bool $strict): self
    {
        return new self($strict, $this->strictDirectives, $this->strictVariables, $this->maxNestingDepth, $this->legacyQuirks, $this->refuseLegacyIncompatible, $this->failOnPolicyViolation, $this->neutralizeDirectiveOutput);
    }

    public function withDirectives(bool $strict): self
    {
        return new self($this->strictSyntax, $strict, $this->strictVariables, $this->maxNestingDepth, $this->legacyQuirks, $this->refuseLegacyIncompatible, $this->failOnPolicyViolation, $this->neutralizeDirectiveOutput);
    }

    /** Targets a Mage-OS tree from before the StyleSmuggler hardening. */
    public function withOutputNeutralizer(bool $enabled): self
    {
        return new self($this->strictSyntax, $this->strictDirectives, $this->strictVariables,
            $this->maxNestingDepth, $this->legacyQuirks, $this->refuseLegacyIncompatible,
            $this->failOnPolicyViolation, $enabled);
    }

    public function withVariables(bool $strict): self
    {
        return new self($this->strictSyntax, $this->strictDirectives, $strict, $this->maxNestingDepth, $this->legacyQuirks, $this->refuseLegacyIncompatible, $this->failOnPolicyViolation, $this->neutralizeDirectiveOutput);
    }
}
