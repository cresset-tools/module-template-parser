<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

/**
 * The three methods of Magento's DB adapter the shadow commands use, over an in-memory SQLite.
 *
 * So the tests run the commands' actual statements rather than asserting on their text. The
 * schema below is the columns those statements touch, not Magento's full tables.
 */
final class SqliteConnection
{
    public readonly \PDO $pdo;

    public function __construct()
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE store_website (website_id INTEGER PRIMARY KEY, code TEXT, name TEXT, sort_order INTEGER DEFAULT 0);
CREATE TABLE store (store_id INTEGER PRIMARY KEY, code TEXT, name TEXT, website_id INTEGER, sort_order INTEGER DEFAULT 0);
CREATE TABLE core_config_data (config_id INTEGER PRIMARY KEY, scope TEXT, scope_id INTEGER, path TEXT, value TEXT, updated_at TEXT);
INSERT INTO store_website VALUES (0, 'admin', 'Admin', 0), (1, 'base', 'Main Website', 0), (2, 'b2b', 'Trade', 1);
INSERT INTO store VALUES (0, 'admin', 'Admin', 0, 0), (1, 'default', 'Default Store View', 1, 0), (2, 'nl', 'Dutch', 1, 1), (3, 'trade', 'Trade', 2, 0);
SQL);
    }

    public function createShadowTable(): self
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE cresset_template_shadow (
    entity_id INTEGER PRIMARY KEY,
    store_id INTEGER NOT NULL,
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
)
SQL);

        return $this;
    }

    /** @param array<string,mixed> $row */
    public function insertShadow(array $row): self
    {
        $row += [
            'agreed' => 0, 'diverged' => 0, 'refused' => 0, 'crashed' => 0,
            'first_seen' => '2026-09-01 08:00:00', 'last_seen' => '2026-09-29 12:00:00',
            'last_divergence_at' => null, 'renders_since_divergence' => 0,
            'last_divergence' => null, 'last_refusal' => null, 'last_crash' => null,
        ];
        foreach (['last_divergence', 'last_refusal', 'last_crash'] as $json) {
            if (is_array($row[$json])) {
                $row[$json] = json_encode($row[$json]);
            }
        }

        $this->query(
            sprintf(
                'INSERT INTO cresset_template_shadow (%s) VALUES (%s)',
                implode(', ', array_keys($row)),
                implode(', ', array_fill(0, count($row), '?'))
            ),
            array_values($row)
        );

        return $this;
    }

    public function setConfig(string $scope, int $scopeId, string $path, string $value, string $updatedAt): self
    {
        $this->query(
            'INSERT INTO core_config_data (scope, scope_id, path, value, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$scope, $scopeId, $path, $value, $updatedAt]
        );

        return $this;
    }

    /** @return list<array<string,mixed>> */
    public function fetchAll($sql, $bind = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_values((array)$bind));

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function query($sql, $bind = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_values((array)$bind));

        return $statement;
    }

    public function isTableExists($tableName, $schemaName = null): bool
    {
        return $this->fetchAll("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$tableName]) !== [];
    }
}
