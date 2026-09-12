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

    /** Argument arrays nested deeper than this are refused; legacy segfaults instead. */
    private const MAX_ARGUMENT_DEPTH = 64;

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

        $head = self::stripInnerWhitespace(array_shift($parts));

        // A call at the HEAD of a path is the variable itself. StrictResolver's first-segment
        // branch tests only the token's NAME against the scope and ignores its type, so
        // `{{var a()}}` and `{{var a}}` read the same variable.
        $call = strpos($head, '(');
        if ($call !== false) {
            $head = substr($head, 0, $call);
        }

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

    /**
     * isWhiteSpace() skips whitespace ANYWHERE in a name, not only at its edges.
     *
     * So `{{var a b}}` reads the variable `ab` there, and trimming the segment ends instead
     * made it read one called `a b` - both resolve, to different data, which is the quiet
     * kind of divergence. The set is trim()'s, so NUL and vertical tab count and form feed
     * does not.
     */
    private static function stripInnerWhitespace(string $name): string
    {
        return strtr($name, [' ' => '', "\t" => '', "\n" => '', "\r" => '', "\0" => '', "\x0B" => '']);
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
     *
     * Wider than shouldHandleDataAccess(), which advances only past an array, a DataObject or
     * an AbstractTemplate - classes this package will not name, so hasDataBag() duck-types
     * getData() instead. A plain object is therefore walked where legacy's cursor stops;
     * member() refuses it there, and the caller turns that into the same null the stranded
     * cursor yields.
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
        // A name ends at the FIRST `(` in Tokenizer\Variable, and getMethodArgs() consumes
        // to the closing `)` or to the end of the string - it does not require one to be
        // there. Matching a full `name(...)` shape instead made `{{var a.getB(}}` a plain
        // member read, which renders nothing where legacy raises `Call to a member function
        // getData() on array`: a fail-open hole in the one refusal this engine states
        // absolutely, and one the corpus could not see because it has no such expression.
        $paren = strpos($part, '(');
        if ($paren === false) {
            return $this->member($value, $part);
        }

        // isWhiteSpace() skips whitespace anywhere in a name, so `g etB()` is `getB()`.
        $name = self::stripInnerWhitespace(substr($part, 0, $paren));

        // Everything after the `(`; parseValues() stops at the first top-level `)` on its
        // own, exactly as getMethodArgs() does.
        return $this->callAccessor($value, $name, substr($part, $paren + 1), $context);
    }

    /** `foo.bar` - an array key, a data bag entry, a real getter, or a public property. */
    private function member(mixed $value, string $key): Resolution
    {
        $key = self::stripInnerWhitespace($key);

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
        // `substr($name, 0, 3) == 'get'` there, with no exclusion for the bare word - so
        // `.get()` maps to getData('') and DataObject hands back its WHOLE data bag.
        $isGetter = str_starts_with($method, 'get');

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
     * behaviour is to parse these and throw them away - and so is this engine's, so none of
     * this work happens at all.
     *
     * The grammar is legacy's: values separated by commas or whitespace; a run of digits and
     * dots is a float; `[` opens an array whose members may carry a `key:` prefix; anything
     * else is a string, quoted or bare, in which a backslash escapes the next character. A
     * `$name` value resolves against the scope, as getStackArgs() does, so
     * `getUrl($store, ...)` is handed the store and not the word.
     *
     * Written as a cursor port rather than a reasonable-looking scanner, for the reason
     * ParameterParser is: a hand-rolled version disagreed with the tokenizer five ways and
     * hung on the sixth. What is NOT legacy's is the depth cap and the progress assertion -
     * legacy has neither, and segfaults on the first and spins on the second.
     *
     * @return list<mixed>
     */
    private function parseArguments(string $arguments, Context $context): array
    {
        $offset = 0;

        /** @var list<mixed> $parsed */
        $parsed = $this->parseValues($arguments, $offset, $context, false, 0);

        return $parsed;
    }

    /**
     * Values up to the closing `]` (inside an array) or the closing `)` / end of input.
     *
     * The `)` case is the one legacy gets right and a scanner easily does not: `)` is a
     * string break, so a bare `)` makes parseString() return '' without moving the cursor.
     * Filtering only `,` and whitespace there meant the loop appended '' for ever -
     * `{{var o.f())}}` exhausted memory in under a second, reachable from every CLI command
     * because the console wires the method-call port unconditionally.
     *
     * @return array<array-key,mixed>
     */
    private function parseValues(string $source, int &$offset, Context $context, bool $inArray, int $depth): array
    {
        if ($depth > self::MAX_ARGUMENT_DEPTH) {
            // Legacy recurses until the C stack gives out - a 360 KB argument list segfaults
            // it, and PHP cannot catch that. Refusing is the only safe reading, and no real
            // template nests arguments anywhere near this deep.
            // Unpositioned, like AccessorError: the resolver has no view of the source, and
            // the evaluator re-raises it against the directive it was resolving.
            throw NestingLimitError::at(
                '',
                0,
                sprintf('method arguments nested deeper than %d levels', self::MAX_ARGUMENT_DEPTH),
                'legacy recurses here until the C stack gives out, which PHP cannot catch'
            );
        }

        $values = [];
        $length = strlen($source);

        while ($offset < $length) {
            $char = $source[$offset];

            if ($inArray ? $char === ']' : $char === ')') {
                $offset++;
                break;
            }
            if ($char === ',' || trim($char) === '') {
                $offset++;
                continue;
            }

            $before = $offset;
            // Keys exist inside an array and nowhere else: getMethodArgs() never reads one.
            $key = $inArray ? $this->parseMemberKey($source, $offset) : null;
            $value = $this->parseValue($source, $offset, $context, $inArray, $depth);

            // getArray() and getMethodArgs() both advance unconditionally each pass; this
            // scanner can decline to. Without this the only symptom is a hang.
            if ($offset === $before) {
                $offset++;
                continue;
            }

            // `if ($key)`, not a null check: legacy tests the key for truthiness, so the
            // string '0' is falsy there and the member is APPENDED rather than landing on
            // slot 0 and overwriting whatever was already there.
            if ($key === null || $key === '' || $key === '0') {
                $values[] = $value;
            } else {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    private function parseValue(string $source, int &$offset, Context $context, bool $inArray, int $depth): mixed
    {
        $char = $source[$offset];

        if ($char >= '0' && $char <= '9') {
            return $this->parseNumber($source, $offset);
        }
        if ($char === '[') {
            $offset++;

            return $this->parseValues($source, $offset, $context, true, $depth + 1);
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

    /**
     * getString(): a quoted run or a bare run up to a separator, backslash escaping either.
     *
     * The escape applies outside quotes too, which is what makes `f(a\,b)` a single argument
     * `a,b`. Reading bare values byte-for-byte instead also left an escaped `)` behind as a
     * stray break character, which is the second way into the hang above.
     */
    private function parseString(string $source, int &$offset, bool $inArray): string
    {
        $length = strlen($source);

        // getString() returns empty the moment it lands on whitespace, before it even looks
        // for a quote. Only reachable after a `key:`, since the array loop skips whitespace
        // between members - which is why `[a : b]` is `a => ''` there and not `a => 'b'`.
        if (trim($source[$offset]) === '') {
            return '';
        }

        $quote = ($source[$offset] === '"' || $source[$offset] === "'") ? $source[$offset] : null;
        $value = $quote === null ? $source[$offset] : '';

        while (++$offset < $length) {
            $char = $source[$offset];

            if ($quote === null && $this->isStringBreak($char, $inArray)) {
                break;
            }
            if ($quote !== null && $char === $quote) {
                $offset++;
                break;
            }
            if ($char === '\\' && $offset + 1 < $length) {
                $value .= $source[++$offset];
                continue;
            }

            $value .= $char;
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
     * Looks ahead rather than consuming, because legacy rewinds when it finds no colon. The
     * probe stops at `[` as well as at a string break: without that, a run of N `[` was
     * rescanned at every nesting level, which is quadratic - 16 000 of them took 7.5 seconds
     * where the same depth with a comma after each took 0.011.
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
            // The FIRST character joins the key unconditionally, before the colon test, so a
            // leading `:` is part of the key text rather than a separator: `[:1]` is the
            // single value `:1` there, not an empty key holding 1.
            $key .= $source[$probe++];
            while ($probe < $length
                && $source[$probe] !== ':'
                && $source[$probe] !== '['
                && !$this->isStringBreak($source[$probe], true)) {
                $key .= $source[$probe++];
            }
        }

        if ($probe >= $length || $source[$probe] !== ':') {
            return null;                // no colon: an unkeyed member, and $offset stays put
        }

        // A trailing `key:` with nothing after it would leave the cursor past the end, and
        // every read from there is an "Uninitialized string offset" warning - which Magento's
        // error handler turns into a thrown ErrorException.
        if ($probe + 1 >= $length) {
            return null;
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
            throw $this->accessorFailure($method, $e);
        }
    }

    /** Whether this object keeps its values in a DataObject-style bag. */
    private function hasDataBag(object $value): bool
    {
        return $this->takesOptionalStringKey($value, 'getData');
    }

    /**
     * Whether a method accepts a key and also tolerates being called without one.
     *
     * Both halves are load-bearing: a `getData(int $i = 0)` has no REQUIRED parameters yet
     * raises a TypeError the moment a string key reaches it, and a `getData()` taking none at
     * all silently returns the whole bag for every member. hasData() is held to the same
     * shape, because it is reached with a key the same way.
     */
    private function takesOptionalStringKey(object $value, string $method): bool
    {
        if (!method_exists($value, $method)) {
            return false;
        }

        $reflection = new \ReflectionMethod($value, $method);

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
        if ($this->takesOptionalStringKey($value, 'hasData')) {
            if ((bool)$this->callHost($value, 'hasData', $key)) {
                return true;
            }

            // hasData() is `array_key_exists($key, $this->_data)` and knows nothing about the
            // `a/b/c` path syntax getData() implements - getData falls back to getDataByPath()
            // whenever a direct lookup comes back null and the key holds a slash. Trusting
            // hasData for those keys made `{{var order.billing/city}}` resolve on the filter
            // and to nothing here. The asymmetry is DataObject's; only slash keys are widened,
            // so a key that is genuinely absent is still missing rather than null.
            return str_contains($key, '/') && $this->readBag($value, $key) !== null;
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
     * routinely override getData() with lazy loading - so they are wrapped like one.
     */
    private function callHost(object $value, string $method, string $key): mixed
    {
        try {
            return $value->{$method}($key);
        } catch (\Throwable $e) {
            throw $this->accessorFailure($method, $e);
        }
    }

    /**
     * What an exception out of a host method becomes.
     *
     * Everything else in this resolver is fail-soft - an object with no __toString yields ''
     * - so a method that raises is too, rather than taking the render down or surfacing an
     * internal message through the output. A TemplateError, so a caller's existing catch
     * still covers it.
     */
    private function accessorFailure(string $method, \Throwable $e): AccessorError
    {
        return AccessorError::at(
            '',
            0,
            sprintf('reading %s() on the host object raised %s', $method, $e::class),
            'the template cannot be responsible for this - it is a defect in the host object'
        );
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
