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

        if (!$context->policy()->permitsDirective($node->name())) {
            $this->refusedByPolicy($node, $context, PolicyViolation::DIRECTIVE, $node->name());
            return '';
        }

        $handler = $this->handlers[$node->name()] ?? null;
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

    private function registerDefaults(): void
    {
        $this->handlers['var'] = function (DirectiveNode $n, Context $c): string {
            [$expr, $modifiers] = $this->splitModifiers($n->params());
            $resolution = $this->variables->resolve($expr, $c);

            if (!$resolution->found) {
                $this->requireVariable($resolution, $n, $c, $expr);
            }

            return $this->applyModifiers($this->stringify($resolution->value), $modifiers);
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
                $out .= $e->renderNodes($n->children(), $c->withVariables([$item => $value]));
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
                $text = str_replace('%' . $k, $this->stringify($resolved ?? $v), $text);
            }
            return $text;
        };
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

    /** @param string[] $modifiers */
    private function applyModifiers(string $value, array $modifiers): string
    {
        if ($modifiers === []) {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
        foreach ($modifiers as $modifier) {
            $value = match (strtolower($modifier)) {
                'raw' => $value,
                'nl2br' => nl2br($value),
                'escape' => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
                default => $value,
            };
        }
        return $value;
    }

    private function stringify(mixed $value): string
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
        return !in_array($value, [null, false, '', '0', 0], true);
    }
}
