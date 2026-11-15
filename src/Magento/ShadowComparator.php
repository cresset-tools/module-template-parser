<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

use MageOS\TemplateParser\DeliberateDifference;
use MageOS\TemplateParser\Diagnostics;
use MageOS\TemplateParser\Magento\Shadow\ShadowOutcome;
use MageOS\TemplateParser\TemplateError;

/**
 * Renders a template through the new engine the way the filter would have, and says whether
 * that agrees with legacy.
 *
 * The templates that decide whether a migration is safe live in merchant databases and
 * cannot be audited in advance. Shadow mode turns that unknowable compatibility question into
 * measured data, and Parser mode keeps measuring a sample of renders after the switch. Both
 * use this class: render() produces the candidate - finished as the host would finish it - or
 * says why there is none, and judge() compares it with legacy's. What gets served is the
 * plugin's business, and where the outcome is kept is ShadowRecorder's.
 *
 * Whether it runs at all is not decided here either. TemplateFilterPlugin calls it only for a
 * store set to Shadow or Parser in `system/template_engine/mode`, so the store view is the
 * unit of rollout rather than the installation.
 */
class ShadowComparator
{
    /**
     * @param ?bool $hostEncodesValues whether the host's filter encodes `{{` in resolved
     *        directive output; null asks the host - see HostOutputNeutralizer
     */
    public function __construct(
        private readonly TemplateFilterInterface $adapter,
        private readonly ?bool $hostEncodesValues = null
    ) {
    }

    /**
     * The candidate render, or the outcome that stands in for one.
     *
     * A string is output fit to serve. A ShadowOutcome is the reason there is none - refused,
     * refused in part by policy, the host raised, or crashed - and in Parser mode means "fall
     * back to legacy". The host
     * raising counts even though the adapter caught it: the adapter's error text is its
     * imitation of the filter's, and the filter's own - which differs between developer and
     * production mode, and logs - is what a customer gets today.
     *
     * @param array<string,mixed> $variables
     * @param bool $plainTemplateMode the subject is rendering the PLAIN part of an email
     * @param ?callable(string,list<string>):string $finish what the host does to a FINISHED
     *        render, given the stylesheets this render asked to have inlined
     * @param array<string,mixed> $designParams the design {{css}} resolves against
     *
     * `$finish` matters more than it looks. A legacy `{{inlinecss}}` registers an after-filter
     * callback that runs Emogrifier over the whole document before filter() returns, so the
     * legacy result has its stylesheets inlined; this engine defers that step to its host and
     * returns the document without it. Comparing the two directly reported a divergence for
     * every template carrying a stylesheet - 118 of them on a stock store - none of which was
     * a disagreement between the engines.
     *
     * And only when THIS render asked for it. The callback is per render - the filter resets
     * it after each one - but the list of files it inlines is not, so an email's subject,
     * filtered by the same instance straight after its body, still sees the body's
     * stylesheets. Finishing unconditionally wrapped every subject in an HTML document and
     * reported each as a divergence, found on a real store: legacy 12 bytes, candidate 729.
     */
    public function render(
        string $source,
        array $variables = [],
        bool $plainTemplateMode = false,
        ?callable $finish = null,
        array $designParams = []
    ): string|ShadowOutcome {
        try {
            $candidate = $this->adapter
                ->setPlainTemplateMode($plainTemplateMode)
                ->setDesignParams($designParams)
                ->setVariables($variables)
                ->filter($source);

            $hostError = $this->adapter->lastError();
            if ($hostError !== null) {
                return ShadowOutcome::hostRaised($hostError);
            }

            // A render that skipped something by policy is incomplete, not merely different:
            // the filter has no policy and renders what this one left out. Served, a stock
            // order email went out without its item table, because the layout handle that
            // builds it was not allowed. So it is declined like a refusal, and in Parser mode
            // the filter renders it - exactly what the customer gets today.
            $violations = $this->adapter->violations();
            if ($violations !== []) {
                return ShadowOutcome::policyRefused(
                    array_map(static fn ($violation): string => $violation->describe(), $violations)
                );
            }

            $stylesheets = $this->stylesheetsToInline();
            if ($finish !== null && $stylesheets !== []) {
                $candidate = $finish($candidate, $stylesheets);
            }

            return $candidate;
        } catch (TemplateError $e) {
            // Declined on purpose: Parser mode falls back to legacy for this template.
            return ShadowOutcome::refused($e);
        } catch (\Throwable $e) {
            return ShadowOutcome::crashed($e);
        }
    }

    /**
     * Whether legacy's render is not a finished one: it still holds a `:deferred:` placeholder
     * - `DeferredDirectiveRegistry`'s, from Mage-OS 3.6.0 and the security patch it ports -
     * that the candidate does not.
     *
     * Only the root render resolves them, and the filter takes a render to be the root by a
     * depth meter shared by the whole process, which `Template::filter()` steps back up only
     * when it returns. One render that raised, in a cron or a queue consumer sending many
     * emails, leaves every later render in that process a level deep and its includes'
     * deferrals unresolved. That is legacy's output to the customer, as it is under Legacy -
     * but compared, it reads as this engine diverging, so it is not compared.
     */
    public static function legacyLeftDeferrals(string $candidate, string $legacyResult): bool
    {
        // 3.6.0's is `:deferred:<sha256 hex>:`; the patch's port to older lines need not be.
        $placeholder = '/:deferred:[0-9A-Za-z]{32,}/';

        return preg_match($placeholder, $legacyResult) === 1 && preg_match($placeholder, $candidate) !== 1;
    }

