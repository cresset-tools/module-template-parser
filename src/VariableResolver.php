<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

/**
 * Resolves a variable expression against the scope.
 *
 * Supports the shapes real templates use: `foo`, `foo.bar`, `foo.getBar()`.
 *
 * Magento data objects hold their values in a bag reached through `getData()` rather than in
 * real properties or methods, so `method_exists()` alone sees nothing on them - which is how
 * every stock template variable (`store`, `customer`, `order`) ends up resolving to nothing.
 * Legacy's StrictResolver calls `->getData($key)` and maps `getFooBar()` to
 * `getData('foo_bar')`; both are reproduced here.
 *
 * Legacy is emphatic that the data bag is the ONLY way into an object - its own comment reads
 * "Strict mode should not call getter methods except DataObject's getData". Compatible mode
 * therefore never invokes a real accessor. Outside compatible mode a real accessor is a
 * useful thing to reach, so it is consulted after the bag, and restricted to public,
 * NON-STATIC, argument-less methods with an accessor-shaped name. Statics are excluded
 * deliberately: a static factory is reachable from any class name, not just from the object
 * graph the host put in scope.
 */
final class VariableResolver
{
    private const ACCESSOR = '/^(get|has|is)[A-Z0-9_]/';

    /** `getBar()`, and also `getBar("x")` - legacy parses arguments and then ignores them. */
    private const METHOD_CALL = '/^([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)\)$/s';

    public function __construct(private readonly bool $legacyQuirks = false)
    {
    }

    public function resolve(string $expression, Context $context): Resolution
    {
        // Legacy's Variable tokenizer skips whitespace anywhere and treats a leading `.` as
        // no action at all, so `a . b`, `.a` and `a..b` all resolve like `a.b`. Dropping the
        // empty segments here also means `{{var o.}}` cannot reach a member named ''.
        $parts = array_values(array_filter(
            array_map('trim', explode('.', $expression)),
            static fn (string $part): bool => $part !== ''
        ));
        if ($parts === []) {
            return Resolution::missing();
        }

        $head = array_shift($parts);

        if (!$context->has($head)) {
            return Resolution::missing($head);
        }
        $value = $context->get($head);

        foreach ($parts as $part) {
            if ($this->legacyQuirks && !$this->allowsDataAccess($value)) {
                // StrictResolver::shouldHandleDataAccess() only advances its cursor when the
                // parent is an array or DataObject. On a scalar parent the access is never
                // attempted and the cursor stays put, so the parent itself is the result -
                // which is why {{var store.frontend_name}} renders the store when `store`
                // is a scalar.
                return $this->result($value);
            }

            $step = $this->step($value, $part);
            if (!$step->found) {
                // The access WAS attempted and produced nothing.
                return $this->legacyQuirks ? Resolution::of(null) : $step;
            }
            $value = $step->value;
        }

        return $this->result($value);
    }

    /** Convenience for existence tests, which never raise. */
    public function value(string $expression, Context $context): mixed
    {
        return $this->resolve($expression, $context)->value;
    }

    /**
     * Whether legacy would attempt member access on this value at all.
     */
    private function allowsDataAccess(mixed $value): bool
    {
        return is_array($value) || is_object($value);
    }

    /**
     * Legacy only ever returns a scalar or an array; anything else becomes null.
     */
    private function result(mixed $value): Resolution
    {
        if ($this->legacyQuirks && !is_scalar($value) && !is_array($value) && $value !== null) {
            return Resolution::of(null);
        }
        return Resolution::of($value);
    }

    private function step(mixed $value, string $part): Resolution
    {
        return preg_match(self::METHOD_CALL, $part, $m) === 1
            ? $this->callAccessor($value, $m[1])
            : $this->member($value, $part);
    }

    /** `foo.bar` - an array key, a data bag entry, a real getter, or a public property. */
    private function member(mixed $value, string $key): Resolution
    {
        if (is_array($value)) {
            // array_key_exists, not ??, so a key holding null is found rather than missing.
            return array_key_exists($key, $value)
                ? Resolution::of($value[$key])
                : Resolution::missing($key);
        }

        if (!is_object($value)) {
            return Resolution::missing($key);
        }

        if ($this->hasDataBag($value)) {
            // Asking the bag whether it holds the key, rather than treating any getData()
            // return as a hit, is what lets strict mode report a typo'd member. Every real
            // Magento template variable is a DataObject, so without this the unknown-variable
            // check is off for precisely the common case.
            if ($this->bagHas($value, $key)) {
                return Resolution::of($this->readBag($value, $key));
            }
            if ($this->legacyQuirks) {
                return Resolution::missing($key);
            }
        }

        if ($this->legacyQuirks) {
            // StrictResolver reaches object members only through getData(). A plain object
            // is never walked, and its final value fails the is_scalar||is_array guard, so
            // legacy yields nothing - getters and public properties are not consulted.
            return Resolution::missing($key);
        }

        $getter = 'get' . $this->studly($key);
        if (preg_match(self::ACCESSOR, $getter) === 1) {
            $viaGetter = $this->invokeAccessor($value, $getter);
            if ($viaGetter !== null) {
                return $viaGetter;
            }
        }

        if (property_exists($value, $key)) {
            $property = new \ReflectionProperty($value, $key);
            return $property->isPublic()
                ? Resolution::of($property->getValue($value))
                : Resolution::missing($key);
        }

        return Resolution::missing($key);
    }

