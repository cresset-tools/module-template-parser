<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Context;
use MageOS\TemplateParser\Options;
use MageOS\TemplateParser\Parser;
use MageOS\TemplateParser\TemplateEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the parser over real templates harvested from the Magento/Mage-OS tree.
 *
 * These are the shapes merchants actually have stored, including .html files whose `{{`
 * sequences are not directives at all. The bar is: never throw, never lose content, never
 * execute anything that was not written in the template.
 */
final class CorpusTest extends TestCase
{
    private const CORPUS = __DIR__ . '/fixtures/corpus';

    public static function corpusFiles(): array
    {
        $cases = [];
        foreach (glob(self::CORPUS . '/*') ?: [] as $file) {
            $cases[basename($file)] = [$file];
        }
        return $cases;
    }

    public function testCorpusIsNotEmpty(): void
    {
        self::assertGreaterThan(30, count(self::corpusFiles()), 'corpus should hold the harvested templates');
    }

    /** Lenient parsing must never fail on real content. */
    #[DataProvider('corpusFiles')]
    public function testParsesWithoutError(string $file): void
    {
        $source = (string)file_get_contents($file);
        $root = (new Parser(options: Options::lenient()))->parse($source);
        self::assertNotNull($root);
    }

    /** Parsing must be lossless: the AST reproduces the file byte for byte. */
    #[DataProvider('corpusFiles')]
    public function testParseIsLossless(string $file): void
    {
        $source = (string)file_get_contents($file);
        self::assertSame($source, (new Parser(options: Options::lenient()))->parse($source)->raw());
    }

    /** Rendering with no variables must not throw, and must not emit PHP. */
    #[DataProvider('corpusFiles')]
    public function testRendersWithoutError(string $file): void
    {
        $source = (string)file_get_contents($file);
        $out = TemplateEngine::lenient()->render($source, [], new Context([]));
        self::assertIsString($out);
        self::assertStringNotContainsString('<?php', $out);
    }

    /**
     * Every directive name found across the corpus, so a regression in the lexer's name
     * handling shows up as a missing name rather than silently becoming text.
     */
    public function testKnownDirectiveNamesAreLexedAsDirectives(): void
    {
        $lexer = new \MageOS\TemplateParser\Lexer\Lexer();
        $seen = [];
        foreach (self::corpusFiles() as [$file]) {
            foreach ($lexer->tokenize((string)file_get_contents($file)) as $token) {
                if ($token->type !== \MageOS\TemplateParser\Lexer\TokenType::Text) {
                    $seen[$token->name] = true;
                }
            }
        }
        foreach (['trans', 'depend', 'var', 'template', 'layout', 'if', 'store', 'inlinecss', 'css'] as $expected) {
            self::assertArrayHasKey($expected, $seen, "corpus should exercise {{{$expected}}}");
        }
    }
}
