<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/**
 * Facade: parse once, evaluate once.
 *
 * Contrast with the legacy filter, which scans the *output* a second time. There is no
 * second scan here, so content introduced by a variable is never eligible for execution.
 */
final class TemplateEngine
{
    /*
     * Nullable, not `= new X()`.
     *
     * Magento's DI compiler stores a constructor default verbatim and writes it into
     * generated/metadata with var_export(), which emits `X::__set_state(...)` for an object.
     * No class here defines __set_state, so that file - loaded on every request in
     * production mode - is a fatal. Developer mode consumes the default directly and never
     * notices, so this shipped green.
     */
    private readonly Parser $parser;

    private readonly Evaluator $evaluator;

    public function __construct(?Parser $parser = null, ?Evaluator $evaluator = null)
    {
        $this->parser = $parser ?? new Parser();
        $this->evaluator = $evaluator ?? new Evaluator();
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
        if ($context !== null && ($variables !== [] || $policy !== null)) {
            // Silently dropping them is the dangerous reading: a caller tightening a render
            // by adding a policy argument would get no error and no policy.
            throw new \InvalidArgumentException(
                'render() takes either a $context or a $variables/$policy pair, not both - '
                . 'the context already carries its own variables and policy, so passing both '
                . 'leaves it ambiguous which should win'
            );
        }

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
