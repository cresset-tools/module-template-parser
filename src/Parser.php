<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

use Cresset\TemplateParser\Ast\DirectiveNode;
use Cresset\TemplateParser\Ast\Node;
use Cresset\TemplateParser\Ast\RootNode;
use Cresset\TemplateParser\Ast\TextNode;
use Cresset\TemplateParser\Lexer\Lexer;
use Cresset\TemplateParser\Lexer\Token;
use Cresset\TemplateParser\Lexer\TokenType;

/**
 * Builds an AST from template source.
 *
 * Strict by default: an unclosed block, a stray closing tag or an unknown directive name is
 * reported with its position and a source excerpt. Lenient mode degrades the same cases to
 * literal text, which is what rendering already-stored content requires.
 */
final class Parser
{
    private string $source = '';

    /** Effective bound for the parse in progress; the Options value unless overridden. */
    private int $maxNestingDepth = Options::DEFAULT_MAX_NESTING_DEPTH;

    /** @var LegacyIncompatibility[] */
    private array $incompatibilities = [];

    /*
     * Nullable, not `= new X()`.
     *
     * Magento's DI compiler stores a constructor default verbatim and writes it into
     * generated/metadata with var_export(), which emits `X::__set_state(...)` for an object.
     * No class here defines __set_state, so that file - loaded on every request in
     * production mode - is a fatal. Developer mode consumes the default directly and never
     * notices, so this shipped green.
     */
    private readonly DirectiveSpec $spec;

    private readonly Options $options;

    public function __construct(?DirectiveSpec $spec = null, ?Options $options = null, ?Lexer $lexer = null)
    {
        $this->spec = $spec ?? new DirectiveSpec();
        $this->options = $options ?? new Options();
        $this->lexer = $lexer ?? new Lexer($this->spec);
    }

    private readonly Lexer $lexer;

    /**
     * @param int|null $maxNestingDepth per-render override; null uses the engine default
     */
    public function parse(string $source, ?int $maxNestingDepth = null): RootNode
    {
        if ($maxNestingDepth !== null && $maxNestingDepth < 1) {
            throw new \InvalidArgumentException('maxNestingDepth must be at least 1');
        }

        $this->source = $source;
        $this->maxNestingDepth = $maxNestingDepth ?? $this->options->maxNestingDepth;
        $this->incompatibilities = [];
        $this->refuseLegacyParsingDifferences($source);

        $tokens = $this->lexer->tokenize($source);
        $this->refuseRunOnsLegacyDiesOn();
        $index = 0;
        // A variable, because parseUntil() takes the stack by reference so that nesting costs
        // one entry rather than a copy per level.
        $openStack = [];
        $children = $this->parseUntil($tokens, $index, null, $openStack);

        while ($index < count($tokens)) {
            // Only reachable in lenient mode: a stray close stopped the top-level scan.
            $children[] = new TextNode($tokens[$index]->raw);
            $index++;
            $children = array_merge($children, $this->parseUntil($tokens, $index, null, $openStack));
        }

        $this->assertNoStrayElse($children);
        $this->refuseStrayClosingTagsInText($children, $source);

        return new RootNode($children, $source, $this->incompatibilities);
    }

