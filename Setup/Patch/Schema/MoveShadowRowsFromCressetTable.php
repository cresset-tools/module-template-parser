<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Setup\Patch\Schema;

use MageOS\TemplateParser\Magento\Shadow\ShadowRecorder;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Carries Shadow's history over from the module's earlier name.
 *
 * Up to 0.3.0 this module was Cresset_TemplateParser and kept its rows in
 * `cresset_template_shadow`. A store that ran Shadow under that name has evidence it should
 * not lose by upgrading: "clean since", the renders since the last divergence, the last
 * divergence itself. The columns did not change, so the rows copy across as they are.
 *
 * Not `onCreate="migrateDataFromAnotherTable(...)"` in db_schema.xml: Magento's trigger runs
 * an INSERT ... SELECT without checking the old table exists, so every fresh install - which
 * has no old table - would fail setup:upgrade. And a schema patch rather than a data patch:
 * data patches run inside a transaction, where the adapter refuses the DROP TABLE ("DDL
 * statements are not allowed in transactions") - which is how the first attempt failed on a
 * real store. Schema patches run after the declarative schema, so the new table exists.
 *
 * A row already in the new table wins over the old one for the same store view and template,
 * because it can only have been written after the rename. The old table is dropped once its
 * rows are copied: the module that maintained it is gone, so nothing would ever clean it up.
 */
class MoveShadowRowsFromCressetTable implements SchemaPatchInterface
{
    public const OLD_TABLE = 'cresset_template_shadow';

    /** Every column but the id, as 0.3.0 created them. */
    private const COLUMNS = [
        'store_id', 'template', 'agreed', 'diverged', 'refused', 'crashed', 'served', 'fell_back',
        'first_seen', 'last_seen', 'last_divergence_at', 'renders_since_divergence',
        'last_divergence', 'last_refusal', 'last_crash',
    ];

    public function __construct(private readonly SchemaSetupInterface $setup)
    {
    }

    public function apply(): static
    {
        $connection = $this->setup->getConnection();
        $old = $this->setup->getTable(self::OLD_TABLE);
        if (!$connection->isTableExists($old)) {
            return $this;
        }
        $new = $this->setup->getTable(ShadowRecorder::TABLE);

        $columns = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', self::COLUMNS));
        $copied = implode(', ', array_map(static fn (string $c): string => 'o.`' . $c . '`', self::COLUMNS));

        // Portable SQL rather than INSERT IGNORE, so the test suite can run it on SQLite.
        $connection->query(sprintf(
            'INSERT INTO `%1$s` (%2$s) SELECT %3$s FROM `%4$s` o WHERE NOT EXISTS '
                . '(SELECT 1 FROM `%1$s` n WHERE n.`store_id` = o.`store_id` AND n.`template` = o.`template`)',
            $new,
            $columns,
            $copied,
            $old
        ));
        $connection->query(sprintf('DROP TABLE `%s`', $old));

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
