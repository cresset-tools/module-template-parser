<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Console\Command\ShadowClearCommand;
use Cresset\TemplateParser\Console\Command\ShadowReportCommand;
use Cresset\TemplateParser\Console\MagentoContext;
use Cresset\TemplateParser\Console\Magento\ShadowReportCommand as BinMagentoReport;
use Cresset\TemplateParser\Console\Shadow\ShadowReport;
use Cresset\TemplateParser\Console\Shadow\ShadowTable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * shadow:report and shadow:clear, against the statements they really run.
 *
 * The report is the rollout gate, so what matters most is that it cannot say "clean" by
 * accident: not over an empty table, not with a divergence left out of a count, not because a
 * pattern matched more or less than it said.
 */
final class ShadowCommandsTest extends TestCase
{
    private SqliteConnection $db;

    protected function setUp(): void
    {
        $this->db = (new SqliteConnection())->createShadowTable();
    }

    private function report(array $options = [], ?Command $command = null): CommandTester
    {
        $command ??= new ShadowReportCommand();
        $command->setMagentoContext(MagentoContext::unavailable('tests'));
        $command->setShadowTable(new ShadowTable($this->db));
        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester;
    }

    private function clear(array $options): CommandTester
    {
        $command = (new ShadowClearCommand())
            ->setMagentoContext(MagentoContext::unavailable('tests'))
            ->setShadowTable(new ShadowTable($this->db));
        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester;
    }

    private function seedCleanStore(): void
    {
        $this->db
            ->insertShadow(['store_id' => 1, 'template' => 'email:sales_email_order_template', 'agreed' => 40, 'renders_since_divergence' => 40])
            ->insertShadow(['store_id' => 1, 'template' => 'cms_block:7', 'agreed' => 900, 'renders_since_divergence' => 900,
                'first_seen' => '2026-08-30 10:00:00']);
    }

    // ---------------------------------------------------------------- the gate

    public function testACleanStoreExitsZeroAndSaysSince(): void
    {
        $this->seedCleanStore();

        $tester = $this->report();

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('store view default (id 1, Default Store View)', $display);
        self::assertStringContainsString('2 template(s), 940 render(s) from 2026-08-30 10:00:00 to 2026-09-29 12:00:00 UTC', $display);
        self::assertStringContainsString('clean since 2026-08-30 10:00:00 UTC', $display);
        self::assertStringContainsString('No divergences.', $display);
    }

    /** "No divergences" and "no data" must not look alike. */
    public function testNothingRecordedIsItsOwnExitCode(): void
    {
        $tester = $this->report();

        self::assertSame(ShadowReportCommand::NOTHING_RECORDED, $tester->getStatusCode());
        self::assertStringContainsString('Nothing recorded', $tester->getDisplay());
        self::assertStringNotContainsString('No divergences', $tester->getDisplay());
    }

    public function testNothingRecordedForASelectionIsNotCleanEither(): void
    {
        $this->seedCleanStore();

        $tester = $this->report(['--store' => '2']);

        self::assertSame(ShadowReportCommand::NOTHING_RECORDED, $tester->getStatusCode());
        self::assertStringContainsString('Nothing recorded for this selection', $tester->getDisplay());
    }

    public function testADivergenceFailsTheReportAndSaysWhatToRun(): void
    {
        $this->seedCleanStore();
        $this->db->insertShadow([
            'store_id' => 2, 'template' => 'cms_block:9', 'agreed' => 30, 'diverged' => 2,
            'last_divergence_at' => '2026-09-20 09:00:00', 'renders_since_divergence' => 12,
            'last_divergence' => [
                'legacy_length' => 120, 'candidate_length' => 124, 'first_difference_at' => 57,
                'policy_violations' => [], 'legacy_incompatibilities' => ['{{for}} over a scalar'],
            ],
        ]);

        $tester = $this->report();

        self::assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('DIVERGED cms_block:9', $display);
        self::assertStringContainsString('2 diverged, 0 crashed, 30 agreed; last 2026-09-20 09:00:00 UTC, 12 clean render(s) since', $display);
        self::assertStringContainsString('legacy 120 byte(s), new engine 124, first difference at byte 57', $display);
        self::assertStringContainsString('legacy could not have rendered: {{for}} over a scalar', $display);
        self::assertStringContainsString('fix:', $display);
        self::assertStringContainsString('diff --store=2 --source=cms', $display);
        self::assertStringContainsString('last divergence 2026-09-20 09:00:00 UTC, in cms_block:9', $display);
    }

