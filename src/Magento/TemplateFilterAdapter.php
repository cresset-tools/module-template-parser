<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\Evaluator;
use Cresset\TemplateParser\HostDirectives;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\Parser;
use Cresset\TemplateParser\RenderPolicy;
use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\TemplateEngine;

/**
 * Presents the engine with the shape Magento's template filter uses.
 *
 * Deliberately NOT registered as a DI preference for Magento\Framework\Filter\Template.
 * Swapping the engine under every stored template is a decision for the integrator, taken
 * after running ShadowComparator over real content - not a side effect of installing this
 * module.
 */
class TemplateFilterAdapter implements TemplateFilterInterface
{
    private TemplateEngine $engine;

    /** @var array<string,mixed> */
    private array $variables = [];

    /** The scope of the LAST render, rebuilt per filter() call. */
    private Context $context;

    private readonly Options $options;

    private readonly RenderPolicy $defaultPolicy;

    private ?RenderPolicy $policy = null;

    /**
     * @param RenderPolicy|null $policy what a render may do, when the caller does not say.
     *
     * Defaults to unrestricted, unlike Context, and deliberately: this class replaces
     * Magento\Framework\Filter\Template, which has no policy at all. Defaulting to
     * RenderPolicy::restricted() here would refuse {{block}} and {{layout}} - the item table
     * of every stock order, invoice, shipment and creditmemo email - and do it silently.
     * Tightening it is an explicit decision, made in di.xml or per render via setPolicy().
     */
    public function __construct(
        ?HostServices $services = null,
        ?Options $options = null,
        ?RenderPolicy $policy = null
    ) {
        $services ??= new HostServices();
        $this->options = $options ?? new Options();
        $this->defaultPolicy = $policy ?? RenderPolicy::unrestricted();
        $parser = new Parser(options: $this->options);
        $evaluator = new Evaluator(
            new \Cresset\TemplateParser\VariableResolver($this->options->legacyQuirks),
            new \Cresset\TemplateParser\ParameterParser(),
            new \Cresset\TemplateParser\DirectiveSpec(),
            $this->options
        );
        HostDirectives::register($evaluator, $services, $parser);

        $this->engine = new TemplateEngine($parser, $evaluator);
        $this->context = new Context();
    }

    /** @param array<string,mixed> $variables */
    public function setVariables(array $variables): static
    {
        $this->variables = $variables;
        $this->context = new Context($variables, $this->policy ?? $this->defaultPolicy);
        return $this;
    }

    public function setPolicy(?RenderPolicy $policy): static
    {
        $this->policy = $policy;
        return $this;
    }

    public function filter(string $value): string
    {
        // A fresh scope per call. Reusing one context makes deferred(), violations() and
        // incompatibilities() cumulative across every template this adapter has ever
        // filtered, so a caller acting on "the last render" acts on all of them.
        $this->context = new Context($this->variables, $this->policy ?? $this->defaultPolicy);

        return $this->engine->render($value, context: $this->context);
    }

    /** Deferred work collected during the last render (for example inline CSS files). */
    public function deferred(): array
    {
        return $this->context->deferred();
    }

    /** Policy refusals recorded during the last render. */
    public function violations(): array
    {
        return $this->context->violations();
    }

    /** Constructs the last render produced that the legacy filter could not have. */
    public function incompatibilities(): array
    {
        return $this->context->incompatibilities();
    }

    public function engine(): TemplateEngine
    {
        return $this->engine;
    }
}
