<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

/**
 * Facade: parse once, evaluate once.
 *
 * Contrast with the legacy filter, which scans the *output* a second time. There is no
 * second scan here, so content introduced by a variable is never eligible for execution.
 */
final class TemplateEngine
{
    public function __construct(
        private readonly Parser $parser = new Parser(),
        private readonly Evaluator $evaluator = new Evaluator()
    ) {
    }

    /** Builds an engine with a single strictness setting applied to both stages. */
    public static function withOptions(Options $options, DirectiveSpec $spec = new DirectiveSpec()): self
    {
        return new self(
            new Parser($spec, $options),
            new Evaluator(new VariableResolver($options->legacyQuirks), new ParameterParser(), $spec, $options)
        );
    }

    public static function lenient(): self
    {
        return self::withOptions(Options::lenient());
    }

    /** Bug-for-bug rendering compatibility with the legacy filter. See Options::compatible(). */
    public static function compatible(): self
    {
        return self::withOptions(Options::compatible());
    }

    /** @param array<string,mixed> $variables */
    public function render(
        string $source,
        array $variables = [],
        ?Context $context = null,
        ?RenderPolicy $policy = null
    ): string {
        $context ??= new Context($variables, $policy);
        $ast = $this->parser->parse($source, $context->policy()->maxNestingDepth());
        $context->noteIncompatibilities($ast->incompatibilities());

        return $this->evaluator->evaluate($ast, $context);
    }

    public function evaluator(): Evaluator
    {
        return $this->evaluator;
    }

    public function parser(): Parser
    {
        return $this->parser;
    }
}
