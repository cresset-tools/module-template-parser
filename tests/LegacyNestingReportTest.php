<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Context;
use MageOS\TemplateParser\LegacyIncompatibility;
use MageOS\TemplateParser\LegacyIncompatibleError;
use MageOS\TemplateParser\Options;
use MageOS\TemplateParser\TemplateEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Compatible mode renders nesting the legacy filter cannot, and reports it.
 *
 * Legacy manages exactly two levels with differing names; anything else raises a TypeError
 * and the mail never sends. Reproducing that crash would make compatible mode no safer than
 * what it replaces, so the construct renders - but an operator is told, because a template
 * relying on it can no longer run on the old engine.
 */
final class LegacyNestingReportTest extends TestCase
{
    /** What legacy can actually do - verified against the unpatched filter. */
    #[DataProvider('legacySupported')]
    public function testSupportedNestingIsNotReported(string $template): void
    {
        $context = new Context(['a' => 1, 'b' => 1, 'c' => 1]);
        TemplateEngine::compatible()->render($template, [], $context);

        self::assertSame([], $context->incompatibilities(), 'legacy renders this, so nothing to report');
    }

    public static function legacySupported(): array
    {
        return [
            'depend > if'         => ['{{depend a}}A{{if b}}B{{/if}}Z{{/depend}}'],
            'if > depend'         => ['{{if a}}A{{depend b}}B{{/depend}}Z{{/if}}'],
            'single level'        => ['{{if a}}A{{/if}}'],
            'siblings same name'  => ['{{depend a}}A{{/depend}}{{depend b}}B{{/depend}}'],
            'no nesting at all'   => ['{{var a}}'],
        ];
    }

    /** @param string $kind */
    #[DataProvider('legacyUnsupported')]
    public function testUnsupportedNestingRendersButIsReported(string $template, string $kind, string $needle): void
    {
        $context = new Context(['a' => 1, 'b' => 1, 'c' => 1]);
        $out = TemplateEngine::compatible()->render($template, [], $context);

        self::assertSame('ABZ', $out, 'it must still render - legacy crashes here');

        $found = $context->incompatibilities();
        self::assertCount(1, $found);
        self::assertSame($kind, $found[0]->kind);
        self::assertStringContainsString($needle, $found[0]->message);
        self::assertSame(1, $found[0]->line);
    }

    public static function legacyUnsupported(): array
    {
        return [
            'depend > depend' => [
                '{{depend a}}A{{depend b}}B{{/depend}}Z{{/depend}}',
                LegacyIncompatibility::SAME_NAME_NESTING,
                '{{depend}} nested inside {{depend}}',
            ],
            'if > if' => [
                '{{if a}}A{{if b}}B{{/if}}Z{{/if}}',
                LegacyIncompatibility::SAME_NAME_NESTING,
                '{{if}} nested inside {{if}}',
            ],
        ];
    }

    /** Three levels exceeds legacy even when the innermost name is new. */
    public function testThreeLevelsIsReportedAsTooDeep(): void
    {
        $context = new Context(['a' => 1, 'b' => 1, 'c' => 1]);
        TemplateEngine::compatible()->render(
            '{{depend a}}{{if b}}{{depend c}}X{{/depend}}{{/if}}{{/depend}}',
            [],
            $context
        );

        $kinds = array_map(static fn ($i) => $i->kind, $context->incompatibilities());
        self::assertContains(LegacyIncompatibility::SAME_NAME_NESTING, $kinds);
    }

    public function testDepthReportUsesTheLegacyLimitNotTheConfiguredOne(): void
    {
        // maxNestingDepth is 3, so this renders; but legacy manages only 2.
        $context = new Context(['a' => 1, 'b' => 1, 'c' => 1]);
        $engine = TemplateEngine::withOptions(
            Options::compatible()->withMaxNestingDepth(4)
        );
        $engine->render('{{depend a}}{{if b}}{{for i in xs}}X{{/for}}{{/if}}{{/depend}}',
            ['a' => 1, 'b' => 1, 'xs' => ['q']], $context);

        $found = $context->incompatibilities();
        self::assertCount(1, $found);
        self::assertSame(LegacyIncompatibility::NESTING_DEPTH, $found[0]->kind);
        self::assertStringContainsString('at most 2', $found[0]->message);
    }

    /** Opt-in: refuse what legacy cannot render, so a rollback stays possible. */
    public function testRefuseModeRejectsInsteadOfRendering(): void
    {
        $engine = TemplateEngine::withOptions(
            Options::compatible()->withRefuseLegacyIncompatible(true)
        );

        try {
            $engine->render('{{if a}}A{{if b}}B{{/if}}Z{{/if}}', ['a' => 1, 'b' => 1]);
            self::fail('expected the construct to be refused');
        } catch (LegacyIncompatibleError $e) {
            self::assertStringContainsString('{{if}} nested inside {{if}}', $e->getMessage());
            self::assertStringContainsString('renders here but not on the legacy filter', $e->getMessage());
        }
    }

    public function testRefuseModeStillAllowsWhatLegacySupports(): void
    {
        $engine = TemplateEngine::withOptions(
            Options::compatible()->withRefuseLegacyIncompatible(true)
        );
        self::assertSame(
            'ABZ',
            $engine->render('{{depend a}}A{{if b}}B{{/if}}Z{{/depend}}', ['a' => 1, 'b' => 1])
        );
    }

    /** Reporting is a compatibility concern; the non-legacy modes stay quiet. */
    public function testStrictAndLenientModesDoNotReport(): void
    {
        foreach ([new TemplateEngine(), TemplateEngine::lenient()] as $engine) {
            $context = new Context(['a' => 1, 'b' => 1]);
            $engine->render('{{if a}}A{{if b}}B{{/if}}Z{{/if}}', [], $context);
            self::assertSame([], $context->incompatibilities());
        }
    }

    public function testIncompatibilityDescribesItsLocation(): void
    {
        $context = new Context(['a' => 1, 'b' => 1]);
        TemplateEngine::compatible()->render(
            "line one\n{{if a}}A{{if b}}B{{/if}}Z{{/if}}",
            [],
            $context
        );

        $found = $context->incompatibilities()[0];
        self::assertSame(2, $found->line);
        self::assertStringContainsString('line 2', $found->describe());
    }
}
