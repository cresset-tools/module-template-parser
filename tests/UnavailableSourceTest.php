<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Tests;

use MageOS\TemplateParser\Console\Application;
use MageOS\TemplateParser\Console\Command\CheckCommand;
use MageOS\TemplateParser\Console\MagentoContext;
use MageOS\TemplateParser\Console\Source\DatabaseTemplates;
use MageOS\TemplateParser\Console\Source\SourceUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A source that cannot be read is named, and a run that missed one is not a pass.
 *
 * With the database down, `diff --source=all` compared the codebase files alone and exited 0,
 * and `check` mentioned the skip only under -v. Worse, the database sources did not even count
 * as unavailable: ResourceConnection builds without a database, and the failed query was taken
 * for a missing table, so each source read as empty.
 */
final class UnavailableSourceTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testADatabaseThatIsDownIsUnavailableNotEmpty(): void
    {
        $source = DatabaseTemplates::emailTemplates($this->store(new FakeDatabase(down: true)));

        self::assertFalse($source->isAvailable());
        self::assertStringContainsString('could not be reached', (string)$source->unavailableReason());
        self::assertStringContainsString('Connection refused', (string)$source->unavailableReason());

        $this->expectException(SourceUnavailable::class);
        iterator_to_array($source->subjects());
    }

    public function testAnAbsentTableIsEmptyNotAFailure(): void
    {
        $source = DatabaseTemplates::newsletterTemplates($this->store(new FakeDatabase(tables: [])));

        self::assertTrue($source->isAvailable());
        self::assertSame([], iterator_to_array($source->subjects(), false));
    }

    public function testAReadThatFailsPartwayIsAFailure(): void
    {
        $source = DatabaseTemplates::emailTemplates($this->store(new FakeDatabase(failReads: true)));

        self::assertTrue($source->isAvailable());
        $this->expectException(SourceUnavailable::class);
        $this->expectExceptionMessage('email_template could not be read');
        iterator_to_array($source->subjects());
    }

    public function testAReadableDatabaseYieldsItsTemplates(): void
    {
        $source = DatabaseTemplates::emailTemplates($this->store(new FakeDatabase()));

        self::assertCount(1, iterator_to_array($source->subjects(), false));
    }

    public function testCheckInAStoreWhoseDatabaseIsDownNamesTheSkipAndExitsThree(): void
    {
        $tester = $this->check($this->store(new FakeDatabase(down: true), $this->codebase()));

        $exit = $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(CheckCommand::SOURCE_UNAVAILABLE, $exit);
        self::assertStringContainsString('skipped database email templates', $tester->getErrorOutput());
        self::assertStringContainsString('skipped CMS pages', $tester->getErrorOutput());
        self::assertStringContainsString('not checked:', $tester->getDisplay());
    }

    public function testCheckJsonListsTheSkippedSourcesAndStaysParseable(): void
    {
        $tester = $this->check($this->store(new FakeDatabase(down: true), $this->codebase()));

        $exit = $tester->execute(['--format' => 'json'], ['capture_stderr_separately' => true]);

        self::assertSame(CheckCommand::SOURCE_UNAVAILABLE, $exit);
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertContains('database email templates', array_column($report['skipped'], 'source'));
    }

    /** Outside a store nothing is readable: still exit 2, but name what was skipped without -v. */
    public function testCheckWithoutAStoreNamesWhatItSkippedWithoutVerbose(): void
    {
        $tester = $this->check(MagentoContext::unavailable('no store in tests'));

        $exit = $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(CheckCommand::NOTHING_CHECKED, $exit);
        self::assertStringContainsString('skipped codebase email templates: no Magento root was found', $tester->getErrorOutput());
        self::assertStringContainsString('skipped database email templates: no store: no store in tests', $tester->getErrorOutput());
    }

    public function testASourceAskedForByNameThatCannotBeReadFails(): void
    {
        $tester = $this->check($this->store(new FakeDatabase(down: true), $this->codebase()));

        $exit = $tester->execute(['--source' => ['codebase', 'email']], ['capture_stderr_separately' => true]);

        self::assertSame(CheckCommand::SOURCE_UNAVAILABLE, $exit);
    }

    public function testAnErrorStillWinsOverASkip(): void
    {
        $tester = $this->check($this->store(new FakeDatabase(down: true), $this->codebase('{{if a}}unclosed')));

        self::assertSame(1, $tester->execute(['--posture' => 'strict'], ['capture_stderr_separately' => true]));
    }

    private function check(MagentoContext $context): CommandTester
    {
        $application = Application::create();
        $application->setAutoExit(false);
        $command = $application->find('check');
        $command->setMagentoContext($context);

        return new CommandTester($command);
    }

    private function store(FakeDatabase $database, ?string $root = null): MagentoContext
    {
        return MagentoContext::fromObjectManager(new class ($database) {
            public function __construct(private readonly FakeDatabase $database)
            {
            }

            public function get(string $class): ?object
            {
                return $class === \Magento\Framework\App\ResourceConnection::class ? $this->database : null;
            }
        }, $root ?? $this->root);
    }

    private function codebase(string $template = 'Hello {{var name}}'): string
    {
        $this->root = sys_get_temp_dir() . '/tp-src-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/app/code/Acme/Mail/view/frontend/email', 0777, true);
        file_put_contents($this->root . '/app/code/Acme/Mail/view/frontend/email/hello.html', $template);

        return $this->root;
    }
}

/** Duck-types the parts of ResourceConnection and its adapter that DatabaseTemplates uses. */
final class FakeDatabase
{
    /** @param string[] $tables */
    public function __construct(
        private readonly bool $down = false,
        private readonly bool $failReads = false,
        private readonly array $tables = ['email_template', 'cms_block', 'cms_page', 'newsletter_template'],
    ) {
    }

    public function getTableName(string $table): string
    {
        return $table;
    }

    public function getConnection(): object
    {
        return new class ($this->down, $this->failReads, $this->tables) {
            /** @param string[] $tables */
            public function __construct(private bool $down, private bool $failReads, private array $tables)
            {
            }

            public function fetchOne(string $sql): string
            {
                if ($this->down) {
                    throw new \RuntimeException("SQLSTATE[HY000] [2002] Connection refused\nmore detail");
                }

                return '1';
            }

            public function isTableExists(string $table): bool
            {
                return in_array($table, $this->tables, true);
            }

            public function select(): object
            {
                return new class {
                    public function from(string $table, array $columns): self
                    {
                        return $this;
                    }
                };
            }

            /** @return list<array<string,string>> */
            public function fetchAll(object $select): array
            {
                if ($this->failReads) {
                    throw new \RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
                }

                return [['template_id' => '1', 'template_code' => 'hello', 'template_text' => 'Hi {{var name}}']];
            }
        };
    }
}