    /**
     * @param Token[] $tokens
     * @param string[] $openStack block directives currently open, outermost first
     * @return Node[]
     */
    private function parseUntil(array $tokens, int &$index, ?string $closingName, array &$openStack): array
    {
        $nodes = [];
        $count = count($tokens);

        while ($index < $count) {
            $token = $tokens[$index];

            if ($token->type === TokenType::Text) {
                $nodes[] = new TextNode($token->raw);
                $index++;
                continue;
            }

            if ($token->type === TokenType::Degenerate) {
                $this->refuseIfLegacyCannotRender(
                    $token->offset,
                    LegacyIncompatibility::DEGENERATE_CONSTRUCT,
                    sprintf(
                        // This claim IS unconditional, unlike the others nearby. The lexer
                        // only reaches Degenerate when no second `{{` opens inside the span -
                        // the case where legacy would rescue the construct through
                        // SimpleDirective is routed to text instead - so what is left here
                        // captures an empty directive name and reaches
                        // ProcessorPool::get(null) every time.
                        '%s does not start with a letter - the legacy filter captures an empty '
                        . 'directive name and raises a TypeError here',
                        trim($token->raw)
                    )
                );
                $nodes[] = new TextNode($token->raw);
                $index++;
                continue;
            }

            if ($token->type === TokenType::DirectiveClose) {
                if ($token->name === $closingName) {
                    return $nodes;
                }
                if (in_array($token->name, $openStack, true)) {
                    return $nodes;   // closes a block open further out
                }
                if ($this->options->strictSyntax) {
                    throw SyntaxError::at(
                        $this->source,
                        $token->offset,
                        sprintf('Unexpected closing directive {{/%s}} — nothing is open here', $token->name),
                        $this->closingHint($token->name, $openStack)
                    );
                }
                $this->refuseIfLegacyCannotRender(
                    $token->offset,
                    LegacyIncompatibility::STRAY_CLOSING_TAG,
                    sprintf(
                        '{{/%s}} closes nothing here - on the legacy filter its meaning depends '
                        . 'on text elsewhere in the template, which may pair it with an earlier '
                        . '{{%s}} or may leave it to raise',
                        $token->name,
                        $token->name
                    )
                );
                $nodes[] = new TextNode($token->raw);
                $index++;
                continue;
            }

            $nodes[] = $this->parseOpen($tokens, $index, $token, $openStack);
        }

        return $nodes;
    }

    /** @param Token[] $tokens */
    private function parseOpen(array $tokens, int &$index, Token $token, array &$openStack): Node
    {
        if ($this->options->strictDirectives && !$this->spec->isKnown($token->name)) {
            throw SyntaxError::at(
                $this->source,
                $token->offset,
                sprintf('Unknown directive {{%s}}', $token->name),
                $this->nameHint($token->name)
            );
        }

        if (!$this->spec->isBlock($token->name)) {
            $index++;
            return new DirectiveNode($token->name, $token->params, $token->raw, $token->offset);
        }

        $this->noteLegacyNesting($token, $openStack);

        if (count($openStack) >= $this->maxNestingDepth) {
            throw NestingLimitError::at(
                $this->source,
                $token->offset,
                sprintf(
                    'Nesting limit exceeded: {{%s}} would be %d levels deep, limit is %d',
                    $token->name,
                    count($openStack) + 1,
                    $this->maxNestingDepth
                ),
                sprintf(
                    'enclosing directives are %s; raise it with Options::withMaxNestingDepth() if intentional',
                    implode(' > ', array_map(static fn (string $n): string => '{{' . $n . '}}', $openStack))
                )
            );
        }

        $node = new DirectiveNode($token->name, $token->params, $token->raw, $token->offset);
        $index++;

        // Push and pop, not `[...$openStack, $name]`. That built a fresh array per level, so
        // every open directive held its own copy and peak memory was O(depth squared): 4 000
        // levels of a 58 KB template reached 178 MB, and 10 000 exhausted a gigabyte. The
        // default depth of 3 never noticed, but Options::withMaxNestingDepth() has no upper
        // bound, so a host raising it for a "trusted" template type inherited the footgun.
        $openStack[] = $token->name;

        try {
            $body = $this->parseUntil($tokens, $index, $token->name, $openStack);
        } finally {
            array_pop($openStack);
        }

        if ($this->spec->acceptsElse($token->name)) {
            $split = $this->splitOnElse($body);
            if ($split !== null) {
                [$body, $alternate, $elseRaw] = $split;
                // A second {{else}} in the same {{if}} is a mistake, not a second branch:
                // without this it is silently accepted and both branches run into one.
                $this->assertNoStrayElse($alternate, 'a second {{else}} in one {{if}}');
                $node->setAlternate($alternate, $elseRaw);
            }
        }
        if (!$this->spec->acceptsElse($token->name)) {
            $this->assertNoStrayElse($body);
        }
        $node->setChildren($body);

        if ($index < count($tokens)
            && $tokens[$index]->type === TokenType::DirectiveClose
            && $tokens[$index]->name === $token->name
        ) {
            $node->setClosingRaw($tokens[$index]->raw);
            $index++;
            return $node;
        }

        if ($this->options->strictSyntax) {
            throw SyntaxError::at(
                $this->source,
                $token->offset,
                sprintf('Unclosed directive {{%s}} — expected {{/%s}}', $token->name, $token->name),
                sprintf('add {{/%s}} to close it, or remove the opening tag', $token->name)
            );
        }

        $this->refuseIfLegacyCannotRender(
            $token->offset,
            LegacyIncompatibility::UNCLOSED_BLOCK,
            sprintf(
                '{{%s}} is never closed here - the legacy filter finds its closing tag with a '
                . 'separate pattern, so whether this renders there depends on text further on',
                $token->name
            )
        );

        return new UnclosedDirective($node);
    }

