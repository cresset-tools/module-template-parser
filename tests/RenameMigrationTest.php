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

    public function testRowsUnderTheOldNameAreCopiedAsTheyWereAndTheOldTableDropped(): void
    {
        $this->db->createShadowTable(MoveShadowRowsFromCressetTable::OLD_TABLE)
            ->insertShadow([
                'store_id' => 1, 'template' => 'email:sales_email_order_template', 'agreed' => 990,
                'diverged' => 2, 'last_divergence_at' => '2026-10-07 09:06:25',
                'renders_since_divergence' => 988, 'last_divergence' => ['first_difference_at' => 12],
            ], MoveShadowRowsFromCressetTable::OLD_TABLE)
            ->insertShadow(['store_id' => 2, 'template' => 'cms_page:1', 'agreed' => 7], MoveShadowRowsFromCressetTable::OLD_TABLE);

        $this->patch()->apply();

        $rows = $this->db->fetchAll('SELECT * FROM mageos_template_parser_shadow ORDER BY store_id');
        self::assertCount(2, $rows);
        self::assertSame('email:sales_email_order_template', $rows[0]['template']);
        self::assertSame(990, (int)$rows[0]['agreed']);
        self::assertSame(2, (int)$rows[0]['diverged']);
        self::assertSame(988, (int)$rows[0]['renders_since_divergence']);
        self::assertSame('2026-10-07 09:06:25', $rows[0]['last_divergence_at']);
        self::assertSame(['first_difference_at' => 12], json_decode((string)$rows[0]['last_divergence'], true));
        self::assertFalse($this->db->isTableExists(MoveShadowRowsFromCressetTable::OLD_TABLE));
    }

    public function testARowWrittenAfterTheRenameWinsOverTheOldOne(): void
    {
        $this->db->createShadowTable(MoveShadowRowsFromCressetTable::OLD_TABLE)
            ->insertShadow(['store_id' => 1, 'template' => 'cms_page:1', 'agreed' => 5], MoveShadowRowsFromCressetTable::OLD_TABLE)
            ->insertShadow(['store_id' => 1, 'template' => 'cms_page:1', 'agreed' => 40]);

        $this->patch()->apply();

        $rows = $this->db->fetchAll('SELECT agreed FROM mageos_template_parser_shadow');
        self::assertSame([40], array_map(static fn (array $r): int => (int)$r['agreed'], $rows));
    }
}
