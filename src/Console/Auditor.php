<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

use Cresset\TemplateParser\Console\Source\TemplateSource;
use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\RenderPolicy;
use Cresset\TemplateParser\TemplateEngine;
use Cresset\TemplateParser\TemplateError;

/**
 * Renders subjects through the engine and reports what happened.
 *
 * This is the whole library, as far as the commands are concerned: they collect subjects,
 * hand them here, and print what comes back. Keeping it free of Symfony types is what lets
 * the same logic run inside n98-magerun2, or in anything else that can require this package.
 */
class Auditor
{
    private readonly Fixer $fixer;

    public function __construct(
        private readonly EngineFactory $engines,
        private readonly StoreEmulator $stores,
        private readonly ?HostExtensions $extensions = null,
    ) {
        $this->fixer = new Fixer();
    }

    /**
     * Checks each subject renders, and reports what stops it.
     *
     * @param iterable<TemplateSubject> $subjects
     * @return Finding[]
     */
    public function check(iterable $subjects, Mode $mode, ?int $storeId = null, ?RenderPolicy $policy = null): array
    {
        $findings = [];

        foreach ($subjects as $subject) {
            $store = $subject->storeId ?? $storeId;
            $findings = array_merge($findings, $this->stores->around($store, function () use ($subject, $mode, $store, $policy): array {
                $engine = $this->engines->create($mode, $store);
                $context = new Context($subject->variables, $policy ?? RenderPolicy::unrestricted());

                // Worked out before the render, because a template using one of these may well
                // REFUSE - a paired custom directive leaves a `{{/mydir}}` this engine has no
                // opener for - and that is the case where the reason is least obvious.
                $extension = $this->extensions?->noteFor($subject->content, $engine->evaluator()->registered());
                $extensionFinding = $extension === null ? [] : [new Finding(
                    severity: Finding::WARNING,
                    subject: $subject,
                    summary: $extension,
                    fix: 'No CustomDirectiveRenderer is wired in this run, so the store '
                        . 'renders this directive and the engine has no handler for it. '
                        . 'etc/di.xml supplies PoolCustomDirectiveRenderer, and a port that '
                        . 'cannot be constructed is skipped rather than fatal - start there. '
                        . 'Until one is wired, {{mydir "v"}} comes back as its own text and '
                        . '{{mydir}}body{{/mydir}} is refused, because the closing tag has no '
                        . 'opener this engine knows.',
                )];

                try {
                    $engine->render($subject->content, context: $context);
                } catch (TemplateError $e) {
                    [$severity, $fix] = $this->fixer->advise($e);

                    return array_merge($extensionFinding, [new Finding(
                        severity: $severity,
                        subject: $subject,
                        summary: $this->firstLine($e->getMessage()),
                        fix: $fix,
                        line: $e->sourceLine ?? null,
                    )]);
                } catch (\Throwable $e) {
                    return array_merge($extensionFinding, [new Finding(
                        severity: Finding::ERROR,
                        subject: $subject,
                        summary: sprintf('%s: %s', (new \ReflectionClass($e))->getShortName(), $e->getMessage()),
                        fix: 'This is not a template error - the engine or a wired port raised it. '
                            . 'Worth reporting if the template itself looks reasonable.',
                    )]);
                }

                // A directive the STORE has and this engine does not comes back as its own
                // text - exactly what a store without that extension would do - so the
                // difference is invisible unless someone says it. Not an error: the template
                // renders, and on a store without the extension it renders identically.
                $findings = $extensionFinding;

                foreach ($context->violations() as $violation) {
                    $findings[] = new Finding(
                        severity: Finding::NOTE,
                        subject: $subject,
                        summary: $violation->describe(),
                        fix: 'The render policy refused this directive. Grant it with '
                            . 'RenderPolicy::alsoAllowing() if the template is trusted.',
                    );
                }
                foreach ($context->incompatibilities() as $incompatibility) {
                    $findings[] = new Finding(
                        severity: Finding::WARNING,
                        subject: $subject,
                        summary: $incompatibility->describe(),
                        fix: 'This renders here but not on the legacy filter, so it cannot be '
                            . 'rolled back. Worth fixing before you switch.',
                    );
                }

                return $findings;
            }));
        }

        return $findings;
    }

