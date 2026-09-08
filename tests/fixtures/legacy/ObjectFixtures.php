<?php
declare(strict_types=1);

/**
 * Object-shaped corpus values.
 *
 * Objects cannot round-trip through the JSON fixture, so a case records a TAG and both the
 * recorder and the replay test build the value from the same factory. Without this the
 * corpus can only hold scalars and arrays - which is exactly why the DataObject resolution
 * failure went unnoticed, since every real Magento template variable is one.
 *
 * The recorder runs with Magento on the include path and uses the REAL DataObject; the test
 * run does not, and uses FakeDataObject. That split is only sound while the two behave
 * alike, so record-legacy.php asserts they agree before it writes anything - see
 * assertFakeMatchesReal(). Getting this wrong records one object's behaviour and replays
 * another's, which is a parity measurement of nothing.
 */
final class ObjectFixtures
{
    public const TAGS = ['dataobject', 'stringable', 'plainobject'];

    public static function make(string $tag): mixed
    {
        return match ($tag) {
            'dataobject' => class_exists(\Magento\Framework\DataObject::class)
                ? new \Magento\Framework\DataObject(self::dataObjectContents())
                : new FakeDataObject(self::dataObjectContents()),
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

    /** @return array<string,mixed> */
    public static function dataObjectContents(): array
    {
        return [
            'b' => 'deep',
            'n' => 0,
            'address_1' => 'A1',
            'nested' => ['q' => 'NESTED'],
            'null_key' => null,
        ];
    }

    /**
     * Probes covering every DataObject behaviour the resolver depends on.
     *
     * @return array<string,callable(object):mixed>
     */
    public static function equivalenceProbes(): array
    {
        return [
            'getData()'            => static fn (object $o): mixed => $o->getData(),
            'getData(b)'           => static fn (object $o): mixed => $o->getData('b'),
            'getData(n)'           => static fn (object $o): mixed => $o->getData('n'),
            'getData(missing)'     => static fn (object $o): mixed => $o->getData('missing'),
            'getData(null_key)'    => static fn (object $o): mixed => $o->getData('null_key'),
            'getData(nested/q)'    => static fn (object $o): mixed => $o->getData('nested/q'),
            'hasData(b)'           => static fn (object $o): mixed => $o->hasData('b'),
            'hasData(n)'           => static fn (object $o): mixed => $o->hasData('n'),
            'hasData(null_key)'    => static fn (object $o): mixed => $o->hasData('null_key'),
            'hasData(missing)'     => static fn (object $o): mixed => $o->hasData('missing'),
            'hasData()'            => static fn (object $o): mixed => $o->hasData(),
            'getB()'               => static fn (object $o): mixed => $o->getB(),
            'getAddress1()'        => static fn (object $o): mixed => $o->getAddress1(),
            'getNullKey()'         => static fn (object $o): mixed => $o->getNullKey(),
            'getMissing()'         => static fn (object $o): mixed => $o->getMissing(),
            'hasB() magic'         => static fn (object $o): mixed => $o->hasB(),
            'offsetGet(b)'         => static fn (object $o): mixed => $o['b'],
            'offsetExists(b)'      => static fn (object $o): mixed => isset($o['b']),
            'toArray()'            => static fn (object $o): mixed => $o->toArray(),
        ];
    }
}

/**
 * Stands in for Magento\Framework\DataObject when it is not on the include path.
 *
 * Faithful to the parts a template can reach: the '/' path form of getData, hasData's
 * array_key_exists (so a key holding null is still present), ArrayAccess, and the
 * _underscore rule - which treats a run of digits as its own segment, so getAddress1()
 * reads address_1 rather than address1.
 */
class FakeDataObject implements \ArrayAccess
{
    /** @param array<string,mixed> $data */
    public function __construct(protected array $data = [])
    {
    }

    public function getData($key = '', $index = null)
    {
        if ('' === $key) {
            return $this->data;
        }
        if ($key === null) {
            return null;
        }

        $value = $this->data[$key] ?? null;
        if ($value === null && str_contains((string)$key, '/')) {
            $value = $this->getDataByPath((string)$key);
        }

        if ($index !== null) {
            if ($value === (array)$value) {
                $value = $value[$index] ?? null;
            } elseif (is_string($value)) {
                $parts = explode(PHP_EOL, $value);
                $value = $parts[$index] ?? null;
            } else {
                $value = null;
            }
        }

        return $value;
    }

    public function getDataByPath($path)
    {
        $data = $this->data;
        foreach (explode('/', (string)$path) as $key) {
            if ((array)$data === $data && isset($data[$key])) {
                $data = $data[$key];
            } elseif ($data instanceof self) {
                $data = $data->getData($key);
            } else {
                return null;
            }
        }
        return $data;
    }

    public function hasData($key = '')
    {
        if (empty($key) || !is_string($key)) {
            return !empty($this->data);
        }
        return array_key_exists($key, $this->data);
    }

    public function setData($key, $value = null)
    {
        $this->data[$key] = $value;
        return $this;
    }

    public function unsetData($key = null)
    {
        if ($key === null) {
            $this->data = [];
        } else {
            unset($this->data[$key]);
        }
        return $this;
    }

    public function toArray(array $keys = [])
    {
        if ($keys === []) {
            return $this->data;
        }
        return array_intersect_key($this->data, array_flip($keys));
    }

    public function __call($method, $args)
    {
        return match (substr($method, 0, 3)) {
            'get' => $this->getData($this->underscore($method), $args[0] ?? null),
            'set' => $this->setData($this->underscore($method), $args[0] ?? null),
            'uns' => $this->unsetData($this->underscore($method)),
            'has' => isset($this->data[$this->underscore($method)]),
            default => null,
        };
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->data[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }

    /** DataObject::_underscore, digits and all. */
    private function underscore(string $name): string
    {
        return strtolower(trim(
            (string)preg_replace('/([A-Z]|[0-9]+)/', '_$1', lcfirst(substr($name, 3))),
            '_'
        ));
    }
}
