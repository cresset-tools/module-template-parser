<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Shadow;

use Cresset\TemplateParser\Console\MagentoContext;

/**
 * Read and clear access to what Shadow mode recorded, and to the store and config tables the
 * commands put it in context with.
 *
 * Plain SQL through the connection Magento already has, rather than collections or
 * repositories, for two reasons. The report reads one small table - bounded by store views x
 * templates, see Shadow\ShadowRecorder - and aggregates it in PHP, which is simpler than
 * teaching a collection to do it. And the connection is duck-typed (`fetchAll`, `query`,
 * `isTableExists`), so the tests run these exact statements against SQLite instead of
 * asserting on strings.
 *
 * The statements are therefore written in the SQL both dialects share: no GREATEST, no
 * backtick-only syntax, LIKE with an explicit ESCAPE.
 */
class ShadowTable
{
    public const TABLE = 'cresset_template_shadow';

    /** @var \Closure(string):string */
    private \Closure $tableName;

    /**
     * @param object $connection Magento's DB adapter, or anything with its fetchAll/query/isTableExists
     * @param ?\Closure(string):string $tableName applies the install's table prefix
     */
    public function __construct(private readonly object $connection, ?\Closure $tableName = null)
    {
        $this->tableName = $tableName ?? static fn (string $name): string => $name;
    }

    /** Null when there is no store to read from; the commands say why from the context. */
    public static function fromMagento(MagentoContext $magento): ?self
    {
        $resource = $magento->get(\Magento\Framework\App\ResourceConnection::class);
        if ($resource === null) {
            return null;
        }

        try {
            $connection = $resource->getConnection();
        } catch (\Throwable) {
            return null;
        }

        return is_object($connection)
            ? new self($connection, static fn (string $name): string => (string)$resource->getTableName($name))
            : null;
    }

    /** False until setup:upgrade has run with this module enabled. */
    public function exists(): bool
    {
        try {
            return (bool)$this->connection->isTableExists($this->table(self::TABLE));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Recorded rows, optionally for one store view and templates matching a pattern.
     *
     * @return list<array<string,mixed>>
     */
    public function rows(?int $storeId = null, ?string $template = null): array
    {
        [$where, $bind] = $this->filter($storeId, $template);

        return array_values($this->connection->fetchAll(
            sprintf('SELECT * FROM %s%s ORDER BY store_id, template', $this->table(self::TABLE), $where),
            $bind
        ));
    }

    /** @return int how many rows were removed */
    public function clear(?int $storeId = null, ?string $template = null): int
    {
        [$where, $bind] = $this->filter($storeId, $template);

        $statement = $this->connection->query(sprintf('DELETE FROM %s%s', $this->table(self::TABLE), $where), $bind);

        return is_object($statement) && method_exists($statement, 'rowCount') ? (int)$statement->rowCount() : 0;
    }

    /**
     * Every store view except admin, keyed by id.
     *
     * @return array<int,array{code:string,name:string,website_id:int}>
     */
    public function stores(): array
    {
        $stores = [];
        foreach ($this->connection->fetchAll(sprintf(
            'SELECT store_id, code, name, website_id FROM %s WHERE store_id <> 0 ORDER BY website_id, sort_order, store_id',
            $this->table('store')
        )) as $row) {
            $stores[(int)$row['store_id']] = [
                'code' => (string)$row['code'],
                'name' => (string)$row['name'],
                'website_id' => (int)$row['website_id'],
            ];
        }

        return $stores;
    }

    /**
     * Every website except admin, keyed by id.
     *
     * @return array<int,array{code:string,name:string}>
     */
    public function websites(): array
    {
        $websites = [];
        foreach ($this->connection->fetchAll(sprintf(
            'SELECT website_id, code, name FROM %s WHERE website_id <> 0 ORDER BY sort_order, website_id',
            $this->table('store_website')
        )) as $row) {
            $websites[(int)$row['website_id']] = ['code' => (string)$row['code'], 'name' => (string)$row['name']];
        }

        return $websites;
    }

    /**
     * The saved values of one config path, at every scope.
     *
     * @return list<array{scope:string,scope_id:int,value:?string,updated_at:?string}>
     */
    public function configValues(string $path): array
    {
        $rows = [];
        foreach ($this->connection->fetchAll(
            sprintf('SELECT scope, scope_id, value, updated_at FROM %s WHERE path = ?', $this->table('core_config_data')),
            [$path]
        ) as $row) {
            $rows[] = [
                'scope' => (string)$row['scope'],
                'scope_id' => (int)$row['scope_id'],
                'value' => $row['value'] !== null ? (string)$row['value'] : null,
                'updated_at' => isset($row['updated_at']) ? (string)$row['updated_at'] : null,
            ];
        }

        return $rows;
    }

    /**
     * `*` in a template pattern matches anything, so `cms_block:*` selects every block. The
     * LIKE metacharacters are escaped first: a template id can contain `_`, and an unescaped
     * one would match any character.
     *
     * @return array{0:string,1:list<mixed>}
     */
    private function filter(?int $storeId, ?string $template): array
    {
        $clauses = [];
        $bind = [];

        if ($storeId !== null) {
            $clauses[] = 'store_id = ?';
            $bind[] = $storeId;
        }

        if ($template !== null && $template !== '') {
            if (str_contains($template, '*')) {
                $clauses[] = "template LIKE ? ESCAPE '!'";
                $bind[] = str_replace('*', '%', strtr($template, ['!' => '!!', '%' => '!%', '_' => '!_']));
            } else {
                $clauses[] = 'template = ?';
                $bind[] = $template;
            }
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $bind];
    }

    private function table(string $name): string
    {
        return ($this->tableName)($name);
    }
}