    /** `foo.getBar()` - a data bag entry under the mapped name, or a real method. */
    private function callAccessor(mixed $value, string $method): Resolution
    {
        $label = $method . '()';

        if (!is_object($value)) {
            return Resolution::missing($label);
        }

        // StrictResolver::handleDataAccess only acts on a `get` prefix; anything else leaves
        // the variable unset and resolves to null.
        $isGetter = str_starts_with($method, 'get') && $method !== 'get';

        if ($this->hasDataBag($value) && $isGetter) {
            $key = $this->dataKeyFromGetter($method);
            if ($this->bagHas($value, $key)) {
                return Resolution::of($this->readBag($value, $key));
            }
            if ($this->legacyQuirks) {
                return Resolution::missing($label);
            }
        }

        if ($this->legacyQuirks) {
            return Resolution::missing($label);
        }

        if (preg_match(self::ACCESSOR, $method) !== 1) {
            return Resolution::missing($label);
        }

        return $this->invokeAccessor($value, $method) ?? Resolution::missing($label);
    }

    /** Invokes a public, non-static, argument-less method, or null if it is not one. */
    private function invokeAccessor(object $value, string $method): ?Resolution
    {
        if (!method_exists($value, $method)) {
            return null;
        }

        $reflection = new \ReflectionMethod($value, $method);
        if (!$reflection->isPublic()
            || $reflection->isStatic()
            || $reflection->getNumberOfRequiredParameters() > 0
        ) {
            return null;
        }

        try {
            return Resolution::of($reflection->invoke($value));
        } catch (\Throwable $e) {
            // A template must not be able to take the render down with a host exception, or
            // surface an internal message through it. Everything else here is fail-soft (an
            // object with no __toString yields ''), so this is too - as a TemplateError, so a
            // caller's existing catch still covers it.
            throw AccessorError::at(
                '',
                0,
                sprintf('reading %s() on the host object raised %s', $method, $e::class),
                'the template cannot be responsible for this - it is a defect in the host object'
            );
        }
    }

    /** Whether this object keeps its values in a DataObject-style bag. */
    private function hasDataBag(object $value): bool
    {
        if (!method_exists($value, 'getData')) {
            return false;
        }

        $reflection = new \ReflectionMethod($value, 'getData');

        // It must accept a key as well as tolerate being called without one: a
        // `getData(int $i = 0)` has no REQUIRED parameters yet raises a TypeError the moment
        // a string key reaches it, and a `getData()` taking none at all silently returns the
        // whole bag for every member.
        return $reflection->isPublic()
            && !$reflection->isStatic()
            && $reflection->getNumberOfRequiredParameters() === 0
            && $reflection->getNumberOfParameters() >= 1
            && $this->acceptsStringKey($reflection);
    }

    private function acceptsStringKey(\ReflectionMethod $reflection): bool
    {
        $type = $reflection->getParameters()[0]->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return true;                    // untyped, union or intersection: assume it copes
        }

        return in_array($type->getName(), ['string', 'mixed'], true);
    }

    /** Whether the bag holds this key, using hasData() when the object offers one. */
    private function bagHas(object $value, string $key): bool
    {
        if (method_exists($value, 'hasData')) {
            $reflection = new \ReflectionMethod($value, 'hasData');
            if ($reflection->isPublic()
                && !$reflection->isStatic()
                && $reflection->getNumberOfRequiredParameters() === 0
                && $reflection->getNumberOfParameters() >= 1
            ) {
                return (bool)$value->hasData($key);
            }
        }

        return $this->readBag($value, $key) !== null;
    }

    private function readBag(object $value, string $key): mixed
    {
        return $value->getData($key);
    }

    /** first_name -> FirstName */
    private function studly(string $key): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
    }

    /**
     * getFooBar -> foo_bar, exactly as StrictResolver::extractDataKeyFromGetter does it.
     *
     * The digit rule is the part a hand-rolled camel-to-snake gets wrong: a run of digits is
     * its own segment, so getAddress1() reads address_1, not address1.
     */
    private function dataKeyFromGetter(string $method): string
    {
        return strtolower(ltrim(
            trim((string)preg_replace('/([A-Z]|[0-9]+)/', '_$1', substr($method, 3))),
            '_'
        ));
    }
}
