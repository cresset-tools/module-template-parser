<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Console;

/**
 * Reads `--store` as an id or a store view code.
 *
 * Every command cast it with (int), so a code - `--store=nl`, which is how most people name a
 * store view - silently became store 0. On `template:shadow:clear` that deleted the admin
 * store's rows and said "Cleared N row(s)". A code is looked up; anything else is refused.
 */
final class StoreOption
{
    /**
     * @param array<string,int> $codes store view code => id, for the stores this command knows
     * @throws \InvalidArgumentException for a value that is neither an id nor a known code
     */
    public static function resolve(mixed $value, array $codes): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim((string)$value);
        if (ctype_digit($value)) {
            return (int)$value;
        }
        if (isset($codes[$value])) {
            return $codes[$value];
        }

        throw new \InvalidArgumentException(sprintf(
            'Unknown --store "%s". Give a store view id%s.',
            $value,
            $codes === [] ? ' (no store is connected to look a code up in)' : ', or one of the codes ' . implode(', ', array_keys($codes))
        ));
    }

    /**
     * @param iterable<array{id:int,code:string}|array{code:string}> $stores
     * @return array<string,int>
     */
    public static function codes(iterable $stores): array
    {
        $codes = [];
        foreach ($stores as $key => $store) {
            $codes[(string)$store['code']] = (int)($store['id'] ?? $key);
        }

        return $codes;
    }
}
