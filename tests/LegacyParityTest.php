<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\LegacyIncompatibleError;
use MageOS\TemplateParser\Options;
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
     * Where legacy raises a fatal, compatible mode refuses.
     *
     * This is the bug-for-bug contract: an engine that renders what the old one crashes on
     * is a better engine, not a compatible one. 64 of the recorded cases crash the stock
     * filter - degenerate directive names, stray closing tags, unclosed blocks - and
     * compatible mode declines all of them, with a diagnostic rather than a TypeError.
     */
    #[DataProvider('legacyFatalCases')]
    public function testCompatibleModeRefusesWhatLegacyCannotRender(array $case): void
    {
        $this->expectException(LegacyIncompatibleError::class);
        TemplateEngine::compatible()->render($case['template'], $case['variables']);
    }

    /**
     * With the refusal switched off, the same cases render - the improvement, available to
     * anyone who does not need to keep a rollback to the legacy filter working.
     */
    #[DataProvider('legacyFatalCases')]
    public function testPermissiveModeRendersWhereLegacyFataled(array $case): void
    {
        $engine = TemplateEngine::withOptions(
            Options::compatible()->withRefuseLegacyIncompatible(false)
        );

        $actual = $engine->render($case['template'], $case['variables']);
        self::assertIsString($actual, 'permissive mode should render: ' . $case['id']);
    }

    /**
     * The claim in one assertion: compatible mode refuses EXACTLY the cases legacy fatals
     * on - no more, no fewer. Refusing extra cases would be a regression as real as
     * rendering ones legacy cannot.
     */
    public function testRefusalSetMatchesLegacyFatalSetExactly(): void
    {
        $engine = TemplateEngine::compatible();
        $refused = $rendered = [];

        foreach (self::recordedCases() as $id => [$case]) {
            try {
                $engine->render($case['template'], $case['variables']);
                $rendered[] = $id;
            } catch (LegacyIncompatibleError) {
                $refused[] = $id;
            } catch (\Throwable) {
                $rendered[] = $id;   // some other error is a different question
            }
        }

        $legacyFatal = array_keys(self::legacyFatalCases());
        sort($refused);
        sort($legacyFatal);

        self::assertSame(
            $legacyFatal,
            $refused,
            'compatible mode should refuse exactly the constructs legacy cannot render'
        );
        self::assertNotEmpty($refused);
    }
}
