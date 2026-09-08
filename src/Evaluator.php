<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

use MageOS\TemplateParser\Ast\DirectiveNode;
use MageOS\TemplateParser\Ast\Node;
use MageOS\TemplateParser\Ast\RootNode;
use MageOS\TemplateParser\Ast\TextNode;

/**
 * Renders an AST.
 *
 * The security property this exists to provide: a variable's value is inserted into the
 * output as a *value*. It is never scanned for directives, at any depth, under any
 * modifier. There is no second pass, so there is nothing for a smuggled marker to unlock.
 */
final class Evaluator
{
    /** The escape types Email\Model\Template\Filter::modifierEscape actually implements. */
    private const ESCAPE_TYPES = ['html', 'htmlentities', 'url'];

    /** @var array<string,callable(DirectiveNode, Context, self):string> */
    private array $handlers = [];

    private string $source = '';

    /** Position of the {{var}} whose modifiers are being applied, for diagnostics. */
    private int $modifierOffset = 0;

    public function __construct(
        private readonly VariableResolver $variables = new VariableResolver(),
        private readonly ParameterParser $parameters = new ParameterParser(),
        private readonly DirectiveSpec $spec = new DirectiveSpec(),
        private readonly Options $options = new Options()
    ) {
        $this->registerDefaults();
    }

    /** @param callable(DirectiveNode, Context, self):string $handler */
    public function register(string $name, callable $handler): void
    {
        $this->handlers[$name] = $handler;
    }

    /**
     * Drops a handler, so the directive renders verbatim again.
     *
     * The host application decides which directives exist. An unregistered directive is
     * never guessed at - it round-trips as text.
     */
    public function unregister(string $name): void
    {
        unset($this->handlers[$name]);
    }

    /** @return string[] */
    public function registered(): array
    {
        return array_keys($this->handlers);
    }

    public function evaluate(RootNode $root, Context $context): string
    {
        // Saved and restored so a nested render of a different template (for instance a
        // {{template}} include) reports positions against its own source.
        $outer = $this->source;
        $this->source = $root->source();
        try {
            return $this->renderNodes($root->children(), $context);
        } finally {
            $this->source = $outer;
        }
    }

