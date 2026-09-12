<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\LegacyIncompatibleError;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\TemplateEngine;
use Cresset\TemplateParser\TemplateError;
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

    /**
     * Every case the filter RENDERED, comparable surface or not.
     *
     * `parity` answers "may these two outputs be compared", which is a different question from
     * "did the filter crash". A refusal claiming a crash is wrong wherever the filter rendered,
     * and filtering this by parity is why the claim went unchecked for every {{for}} case in
     * the corpus - the one construct where it turned out to be false.
     *
     * @return array<string,array{0:array}>
     */
    public static function everyRenderedCase(): array
    {
        return array_filter(self::recordedCases(), static fn ($c) => $c[0]['outcome'] === 'ok');
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

    /**
     * Cases whose rendering changed with the StyleSmuggler hardening.
     *
     * @return array<string,array{0:array}>
     */
    public static function preHardeningCases(): array
    {
        return array_filter(
            self::recordedCases(),
            static fn ($c) => isset($c[0]['pre_hardening'])
                && $c[0]['parity']
                && !in_array(explode('/', $c[0]['id'])[0], self::DELIBERATE_OVER_REFUSALS, true)
        );
    }

    /**
     * Compatible mode targets the CURRENT filter by default, and the older one on request.
     *
     * Mage-OS added Template\DirectiveOutputNeutralizer with the StyleSmuggler hardening; it
     * encodes `{{` in resolved directive output, which changes observable rendering for any
     * variable whose value contains a directive opener. Both trees are in the field, so both
     * are recorded and both are asserted - a flag with only one of its settings tested is a
     * flag that works by accident.
     */
    #[DataProvider('preHardeningCases')]
    public function testPreHardeningRenderingIsReproducibleOnRequest(array $case): void
    {
        $engine = TemplateEngine::withOptions(Options::compatible()->withOutputNeutralizer(false));
        $expected = $case['pre_hardening'];

        if ($expected['outcome'] !== 'ok') {
            $this->expectException(LegacyIncompatibleError::class);
            $engine->render($case['template'], self::variablesFor($case));
            return;
        }

        self::assertSame(
            $expected['expected'],
            $engine->render($case['template'], self::variablesFor($case)),
            sprintf("pre-hardening rendering diverged for %s\n  template: %s", $case['id'], $case['template'])
        );
    }

    /** ...and the two really are different, or the flag is doing nothing. */
    public function testTheHardeningActuallyChangesRendering(): void
    {
        $cases = self::preHardeningCases();
        self::assertGreaterThan(40, count($cases), 'the corpus should exercise the neutralizer widely');

        $case = $cases['var/directive'][0] ?? array_values($cases)[0][0];
        self::assertNotSame(
            $case['expected'],
            $case['pre_hardening']['expected'],
            'a recorded pre-hardening variant that matches the current one is not a variant'
        );
    }

    public function testTheCorpusIsSubstantial(): void
    {
        $cases = self::recordedCases();
        self::assertGreaterThan(500, count($cases), 'recorded corpus should be large');
        self::assertGreaterThan(40, count(self::legacyFatalCases()), 'corpus should exercise legacy failure modes');
    }

    /**
     * The prose quotes numbers about this corpus, and they go stale.
     *
     * A headline nobody recomputes is a claim that decays: the README read 3176/323/2199
     * against a corpus of 4199/649/2679, understating the fatals by half. CONTRIBUTING is
     * covered too, because that is where every stale figure survived the round that caught
     * the README - it was not being read by anything.
     *
     * Asserted here so growing the corpus fails the suite until the sentences are updated
     * with it - the same bargain the language documentation makes.
     */
    public function testTheProseMatchesTheCorpus(): void
    {
        $cases = self::recordedCases();
        $fatal = 0;
        $comparable = 0;
        $preHardening = 0;

        foreach ($cases as [$case]) {
            if ($case['outcome'] === 'throw') {
                $fatal++;
                continue;
            }
            if (isset($case['pre_hardening'])) {
                $preHardening++;
            }
            if ($case['parity'] && !in_array(explode('/', $case['id'])[0], self::DELIBERATE_OVER_REFUSALS, true)) {
                $comparable++;
            }
        }

        $root = dirname(__DIR__);

        $expected = [
            'README.md' => [
                'total cases'    => sprintf('**%d cases recorded', count($cases)),
                'legacy fatals'  => sprintf('%d of them constructs the legacy filter cannot render', $fatal),
                'compared cases' => sprintf('the %d cases where both engines render', $comparable),
                'replayed'       => sprintf('replays the %d recorded cases', count($cases)),
                'pre-hardening'  => sprintf('%d cases carry a second expectation', $preHardening),
            ],
            'CONTRIBUTING.md' => [
                'total cases'    => sprintf('%d cases in total', count($cases)),
                'legacy fatals'  => sprintf('%d of which crash the', $fatal),
                'pre-hardening'  => sprintf('%d carry a second expectation', $preHardening),
                'compared cases' => sprintf('over the %d rendering-comparable', $comparable),
            ],
        ];

        foreach ($expected as $file => $sentences) {
            $prose = (string)file_get_contents($root . '/' . $file);
            foreach ($sentences as $what => $sentence) {
                self::assertStringContainsString(
                    $sentence,
                    $prose,
                    sprintf('%s is stale for %s - expected "%s"', $file, $what, $sentence)
                );
            }
        }
    }

    /**
     * The two files also quote the suite's own size, which they cannot compute.
     *
     * Not a count of tests - PHPUnit knows that and this does not - but the two files have to
     * agree with each other, which is what actually went wrong: they were updated one at a
     * time and drifted to different figures for the same run.
     */
    public function testBothFilesQuoteTheSameSuiteSize(): void
    {
        $figures = [];
        foreach (['README.md', 'CONTRIBUTING.md'] as $file) {
            $prose = (string)file_get_contents(dirname(__DIR__) . '/' . $file);
            self::assertSame(
                1,
                preg_match('/^(\d+) tests\./m', $prose, $m),
                $file . ' should state the suite size once, as "N tests."'
            );
            $figures[$file] = $m[1];
        }

        self::assertSame(
            $figures['README.md'],
            $figures['CONTRIBUTING.md'],
            'the two files disagree about how many tests there are'
        );
    }

    /**
     * Shapes compatible mode deliberately refuses even though legacy renders them.
     *
     * Every one is fail-closed. See testExtraRefusalsAreOnlyTheDocumentedShapes for what
     * each of them is and why it is not imitated; this list is asserted to be exactly the
     * observed set, so it cannot drift without a test failing.
     */
    public const DELIBERATE_OVER_REFUSALS = [
        // Sorted, because the observed set is - see testExtraRefusalsAreOnlyTheDocumentedShapes.
        // `mixed_close`, `shout_paired`, `upper_paired` and `upper_wraps_dir` are
        // `unknown_paired` in other cases rather than new shapes: CONSTRUCTION_PATTERN carries
        // /si and closes with a backreference, so legacy swallows the block whatever the case.
        // The `brace_top_*` three are a consequence of the run-on divergence rather than a
        // new decision: where the regex swallows a directive, it never EVALUATES it either,
        // so a value this engine refuses on is one legacy never looked at. They appear here
        // only in the variable sets carrying such a value.
        'brace_top_trans', 'brace_top_two', 'brace_top_var',
        'cross_unclosed', 'depend_dot', 'mixed_close', 'nest_empty_dep', 'nest_empty_if',
        'shout_paired', 'unknown_paired', 'upper_paired', 'upper_var_dot', 'upper_wraps_dir',
        'var_digit', 'var_dot', 'var_paired', 'var_underscore',
    ];

    /** Where legacy renders, compatible mode must render identically - or refuse by design. */
    #[DataProvider('renderingCases')]
    public function testCompatibleModeMatchesLegacy(array $case): void
    {
        if (in_array(explode('/', $case['id'])[0], self::DELIBERATE_OVER_REFUSALS, true)) {
            self::markTestSkipped('deliberate over-refusal: ' . $case['id']);
        }

        $actual = TemplateEngine::compatible()->render($case['template'], self::variablesFor($case));

        self::assertSame(
            $case['expected'],
            $actual,
            sprintf("compatible mode diverged for %s\n  template: %s", $case['id'], $case['template'])
        );
    }

    /**
     * A refusal must not tell the operator the legacy filter crashes when it does not.
     *
     * Half the over-refusal messages used to. `{{if}}{{if}}{{/if}}` renders as '' there, by
     * accident of the lazy body match, and the message said TypeError; `{{if a}}A{{/if }}`
     * really does raise ALONE, but renders when a well-formed {{/if}} follows it later, and
     * the message asserted the crash unconditionally.
     *
     * That matters because of who reads it. This tool exists to tell a merchant which stored
     * templates are broken, and a message claiming their template was already crashing - when
     * it has been sending mail for years - is the tool being confidently wrong in the one
     * place it is meant to be authoritative.
     *
     * The corpus knows the answer for every case it holds: `outcome` records what the filter
     * really did. So a strong claim is only allowed where the corpus does not contradict it.
     */
    #[DataProvider('everyRenderedCase')]
    public function testNoRefusalClaimsACrashTheFilterDoesNotHave(array $case): void
    {
        try {
            TemplateEngine::compatible()->render($case['template'], self::variablesFor($case));
            $this->addToAssertionCount(1);

            return;
        } catch (TemplateError $e) {
            $message = $e->getMessage();
        }

        self::assertStringNotContainsString(
            'raises a TypeError here',
            $message,
            sprintf(
                "%s is recorded as RENDERING on the legacy filter, so a refusal must not say it "
                . "crashes there.\n  template: %s\n  legacy rendered: %s",
                $case['id'],
                $case['template'],
                var_export($case['expected'], true)
            )
        );
    }

    /**
     * Where the directive surfaces differ, parity is not the contract - but the engine must
     * still render without raising, and must never emit the payload as executable output.
     */
    #[DataProvider('surfaceDivergentCases')]
    public function testSurfaceDivergentCasesStillRenderSafely(array $case): void
    {
        $engine = TemplateEngine::compatible();
        // A refusal emits nothing, so there is nothing unsafe in it to check - but it still
        // has to be the same outcome twice, which is what the comparison below is for.
        $render = static function () use ($engine, $case): ?string {
            try {
                return $engine->render($case['template'], self::variablesFor($case));
            } catch (TemplateError) {
                return null;
            }
        };

        $actual = $render();
        self::assertSame($actual, $render(), 'rendering is not deterministic: ' . $case['id']);

        if ($actual === null) {
            return;
        }

        self::assertStringNotContainsString('<?php', $actual);
        // The engine may legitimately differ from legacy here, but it may not invent a
        // directive: whatever comes out must contain no construct legacy would have run.
        self::assertNoLiveDirectiveSurvived($actual, $case);
    }

    /**
     * Nothing in the output may be a construct the legacy filter would execute.
     *
     * A directive can legitimately survive verbatim - lenient pass-through and `|raw` both
     * do that - but only if it was in the TEMPLATE. One that appears in the output without
     * being in the template came from a variable's value, which is the smuggling this
     * engine exists to prevent.
     */
    private static function assertNoLiveDirectiveSurvived(string $actual, array $case): void
    {
        // A value echoed raw may legitimately carry a construct - that is what |raw means,
        // and compatible mode's modifier chain fails open by design. What must not happen is
        // a construct appearing from nowhere, so both sources count as accounted for.
        $accountedFor = $case['template'] . ' ' . json_encode($case['variables']);

        preg_match_all('/\{\{[a-z]{1,10}[\s}]/i', $actual, $found);
        foreach (array_unique($found[0]) as $construct) {
            self::assertStringContainsString(
                $construct,
                $accountedFor,
                sprintf(
                    '%s: "%s" is in the output but in neither the template nor its variables',
                    $case['id'],
                    $construct
                )
            );
        }
    }

    /**
     * Where legacy raises a fatal, compatible mode refuses.
     *
     * This is the bug-for-bug contract: an engine that renders what the old one crashes on
     * is a better engine, not a compatible one. The cases that crash the stock filter -
     * degenerate directive names, stray closing tags, unclosed blocks - are declined here,
     * with a diagnostic rather than a TypeError.
     */
    #[DataProvider('legacyFatalCases')]
    public function testCompatibleModeRefusesWhatLegacyCannotRender(array $case): void
    {
        $this->expectException(LegacyIncompatibleError::class);
        TemplateEngine::compatible()->render($case['template'], self::variablesFor($case));
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

        $actual = $engine->render($case['template'], self::variablesFor($case));

        // assertIsString() on a `: string` return can never fail. What is actually being
        // claimed is that these render rather than raise, and that the result is inert.
        self::assertStringNotContainsString('<?php', $actual, $case['id']);
        self::assertNoLiveDirectiveSurvived($actual, $case);
        self::assertSame(
            $actual,
            $engine->render($case['template'], self::variablesFor($case)),
            'rendering is not deterministic: ' . $case['id']
        );
    }

    /**
     * The safety-critical direction: compatible mode never renders what legacy crashed on.
     *
     * This is the half of the contract that matters. A construct the old filter died on is
     * one nobody has ever seen the output of, so rendering it is inventing behaviour - and
     * in a shadow comparison it looks like the new engine "fixed" something when it has
     * actually changed what a stored template means.
     */
    public function testEveryLegacyFatalIsRefused(): void
    {
        $engine = TemplateEngine::compatible();
        $rendered = [];

        foreach (self::legacyFatalCases() as $id => [$case]) {
            try {
                $engine->render($case['template'], self::variablesFor($case));
                $rendered[] = $id;
            } catch (LegacyIncompatibleError) {
                // refused, as required
            } catch (\Throwable) {
                // any other refusal is still a refusal
            }
        }

        self::assertSame([], $rendered, 'compatible mode rendered constructs legacy cannot');
        self::assertNotEmpty(self::legacyFatalCases());
    }

    /**
     * The truthiness quirk, checked against what the filter RECORDED rather than against prose.
     *
     * `{{if}}` tests `resolve(...) == ''`, so the branch it takes is decided by a loose
     * comparison - and `KnownDivergenceTest` pins that comparison on every supported version.
     * This ties the two together: for each value, the branch the comparison predicts has to be
     * the branch the filter was actually recorded taking. If PHP changes the comparison again,
     * one test says the mechanism moved and this one says the behaviour moved with it.
     */
    public function testTheRecordedTruthinessMatchesTheComparisonThatCausesIt(): void
    {
        $cases = self::recordedCases();
        $checked = 0;

        foreach ([
            'if/emptystr' => '',
            'if/zero' => 0,
            'if/strzero' => '0',
            'if/emptyarr' => [],
            'if/word' => 'x',
        ] as $id => $value) {
            if (!isset($cases[$id])) {
                continue;
            }

            $case = $cases[$id][0];
            $expected = ($value == '') ? '[]' : '[Y]';

            self::assertSame(
                $expected,
                $case['expected'],
                sprintf(
                    '%s: `%s == \'\'` is %s on PHP %s, so the filter should have taken the %s '
                    . 'branch - but it was recorded rendering %s',
                    $id,
                    var_export($value, true),
                    var_export($value == '', true),
                    PHP_VERSION,
                    ($value == '') ? 'false' : 'true',
                    var_export($case['expected'], true)
                )
            );
            $checked++;
        }

        self::assertGreaterThanOrEqual(4, $checked, 'the truthiness cases left the corpus');
    }

    /**
     * The other direction, stated honestly: compatible mode refuses a SUPERSET.
     *
     * Every extra refusal is fail-closed - the construct is reported rather than rendered
     * differently - and each is a place where legacy's regex does something this parser will
     * not imitate. They are enumerated by shape so the list cannot quietly grow: a new entry
     * here means a new incompatibility, and has to be an explicit decision.
     *
     *   var_dot / var_underscore / var_digit / depend_dot
     *       CONSTRUCTION_PATTERN captures the name as a greedy [a-z]{0,10}, so `{{var.a}}`
     *       is a live variable read of `.a`. Imitating that means treating any punctuation
     *       after a name as a parameter separator.
     *   nest_empty_if / nest_empty_dep / cross_unclosed
     *       Same-name and crossed nesting, which legacy resolves to '' by accident of its
     *       lazy body match rather than by design.
     *   unknown_paired / var_paired
     *       `{{foo}}x{{/foo}}` and `{{var a}}Y{{/var}}` - the optional closing group swallows
     *       a body for directives that have no body at all.
     *   brace_top_var / brace_top_trans / brace_top_two
     *       One missing brace at top level. The lazy `(.*?)}}` swallows the broken opener and
     *       the intact directive after it, so legacy renders neither and never reaches the
     *       value; this engine renders the intact one, and refuses when THAT value is one it
     *       refuses on. Not a second decision - the same declared divergence, seen from the
     *       refusal side. Where the swallowed stretch costs legacy a paired directive it
     *       raises instead, and those are refused here too, which is the other test.
     */
    public function testExtraRefusalsAreOnlyTheDocumentedShapes(): void
    {
        $expected = self::DELIBERATE_OVER_REFUSALS;   // see the constant for what each is

        $engine = TemplateEngine::compatible();
        $shapes = [];

        foreach (self::recordedCases() as $id => [$case]) {
            if ($case['outcome'] !== 'ok') {
                continue;                     // legacy fatals are the other test
            }
            try {
                $engine->render($case['template'], self::variablesFor($case));
            } catch (LegacyIncompatibleError) {
                $shapes[explode('/', $id)[0]] = true;
            } catch (\Throwable) {
                $shapes[explode('/', $id)[0]] = true;
            }
        }

        $found = array_keys($shapes);
        sort($found);

        self::assertSame($expected, $found, 'the set of deliberate over-refusals changed');
    }

    /**
     * Where both engines render, they must agree byte for byte.
     *
     * Stated separately from the per-case provider so a regression shows up as one failure
     * naming the count, rather than as several hundred.
     */
    public function testNoRenderedCaseDiverges(): void
    {
        $engine = TemplateEngine::compatible();
        $diverged = [];

        foreach (self::recordedCases() as $id => [$case]) {
            if ($case['outcome'] !== 'ok' || !$case['parity']) {
                continue;                     // surface-divergent directives are not compared
            }
            try {
                $actual = $engine->render($case['template'], self::variablesFor($case));
            } catch (\Throwable) {
                continue;                     // a refusal is covered above
            }
            if ($actual !== $case['expected']) {
                $diverged[] = $id;
            }
        }

        self::assertSame([], $diverged, 'compatible mode rendered differently from legacy');
    }
}
