<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\DirectiveSpec;
use Cresset\TemplateParser\Evaluator;
use Cresset\TemplateParser\HostDirectives;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\Parser;
use Cresset\TemplateParser\RenderPolicy;
use Cresset\TemplateParser\TemplateEngine;
use Cresset\TemplateParser\Testing\PortTape;
use Cresset\TemplateParser\Testing\ReplayedPortFailure;
use Cresset\TemplateParser\Testing\TapedServices;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The twelve directives that need a host, replayed from a real store.
 *
 * `tools/record-legacy.php` builds a filter out of a handful of required files and no
 * application, so it cannot reach {{store}}, {{media}}, {{view}}, {{protocol}}, {{block}},
 * {{widget}}, {{layout}}, {{config}}, {{customvar}}, {{template}}, {{css}} or {{inlinecss}}.
 * They were excluded from the parity corpus by construction - twelve of nineteen directives -
 * and that is where every security bug adversarial fuzzing has found in this package has
 * been: four URL guard holes, three missing {{block}} controls, a {{layout}} that discarded
 * every parameter it was handed.
 *
 * So they are recorded against a real store by `tools/record-store-ports.php` and replayed
 * here, and what is replayed is the TAPE: every question the engine asked its ports and the
 * answer it got. The port boundary is where this engine's responsibility ends, which makes
 * the tape exactly its observable decisions - what it let through, what it refused by never
 * asking at all, what it forwarded alongside. A tape needs no store, which is what makes this
 * a fixture rather than a manual check.
 *
 * A guard that starts refusing shows up as a call the tape has and the replay never makes; a
 * guard that stops refusing, as a call the tape does not have. Both fail.
 */
final class StorePortParityTest extends TestCase
{
    /** @return array<string,array{0:array}> */
    public static function recordedCases(): array
    {
        $file = dirname(__DIR__) . '/tests/fixtures/legacy/store-ports.json';
        $data = json_decode((string)file_get_contents($file), true);

        $cases = [];
        foreach ($data['cases'] ?? [] as $case) {
            $cases[$case['id']] = [$case + ['_ports' => $data['recorded_against']['ports'] ?? []]];
        }

        return $cases;
    }

    public function testTheRecordingIsSubstantial(): void
    {
        $cases = self::recordedCases();

        self::assertGreaterThan(300, count($cases), 'the store recording should be large');

        // Every port-backed directive has to appear, or a directive could quietly leave the
        // matrix and take its coverage with it - which is the situation this file exists to
        // end. {{filter}} is absent deliberately: it is a modifier surface, not a host port.
        $templates = implode(' ', array_map(static fn (array $c): string => $c[0]['template'], $cases));
        foreach ([
            'store', 'media', 'view', 'protocol', 'block', 'widget',
            'layout', 'config', 'customvar', 'template', 'css', 'inlinecss',
        ] as $directive) {
            self::assertStringContainsString('{{' . $directive, $templates, $directive . ' left the matrix');
        }
    }

    /**
     * The engine asks its ports exactly what it asked when this was recorded.
     *
     * Not "something reasonable" - exactly, and in order. A replay that asks a different
     * question is an engine that changed its mind about what reaches the host, which is the
     * only thing a guard does.
     */
    #[DataProvider('recordedCases')]
    public function testThePortCallsAreUnchanged(array $case): void
    {
        [$tape, $result] = self::replay($case);

        self::assertSame(
            [],
            $tape->unplayed(),
            sprintf(
                "%s: the tape has calls the engine no longer makes - a guard started refusing\n  %s",
                $case['id'],
                implode("\n  ", array_map(
                    static fn (array $e): string => $e['port'] . '::' . $e['method']
                        . '(' . PortTape::describe($e['args']) . ')',
                    $tape->unplayed()
                ))
            )
        );

        self::assertSame($case['outcome'], $result[0], $case['id'] . ': the outcome changed');
    }

    /** And assembles the same output from the same answers. */
    #[DataProvider('recordedCases')]
    public function testTheRenderedOutputIsUnchanged(array $case): void
    {
        [, $result] = self::replay($case);

        self::assertSame($case['expected'], $result[1], $case['id']);
    }

