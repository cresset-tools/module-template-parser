<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Lexer\Lexer;
use MageOS\TemplateParser\Lexer\TokenType;
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
        self::assertSame('class="A\\B" id=x', $tokens[0]->params);
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
