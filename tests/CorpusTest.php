<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\Parser;
use Cresset\TemplateParser\TemplateEngine;
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
        foreach (glob(self::CORPUS . '/*.html') ?: [] as $file) {
            $cases[basename($file)] = [$file];
        }
        return $cases;
    }

    public function testCorpusIsNotEmpty(): void
    {
        self::assertGreaterThan(30, count(self::corpusFiles()), 'corpus should hold the harvested templates');
    }

    /**
     * Lenient parsing must never fail on real content, and must actually find the directives.
     *
     * assertNotNull() on a non-nullable return is not an assertion - it passes against any
     * implementation at all, including one that returns an empty tree.
     */
    #[DataProvider('corpusFiles')]
    public function testParsesWithoutError(string $file): void
    {
        $source = (string)file_get_contents($file);
        $root = (new Parser(options: Options::lenient()))->parse($source);

        self::assertNotSame([], $root->children(), 'the corpus file parsed to nothing');

        if (str_contains($source, '{{')) {
            self::assertGreaterThan(
                0,
                self::countDirectives($root->children()),
                'a file containing {{ produced no directive nodes'
            );
        }
    }

    /** @param \Cresset\TemplateParser\Ast\Node[] $nodes */
    private static function countDirectives(array $nodes): int
    {
        $found = 0;
        foreach ($nodes as $node) {
            if ($node instanceof \Cresset\TemplateParser\Ast\DirectiveNode) {
                $found++;
                $found += self::countDirectives($node->children());
                if ($node->hasAlternate()) {
                    $found += self::countDirectives($node->alternate());
                }
            }
        }
        return $found;
    }

    /** Parsing must be lossless: the AST reproduces the file byte for byte. */
    #[DataProvider('corpusFiles')]
    public function testParseIsLossless(string $file): void
    {
        $source = (string)file_get_contents($file);
        self::assertSame($source, (new Parser(options: Options::lenient()))->parse($source)->raw());
    }

    /**
     * Rendering with no variables must not throw, must not emit PHP, and must actually
     * produce the template's literal text.
     *
     * assertIsString() on a `: string` return can never fail; it passed against a renderer
     * that returned '' for everything.
     */
    #[DataProvider('corpusFiles')]
    public function testRendersWithoutError(string $file): void
    {
        $source = (string)file_get_contents($file);
        $engine = TemplateEngine::lenient();
        $out = $engine->render($source, [], new Context([]));

        self::assertStringNotContainsString('<?php', $out);

        if (trim($source) !== '') {
            self::assertNotSame('', $out, 'a non-empty template rendered to nothing');
        }

        // Every literal run outside a directive has to survive into the output.
        foreach (self::literalRuns($source) as $literal) {
            self::assertStringContainsString($literal, $out, 'literal text was lost: ' . $literal);
        }

        self::assertSame($out, $engine->render($source, [], new Context([])), 'rendering is not deterministic');
    }

    /**
     * Reasonably long literal runs from the source, which lenient rendering must preserve.
     *
     * @return string[]
     */
    private static function literalRuns(string $source): array
    {
        $runs = [];
        foreach ((new Parser(options: Options::lenient()))->parse($source)->children() as $node) {
            if (!$node instanceof \Cresset\TemplateParser\Ast\TextNode) {
                continue;
            }
            foreach (preg_split('/\s+/', $node->text()) ?: [] as $word) {
                if (strlen($word) >= 12 && !str_contains($word, '{{')) {
                    $runs[] = $word;
                }
            }
        }
        return array_slice(array_unique($runs), 0, 5);
    }

    /**
     * Every directive name found across the corpus, so a regression in the lexer's name
     * handling shows up as a missing name rather than silently becoming text.
     */
    public function testKnownDirectiveNamesAreLexedAsDirectives(): void
    {
        $lexer = new \Cresset\TemplateParser\Lexer\Lexer();
        $seen = [];
        foreach (self::corpusFiles() as [$file]) {
            foreach ($lexer->tokenize((string)file_get_contents($file)) as $token) {
                if ($token->type !== \Cresset\TemplateParser\Lexer\TokenType::Text) {
                    $seen[$token->name] = true;
                }
            }
        }
        foreach (['trans', 'depend', 'var', 'template', 'layout', 'if', 'store', 'inlinecss', 'css'] as $expected) {
            self::assertArrayHasKey($expected, $seen, "corpus should exercise {{{$expected}}}");
        }
    }
}
