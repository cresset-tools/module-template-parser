<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Command;

use Cresset\TemplateParser\Console\Auditor;
use Cresset\TemplateParser\Console\Divergence;
use Cresset\TemplateParser\Console\EngineFactory;
use Cresset\TemplateParser\Console\HostExtensions;
use Cresset\TemplateParser\Console\LegacyRender;
use Cresset\TemplateParser\Console\LegacyRenderer;
use Cresset\TemplateParser\Console\Mode;
use Cresset\TemplateParser\Console\StoreEmulator;
use Cresset\TemplateParser\Console\TemplateSubject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'diff', description: 'Render every template through both engines and report where they differ')]
class DiffCommand extends Command
{
    use MagentoAware;
    use SourceSelection;

    protected function configure(): void
    {
        $this->addModeOption()
            ->addLayoutOption()
            ->addSourceOptions()
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store id to render in')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text')
            ->addOption('show', null, InputOption::VALUE_REQUIRED, 'Excerpt width around the first difference', '60')
            ->addOption('fail-on-divergence', null, InputOption::VALUE_NONE, 'Exit non-zero if anything differs')
            ->setHelp(<<<'HELP'
Renders each template through the filter your store runs today AND through this
engine, and reports every one whose output differs.

This is the number that decides whether a migration is safe, and it needs a
store: the templates that matter are in a merchant's database, not in the
repository. Without one, only syntax can be checked - use `check` for that.

  template-parser diff --source=email --store=1
  template-parser diff --source=all --format=json --fail-on-divergence
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $magento = $this->magento();
        // ONE emulator for the process - see StoreEmulator::$depth. A renderer with its own
        // would tear down the Auditor's, and every template after the first would resolve
        // {{css}} and {{view}} against no theme at all.
        $stores = new StoreEmulator($magento);
        $legacy = new LegacyRenderer($magento, $stores);

        if (!$legacy->isAvailable()) {
            $output->writeln('<error>diff needs a Magento store to compare against.</error>');
            $output->writeln('  ' . ($magento->reason() ?? 'the legacy filter could not be resolved'));
            $output->writeln('  Run this from inside a store, or use <comment>check</comment> to validate syntax without one.');

            return Command::FAILURE;
        }

        $mode = Mode::parse((string)$input->getOption('mode'));
        $storeId = $input->getOption('store') !== null ? (int)$input->getOption('store') : null;
        $width = max(20, (int)$input->getOption('show'));

        $auditor = new Auditor(
            new EngineFactory($magento, $this->layoutHandles($input)),
            $stores,
            new HostExtensions($magento)
        );
        $render = static fn (TemplateSubject $subject): ?LegacyRender => $legacy->render(
            $subject->content,
            $subject->variables,
            $subject->storeId ?? $storeId,
            $subject->kind,
        );

        $divergences = [];
        $examined = 0;
        foreach ($this->selectedSources($input, $magento) as $source) {
            if (!$source->isAvailable()) {
                continue;
            }
            $subjects = [];
            foreach ($source->subjects() as $subject) {
                $subjects[] = $subject;
                $examined++;
            }
            $divergences = array_merge($divergences, $auditor->diff($subjects, $mode, $render, $storeId));
        }

        return $input->getOption('format') === 'json'
            ? $this->reportJson($divergences, $examined, $output, $width, (bool)$input->getOption('fail-on-divergence'))
            : $this->reportText($divergences, $examined, $output, $width, (bool)$input->getOption('fail-on-divergence'));
    }

    /** @param Divergence[] $divergences */
    private function reportText(array $divergences, int $examined, OutputInterface $output, int $width, bool $failOnDivergence): int
    {
        foreach ($divergences as $divergence) {
            $output->writeln('<comment>differs</comment> ' . $divergence->subject->label);
            if ($divergence->note !== null) {
                $output->writeln('  ' . $divergence->note);
            }
            [$legacy, $ours] = $divergence->excerpt($width);
            $at = $divergence->firstDifference();
            if ($at !== null) {
                $output->writeln(sprintf('  first difference at byte %d', $at));
            }
            $output->writeln('  <fg=gray>today</> ' . $this->oneLine($legacy));
            $output->writeln('  <fg=cyan>ours </> ' . $this->oneLine($ours));
            $output->writeln('');
        }

        $output->writeln(sprintf('%d of %d templates differ', count($divergences), $examined));
        if ($divergences !== []) {
            $output->writeln('<fg=gray>A difference is not automatically a problem - compatible mode refuses some</>');
            $output->writeln('<fg=gray>constructs the old filter also could not render. Read each one.</>');
        }

        return $failOnDivergence && $divergences !== [] ? Command::FAILURE : Command::SUCCESS;
    }

    /** @param Divergence[] $divergences */
    private function reportJson(array $divergences, int $examined, OutputInterface $output, int $width, bool $failOnDivergence): int
    {
        $rows = [];
        foreach ($divergences as $divergence) {
            [$legacy, $ours] = $divergence->excerpt($width);
            $rows[] = [
                'id' => $divergence->subject->id,
                'label' => $divergence->subject->label,
                'origin' => $divergence->subject->origin,
                'note' => $divergence->note,
                'first_difference_at' => $divergence->firstDifference(),
                'legacy_excerpt' => $legacy,
                'candidate_excerpt' => $ours,
            ];
        }

        $output->writeln((string)json_encode(
            ['examined' => $examined, 'divergent' => count($rows), 'divergences' => $rows],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));

        return $failOnDivergence && $rows !== [] ? Command::FAILURE : Command::SUCCESS;
    }

    private function oneLine(string $value): string
    {
        return str_replace(["\n", "\r"], ['\n', ''], $value);
    }
}
