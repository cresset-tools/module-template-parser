<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Cresset\TemplateParser\Diagnostics;

use Psr\Log\LoggerInterface;

/**
 * Runs the new engine alongside the legacy filter and records where they differ.
 *
 * The templates that decide whether a migration is safe live in merchant databases and
 * cannot be audited in advance. Shadow mode turns that unknowable compatibility question
 * into measured data: legacy output is always what gets returned, so enabling this changes
 * nothing a customer sees.
 */
class ShadowComparator
{
    public function __construct(
        private readonly TemplateFilterInterface $adapter,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = false
    ) {
    }

    /**
     * @param array<string,mixed> $variables
     * @param bool $plainTemplateMode the subject is rendering the PLAIN part of an email
     * @param array<string,mixed> $designParams the design the legacy filter resolved {{css}} against
     * @param ?callable(string):string $finish whatever the host does to a FINISHED render
     * @return string the LEGACY result, always
     *
     * `$finish` matters more than it looks. `Email\Model\Template\Filter::filter()` runs
     * Emogrifier over the whole document before returning, so the legacy result handed to this
     * method has its stylesheets inlined; this engine defers that step to its host and returns
     * the document without it. Comparing the two directly reported a divergence for every
     * template carrying a stylesheet - 118 of them on a stock store - none of which was a
     * disagreement between the engines.
     */
    public function compare(
        string $source,
        string $legacyResult,
        array $variables = [],
        bool $plainTemplateMode = false,
        ?callable $finish = null,
        array $designParams = []
    ): string {
        if (!$this->enabled) {
            return $legacyResult;
        }

        try {
            $candidate = $this->adapter
                ->setPlainTemplateMode($plainTemplateMode)
                ->setDesignParams($designParams)
                ->setVariables($variables)
                ->filter($source);

            if ($finish !== null) {
                $candidate = $finish($candidate);
            }
        } catch (\Throwable $e) {
            $this->logger->info('template-parser shadow: engine raised', [
                'error' => $e->getMessage(),
                'template_hash' => hash('sha256', $source),
            ]);
            return $legacyResult;
        }

        if ($candidate !== $legacyResult) {
            // The causes, not just the fact. A byte offset alone tells an integrator
            // nothing actionable; a refused directive or a construct the legacy filter
            // could not have rendered names what to look at.
            $this->logger->info('template-parser shadow: divergence', [
                'template_hash' => hash('sha256', $source),
                'legacy_length' => strlen($legacyResult),
                'candidate_length' => strlen($candidate),
                'first_difference_at' => Diagnostics::firstDifferingByte($legacyResult, $candidate),
                'policy_violations' => array_map(
                    static fn ($violation): string => $violation->describe(),
                    $this->adapter->violations()
                ),
                'legacy_incompatibilities' => array_map(
                    static fn ($incompatibility): string => $incompatibility->describe(),
                    $this->adapter->incompatibilities()
                ),
            ]);
        }

        return $legacyResult;
    }
}