    /**
     * Renders each subject through both engines and reports where they differ.
     *
     * @param iterable<TemplateSubject> $subjects
     * @param callable(TemplateSubject):?LegacyRender $legacy renders the legacy result, or null if it cannot
     * @return Divergence[]
     */
    public function diff(iterable $subjects, Mode $mode, callable $legacy, ?int $storeId = null): array
    {
        $divergences = [];

        foreach ($subjects as $subject) {
            $store = $subject->storeId ?? $storeId;
            $divergence = $this->stores->around($store, function () use ($subject, $mode, $store, $legacy): ?Divergence {
                $engine = $this->engines->create($mode, $store);

                try {
                    $legacyRender = $legacy($subject);
                } catch (\Throwable $e) {
                    $legacyRender = null;
                }

                $legacyOutput = $legacyRender?->output;
                // The variables Magento built for the legacy render, not the ones this tool
                // was handed: an email template model adds a dozen store variables of its
                // own, and rendering our side without them compares two different inputs.
                $variables = $legacyRender?->variables ?: $subject->variables;

                $ours = null;
                $ourFailure = null;
                try {
                    // Unrestricted DELIBERATELY, and only here. diff exists to measure the two
                    // engines against each other, and the legacy filter has no policy at all -
                    // so anything this one refused on policy would be reported as a divergence
                    // in the engine rather than as the posture it is. The policy that matters
                    // is the one the host sets at render time; this is a measurement.
                    $ours = $engine->render($subject->content, context: new Context(
                        $variables,
                        RenderPolicy::unrestricted(),
                        false,
                        // The design the filter resolved its stylesheets against. Looking it
                        // up here would give a different theme: the model cancels its own
                        // emulation before filter() returns.
                        $legacyRender?->designParams ?? []
                    ));
                    // Whatever the host does to a finished render, it does to both.
                    if ($legacyRender?->finish !== null) {
                        $ours = ($legacyRender->finish)($ours);
                    }
                } catch (\Throwable $e) {
                    $ourFailure = (new \ReflectionClass($e))->getShortName() . ': ' . $this->firstLine($e->getMessage());
                }

                // Both refusing is agreement, not divergence. Compatible mode declines what
                // the old filter crashed on, so a template neither can render is one nobody
                // has ever received - reporting it as a difference buries the ones that are.
                if ($legacyOutput === null && $ourFailure !== null) {
                    return null;
                }

                if ($ourFailure !== null) {
                    // The extension note belongs MOST here. A paired custom directive makes
                    // this engine refuse the stray `{{/mydir}}`, and "closes nothing here" is
                    // a puzzling thing to read about a directive your own store implements.
                    return new Divergence($subject, $legacyOutput, null, implode('; ', array_filter([
                        'renders today, refused here - ' . $ourFailure,
                        $this->extensions?->noteFor($subject->content, $engine->evaluator()->registered()),
                    ])));
                }

                if ($legacyOutput === null) {
                    return new Divergence($subject, null, $ours,
                        'the legacy filter cannot render this, but this engine does');
                }

                if ($legacyOutput === $ours) {
                    return null;
                }

                return new Divergence($subject, $legacyOutput, $ours, $this->divergenceNote($subject->content, $legacyOutput, $ours, $engine));
            });

            if ($divergence !== null) {
                $divergences[] = $divergence;
            }
        }

        return $divergences;
    }

    /** Both notes a difference can carry: a port this tool lacks, and one the filter lacks. */
    private function divergenceNote(string $source, string $legacyOutput, string $ours, TemplateEngine $engine): ?string
    {
        $notes = array_filter([
            $this->unwiredPortNote($ours, $engine),
            $this->surfaceGapNote($legacyOutput, $ours, $engine),
            $this->extensions?->noteFor($source, $engine->evaluator()->registered()),
        ]);

        return $notes === [] ? null : implode('; ', $notes);
    }

    /**
     * Names directives the FILTER has no processor for on this surface, when we render them.
     *
     * The mirror image of the note below, and the one that reads worst without it. Both
     * `Email\Model\Template\Filter` and the newsletter filter extend
     * `Framework\Filter\Template`, which has no widgetDirective at all - so an email template
     * containing `{{widget type="..."}}` renders that text verbatim today, while this engine,
     * with a widget port wired, builds the block and renders its HTML.
     *
     * That is a capability the template did not previously have - block instantiation from
     * template text, on a surface where the filter offered none - and calling it a divergence
     * without saying so puts it on the engine. The CMS surface is the other way round: that
     * pipeline DOES implement widget, and there the two agree.
     *
     * Only directives verbatim in the LEGACY output and absent from ours count, which is the
     * signature of "they had no processor and we did". One that is verbatim on both sides is
     * a misspelling and cancels out.
     */
    private function surfaceGapNote(string $legacyOutput, string $ours, TemplateEngine $engine): ?string
    {
        $found = [];
        foreach ($engine->evaluator()->registered() as $name) {
            if (HostExtensions::mentions($legacyOutput, $name) && !HostExtensions::mentions($ours, $name)) {
                $found[] = $name;
            }
        }

        if ($found === []) {
            return null;
        }

        sort($found);

        return sprintf(
            'the filter this template renders through has no {{%s}} processor, so it emits the '
            . 'directive as written; this engine has a port wired and renders it - a capability '
            . 'gained, not the engines disagreeing',
            implode('}}, {{', $found)
        );
    }

    /**
     * Names the ports a divergence is actually caused by, when it is caused by a port.
     *
     * A directive this engine knows but has no port for stays unregistered, and compatible
     * mode emits an unregistered directive verbatim - so it lands in the output as its own
     * source text and the report shows a byte difference that reads like an engine bug.
     * {{layout}} does this on every run by default, because it needs a handle allowlist
     * before it will render anything, and that turns eight stock sales emails plus their
     * theme overrides into sixteen unexplained diffs.
     *
     * Only directives left verbatim in OUR output count. One the author simply misspelled is
     * verbatim on both sides and cancels out of the comparison.
     */
    private function unwiredPortNote(string $ours, TemplateEngine $engine): ?string
    {
        $unwired = array_diff($engine->evaluator()->spec()->knownNames(), $engine->evaluator()->registered());

        $found = [];
        foreach ($unwired as $name) {
            if (HostExtensions::mentions($ours, $name)) {
                $found[] = $name;
            }
        }

        if ($found === []) {
            return null;
        }

        sort($found);

        return sprintf(
            'no port wired for {{%s}}, so it is left as written here and rendered today - '
            . 'this is the tool being unconfigured, not the engines disagreeing',
            implode('}}, {{', $found)
        );
    }

    private function firstLine(string $message): string
    {
        return trim(strtok($message, "\n") ?: $message);
    }
}
