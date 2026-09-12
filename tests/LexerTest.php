<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Lexer\Lexer;
use Cresset\TemplateParser\Lexer\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LexerTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    public function testPlainTextIsOneToken(): void
    {
        $tokens = $this->lexer->tokenize('hello world');
        self::assertCount(1, $tokens);
        self::assertSame(TokenType::Text, $tokens[0]->type);
    }

    public function testDirectiveNameAndParamsAreSeparated(): void
    {
        $tokens = $this->lexer->tokenize('{{block class="A\\B" id=x}}');
        self::assertCount(1, $tokens);
        self::assertSame(TokenType::DirectiveOpen, $tokens[0]->type);
        self::assertSame('block', $tokens[0]->name);
        // Untrimmed, leading space and all: legacy's $construction[2] is the raw remainder,
        // and whitespace at the edges decides whether a modifier is recognised.
        self::assertSame(' class="A\\B" id=x', $tokens[0]->params);
    }

    public function testClosingTag(): void
    {
        $tokens = $this->lexer->tokenize('{{/if}}');
        self::assertSame(TokenType::DirectiveClose, $tokens[0]->type);
        self::assertSame('if', $tokens[0]->name);
    }

    /** Real .html files contain {{ that is not a directive; it must stay text. */
    #[DataProvider('nonDirectives')]
    public function testNonDirectivesStayText(string $source): void
    {
        $tokens = $this->lexer->tokenize($source);
        foreach ($tokens as $token) {
            self::assertSame(TokenType::Text, $token->type, 'unexpectedly lexed as a directive: ' . $source);
        }
        self::assertSame($source, implode('', array_map(static fn ($t) => $t->raw, $tokens)));
    }

    public static function nonDirectives(): array
    {
        return [
            // These begin with a letter, so legacy hands them back verbatim too.
            'translation string' => ['{{Forgot Your Password?}}'],
            'sentence'           => ['{{Are you sure you want to delete this "product"?}}'],
            'uppercase word'     => ['{{Password}}'],
            'unterminated'       => ['{{var x'],
        ];
    }

    /**
     * A `{{...}}` not starting with a letter is neither a directive nor plain text: legacy
     * raises a TypeError on it, so it is tracked separately for compatible mode to refuse.
     */
    #[DataProvider('degenerateConstructs')]
    public function testDegenerateConstructsAreTrackedSeparately(string $source): void
    {
        $tokens = $this->lexer->tokenize($source);
        $types = array_map(static fn ($t) => $t->type, $tokens);

        self::assertContains(TokenType::Degenerate, $types, 'expected a degenerate token: ' . $source);
        self::assertSame($source, implode('', array_map(static fn ($t) => $t->raw, $tokens)));
    }

    public static function degenerateConstructs(): array
    {
        return [
            'empty braces'  => ['{{}}'],
            'leading space' => ['a {{ b }} c'],
            'digits'        => ['{{100}}'],
            'punctuation'   => ['{{!!}}'],
            'underscore'    => ['{{_x}}'],
            'slash only'    => ['{{/}}'],
        ];
    }

    /** A well-formed closing tag is never mistaken for a degenerate construct. */
    public function testClosingTagsAreNotDegenerate(): void
    {
        foreach (['{{/if}}', '{{/depend}}', '{{if a}}x{{/if}}'] as $source) {
            foreach ($this->lexer->tokenize($source) as $token) {
                self::assertNotSame(TokenType::Degenerate, $token->type, $source);
            }
        }
    }

    /**
     * Where the construct ends when a quoted parameter holds a `}}`.
     *
     * Two answers, and the seam between them was never measured. An UNESCAPED quote leaves an
     * odd count before the naive closer, so the lexer walks the span and the construct runs to
     * the closer outside the quotes - the declared divergence from the legacy filter, whose
     * `(.*?)}}` stops at the first one wherever it falls. An ESCAPED quote leaves the count
     * even, so there is no walk and the construct ends at that first `}}` - which is where the
     * filter ends it too.
     *
     * Measured against a real filter: the first row diverges (declared, recorded as a corpus
     * case with the equality dropped) and the second agrees. Pinned because the agreement in
     * the second was an accident of the counting until this said so.
     */
    #[DataProvider('quotedClosers')]
    public function testAQuotedCloserEndsTheConstructWhereTheCountingSaysItDoes(
        string $source,
        string $firstRaw
    ): void {
        self::assertSame($firstRaw, $this->lexer->tokenize($source)[0]->raw);
    }

    public static function quotedClosers(): array
    {
        return [
            // The quote is open at the naive closer, so the walk runs and finds the real one.
            'unescaped quote' => ['{{trans "a}}b"}} tail', '{{trans "a}}b"}}'],
            'single quotes'   => ["{{trans 'a}}b'}} tail", "{{trans 'a}}b'}}"],
            // The escaped quote does not open anything, so the first `}}` is the closer.
            'escaped quote'   => ['{{trans "a\\"b}}c"}} tail', '{{trans "a\\"b}}'],
            // A backslash with no quote at all must not reach the walk either.
            'bare backslash'  => ['{{var a\\b}} tail', '{{var a\\b}}'],
        ];
    }

    /** Round-tripping every token must reproduce the source exactly. */
    #[DataProvider('roundTripSamples')]
    public function testRoundTrip(string $source): void
    {
        $tokens = $this->lexer->tokenize($source);
        self::assertSame($source, implode('', array_map(static fn ($t) => $t->raw, $tokens)));
    }

    public static function roundTripSamples(): array
    {
        return [
            ['pre {{var x}} post'],
            ['{{if a}}yes{{else}}no{{/if}}'],
            ['{{depend x}}{{var y|escape}}{{/depend}}'],
            ['text only'],
            [''],
            ['{{var a}}{{var b}}{{var c}}'],
            ['{{trans "Hello %name" name=$x}}'],
        ];
    }
}
