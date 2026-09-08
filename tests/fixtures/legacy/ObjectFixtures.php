<?php
declare(strict_types=1);

/**
 * Object-shaped corpus values.
 *
 * Objects cannot round-trip through the JSON fixture, so a case records a TAG and both the
 * recorder and the replay test build the value from the same factory. Without this the
 * corpus can only hold scalars and arrays - which is exactly why the DataObject resolution
 * failure went unnoticed, since every real Magento template variable is one.
 */
final class ObjectFixtures
{
    public const TAGS = ['dataobject', 'stringable', 'plainobject'];

    public static function make(string $tag): mixed
    {
        return match ($tag) {
            'dataobject' => class_exists(\Magento\Framework\DataObject::class)
                ? new \Magento\Framework\DataObject(['b' => 'deep', 'n' => 0])
                : self::fakeDataObject(),
            'stringable' => new class {
                public function __toString(): string { return 'STR'; }
            },
            'plainobject' => new class {
                public $b = 'pub';
                public function getB(): string { return 'getter'; }
            },
            default => null,
        };
    }

    /** Stands in when the real DataObject is not on the include path (the test run). */
    private static function fakeDataObject(): object
    {
        return new class {
            private array $data = ['b' => 'deep', 'n' => 0];
            public function getData($key = '', $index = null)
            {
                return $key === '' ? $this->data : ($this->data[$key] ?? null);
            }
            public function __call($method, $args)
            {
                if (str_starts_with($method, 'get')) {
                    $key = strtolower((string)preg_replace('/(.)([A-Z])/', '$1_$2', substr($method, 3)));
                    return $this->data[$key] ?? null;
                }
                return null;
            }
        };
    }
}
