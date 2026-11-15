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
 * A row can already be in the new table for the same store view and template: setup:upgrade
 * creates the table and enables the module before it applies patches, without entering
 * maintenance mode, and cron never waits for it - so a store keeps recording in between. Such
 * a row is MERGED with the old one, the way ShadowRecorder merges a flush into a row: counters
 * add up, the earliest first_seen and latest last_seen stand, and the newer of each "last"
 * record wins while an older one survives where the new row has none. Keeping only the new row
 * threw away the old history for the busiest templates - a divergence included, which turned a
 * failing rollout gate clean.
 *
 * Refusals and crashes record the class that raised them, which 0.3.0 wrote under the
 * `Cresset\TemplateParser\` namespace; those names are rewritten so the report recognises them.
 *
 * The old table is dropped once its rows are moved: the module that maintained it is gone, so
 * nothing would ever clean it up.
 */
class MoveShadowRowsFromCressetTable implements SchemaPatchInterface
{
    public const OLD_TABLE = 'cresset_template_shadow';

    private const OLD_NAMESPACE = 'Cresset\\TemplateParser\\';
    private const NEW_NAMESPACE = 'MageOS\\TemplateParser\\';

    private const COUNTERS = ['agreed', 'diverged', 'refused', 'crashed', 'served', 'fell_back'];

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

        // Portable SQL rather than INSERT ... ON DUPLICATE KEY, so the test suite can run it on
        // SQLite: merge the rows both tables have, then copy the ones only the old table has.
        $connection->query($this->merge($new, $old));

        $columns = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', self::COLUMNS));
        $copied = implode(', ', array_map(static fn (string $c): string => 'o.`' . $c . '`', self::COLUMNS));
        $connection->query(sprintf(
            'INSERT INTO `%1$s` (%2$s) SELECT %3$s FROM `%4$s` o WHERE NOT EXISTS '
                . '(SELECT 1 FROM `%1$s` n WHERE n.`store_id` = o.`store_id` AND n.`template` = o.`template`)',
            $new,
            $columns,
            $copied,
            $old
        ));
        $connection->query(sprintf('DROP TABLE `%s`', $old));
        $this->renameErrorClasses($connection, $new);

        return $this;
    }

    /**
     * One UPDATE over the rows both tables hold, each column from a correlated subquery.
     *
     * MySQL applies a single-table UPDATE's assignments left to right, each seeing the ones
     * before it; SQLite sees the original row throughout. renders_since_divergence reads
     * last_divergence_at, so it is assigned first, where both read the new row's own value.
     */
    private function merge(string $new, string $old): string
    {
        $o = static fn (string $column): string => sprintf(
            '(SELECT o.`%1$s` FROM `%2$s` o WHERE o.`store_id` = `%3$s`.`store_id` AND o.`template` = `%3$s`.`template`)',
            $column,
            $old,
            $new
        );

        $set = [
            // Renders since the new row's own divergence, or since the old row's, if the new
            // row has none: then the old count runs on into the new one.
            '`renders_since_divergence` = CASE WHEN `last_divergence_at` IS NULL'
                . ' THEN `renders_since_divergence` + ' . $o('renders_since_divergence')
                . ' ELSE `renders_since_divergence` END',
        ];
        foreach (self::COUNTERS as $counter) {
            $set[] = sprintf('`%1$s` = `%1$s` + %2$s', $counter, $o($counter));
        }
        $set[] = sprintf('`first_seen` = CASE WHEN %1$s < `first_seen` THEN %1$s ELSE `first_seen` END', $o('first_seen'));
        $set[] = sprintf('`last_seen` = CASE WHEN %1$s > `last_seen` THEN %1$s ELSE `last_seen` END', $o('last_seen'));
        foreach (['last_divergence_at', 'last_divergence', 'last_refusal', 'last_crash'] as $last) {
            $set[] = sprintf('`%1$s` = COALESCE(`%1$s`, %2$s)', $last, $o($last));
        }

        return sprintf(
            'UPDATE `%1$s` SET %2$s WHERE EXISTS (SELECT 1 FROM `%3$s` o WHERE o.`store_id` = `%1$s`.`store_id` AND o.`template` = `%1$s`.`template`)',
            $new,
            implode(', ', $set),
            $old
        );
    }

    /** `Cresset\TemplateParser\SyntaxError` in a recorded refusal or crash, as the class it now is. */
    private function renameErrorClasses(object $connection, string $new): void
    {
        $rows = $connection->fetchAll(sprintf(
            "SELECT `store_id`, `template`, `last_refusal`, `last_crash` FROM `%s`"
                . " WHERE `last_refusal` LIKE '%%Cresset%%' OR `last_crash` LIKE '%%Cresset%%'",
            $new
        ));
        foreach ($rows as $row) {
            $connection->query(
                sprintf('UPDATE `%s` SET `last_refusal` = ?, `last_crash` = ? WHERE `store_id` = ? AND `template` = ?', $new),
                [
                    self::renamed($row['last_refusal']),
                    self::renamed($row['last_crash']),
                    $row['store_id'],
                    $row['template'],
                ]
            );
        }
    }

    private static function renamed(?string $json): ?string
    {
        $record = $json === null ? null : json_decode($json, true);
        if (!is_array($record) || !is_string($record['error'] ?? null)
            || !str_starts_with($record['error'], self::OLD_NAMESPACE)
        ) {
            return $json;
        }
        $record['error'] = self::NEW_NAMESPACE . substr($record['error'], strlen(self::OLD_NAMESPACE));

        return (string)json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
