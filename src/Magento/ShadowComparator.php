<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

use Psr\Log\LoggerInterface;

/**
 * Runs the new engine alongside the legacy filter and records where they differ.
 *
 * The templates that decide whether a migration is safe live in merchant databases and
 * cannot be audited in advance. Shadow mode turns that unknowable compatibility question
 * into measured data: legacy output is always what gets returned, so enabling this changes
 * nothing a customer sees.
 */
final class ShadowComparator
{
    public function __construct(
        private readonly TemplateFilterAdapter $adapter,
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
            $this->logger->info('template-parser shadow: divergence', [
                'template_hash' => hash('sha256', $source),
                'legacy_length' => strlen($legacyResult),
                'candidate_length' => strlen($candidate),
                'first_difference_at' => $this->firstDifference($legacyResult, $candidate),
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
