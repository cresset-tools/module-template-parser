<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\Diagnostics;
use Cresset\TemplateParser\LegacyReading;
use Cresset\TemplateParser\PolicyViolation;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\RenderPolicy;
use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\TemplateEngine;
use Cresset\TemplateParser\TemplateError;

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

    private ?\Exception $lastError = null;

    /** See unwiredDirectives(). */
    private ?string $unwired = null;

    private readonly Options $options;

    private readonly RenderPolicy $defaultPolicy;

    private ?RenderPolicy $policy = null;

    private bool $plainTemplateMode = false;

    /** @var array<string,mixed> */
    private array $designParams = [];

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
        // COMPATIBLE, not the strict default. This class stands in for
        // Magento\Framework\Filter\Template, and the strict default refuses what that filter
        // renders - an unknown variable raises instead of rendering empty, which is a thing
        // stock templates do constantly. Shadow mode over the 48 stock email templates raised
        // 85 times on that alone before this. Pass Options explicitly to choose otherwise.
        $this->options = $options ?? Options::compatible();
        $this->defaultPolicy = $policy ?? RenderPolicy::unrestricted();
        $this->engine = TemplateEngine::forHost($services, $this->options);
        $this->context = new Context();
        $this->unwired = self::unwiredDirectives($services);
    }

    /**
     * The filter's directives this adapter has no port for, as a pattern; null when it has
     * every one.
     *
     * A directive with no port stays unregistered, and renders as its own text - where the
     * filter renders it. The module's di.xml wires every port, so this is a host that left one
     * out; a render that uses such a directive records it, and is declined rather than served.
     */
    private static function unwiredDirectives(HostServices $services): ?string
    {
        $ports = [
            'block' => $services->blocks,
            'template' => $services->templates,
            'config' => $services->config,
            'customvar' => $services->customVariables,
            'store' => $services->urls,
            'media' => $services->urls,
            'view' => $services->urls,
            'protocol' => $services->urls,
            'css' => $services->stylesheets,
            'layout' => $services->layouts,
            'widget' => $services->widgets,
            // The built-in {{trans}} renders untranslated without one.
            'trans' => $services->translator,
        ];
        $missing = array_keys(array_filter($ports, static fn (?object $port): bool => $port === null));

        // `(?![a-z])` because the filter's name is every letter up to ten: `{{stores}}` is not
        // {{store}} to it either.
        return $missing === [] ? null : '/\{\{(' . implode('|', $missing) . ')(?![a-z])/i';
    }

    /** The exception the last filter() swallowed, or null if it swallowed none. */
    public function lastError(): ?\Exception
    {
        return $this->lastError;
    }

    /** @param array<string,mixed> $variables */
    public function setVariables(array $variables): static
    {
        $this->variables = $variables;
        return $this;
    }

    public function setPlainTemplateMode(bool $plain): static
    {
        $this->plainTemplateMode = $plain;
        return $this;
    }

    /** @param array<string,mixed> $designParams */
    public function setDesignParams(array $designParams): static
    {
        $this->designParams = $designParams;
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
        //
        // Held locally and published only when the render is over, because filter() is
        // RE-ENTRANT through the host: a {{block}} that renders a CMS block reaches that
        // block's filter, whose plugin renders it through this same shared adapter in the
        // middle of the outer render. Published on the way in, the inner render's context
        // replaced the outer one, and the outer caller then read the inner render's
        // deferrals, violations and error as its own. Published on the way out, each caller
        // reads the render it just made, because the inner one finishes first.
        $context = new Context($this->variables, $this->policy ?? $this->defaultPolicy, $this->plainTemplateMode, $this->designParams);
        $error = null;

        try {
            $rendered = $this->engine->render($value, context: $context);
            $this->noteUnwired($value, $context);
            $this->noteLegacyReading($value, $context);

            return $rendered;
        } catch (TemplateError $e) {
            // This engine's own diagnostics are the product, not a failure to hide. Shadow
            // mode reports them as refusals, `check` turns them into findings, and a caller
            // that wanted them swallowed can catch them itself.
            throw $e;
        } catch (\Exception $e) {
            // Email\Model\Template\Filter::filter() catches \Exception and substitutes this
            // string, so one bad directive costs a template rather than the request. A
            // drop-in that lets the exception out turns a degraded email into a 500 - and a
            // block raising is not rare: 595 of the 1480 block classes in a stock store do it
            // when instantiated with no data, 52 of them ordinary frontend blocks a CMS editor
            // could name.
            //
            // So this arm is host code raising - a block's InvalidArgumentException, a
            // ValidatorException from the view layer - which is exactly what the filter's
            // own catch is for. lastError() says it happened: Parser mode hands such a render
            // to legacy rather than serving this imitation of the filter's message.
            //
            // \Error is deliberately not caught either, matching the filter: a TypeError from
            // a template is how a legacy fatal is detected, and swallowing it would hide the
            // one thing compatible mode is measured on.
            $error = $e;

            return (string)__('Error filtering template: %1', $e->getMessage());
        } finally {
            $this->context = $context;
            $this->lastError = $error;
        }
    }

    /**
     * Records where the filter would read this template differently - see LegacyReading.
     *
     * Only for the compatible posture, which is the one that claims to render as the filter
     * does; the others render differently on purpose and say so.
     */
    private function noteLegacyReading(string $source, Context $context): void
    {
        if (!$this->options->legacyQuirks) {
            return;
        }

        $difference = LegacyReading::firstDifference($source);
        if ($difference === null) {
            return;
        }

        ['line' => $line, 'column' => $column] = Diagnostics::locate($source, $difference['offset']);
        $context->recordViolation(new PolicyViolation('construct the filter reads differently', $difference['rule'], $line, $column));
    }

    /** Records each use of a directive this adapter has no port for, where it is. */
    private function noteUnwired(string $source, Context $context): void
    {
        if ($this->unwired === null
            || !preg_match_all($this->unwired, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)
        ) {
            return;
        }

        foreach ($matches as $match) {
            ['line' => $line, 'column' => $column] = Diagnostics::locate($source, $match[0][1]);
            $context->recordViolation(new PolicyViolation('unwired directive', strtolower($match[1][0]), $line, $column));
        }
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
}
