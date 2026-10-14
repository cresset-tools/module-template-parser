<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Setup\Patch\Schema\MoveShadowRowsFromCressetTable;
use Magento\Framework\Setup\SchemaSetupInterface;
use PHPUnit\Framework\TestCase;

/**
 * Upgrading from Cresset_TemplateParser keeps what Shadow recorded under the old name.
 *
 * The rows are a store's evidence for moving a store view to Parser, so an upgrade that
 * silently started them over would reset every "clean since" to the day of the upgrade.
 */
final class RenameMigrationTest extends TestCase
{
    private SqliteConnection $db;

    protected function setUp(): void
    {
        $this->db = (new SqliteConnection())->createShadowTable();
    }

    private function patch(): MoveShadowRowsFromCressetTable
    {
        $db = $this->db;
        $setup = new class ($db) implements SchemaSetupInterface {
            public function __construct(private readonly SqliteConnection $db)
            {
            }

            public function getConnection()
            {
                return $this->db;
            }

            public function getTable($tableName)
            {
                return $tableName;
            }
        };

        return new MoveShadowRowsFromCressetTable($setup);
    }

    public function testAFreshInstallHasNothingToMoveAndDoesNotFail(): void
    {
        $this->patch()->apply();

        self::assertSame([], $this->db->fetchAll('SELECT * FROM mageos_template_parser_shadow'));
    }

    /**
     * The table as 0.3.0 created it - written out, not built from today's definition, so a
     * change to the new table's columns cannot quietly pass as "the old one".
     */
    private function oldTable(): SqliteConnection
    {
        $this->db->query(
            'CREATE TABLE cresset_template_shadow (
                entity_id INTEGER PRIMARY KEY AUTOINCREMENT,
                store_id INTEGER NOT NULL DEFAULT 0,
                template TEXT NOT NULL,
                agreed INTEGER NOT NULL DEFAULT 0,
                diverged INTEGER NOT NULL DEFAULT 0,
                refused INTEGER NOT NULL DEFAULT 0,
                crashed INTEGER NOT NULL DEFAULT 0,
                served INTEGER NOT NULL DEFAULT 0,
                fell_back INTEGER NOT NULL DEFAULT 0,
                first_seen TEXT NOT NULL,
                last_seen TEXT NOT NULL,
                last_divergence_at TEXT NULL,
                renders_since_divergence INTEGER NOT NULL DEFAULT 0,
                last_divergence TEXT NULL,
                last_refusal TEXT NULL,
                last_crash TEXT NULL,
                UNIQUE (store_id, template)
            )'
        );

