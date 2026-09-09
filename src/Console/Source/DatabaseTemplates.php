<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Source;

use Cresset\TemplateParser\Console\MagentoContext;
use Cresset\TemplateParser\Console\TemplateSubject;

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
        private readonly ?string $storeColumn = null,
        private readonly string $kind = TemplateSubject::KIND_EMAIL,
    ) {
    }

    public static function emailTemplates(MagentoContext $magento): self
    {
        return new self($magento, 'database email templates', 'email_template', 'template_id', 'template_code', 'template_text');
    }

    public static function cmsBlocks(MagentoContext $magento): self
    {
        return new self($magento, 'CMS blocks', 'cms_block', 'block_id', 'identifier', 'content', null, TemplateSubject::KIND_CMS);
    }

    public static function cmsPages(MagentoContext $magento): self
    {
        return new self($magento, 'CMS pages', 'cms_page', 'page_id', 'identifier', 'content', null, TemplateSubject::KIND_CMS);
    }

    public static function newsletterTemplates(MagentoContext $magento): self
    {
        return new self($magento, 'newsletter templates', 'newsletter_template', 'template_id', 'template_code', 'template_text', null, TemplateSubject::KIND_NEWSLETTER);
    }

    public function name(): string
    {
        return $this->label;
    }

    public function isAvailable(): bool
    {
        return $this->connection() !== null;
    }

    public function subjects(): iterable
    {
        $connection = $this->connection();
        if ($connection === null) {
            return;
        }

        try {
            $table = $connection->getTableName($this->table);
            $select = $connection->getConnection()->select()->from(
                $table,
                [$this->idColumn, $this->nameColumn, $this->bodyColumn]
            );
            $rows = $connection->getConnection()->fetchAll($select);
        } catch (\Throwable) {
            return;                      // table absent - the module is not installed
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

    private function connection(): ?object
    {
        return $this->magento->get(\Magento\Framework\App\ResourceConnection::class);
    }
}
