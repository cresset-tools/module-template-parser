<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'shadow:clear', description: 'Forget what Shadow mode recorded, so a template starts over')]
class ShadowClearCommand extends Command
{
    use MagentoAware;
    use ShadowAware;

    protected function configure(): void
    {
        $this->addOption('store', null, InputOption::VALUE_REQUIRED, 'Only this store view id')
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'Only this template, e.g. cms_block:7; * matches anything')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Everything, for every store view')
            ->setHelp(<<<'HELP'
Deletes recorded Shadow results, so what is measured next starts from nothing.

For after a fix: clearing a template means its next report shows only renders
made since. `shadow:report --since` answers the same question without deleting
anything, and is usually the better first step.

One of --store, --template or --all is required. Clearing everything is a
decision, not a default: it throws away the evidence a rollout rests on.

  template:shadow:clear --template=cms_block:7
  template:shadow:clear --store=2
  template:shadow:clear --all
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storeId = $input->getOption('store') !== null ? (int)$input->getOption('store') : null;
        $template = $input->getOption('template') !== null && $input->getOption('template') !== ''
            ? (string)$input->getOption('template')
            : null;
        $all = (bool)$input->getOption('all');

        if ($storeId === null && $template === null && !$all) {
            $output->writeln('<error>Say what to clear: --store, --template, or --all for everything.</error>');

            return Command::INVALID;
        }
        if ($all && ($storeId !== null || $template !== null)) {
            $output->writeln('<error>--all clears everything; leave it out to clear a selection.</error>');

            return Command::INVALID;
        }

        $table = $this->shadowTable($output);
        if ($table === null) {
            return Command::FAILURE;
        }

        $removed = $table->clear($storeId, $template);
        $output->writeln(sprintf('Cleared %d row(s).', $removed));

        return Command::SUCCESS;
    }
}