        return $this->db;
    }

    /** @return array<string,mixed> the row, without its id */
    private function row(int $storeId, string $template): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM mageos_template_parser_shadow WHERE store_id = ? AND template = ?',
            [$storeId, $template]
        );
        self::assertCount(1, $rows);
        unset($rows[0]['entity_id']);

        return $rows[0];
    }

    public function testRowsUnderTheOldNameAreCopiedAsTheyWereAndTheOldTableDropped(): void
    {
        $old = [
            'store_id' => 1, 'template' => 'email:sales_email_order_template',
            'agreed' => 990, 'diverged' => 2, 'refused' => 3, 'crashed' => 1, 'served' => 40, 'fell_back' => 4,
            'first_seen' => '2026-09-14 07:00:00', 'last_seen' => '2026-10-07 10:00:00',
            'last_divergence_at' => '2026-10-07 09:06:25', 'renders_since_divergence' => 988,
            'last_divergence' => '{"first_difference_at":12}',
            'last_refusal' => '{"error":"policy","problem":"directive \"block\" is not allowed"}',
            'last_crash' => '{"error":"RuntimeException","message":"boom"}',
        ];
        $this->oldTable()
            ->insertShadow($old, MoveShadowRowsFromCressetTable::OLD_TABLE)
            ->insertShadow(['store_id' => 2, 'template' => 'cms_page:1', 'agreed' => 7], MoveShadowRowsFromCressetTable::OLD_TABLE);

        $this->patch()->apply();

        self::assertEquals($old, $this->row(1, 'email:sales_email_order_template'), 'every column, as it was');
        self::assertSame(7, (int)$this->row(2, 'cms_page:1')['agreed']);
        self::assertFalse($this->db->isTableExists(MoveShadowRowsFromCressetTable::OLD_TABLE));
    }

    /**
     * setup:upgrade creates the new table and enables the module before it applies patches,
     * and nothing stops the store recording in between. Keeping only the new row turned a
     * diverged template's 1200 comparisons into 3, and the failing gate clean.
     */
    public function testARowAlsoWrittenAfterTheRenameIsMergedNotReplaced(): void
    {
        $this->oldTable()->insertShadow([
            'store_id' => 1, 'template' => 'cms_block:5', 'agreed' => 1200, 'diverged' => 1, 'served' => 120000,
            'first_seen' => '2026-09-14 07:00:00', 'last_seen' => '2026-10-13 23:59:00',
            'last_divergence_at' => '2026-10-05 08:00:00', 'renders_since_divergence' => 600,
            'last_divergence' => ['first_difference_at' => 4],
        ], MoveShadowRowsFromCressetTable::OLD_TABLE);
        $this->db->insertShadow([
            'store_id' => 1, 'template' => 'cms_block:5', 'agreed' => 3, 'served' => 3,
            'first_seen' => '2026-10-14 06:00:00', 'last_seen' => '2026-10-14 06:01:00',
            'renders_since_divergence' => 3,
        ]);

        $this->patch()->apply();

        $row = $this->row(1, 'cms_block:5');
        self::assertSame([1203, 1, 120003], [(int)$row['agreed'], (int)$row['diverged'], (int)$row['served']]);
        self::assertSame('2026-09-14 07:00:00', $row['first_seen'], 'the earliest first_seen');
        self::assertSame('2026-10-14 06:01:00', $row['last_seen'], 'the latest last_seen');
        self::assertSame('2026-10-05 08:00:00', $row['last_divergence_at'], 'the old divergence survives');
        self::assertSame(['first_difference_at' => 4], json_decode((string)$row['last_divergence'], true));
        self::assertSame(603, (int)$row['renders_since_divergence'], 'the old run of renders continues');
    }

    /** A divergence after the rename is the latest one: the count restarts there, as the recorder's does. */
    public function testANewerDivergenceWinsAndRestartsTheCount(): void
    {
        $this->oldTable()->insertShadow([
            'store_id' => 1, 'template' => 'email:12', 'agreed' => 50, 'diverged' => 1,
            'last_divergence_at' => '2026-10-01 08:00:00', 'renders_since_divergence' => 40,
            'last_divergence' => ['first_difference_at' => 1],
        ], MoveShadowRowsFromCressetTable::OLD_TABLE);
        $this->db->insertShadow([
            'store_id' => 1, 'template' => 'email:12', 'agreed' => 2, 'diverged' => 1,
            'first_seen' => '2026-10-14 06:00:00', 'last_seen' => '2026-10-14 06:05:00',
            'last_divergence_at' => '2026-10-14 06:02:00', 'renders_since_divergence' => 1,
            'last_divergence' => ['first_difference_at' => 9],
        ]);

        $this->patch()->apply();

        $row = $this->row(1, 'email:12');
        self::assertSame([52, 2], [(int)$row['agreed'], (int)$row['diverged']]);
        self::assertSame('2026-10-14 06:02:00', $row['last_divergence_at']);
        self::assertSame(['first_difference_at' => 9], json_decode((string)$row['last_divergence'], true));
        self::assertSame(1, (int)$row['renders_since_divergence']);
    }

    /** 0.3.0 recorded the class that raised, under its namespace; the report keys its advice on it. */
    public function testRecordedErrorClassesAreRenamed(): void
    {
        $this->oldTable()->insertShadow([
            'store_id' => 1, 'template' => 'email:3', 'refused' => 1, 'crashed' => 1,
            'last_refusal' => ['error' => 'Cresset\\TemplateParser\\SyntaxError', 'problem' => 'unclosed', 'line' => 1, 'column' => 3],
            'last_crash' => ['error' => 'Cresset\\TemplateParser\\TemplateError', 'message' => 'x'],
        ], MoveShadowRowsFromCressetTable::OLD_TABLE)->insertShadow([
            'store_id' => 2, 'template' => 'email:3', 'refused' => 1,
            'last_refusal' => ['error' => 'policy', 'problem' => 'Cresset is a word here'],
        ], MoveShadowRowsFromCressetTable::OLD_TABLE);

        $this->patch()->apply();

        $row = $this->row(1, 'email:3');
        self::assertSame('MageOS\\TemplateParser\\SyntaxError', json_decode((string)$row['last_refusal'], true)['error']);
        self::assertSame(['problem' => 'unclosed', 'line' => 1, 'column' => 3], array_diff_key(json_decode((string)$row['last_refusal'], true), ['error' => 1]));
        self::assertSame('MageOS\\TemplateParser\\TemplateError', json_decode((string)$row['last_crash'], true)['error']);
        self::assertSame(['error' => 'policy', 'problem' => 'Cresset is a word here'], json_decode((string)$this->row(2, 'email:3')['last_refusal'], true));
    }
}