    /**
     * Refuses, or records, a construct the legacy filter cannot render.
     *
     * Only meaningful in compatible mode: strict already rejects most of these as syntax
     * errors, and lenient deliberately recovers from them.
     */
    /**
     * Constructs the legacy filter reads differently from this parser.
     *
     * Both cases come from CONSTRUCTION_PATTERN, `/\{\{([a-z]{0,10})(.*?)\}\}.../si`:
     *
     *  - The name is a greedy run of letters, so it ends at the first non-letter rather than
     *    at whitespace. `{{if_a}}` is therefore `if` with the parameter `_a`, which reaches
     *    IfDirective and raises a TypeError; `{{var.a}}` is `var` with `.a`, which is a live
     *    variable read. This parser requires whitespace or `}` after a name, so it sees
     *    neither - it hands the whole thing back as text, which is safe but is not what the
     *    old filter did.
     *  - The closing group is a backreference, `\{\{\/(?:\1)\}\}`, with no allowance for
     *    whitespace - while CONSTRUCTION_IF_PATTERN does allow it. So `{{/if }}` matches one
     *    pattern and not the other, and IfDirective is handed an empty match: a TypeError.
     *
     * Refused rather than reproduced. Emitting them as text is already the safe behaviour;
     * what compatible mode must not do is stay quiet about a template whose meaning changes.
     */
    private function refuseLegacyParsingDifferences(string $source): void
    {
        if (!$this->options->legacyQuirks) {
            return;
        }

        // (?![a-zA-Z]) stops the name capture backtracking. `[a-z]{1,10}` is greedy, so on
        // `{{ifx a}}` it gave up the `x` and reported an `if`-prefix split that legacy never
        // performs - legacy's own capture is greedy too, and its name there really is `ifx`.
        // The bug fired for exactly one extra letter, so `{{vars}}` and `{{blocks}}` were
        // refused while `{{variable}}` was not.
        //
        // [a-zA-Z], not [a-z]: CONSTRUCTION_PATTERN carries /si and LegacyDirective
        // dispatches by reflection, which resolves case-insensitively, so `{{VAR.x}}` is a
        // live variable read on the legacy filter. Matching only lower case made compatible
        // mode MORE permissive than the filter it reproduces.
        foreach ($this->matches('/\{\{([a-zA-Z]{1,10})(?![a-zA-Z])([^\s}])/', $source) as $match) {
            [$name, $offset] = $match[1];
            if (!$this->spec->isKnown(strtolower($name))) {
                continue;                   // legacy would not dispatch it either
            }
            $this->refuseIfLegacyCannotRender(
                $offset - 2,
                LegacyIncompatibility::NAME_PREFIX_SPLIT,
                sprintf(
                    '{{%s%s...}} - the legacy filter reads the name as "%s" and the rest as '
                    . 'its parameters, which is a different construct from this one',
                    $name,
                    $match[2][0],
                    $name
                )
            );
        }

        foreach ($this->matches('/\{\{\/([a-zA-Z]{1,10})\s+\}\}/', $source) as $match) {
            [$name, $offset] = $match[1];
            if (!$this->spec->isKnown(strtolower($name))) {
                continue;
            }
            $this->refuseIfLegacyCannotRender(
                $offset - 3,
                LegacyIncompatibility::PADDED_CLOSING_TAG,
                sprintf(
                    '{{/%s }} has whitespace before the braces - the legacy filter\'s closing '
                    . 'backreference does not allow it, so this closes nothing there. Alone it '
                    . 'raises; with another well-formed {{/%s}} later in the template it renders '
                    . 'instead, which is why it is refused either way',
                    $name,
                    $name
                )
            );
        }
    }

