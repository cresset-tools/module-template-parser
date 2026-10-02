<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento\Plugin;

use Cresset\TemplateParser\Magento\Config\EngineMode;
use Cresset\TemplateParser\Magento\RenderScope;
use Cresset\TemplateParser\Magento\Shadow\ShadowOutcome;
use Cresset\TemplateParser\Magento\Shadow\ShadowRecorder;
use Cresset\TemplateParser\Magento\Shadow\TemplateIdentity;
use Cresset\TemplateParser\Magento\ShadowComparator;
use Magento\Framework\Filter\Template as LegacyTemplate;

/**
 * The integration point.
 *
 * A DI preference cannot be used to adopt this engine. Emails render through
 * Magento\Email\Model\Template\Filter, CMS extends that, and Newsletter extends
 * Widget\Model\Template\FilterEmulate - all concrete subclasses that DI instantiates
 * directly, so a preference for the Framework base class never applies. Interception is the
 * only mechanism that reaches them, which is what this is: the module's own di.xml declares it
 * on the Email filter, and a plugin on a class applies to its subclasses.
 *
 * Wired on install, and off until configured. Each render reads `system/template_engine/mode`
 * for the subject's store:
 *
 * - Legacy, the default: the filter runs and nothing else happens.
 * - Shadow: the filter runs and its result is served; this engine renders the same template
 *   as well, and the outcome is recorded against the store view and the template - see
 *   TemplateIdentity and ShadowRecorder.
 * - Parser: this engine's result is served, and the filter does not run - unless this engine
 *   declines the render (a refusal, the host raising, a crash), in which case the filter runs
 *   instead and its result is served, exactly as under Legacy. A sample of served renders,
 *   `system/template_engine/parser_shadow_rate` percent, is also rendered by the filter and
 *   compared, so a regression after the switch still shows up in the report.
 *
 * An around plugin because Parser has to be able NOT to run the filter. It is also what lets
 * each invocation keep its own scope in local variables: filter() is re-entrant - an include
 * or a CMS block renders inside its parent's filter() - and a before/after pair needed a stack
 * of frames to get the same right.
 *
 * The variables, the plain-text flag and the design are captured on the way past because the
 * filter keeps them in protected properties with setters and no getters. They are kept PER
 * FILTER, as the filter keeps them: the plugin is shared by every filter instance, and with one
 * slot a CMS block rendered inside an email was rendered with the email's variables.
 */
class TemplateFilterPlugin
{
    /**
     * What each filter instance has been told, as it would hold it itself.
     *
     * Weak, so a filter that is gone takes its entry with it.
     *
     * @var \WeakMap<object,array{variables:array<string,mixed>,plain:bool,design:array<string,mixed>}>
     */
    private \WeakMap $state;

    /** @var array<class-string,list<string>> filter class => directives only it can render */
    private array $legacyOnly = [];

    public function __construct(
        private readonly ShadowComparator $comparator,
        private readonly EngineMode $mode,
        private readonly TemplateIdentity $identity,
        private readonly ShadowRecorder $recorder,
        private readonly ?RenderScope $scope = null
    ) {
        $this->state = new \WeakMap();
    }

    /**
     * Merged, not replaced: `Framework\Filter\Template::setVariables()` assigns each name into
     * what the instance already holds, so a second call adds to the first.
     *
     * @param array<string,mixed> $variables
     * @return array{0:array<string,mixed>}
     */
    public function beforeSetVariables(LegacyTemplate $subject, array $variables): array
    {
        $state = $this->stateOf($subject);
        foreach ($variables as $name => $value) {
            $state['variables'][$name] = $value;
        }
        $this->state[$subject] = $state;

        return [$variables];
    }

    /**
     * Captured for the same reason the variables are: it is set on the subject, not on us.
     *
     * getProcessedTemplate() calls setPlainTemplateMode() on the filter it holds, so without
     * capturing it here the candidate render would use the HTML value of every custom variable
     * while the legacy render used the text one, and every plain email would report as a
     * divergence caused by nothing.
     *
     * `$plain` is untyped because the method it plugs into is: forwarded as given, cast only
     * for the copy kept here.
     *
     * @return array{0:mixed}
     */
    public function beforeSetPlainTemplateMode(LegacyTemplate $subject, $plain): array
    {
        $state = $this->stateOf($subject);
        $state['plain'] = (bool)$plain;
        $this->state[$subject] = $state;

        return [$plain];
    }

