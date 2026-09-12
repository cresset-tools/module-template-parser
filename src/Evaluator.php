<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

use Cresset\TemplateParser\Ast\DirectiveNode;
use Cresset\TemplateParser\Ast\Node;
use Cresset\TemplateParser\Ast\RootNode;
use Cresset\TemplateParser\Ast\TextNode;

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

    /**
     * Email\Model\Template\Filter::TRANS_DIRECTIVE_REGEX, verbatim but for the pointless /i.
     *
     * `[^\1]` is not a backreference - inside a character class `\1` is the octal escape for
     * chr(1) - so it reads "any character except chr(1)", which is every character a template
     * will ever hold. The /s is for the trailing `.*`, not for the class: a class matches
     * newlines either way. Kept as legacy writes it so the one input it does refuse is
     * refused here too.
     */
    private const TRANS_BODY_PATTERN = '/^\s*([\'"])([^\1]*?)(?<!\\\)\1(\s.*)?$/s';

    /** @var array<string,callable(DirectiveNode, Context, self):string> */
    private array $handlers = [];

    private string $source = '';

    /* Nullable, not `= new X()` - a constructor default is fatal under the DI compiler. See TemplateEngine. */
    private readonly VariableResolver $variables;

    private readonly ParameterParser $parameters;

    private readonly DirectiveSpec $spec;

    private readonly Options $options;

    public function __construct(
        ?VariableResolver $variables = null,
        ?ParameterParser $parameters = null,
        ?DirectiveSpec $spec = null,
        ?Options $options = null
    ) {
        $this->options = $options ?? new Options();
        // Derived from the options, not defaulted on their own. An Evaluator built with
        // compatible options and a default resolver is a compatible evaluator with a strict
        // resolver inside it - which is neither mode, and is exactly what the CLI was
        // building: `--mode=compatible` reproduced the directive quirks and none of the
        // variable or parameter ones.
        $this->variables = $variables ?? new VariableResolver($this->options->legacyQuirks);
        // ParameterParser takes no mode: how a blob splits into parameters is structure, not
        // policy, and it is the filter's structure in every mode. It used to take the flag,
        // and TemplateFilterAdapter - the class that actually ships inside Magento - forgot
        // to pass it, so the drop-in was the one place compatible mode was not compatible.
        $this->parameters = $parameters ?? new ParameterParser();
        $this->spec = $spec ?? new DirectiveSpec();
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

        return $this->neutralizeOutput($handler($node, $context, $this), $node);
    }

    /**
     * Encodes directive openers in a resolved directive's output.
     *
     * Reproduces Mage-OS's Template\DirectiveOutputNeutralizer, added with the StyleSmuggler
     * hardening. Legacy applies it to the output of every directive that actually resolved -
     * `$replacedValue !== $construction[0]` - so a variable whose value contains `{{` renders
     * with the braces encoded.
     *
     * This engine does not need it: a value is never re-parsed here, whatever the setting.
     * It exists so compatible mode still matches the filter byte for byte now the hardening
     * has landed. The signed-span handling upstream has no analogue here, because nothing is
     * ever signed - deferral is structured.
     */
    private function neutralizeOutput(string $output, DirectiveNode $node): string
    {
        if (!$this->options->legacyQuirks
            || !$this->options->neutralizeDirectiveOutput
            || $output === ''
            || !str_contains($output, '{')
            || $output === $node->fullRaw()
        ) {
            return $output;
        }

        $output = str_replace('{{', '&#123;&#123;', $output);

        // A single brace at either edge is encoded too: it could pair with an adjacent one
        // once this output sits next to its neighbours.
        if ($output[0] === '{') {
            $output = '&#123;' . substr($output, 1);
        }
        if (str_ends_with($output, '{')) {
            $output = substr($output, 0, -1) . '&#123;';
        }

        return $output;
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

    /**
     * Parses a directive's parameters, resolving `$name` values against the scope.
     *
     * Legacy does this in Template::getParameters(), which every parameterised directive
     * goes through - so `{{css file=$f}}`, `{{block class=$c}}` and the rest all read a
     * variable, and a value that does NOT start with `$` is a literal and stays one.
     * Both halves of that matter: this engine used to resolve every value, which made
     * `{{trans "Hi %n" n="Acme"}}` emit the contents of a variable called `Acme`.
     *
     * Pass the context to get that resolution. Without one the values come back raw, which
     * is what the callers that only inspect a directive - rather than render it - want.
     */
    public function params(DirectiveNode $node, ?Context $context = null): array
    {
        $params = $this->parameters->parse($node->params());

        return $context === null ? $params : $this->resolveParameterValues($params, $context, $node);
    }

    /**
     * Applies legacy's `$name` rule to an already-parsed parameter list.
     *
     * An unresolved name becomes null, as it does there, and that is observable rather than
     * tidy: `{{store url='...' _query_id=$user.user_id}}` with no `user` in scope reaches
     * http_build_query() as null, which drops the parameter, where '' would have produced
     * `?id=`. Call sites read their parameters with `?? ''`, so null and '' are the same to
     * them; only the ones that pass a parameter list onward can tell.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function resolveParameterValues(array $params, Context $context, DirectiveNode $node): array
    {
        foreach ($params as $key => $value) {
            if (!is_string($value) || !str_starts_with($value, '$')) {
                continue;
            }

            $expression = substr($value, 1);
            $resolution = $this->resolveAt($expression, $context, $node);

            if ($resolution->value === null) {
                // Legacy renders a parameter it cannot resolve as nothing, silently. Strict
                // mode says so instead: a subject line reading "Welcome to " because
                // `$store` was never passed is the exact bug this mode exists to surface.
                $this->requireVariable($resolution, $node, $context, $expression, $node->name());
                $params[$key] = null;
                continue;
            }

            $params[$key] = $this->toStringValue($resolution->value);
        }

        return $params;
    }

    /*
     * What a directive handler is given.
     *
     * A handler receives the node, the context and this object, so everything below is public
     * API and frozen at the first tag: params(), renderNodes(), renderTrans(), stringify(),
     * escapeValue(), refusedByPolicy(), resolver(), options(), spec(). HostDirectives uses
     * seven of the nine; the other two are here for a host writing its own handler, which is
     * the whole point of `register()` being public.
     */

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

    /**
     * Stringify and html-escape a value, for handlers that insert one into their output.
     *
     * {{var}} gets this through applyModifiers; any other directive that emits a resolved
     * value needs it explicitly, or that directive becomes the way to get an unescaped value
     * into the page.
     *
     * The two shipped handlers that do not use it, {{config}} and {{customvar}}, are matching
     * the filter deliberately - configDirective() and customvarDirective() both return their
     * value raw, and neither value comes from template text.
     */
    public function escapeValue(mixed $value): string
    {
        return $this->escape($this->toStringValue($value));
    }

    private function registerDefaults(): void
    {
        $this->handlers['var'] = function (DirectiveNode $n, Context $c): string {
            [$expr, $modifiers] = $this->splitModifiers($n->params());
            $resolution = $this->resolveAt($expr, $c, $n);

            if (!$resolution->found) {
                $this->requireVariable($resolution, $n, $c, $expr);
            }

            // Legacy hands the RAW resolved value to the modifier chain, so the type each
            // function sees depends on what ran before it. Elsewhere the value is stringified
            // up front, which is safer and simpler.
            $carried = $this->options->legacyQuirks
                ? ($resolution->value ?? '')     // getVariable(..., '') defaults null to ''
                : $this->toStringValue($resolution->value);

            return $this->applyModifiers($carried, $modifiers, $c, $n->offset());
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
            if (is_object($values)) {
                // Not just Generator. Any Traversable is host code: an IteratorAggregate
                // whose getIterator() throws, or an Iterator whose current() throws. A
                // Magento collection that fails to load is exactly this shape.
                try {
                    $values = iterator_to_array($values, false);
                } catch (\Throwable $x) {
                    throw TemplateTypeError::at(
                        $this->source,
                        $n->offset(),
                        sprintf('{{for %s in %s}} cannot iterate: %s', $item, $collection, $x->getMessage()),
                        'a Generator can only be walked once; any other collection here failed to load'
                    );
                }
            }

            $out = '';
            $index = 0;
            foreach ($values as $value) {
                // The body gets its own scope for the loop variable, but its deferred work
                // and policy violations belong to the render - without absorbing them a
                // violation could be hidden simply by wrapping it in a loop.
                //
                // `loop` alongside it, because ForDirective injects one and templates use it.
                // Zero-based, as `setData('index', $loopIndex++)` there is - a template that
                // prints it would otherwise be off by one, and one that renders nothing at all
                // here would be the silent kind of regression this engine exists to avoid.
                // Legacy overwrites any `loop` already in scope, so this does too.
                $iteration = $c->withVariables([$item => $value, 'loop' => ['index' => $index++]]);
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
            // A stylesheet has nothing to inline into a text/plain body, and the filter
            // returns '' here rather than deferring. Deferring anyway would hand the host a
            // stylesheet to run Emogrifier over a document that is not HTML.
            if ($c->plainText()) {
                return '';
            }

            $params = $this->parameters->parse($n->params());
            $file = $params['file'] ?? '';
            // Guarded like {{css file=}} is. Deferring is still handing a path to the host,
            // and PathGuard's own contract is that the handlers apply it "so a host cannot
            // forget it" - this was the one handler that passed a path outward without it.
            if ($file !== '' && PathGuard::isSafeRelativePath($file)) {
                $c->defer('inlinecss', ['file' => $file]);
            }
            return '';
        };

        $this->handlers['trans'] = fn (DirectiveNode $n, Context $c): string => $this->renderTrans(
            $n,
            $c,
            // One pass, not a str_replace per argument. Substituting in sequence has two
            // faults: `%name` rewrites the front of `%name_long` before its own turn comes,
            // and a value that happens to contain `%b` becomes a live placeholder for a
            // later argument - which would make a variable's value template syntax again,
            // the one thing this engine exists to prevent. strtr() takes the longest
            // matching key at each position and never re-scans what it has written.
            static fn (string $text, array $args): string => $args === [] ? $text : strtr($text, $args)
        );
    }

    /**
     * Refuses - or records - a construct the legacy filter could not have rendered.
     *
     * Mirrors the parser's handling, so `refuseLegacyIncompatible` governs both.
     */
    private function noteLegacyIncompatible(Context $context, string $message, int $offset): void
    {
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
     * The placeholder an argument key stands for.
     *
     * Phrase\Renderer\Placeholder::keyToPlaceholder() adds one to an INTEGER key, because
     * `__('%1', $a)` numbers its positional arguments from one while PHP numbers the array
     * it collects them into from zero. A template's arguments go through the same code, and
     * PHP has already turned the tokenizer's '1' into int 1 by the time they get there - so
     * `{{trans "%1" 1=$x}}` fills in %2 and leaves %1 standing, and `{{trans "%2" 1=$x}}` is
     * the one that works. Named arguments are unaffected.
     */
    private static function placeholderFor(string|int $key): string
    {
        return '%' . (is_int($key) ? $key + 1 : $key);
    }

    /**
     * Renders a {{trans}} directive - body, modifiers, arguments and all.
     *
     * The translation step is the caller's, because the built-in handler has no translator
     * and a host that does substitutes through its own; everything around it is identical
     * and belongs in one place.
     *
     * Modifiers apply to the whole translated result and default to `escape`, which is
     * where {{trans}}'s escaping comes from - for the substituted values AND for the text:
     *   {{var x}}              -> &lt;img src=x onerror=...&gt;
     *   {{trans "Hi %n" n=$x}} -> Hi &lt;img src=x onerror=...&gt;
     * Escaping only the values would be the smaller change but leaves the text raw, and the
     * text is not always written by whoever wrote the template - it is the msgid, so what
     * reaches the page is a translation out of the i18n files or the translation table.
     *
     * @param callable(string,array<string,string>):string $translate keyed by placeholder
     */
    public function renderTrans(DirectiveNode $node, Context $context, callable $translate): string
    {
        [$body, $modifiers] = $this->splitTransModifiers($node->params());
        [$text, $args] = $this->splitTransParams($body);

        // legacy: `if (empty($text)) { return ''; }`, so {{trans "0"}} renders nothing.
        if (empty($text)) {
            return '';
        }

        $resolved = [];
        foreach ($this->resolveParameterValues($args, $context, $node) as $key => $value) {
            // toStringValue(), not a cast - an object with no __toString is a perfectly
            // ordinary template variable and must not be a fatal.
            $resolved[self::placeholderFor($key)] = $this->toStringValue($value);
        }

        return $this->applyModifiers($translate($text, $resolved), $modifiers, $context, $node->offset());
    }

    /**
     * Splits a {{trans}} body from its modifiers, the way explodeModifiers() does.
     *
     * On the FIRST `|` anywhere in the body - including one inside the quoted text, which
     * then leaves the text unterminated and makes the whole directive render nothing.
     * Reproduced rather than corrected: it decides output, and splitting anywhere else
     * would make `{{trans "a|b"}}` render differently here than on the filter this engine
     * is measured against.
     *
     * The `['escape']` default is legacy's, not a hardening choice here: transDirective()
     * calls explodeModifiers($construction[2], 'escape').
     *
     * @return array{0:string,1:string[]}
     */
    private function splitTransModifiers(string $body): array
    {
        $parts = explode('|', $body, 2);

        return count($parts) === 2 ? [$parts[0], explode('|', $parts[1])] : [$body, ['escape']];
    }

    /**
     * Splits `"some text" arg=$expr` into the literal and its arguments.
     *
     * Mirrors Email\Model\Template\Filter::getTransParameters(), refusals included: the
     * body has to be a quoted string and its arguments have to be separated from it by
     * whitespace. Anything else is not treated as the text - it renders nothing at all.
     *
     * @return array{0:string,1:array<string,string>}
     */
    public function splitTransParams(string $params): array
    {
        if (preg_match(self::TRANS_BODY_PATTERN, $params, $matches) !== 1) {
            return ['', []];
        }

        // stripslashes(), not just unescaping the quote: legacy runs the whole literal
        // through it, so a `\n` written in a template renders as the letter n.
        $text = stripslashes($matches[2]);

        // Untrimmed, and `empty()` rather than a comparison, both as getTransParameters() has
        // them: the argument blob keeps the whitespace the regex captured, so `a= ` ends the
        // value at the space instead of leaving `=` as the last character.
        $rest = $matches[3] ?? '';

        return [$text, empty($rest) ? [] : $this->parameters->parse($rest)];
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

    /**
     * Splits `expr|mod|mod` the way explodeModifiers() plus applyModifiers() do.
     *
     * The expression is trimmed because the variable tokenizer skips whitespace anywhere in a
     * path. The modifier NAMES are not, in compatible mode: applyModifiers() looks each one up
     * with `isset($this->_modifiers[$part])`, so `escape ` is not found and is silently
     * skipped - taking the escaping with it. Outside compatible mode they are trimmed, so a
     * stray space cannot cost a template its escaping here.
     *
     * @return array{0:string,1:string[]}
     */
    private function splitModifiers(string $params): array
    {
        $parts = explode('|', $params);
        $expr = trim(array_shift($parts) ?? '');

        return [$expr, $this->options->legacyQuirks ? $parts : array_map('trim', $parts)];
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
     * @param int $offset position of the directive whose modifiers these are, for diagnostics
     */
    private function applyModifiers(mixed $value, array $modifiers, Context $context, int $offset): string
    {
        if ($modifiers === []) {
            // Through applyEscapeModifier, not straight to escape(): `escape` is what
            // varDirective defaults to, so the no-modifier path has to behave exactly like
            // an explicit one - including recursing into an array, which is where legacy
            // dies on an element it cannot cast.
            return $this->toStringValue($this->applyEscapeModifier($value, 'html', $context, $offset));
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
                    . 'to nl2br() and raises a TypeError here',
                    $offset
                );
            }

            if ($this->options->legacyQuirks && $lookup === 'nl2br' && !is_string($value)) {
                // Email\Model\Template\Filter declares strict_types, so nl2br() receives
                // whatever the previous modifier left and a non-string is a TypeError there.
                $this->noteLegacyIncompatible(
                    $context,
                    'the |nl2br modifier receives a non-string value - '
                    . 'the legacy filter raises a TypeError here',
                    $offset
                );
            }
            $value = match ($lookup) {
                'raw' => $value,
                'nl2br' => nl2br(is_string($value) ? $value : $this->toStringValue($value)),
                'escape' => $this->applyEscapeModifier($value, $params[0] ?? 'html', $context, $offset),
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
    private function applyEscapeModifier(mixed $value, string $type, Context $context, int $offset): mixed
    {
        if (!in_array($type, self::ESCAPE_TYPES, true)) {
            if ($this->options->legacyQuirks) {
                return $value;
            }
            $type = 'html';
        }

        if ($type === 'html') {
            if (is_array($value)) {
                if ($this->options->legacyQuirks) {
                    // Escaper::escapeHtml recurses and casts each element, so an element that
                    // is an object without __toString is an Error there - and `escape` is
                    // varDirective's DEFAULT modifier, so plain `{{var a}}` over such an
                    // array is a legacy fatal with no modifier written at all.
                    $this->refuseUnstringableElements($value, $context, $offset);
                }

                return array_map(
                    fn (mixed $item): mixed => $this->applyEscapeModifier($item, 'html', $context, $offset),
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
                ),
                $offset
            );
        }

        return $this->escape($this->toStringValue($value), $type);
    }

    /**
     * Notes an array element the legacy escaper could not have cast to a string.
     *
     * @param array<mixed> $value
     */
    private function refuseUnstringableElements(array $value, Context $context, int $offset): void
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                $this->refuseUnstringableElements($item, $context, $offset);
                continue;
            }
            if (is_object($item) && !method_exists($item, '__toString')) {
                $this->noteLegacyIncompatible(
                    $context,
                    sprintf(
                        'the value is an array holding a %s, which the legacy escaper casts '
                        . 'to string and dies on',
                        $item::class
                    ),
                    $offset
                );
                return;
            }
        }
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
     * The legacy filter tests `resolve(...) == ''`, which on PHP 8 makes 0, 0.0, '0' and []
     * truthy — almost certainly not what a template author means by {{if qty}}. See
     * KnownDivergenceTest.
     */
    private function truthy(mixed $value): bool
    {
        if ($this->options->legacyQuirks) {
            // Exactly what IfDirective and DependDirective do.
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
