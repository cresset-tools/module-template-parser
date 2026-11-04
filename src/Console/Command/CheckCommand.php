<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Console\Command;

use MageOS\TemplateParser\Console\StoreOption;
use MageOS\TemplateParser\Console\Auditor;
use MageOS\TemplateParser\Console\EngineFactory;
use MageOS\TemplateParser\Console\HostExtensions;
use MageOS\TemplateParser\Console\Finding;
use MageOS\TemplateParser\Console\Mode;
use MageOS\TemplateParser\Console\StoreEmulator;
use MageOS\TemplateParser\Console\TemplateSubject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'check', description: 'Check templates render, and say what to fix when they do not')]
class CheckCommand extends Command
{
    /** No selected source had a template to check - not a pass. */
    public const NOTHING_CHECKED = 2;

    /** A selected source could not be read, so the run did not cover what was asked. */
    public const SOURCE_UNAVAILABLE = 3;

    use MagentoAware;
    use SourceSelection;

    protected function configure(): void
    {
        $this->addPostureOption()
            ->addLayoutOption()
            ->addSourceOptions()
            ->addArgument('path', InputArgument::OPTIONAL, 'A single template file to check instead of a source')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view to render in, by id or code')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Exit non-zero at this severity or worse: error, warning, note', 'error')
            ->setHelp(<<<'HELP'
Renders every template it can find and reports what stops it.

Exit code is what makes this useful in CI: 0 when nothing at or above --fail-on
was found, 1 otherwise, 2 when there was nothing to check at all, and 3 when a
selected source could not be read (a store whose database is down, say). The default only fails on errors, so a first run does not
drown you in the warnings a decade of templates will produce.

  template-parser check --source=codebase --posture=strict --fail-on=error
  template-parser check --source=all --format=json > findings.json
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $magento = $this->magento();
        $mode = $this->posture($input, $output);
        $failOn = $this->failOn($input);
        $storeId = StoreOption::resolve($input->getOption('store'), StoreOption::codes((new StoreEmulator($magento))->stores()));

        $auditor = new Auditor(
            new EngineFactory($magento, $this->layoutHandles($input)),
            new StoreEmulator($magento),
            new HostExtensions($magento)
        );

        $skipped = [];
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
            $checked = 0;
            [$groups, $skipped] = $this->readSources($input, $magento, $output);
            foreach ($groups as $subjects) {
                $checked += count($subjects);
                $findings = array_merge($findings, $auditor->check($subjects, $mode, $storeId));
            }

            // Nothing found to check is not a pass. A CI job pointed at the wrong root, or run
            // outside a store for --source=email, reported "0 error(s)" and exited 0.
            if ($checked === 0) {
                $output->writeln('<comment>Nothing to check:</comment> no selected source had any templates here.');

                return self::NOTHING_CHECKED;
            }
        }

        $exit = $input->getOption('format') === 'json'
            ? $this->reportJson($findings, $output, $failOn, $skipped)
            : $this->reportText($findings, $output, $failOn, $mode, $skipped);

        // A partial scan is not a pass, however clean the part was.
        return $exit === Command::SUCCESS && $this->skipsMakeRunIncomplete($input, $magento, $skipped)
            ? self::SOURCE_UNAVAILABLE
            : $exit;
    }

    /**
     * @param Finding[] $findings
     * @param array<string,string> $skipped
     */
    private function reportText(array $findings, OutputInterface $output, string $failOn, Mode $mode, array $skipped = []): int
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
            'checked in the %s posture: %d error(s), %d warning(s), %d note(s)',
            $mode->value,
            $counts[Finding::ERROR],
            $counts[Finding::WARNING],
            $counts[Finding::NOTE]
        ));
        if ($skipped !== []) {
            $output->writeln(sprintf('<comment>not checked:</comment> %s - see above', implode(', ', array_keys($skipped))));
        }

        return $this->exitCode($counts, $failOn);
    }

    /**
     * @param Finding[] $findings
     * @param array<string,string> $skipped
     */
    private function reportJson(array $findings, OutputInterface $output, string $failOn, array $skipped = []): int
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

        $output->writeln(json_encode(['findings' => $rows, 'counts' => $counts, 'skipped' => self::skippedRows($skipped)], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $this->exitCode($counts, $failOn);
    }

    /**
     * Read --fail-on before the scan, not at the end of it.
     *
     * exitCode() runs after a full codebase walk and after the report is printed, so a
     * misspelling caught there costs the whole run - and, because the severities nest, a
     * misspelling that fell through to the error gate let a build pass that should have
     * failed. --posture and --source are read here for the same reason.
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
