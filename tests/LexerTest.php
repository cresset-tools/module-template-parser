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
            'translation string' => ['{{Forgot Your Password?}}'],
            'sentence'           => ['{{Are you sure you want to delete this "product"?}}'],
            'empty braces'       => ['{{}}'],
            'unterminated'       => ['{{var x'],
            'just braces'        => ['a {{ b }} c'],
            'uppercase word'     => ['{{Password}}'],
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
