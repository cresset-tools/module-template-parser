<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Diagnostics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The caret has to land under the byte the error is about, or the excerpt argues for the
 * wrong thing. Compatible mode refuses by default, so this text is what a user sees first.
 */
final class DiagnosticsTest extends TestCase
{
    /**
     * locate() splits on "\n" and nothing else - deliberately, because indexing one byte with
     * strpos is what keeps a 2.7 MB template out of a \R regex over the whole source. excerpt()
     * has to split the same way, or the two disagree about which line is line three.
     */
    #[DataProvider('sourcesWithALineThreeCaret')]
    public function testTheCaretLandsUnderTheOffsetWhateverTheLineEndings(string $source, int $offset): void
    {
        $excerpt = Diagnostics::excerpt($source, $offset);
        $carets = array_values(array_filter(
            explode("\n", $excerpt),
            static fn (string $line): bool => str_contains($line, '^')
        ));

        self::assertCount(1, $carets);
        self::assertSame('    | ^', $carets[0], "in:\n" . $excerpt);
        self::assertStringContainsString('3 | HERE', $excerpt);
    }

    public static function sourcesWithALineThreeCaret(): array
    {
        return [
            // The line the caret belongs under is the third in every one of these, whatever
            // the bytes between the lines are.
            'unix'       => ["one\ntwo\nHERE\nfour", 8],
            'windows'    => ["one\r\ntwo\r\nHERE\r\nfour", 10],
            // \R would split these into more lines than locate() counts, walking the caret up.
            'bare cr'    => ["one\rtwo\nthree\nHERE", 14],
            'form feed'  => ["one\ftwo\nthree\nHERE", 14],
            'vertical'   => ["one\vtwo\nthree\nHERE", 14],
        ];
    }

    /** The CR of a CRLF pair is trimmed off the printed line, not left to move the terminal. */
    public function testCarriageReturnsAreNotPrinted(): void
    {
        self::assertStringNotContainsString("\r", Diagnostics::excerpt("one\r\ntwo\r\nthree", 5));
    }
}
