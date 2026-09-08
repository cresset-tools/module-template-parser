<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

use MageOS\TemplateParser\Context;
use MageOS\TemplateParser\Evaluator;
use MageOS\TemplateParser\HostDirectives;
use MageOS\TemplateParser\Options;
use MageOS\TemplateParser\Parser;
use MageOS\TemplateParser\Port\BlockRenderer;
use MageOS\TemplateParser\Port\TemplateLoader;
use MageOS\TemplateParser\Port\Translator;
use MageOS\TemplateParser\TemplateEngine;

/**
 * Presents the engine with the shape Magento's template filter uses.
 *
 * Deliberately NOT registered as a DI preference for Magento\Framework\Filter\Template.
 * Swapping the engine under every stored template is a decision for the integrator, taken
 * after running ShadowComparator over real content - not a side effect of installing this
 * module.
 */
final class TemplateFilterAdapter
{
    private TemplateEngine $engine;
    private Context $context;

    public function __construct(
        ?BlockRenderer $blocks = null,
        ?Translator $translator = null,
        ?TemplateLoader $templates = null,
        private Options $options = new Options()
    ) {
        $parser = new Parser(options: $this->options);
        $evaluator = new Evaluator(options: $this->options);
        HostDirectives::register($evaluator, $blocks, $translator, $templates, $parser);

        $this->engine = new TemplateEngine($parser, $evaluator);
        $this->context = new Context();
    }

    /** @param array<string,mixed> $variables */
    public function setVariables(array $variables): self
    {
        $this->context = new Context($variables);
        return $this;
    }

    public function filter(string $value): string
    {
        return $this->engine->render($value, [], $this->context);
    }

    /** Deferred work collected during the last render (for example inline CSS files). */
    public function deferred(): array
    {
        return $this->context->deferred();
    }

    public function engine(): TemplateEngine
    {
        return $this->engine;
    }
}