    /** The advice names the command as it is spelled where the reader is typing. */
    public function testTheFixNamesTheCommandInThisEntrypoint(): void
    {
        $this->db->insertShadow([
            'store_id' => 1, 'template' => 'email:12', 'diverged' => 1, 'last_divergence_at' => '2026-09-20 09:00:00',
            'last_divergence' => ['legacy_length' => 1, 'candidate_length' => 2, 'first_difference_at' => 0],
        ]);
        $objectManager = new class implements \Magento\Framework\ObjectManagerInterface {
            public function create($type, array $arguments = []) { return null; }
            public function get($type) { return null; }
            public function configure(array $configuration) {}
        };

        self::assertStringContainsString('run diff --store=1 --source=email', $this->report()->getDisplay());
        self::assertStringContainsString(
            'run template:diff --store=1 --source=email',
            $this->report([], new BinMagentoReport($objectManager))->getDisplay()
        );
    }

    public function testACrashIsCalledABugInTheEngine(): void
    {
        $this->db->insertShadow([
            'store_id' => 1, 'template' => 'email:sales_email_order_template', 'agreed' => 3, 'crashed' => 1,
            'last_divergence_at' => '2026-09-21 10:00:00',
            'last_crash' => ['error' => 'TypeError', 'message' => 'boom'],
        ]);

        $tester = $this->report();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('CRASHED  email:sales_email_order_template', $tester->getDisplay());
        self::assertStringContainsString('last crash: TypeError: boom', $tester->getDisplay());
        self::assertStringContainsString('bug in the new engine', $tester->getDisplay());
    }

    /** Parser mode falls back for a refusal, so it is listed and never fails the gate. */
    public function testARefusalIsListedButDoesNotFail(): void
    {
        $this->db->insertShadow([
            'store_id' => 1, 'template' => 'cms_page:2', 'agreed' => 5, 'refused' => 3, 'renders_since_divergence' => 8,
            'last_refusal' => ['error' => 'X', 'problem' => 'Unknown directive {{shout}}', 'line' => 4, 'column' => 2, 'hint' => 'register it'],
        ]);

        $tester = $this->report();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('REFUSED  cms_page:2', $tester->getDisplay());
        self::assertStringContainsString('Unknown directive {{shout}} (line 4, column 2)', $tester->getDisplay());
        self::assertStringContainsString('hint: register it', $tester->getDisplay());
    }

    /** A divergence before --since is history: still counted, no longer failing. */
    public function testSinceNarrowsWhatFails(): void
    {
        $this->db->insertShadow([
            'store_id' => 1, 'template' => 'cms_block:9', 'agreed' => 50, 'diverged' => 1,
            'last_divergence_at' => '2026-09-10 09:00:00', 'renders_since_divergence' => 50,
        ]);

        self::assertSame(1, $this->report()->getStatusCode());
        self::assertSame(1, $this->report(['--since' => '2026-09-10 09:00'])->getStatusCode(), 'at the moment still counts');

        $after = $this->report(['--since' => '2026-09-10 09:00:01']);
        self::assertSame(0, $after->getStatusCode());
        self::assertStringContainsString('No divergences since 2026-09-10 09:00:01 UTC', $after->getDisplay());
        self::assertStringContainsString('DIVERGED cms_block:9', $after->getDisplay(), 'still shown, just not failing');
    }

    public function testAnUnreadableSinceIsRefusedBeforeTheQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown --since "whenever"/');

