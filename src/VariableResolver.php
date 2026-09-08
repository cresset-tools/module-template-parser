<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

/**
 * Resolves a variable expression against the scope.
 *
 * Supports the shapes real templates use: `foo`, `foo.bar`, `foo.getBar()`. Method calls
 * are restricted to argument-less accessors, which is stricter than the legacy resolver.
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

            $next = $this->step($value, $part);
            if ($next === null) {
                // The access WAS attempted and produced nothing. Legacy records null here,
                // so the result is empty rather than the parent.
                return $this->legacyQuirks ? Resolution::of(null) : Resolution::missing($part);
            }
            $value = $next;
        }

        return $this->result($value);
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
        if ($this->legacyQuirks && !is_scalar($value) && !is_array($value)) {
            return Resolution::of(null);
        }
        return Resolution::of($value);
    }

    /** Convenience for existence tests, which never raise. */
    public function value(string $expression, Context $context): mixed
    {
        return $this->resolve($expression, $context)->value;
    }

    private function step(mixed $value, string $part): mixed
    {
        if (str_ends_with($part, '()')) {
            $method = substr($part, 0, -2);
            if (!is_object($value) || !preg_match(self::ACCESSOR, $method) || !method_exists($value, $method)) {
                return null;
            }
            $reflection = new \ReflectionMethod($value, $method);
            if (!$reflection->isPublic() || $reflection->getNumberOfRequiredParameters() > 0) {
                return null;
            }
            return $reflection->invoke($value);
        }

        if (is_array($value)) {
            return $value[$part] ?? null;
        }

        if (is_object($value)) {
            $getter = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $part)));
            if (method_exists($value, $getter)) {
                $reflection = new \ReflectionMethod($value, $getter);
                if ($reflection->isPublic() && $reflection->getNumberOfRequiredParameters() === 0) {
                    return $reflection->invoke($value);
                }
            }
            if (property_exists($value, $part)) {
                $property = new \ReflectionProperty($value, $part);
                return $property->isPublic() ? $property->getValue($value) : null;
            }
        }

        return null;
    }
}
