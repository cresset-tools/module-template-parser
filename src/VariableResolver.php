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
 * Legacy's StrictResolver calls `->getData($key)`, and maps `getFooBar()` to
 * `getData('foo_bar')`; both are reproduced here.
 *
 * Method calls are restricted to argument-less, public, NON-STATIC accessors. Statics are
 * excluded deliberately: a static factory is reachable from any class name, not just from
 * the object graph the host put in scope.
 */
final class VariableResolver
{
    private const ACCESSOR = '/^(get|has|is)[A-Z0-9_]/';

    public function __construct(private readonly bool $legacyQuirks = false)
    {
    }

    public function resolve(string $expression, Context $context): Resolution
    {
        $expression = trim($expression);
        if ($expression === '') {
            return Resolution::missing();
        }

        $parts = explode('.', $expression);
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
        return str_ends_with($part, '()')
            ? $this->callAccessor($value, substr($part, 0, -2))
            : $this->member($value, $part);
    }

    /** `foo.bar` - an array key, a real getter, a data bag entry, or a public property. */
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
            return Resolution::of($value->getData($key));
        }

        if ($this->legacyQuirks) {
            // StrictResolver reaches object members only through getData(). A plain object
            // is never walked, and its final value fails the is_scalar||is_array guard, so
            // legacy yields nothing - getters and public properties are not consulted.
            return Resolution::missing($key);
        }

        $viaGetter = $this->invokeAccessor($value, 'get' . $this->studly($key));
        if ($viaGetter !== null) {
            return $viaGetter;
        }

        if (property_exists($value, $key)) {
            $property = new \ReflectionProperty($value, $key);
            return $property->isPublic()
                ? Resolution::of($property->getValue($value))
                : Resolution::missing($key);
        }

        return Resolution::missing($key);
    }

    /** `foo.getBar()` - a real method, or a data bag entry under the snake_cased name. */
    private function callAccessor(mixed $value, string $method): Resolution
    {
        $label = $method . '()';

        if (!is_object($value) || !preg_match(self::ACCESSOR, $method)) {
            return Resolution::missing($label);
        }

        $direct = $this->invokeAccessor($value, $method);
        if ($direct !== null) {
            return $direct;
        }

        // DataObject serves getFooBar() through __call, so method_exists() saw nothing.
        if ($this->hasDataBag($value) && str_starts_with($method, 'get')) {
            return Resolution::of($value->getData($this->snake(substr($method, 3))));
        }

        return Resolution::missing($label);
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

        return Resolution::of($reflection->invoke($value));
    }

    /** Whether this object keeps its values in a DataObject-style bag. */
    private function hasDataBag(object $value): bool
    {
        if (!method_exists($value, 'getData')) {
            return false;
        }

        $reflection = new \ReflectionMethod($value, 'getData');

        return $reflection->isPublic()
            && !$reflection->isStatic()
            && $reflection->getNumberOfRequiredParameters() === 0;
    }

    /** first_name -> FirstName */
    private function studly(string $key): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
    }

    /** FirstName -> first_name */
    private function snake(string $name): string
    {
        return strtolower((string)preg_replace('/(.)([A-Z])/', '$1_$2', $name));
    }
}