    /**
     * Captured for the same reason again: it is set on the subject, and by the time filter()
     * returns the emulation it was taken inside is gone.
     *
     * @param array<string,mixed> $designParams
     * @return array{0:array<string,mixed>}
     */
    public function beforeSetDesignParams(LegacyTemplate $subject, array $designParams): array
    {
        $state = $this->stateOf($subject);
        $state['design'] = $designParams;
        $this->state[$subject] = $state;

        return [$designParams];
    }

    /**
     * Decides which engine renders this invocation, and serves its result.
     *
     * `$value` is untyped because the method it wraps is; anything but a string goes to the
     * filter, which raises for it as it always has.
     */
    public function aroundFilter(LegacyTemplate $subject, callable $proceed, $value)
    {
        // A CHILD render belongs to legacy whatever the stage. The filter renders a child
        // only while rendering its parent - this engine loads {{template}} includes itself,
        // so a parent it serves never reaches one - and a child's output is not a document:
        // the filter defers {{inlinecss}} by emitting a SIGNED placeholder for its parent to
        // resolve, a contract only the legacy parent can keep. Nor is one worth comparing:
        // the signature is random per render, so it could never match, and the parent's
        // comparison covers the same content.
        if (!is_string($value) || (method_exists($subject, 'isChildTemplate') && $subject->isChildTemplate())) {
            return $proceed($value);
        }

        $storeId = $this->storeIdOf($subject);

        return match ($this->mode->forStore($storeId)) {
            EngineMode::SHADOW => $this->shadow($subject, $proceed, $value, $storeId),
            EngineMode::PARSER => $this->parser($subject, $proceed, $value, $storeId),
            default => $proceed($value),
        };
    }

    /** Serves legacy, and records how this engine would have done. */
    private function shadow(LegacyTemplate $subject, callable $proceed, string $value, int|string|null $storeId): mixed
    {
        // Taken before the filter runs, as the scope this invocation was called with: by the
        // time it returns, an include may have told the subject something else.
        $state = $this->stateOf($subject);
        $result = $proceed($value);

        try {
            $unmodelled = $this->unmodelledDirective($subject, $value);
            if ($unmodelled !== null) {
                $this->recorder->record($storeId, $this->templateOf($subject, $value), $unmodelled);

                return $result;
            }

            // Legacy has already put this render's stylesheets on the subject, so the finisher
            // only has to apply them. Registering them again would be harmless here, but not in
            // general: one this engine accepted and legacy did not would then be inlined into
            // later LEGACY renders on the same instance, which Shadow promises not to change.
            $outcome = $this->inScopeOf($subject, $storeId, fn () => $this->comparator->compare(
                $value,
                (string)$result,
                $state['variables'],
                $state['plain'],
                $this->finisher($subject, registerStylesheets: false),
                $state['design']
            ));
            $this->recorder->record($storeId, $this->templateOf($subject, $value), $outcome);
        } catch (\Throwable) {
            // The comparator and the recorder each contain their own failures; this is the
            // backstop for the promise that Shadow never changes a render.
        }

        return $result;
    }

