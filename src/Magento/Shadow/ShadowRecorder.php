<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento\Shadow;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Records Shadow outcomes in `cresset_template_shadow`, one row per store view and template.
 *
 * One row, not one per outcome, because the question the rollout asks - "has this template
 * been clean since its last divergence, and for how many renders?" - is answered by resetting
 * a counter at the moment a divergence is counted, and that has to happen in the same row
 * update to be atomic. The row carries a counter per outcome, the first and last time the
 * template was compared, `last_divergence_at` and `renders_since_divergence`:
 *
 * - a divergence or a crash sets `last_divergence_at` and resets the counter to zero. A crash
 *   counts because Parser mode would have served nothing sensible for it either;
 * - an agreement or a refusal adds one. A refusal counts as clean because Parser mode falls
 *   back to legacy for it, so the customer gets exactly what they get today;
 * - "clean since" is `last_divergence_at`, or `first_seen` for a template that never diverged.
 *
 * Aggregated in memory and written in one INSERT ... ON DUPLICATE KEY UPDATE, so a page that
 * renders twenty CMS blocks costs one statement rather than twenty. Written at shutdown - a
 * shutdown function rather than a destructor, because destructors run during object teardown
 * in no defined order and the database connection can already be gone - and additionally
 * whenever the buffer holds FLUSH_AT_ROWS templates or FLUSH_AFTER_SECONDS have passed, so a
 * cron run or queue consumer that lives for hours reports as it goes and holds a bounded
 * buffer.
 *
 * Recording must never cost a render. Every failure is caught; the first is logged as a
 * warning and the rest are not, so a missing table does not write a line per page view. A
 * batch that fails to write is dropped rather than retried: holding it would grow without
 * bound against a database that is not coming back.
 */
class ShadowRecorder
{
    public const TABLE = 'cresset_template_shadow';

    public const FLUSH_AT_ROWS = 100;
    public const FLUSH_AFTER_SECONDS = 60;

    /** Matches the column; an identity longer than this is cut, never rejected. */
    private const TEMPLATE_LENGTH = 255;

    private const COLUMNS = [
        'store_id', 'template',
        'agreed', 'diverged', 'refused', 'crashed',
        'first_seen', 'last_seen',
        'last_divergence_at', 'renders_since_divergence',
        'last_divergence', 'last_refusal', 'last_crash',
    ];

    /** @var array<string,array<string,mixed>> "store\0template" => pending row */
    private array $pending = [];

    private ?int $lastFlush = null;

    private bool $shutdownRegistered = false;

    private bool $warned = false;

    /** @var \Closure():int */
    private \Closure $clock;

    /**
     * @param bool $flushOnShutdown off only in tests, which flush by hand
     * @param ?\Closure():int $clock the time, as a Unix timestamp; the system clock if null
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly bool $flushOnShutdown = true,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param int|string|null $storeId as the filter holds it; null means the current store
     */
    public function record(int|string|null $storeId, string $template, ShadowOutcome $outcome): void
    {
        try {
            $this->add($this->resolveStore($storeId), $template, $outcome);

            if (!$this->shutdownRegistered && $this->flushOnShutdown) {
                register_shutdown_function([$this, 'flush']);
                $this->shutdownRegistered = true;
            }

            $now = ($this->clock)();
            $this->lastFlush ??= $now;
            if (count($this->pending) >= self::FLUSH_AT_ROWS || $now - $this->lastFlush >= self::FLUSH_AFTER_SECONDS) {
                $this->flush();
            }
        } catch (\Throwable $e) {
            $this->warnOnce($e);
        }
    }

    /** Writes everything pending. Safe to call at any time, and more than once. */
    public function flush(): void
    {
        $rows = $this->pending;
        $this->pending = [];
        $this->lastFlush = ($this->clock)();

        if ($rows === []) {
            return;
        }

        try {
            [$sql, $bind] = $this->statement(array_values($rows));
            $this->resource->getConnection()->query($sql, $bind);
        } catch (\Throwable $e) {
            $this->warnOnce($e);
        }
    }

