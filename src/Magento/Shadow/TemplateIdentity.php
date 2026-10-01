<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento\Shadow;

/**
 * Which template a render was, recovered from its source text.
 *
 * The filter is handed a string and never learns where it came from, so the plugins on the
 * places that fetch template text - the email model before it processes, the CMS models as
 * they hand out content - register an identity against that text here, and the filter plugin
 * looks it up by the source it was given. The identity is what a Shadow report is keyed on:
 * `email:sales_email_order_template` or `cms_block:12` says which template to open, where a
 * hash of the source said nothing anyone could act on.
 *
 * Keyed by a hash rather than the text itself so the registry holds 16 bytes per entry, not
 * a copy of every template it has seen. Bounded, because a cron run or a queue consumer
 * sends thousands of emails in one process and this lives as long as that process does: past
 * the capacity the least recently registered entry goes. A render whose registration was
 * evicted is reported as unidentified rather than misattributed.
 *
 * Two templates with byte-identical source share an entry, and the one registered last names
 * it. They render identically, so the comparison is the same either way.
 */
class TemplateIdentity
{
    public const CAPACITY = 256;

    /** @var array<string,string> hash of the source => identity, oldest first */
    private array $bySource = [];

    public function __construct(private readonly int $capacity = self::CAPACITY)
    {
    }

    public function remember(string $source, string $identity): void
    {
        if ($source === '') {
            return;
        }

        $key = self::key($source);
        // Re-registering moves an entry to the young end, so a template rendered over and over
        // is never the one evicted.
        unset($this->bySource[$key]);
        $this->bySource[$key] = $identity;

        if (count($this->bySource) > $this->capacity) {
            unset($this->bySource[array_key_first($this->bySource)]);
        }
    }

    public function identify(string $source): ?string
    {
        return $source === '' ? null : ($this->bySource[self::key($source)] ?? null);
    }

    /**
     * What a render nothing registered is recorded as: the filter class that rendered it, so
     * a report can at least say "a CMS render" or "a newsletter subject".
     */
    public static function unidentified(object $filter): string
    {
        $class = $filter::class;
        if (str_contains($class, '@anonymous')) {
            $class = get_parent_class($filter) ?: 'anonymous';
        }
        // The generated interceptor is an implementation detail of DI, not the class anyone
        // would look for.
        if (str_ends_with($class, '\\Interceptor')) {
            $class = substr($class, 0, -strlen('\\Interceptor'));
        }

        return 'unidentified:' . $class;
    }

    private static function key(string $source): string
    {
        return hash('xxh128', $source, true);
    }
}
