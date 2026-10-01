<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Cresset\TemplateParser\Diagnostics;
use Cresset\TemplateParser\Magento\Shadow\ShadowOutcome;
use Cresset\TemplateParser\TemplateError;

/**
 * Runs the new engine alongside the legacy filter and says whether they agree.
 *
 * The templates that decide whether a migration is safe live in merchant databases and
 * cannot be audited in advance. Shadow mode turns that unknowable compatibility question
 * into measured data. This class only measures: it renders the candidate, classifies the
 * result as a ShadowOutcome and hands it back. What gets served is the plugin's business -
 * always the legacy result - and where the outcome is kept is ShadowRecorder's.
 *
 * Whether it runs at all is not decided here either. TemplateFilterPlugin calls it only for a
 * render whose store is set to Shadow in `system/template_engine/mode`, so the store view is
 * the unit of rollout rather than the installation.
 */
class ShadowComparator
{
    public function __construct(private readonly TemplateFilterInterface $adapter)
    {
    }

    /**
     * @param array<string,mixed> $variables
     * @param bool $plainTemplateMode the subject is rendering the PLAIN part of an email
     * @param array<string,mixed> $designParams the design the legacy filter resolved {{css}} against
     * @param ?callable(string):string $finish whatever the host does to a FINISHED render
     *
     * `$finish` matters more than it looks. A legacy `{{inlinecss}}` registers an after-filter
     * callback that runs Emogrifier over the whole document before filter() returns, so the
     * legacy result handed to this method has its stylesheets inlined; this engine defers that
     * step to its host and returns the document without it. Comparing the two directly
     * reported a divergence for every template carrying a stylesheet - 118 of them on a stock
     * store - none of which was a disagreement between the engines.
     *
     * And only when THIS render asked for it. The callback is per render - the filter resets
     * it after each one - but the list of files it inlines is not, so an email's subject,
     * filtered by the same instance straight after its body, still sees the body's
     * stylesheets. Finishing unconditionally wrapped every subject in an HTML document and
     * reported each as a divergence, found on a real store: legacy 12 bytes, candidate 729.
     */
    public function compare(
        string $source,
        string $legacyResult,
        array $variables = [],
        bool $plainTemplateMode = false,
        ?callable $finish = null,
        array $designParams = []
    ): ShadowOutcome {
        try {
            $candidate = $this->adapter
                ->setPlainTemplateMode($plainTemplateMode)
                ->setDesignParams($designParams)
                ->setVariables($variables)
                ->filter($source);

            if ($finish !== null && $this->requestedInlineCss()) {
                $candidate = $finish($candidate);
            }
        } catch (TemplateError $e) {
            // Declined on purpose: in Parser mode this template falls back to legacy (#2).
            return ShadowOutcome::refused($e);
        } catch (\Throwable $e) {
            return ShadowOutcome::crashed($e);
        }

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

    /** Whether the render just finished deferred an {{inlinecss}}, its own or an include's. */
    private function requestedInlineCss(): bool
    {
        foreach ($this->adapter->deferred() as $entry) {
            if (($entry['kind'] ?? null) === 'inlinecss') {
                return true;
            }
        }

        return false;
    }
}
