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

    /** Safe string conversion - an object with no __toString yields '' rather than a fatal. */
    public function stringify(mixed $value): string
    {
        return $this->toStringValue($value);
    }

    private function registerDefaults(): void
    {
        $this->handlers['var'] = function (DirectiveNode $n, Context $c): string {
            [$expr, $modifiers] = $this->splitModifiers($n->params());
            $resolution = $this->variables->resolve($expr, $c);

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
                return '';
            }
            [$_, $item, $collection] = $m;
            $resolution = $this->variables->resolve($collection, $c);

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
            foreach ($args as $k => $v) {
                $resolved = $this->variables->value(ltrim($v, '$'), $c);
                $text = str_replace('%' . $k, $this->toStringValue($resolved ?? $v), $text);
            }
            return $text;
        };
    }

    /**
     * Refuses - or records - a construct the legacy filter could not have rendered.
     *
     * Mirrors the parser's handling, so `refuseLegacyIncompatible` governs both.
     */
    private function noteLegacyIncompatible(Context $context, string $message): void
    {
        if ($this->options->refuseLegacyIncompatible) {
            throw LegacyIncompatibleError::at(
                $this->source,
                $this->modifierOffset,
                $message,
                'this renders here but not on the legacy filter; unset '
                . 'Options::$refuseLegacyIncompatible to allow it'
            );
        }

        $context->noteIncompatibilities([
            LegacyIncompatibility::at(
                $this->source,
                $this->modifierOffset,
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
        $resolution = $this->variables->resolve($node->params(), $context);

        if (!$resolution->found) {
            $this->requireVariable($resolution, $node, $context, trim($node->params()), $directive);
            return false;
        }

        return $this->truthy($resolution->value);
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
                'escape' => $this->escape($this->toStringValue($value), $params[0] ?? 'html'),
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
     */
    private function escape(string $value, string $type = 'html'): string
    {
        return match ($type) {
            'htmlentities' => htmlentities($value, ENT_QUOTES),
            'url' => rawurlencode($value),
            'html' => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false),
            default => $value,
        };
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