        $this->report(['--since' => 'whenever']);
    }

    public function testTemplatePatternsSelectAndEscape(): void
    {
        $this->db
            ->insertShadow(['store_id' => 1, 'template' => 'cms_block:7', 'agreed' => 1])
            ->insertShadow(['store_id' => 1, 'template' => 'cms_page:7', 'agreed' => 1])
            ->insertShadow(['store_id' => 1, 'template' => 'email:a_b', 'agreed' => 1])
            ->insertShadow(['store_id' => 1, 'template' => 'email:aXb', 'agreed' => 1]);
        $table = new ShadowTable($this->db);

        $names = static fn (array $rows): array => array_column($rows, 'template');

        self::assertSame(['cms_block:7'], $names($table->rows(null, 'cms_block:*')));
        self::assertSame(['cms_block:7', 'cms_page:7'], $names($table->rows(null, 'cms_*:7')));
        self::assertSame(['email:a_b'], $names($table->rows(null, 'email:a_*')), '_ is literal, not any character');
        self::assertSame(['email:a_b'], $names($table->rows(null, 'email:a_b')));
    }

    public function testJsonCarriesEveryTemplateAndItsFix(): void
    {
        $this->seedCleanStore();
        $this->db->insertShadow([
            'store_id' => 1, 'template' => 'newsletter:3', 'diverged' => 1, 'last_divergence_at' => '2026-09-20 09:00:00',
            'last_divergence' => ['legacy_length' => 1, 'candidate_length' => 2, 'first_difference_at' => 0,
                'policy_violations' => ['{{block}} class not allowed'], 'legacy_incompatibilities' => []],
        ]);

        $tester = $this->report(['--format' => 'json']);
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $tester->getStatusCode());
        self::assertFalse($report['clean']);
        self::assertTrue($report['recorded']);
        self::assertCount(1, $report['stores']);
        self::assertSame(3, $report['stores'][0]['templates']);
        $newsletter = array_values(array_filter(
            $report['stores'][0]['templates_detail'],
            static fn (array $t): bool => $t['template'] === 'newsletter:3'
        ))[0];
        self::assertStringContainsString('by policy', $newsletter['fix']);
        self::assertStringContainsString('--source=newsletter', $newsletter['fix']);
        self::assertSame(['{{block}} class not allowed'], $newsletter['last_divergence']['policy_violations']);
    }

    /** No foreign key, so a deleted store view's rows remain; they are left out, and counted. */
    public function testRowsForADeletedStoreAreLeftOutButMentioned(): void
    {
        $this->seedCleanStore();
        $this->db->insertShadow(['store_id' => 99, 'template' => 'cms_block:1', 'diverged' => 5, 'last_divergence_at' => '2026-09-20 09:00:00']);

        $tester = $this->report();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 row(s) for deleted store views not shown', $tester->getDisplay());
    }

    public function testAMissingTableSaysToRunSetupUpgrade(): void
    {
        $this->db = new SqliteConnection();

        $tester = $this->report();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No cresset_template_shadow table', $tester->getDisplay());
        self::assertStringContainsString('setup:upgrade', $tester->getDisplay());
    }

    public function testWithoutAStoreItSaysSoRatherThanReportingNothing(): void
    {
        $command = (new ShadowReportCommand())->setMagentoContext(MagentoContext::unavailable('no app/etc/env.php found'));
        $tester = new CommandTester($command);

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('needs a Magento store', $tester->getDisplay());
        self::assertStringContainsString('no app/etc/env.php found', $tester->getDisplay());
    }

    // ---------------------------------------------------------------- the arithmetic

    public function testTheReportAggregatesPerStore(): void
    {
        $rows = [
            ['store_id' => 1, 'template' => 'a', 'agreed' => 5, 'diverged' => 1, 'refused' => 2, 'crashed' => 0,
                'first_seen' => '2026-09-02 00:00:00', 'last_seen' => '2026-09-05 00:00:00',
                'last_divergence_at' => '2026-09-04 00:00:00', 'renders_since_divergence' => 3],
            ['store_id' => 1, 'template' => 'b', 'agreed' => 1, 'diverged' => 0, 'refused' => 0, 'crashed' => 1,
                'first_seen' => '2026-09-01 00:00:00', 'last_seen' => '2026-09-06 00:00:00',
                'last_divergence_at' => '2026-09-05 00:00:00', 'renders_since_divergence' => 0],
        ];
        $report = new ShadowReport($rows, [1 => ['code' => 'default', 'name' => 'Default', 'website_id' => 1]]);
        $store = $report->stores()[0];

        self::assertSame(2, $store['templates']);
        self::assertSame(10, $store['renders']);
        self::assertSame([6, 1, 2, 1], [$store['agreed'], $store['diverged'], $store['refused'], $store['crashed']]);
        self::assertSame('2026-09-01 00:00:00', $store['first_seen']);
        self::assertSame('2026-09-06 00:00:00', $store['last_seen']);
        self::assertSame('2026-09-05 00:00:00', $store['last_divergence_at']);
        self::assertSame('b', $store['last_divergence_template']);
        self::assertSame(2, $store['failing']);
        self::assertTrue($report->isFailing());
        self::assertSame(
            '2 template(s), 10 render(s): 6 agreed, 1 diverged, 2 refused, 1 crashed; last divergence 2026-09-05 00:00:00 UTC',
            ShadowReport::oneLine($store)
        );
    }

    // ---------------------------------------------------------------- clear

    public function testClearRefusesWithoutASelection(): void
    {
        $this->seedCleanStore();

        $tester = $this->clear([]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--store, --template, or --all', $tester->getDisplay());
        self::assertCount(2, (new ShadowTable($this->db))->rows());
    }

    public function testClearRefusesAllTogetherWithASelection(): void
    {
        $this->seedCleanStore();

        self::assertSame(Command::INVALID, $this->clear(['--all' => true, '--store' => '1'])->getStatusCode());
        self::assertCount(2, (new ShadowTable($this->db))->rows());
    }

    public function testClearRemovesOnlyTheSelection(): void
    {
        $this->seedCleanStore();
        $this->db->insertShadow(['store_id' => 2, 'template' => 'cms_block:7', 'agreed' => 1]);

        $tester = $this->clear(['--template' => 'cms_block:7', '--store' => '1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Cleared 1 row(s).', $tester->getDisplay());
        self::assertSame(
            [[1, 'email:sales_email_order_template'], [2, 'cms_block:7']],
            array_map(static fn (array $r): array => [(int)$r['store_id'], $r['template']], (new ShadowTable($this->db))->rows())
        );
    }

    public function testClearAllEmptiesTheTable(): void
    {
        $this->seedCleanStore();

        self::assertStringContainsString('Cleared 2 row(s).', $this->clear(['--all' => true])->getDisplay());
        self::assertSame([], (new ShadowTable($this->db))->rows());
    }
}
