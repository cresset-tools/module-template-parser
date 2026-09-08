<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

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
     * @return string the LEGACY result, always
     */
    public function compare(string $source, string $legacyResult, array $variables = []): string
    {
        if (!$this->enabled) {
            return $legacyResult;
        }

        try {
            $candidate = $this->adapter->setVariables($variables)->filter($source);
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
                'first_difference_at' => $this->firstDifference($legacyResult, $candidate),
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

    private function firstDifference(string $a, string $b): int
    {
        $limit = min(strlen($a), strlen($b));
        for ($i = 0; $i < $limit; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $i;
            }
        }
        return $limit;
    }
}
