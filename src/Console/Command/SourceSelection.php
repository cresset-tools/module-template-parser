<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Console\Command;

use MageOS\TemplateParser\Console\MagentoContext;
use MageOS\TemplateParser\Console\Source\CodebaseEmailTemplates;
use MageOS\TemplateParser\Console\Source\DatabaseTemplates;
use MageOS\TemplateParser\Console\Source\SourceUnavailable;
use MageOS\TemplateParser\Console\Source\TemplateSource;
use MageOS\TemplateParser\Console\TemplateSubject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Which pile of templates a command works on.
 *
 * Shared between check and diff because the answer should not depend on which you ran, and
 * because "everything" has to mean the same thing in CI as it does when a merchant is
 * sizing a migration.
 */
trait SourceSelection
{
    /** @var array<string,string> */
    private static array $sources = [
        'codebase' => 'email templates shipped as files',
        'email' => 'email templates in the database',
        'cms' => 'CMS blocks and pages',
        'newsletter' => 'newsletter templates',
    ];

    protected function addSourceOptions(): static
    {
        $this->addOption(
            'source',
            's',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Where to read templates from: ' . implode(', ', array_keys(self::$sources)) . ', or all',
            ['all']
        );

        return $this;
    }

    /** @return TemplateSource[] */
    protected function selectedSources(InputInterface $input, MagentoContext $magento): array
    {
        $requested = (array)$input->getOption('source');
        if (in_array('all', $requested, true)) {
            $requested = array_keys(self::$sources);
        }

        $sources = [];
        foreach ($requested as $name) {
            $sources[] = match ($name) {
                'codebase' => new CodebaseEmailTemplates($magento->root()),
                'email' => DatabaseTemplates::emailTemplates($magento),
                'cms' => DatabaseTemplates::cmsBlocks($magento),
                'newsletter' => DatabaseTemplates::newsletterTemplates($magento),
                default => throw new \InvalidArgumentException(sprintf(
                    'Unknown source "%s". Use one of: %s, all.',
                    $name,
                    implode(', ', array_keys(self::$sources))
                )),
            };

            if ($name === 'cms') {
                $sources[] = DatabaseTemplates::cmsPages($magento);
            }
        }

        return $sources;
    }

    /**
     * Reads every selected source, and says out loud which could not be read.
     *
     * A source that cannot be read is never silently left out: with the database down,
     * `diff --source=all` compared the codebase files alone and exited 0, which reads as a
     * pass. The warning goes to stderr so a JSON report on stdout stays parseable.
     *
     * @return array{0: list<list<TemplateSubject>>, 1: array<string,string>}
     *         the subjects of each readable source, and source name => why it was skipped
     */
    protected function readSources(InputInterface $input, MagentoContext $magento, OutputInterface $output): array
    {
        $groups = [];
        $skipped = [];
        foreach ($this->selectedSources($input, $magento) as $source) {
            $reason = $source->unavailableReason();
            if ($reason === null) {
                try {
                    $groups[] = [...$source->subjects()];
                    continue;
                } catch (SourceUnavailable $e) {
                    $reason = $e->getMessage();
                }
            }
            $skipped[$source->name()] = $reason;
            $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errors->writeln(sprintf('<comment>skipped %s:</comment> %s', $source->name(), $reason));
        }

        return [$groups, $skipped];
    }

    /**
     * Whether a skipped source makes the run incomplete rather than merely narrower.
     *
     * It does when the source was asked for by name, or when a store is there and its
     * database still could not be read - that is a broken store, not a missing one. Only
     * `--source=all` outside a store may skip the database sources quietly-but-visibly:
     * checking a codebase in CI with no store is a supported use.
     *
     * @param array<string,string> $skipped
     */
    protected function skipsMakeRunIncomplete(InputInterface $input, MagentoContext $magento, array $skipped): bool
    {
        if ($skipped === []) {
            return false;
        }

        return $magento->isAvailable() || !in_array('all', (array)$input->getOption('source'), true);
    }

    /**
     * @param array<string,string> $skipped
     * @return list<array{source: string, reason: string}>
     */
    private static function skippedRows(array $skipped): array
    {
        $rows = [];
        foreach ($skipped as $source => $reason) {
            $rows[] = ['source' => $source, 'reason' => $reason];
        }

        return $rows;
    }
}
