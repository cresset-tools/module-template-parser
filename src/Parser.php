<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

use MageOS\TemplateParser\Ast\DirectiveNode;
use MageOS\TemplateParser\Ast\Node;
use MageOS\TemplateParser\Ast\RootNode;
use MageOS\TemplateParser\Ast\TextNode;
use MageOS\TemplateParser\Lexer\Lexer;
use MageOS\TemplateParser\Lexer\Token;
use MageOS\TemplateParser\Lexer\TokenType;

/**
 * Builds an AST from template source.
 *
 * Strict by default: an unclosed block, a stray closing tag or an unknown directive name is
 * reported with its position and a source excerpt. Lenient mode degrades the same cases to
 * literal text, which is what rendering already-stored content requires.
 */
final class Parser
{
    /** The deepest nesting the legacy filter can render: two levels, with differing names. */
    private const LEGACY_MAX_NESTING = 2;

    private string $source = '';

    /** Effective bound for the parse in progress; the Options value unless overridden. */
    private int $maxNestingDepth = Options::DEFAULT_MAX_NESTING_DEPTH;

    /** @var LegacyIncompatibility[] */
    private array $incompatibilities = [];

    public function __construct(
        private readonly DirectiveSpec $spec = new DirectiveSpec(),
        private readonly Options $options = new Options(),
        private readonly Lexer $lexer = new Lexer()
    ) {
    }

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
        $tokens = $this->lexer->tokenize($source);
        $index = 0;
        $children = $this->parseUntil($tokens, $index, null, []);

        while ($index < count($tokens)) {
            // Only reachable in lenient mode: a stray close stopped the top-level scan.
            $children[] = new TextNode($tokens[$index]->raw);
            $index++;
            $children = array_merge($children, $this->parseUntil($tokens, $index, null, []));
        }

        $this->assertNoStrayElse($children);

        return new RootNode($children, $source, $this->incompatibilities);
    }

    /**
     * @param Token[] $tokens
     * @param string[] $openStack block directives currently open, outermost first
     * @return Node[]
     */
    private function parseUntil(array $tokens, int &$index, ?string $closingName, array $openStack): array
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
                        '%s does not start with a letter - the legacy filter raises a TypeError here',
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
                        '{{/%s}} closes nothing - the legacy filter raises a TypeError here',
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
    private function parseOpen(array $tokens, int &$index, Token $token, array $openStack): Node
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

        $body = $this->parseUntil($tokens, $index, $token->name, [...$openStack, $token->name]);

        if ($this->spec->acceptsElse($token->name)) {
            $split = $this->splitOnElse($body);
            if ($split !== null) {
                [$body, $alternate] = $split;
                $node->setAlternate($alternate);
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
            sprintf('{{%s}} is never closed - the legacy filter raises a TypeError here', $token->name)
        );

        return new UnclosedDirective($node);
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
     * a TypeError. In practice that means: two levels maximum, and the two names must differ.
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
                    '{{%s}} nested inside {{%s}} - the legacy filter raises a TypeError here',
                    $token->name,
                    $token->name
                )
            );
        } elseif (count($openStack) >= self::LEGACY_MAX_NESTING) {
            $incompatibility = LegacyIncompatibility::at(
                $this->source,
                $token->offset,
                LegacyIncompatibility::NESTING_DEPTH,
                sprintf(
                    '{{%s}} is %d levels deep - the legacy filter manages at most %d',
                    $token->name,
                    count($openStack) + 1,
                    self::LEGACY_MAX_NESTING
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
    private function assertNoStrayElse(array $nodes): void
    {
        if (!$this->options->strictSyntax) {
            return;
        }

        foreach ($nodes as $node) {
            if ($node instanceof DirectiveNode && $node->name() === 'else') {
                throw SyntaxError::at(
                    $this->source,
                    $node->offset(),
                    '{{else}} outside of an {{if}}',
                    'only {{if}} has an alternate branch; {{depend}} and {{for}} do not'
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
            if ($node instanceof DirectiveNode && $node->name() === 'else') {
                return [array_slice($body, 0, $i), array_slice($body, $i + 1)];
            }
        }
        return null;
    }
}
