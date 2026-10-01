<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Shadow;

/**
 * What Shadow mode's rows say, per store view.
 *
 * Pure: rows in, summaries out, so the arithmetic the rollout decision rests on is tested
 * without a database. See Shadow\ShadowRecorder for what each column means; in short, a
 * divergence or a crash counts against a template, an agreement or a refusal counts as clean,
 * and "clean since" is the last divergence or, failing one, the first comparison.
 *
 * `$since` narrows what counts against a template to divergences at or after that moment -
 * the question after fixing something is whether it has diverged SINCE the fix, and a
 * divergence from before it is history rather than evidence. Earlier divergences stay in the
 * counts; they just stop failing the report.
 */
final class ShadowReport
{
    /** @var array<int,array<string,mixed>> store id => summary */
    private array $stores = [];

    private int $orphanedRows = 0;

    /**
     * @param list<array<string,mixed>> $rows from ShadowTable::rows()
     * @param array<int,array{code:string,name:string,website_id:int}> $stores from ShadowTable::stores()
     * @param ?string $since UTC 'Y-m-d H:i:s'
     */
    public function __construct(array $rows, array $stores, private readonly ?string $since = null)
    {
        foreach ($rows as $row) {
            $storeId = (int)$row['store_id'];
            if (!isset($stores[$storeId])) {
                // The recorder keeps no foreign key (see db_schema.xml), so a deleted store
                // view's rows outlive it. They describe nothing that can still be switched.
                $this->orphanedRows++;
                continue;
            }

            $this->stores[$storeId] ??= [
                'store_id' => $storeId,
                'code' => $stores[$storeId]['code'],
                'name' => $stores[$storeId]['name'],
                'templates' => 0,
                'renders' => 0,
                'agreed' => 0,
                'diverged' => 0,
                'refused' => 0,
                'crashed' => 0,
                'first_seen' => null,
                'last_seen' => null,
                'last_divergence_at' => null,
                'last_divergence_template' => null,
                'failing' => 0,
                'rows' => [],
            ];
            $store = &$this->stores[$storeId];

            $template = self::template($row);
            $template['failing'] = $template['last_divergence_at'] !== null
                && ($this->since === null || $template['last_divergence_at'] >= $this->since);

            $store['templates']++;
            foreach (['agreed', 'diverged', 'refused', 'crashed'] as $counter) {
                $store[$counter] += $template[$counter];
                $store['renders'] += $template[$counter];
            }
            $store['first_seen'] = self::earliest($store['first_seen'], $template['first_seen']);
            $store['last_seen'] = self::latest($store['last_seen'], $template['last_seen']);
            if ($template['last_divergence_at'] !== null
                && ($store['last_divergence_at'] === null || $template['last_divergence_at'] > $store['last_divergence_at'])) {
                $store['last_divergence_at'] = $template['last_divergence_at'];
                $store['last_divergence_template'] = $template['template'];
            }
            $store['failing'] += $template['failing'] ? 1 : 0;
            $store['rows'][] = $template;
            unset($store);
        }
    }

    /** @return list<array<string,mixed>> in store id order */
    public function stores(): array
    {
        ksort($this->stores);

        return array_values($this->stores);
    }

    public function isEmpty(): bool
    {
        return $this->stores === [];
    }

    /** Anything diverged or crashed (since `$since`, when given). */
    public function isFailing(): bool
    {
        foreach ($this->stores as $store) {
            if ($store['failing'] > 0) {
                return true;
            }
        }

        return false;
    }

    public function orphanedRows(): int
    {
        return $this->orphanedRows;
    }

    /**
     * One store view's state in a line, for `status`.
     *
     * @param array<string,mixed> $store
     */
    public static function oneLine(array $store): string
    {
        $line = sprintf(
            '%d template(s), %d render(s): %d agreed, %d diverged, %d refused, %d crashed',
            $store['templates'],
            $store['renders'],
            $store['agreed'],
            $store['diverged'],
            $store['refused'],
            $store['crashed']
        );

        return $line . ($store['last_divergence_at'] !== null
            ? sprintf('; last divergence %s UTC', $store['last_divergence_at'])
            : sprintf('; clean since %s UTC', $store['first_seen']));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function template(array $row): array
    {
        return [
            'template' => (string)$row['template'],
            'agreed' => (int)$row['agreed'],
            'diverged' => (int)$row['diverged'],
            'refused' => (int)$row['refused'],
            'crashed' => (int)$row['crashed'],
            'first_seen' => (string)$row['first_seen'],
            'last_seen' => (string)$row['last_seen'],
            'last_divergence_at' => $row['last_divergence_at'] !== null ? (string)$row['last_divergence_at'] : null,
            'clean_since' => $row['last_divergence_at'] !== null ? (string)$row['last_divergence_at'] : (string)$row['first_seen'],
            'renders_since_divergence' => (int)$row['renders_since_divergence'],
            'last_divergence' => self::decode($row['last_divergence'] ?? null),
            'last_refusal' => self::decode($row['last_refusal'] ?? null),
            'last_crash' => self::decode($row['last_crash'] ?? null),
        ];
    }

    /** @return ?array<string,mixed> */
    private static function decode(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function earliest(?string $a, string $b): string
    {
        return $a === null || $b < $a ? $b : $a;
    }

    private static function latest(?string $a, string $b): string
    {
        return $a === null || $b > $a ? $b : $a;
    }
}
