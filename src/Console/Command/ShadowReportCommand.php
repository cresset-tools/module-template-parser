<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Command;

use Cresset\TemplateParser\Console\Shadow\ShadowReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'shadow:report', description: 'Summarise what Shadow mode recorded, per store view')]
class ShadowReportCommand extends Command
{
    use MagentoAware;
    use ShadowAware;

    /** Exit code when nothing in scope was ever compared. */
    public const NOTHING_RECORDED = 2;

    protected function configure(): void
    {
        $this->addOption('store', null, InputOption::VALUE_REQUIRED, 'Only this store view id')
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'Only this template, e.g. cms_block:7; * matches anything, e.g. "email:*"')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only count divergences at or after this time (UTC), e.g. "2026-09-01 12:00" or "-7 days"')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text')
            ->setHelp(<<<'HELP'
Reads what Shadow mode recorded and says, per store view, whether its templates
render the same through both engines.

The exit code is the rollout gate:

  0  compared, and nothing diverged or crashed (since --since, when given)
  1  something diverged or crashed
  2  nothing in scope was compared at all - Shadow is off, or nothing rendered yet

2 is separate on purpose. "No divergences" and "no data" look alike in a count
of failures, and only one of them is evidence for switching to Parser.

Refusals are listed but never fail the report: Parser mode falls back to the
legacy filter for them, so the customer gets exactly what they get today.

  template:shadow:report --store=1
  template:shadow:report --template="cms_block:*" --since="-7 days"
  template:shadow:report --format=json
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string)$input->getOption('format');
        if (!in_array($format, ['text', 'json'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown --format "%s". Use text or json.', $format));
        }
        $since = $this->since($input);
        $storeId = $input->getOption('store') !== null ? (int)$input->getOption('store') : null;
        $template = $input->getOption('template') !== null ? (string)$input->getOption('template') : null;

        $table = $this->shadowTable($output);
        if ($table === null) {
            return Command::FAILURE;
        }

        $report = new ShadowReport($table->rows($storeId, $template), $table->stores(), $since);
        $exit = $report->isEmpty() ? self::NOTHING_RECORDED : ($report->isFailing() ? Command::FAILURE : Command::SUCCESS);

        if ($format === 'json') {
            $output->writeln((string)json_encode([
                'since' => $since,
                'clean' => $exit === Command::SUCCESS,
                'recorded' => !$report->isEmpty(),
                'stores' => array_map(fn (array $store): array => $this->jsonStore($store), $report->stores()),
                'orphaned_rows' => $report->orphanedRows(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        foreach ($report->stores() as $store) {
            $this->textStore($store, $output);
        }

        if ($report->orphanedRows() > 0) {
            $output->writeln(sprintf('<fg=gray>%d row(s) for deleted store views not shown.</>', $report->orphanedRows()));
        }

        $output->writeln(match ($exit) {
            self::NOTHING_RECORDED => sprintf(
                '<comment>Nothing recorded%s.</comment> Put a store view in Shadow (see %s) and let templates render.',
                $storeId !== null || $template !== null ? ' for this selection' : '',
                $this->sibling('status')
            ),
            Command::FAILURE => '<error>Divergences recorded' . ($since !== null ? ' since ' . $since . ' UTC' : '') . '.</error> Read each one above before moving a store view to Parser.',
            default => '<info>No divergences' . ($since !== null ? ' since ' . $since . ' UTC' : '') . '.</info>',
        });

        return $exit;
    }

    /** @param array<string,mixed> $store */
    private function textStore(array $store, OutputInterface $output): void
    {
        $output->writeln(sprintf('<options=bold>store view %s</> (id %d, %s)', $store['code'], $store['store_id'], $store['name']));
        $output->writeln(sprintf(
            '  %d template(s), %d render(s) from %s to %s UTC',
            $store['templates'],
            $store['renders'],
            $store['first_seen'],
            $store['last_seen']
        ));
        $output->writeln(sprintf(
            '  %d agreed, %d diverged, %d refused, %d crashed',
            $store['agreed'],
            $store['diverged'],
            $store['refused'],
            $store['crashed']
        ));
        $output->writeln($store['last_divergence_at'] !== null
            ? sprintf('  last divergence %s UTC, in %s', $store['last_divergence_at'], $store['last_divergence_template'])
            : sprintf('  <info>clean since %s UTC</info>', $store['first_seen']));
        $output->writeln('');

        foreach ($store['rows'] as $row) {
            if ($row['last_divergence_at'] !== null) {
                $this->textDivergent($row, (int)$store['store_id'], $output);
            }
        }
        foreach ($store['rows'] as $row) {
            if ($row['refused'] > 0) {
                $this->textRefused($row, $output);
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function textDivergent(array $row, int $storeId, OutputInterface $output): void
    {
        $output->writeln(sprintf(
            '  <%s>%s</> %s',
            $row['failing'] ? 'error' : 'fg=gray',
            $row['crashed'] > 0 && $row['diverged'] === 0 ? 'CRASHED ' : 'DIVERGED',
            $row['template']
        ));
        $output->writeln(sprintf(
            '    %d diverged, %d crashed, %d agreed; last %s UTC, %d clean render(s) since',
            $row['diverged'],
            $row['crashed'],
            $row['agreed'],
            $row['last_divergence_at'],
            $row['renders_since_divergence']
        ));

        foreach ($this->details($row) as $line) {
            $output->writeln('    ' . $line);
        }
        $output->writeln('    <fg=cyan>fix:</> ' . $this->fix($row, $storeId));
        $output->writeln('');
    }

    /** @param array<string,mixed> $row */
    private function textRefused(array $row, OutputInterface $output): void
    {
        $refusal = $row['last_refusal'] ?? [];
        $output->writeln(sprintf('  <comment>REFUSED </comment> %s', $row['template']));
        $output->writeln(sprintf('    %d refused of %d render(s)', $row['refused'], $row['agreed'] + $row['diverged'] + $row['refused'] + $row['crashed']));
        if (isset($refusal['problem'])) {
            $output->writeln(sprintf(
                '    %s%s',
                $refusal['problem'],
                isset($refusal['line']) ? sprintf(' (line %d, column %d)', $refusal['line'], $refusal['column'] ?? 0) : ''
            ));
        }
        if (isset($refusal['hint'])) {
            $output->writeln('    hint: ' . $refusal['hint']);
        }
        $output->writeln('    <fg=gray>Parser mode falls back to legacy for this template; fixing it lets the new engine render it.</>');
        $output->writeln('');
    }

    /**
     * @param array<string,mixed> $row
     * @return string[]
     */
    private function details(array $row): array
    {
        $lines = [];

        $divergence = $row['last_divergence'];
        if ($divergence !== null) {
            $lines[] = sprintf(
                'last divergence: legacy %d byte(s), new engine %d, first difference at byte %s',
                $divergence['legacy_length'] ?? 0,
                $divergence['candidate_length'] ?? 0,
                isset($divergence['first_difference_at']) ? (string)$divergence['first_difference_at'] : '?'
            );
            foreach ($divergence['policy_violations'] ?? [] as $violation) {
                $lines[] = 'refused by policy: ' . $violation;
            }
            foreach ($divergence['legacy_incompatibilities'] ?? [] as $incompatibility) {
                $lines[] = 'legacy could not have rendered: ' . $incompatibility;
            }
        }

        $crash = $row['last_crash'];
        if ($crash !== null) {
            $lines[] = sprintf('last crash: %s: %s', $crash['error'] ?? '?', $crash['message'] ?? '');
        }

        return $lines;
    }

    /**
     * What to do next, in the style of `check`: one sentence, naming the command to run.
     *
     * @param array<string,mixed> $row
     */
    private function fix(array $row, int $storeId): string
    {
        if ($row['last_crash'] !== null && ($row['last_divergence'] === null || $row['crashed'] >= $row['diverged'])) {
            return 'a crash is a bug in the new engine, not in the template - please report it with the error above at '
                . 'https://github.com/cresset-tools/module-template-parser/issues';
        }

        $template = (string)$row['template'];
        if (str_starts_with($template, 'unidentified:')) {
            return sprintf(
                'this render could not be traced to a template; run %s --store=%d --source=all to find the one that differs',
                $this->sibling('diff'),
                $storeId
            );
        }

        $divergence = $row['last_divergence'] ?? [];
        $reproduce = sprintf('%s --store=%d --source=%s', $this->sibling('diff'), $storeId, self::source($template));

        if (($divergence['policy_violations'] ?? []) !== []) {
            return sprintf('the new engine refused part of this render by policy (above); run %s to see where, and change the template or the policy', $reproduce);
        }
        if (($divergence['legacy_incompatibilities'] ?? []) !== []) {
            return sprintf('the new engine rendered something the legacy filter cannot (above); run %s to see the output side by side', $reproduce);
        }

        return sprintf('run %s to see where the outputs differ', $reproduce);
    }

    /** The `--source` of check and diff that holds this template. */
    private static function source(string $template): string
    {
        $kind = strstr($template, ':', true) ?: $template;
        $id = substr($template, strlen($kind) + 1);

        return match ($kind) {
            'email' => ctype_digit(strtok($id, '/') ?: '') ? 'email' : 'codebase',
            'newsletter' => 'newsletter',
            'cms_block', 'cms_page' => 'cms',
            default => 'all',
        };
    }

    /**
     * @param array<string,mixed> $store
     * @return array<string,mixed>
     */
    private function jsonStore(array $store): array
    {
        $storeId = (int)$store['store_id'];
        $store['templates_detail'] = array_map(function (array $row) use ($storeId): array {
            $row['fix'] = $row['last_divergence_at'] !== null ? $this->fix($row, $storeId) : null;

            return $row;
        }, $store['rows']);
        unset($store['rows']);

        return $store;
    }

    /** Read before the query, the way check reads --fail-on: a typo must not cost a run. */
    private function since(InputInterface $input): ?string
    {
        $since = $input->getOption('since');
        if ($since === null || $since === '') {
            return null;
        }

        // The procedural form, because it returns false instead of throwing. PHP 8.3's
        // DateMalformedStringException trips older Xdebug releases, which try to add a
        // dynamic property to it and raise an Error in its place - so the constructor turned
        // a typo into a crash on exactly the CI setup that has Xdebug loaded.
        $moment = date_create_immutable((string)$since, new \DateTimeZone('UTC'));
        if ($moment === false) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown --since "%s". Use a date such as "2026-09-01 12:00", or a relative time such as "-7 days".',
                (string)$since
            ));
        }

        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
