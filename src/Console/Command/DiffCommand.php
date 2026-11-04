<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Console\Command;

use MageOS\TemplateParser\Console\StoreOption;
use MageOS\TemplateParser\Console\Auditor;
use MageOS\TemplateParser\Console\Divergence;
use MageOS\TemplateParser\Console\EngineFactory;
use MageOS\TemplateParser\Console\HostExtensions;
use MageOS\TemplateParser\Console\LegacyRender;
use MageOS\TemplateParser\Console\LegacyRenderer;
use MageOS\TemplateParser\Console\Mode;
use MageOS\TemplateParser\Console\StoreEmulator;
use MageOS\TemplateParser\Console\TemplateSubject;
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

    /** A selected source could not be read, so the comparison did not cover what was asked. */
    public const SOURCE_UNAVAILABLE = 3;

    protected function configure(): void
    {
        $this->addPostureOption()
            ->addLayoutOption()
            ->addSourceOptions()
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view, by id or code: what switching this store view to Parser would change')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text')
            ->addOption('show', null, InputOption::VALUE_REQUIRED, 'Excerpt width around the first difference', '60')
            ->addOption('fail-on-divergence', null, InputOption::VALUE_NONE, 'Exit non-zero if anything differs')
            ->setHelp(<<<'HELP'
Renders each template through the filter your store runs today AND through this
engine, and reports every one whose output differs.

With --store=N that is the answer to "what would switching this store view to
Parser change?": the default posture, compatible, is the one Parser mode runs.
Shadow mode measures the same thing on live renders; this measures it now, over
every template, without waiting for them to be sent.

It needs a store: the templates that matter are in a merchant's database, not in
the repository. Without one, only syntax can be checked - use `check` for that.

  template-parser diff --source=email --store=1
  template-parser diff --source=all --format=json --fail-on-divergence

A selected source that cannot be read - a database that is down, say - is named
on stderr and exits 3, whatever the comparison of the rest found.
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

        $mode = $this->posture($input, $output);
        $storeId = StoreOption::resolve($input->getOption('store'), StoreOption::codes((new StoreEmulator($magento))->stores()));
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
        [$groups, $skipped] = $this->readSources($input, $magento, $output);
        foreach ($groups as $subjects) {
            $examined += count($subjects);
            $divergences = array_merge($divergences, $auditor->diff($subjects, $mode, $render, $storeId));
        }

        $exit = $input->getOption('format') === 'json'
            ? $this->reportJson($divergences, $examined, $output, $width, (bool)$input->getOption('fail-on-divergence'), $skipped)
            : $this->reportText($divergences, $examined, $output, $width, (bool)$input->getOption('fail-on-divergence'), $skipped);

        // "0 of N differ" over part of what was asked is not a pass. diff always has a store,
        // so a source it could not read is a store that is broken, not one that is absent.
        return $exit === Command::SUCCESS && $this->skipsMakeRunIncomplete($input, $magento, $skipped)
            ? self::SOURCE_UNAVAILABLE
            : $exit;
    }

    /**
     * @param Divergence[] $divergences
     * @param array<string,string> $skipped
     */
    private function reportText(array $divergences, int $examined, OutputInterface $output, int $width, bool $failOnDivergence, array $skipped = []): int
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
        if ($skipped !== []) {
            $output->writeln(sprintf('<comment>not compared:</comment> %s - see above', implode(', ', array_keys($skipped))));
        }
        if ($divergences !== []) {
            $output->writeln('<fg=gray>A difference is not automatically a problem - compatible mode refuses some</>');
            $output->writeln('<fg=gray>constructs the old filter also could not render. Read each one.</>');
        }

        return $failOnDivergence && $divergences !== [] ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param Divergence[] $divergences
     * @param array<string,string> $skipped
     */
    private function reportJson(array $divergences, int $examined, OutputInterface $output, int $width, bool $failOnDivergence, array $skipped = []): int
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

        $output->writeln(json_encode(
            ['examined' => $examined, 'divergent' => count($rows), 'divergences' => $rows, 'skipped' => self::skippedRows($skipped)],
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));

        return $failOnDivergence && $rows !== [] ? Command::FAILURE : Command::SUCCESS;
    }

    private function oneLine(string $value): string
    {
        return str_replace(["\n", "\r"], ['\n', ''], $value);
    }
}
