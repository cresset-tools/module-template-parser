<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Cresset\TemplateParser\Diagnostics;
use Cresset\TemplateParser\Magento\Shadow\ShadowOutcome;
use Cresset\TemplateParser\TemplateError;

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
    public function __construct(private readonly TemplateFilterInterface $adapter)
    {
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
     * Whether a candidate from render() agrees with legacy, and why not when it does not.
     *
     * Reads the causes off the adapter, so call it straight after the render() that
     * produced `$candidate`.
     */
    public function judge(string $candidate, string $legacyResult): ShadowOutcome
    {
        if ($candidate === $legacyResult) {
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

        return $candidate instanceof ShadowOutcome ? $candidate : $this->judge($candidate, $legacyResult);
    }

    /**
     * The stylesheets the render just finished asked to have inlined, its own or an
     * include's, in the order it asked. Empty when it asked for none.
     *
     * @return list<string>
     */
    private function stylesheetsToInline(): array
    {
        $files = [];
        foreach ($this->adapter->deferred() as $entry) {
            if (($entry['kind'] ?? null) === 'inlinecss') {
                $files[] = (string)($entry['payload']['file'] ?? '');
            }
        }

        return $files;
    }
}