    /**
     * A `{{/…}}` this parser did not consume as a closing tag is a legacy fatal.
     *
     * CONSTRUCTION_PATTERN captures the name as `[a-z]{0,10}`, so a leading `/` leaves it
     * EMPTY. LegacyDirective then reflects `Directive`, gets a ReflectionException, falls
     * back to SimpleDirective - whose own pattern requires `{{[a-z]` and cannot match a
     * close - and hands `ProcessorPool::get(null)` a null. Uncaught TypeError, every time,
     * for every spelling.
     *
     * This engine already refused the spellings its lexer recognised as closes: `{{/if}}`,
     * `{{/if }}`, `{{/}}`. The ones it did not - `{{/A}}`, `{{/Items}}`, `{{/a b}}`,
     * `{{/a.b}}`, `{{/var a}}`, `{{/a/}}` - fell through to text and rendered, which is a
     * fail-open hole in the claim this project states without qualification: nothing the
     * legacy filter crashes on is rendered here.
     *
     * Walking the TREE rather than the source: a close that was consumed became structure
     * and is not in a text node, so whatever is still spelled `{{/…}}` here is one legacy
     * would have died on. A `{{/` with no `}}` after it is left alone - CONSTRUCTION_PATTERN
     * needs the closer to match at all.
     *
     * @param Node[] $nodes
     */
    private function refuseStrayClosingTagsInText(array $nodes, string $source): void
    {
        if (!$this->options->legacyQuirks || !str_contains($source, '{{/')) {
            return;
        }

        foreach ($nodes as $node) {
            if ($node instanceof DirectiveNode) {
                $this->refuseStrayClosingTagsInText($node->children(), $source);
                if ($node->hasAlternate()) {
                    $this->refuseStrayClosingTagsInText($node->alternate(), $source);
                }
                continue;
            }
            if (!$node instanceof TextNode) {
                continue;
            }

            foreach ($this->matches('/\{\{\/[^{}]*\}\}/', $node->text()) as $match) {
                $spelling = $match[0][0];
                $at = strpos($source, $spelling);

                $this->refuseIfLegacyCannotRender(
                    $at === false ? 0 : $at,
                    LegacyIncompatibility::STRAY_CLOSING_TAG,
                    sprintf(
                        '%s is not a closing tag this parser paired - on the legacy filter it '
                        . 'captures an empty directive name and raises, unless an opener of the '
                        . 'same name earlier in the template swallows it first',
                        $spelling
                    )
                );
            }
        }
    }

    /**
     * Walks a pattern's matches one at a time.
     *
     * Not preg_match_all(PREG_OFFSET_CAPTURE): that builds a capture table for every match
     * in the whole source before the first one is read, and in compatible mode the loops
     * above throw on the first match they care about. 4 MB of `{{var.` allocated 527 MB to
     * report a single error - a fatal at Magento's usual 768 MB limit, from a template a
     * merchant can paste into a CMS block.
     *
     * @return \Generator<int,array<int,array{0:string,1:int}>>
     */
    private function matches(string $pattern, string $source): \Generator
    {
        $offset = 0;
        $length = strlen($source);

        while ($offset < $length && preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            yield $match;

            // max(1, ...) so a pattern that could match empty cannot spin here. Neither of
            // these can, but the next one added might.
            $offset = $match[0][1] + max(1, strlen($match[0][0]));
        }
    }

