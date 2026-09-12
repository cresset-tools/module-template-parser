<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Command;

use Cresset\TemplateParser\Console\Auditor;
use Cresset\TemplateParser\Console\EngineFactory;
use Cresset\TemplateParser\Console\HostExtensions;
use Cresset\TemplateParser\Console\Finding;
use Cresset\TemplateParser\Console\Mode;
use Cresset\TemplateParser\Console\StoreEmulator;
use Cresset\TemplateParser\Console\TemplateSubject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'check', description: 'Check templates render, and say what to fix when they do not')]
class CheckCommand extends Command
{
    use MagentoAware;
    use SourceSelection;

    protected function configure(): void
    {
        $this->addModeOption()
            ->addLayoutOption()
            ->addSourceOptions()
            ->addArgument('path', InputArgument::OPTIONAL, 'A single template file to check instead of a source')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store id to render in')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Exit non-zero at this severity or worse: error, warning, note', 'error')
            ->setHelp(<<<'HELP'
Renders every template it can find and reports what stops it.

Exit code is what makes this useful in CI: 0 when nothing at or above --fail-on
was found, 1 otherwise. The default only fails on errors, so a first run does not
drown you in the warnings a decade of templates will produce.

  template-parser check --source=codebase --mode=strict --fail-on=error
  template-parser check --source=all --format=json > findings.json
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $magento = $this->magento();
        $mode = Mode::parse((string)$input->getOption('mode'));
        $failOn = $this->failOn($input);
        $storeId = $input->getOption('store') !== null ? (int)$input->getOption('store') : null;

        $auditor = new Auditor(
            new EngineFactory($magento, $this->layoutHandles($input)),
            new StoreEmulator($magento),
            new HostExtensions($magento)
        );

        $path = $input->getArgument('path');
        if ($path !== null) {
            if (!is_file($path)) {
                $output->writeln(sprintf('<error>no such file: %s</error>', $path));

                return Command::FAILURE;
            }
            $subjects = [new TemplateSubject('file:' . $path, $path, 'argument', (string)file_get_contents($path))];
            $findings = $auditor->check($subjects, $mode, $storeId);
        } else {
            $findings = [];
            foreach ($this->selectedSources($input, $magento) as $source) {
                if (!$source->isAvailable()) {
                    if ($output->isVerbose()) {
                        $output->writeln(sprintf('<fg=gray>skipping %s - not available here</>', $source->name()));
                    }
                    continue;
                }
                $findings = array_merge($findings, $auditor->check($source->subjects(), $mode, $storeId));
            }
        }

        return $input->getOption('format') === 'json'
            ? $this->reportJson($findings, $output, $failOn)
            : $this->reportText($findings, $output, $failOn, $mode);
    }

    /** @param Finding[] $findings */
    private function reportText(array $findings, OutputInterface $output, string $failOn, Mode $mode): int
    {
        $counts = [Finding::ERROR => 0, Finding::WARNING => 0, Finding::NOTE => 0];

        foreach ($findings as $finding) {
            $counts[$finding->severity]++;
            $colour = match ($finding->severity) {
                Finding::ERROR => 'error',
                Finding::WARNING => 'comment',
                default => 'fg=gray',
            };

            $output->writeln(sprintf(
                '<%s>%s</> %s%s',
                $colour,
                strtoupper($finding->severity),
                $finding->subject->label,
                $finding->line !== null ? ':' . $finding->line : ''
            ));
            $output->writeln('  ' . $finding->summary);
            if ($finding->fix !== null) {
                $output->writeln('  <fg=cyan>fix:</> ' . $finding->fix);
            }
            $output->writeln('');
        }

        $output->writeln(sprintf(
            'checked in %s mode: %d error(s), %d warning(s), %d note(s)',
            $mode->value,
            $counts[Finding::ERROR],
            $counts[Finding::WARNING],
            $counts[Finding::NOTE]
        ));

        return $this->exitCode($counts, $failOn);
    }

    /** @param Finding[] $findings */
    private function reportJson(array $findings, OutputInterface $output, string $failOn): int
    {
        $counts = [Finding::ERROR => 0, Finding::WARNING => 0, Finding::NOTE => 0];
        $rows = [];

        foreach ($findings as $finding) {
            $counts[$finding->severity]++;
            $rows[] = [
                'severity' => $finding->severity,
                'id' => $finding->subject->id,
                'label' => $finding->subject->label,
                'origin' => $finding->subject->origin,
                'line' => $finding->line,
                'summary' => $finding->summary,
                'fix' => $finding->fix,
            ];
        }

        $output->writeln((string)json_encode(['findings' => $rows, 'counts' => $counts], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $this->exitCode($counts, $failOn);
    }

    /**
     * Read --fail-on before the scan, not at the end of it.
     *
     * exitCode() runs after a full codebase walk and after the report is printed, so a
     * misspelling caught there costs the whole run - and, because the severities nest, a
     * misspelling that fell through to the error gate let a build pass that should have
     * failed. --mode and --source are read here for the same reason.
     */
    private function failOn(InputInterface $input): string
    {
        $failOn = strtolower(trim((string)$input->getOption('fail-on')));

        if (!in_array($failOn, ['error', 'warning', 'note'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown --fail-on "%s". Use error, warning or note.',
                (string)$input->getOption('fail-on')
            ));
        }

        return $failOn;
    }

    /** @param array<string,int> $counts */
    private function exitCode(array $counts, string $failOn): int
    {
        $fails = match ($failOn) {
            'note' => $counts[Finding::ERROR] + $counts[Finding::WARNING] + $counts[Finding::NOTE],
            'warning' => $counts[Finding::ERROR] + $counts[Finding::WARNING],
            default => $counts[Finding::ERROR],
        };

        return $fails > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