    /**
     * Whether a candidate from render() agrees with legacy, and why not when it does not.
     *
     * Reads the causes off the adapter, so call it straight after the render() that
     * produced `$candidate`.
     */
    public function judge(string $candidate, string $legacyResult): ShadowOutcome
    {
        // Nor is a link id Magento draws at random per render a disagreement - see RandomMarkup.
        if (RandomMarkup::sameRender($candidate, $legacyResult)) {
            return ShadowOutcome::agreed();
        }

        // The causes, not just the fact. A byte offset alone tells an integrator nothing
        // actionable; a refused directive or a construct the legacy filter could not have
        // rendered names what to look at.
        return ShadowOutcome::diverged(
            strlen($legacyResult),
            strlen($candidate),
            Diagnostics::firstDifferingByte($legacyResult, $candidate),
            array_map(static fn ($violation): string => $violation->describe(), $this->adapter->violations()),
            array_map(static fn ($incompatibility): string => $incompatibility->describe(), $this->adapter->incompatibilities())
        );
    }

    /**
     * Whether a divergence from judge() could be the deliberate difference, and is worth a
     * marked render to find out - see DeliberateDifference.
     *
     * Only on a host whose filter predates the output neutralizer, and only when the candidate
     * holds an encoded brace, the one trace the neutralizer leaves. Everything else is decided
     * without a second render.
     */
    public function mayBeDeliberate(ShadowOutcome $outcome, string $candidate): bool
    {
        return $outcome->outcome === ShadowOutcome::DIVERGE
            && str_contains($candidate, '&#123;')
            && $this->adapter instanceof MarksNeutralizedOutput
            && !($this->hostEncodesValues ?? HostOutputNeutralizer::encodesValues());
    }

    /**
     * The render render() would make, with the neutralizer marking instead of encoding, or a
     * crash standing in for one. Never recorded: a marked render that does not complete only
     * leaves the divergence a divergence.
     *
     * The same arguments as the render() it checks, finished the same way.
     *
     * @param array<string,mixed> $variables
     * @param ?callable(string,list<string>):string $finish
     * @param array<string,mixed> $designParams
     */
    public function renderMarked(
        string $source,
        array $variables = [],
        bool $plainTemplateMode = false,
        ?callable $finish = null,
        array $designParams = []
    ): string|ShadowOutcome {
        try {
            if (!$this->adapter instanceof MarksNeutralizedOutput) {
                return ShadowOutcome::crashed(new \LogicException('the adapter cannot render marked'));
            }
            $this->adapter
                ->setPlainTemplateMode($plainTemplateMode)
                ->setDesignParams($designParams)
                ->setVariables($variables);
            $marked = $this->adapter->filterMarked($source);
            if ($marked === null) {
                return ShadowOutcome::crashed(new \RuntimeException('the marked render did not complete'));
            }

            [$rendered, $deferred] = $marked;
            $stylesheets = self::stylesheetsIn($deferred);
            if ($finish !== null && $stylesheets !== []) {
                $rendered = $finish($rendered, $stylesheets);
            }

            return $rendered;
        } catch (\Throwable $e) {
            return ShadowOutcome::crashed($e);
        }
    }

    /**
     * The divergence `$outcome`, or the deliberate difference when the marked render shows it
     * is the whole of it - see DeliberateDifference.
     */
    public function settle(ShadowOutcome $outcome, string $candidate, string|ShadowOutcome $marked, string $legacyResult): ShadowOutcome
    {
        if ($outcome->outcome !== ShadowOutcome::DIVERGE
            || !is_string($marked)
            || !DeliberateDifference::explains(
                RandomMarkup::normalized($candidate),
                RandomMarkup::normalized($marked),
                RandomMarkup::normalized($legacyResult)
            )
        ) {
            return $outcome;
        }

        return ShadowOutcome::deliberate(
            strlen($legacyResult),
            strlen($candidate),
            Diagnostics::firstDifferingByte($legacyResult, $candidate)
        );
    }

    /**
     * render() and judge() in one: what Shadow mode does with every render.
     *
     * @param array<string,mixed> $variables
     * @param ?callable(string,list<string>):string $finish
     * @param array<string,mixed> $designParams
     */
    public function compare(
        string $source,
        string $legacyResult,
        array $variables = [],
        bool $plainTemplateMode = false,
        ?callable $finish = null,
        array $designParams = []
    ): ShadowOutcome {
        $candidate = $this->render($source, $variables, $plainTemplateMode, $finish, $designParams);
        if ($candidate instanceof ShadowOutcome) {
            return $candidate;
        }

        $outcome = $this->judge($candidate, $legacyResult);

        return $this->mayBeDeliberate($outcome, $candidate)
            ? $this->settle($outcome, $candidate, $this->renderMarked($source, $variables, $plainTemplateMode, $finish, $designParams), $legacyResult)
            : $outcome;
    }

    /**
     * The stylesheets the render just finished asked to have inlined, its own or an
     * include's, in the order it asked. Empty when it asked for none.
     *
     * @return list<string>
     */
    private function stylesheetsToInline(): array
    {
        return self::stylesheetsIn($this->adapter->deferred());
    }

    /**
     * @param array<int,array{kind:string,payload:array}> $deferred
     * @return list<string>
     */
    private static function stylesheetsIn(array $deferred): array
    {
        $files = [];
        foreach ($deferred as $entry) {
            if (($entry['kind'] ?? null) === 'inlinecss') {
                $files[] = (string)($entry['payload']['file'] ?? '');
            }
        }

        return $files;
    }
}
