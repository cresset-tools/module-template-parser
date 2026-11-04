<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Context;
use MageOS\TemplateParser\HostServices;
use MageOS\TemplateParser\Port\TemplateLoader;
use MageOS\TemplateParser\RenderPolicy;
use MageOS\TemplateParser\TemplateEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The corpus's deferral cases, replayed through the engine with their includes.
 *
 * Deferral is what Mage-OS 3.6.0 changed in the filter: an include's {{inlinecss}} is
 * resolved at the root template, where 3.5.0 resolved it in the innermost {{if}} or
 * {{depend}} body of the root around the include and inlined the stylesheet over that body
 * alone. LegacyParityTest cannot compare these - {{template}} has no port there - so this
 * serves each case's recorded includes through a loader, and stands in for the host's
 * inlining step the way the recorder does: the distinct files, prefixed to the document.
 *
 * Per target, each case is either rendered exactly as that release's filter rendered it, or
 * declined because the filter inlined a stylesheet over part of the document - and then only
 * where the whole-document result really is not what the filter produced, so the decline
 * cannot quietly widen to cases the engine would have got right.
 */
final class DeferralParityTest extends TestCase
{
    private const PARTIAL = 'stylesheet the filter inlines over part of the document';

    /**
     * Cases where the engine inlines the same stylesheets as the filter, in another order.
     *
     * The filter registers stylesheets in the order its processor passes reach them -
     * {{depend}} bodies, then {{if}} bodies, then {{template}}, then the rest - and this
     * engine in document order. From 3.6.0 an include inside a body is resolved at the root,
     * ahead of an include written before it. The order is only visible where two stylesheets
     * set the same property on the same element, which Shadow measures on the store's own
     * templates; a stock store inlines one stylesheet. Pinned so the list cannot grow
     * unnoticed. Keyed "<target> <construct>".
     */
    private const STYLESHEET_ORDER = ['3.6.0 defer_inc_if_and_top'];

    /** @return array<string,array{0:array}> */
    public static function deferralCases(): array
    {
        return array_filter(
            LegacyParityTest::recordedCases(),
            static fn ($c) => isset($c[0]['includes'])
                && $c[0]['outcome'] === 'ok'
                // A root that is itself a child template is a filter state this engine
                // never renders in: the plugin hands every child render to the filter.
                && !$c[0]['root_is_child']
                // {{for}} is the declared divergence - see LegacyParityTest.
                && !str_contains($c[0]['template'], '{{for')
                // With no variables at all the filter hands {{if}} and {{depend}} back
                // verbatim, yet its {{template}} pass still renders the include written
                // inside them. That is the no-variables quirk, not deferral, and not this
                // test's subject.
                && $c[0]['variables'] !== []
        );
    }

    #[DataProvider('deferralCases')]
    public function testDeferralRendersAsTheTargetOrIsDeclined(array $case): void
    {
        [$whole, $declined] = self::render($case);

        if ($declined) {
            self::assertNotSame(
                $case['expected'],
                $whole,
                sprintf('%s (%s) was declined, but the whole-document render is what the filter produced', $case['id'], $case['target'])
            );
            return;
        }

        if ($whole !== $case['expected']
            && in_array($case['target'] . ' ' . explode('/', $case['id'])[0], self::STYLESHEET_ORDER, true)
        ) {
            self::assertSame(self::stylesheets($case['expected']), self::stylesheets($whole), $case['id']);
            self::assertSame(self::unstyled($case['expected']), self::unstyled($whole), $case['id']);
            return;
        }

        self::assertSame(
            $case['expected'],
            $whole,
            sprintf("%s diverged from Mage-OS %s\n  template: %s", $case['id'], $case['target'], $case['template'])
        );
    }

    /** @return string[] the stylesheets the stand-in finisher recorded, sorted */
    private static function stylesheets(string $rendered): array
    {
        preg_match('/^<!--inlined:([^>]*)-->/', $rendered, $m);
        $files = explode(',', $m[1] ?? '');
        sort($files);
        return $files;
    }

    private static function unstyled(string $rendered): string
    {
        return (string)preg_replace('/^<!--inlined:[^>]*-->/', '', $rendered);
    }

    /**
     * Where 3.5.0 and 3.6.0 part company, the same template is declined on the first and
     * rendered on the second - the engine's output is the same either way; only whether it
     * may be served changes.
     */
    public function testAnIncludeInsideABodyIsDeclinedOnlyBelowTheRegistry(): void
    {
        $byTarget = [];
        foreach (self::deferralCases() as [$case]) {
            if ($case['id'] === 'defer_inc_in_if/word') {
                $byTarget[$case['target']] = self::render($case);
            }
        }

        self::assertSame([true, false], [$byTarget['3.5.0'][1], $byTarget['3.6.0'][1]]);
        self::assertSame($byTarget['3.5.0'][0], $byTarget['3.6.0'][0]);
    }

    /** A stylesheet named in a body of the root template is inlined over that body by both. */
    public function testAStylesheetNamedInsideABodyIsDeclinedOnEveryTarget(): void
    {
        $declined = [];
        foreach (self::deferralCases() as [$case]) {
            if ($case['id'] === 'defer_root_in_if/word') {
                $declined[$case['target']] = self::render($case)[1];
            }
        }

        self::assertSame(['3.5.0' => true, '3.6.0' => true], $declined);
    }

    /** Each STYLESHEET_ORDER entry still differs somewhere, or it should leave the list. */
    public function testTheStylesheetOrderDivergencesAreStillReal(): void
    {
        $still = [];
        foreach (self::deferralCases() as [$case]) {
            $key = $case['target'] . ' ' . explode('/', $case['id'])[0];
            if (in_array($key, self::STYLESHEET_ORDER, true) && self::render($case)[0] !== $case['expected']) {
                $still[$key] = true;
            }
        }

        self::assertSame(self::STYLESHEET_ORDER, array_keys($still));
    }

    public function testTheCorpusHasDeferralCasesForEveryTarget(): void
    {
        $targets = array_count_values(array_map(static fn ($c) => $c[0]['target'], self::deferralCases()));

        self::assertSame(array_keys(LegacyParityTest::TARGETS), array_keys($targets));
        self::assertGreaterThan(60, min($targets));
    }

    /**
     * The render, finished as the recorder's stand-in finishes one, and whether the engine
     * declined it.
     *
     * @return array{0:string,1:bool}
     */
    private static function render(array $case): array
    {
        $includes = $case['includes'];
        $loader = new class ($includes) implements TemplateLoader {
            /** @param array<string,string> $includes */
            public function __construct(private readonly array $includes)
            {
            }

            public function load(string $configPath): ?string
            {
                return $this->includes[$configPath] ?? null;
            }
        };

        $engine = TemplateEngine::forHost(
            new HostServices(templates: $loader),
            LegacyParityTest::optionsFor($case['target'])
        );
        $context = new Context($case['variables'], RenderPolicy::unrestricted());
        $rendered = $engine->render($case['template'], context: $context);

        $files = [];
        foreach ($context->deferred() as $entry) {
            if ($entry['kind'] === 'inlinecss') {
                $files[] = $entry['payload']['file'];
            }
        }
        if ($files !== []) {
            $rendered = '<!--inlined:' . implode(',', array_unique($files)) . '-->' . $rendered;
        }

        $declined = false;
        foreach ($context->violations() as $violation) {
            $declined = $declined || $violation->kind === self::PARTIAL;
        }

        return [$rendered, $declined];
    }
}
