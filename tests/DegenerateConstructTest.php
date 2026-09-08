<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\LegacyIncompatibility;
use Cresset\TemplateParser\LegacyIncompatibleError;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\TemplateEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which `{{...}}` shapes the legacy filter can render, and which kill it.
 *
 * The dividing line is subtler than it looks. CONSTRUCTION_PATTERN is case-insensitive, so
 * `[a-z]{0,10}` captures a name from anything beginning with a letter - upper or lower. That
 * name fails to resolve, SimpleDirective is consulted, ProcessorPool::get() throws
 * InvalidArgumentException, and that IS caught: the construct comes back verbatim.
 *
 * Begin with a digit, space, slash, underscore or punctuation and no name is captured at
 * all. SimpleDirective is then handed a null directiveName, and ProcessorPool::get(null) is
 * a TypeError - which nothing catches.
 *
 * Every expectation below was verified against the real unpatched filter.
 */
final class DegenerateConstructTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Shapes legacy renders verbatim: we must render them identically.
    // ---------------------------------------------------------------------------

    #[DataProvider('legacyRenders')]
    public function testShapesLegacyRendersAreRenderedIdentically(string $template): void
    {
        foreach ([
            'compatible' => TemplateEngine::compatible(),
            'permissive' => TemplateEngine::withOptions(Options::compatible()->withRefuseLegacyIncompatible(false)),
            'lenient'    => TemplateEngine::lenient(),
        ] as $label => $engine) {
            self::assertSame($template, $engine->render($template, ['x' => 'X']), $label);
        }
    }

    public static function legacyRenders(): array
    {
        return [
            'lowercase word'      => ['{{color}}'],
            'lowercase colon'     => ['{{color:red}}'],
            'letters then digit'  => ['{{abc1}}'],
            'two letters'         => ['{{ab}}'],
            // NOTE: {{var}} with no expression is deliberately absent. It starts with a
            // letter, so it is not degenerate - but base Framework\Filter\Template returns
            // it verbatim (VarDirective bails on an empty $construction[2]) while
            // Email\Model\Template\Filter resolves it to ''. This engine targets Email.
            'uppercase word'      => ['{{Password}}'],
            'uppercase sentence'  => ['{{Forgot Your Password?}}'],
            'lowercase sentence'  => ['{{forgot your password?}}'],
            'css-like'            => ['a{{color:red}}b'],
            'sentence w/ quotes'  => ['{{Are you sure you want to delete this "product"?}}'],
        ];
    }

    // ---------------------------------------------------------------------------
    // Shapes legacy fatals on: compatible mode refuses them.
    // ---------------------------------------------------------------------------

    #[DataProvider('legacyFatals')]
    public function testShapesLegacyFatalsOnAreRefusedInCompatibleMode(string $template): void
    {
        $this->expectException(LegacyIncompatibleError::class);
        TemplateEngine::compatible()->render($template, ['x' => 'X']);
    }

    #[DataProvider('legacyFatals')]
    public function testTheRefusalNamesTheConstructAndExplainsWhy(string $template): void
    {
        try {
            TemplateEngine::compatible()->render($template, ['x' => 'X']);
            self::fail('expected refusal for ' . $template);
        } catch (LegacyIncompatibleError $e) {
            self::assertStringContainsString('does not start with a letter', $e->getMessage());
            self::assertStringContainsString('raises a TypeError', $e->getMessage());
            self::assertStringContainsString('refuseLegacyIncompatible', $e->getMessage());
        }
    }

    /** Opting out renders them and records the fact. */
    #[DataProvider('legacyFatals')]
    public function testPermissiveModeRendersThemAsTextAndReports(string $template): void
    {
        $context = new Context(['x' => 'X']);
        $engine = TemplateEngine::withOptions(Options::compatible()->withRefuseLegacyIncompatible(false));

        self::assertSame($template, $engine->render($template, [], $context));

        $kinds = array_map(static fn ($i) => $i->kind, $context->incompatibilities());
        self::assertContains(LegacyIncompatibility::DEGENERATE_CONSTRUCT, $kinds);
    }

    /** Outside compatible mode these are simply text - legacy fidelity is not the contract. */
    #[DataProvider('legacyFatals')]
    public function testNonCompatibleModesJustRenderThemAsText(string $template): void
    {
        self::assertSame($template, TemplateEngine::lenient()->render($template, ['x' => 'X']));
    }

    public static function legacyFatals(): array
    {
        return [
            'leading space'     => ['{{ var x }}'],
            'digits'            => ['{{100}}'],
            'digits in prose'   => ['Prices from {{100}} to {{200}}'],
            'digit then letters'=> ['{{1abc}}'],
            'empty braces'      => ['{{}}'],
            'punctuation'       => ['{{!!}}'],
            'underscore'        => ['{{_x}}'],
            'slash only'        => ['{{/}}'],
        ];
    }

    // ---------------------------------------------------------------------------
    // The other two legacy fatal conditions, which the parser already detects.
    // ---------------------------------------------------------------------------

    public function testStrayClosingTagIsRefusedInCompatibleMode(): void
    {
        $this->expectException(LegacyIncompatibleError::class);
        $this->expectExceptionMessageMatches('/\{\{\/if\}\} closes nothing/');
        TemplateEngine::compatible()->render('a{{/if}}b', ['a' => 1]);
    }

    public function testUnclosedBlockIsRefusedInCompatibleMode(): void
    {
        $this->expectException(LegacyIncompatibleError::class);
        $this->expectExceptionMessageMatches('/\{\{if\}\} is never closed/');
        TemplateEngine::compatible()->render('a{{if a}}b', ['a' => 1]);
    }

    #[DataProvider('otherLegacyFatalShapes')]
    public function testPermissiveModeRecordsTheOtherShapesToo(string $template, string $kind): void
    {
        $context = new Context(['a' => 1]);
        $engine = TemplateEngine::withOptions(Options::compatible()->withRefuseLegacyIncompatible(false));
        $engine->render($template, [], $context);

        $kinds = array_map(static fn ($i) => $i->kind, $context->incompatibilities());
        self::assertContains($kind, $kinds);
    }

    public static function otherLegacyFatalShapes(): array
    {
        return [
            'stray close'     => ['a{{/if}}b', LegacyIncompatibility::STRAY_CLOSING_TAG],
            'unclosed block'  => ['a{{if a}}b', LegacyIncompatibility::UNCLOSED_BLOCK],
            'same-name nest'  => ['{{if a}}{{if a}}x{{/if}}{{/if}}', LegacyIncompatibility::SAME_NAME_NESTING],
        ];
    }

    /** A paired closing tag is never mistaken for a stray one. */
    public function testWellFormedTemplatesAreUnaffected(): void
    {
        $engine = TemplateEngine::compatible();
        self::assertSame('Y', $engine->render('{{if a}}Y{{/if}}', ['a' => 1]));
        self::assertSame('Y', $engine->render('{{depend a}}Y{{/depend}}', ['a' => 1]));
        self::assertSame('ABZ', $engine->render('{{depend a}}A{{if b}}B{{/if}}Z{{/depend}}', ['a' => 1, 'b' => 1]));
        self::assertSame('X', $engine->render('{{var a}}', ['a' => 'X']));
    }
}
