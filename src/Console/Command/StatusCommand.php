<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Command;

use Cresset\TemplateParser\Console\Shadow\ShadowReport;
use Cresset\TemplateParser\Magento\Config\EngineMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'status', description: 'Show which template engine each store view runs, and since when')]
class StatusCommand extends Command
{
    use MagentoAware;
    use ShadowAware;

    private ?EngineMode $engineMode = null;

    /** For tests; otherwise resolved from the store. */
    public function setEngineMode(EngineMode $engineMode): static
    {
        $this->engineMode = $engineMode;

        return $this;
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text')
            ->setHelp(<<<'HELP'
Lists every website and store view with the stage it is at in the rollout -
Legacy, Shadow, and later Parser - where that is configured, and since when.
For a store view with Shadow results, one line of what they say; shadow:report
has the rest.

The stage shown is the one renders use, read the way the plugin reads it. Where
the value saved in the database says something else, that is said too: the
config cache is stale, or app/etc/env.php or config.php overrides it.

  template:status
  template:status --format=json
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string)$input->getOption('format');
        if (!in_array($format, ['text', 'json'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown --format "%s". Use text or json.', $format));
        }

        $database = $this->database($output);
        if ($database === null) {
            return Command::FAILURE;
        }

        $saved = $database->configValues(EngineMode::XML_PATH);
        $stores = $database->stores();
        $websites = $database->websites();
        $recorded = $database->exists();
        $report = $recorded ? new ShadowReport($database->rows(), $stores) : null;
        $summaries = [];
        foreach ($report?->stores() ?? [] as $summary) {
            $summaries[$summary['store_id']] = $summary;
        }

        $rows = [];
        foreach ($stores as $storeId => $store) {
            $origin = $this->origin($saved, $storeId, $store['website_id']);
            $savedMode = self::normalise($origin['value']);
            $effective = $this->effective($storeId) ?? $savedMode;

            $rows[] = [
                'store_id' => $storeId,
                'code' => $store['code'],
                'name' => $store['name'],
                'website_id' => $store['website_id'],
                'mode' => $effective,
                'set_at' => $origin['scope'],
                'since' => $origin['updated_at'],
                'saved_mode' => $savedMode,
                'shadow' => isset($summaries[$storeId]) ? ShadowReport::oneLine($summaries[$storeId]) : null,
            ];
        }

        if ($format === 'json') {
            $output->writeln((string)json_encode([
                'shadow_table' => $recorded,
                'websites' => array_map(
                    static fn (int $id, array $website): array => ['website_id' => $id] + $website,
                    array_keys($websites),
                    array_values($websites)
                ),
                'stores' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        foreach ($websites as $websiteId => $website) {
            $output->writeln(sprintf('<options=bold>%s</> (website %s)', $website['name'], $website['code']));
            foreach ($rows as $row) {
                if ($row['website_id'] === $websiteId) {
                    $this->textRow($row, $output);
                }
            }
            $output->writeln('');
        }

        if (!$recorded) {
            $output->writeln('<fg=gray>No Shadow table yet: enable the module and run bin/magento setup:upgrade.</>');
        }
        $output->writeln(sprintf(
            '<fg=gray>Set a stage with bin/magento config:set --scope=stores --scope-code=CODE %s shadow; read Shadow results with %s.</>',
            EngineMode::XML_PATH,
            $this->sibling('shadow:report')
        ));

        return Command::SUCCESS;
    }

    /** @param array<string,mixed> $row */
    private function textRow(array $row, OutputInterface $output): void
    {
        $output->writeln(sprintf(
            '  %-12s %-8s %s',
            sprintf('%s (%d)', $row['code'], $row['store_id']),
            ucfirst($row['mode']),
            $row['set_at'] === 'not set'
                ? 'not set - the module default'
                : sprintf('set at %s, since %s UTC', $row['set_at'], $row['since'] ?? '?')
        ));

        if ($row['saved_mode'] !== $row['mode']) {
            $output->writeln(sprintf(
                '    <comment>the database says %s; the config cache is stale, or app/etc/env.php or config.php overrides it</comment>',
                ucfirst($row['saved_mode'])
            ));
        }
        if ($row['shadow'] !== null) {
            $output->writeln('    ' . $row['shadow']);
        }
    }

    /**
     * Which saved value applies to a store view, the way Magento's scope fallback picks it:
     * the store view's own, else its website's, else the default.
     *
     * @param list<array{scope:string,scope_id:int,value:?string,updated_at:?string}> $saved
     * @return array{scope:string,value:?string,updated_at:?string}
     */
    private function origin(array $saved, int $storeId, int $websiteId): array
    {
        foreach ([['stores', $storeId, 'store view'], ['websites', $websiteId, 'website'], ['default', 0, 'default']] as [$scope, $id, $label]) {
            foreach ($saved as $value) {
                if ($value['scope'] === $scope && $value['scope_id'] === $id) {
                    return ['scope' => $label, 'value' => $value['value'], 'updated_at' => $value['updated_at']];
                }
            }
        }

        return ['scope' => 'not set', 'value' => null, 'updated_at' => null];
    }

    private function effective(int $storeId): ?string
    {
        $engineMode = $this->engineMode ?? $this->magento()->get(EngineMode::class);

        if (!$engineMode instanceof EngineMode) {
            return null;
        }

        try {
            return $engineMode->forStore($storeId);
        } catch (\Throwable) {
            return null;
        }
    }

    /** As EngineMode reads a value: anything it does not recognise is Legacy. */
    private static function normalise(?string $value): string
    {
        $value = strtolower(trim((string)$value));

        return in_array($value, EngineMode::ALL, true) ? $value : EngineMode::LEGACY;
    }
}
