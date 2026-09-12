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

    /* The CONSTRUCTOR parameter is nullable, not `= new DirectiveSpec()`: a constructor
     * default is fatal under the DI compiler. See TemplateEngine. */
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
        // Before any refusal is raised: the spans decide which of them apply.
        $this->findLoopBodies($source);
        $this->refuseLegacyParsingDifferences($source);

        $tokens = $this->lexer->tokenize($source);
        $this->refuseRunOnsLegacyDiesOn();
        $index = 0;
        // A variable, because parseUntil() takes the stack by reference so that nesting costs
        // one entry rather than a copy per level.
        $openStack = [];
        // parseUntil() consumes every token at top level: its two early returns are a close
        // matching $closingName, which is null here and a token's name never is, and a close
        // naming something on $openStack, which is empty here and balanced by parseOpen()'s
        // finally. A stray close at top level is emitted as text and the scan carries on.
        $children = $this->parseUntil($tokens, $index, null, $openStack);

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

        // An optionally-paired directive is a block only when it is actually closed, which is
        // what `(?:(?P<content>.*?){{\/(?P=directiveName)}})?` means: the body is optional and
        // LAZY, so it runs to the first matching close or the construct has no body at all.
        // Deciding it here rather than in the lexer keeps the lookahead over tokens, where a
        // close is already a token rather than a string that might be one.
        $paired = $this->spec->isBlock($token->name)
            || ($this->spec->isOptionalBlock($token->name) && self::closesLater($tokens, $index, $token->name));

        if (!$paired) {
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

    /**
     * The legacy filter's own loop pattern, which is what decides where a body begins and ends.
     *
     * Lazy on both halves, so it runs from the first `{{for …}}` to the FIRST `{{/for}}` - and
     * that is the behaviour, not an approximation of it. A nested loop leaves the outer
     * `{{/for}}` stranded outside any body, which is why the filter dies on nested loops.
     */
    private const LEGACY_LOOP_PATTERN =
        '/{{for(?P<loopItem>.*? )(in)(?P<loopData>.*?)}}(?P<loopBody>.*?){{\/for}}/si';

    /** The opening half of LEGACY_LOOP_PATTERN, anchored, for asking about one construct. */
    private const LEGACY_LOOP_OPENER = '/^{{for.*? in.*?}}/si';

    /** @var list<array{0:int,1:int}> half-open [from, to) offsets of each loop body */
    private array $loopBodySpans = [];

    /**
     * Nothing inside a `{{for}}` body reaches a directive processor, so nothing there can be a
     * legacy fatal.
     *
     * ForDirective does not render its body. It runs CONSTRUCTION_PATTERN over the raw text and
     * str_replaces each construct it finds with the VARIABLE RESOLUTION of that construct's
     * parameter text - so `{{}}`, `{{/if}}`, `{{var.a}}` and `{{var1 x}}` are all just variable
     * reads of `''`, `/if`, `.a` and `1 x`, every one of which resolves to nothing and renders.
     * ProcessorPool::get() is never called, so the TypeError this engine was warning about
     * cannot happen there.
     *
     * A refusal here would assert a crash the filter does not have, which is the failure mode
     * testNoRefusalClaimsACrashTheFilterDoesNotHave guards against.
     *
     * The exemption stops exactly where the filter's does. An UNCLOSED `{{for}}` matches no
     * loop pattern, so its contents are ordinary source and still fatal; so is anything after
     * a `{{/for}}`; and so is the outer `{{/for}}` of a nested pair, which is left stranded by
     * the lazy body and is why the filter dies on nested loops.
     */
    private function insideLoopBody(int $offset): bool
    {
        $bodyEnd = null;
        foreach ($this->loopBodySpans as [$from, $to]) {
            if ($offset >= $from && $offset < $to) {
                $bodyEnd = $to;
                break;
            }
        }

        if ($bodyEnd === null) {
            return false;
        }

        // With ONE exception, and it is the construct that creates the body in the first
        // place. A nested loop opener strands the outer `{{/for}}`: LEGACY_LOOP_PATTERN is
        // lazy on both halves, so the match runs from the outer opener to the INNER closer
        // and the outer one is left outside any body, where it captures an empty directive
        // name and raises. So the filter cannot express a nested loop at all, and a nested
        // opener is exactly the thing that must still be refused. Everything else in a loop
        // body is a variable read that renders.
        //
        // An OPENER, matched, not a name starting `for`. `{{format x in rows}}` opens a loop
        // to that pattern and `{{for2 a}}` does not, and a prefix test refused `{{for2 a}}`
        // here - claiming a crash the filter does not have, where it renders as an empty
        // variable read. The window stops at the body's end because a match beyond it would
        // not be nested inside this loop.
        return preg_match(
            self::LEGACY_LOOP_OPENER,
            substr($this->source, $offset, $bodyEnd - $offset)
        ) !== 1;
    }

    /** Walked one match at a time: a match table over a large source is how this once OOMed. */
    private function findLoopBodies(string $source): void
    {
        $this->loopBodySpans = [];

        if (stripos($source, '{{for') === false) {
            return;
        }

        foreach ($this->matches(self::LEGACY_LOOP_PATTERN, $source) as $match) {
            // The named group is also numbered; 4 is loopBody in the pattern above.
            [$body, $offset] = $match['loopBody'] ?? $match[4] ?? ['', -1];
            if ($offset >= 0) {
                $this->loopBodySpans[] = [$offset, $offset + strlen($body)];
            }
        }
    }

    /**
     * Whether a matching close for this name appears later in the token stream.
     *
     * The FIRST one, with no regard for nesting, because that is what a lazy body does: in
     * `{{mydir}}a{{mydir}}b{{/mydir}}c{{/mydir}}` the filter pairs the first opener with the
     * first closer and leaves the rest as text. Looking for a balanced pair would read that
     * template differently from the store.
     *
     * @param Token[] $tokens
     */
    private static function closesLater(array $tokens, int $index, string $name): bool
    {
        for ($i = $index + 1, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i]->type === TokenType::DirectiveClose && $tokens[$i]->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refuses, or records, a construct the legacy filter cannot render.
     *
     * Only meaningful in compatible mode: strict already rejects most of these as syntax
     * errors, and lenient deliberately recovers from them.
     */
    private function refuseIfLegacyCannotRender(int $offset, string $kind, string $message): void
    {
        if (!$this->options->legacyQuirks) {
            return;
        }

        if ($this->insideLoopBody($offset)) {
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

        if (!in_array($token->name, $openStack, true)) {
            return;
        }

        $this->refuseIfLegacyCannotRender(
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
     * @return array{0: Node[], 1: Node[], 2: string}|null
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
