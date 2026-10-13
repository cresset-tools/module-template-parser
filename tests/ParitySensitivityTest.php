<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Options;
use MageOS\TemplateParser\TemplateEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves the parity corpus is not vacuous.
 *
 * A suite of 500 green assertions means nothing if the corpus cannot tell a correct engine
 * from a broken one. Each test here deliberately mis-configures the engine and asserts the
 * SAME recorded cases then fail - so a passing LegacyParityTest is evidence, not decoration.
 *
 * If one of these ever passes with zero divergences, the corpus has stopped covering that
 * behaviour and needs extending.
 */
final class ParitySensitivityTest extends TestCase
{
    /** @return array<int,array{template:string,variables:array,expected:string}> */
    private static function parityCases(): array
    {
        $cases = json_decode(
            (string)file_get_contents(__DIR__ . '/fixtures/legacy/cases.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        return array_values(array_filter(
            $cases,
            // The shapes compatible mode deliberately refuses are excluded here too: this
            // corpus measures rendering equality, and a refusal is a different claim, made
            // by LegacyParityTest::testExtraRefusalsAreOnlyTheDocumentedShapes.
            static fn ($c) => $c['outcome'] === 'ok'
                && $c['parity']
                && !in_array(
                    explode('/', $c['id'])[0],
                    LegacyParityTest::DELIBERATE_OVER_REFUSALS,
                    true
                )
        ));
    }

    /** @return array<string,mixed> */
    private static function variablesFor(array $case): array
    {
        $variables = $case['variables'];
        if (($case['object'] ?? null) !== null) {
            require_once __DIR__ . '/fixtures/legacy/ObjectFixtures.php';
            $variables['a'] = \ObjectFixtures::make($case['object']);
        }
        return $variables;
    }

    /** Renders every parity case with $engine and counts how many stop matching. */
    private function divergences(TemplateEngine $engine): int
    {
        $count = 0;
        foreach (self::parityCases() as $case) {
            try {
                $actual = $engine->render($case['template'], self::variablesFor($case));
            } catch (\Throwable) {
                $count++;
                continue;
            }
            if ($actual !== $case['expected']) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param callable():TemplateEngine $factory
     */
    #[DataProvider('mutations')]
    public function testCorpusDetectsMutation(string $label, callable $factory, int $atLeast): void
    {
        $found = $this->divergences($factory());

        self::assertGreaterThanOrEqual(
            $atLeast,
            $found,
            sprintf(
                'The corpus failed to detect "%s" (%d divergences, expected at least %d). '
                . 'Either the mutation is not really a behaviour change, or the corpus has '
                . 'stopped covering it and needs extending.',
                $label,
                $found,
                $atLeast
            )
        );
    }

    public static function mutations(): array
    {
        return [
            // Every legacy quirk switched off at once.
            'all legacy quirks disabled' => [
                'all legacy quirks disabled',
                static fn () => TemplateEngine::lenient(),
                40,
            ],
            // Standard PHP truthiness instead of `== ''`: 0, '0' and [] flip.
            'standard truthiness' => [
                'standard truthiness',
                static fn () => TemplateEngine::withOptions(
                    Options::compatible()->withLegacyQuirks(false)->withVariables(false)
                ),
                20,
            ],
            // Strict mode raises where legacy rendered.
            'strict mode' => [
                'strict mode',
                static fn () => new TemplateEngine(),
                20,
            ],
        ];
    }

    /** The control: the engine under test must pass the same corpus cleanly. */
    public function testUnmutatedEngineHasNoDivergences(): void
    {
        self::assertSame(
            0,
            $this->divergences(TemplateEngine::compatible()),
            'compatible mode must match every recorded legacy rendering'
        );
    }

    public function testTheCorpusIsBigEnoughToBeMeaningful(): void
    {
        self::assertGreaterThan(400, count(self::parityCases()));
    }
}
