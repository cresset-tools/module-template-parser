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

                try {
                    $engine->render($subject->content, context: $context);
                } catch (TemplateError $e) {
                    [$severity, $fix] = $this->fixer->advise($e);

                    return [new Finding(
                        severity: $severity,
                        subject: $subject,
                        summary: $this->firstLine($e->getMessage()),
                        detail: $e->getMessage(),
                        fix: $fix,
                        line: $e->sourceLine ?? null,
                    )];
                } catch (\Throwable $e) {
                    return [new Finding(
                        severity: Finding::ERROR,
                        subject: $subject,
                        summary: sprintf('%s: %s', (new \ReflectionClass($e))->getShortName(), $e->getMessage()),
                        detail: $e->getMessage(),
                        fix: 'This is not a template error - the engine or a wired port raised it. '
                            . 'Worth reporting if the template itself looks reasonable.',
                    )];
                }

                $findings = [];
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
                    $ours = $engine->render($subject->content, context: new Context($variables, RenderPolicy::unrestricted()));
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
                    return new Divergence($subject, $legacyOutput, null,
                        'renders today, refused here - ' . $ourFailure);
                }

                if ($legacyOutput === null) {
                    return new Divergence($subject, null, $ours,
                        'the legacy filter cannot render this, but this engine does');
                }

                if ($legacyOutput === $ours) {
                    return null;
                }

                return new Divergence($subject, $legacyOutput, $ours, $this->unwiredPortNote($ours, $engine));
            });

            if ($divergence !== null) {
                $divergences[] = $divergence;
            }
        }

        return $divergences;
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
            if (preg_match('/\{\{' . preg_quote($name, '/') . '(?![a-zA-Z0-9_])/i', $ours) === 1) {
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
