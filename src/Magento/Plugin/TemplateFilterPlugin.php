<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento\Plugin;

use MageOS\TemplateParser\Magento\CandidateRenders;
use MageOS\TemplateParser\Magento\Config\EngineMode;
use MageOS\TemplateParser\Magento\DirectiveMethods;
use MageOS\TemplateParser\Magento\HostOnlyDirectives;
use MageOS\TemplateParser\Magento\LegacyOnlyRenders;
use MageOS\TemplateParser\Magento\RenderScope;
use MageOS\TemplateParser\Magento\Shadow\ShadowOutcome;
use MageOS\TemplateParser\Magento\Shadow\ShadowRecorder;
use MageOS\TemplateParser\Magento\Shadow\TemplateIdentity;
use MageOS\TemplateParser\Magento\ShadowComparator;
use Magento\Framework\Filter\Template as LegacyTemplate;
use Magento\Framework\Interception\DefinitionInterface;
use Magento\Framework\Interception\PluginListInterface;

/**
 * The integration point.
 *
 * A DI preference cannot be used to adopt this engine. Emails render through
 * Magento\Email\Model\Template\Filter, CMS extends that, and Newsletter extends
 * Widget\Model\Template\FilterEmulate - all concrete subclasses that DI instantiates
 * directly, so a preference for the Framework base class never applies. Interception is the
 * only mechanism that reaches them, which is what this is: the module's own di.xml declares it
 * on `Framework\Filter\Template`, and a plugin on a class applies to its subclasses.
 *
 * On the base class rather than the Email filter so it also reaches the plain filter itself,
 * which `FilterManager::template()` builds for customer and store address formats - where
 * customer-entered values meet directive processing. Two kinds of filter are rendered, each
 * through its own adapter, and every other subclass is left alone:
 *
 * - the Email filter and its subclasses (CMS, Widget, Newsletter), as before;
 * - exactly `Framework\Filter\Template`, through an adapter that renders as it does - see
 *   Options::$plainFilter.
 *
 * Any other subclass - Catalog's, a module's own - adds directives of its own that neither
 * adapter models, so the plugin hands it straight to the filter in every stage. That check is
 * per class and cached, so for those it costs one array lookup.
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

    /**
     * @var array<class-string,?array{list:PluginListInterface,type:string,before:list<string>,after:list<string>,around:?string}>
     *      filter class => the plugins on filter() that run inside this one - see innerChain()
     */
    private array $innerChains = [];

    /** @var array<class-string,?string> filter class => self::EMAIL, self::PLAIN or null */
    private array $profiles = [];

    private const EMAIL = 'email';
    private const PLAIN = 'plain';
    private const EMAIL_FILTER = 'Magento\\Email\\Model\\Template\\Filter';

    /**
     * How deep each filter instance is inside a filter() of its own that legacy is running.
     *
     * The filter is re-entrant on ITSELF: {{depend}} and {{if}} render their body by calling
     * filter() on the same instance, mid-render. Such a call is not a template, it is part of
     * the one legacy is already rendering. Shadow recorded every body as a template of its
     * own - 20 of the first 119 renders on the demo store were `unidentified` bodies - and
     * Parser was worse: a sampled or fallback render, promised to be exactly legacy's, had
     * each body served by this engine inside it.
     *
     * @var \WeakMap<object,int>
     */
    private \WeakMap $legacyRunning;

    public function __construct(
        private readonly ShadowComparator $comparator,
        private readonly EngineMode $mode,
        private readonly TemplateIdentity $identity,
        private readonly ShadowRecorder $recorder,
        private readonly ?RenderScope $scope = null,
        private readonly ?ShadowComparator $plainComparator = null,
        private readonly ?PluginListInterface $pluginList = null,
        private readonly ?LegacyOnlyRenders $legacyOnlyRenders = null,
        private readonly ?CandidateRenders $candidateRenders = null
    ) {
        $this->state = new \WeakMap();
        $this->legacyRunning = new \WeakMap();
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
     * Not a `before` method itself: setPlainTemplateMode() is the Email filter's, and this
     * plugin is declared on the framework class, where Magento's compiler refuses a plugin
     * method for a method the class does not have. EmailFilterStatePlugin, declared on the
     * Email filter, hands it over.
     */
    public function rememberPlainTemplateMode(LegacyTemplate $subject, bool $plain): void
    {
        $state = $this->stateOf($subject);
        $state['plain'] = $plain;
        $this->state[$subject] = $state;
    }

    /**
     * Captured for the same reason again: it is set on the subject, and by the time filter()
     * returns the emulation it was taken inside is gone. Handed over by EmailFilterStatePlugin,
     * like the plain flag.
     *
     * @param array<string,mixed> $designParams
     */
    public function rememberDesignParams(LegacyTemplate $subject, array $designParams): void
    {
        $state = $this->stateOf($subject);
        $state['design'] = $designParams;
        $this->state[$subject] = $state;
    }

    /**
     * Decides which engine renders this invocation, and serves its result.
     *
     * `$value` is untyped because the method it wraps is; anything but a string goes to the
     * filter, which raises for it as it always has.
     */
    public function aroundFilter(LegacyTemplate $subject, callable $proceed, $value)
    {
        // The console's candidate, served where this engine would serve it - see
        // CandidateRenders. Before anything else: the console asked for exactly this call.
        $candidate = is_string($value) ? $this->candidateRenders?->take($subject) : null;
        if ($candidate !== null) {
            return $this->servedThroughInnerChain($subject, $value, $candidate);
        }

        // A CHILD render belongs to legacy whatever the stage. The filter renders a child
        // only while rendering its parent - this engine loads {{template}} includes itself,
        // so a parent it serves never reaches one - and a child's output is not a document:
        // the filter defers {{inlinecss}} by emitting a placeholder for the legacy root to
        // resolve - a SIGNED copy of the directive up to Mage-OS 3.5.0, a token from the
        // request's DeferredDirectiveRegistry since 3.6.0 - a contract only the legacy root
        // can keep. Nor is one worth comparing: either placeholder is random per request, so
        // it could never match, and the parent's comparison covers the same content.
        if (!is_string($value) || (method_exists($subject, 'isChildTemplate') && $subject->isChildTemplate())) {
            return $proceed($value);
        }

        // A filter neither adapter models, in every stage - see the class docblock.
        if ($this->profileOf($subject) === null) {
            return $proceed($value);
        }

        // Part of a render legacy is already running on this instance - see $legacyRunning.
        // Or one the caller needs to be legacy's own - see LegacyOnlyRenders.
        if (($this->legacyRunning[$subject] ?? 0) > 0 || $this->legacyOnlyRenders?->active()) {
            return $proceed($value);
        }

        $storeId = $this->storeIdOf($subject);

        return match ($this->mode->forStore($storeId)) {
            EngineMode::SHADOW => $this->shadow($subject, $proceed, $value, $storeId),
            EngineMode::PARSER => $this->parser($subject, $proceed, $value, $storeId),
            default => $proceed($value),
        };
    }

    /**
     * Runs the filter, marking the subject as inside a legacy render while it does.
     *
     * Only around the calls this plugin makes to render a template through legacy. Under
     * Legacy the plugin makes no such call, and nothing a nested call could do differs there.
     */
    private function legacy(LegacyTemplate $subject, callable $proceed, string $value): mixed
    {
        $this->legacyRunning[$subject] = ($this->legacyRunning[$subject] ?? 0) + 1;
        try {
            return $proceed($value);
        } finally {
            $depth = $this->legacyRunning[$subject] - 1;
            if ($depth > 0) {
                $this->legacyRunning[$subject] = $depth;
            } else {
                unset($this->legacyRunning[$subject]);
            }
        }
    }

    /** Serves legacy, and records how this engine would have done. */
    private function shadow(LegacyTemplate $subject, callable $proceed, string $value, int|string|null $storeId): mixed
    {
        // Taken before the filter runs, as the scope this invocation was called with: by the
        // time it returns, an include may have told the subject something else.
        $state = $this->stateOf($subject);
        $result = $this->legacy($subject, $proceed, $value);

        try {
            $chain = $this->innerChain($subject);
            $unmodelled = $this->unmodelledDirective($subject, $value) ?? self::unmodelledChain($chain);
            if ($unmodelled !== null) {
                $this->recorder->record($storeId, $this->templateOf($subject, $value), $unmodelled);

                return $result;
            }

            // Legacy has already put this render's stylesheets on the subject, so the finisher
            // only has to apply them. Registering them again would be harmless here, but not in
            // general: one this engine accepted and legacy did not would then be inlined into
            // later LEGACY renders on the same instance, which Shadow promises not to change.
            $outcome = $this->inScopeOf($subject, $storeId, function () use ($subject, $chain, $value, $result, $state): ShadowOutcome {
                $comparator = $this->comparatorFor($subject);
                $candidate = $this->throughInnerChain($subject, $chain, $value, fn (string $input) => $comparator->render(
                    $input,
                    $state['variables'],
                    $state['plain'],
                    $this->finisher($subject, registerStylesheets: false),
                    $state['design']
                ));

                return $candidate instanceof ShadowOutcome ? $candidate : $comparator->judge((string)$candidate, (string)$result);
            });
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
                $legacy = $this->legacy($subject, $proceed, $value);
            } catch (\Throwable) {
                // A legacy fatal: today's customer got an error here. Served by this engine
                // instead, which is no reason to give them one now, and nothing to compare
                // against. Should this engine decline too, the fallback below runs the filter
                // again and the fatal is theirs, as it is under Legacy.
                $legacyRaised = true;
            }
        }

        try {
            $chain = $this->innerChain($subject);
            $candidate = $this->unmodelledDirective($subject, $value)
                ?? self::unmodelledChain($chain)
                ?? $this->inScopeOf($subject, $storeId, fn () => $this->throughInnerChain(
                    $subject,
                    $chain,
                    $value,
                    fn (string $input) => $this->comparatorFor($subject)->render(
                        $input,
                        $state['variables'],
                        $state['plain'],
                        $this->finisher($subject, registerStylesheets: true),
                        $state['design']
                    )
                ));
        } catch (\Throwable $e) {
            // render() contains its own failures; this is the backstop.
            $candidate = ShadowOutcome::crashed($e);
        }

        if ($candidate instanceof ShadowOutcome) {
            // Declined: the customer gets exactly what Legacy would have given them. The
            // sampled render already is that, so it is not rendered twice.
            $this->record($storeId, $template, $candidate, served: false, fellBack: true);

            return $sampled && !$legacyRaised ? $legacy : $this->legacy($subject, $proceed, $value);
        }

        $outcome = null;
        if ($sampled && !$legacyRaised) {
            try {
                $outcome = $this->comparatorFor($subject)->judge((string)$candidate, (string)$legacy);
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

        $this->scope->push($storeId, is_object($urlModel) ? $urlModel : null, $this->hostOnly($subject));
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
     * The plugins on filter() that run INSIDE this one, or null when there are none.
     *
     * Whatever this plugin returns, every plugin around it still applies - the generated
     * Interceptor runs the `after` plugins of its stretch of the chain once the around plugin
     * returns. But a plugin sorted after this one runs only inside `$proceed`: on legacy's
     * render, never on this engine's. Magento sorts by `sortOrder` with a comparison that
     * overflows for a plugin that has none against one that has, so which side of this one
     * Page Builder's `afterFilter` - no sortOrder, and the stock plugin that decodes its HTML
     * Code elements - lands on follows module load order, not anything here. Inside, Parser
     * served the elements undecoded and Shadow compared a render with them against one
     * without.
     *
     * So the plugins inside are run on this engine's render as they would have been on the
     * filter's: `before` plugins on the value, `after` plugins on the result. An `around`
     * plugin inside cannot be - only the filter's own body is what it wraps - so a filter with
     * one is the filter's to render; see unmodelledChain().
     *
     * Asked of the plugin list the subject dispatches through, as DirectiveMethods does.
     * This plugin is found by instance, not by name, so a project di.xml that renamed it is
     * still found.
     *
     * @return ?array{list:PluginListInterface,type:string,before:list<string>,after:list<string>,around:?string}
     */
    private function innerChain(LegacyTemplate $subject): ?array
    {
        $class = $subject::class;
        if (!array_key_exists($class, $this->innerChains)) {
            $this->innerChains[$class] = $this->findInnerChain($subject);
        }

        return $this->innerChains[$class];
    }

    /** @return ?array{list:PluginListInterface,type:string,before:list<string>,after:list<string>,around:?string} */
    private function findInnerChain(LegacyTemplate $subject): ?array
    {
        $original = DirectiveMethods::originalClass($subject::class);
        if ($original === $subject::class) {
            return null;
        }
        [$list, $type] = DirectiveMethods::pluginListOf($subject, $this->pluginList, $original);
        if ($list === null) {
            return null;
        }

        try {
            $code = '__self';
            $seen = [];
            while (!isset($seen[$code]) && is_array($info = $list->getNext($type, 'filter', $code))) {
                $seen[$code] = true;
                // Keyed as PluginListGenerator::inheritPlugins() builds it - see DirectiveMethods.
                $around = $info[DefinitionInterface::LISTENER_AROUND] ?? null;
                if (!is_string($around)) {
                    return null;
                }
                if ($list->getPlugin($type, $around) !== $this) {
                    $code = $around;
                    continue;
                }

                $inner = $list->getNext($type, 'filter', $around);
                if (!is_array($inner)) {
                    return null;
                }

                return [
                    'list' => $list,
                    'type' => $type,
                    'before' => array_values(array_map('strval', (array)($inner[DefinitionInterface::LISTENER_BEFORE] ?? []))),
                    'after' => array_values(array_map('strval', (array)($inner[DefinitionInterface::LISTENER_AFTER] ?? []))),
                    'around' => isset($inner[DefinitionInterface::LISTENER_AROUND])
                        ? (string)$inner[DefinitionInterface::LISTENER_AROUND]
                        : null,
                ];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * An around plugin on filter() inside this one: only the filter's own render runs it.
     *
     * @param ?array{around:?string} $chain
     */
    private static function unmodelledChain(?array $chain): ?ShadowOutcome
    {
        return ($chain['around'] ?? null) === null ? null : ShadowOutcome::policyRefused([sprintf(
            'filter() has the around plugin "%s" inside this engine\'s, which only the filter\'s own render runs',
            $chain['around']
        )]);
    }

    /**
     * Renders `$value` with `$render`, with the plugins inside this one run around it.
     *
     * The same calls the Interceptor makes: each `before` plugin may replace the value, and
     * each `after` plugin is handed the result and the value. A plugin that raises is a
     * crash, which in Parser falls back to the filter - where it raises again, as it would
     * under Legacy.
     *
     * @param ?array{list:PluginListInterface,type:string,before:list<string>,after:list<string>,around:?string} $chain
     * @param callable(string):mixed $render
     */
    private function throughInnerChain(LegacyTemplate $subject, ?array $chain, string $value, callable $render): mixed
    {
        if ($chain === null || ($chain['before'] === [] && $chain['after'] === [])) {
            return $render($value);
        }

        try {
            return $this->runInnerChain($subject, $chain, $value, $render);
        } catch (\Throwable $e) {
            return ShadowOutcome::crashed($e);
        }
    }

    /**
     * @param ?array{list:PluginListInterface,type:string,before:list<string>,after:list<string>,around:?string} $chain
     * @param callable(string):mixed $render
     */
    private function runInnerChain(LegacyTemplate $subject, ?array $chain, string $value, callable $render): mixed
    {
        foreach ($chain['before'] ?? [] as $code) {
            $arguments = $chain['list']->getPlugin($chain['type'], $code)->beforeFilter($subject, $value);
            if ($arguments !== null) {
                $value = array_values((array)$arguments)[0] ?? $value;
                if (!is_string($value)) {
                    throw new \UnexpectedValueException(sprintf('the plugin "%s" made the template a %s', $code, get_debug_type($value)));
                }
            }
        }

        $result = $render($value);
        if ($result instanceof ShadowOutcome) {
            return $result;
        }

        foreach ($chain['after'] ?? [] as $code) {
            $result = $chain['list']->getPlugin($chain['type'], $code)->afterFilter($subject, $result, $value);
        }

        return $result;
    }

    /**
     * The console's candidate, run as a Parser render would be - see CandidateRenders.
     *
     * Raises what Parser would decline on, so the console reports it as refused here.
     *
     * @param callable(string):mixed $render
     */
    private function servedThroughInnerChain(LegacyTemplate $subject, string $value, callable $render): mixed
    {
        $chain = $this->innerChain($subject);
        $refusal = self::unmodelledChain($chain);
        if ($refusal !== null) {
            throw new \RuntimeException((string)$refusal->detail['problem']);
        }

        // Unwrapped: the console's own render raises its own errors, and a plugin's are the
        // plugin's.
        return $this->runInnerChain($subject, $chain, $value, $render);
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
        $name = HostOnlyDirectives::firstIn([...$this->hostOnly($subject), ...self::processorless($subject)], $value);
        if ($name === null) {
            return null;
        }

        return ShadowOutcome::policyRefused([sprintf(
            'directive "%s" is rendered by %s itself, which this engine does not model',
            $name,
            self::originalClass($subject::class)
        )]);
    }

    /**
     * `{{template}}` on a filter no template model is driving.
     *
     * Only `AbstractTemplate::getProcessedTemplate()` gives a filter a template processor, so
     * the CMS, Widget and catalog filters never have one, and legacy's TemplateDirective prints
     * `{Error in template processing}` there. Per instance, not per class: the Email filter has
     * one while an email renders through it, and not otherwise.
     *
     * @return list<string>
     */
    private static function processorless(LegacyTemplate $subject): array
    {
        return method_exists($subject, 'getTemplateProcessor') && $subject->getTemplateProcessor() === null
            ? ['template']
            : [];
    }

    /** @return list<string> */
    private function hostOnly(LegacyTemplate $subject): array
    {
        return $this->legacyOnly[$subject::class] ??= $this->legacyOnlyDirectives($subject);
    }

    /**
     * A directive method a module added or overrode, or a stock one a module has plugged -
     * see DirectiveMethods - and the directives this engine leaves to particular filters.
     *
     * @return list<string>
     */
    private function legacyOnlyDirectives(LegacyTemplate $subject): array
    {
        $names = array_keys(DirectiveMethods::of($subject::class, $this->pluginList, $subject));

        if (is_a($subject, 'Magento\\Cms\\Model\\Template\\Filter')
            && !is_a($subject, 'Magento\\Widget\\Model\\Template\\Filter')
        ) {
            $names[] = 'media';
        }

        // Only the Widget filter has a widgetDirective(); on any other - the transactional
        // Email filter, the CMS one, the plain one - legacy prints `{{widget ...}}` as text.
        // And the newsletter filter renders each widget inside a frontend area emulation; this
        // engine renders it in whatever area is current, which in a newsletter send is not
        // frontend. Emulating around the whole render would move every OTHER directive too.
        if (!is_a($subject, 'Magento\\Widget\\Model\\Template\\Filter')
            || is_a($subject, 'Magento\\Widget\\Model\\Template\\FilterEmulate')
        ) {
            $names[] = 'widget';
        }

        return array_values(array_unique($names));
    }

    /** The class a generated Interceptor stands in for, for a message a person reads. */
    private static function originalClass(string $class): string
    {
        return DirectiveMethods::originalClass($class);
    }

    private function templateOf(LegacyTemplate $subject, string $value): string
    {
        return $this->identity->identify($value)
            // The name an address renderer holds for the call - see TemplateIdentity::during().
            // Only for the plain filter: that is what those calls render through, and an email
            // rendered inside one would otherwise borrow its name.
            ?? ($this->profileOf($subject) === self::PLAIN ? $this->identity->ambient() : null)
            ?? TemplateIdentity::unidentified($subject);
    }

    /**
     * Which adapter renders this filter's templates, or null for one neither models.
     *
     * By class, so a generated Interceptor counts as the class it stands in for. The plain
     * filter only when its comparator is wired; without one it is left alone, as before.
     */
    private function profileOf(LegacyTemplate $subject): ?string
    {
        $class = $subject::class;
        if (!array_key_exists($class, $this->profiles)) {
            $this->profiles[$class] = match (true) {
                is_a($subject, self::EMAIL_FILTER) => self::EMAIL,
                self::originalClass($class) === LegacyTemplate::class && $this->plainComparator !== null => self::PLAIN,
                default => null,
            };
        }

        return $this->profiles[$class];
    }

    private function comparatorFor(LegacyTemplate $subject): ShadowComparator
    {
        return $this->profileOf($subject) === self::PLAIN && $this->plainComparator !== null
            ? $this->plainComparator
            : $this->comparator;
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