    /**
     * A run-on that costs the legacy filter a paired directive is a legacy fatal.
     *
     * One missing brace at top level, with a later `}}` in the document. This engine reads
     * the opener that never closed as text and renders the intact directive after it - the
     * ruling already made for `{{A{{var x}}`. The regex reads the whole stretch as one
     * construct, and what that stretch contains decides whether the filter merely loses
     * output or dies:
     *
     *   `Hi {{var a}, bye {{var a}}`   swallowed, renders `Hi ` - a divergence, not a crash
     *   `A{{if a} B {{var a}} C`       `if` with no body group - TypeError
     *   `Hi {{var a}, {{if a}}Y{{/if}}` the `{{if}}` opener is swallowed, so `{{/if}}` is a
     *                                  close with no opener: an empty name, and a TypeError
     *
     * So the test is whether a PAIRED directive is involved - as the run-on candidate itself,
     * or as an opener inside the stretch whose closer is then left dangling. A void directive
     * has no body group to be missing and no closer to orphan, which is why the first line
     * above renders. Those stay a declared divergence; these are refused, because rendering
     * what the old filter died on is inventing behaviour rather than reproducing it.
     */
    private function refuseRunOnsLegacyDiesOn(): void
    {
        if (!$this->options->legacyQuirks) {
            return;
        }

        foreach ($this->lexer->runOns() as [$offset, $name, $span]) {
            $culprit = $this->spec->isBlock($name) ? $name : $this->pairedOpenerIn($span);
            if ($culprit === null) {
                continue;
            }

            $this->refuseIfLegacyCannotRender(
                $offset,
                LegacyIncompatibility::RUN_ON_CONSTRUCT,
                sprintf(
                    '{{%s at offset %d never closes, and the legacy filter reads everything up '
                    . 'to the next }} as one construct - which leaves {{%s}} without %s and '
                    . 'raises a TypeError. The brace is the bug; add it',
                    $name,
                    $offset,
                    $culprit,
                    $this->spec->isBlock($name) ? 'a body' : 'its opening tag'
                )
            );
        }
    }

    /** The first paired directive opened inside a swallowed span, if any. */
    private function pairedOpenerIn(string $span): ?string
    {
        foreach ($this->matches('/\{\{([a-zA-Z]{1,10})\b/', $span) as $match) {
            $name = strtolower($match[1][0]);
            if ($this->spec->isBlock($name)) {
                return $name;
            }
        }

        return null;
    }

    private function refuseIfLegacyCannotRender(int $offset, string $kind, string $message): void
    {
        if (!$this->options->legacyQuirks) {
            return;
        }

        if ($this->options->refuseLegacyIncompatible) {
            throw LegacyIncompatibleError::at(
                $this->source,
                $offset,
                $message,
                'this renders here but not on the legacy filter; unset '
                . 'Options::$refuseLegacyIncompatible to allow it'
            );
        }

        $this->incompatibilities[] = LegacyIncompatibility::at($this->source, $offset, $kind, $message);
    }

