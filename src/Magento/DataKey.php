<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

/**
 * Whether a parameter name reaches a given block property, the way Magento resolves it.
 *
 * `DataObject::setDataUsingMethod('Template', ...)` calls `setTemplate()`: the method name is
 * built by removing underscores and upper-casing words, and PHP method names are
 * case-insensitive. So `Template=`, `TEMPLATE=` and `tem_plate=` are all `template`, and a guard
 * that drops only the exact lowercase key drops none of them.
 */
final class DataKey
{
    public static function is(string|int $key, string $name): bool
    {
        return self::normalised($key) === self::normalised($name);
    }

    private static function normalised(string|int $key): string
    {
        return strtolower(str_replace('_', '', (string)$key));
    }
}
