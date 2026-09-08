<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\TemplateEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Differential regression test against recorded legacy behaviour.
 *
 * tools/record-legacy.php runs the real Magento filter over the corpus and records what it
 * produced. This replays those recordings, so the comparison runs anywhere - no Magento
 * installation needed - and any drift in compatible mode shows up as a failing case rather
 * than as a surprise in production.
 */
final class LegacyParityTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/legacy/cases.json';

    /** @return array<string,array{0:array}> */
    public static function recordedCases(): array
    {
        $cases = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($cases as $case) {
            $out[$case['id']] = [$case];
        }
        return $out;
    }

    /** Legacy rendered it, and both engines implement every directive it uses. */
    public static function renderingCases(): array
    {
        return array_filter(
            self::recordedCases(),
            static fn ($c) => $c[0]['outcome'] === 'ok' && $c[0]['parity']
        );
    }

    /** Legacy rendered it, but the engines implement different directives for it. */
    public static function surfaceDivergentCases(): array
    {
        return array_filter(
            self::recordedCases(),
            static fn ($c) => $c[0]['outcome'] === 'ok' && !$c[0]['parity']
        );
    }

    public static function legacyFatalCases(): array
    {
        return array_filter(self::recordedCases(), static fn ($c) => $c[0]['outcome'] === 'throw');
    }

    public function testTheCorpusIsSubstantial(): void
    {
        $cases = self::recordedCases();
        self::assertGreaterThan(500, count($cases), 'recorded corpus should be large');
        self::assertGreaterThan(40, count(self::legacyFatalCases()), 'corpus should exercise legacy failure modes');
    }

    /** Where legacy renders, compatible mode must render identically. */
    #[DataProvider('renderingCases')]
    public function testCompatibleModeMatchesLegacy(array $case): void
    {
        $actual = TemplateEngine::compatible()->render($case['template'], $case['variables']);

        self::assertSame(
            $case['expected'],
            $actual,
            sprintf("compatible mode diverged for %s\n  template: %s", $case['id'], $case['template'])
        );
    }

    /**
     * Where the directive surfaces differ, parity is not the contract - but the engine must
     * still render without raising, and must never emit the payload as executable output.
     */
    #[DataProvider('surfaceDivergentCases')]
    public function testSurfaceDivergentCasesStillRenderSafely(array $case): void
    {
        $actual = TemplateEngine::compatible()->render($case['template'], $case['variables']);
        self::assertIsString($actual);
        self::assertStringNotContainsString('<?php', $actual);
    }

    /**
     * Where legacy raises a fatal, this engine must still render.
     *
     * 64 of the recorded cases crash the stock filter - same-name nesting, empty directive
     * names, prose containing braces. None of those are behaviour a template can depend on.
     */
    #[DataProvider('legacyFatalCases')]
    public function testLegacyFatalsStillRender(array $case): void
    {
        $actual = TemplateEngine::compatible()->render($case['template'], $case['variables']);
        self::assertIsString($actual, 'the engine must render where legacy fataled: ' . $case['id']);
    }
}
