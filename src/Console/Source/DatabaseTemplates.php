<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Console\Source;

use MageOS\TemplateParser\Console\MagentoContext;
use MageOS\TemplateParser\Console\TemplateSubject;

/**
 * Templates a merchant edited, read straight from their tables.
 *
 * This is the set that decides whether a migration is safe, and the set nobody can audit
 * ahead of time - it is whatever was typed into the admin over the years. One class for all
 * four tables because they differ only in which columns hold the identifier and the body;
 * four near-identical classes would be four places to fix a bug.
 *
 * Read-only by construction: a SELECT, and nothing on this class can write.
 */
class DatabaseTemplates implements TemplateSource
{
    private function __construct(
        private readonly MagentoContext $magento,
        private readonly string $label,
        private readonly string $table,
        private readonly string $idColumn,
        private readonly string $nameColumn,
        private readonly string $bodyColumn,
        private readonly string $kind = TemplateSubject::KIND_EMAIL,
    ) {
    }

    /** The probe's answer, so a run asks the database once per source rather than per call. */
    private ?string $unavailable = null;

    private bool $probed = false;

    public static function emailTemplates(MagentoContext $magento): self
    {
        return new self($magento, 'database email templates', 'email_template', 'template_id', 'template_code', 'template_text');
    }

    public static function cmsBlocks(MagentoContext $magento): self
    {
        return new self($magento, 'CMS blocks', 'cms_block', 'block_id', 'identifier', 'content', TemplateSubject::KIND_CMS);
    }

    public static function cmsPages(MagentoContext $magento): self
    {
        return new self($magento, 'CMS pages', 'cms_page', 'page_id', 'identifier', 'content', TemplateSubject::KIND_CMS);
    }

    public static function newsletterTemplates(MagentoContext $magento): self
    {
        return new self($magento, 'newsletter templates', 'newsletter_template', 'template_id', 'template_code', 'template_text', TemplateSubject::KIND_NEWSLETTER);
    }

    public function name(): string
    {
        return $this->label;
    }

    public function isAvailable(): bool
    {
        return $this->unavailableReason() === null;
    }

    /**
     * Asks the database, not just the object manager.
     *
     * ResourceConnection builds without a database - Magento connects on first query - so
     * "the service resolved" said nothing. With the database down every table read failed,
     * the failure was taken for a missing table, and the source counted as empty.
     */
    public function unavailableReason(): ?string
    {
        if ($this->probed) {
            return $this->unavailable;
        }
        $this->probed = true;

        if (!$this->magento->isAvailable()) {
            return $this->unavailable = 'no store: ' . ($this->magento->reason() ?? 'Magento could not be booted');
        }
        $connection = $this->connection();
        if ($connection === null) {
            return $this->unavailable = "the store's database connection could not be built";
        }
        try {
            $connection->getConnection()->fetchOne('SELECT 1');
        } catch (\Throwable $e) {
            return $this->unavailable = 'the database could not be reached: ' . self::firstLine($e->getMessage());
        }

        return null;
    }

    public function subjects(): iterable
    {
        $reason = $this->unavailableReason();
        if ($reason !== null) {
            throw new SourceUnavailable($reason);
        }
        $connection = $this->connection();

        try {
            $table = $connection->getTableName($this->table);
            if (!$connection->getConnection()->isTableExists($table)) {
                return;                  // the module that owns the table is not installed
            }
            $select = $connection->getConnection()->select()->from(
                $table,
                [$this->idColumn, $this->nameColumn, $this->bodyColumn]
            );
            $rows = $connection->getConnection()->fetchAll($select);
        } catch (\Throwable $e) {
            throw new SourceUnavailable(sprintf('%s could not be read: %s', $this->table, self::firstLine($e->getMessage())), 0, $e);
        }

        foreach ($rows as $row) {
            $body = (string)($row[$this->bodyColumn] ?? '');
            if (trim($body) === '') {
                continue;
            }

            yield new TemplateSubject(
                id: sprintf('%s:%s', $this->table, $row[$this->idColumn] ?? '?'),
                label: sprintf('%s (%s #%s)', $row[$this->nameColumn] ?? '(unnamed)', $this->table, $row[$this->idColumn] ?? '?'),
                origin: 'database',
                content: $body,
                kind: $this->kind,
            );
        }
    }

    private static function firstLine(string $message): string
    {
        $line = trim(strtok($message, "\n") ?: $message);

        return mb_strlen($line) > 200 ? mb_substr($line, 0, 200) . '…' : $line;
    }

    private function connection(): ?object
    {
        return $this->magento->get(\Magento\Framework\App\ResourceConnection::class);
    }
}