    /**
     * Serves this engine's render, or legacy's when this engine declines.
     *
     * A sampled render runs the filter FIRST, then this engine. The order matters because the
     * filter is re-entrant through the host: a {{block}} in it can reach another filter and,
     * through this plugin, render through the same shared adapter - after which the adapter's
     * violations describe that render, not ours. Rendering ours last means judge() reads ours.
     */
    private function parser(LegacyTemplate $subject, callable $proceed, string $value, int|string|null $storeId): mixed
    {
        $state = $this->stateOf($subject);
        $template = $this->templateOf($subject, $value);

        $sampled = $this->sampled($storeId);
        $legacy = null;
        $legacyRaised = false;
        if ($sampled) {
            try {
                $legacy = $proceed($value);
            } catch (\Throwable) {
                // A legacy fatal: today's customer got an error here. Served by this engine
                // instead, which is no reason to give them one now, and nothing to compare
                // against. Should this engine decline too, the fallback below runs the filter
                // again and the fatal is theirs, as it is under Legacy.
                $legacyRaised = true;
            }
        }

        try {
            $candidate = $this->unmodelledDirective($subject, $value)
                ?? $this->inScopeOf($subject, $storeId, fn () => $this->comparator->render(
                    $value,
                    $state['variables'],
                    $state['plain'],
                    $this->finisher($subject, registerStylesheets: true),
                    $state['design']
                ));
        } catch (\Throwable $e) {
            // render() contains its own failures; this is the backstop.
            $candidate = ShadowOutcome::crashed($e);
        }

        if ($candidate instanceof ShadowOutcome) {
            // Declined: the customer gets exactly what Legacy would have given them. The
            // sampled render already is that, so it is not rendered twice.
            $this->record($storeId, $template, $candidate, served: false, fellBack: true);

            return $sampled && !$legacyRaised ? $legacy : $proceed($value);
        }

        $outcome = null;
        if ($sampled && !$legacyRaised) {
            try {
                $outcome = $this->comparator->judge($candidate, (string)$legacy);
            } catch (\Throwable) {
                // Nothing to record but the serve.
            }
        }
        $this->record($storeId, $template, $outcome, served: true, fellBack: false);

        return $candidate;
    }

    /**
     * Runs `$render` with the subject's store and URL model in RenderScope, for the ports that
     * answer differently per filter.
     *
     * The store is the `_storeId` the filter holds - null when it holds none, as generateWidget
     * tests with isset() - not the store config was read for.
     *
     * @template T
     * @param callable():T $render
     * @return T
     */
    private function inScopeOf(LegacyTemplate $subject, int|string|null $storeId, callable $render): mixed
    {
        if ($this->scope === null) {
            return $render();
        }

        $urlModel = property_exists($subject, 'urlModel')
            ? (function () {
                return $this->urlModel;
            })->call($subject)
            : null;

        $this->scope->push($storeId, is_object($urlModel) ? $urlModel : null);
        try {
            return $render();
        } finally {
            $this->scope->pop();
        }
    }

    /** Recording must never cost a render; the recorder contains its own failures too. */
    private function record(int|string|null $storeId, string $template, ?ShadowOutcome $outcome, bool $served, bool $fellBack): void
    {
        try {
            $this->recorder->record($storeId, $template, $outcome, $served, $fellBack);
        } catch (\Throwable) {
        }
    }

    /**
     * Whether this Parser render is also compared, at the store's configured rate.
     *
     * Per render rather than per template: a template rendered a thousand times a day is
     * compared about ten times at 1%, one rendered twice a week mostly not at all, which is
     * the right way round - the first is where a regression costs the most.
     */
    private function sampled(int|string|null $storeId): bool
    {
        $rate = $this->mode->parserShadowRate($storeId);

        return $rate >= 100.0 || ($rate > 0.0 && mt_rand(0, 999_999) < (int)round($rate * 10_000));
    }

    /**
     * What the host does to a FINISHED render: the subject's own inline-CSS step.
     *
     * Under Shadow, legacy has already registered the stylesheets on the subject. Under
     * Parser it has not run, so this engine's deferrals are registered first, through the
     * subject's own protected method - the one {{inlinecss}} calls - so the subject inlines
     * exactly what it would have. A filter without the step returns the render unfinished,
     * as it would have itself.
     *
     * A finisher that raises is left to raise. The comparator records it as a crash, which
     * in Parser mode means falling back to the filter - whose own catch is what deals with
     * an inliner failing today.
     *
     * @return ?callable(string,list<string>):string
     */
    private function finisher(LegacyTemplate $subject, bool $registerStylesheets): ?callable
    {
        if (!method_exists($subject, 'applyInlineCss')) {
            return null;
        }

        return static function (string $html, array $stylesheets = []) use ($subject, $registerStylesheets): string {
            if ($registerStylesheets && method_exists($subject, 'addInlineCssFile')) {
                (function () use ($stylesheets): void {
                    foreach ($stylesheets as $file) {
                        $this->addInlineCssFile($file);
                    }
                })->call($subject);
            }

            return (string)$subject->applyInlineCss($html);
        };
    }