    /**
     * Records nesting the legacy filter cannot render.
     *
     * Its per-directive regexes use a lazy body, so an outer {{depend}} stops at the FIRST
     * {{/depend}} and the fragment it hands on carries an unclosed inner one - which ends in
     * a TypeError. The constraint that follows is about REPEATED NAMES, not depth: a
     * directive cannot contain itself, at any distance.
     *
     * This used to refuse anything three levels deep, which was simply wrong. Verified
     * against the real filter, all six orderings of {{if}}, {{depend}} and {{for}} render
     * three deep without a fatal, and {{for}} in particular re-matches its own construction
     * rather than inheriting the lazy-body problem. Three is only the practical ceiling
     * because there are three body-taking directives to choose from.
     *
     * @param string[] $openStack
     */
    private function noteLegacyNesting(Token $token, array $openStack): void
    {
        if (!$this->options->legacyQuirks || $openStack === []) {
            return;
        }

        $incompatibility = null;
        if (in_array($token->name, $openStack, true)) {
            $incompatibility = LegacyIncompatibility::at(
                $this->source,
                $token->offset,
                LegacyIncompatibility::SAME_NAME_NESTING,
                sprintf(
                    '{{%s}} nested inside {{%s}} - the legacy filter cannot nest a directive in '
                    . 'itself: its lazy body match ends the outer construct at the INNER closing '
                    . 'tag, so what renders there is not the structure written here',
                    $token->name,
                    $token->name
                )
            );
        }

        if ($incompatibility === null) {
            return;
        }

        $this->refuseIfLegacyCannotRender($token->offset, $incompatibility->kind, $incompatibility->message);
    }

    /**
     * {{else}} is only meaningful as the divider of an {{if}}. The parser removes it there;
     * anything left over is in a block that has no alternate branch, or at the top level.
     *
     * @param Node[] $nodes
     */
    private function assertNoStrayElse(array $nodes, ?string $problem = null): void
    {
        if (!$this->options->strictSyntax) {
            return;
        }

        foreach ($nodes as $node) {
            if ($node instanceof DirectiveNode && $node->name() === 'else') {
                throw SyntaxError::at(
                    $this->source,
                    $node->offset(),
                    $problem ?? '{{else}} outside of an {{if}}',
                    'only {{if}} has an alternate branch, and only one of them; '
                    . '{{depend}} and {{for}} have none'
                );
            }
        }
    }

    /** @param string[] $openStack */
    private function closingHint(string $name, array $openStack): ?string
    {
        if ($openStack !== []) {
            return sprintf('the innermost open directive is {{%s}}; did you mean {{/%s}}?', end($openStack), end($openStack));
        }
        return $this->spec->isBlock($name)
            ? sprintf('there is no matching {{%s}} before this point', $name)
            : sprintf('{{%s}} takes no closing tag', $name);
    }

    private function nameHint(string $name): ?string
    {
        $suggestion = Diagnostics::suggest($name, $this->spec->knownNames());
        return $suggestion === null
            ? 'register it with Evaluator::register(), or check the spelling'
            : sprintf('did you mean {{%s}}?', $suggestion);
    }

    /**
     * @param Node[] $body
     * @return array{0: Node[], 1: Node[]}|null
     */
    private function splitOnElse(array $body): ?array
    {
        foreach ($body as $i => $node) {
            if (!$node instanceof DirectiveNode || $node->name() !== 'else') {
                continue;
            }

            // CONSTRUCTION_IF_PATTERN spells the divider as the literal `({{else}}(.*?))?` -
            // no `\s*`, no parameters. So `{{else }}` is not a divider on the legacy filter
            // at all: it is text inside the TRUE branch, and the {{if}} has no false branch.
            //
            // Neither reading is worth having. Accepting it silently flips which branch is
            // emitted, in both directions - `{{if a}}A{{else }}B{{/if}}` renders `A` here and
            // `A{{else }}B` there - and reproducing legacy would bury a plain typo in output
            // nobody reads. A trailing space is a typo, so it is reported as one.
            if ($node->params() !== '') {
                throw SyntaxError::at(
                    $this->source,
                    $node->offset(),
                    sprintf('{{else%s}} is not a divider - {{else}} takes no parameters', $node->params()),
                    'the legacy filter matches the literal `{{else}}` only, so anything else '
                    . 'is text in the true branch and the {{if}} has no false branch at all'
                );
            }

            return [array_slice($body, 0, $i), array_slice($body, $i + 1), $node->raw()];
        }

        return null;
    }
}