    /** @param Node[] $nodes */
    public function renderNodes(array $nodes, Context $context): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= $this->renderNode($node, $context);
        }
        return $out;
    }

    public function renderNode(Node $node, Context $context): string
    {
        if ($node instanceof TextNode) {
            return $node->text();
        }

        if ($node instanceof UnclosedDirective) {
            return $node->raw();          // unbalanced construct is text
        }

        if (!$node instanceof DirectiveNode) {
            return $node->raw();
        }

        if ($this->options->legacyQuirks
            && $context->names() === []
            && in_array($node->name(), ['var', 'if', 'depend'], true)
        ) {
            // With no variables set, IfDirective, DependDirective and the Email filter's
            // varDirective all return their construction unchanged - the path used when a
            // template is being validated rather than rendered.
            //
            // The output has to stay verbatim for parity, but the body being copied out may
            // contain directives the policy refused. Nothing executes here, yet the refused
            // construct still reaches the output as live template source - which matters in
            // exactly the shadow-rendering setup this mode exists for - so it is recorded.
            $this->notePolicyInVerbatim($node, $context);
            return $node->fullRaw();
        }

        $handler = $this->handlers[$node->name()] ?? null;

        // The policy is consulted only once a handler exists. It removes a capability the
        // host granted; it must not change the output for a directive nobody wired up,
        // which would silently break compatible mode's parity.
        if ($handler !== null && !$context->policy()->permitsDirective($node->name())) {
            $this->refusedByPolicy($node, $context, PolicyViolation::DIRECTIVE, $node->name());
            return '';
        }

        if ($handler === null) {
            if ($this->options->strictDirectives) {
                throw UnknownDirectiveError::at(
                    $this->source,
                    $node->offset(),
                    sprintf('No handler registered for {{%s}}', $node->name()),
                    $this->directiveHint($node->name())
                );
            }
            // Lenient: emit verbatim. Never guess, never execute.
            return $node->fullRaw();
        }

        return $handler($node, $context, $this);
    }

    /**
     * Records the policy refusals inside a subtree that is being emitted verbatim.
     *
     * Emitting is not executing, so this changes no output; it exists so that a refused
     * directive cannot reach the rendered result without leaving a trace.
     */
    private function notePolicyInVerbatim(Node $node, Context $context): void
    {
        if (!$node instanceof DirectiveNode) {
            return;
        }

        $policy = $context->policy();

        if (isset($this->handlers[$node->name()]) && !$policy->permitsDirective($node->name())) {
            $this->refusedByPolicy($node, $context, PolicyViolation::DIRECTIVE, $node->name());
        } elseif ($node->name() === 'block') {
            $class = $this->parameters->parse($node->params())['class'] ?? '';
            if ($class !== '' && !$policy->permitsBlock($class)) {
                $this->refusedByPolicy($node, $context, PolicyViolation::BLOCK, $class);
            }
        }

        $branches = $node->hasAlternate()
            ? [...$node->children(), ...$node->alternate()]
            : $node->children();
        foreach ($branches as $child) {
            $this->notePolicyInVerbatim($child, $context);
        }
    }

    /**
     * Records - or raises on - something the render policy refused.
     *
     * Recording by default: a policy violation should not take down an order email, but it
     * must not pass unnoticed either.
     */
    public function refusedByPolicy(DirectiveNode $node, Context $context, string $kind, string $name): void
    {
        ['line' => $line, 'column' => $column] = Diagnostics::locate($this->source, $node->offset());

        if ($this->options->failOnPolicyViolation) {
            throw PolicyViolationError::at(
                $this->source,
                $node->offset(),
                sprintf('The render policy does not permit %s "%s"', $kind, $name),
                'grant it with RenderPolicy::allowing(...) or withAllowedBlocks(...)'
            );
        }

        $context->recordViolation(new PolicyViolation($kind, $name, $line, $column));
    }

    public function params(DirectiveNode $node): array
    {
        return $this->parameters->parse($node->params());
    }

    public function resolver(): VariableResolver
    {
        return $this->variables;
    }

    public function options(): Options
    {
        return $this->options;
    }

    public function spec(): DirectiveSpec
    {
        return $this->spec;
    }

    /** Safe string conversion - an object with no __toString yields '' rather than a fatal. */
    public function stringify(mixed $value): string
    {
        return $this->toStringValue($value);
    }

    private function registerDefaults(): void
    {
        $this->handlers['var'] = function (DirectiveNode $n, Context $c): string {
            [$expr, $modifiers] = $this->splitModifiers($n->params());
            $resolution = $this->resolveAt($expr, $c, $n);

            if (!$resolution->found) {
                $this->requireVariable($resolution, $n, $c, $expr);
            }

            $this->modifierOffset = $n->offset();

            // Legacy hands the RAW resolved value to the modifier chain, so the type each
            // function sees depends on what ran before it. Elsewhere the value is stringified
            // up front, which is safer and simpler.
            $carried = $this->options->legacyQuirks
                ? ($resolution->value ?? '')     // getVariable(..., '') defaults null to ''
                : $this->toStringValue($resolution->value);

            return $this->applyModifiers($carried, $modifiers, $c);
        };

        $this->handlers['if'] = function (DirectiveNode $n, Context $c, self $e): string {
            if ($this->condition($n, $c, 'if')) {
                return $e->renderNodes($n->children(), $c);
            }
            return $n->hasAlternate() ? $e->renderNodes($n->alternate(), $c) : '';
        };

        $this->handlers['depend'] = function (DirectiveNode $n, Context $c, self $e): string {
            return $this->condition($n, $c, 'depend') ? $e->renderNodes($n->children(), $c) : '';
        };

        $this->handlers['for'] = function (DirectiveNode $n, Context $c, self $e): string {
            if (!preg_match('/^\s*(\S+)\s+in\s+(\S+)\s*$/', $n->params(), $m)) {
                // `{{for i xs}}`, `{{for}}`, `{{for i IN xs}}` - rendering '' for these hides
                // a template that will never loop, which is the kind of defect strict mode
                // exists to surface. Compatible mode keeps quiet: legacy's ForDirective has
                // its own handling for a header it cannot parse, and parity outranks the
                // diagnostic there.
                if ($this->options->strictSyntax && !$this->options->legacyQuirks) {
                    throw SyntaxError::at(
                        $this->source,
                        $n->offset(),
                        sprintf('{{for %s}} is not a loop header', trim($n->params())),
                        'write it as {{for <item> in <collection>}}, with a lower-case "in"'
                    );
                }
                return '';
            }
            [$_, $item, $collection] = $m;
            $resolution = $this->resolveAt($collection, $c, $n);

            if (!$resolution->found) {
                $this->requireVariable($resolution, $n, $c, $collection);
                return '';
            }
            if (!is_iterable($resolution->value)) {
                if ($this->options->strictVariables) {
                    throw TemplateTypeError::at(
                        $this->source,
                        $n->offset(),
                        sprintf(
                            '{{for %s in %s}} needs something iterable, but %s is %s',
                            $item,
                            $collection,
                            $collection,
                            get_debug_type($resolution->value)
                        ),
                        'use an array or Traversable, or guard with {{depend ' . $collection . '}}'
                    );
                }
                return '';
            }
            $values = $resolution->value;

            // A Generator satisfies is_iterable() but throws from foreach when it has already
            // been consumed - two {{for}} loops over the same variable is all it takes. The
            // bare \Exception PHP raises is not a TemplateError, so it escapes lenient mode
            // and takes the render with it.
            if ($values instanceof \Generator) {
                try {
                    // rewind() is what foreach does first, and what actually raises on a
                    // generator that has already been walked.
                    $values->rewind();
                } catch (\Throwable $x) {
                    throw TemplateTypeError::at(
                        $this->source,
                        $n->offset(),
                        sprintf('{{for %s in %s}} cannot iterate: %s', $item, $collection, $x->getMessage()),
                        'a Generator can only be walked once - give the template an array'
                    );
                }
            }

            $out = '';
            foreach ($values as $value) {
                // The body gets its own scope for the loop variable, but its deferred work
                // and policy violations belong to the render - without absorbing them a
                // violation could be hidden simply by wrapping it in a loop.
                $iteration = $c->withVariables([$item => $value]);
                $out .= $e->renderNodes($n->children(), $iteration);
                $c->absorb($iteration);
            }
            return $out;
        };

        // {{else}} is consumed by the parser when it divides an {{if}}. A stray one renders
        // as nothing - except in compatible mode, where legacy hands it back verbatim: it
        // starts with a letter, so SimpleDirective captures the name and the resulting
        // InvalidArgumentException is caught.
        $this->handlers['else'] = fn (DirectiveNode $n): string =>
            $this->options->legacyQuirks ? $n->fullRaw() : '';

        // Structured deferral: recorded, not emitted. The caller decides what to do.
        $this->handlers['inlinecss'] = function (DirectiveNode $n, Context $c): string {
            $params = $this->parameters->parse($n->params());
            if (isset($params['file']) && $params['file'] !== '') {
                $c->defer('inlinecss', ['file' => $params['file']]);
            }
            return '';
        };

        $this->handlers['trans'] = function (DirectiveNode $n, Context $c): string {
            [$text, $args] = $this->splitTransParams($n->params());

            // One pass, not a str_replace per argument. Substituting in sequence has two
            // faults: `%name` rewrites the front of `%name_long` before its own turn comes,
            // and a value that happens to contain `%b` becomes a live placeholder for a
            // later argument - which would make a variable's value template syntax again,
            // the one thing this engine exists to prevent. strtr() takes the longest
            // matching key at each position and never re-scans what it has written.
            $map = [];
            foreach ($args as $k => $v) {
                $resolved = $this->resolveAt(ltrim($v, '$'), $c, $n)->value;
                $map['%' . $k] = $this->toStringValue($resolved ?? $v);
            }

            return $map === [] ? $text : strtr($text, $map);
        };
    }

    /**
     * Refuses - or records - a construct the legacy filter could not have rendered.
     *
     * Mirrors the parser's handling, so `refuseLegacyIncompatible` governs both.
     */
    private function noteLegacyIncompatible(Context $context, string $message, ?int $offset = null): void
    {
        $offset ??= $this->modifierOffset;

        if ($this->options->refuseLegacyIncompatible) {
            throw LegacyIncompatibleError::at(
                $this->source,
                $offset,
                $message,
                'this renders here but not on the legacy filter; unset '
                . 'Options::$refuseLegacyIncompatible to allow it'
            );
        }

        $context->noteIncompatibilities([
            LegacyIncompatibility::at(
                $this->source,
                $offset,
                LegacyIncompatibility::DEGENERATE_CONSTRUCT,
                $message
            ),
        ]);
    }

    /**
     * Splits `"some text" arg=$expr` into the literal and its arguments.
     *
     * @return array{0:string,1:array<string,string>}
     */
    public function splitTransParams(string $params): array
    {
        $raw = trim($params);
        if ($raw === '' || ($raw[0] !== '"' && $raw[0] !== "'")) {
            return [$raw, []];
        }

        $quote = $raw[0];
        $end = $this->findClosingQuote($raw, $quote);
        $text = $end === null ? substr($raw, 1) : substr($raw, 1, $end - 1);
        $text = str_replace('\\' . $quote, $quote, $text);
        $rest = $end === null ? '' : substr($raw, $end + 1);

        return [$text, $this->parameters->parse(trim($rest))];
    }

    /**
     * Evaluates a directive condition.
     *
     * The test itself is truthiness, matching what {{if}} and {{depend}} have always done.
     * A variable that does not resolve at all is a different matter: it is almost always a
     * typo, and silently taking the false branch is how those go unnoticed. Strict mode
     * reports it; lenient mode treats it as false.
     */
    private function condition(DirectiveNode $node, Context $context, string $directive): bool
    {
        $resolution = $this->resolveAt($node->params(), $context, $node);

        if (!$resolution->found) {
            $this->requireVariable($resolution, $node, $context, trim($node->params()), $directive);
            return false;
        }

        return $this->truthy($resolution->value);
    }

    /**
     * Resolves against the scope, giving an accessor failure the position of the directive
     * that triggered it - the resolver itself has no view of the source.
     */
    private function resolveAt(string $expression, Context $context, DirectiveNode $node): Resolution
    {
        try {
            return $this->variables->resolve($expression, $context);
        } catch (AccessorError $e) {
            throw AccessorError::at($this->source, $node->offset(), $e->problem, $e->hint);
        } catch (LegacyFatalShape $e) {
            $this->noteLegacyIncompatible($context, $e->getMessage(), $node->offset());
            return Resolution::of(null);
        }
    }

    private function requireVariable(
        Resolution $resolution,
        DirectiveNode $node,
        Context $context,
        string $expression,
        string $directive = 'var'
    ): void {
        if (!$this->options->strictVariables) {
            return;
        }

        $name = $resolution->failedAt !== '' ? $resolution->failedAt : $expression;
        throw UnknownVariableError::at(
            $this->source,
            $node->offset(),
            sprintf('Unknown variable "%s" in {{%s %s}}', $name, $directive, $expression),
            $this->variableHint($name, $context)
        );
    }

    private function directiveHint(string $name): ?string
    {
        $suggestion = Diagnostics::suggest($name, $this->registered());
        return $suggestion === null
            ? 'register a handler with Evaluator::register(), or use Options::lenient() to emit it as text'
            : sprintf('did you mean {{%s}}?', $suggestion);
    }

    private function variableHint(string $name, Context $context): ?string
    {
        $suggestion = Diagnostics::suggest($name, $context->names());
        if ($suggestion !== null) {
            return sprintf('did you mean {{var %s}}?', $suggestion);
        }
        $available = $context->names();
        sort($available);
        return $available === []
            ? 'no variables are in scope here'
            : 'variables in scope: ' . implode(', ', array_slice($available, 0, 12))
                . (count($available) > 12 ? ', …' : '');
    }

    private function findClosingQuote(string $s, string $quote): ?int
    {
        $len = strlen($s);
        for ($i = 1; $i < $len; $i++) {
            if ($s[$i] === '\\') {
                $i++;
                continue;
            }
            if ($s[$i] === $quote) {
                return $i;
            }
        }
        return null;
    }

    /** @return array{0:string,1:string[]} */
    private function splitModifiers(string $params): array
    {
        $parts = explode('|', $params);
        $expr = trim(array_shift($parts) ?? '');
        return [$expr, array_map('trim', $parts)];
    }

    /**
     * Applies {{var}} modifiers.
     *
     * Legacy semantics (Email\Model\Template\Filter::applyModifiers): a modifier list
     * REPLACES the default escaping, empty parts are skipped, and an unrecognised name is
     * skipped too - so `{{var x|typo}}` renders raw. That is reproduced faithfully in
     * compatible mode.
     *
     * Everywhere else it fails closed: unless `raw` or an explicit `escape` was asked for,
     * the value is escaped BEFORE the listed modifiers run, so `{{var x|nl2br}}` escapes the
     * value and then inserts real <br /> tags, and a typo cannot silently disable escaping.
     *
     * @param string[] $modifiers
     */
    private function applyModifiers(mixed $value, array $modifiers, Context $context): string
    {
        if ($modifiers === []) {
            return $this->escape($this->toStringValue($value));
        }

        /** @var list<array{0:string,1:string[]}> $parsed */
        $parsed = [];
        foreach ($modifiers as $modifier) {
            if ($modifier === '') {
                continue;               // legacy: if (empty($part)) { continue; }
            }
            $params = explode(':', $modifier);
            $parsed[] = [(string)array_shift($params), $params];
        }

        if (!$this->options->legacyQuirks) {
            $names = array_column($parsed, 0);
            $optsOut = array_intersect(['raw', 'escape'], array_map('strtolower', $names)) !== [];
            if (!$optsOut) {
                $value = $this->escape($this->toStringValue($value));
            }
        }

        foreach ($parsed as [$name, $params]) {
            // Legacy looks the modifier up case-sensitively; nothing else should.
            $lookup = $this->options->legacyQuirks ? $name : strtolower($name);

            if ($this->options->legacyQuirks && $lookup === 'nl2br' && $params !== []) {
                // applyModifiers passes the modifier's arguments straight through, so
                // nl2br($value, 'x') is a TypeError on $use_xhtml.
                $this->noteLegacyIncompatible(
                    $context,
                    'the |nl2br modifier is given arguments - the legacy filter passes them '
                    . 'to nl2br() and raises a TypeError here'
                );
            }

            if ($this->options->legacyQuirks && $lookup === 'nl2br' && !is_string($value)) {
                // Email\Model\Template\Filter declares strict_types, so nl2br() receives
                // whatever the previous modifier left and a non-string is a TypeError there.
                $this->noteLegacyIncompatible(
                    $context,
                    'the |nl2br modifier receives a non-string value - '
                    . 'the legacy filter raises a TypeError here'
                );
            }
            $value = match ($lookup) {
                'raw' => $value,
                'nl2br' => nl2br(is_string($value) ? $value : $this->toStringValue($value)),
                'escape' => $this->applyEscapeModifier($value, $params[0] ?? 'html', $context),
                default => $value,      // legacy skips an unknown modifier
            };
        }

        return $this->toStringValue($value);
    }

    /**
     * Mirrors Email\Model\Template\Filter::modifierEscape, whose 'html' case goes through
     * Magento's Escaper: ENT_QUOTES|ENT_SUBSTITUTE and double_encode disabled. Without
     * ENT_SUBSTITUTE an invalid UTF-8 byte makes htmlspecialchars return the empty string,
     * silently deleting the whole value.
     *
     * The default arm is html, NOT the value: an unrecognised type must never reach the
     * output unescaped. Compatible mode's fall-through lives in applyEscapeModifier, which
     * is the only caller that may legitimately skip escaping.
     *
     * htmlentities() is the one branch legacy calls with a bare ENT_QUOTES, which drops
     * ENT_SUBSTITUTE from PHP's default set and so returns '' for invalid UTF-8. That data
     * loss is reproduced only where parity demands it.
     */
    private function escape(string $value, string $type = 'html'): string
    {
        return match ($type) {
            'htmlentities' => $this->options->legacyQuirks
                ? htmlentities($value, ENT_QUOTES)
                : htmlentities($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'url' => rawurlencode($value),
            default => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false),
        };
    }

    /**
     * The `|escape` modifier.
     *
     * Legacy's modifierEscape is a switch with no default, so a type it does not know -
     * `escape:none`, `escape:javascript`, or the empty `escape:` - RETURNS THE VALUE
     * UNCHANGED. Writing an explicit escape modifier therefore disables escaping entirely.
     * That is reproduced in compatible mode and refused everywhere else, where an
     * unrecognised type falls back to html.
     *
     * Escaper::escapeHtml recurses into an array and hands back an array, which is why a
     * following |nl2br is a TypeError rather than receiving the string "Array". htmlentities()
     * and rawurlencode() get the raw value under strict_types, so a non-string is a TypeError
     * there too.
     */
    private function applyEscapeModifier(mixed $value, string $type, Context $context): mixed
    {
        if (!in_array($type, self::ESCAPE_TYPES, true)) {
            if ($this->options->legacyQuirks) {
                return $value;
            }
            $type = 'html';
        }

        if ($type === 'html') {
            if (is_array($value)) {
                return array_map(
                    fn (mixed $item): mixed => $this->applyEscapeModifier($item, 'html', $context),
                    $value
                );
            }

            return $this->escape($this->toStringValue($value), 'html');
        }

        if ($this->options->legacyQuirks && !is_string($value)) {
            $this->noteLegacyIncompatible(
                $context,
                sprintf(
                    'the |escape:%s modifier receives a non-string value - '
                    . 'the legacy filter raises a TypeError here',
                    $type
                )
            );
        }

        return $this->escape($this->toStringValue($value), $type);
    }

    private function toStringValue(mixed $value): string
    {
        if (is_array($value)) {
            // Legacy casts the array, emitting the literal string "Array" into the output
            // (with a PHP notice). Reproduced only in compatibility mode.
            return $this->options->legacyQuirks ? 'Array' : '';
        }
        if ($value === null || is_bool($value)) {
            return is_bool($value) ? ($value ? '1' : '') : '';
        }
        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string)$value : '';
        }
        return (string)$value;
    }

    /**
     * Standard PHP truthiness.
     *
     * The legacy filter tests `resolve(...) == ''`, which on PHP 8 makes 0, '0' and []
     * truthy — almost certainly not what a template author means by {{if qty}}. See
     * KnownDivergenceTest.
     */
    private function truthy(mixed $value): bool
    {
        if ($this->options->legacyQuirks) {
            // Exactly what IfDirective/DependDirective do: `resolve(...) == ''`.
            // On PHP 8 that makes 0, '0' and [] truthy.
            return !($value == '');
        }

        if (is_array($value)) {
            return $value !== [];
        }
        if (is_float($value)) {
            return $value != 0.0;      // in_array(..., true) never matches a float
        }
        return !in_array($value, [null, false, '', '0', 0], true);
    }
}