    /**
     * A directive this template uses that only the subject itself knows how to render.
     *
     * The filter dispatches `{{name}}` to any public `nameDirective()` method by reflection;
     * this engine renders its own handlers and nothing else, so a directive it does not model
     * comes out as its own text - complete-looking, and served in Parser mode. Three kinds:
     *
     * - a method a module added, on a filter subclass or through a preference;
     * - a stock directive a module put a plugin on: the generated Interceptor redeclares the
     *   method, so whatever the plugin changes is behaviour only the filter has;
     * - `Cms\Model\Template\Filter`'s own `{{media}}`, which returns a FILESYSTEM path - the
     *   admin's WYSIWYG image preview opens it - where every other filter returns a URL. The
     *   Widget filter, which the storefront renders CMS through, restores the URL one;
     * - `{{widget}}` in `Widget\Model\Template\FilterEmulate` - the newsletter filter - which
     *   renders each widget in an emulated frontend area.
     *
     * Any of them in the source declines the render, and the filter renders it.
     */
    private function unmodelledDirective(LegacyTemplate $subject, string $value): ?ShadowOutcome
    {
        $names = $this->legacyOnly[$subject::class] ??= self::legacyOnlyDirectives($subject);
        if ($names === [] || !preg_match('/\{\{(' . implode('|', $names) . ')(?![a-z])/i', $value, $m)) {
            return null;
        }

        return ShadowOutcome::policyRefused([sprintf(
            'directive "%s" is rendered by %s itself, which this engine does not model',
            strtolower($m[1]),
            self::originalClass($subject::class)
        )]);
    }

    /** @return list<string> */
    private static function legacyOnlyDirectives(LegacyTemplate $subject): array
    {
        $names = [];
        foreach ((new \ReflectionClass($subject))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || !preg_match('/^([a-zA-Z]{1,10})Directive$/', $method->getName(), $m)) {
                continue;
            }
            $declaring = $method->getDeclaringClass()->getName();
            if (str_ends_with($declaring, '\\Interceptor') || !str_starts_with($declaring, 'Magento\\')) {
                $names[] = strtolower($m[1]);
            }
        }

        if (is_a($subject, 'Magento\\Cms\\Model\\Template\\Filter')
            && !is_a($subject, 'Magento\\Widget\\Model\\Template\\Filter')
        ) {
            $names[] = 'media';
        }

        // The newsletter filter renders each widget inside a frontend area emulation; this
        // engine renders it in whatever area is current, which in a newsletter send is not
        // frontend. Emulating around the whole render would move every OTHER directive too.
        if (is_a($subject, 'Magento\\Widget\\Model\\Template\\FilterEmulate')) {
            $names[] = 'widget';
        }

        return array_values(array_unique($names));
    }

    /** The class a generated Interceptor stands in for, for a message a person reads. */
    private static function originalClass(string $class): string
    {
        return str_ends_with($class, '\\Interceptor') ? (string)get_parent_class($class) : $class;
    }

    private function templateOf(LegacyTemplate $subject, string $value): string
    {
        return $this->identity->identify($value) ?? TemplateIdentity::unidentified($subject);
    }

    /** @return array{variables:array<string,mixed>,plain:bool,design:array<string,mixed>} */
    private function stateOf(LegacyTemplate $subject): array
    {
        return $this->state[$subject] ?? ['variables' => [], 'plain' => false, 'design' => []];
    }

    /**
     * The store this render belongs to, read without asking the subject for it.
     *
     * `Email\Model\Template\Filter::getStoreId()` is not a getter: when no store was set it
     * fills `$_storeId` from the current store and keeps it. The CMS filters are shared
     * instances that are never given a store, so calling it here would pin every later CMS
     * render to whichever store rendered first - a change to legacy behaviour made by the
     * one mode that promises none. So the property is read directly, and an unset store is
     * passed on as null, which config resolves as the current store: exactly what the
     * filter's own lazy lookup would have found.
     *
     * @return int|string|null
     */
    private function storeIdOf(LegacyTemplate $subject): int|string|null
    {
        if (!property_exists($subject, '_storeId')) {
            return null;
        }

        $storeId = (function () {
            return $this->_storeId;
        })->call($subject);

        return is_int($storeId) || is_string($storeId) ? $storeId : null;
    }
}