    /** Resolved when the render happens: "the current store" means nothing by shutdown. */
    private function resolveStore(int|string|null $storeId): int
    {
        if (is_int($storeId)) {
            return $storeId;
        }
        if (is_string($storeId) && ctype_digit($storeId)) {
            return (int)$storeId;
        }

        try {
            return (int)$this->storeManager->getStore($storeId)->getId();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function add(int $storeId, string $template, ShadowOutcome $outcome): void
    {
        $template = mb_strcut($template, 0, self::TEMPLATE_LENGTH);
        $now = gmdate('Y-m-d H:i:s', ($this->clock)());
        $key = $storeId . "\0" . $template;

        $this->pending[$key] ??= [
            'store_id' => $storeId,
            'template' => $template,
            'agreed' => 0,
            'diverged' => 0,
            'refused' => 0,
            'crashed' => 0,
            'first_seen' => $now,
            'last_seen' => $now,
            'last_divergence_at' => null,
            'renders_since_divergence' => 0,
            'last_divergence' => null,
            'last_refusal' => null,
            'last_crash' => null,
        ];
        $row = &$this->pending[$key];
        $row['last_seen'] = $now;

        switch ($outcome->outcome) {
            case ShadowOutcome::AGREE:
                $row['agreed']++;
                $row['renders_since_divergence']++;
                break;
            case ShadowOutcome::REFUSED:
                $row['refused']++;
                $row['renders_since_divergence']++;
                $row['last_refusal'] = self::json($outcome->detail);
                break;
            case ShadowOutcome::DIVERGE:
                $row['diverged']++;
                $row['renders_since_divergence'] = 0;
                $row['last_divergence_at'] = $now;
                $row['last_divergence'] = self::json($outcome->detail);
                break;
            case ShadowOutcome::CRASHED:
                $row['crashed']++;
                $row['renders_since_divergence'] = 0;
                $row['last_divergence_at'] = $now;
                $row['last_crash'] = self::json($outcome->detail);
                break;
        }
    }

    /**
     * The upsert, with the counter reset folded in.
     *
     * A batch row whose `last_divergence_at` is set diverged within the batch, so its
     * `renders_since_divergence` already counts only what came after, and replaces the stored
     * one. A batch row without one adds to it. Every other "last" column keeps the stored
     * value unless the batch has a newer one.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{0:string,1:list<mixed>}
     */
    private function statement(array $rows): array
    {
        $quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
        $table = $quote($this->resource->getTableName(self::TABLE));

        $placeholders = '(' . implode(', ', array_fill(0, count(self::COLUMNS), '?')) . ')';
        $bind = [];
        foreach ($rows as $row) {
            foreach (self::COLUMNS as $column) {
                $bind[] = $row[$column];
            }
        }

        $update = [];
        foreach (['agreed', 'diverged', 'refused', 'crashed'] as $counter) {
            $update[] = sprintf('%1$s = %1$s + VALUES(%1$s)', $quote($counter));
        }
        $update[] = sprintf(
            '%1$s = IF(VALUES(%2$s) IS NULL, %1$s + VALUES(%1$s), VALUES(%1$s))',
            $quote('renders_since_divergence'),
            $quote('last_divergence_at')
        );
        $update[] = sprintf('%1$s = GREATEST(%1$s, VALUES(%1$s))', $quote('last_seen'));
        foreach (['last_divergence_at', 'last_divergence', 'last_refusal', 'last_crash'] as $latest) {
            $update[] = sprintf('%1$s = COALESCE(VALUES(%1$s), %1$s)', $quote($latest));
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s ON DUPLICATE KEY UPDATE %s',
            $table,
            implode(', ', array_map($quote, self::COLUMNS)),
            implode(', ', array_fill(0, count($rows), $placeholders)),
            implode(', ', $update)
        );

        return [$sql, $bind];
    }

    /** @param array<string,mixed> $detail */
    private static function json(array $detail): string
    {
        return (string)json_encode(
            $detail,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

    private function warnOnce(\Throwable $e): void
    {
        if ($this->warned) {
            return;
        }
        $this->warned = true;

        try {
            $this->logger->warning('template-parser shadow: could not record comparisons', [
                'error' => $e::class . ': ' . $e->getMessage(),
            ]);
        } catch (\Throwable) {
            // A logger that raises is not a reason to fail a render either.
        }
    }
}