    /**
     * Where the two engines agreed on the store, they still agree here.
     *
     * This is the parity assertion for the twelve port-backed directives, and it works offline
     * because `legacy` is a recorded CONSTANT while our side is recomputed from the tape every
     * run. Nothing circular: a change that makes this engine render differently from what the
     * filter rendered fails, without needing a store to notice.
     *
     * Only the cases that agreed when this was recorded are held to it. Most of the rest
     * disagree because a guard refused something the filter renders, which is the point of the
     * package - and those are pinned from the other side by the output test above, so a case
     * cannot quietly cross from one group to the other either.
     */
    #[DataProvider('agreeingCases')]
    public function testWhatAgreedWithTheFilterStillAgrees(array $case): void
    {
        [, $result] = self::replay($case);

        self::assertSame('ok', $result[0], $case['id'] . ': this rendered on the store and now raises');
        self::assertSame($case['legacy'], $result[1], $case['id']);
    }

    /** @return array<string,array{0:array}> */
    public static function agreeingCases(): array
    {
        return array_filter(self::recordedCases(), static fn (array $c): bool => (bool)$c[0]['agreed']);
    }

    /**
     * And the set of agreeing cases is itself pinned.
     *
     * Without this a guard that starts refusing something the filter renders simply leaves the
     * agreeing set smaller, and every remaining assertion still passes. The count is the thing
     * that would otherwise erode silently, one case at a time.
     */
    public function testTheAgreementSetHasNotShrunk(): void
    {
        $agreed = count(self::agreeingCases());
        $comparable = count(array_filter(
            self::recordedCases(),
            static fn (array $c): bool => $c[0]['legacy'] !== null
        ));

        self::assertSame(
            190,
            $agreed,
            sprintf(
                'the number of store cases agreeing with the legacy filter changed (%d of %d '
                . 'comparable). If that is deliberate, read the fixture diff and update this '
                . 'number; if it is not, a guard has started or stopped refusing.',
                $agreed,
                $comparable
            )
        );
    }

    /**
     * Nothing a variable's value carries is ever parsed as source, on this surface either.
     *
     * The hostile variable set holds a live `javascript&#58` scheme and an attribute breakout,
     * and these are the directives that emit their result UNESCAPED - so this is where such a
     * value would surface if it were going to.
     */
    #[DataProvider('recordedCases')]
    public function testNoValueIsEverRenderedAsADirective(array $case): void
    {
        [, $result] = self::replay($case);

        if ($result[0] !== 'ok') {
            self::assertTrue(true, 'a refusal renders nothing');
            return;
        }

        self::assertStringNotContainsString('<?php', $result[1], $case['id']);

        // A `{{` in the output has to have been in the template. One that was not came from a
        // value, which is the smuggling this package exists to prevent.
        if (!str_contains($case['template'], '{{')) {
            self::assertStringNotContainsString('{{', $result[1], $case['id']);
        }
    }

    /**
     * @param array<string,mixed> $case
     * @return array{0:PortTape,1:array{0:string,1:?string}}
     */
    private static function replay(array $case): array
    {
        $tape = PortTape::fromEntries($case['tape']);
        $options = Options::compatible();
        $spec = new DirectiveSpec();
        $evaluator = new Evaluator(spec: $spec, options: $options);

        HostDirectives::register(
            $evaluator,
            TapedServices::replaying($case['_ports'], $tape),
            new Parser($spec, $options)
        );

        $engine = new TemplateEngine(new Parser($spec, $options), $evaluator);
        $context = new Context(
            $case['variables'],
            RenderPolicy::unrestricted(),
            (bool)$case['plain_text']
        );

        try {
            return [$tape, ['ok', $engine->render($case['template'], context: $context)]];
        } catch (ReplayedPortFailure $e) {
            // A tape can only carry the class of what the host threw, so the replay throws a
            // stand-in. Reported as the original, because what is being pinned is that the
            // engine still lets a host failure OUT rather than swallowing it - and which
            // failure it was is the host's business, not this engine's.
            return [$tape, ['throw', $e->originalClass]];
        } catch (\Throwable $e) {
            return [$tape, ['throw', (new \ReflectionClass($e))->getShortName()]];
        }
    }
}
