<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

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

    /** @var ?\Closure(object,string,list<mixed>,Context):?Resolution */
    private ?\Closure $methodCallServer = null;

    public function __construct(private readonly bool $legacyQuirks = false)
    {
    }

    /**
     * Lets a host serve a method call that carries arguments.
     *
     * There is exactly one of those in the legacy filter - `getUrl` on a template model -
     * and this is how it gets served without the resolver knowing what a template model is.
     * The server returns null to decline, and declining is the default: with no server
     * installed every method call goes through the ordinary getData() mapping, arguments
     * parsed and dropped, as it always did.
     *
     * A registration method rather than a constructor argument for the same reason
     * Evaluator::register() is one: the ports are wired after the engine is built.
     *
     * @param \Closure(object,string,list<mixed>,Context):?Resolution $server
     */
    public function serveMethodCalls(\Closure $server): void
    {
        $this->methodCallServer = $server;
    }

    public function resolve(string $expression, Context $context): Resolution
    {
        // Tokenizer\AbstractTokenizer::setString() rawurldecodes, so `{{var a%2Eb}}` is
        // `{{var a.b}}` - the decoding happens before the path is split and can therefore
        // create segments.
        $parts = $this->splitPath(rawurldecode($expression));
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

            $step = $this->step($value, $part, $context);
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
     * Splits an expression into its segments, on the dots that actually separate them.
     *
     * Not explode('.'). A method call carries its own arguments and those contain dots:
     * `this.getUrl($store,'x',[_query:[id:$customer.id]])` is two segments, not four, and
     * splitting it naively left the second one unmatchable and the whole expression
     * unresolvable - which is every "set your password" link in every stock account email.
     * Legacy never sees those dots because getMethodArgs() takes the argument list as one
     * token; tracking the nesting here has the same effect.
     *
     * Legacy's tokenizer also skips whitespace anywhere and treats a leading `.` as no
     * action at all, so `a . b`, `.a` and `a..b` all resolve like `a.b`. Dropping the empty
     * segments keeps that, and means `{{var o.}}` cannot reach a member named ''.
     *
     * @return list<string>
     */
    private function splitPath(string $expression): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $expression[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($char === '.' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $part): bool => $part !== ''
        ));
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

    private function step(mixed $value, string $part, Context $context): Resolution
    {
        return preg_match(self::METHOD_CALL, $part, $m) === 1
            ? $this->callAccessor($value, $m[1], $m[2], $context)
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
    private function callAccessor(mixed $value, string $method, string $arguments, Context $context): Resolution
    {
        $label = $method . '()';

        // Before the getData() mapping, because that is where legacy checks it too.
        if ($this->methodCallServer !== null && is_object($value)) {
            $served = ($this->methodCallServer)(
                $value,
                $method,
                $this->parseArguments($arguments, $context),
                $context
            );
            if ($served !== null) {
                return $served;
            }
        }

        if ($this->legacyQuirks && is_array($value) && str_starts_with($method, 'get')) {
            // StrictResolver::handleDataAccess calls ->getData() on the parent - but only
            // when the method name starts with `get`. Anything else leaves the variable
            // unset and resolves to nothing, so refusing every method call on an array
            // refused `{{var a.foo()}}`, which legacy renders as ''.
            throw new LegacyFatalShape(sprintf(
                'the legacy filter calls %s on an array, which is a fatal Error there',
                $label
            ));
        }

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

    /**
     * Parses a method call's arguments, the way Tokenizer\Variable::getMethodArgs() does.
     *
     * Only reached once a host has installed a method-call server. Without one, legacy's own
     * behaviour is to parse these and throw them away - and so is this engine's, so the work
     * is not done at all.
     *
     * The grammar is small and all of it is legacy's: values separated by commas or
     * whitespace; a run of digits and dots is a float; `[` opens an array whose members may
     * carry a `key:` prefix; anything else is a string, either quoted with backslash escapes
     * or bare up to the next separator. A `$name` value resolves against the scope, as
     * getStackArgs() does, so `getUrl($store, ...)` is handed the store and not the word.
     *
     * @return list<mixed>
     */
    private function parseArguments(string $arguments, Context $context): array
    {
        $offset = 0;

        /** @var list<mixed> $parsed */
        $parsed = $this->parseValues($arguments, $offset, $context, null);

        return $parsed;
    }

    /**
     * Values up to $terminator, or to the end of the string when there is none.
     *
     * @return array<array-key,mixed>
     */
    private function parseValues(string $source, int &$offset, Context $context, ?string $terminator): array
    {
        $values = [];
        $length = strlen($source);

        while ($offset < $length) {
            $char = $source[$offset];
            if ($char === $terminator) {
                $offset++;
                break;
            }
            if ($char === ',' || trim($char) === '') {
                $offset++;
                continue;
            }

            // Keys exist inside an array and nowhere else: getMethodArgs() never reads one.
            $key = $terminator === null ? null : $this->parseMemberKey($source, $offset);
            $value = $this->parseValue($source, $offset, $context, $terminator !== null);

            if ($key === null || $key === '') {
                $values[] = $value;
            } else {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    private function parseValue(string $source, int &$offset, Context $context, bool $inArray): mixed
    {
        $char = $source[$offset];

        if ($char >= '0' && $char <= '9') {
            return $this->parseNumber($source, $offset);
        }
        if ($char === '[') {
            $offset++;

            return $this->parseValues($source, $offset, $context, ']');
        }

        $raw = $this->parseString($source, $offset, $inArray);

        return str_starts_with($raw, '$') ? $this->resolve(substr($raw, 1), $context)->value : $raw;
    }

    /** getNumber(): digits and dots, cast to float - so `1` arrives as 1.0, as it does there. */
    private function parseNumber(string $source, int &$offset): float
    {
        $start = $offset;
        $length = strlen($source);
        while ($offset < $length
            && (($source[$offset] >= '0' && $source[$offset] <= '9') || $source[$offset] === '.')) {
            $offset++;
        }

        return (float)substr($source, $start, $offset - $start);
    }

    /** getString(): a quoted run with backslash escapes, or a bare run up to a separator. */
    private function parseString(string $source, int &$offset, bool $inArray): string
    {
        $length = strlen($source);
        $quote = ($source[$offset] === '"' || $source[$offset] === "'") ? $source[$offset] : null;
        $value = '';

        if ($quote !== null) {
            $offset++;
            while ($offset < $length) {
                if ($source[$offset] === '\\' && $offset + 1 < $length) {
                    $value .= $source[++$offset];
                } elseif ($source[$offset] === $quote) {
                    $offset++;

                    return $value;
                } else {
                    $value .= $source[$offset];
                }
                $offset++;
            }

            return $value;
        }

        while ($offset < $length && !$this->isStringBreak($source[$offset], $inArray)) {
            $value .= $source[$offset++];
        }

        return $value;
    }

    /** isStringBreak(): what ends a bare value depends on whether an array is open. */
    private function isStringBreak(string $char, bool $inArray): bool
    {
        return $inArray
            ? $char === ',' || $char === ']'
            : trim($char) === '' || $char === ',' || $char === ')';
    }

    /**
     * getMemberKey(): a `key:` prefix inside an array, or null when the member has none.
     *
     * Looks ahead rather than consuming, because legacy rewinds when it finds no colon.
     */
    private function parseMemberKey(string $source, int &$offset): ?string
    {
        $probe = $offset;
        $length = strlen($source);
        $quote = ($source[$probe] === '"' || $source[$probe] === "'") ? $source[$probe] : null;
        $key = '';

        if ($quote !== null) {
            $probe++;
            while ($probe < $length && $source[$probe] !== $quote) {
                $key .= $source[$probe++];
            }
            $probe++;
        } else {
            // A colon ends a key and nothing else does, which is why this cannot reuse
            // parseString(): there, a colon is an ordinary character in a value.
            while ($probe < $length
                && $source[$probe] !== ':'
                && !$this->isStringBreak($source[$probe], true)) {
                $key .= $source[$probe++];
            }
        }

        if ($probe >= $length || $source[$probe] !== ':') {
            return null;                // no colon: an unkeyed member, and $offset stays put
        }

        $offset = $probe + 1;

        return $key;
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
            // Same shape check getData() gets. Without acceptsStringKey() a
            // `hasData(int $key = 0)` raises a TypeError from inside the resolver.
            if ($reflection->isPublic()
                && !$reflection->isStatic()
                && $reflection->getNumberOfRequiredParameters() === 0
                && $reflection->getNumberOfParameters() >= 1
                && $this->acceptsStringKey($reflection)
            ) {
                return (bool)$this->callHost($value, 'hasData', $key);
            }
        }

        return $this->readBag($value, $key) !== null;
    }

    private function readBag(object $value, string $key): mixed
    {
        return $this->callHost($value, 'getData', $key);
    }

    /**
     * Calls a host data-bag method, converting anything it raises into an AccessorError.
     *
     * getData() and hasData() are host code as much as a real accessor is - Magento models
     * routinely override getData() with lazy loading - so they need the same treatment
     * invokeAccessor() gives, or a template can take the render down with a host exception
     * and surface its message.
     */
    private function callHost(object $value, string $method, string $key): mixed
    {
        try {
            return $value->{$method}($key);
        } catch (\Throwable $e) {
            throw AccessorError::at(
                '',
                0,
                sprintf('reading %s() on the host object raised %s', $method, $e::class),
                'the template cannot be responsible for this - it is a defect in the host object'
            );
        }
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
