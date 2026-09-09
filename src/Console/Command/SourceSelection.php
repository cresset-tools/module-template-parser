<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Command;

use Cresset\TemplateParser\Console\MagentoContext;
use Cresset\TemplateParser\Console\Source\CodebaseEmailTemplates;
use Cresset\TemplateParser\Console\Source\DatabaseTemplates;
use Cresset\TemplateParser\Console\Source\TemplateSource;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

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
}
