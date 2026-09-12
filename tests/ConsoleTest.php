<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Console\Application;
use Cresset\TemplateParser\Console\Auditor;
use Cresset\TemplateParser\Console\LegacyRender;
use Cresset\TemplateParser\Console\EngineFactory;
use Cresset\TemplateParser\Console\HostExtensions;
use Cresset\TemplateParser\Console\Finding;
use Cresset\TemplateParser\Console\MagentoContext;
use Cresset\TemplateParser\Console\Mode;
use Cresset\TemplateParser\Console\Source\CodebaseEmailTemplates;
use Cresset\TemplateParser\Console\StoreEmulator;
use Cresset\TemplateParser\Console\TemplateSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The CLI, without a Magento anywhere near it.
 *
 * Half the point of the tool is that it degrades: a developer with a checkout and no store
 * should still be able to check syntax and try things in the REPL. These run in exactly that
 * situation, which is also what makes them runnable in this package's own CI.
 */
final class ConsoleTest extends TestCase
{
    private function auditor(): Auditor
    {
        $magento = MagentoContext::unavailable('no store in tests');

        return new Auditor(new EngineFactory($magento), new StoreEmulator($magento));
    }

    #[DataProvider('modeSpellings')]
    public function testModesParseIncludingTheLegacyAlias(string $spelling, Mode $expected): void
    {
        self::assertSame($expected, Mode::parse($spelling));
    }

    public static function modeSpellings(): array
    {
        return [
            'strict' => ['strict', Mode::Strict],
            'lenient' => ['lenient', Mode::Lenient],
            'compatible' => ['compatible', Mode::Compatible],
            'legacy is compatible' => ['legacy', Mode::Compatible],
            'case insensitive' => ['STRICT', Mode::Strict],
        ];
    }

    public function testAnUnknownModeSaysWhatIsValid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/strict, lenient or compatible/');
        Mode::parse('yolo');
    }

    /**
     * A divergence caused by an unwired port says so.
     *
     * Compatible mode emits an unregistered directive verbatim, so a port nobody wired lands
     * in the output as its own source and reads like an engine bug. {{layout}} does this on
     * every default run, because it will not render without a handle allowlist.
     */
    public function testADivergenceFromAnUnwiredPortNamesThePort(): void
    {
        $subject = new TemplateSubject(
            id: 'x',
            label: 'x',
            origin: 'test',
            content: 'a{{layout handle="sales_email_order_items"}}b'
        );

        $divergences = $this->auditor()->diff(
            [$subject],
            Mode::Compatible,
            static fn (): LegacyRender => new LegacyRender('a<table/>b', [])
        );

        self::assertCount(1, $divergences);
        self::assertStringContainsString('no port wired for {{layout}}', (string)$divergences[0]->note);
    }

    /**
     * The mirror image: the FILTER has no processor, and this engine does.
     *
     * `Email\Model\Template\Filter` extends `Framework\Filter\Template`, which has no
     * widgetDirective and no transDirective - so those render verbatim on that surface while
     * this engine, with the port wired, renders them. That is a capability the template did
     * not have, and reporting it as a bare difference puts it on the engine.
     */
    public function testADivergenceFromADirectiveTheFilterLacksSaysSo(): void
    {
        $subject = new TemplateSubject(id: 'x', label: 'x', origin: 'test', content: 'a{{trans "hi"}}b');

        $divergences = $this->auditor()->diff(
            [$subject],
            Mode::Compatible,
            // What the base filter does with a directive it has no processor for.
            static fn (): LegacyRender => new LegacyRender('a{{trans "hi"}}b', [])
        );

        self::assertCount(1, $divergences);
        self::assertStringContainsString('has no {{trans}} processor', (string)$divergences[0]->note);
        self::assertStringContainsString('capability gained', (string)$divergences[0]->note);
    }

    /**
     * A directive verbatim on BOTH sides is not a surface gap.
     *
     * With no variables in scope the filter passes directives through untouched, and this
     * engine reproduces that - so `{{var x}}` is its own text on both sides and cancels out
     * of the comparison. Naming it would send a reader looking for a port difference that is
     * not there.
     */
    public function testADirectiveVerbatimOnBothSidesIsNotCalledASurfaceGap(): void
    {
        $subject = new TemplateSubject(id: 'x', label: 'x', origin: 'test', content: 'a{{var x}}b');

        $divergences = $this->auditor()->diff(
            [$subject],
            Mode::Compatible,
            // The same passthrough, plus something else that differs - so there IS a
            // divergence to report, and `{{var}}` is not the reason for it.
            static fn (): LegacyRender => new LegacyRender('a{{var x}}bZ', [])
        );

        self::assertCount(1, $divergences);
        self::assertStringNotContainsString('capability gained', (string)$divergences[0]->note);
    }

    /**
     * A directive absent from our output because we have NO port is not a capability gained.
     *
     * The two notes answer opposite questions and the difference is which side has the port.
     * Here `{{layout}}` is unwired in this tool and sits inside a branch this engine discards,
     * so it is absent from our output entirely - reporting that as a capability gained would
     * be exactly backwards.
     */
    public function testAnUnwiredDirectiveIsNeverCalledACapabilityGained(): void
    {
        $subject = new TemplateSubject(
            id: 'x',
            label: 'x',
            origin: 'test',
            content: '{{depend nope}}{{layout handle="x"}}{{/depend}}',
            variables: ['other' => 1]
        );

        $divergences = $this->auditor()->diff(
            [$subject],
            Mode::Compatible,
            static fn (): LegacyRender => new LegacyRender('{{layout handle="x"}}', ['other' => 1])
        );

        self::assertCount(1, $divergences);
        self::assertStringNotContainsString('capability gained', (string)$divergences[0]->note);
    }

    /**
     * A template refusing because of a directive the STORE implements says so.
     *
     * This is the case the note matters most for, and the one it originally missed: a paired
     * custom directive leaves a `{{/mydir}}` with no opener this engine knows, so the render
     * is REFUSED - and "closes nothing here" is a puzzling thing to read about a directive
     * your own store registers a processor for. It was computed after the render at first,
     * which meant the refusal path returned before it was ever reached.
     */
    public function testARefusalCausedByAHostExtensionSaysSo(): void
    {
        $auditor = new Auditor(
            new EngineFactory(MagentoContext::unavailable('no store')),
            new StoreEmulator(MagentoContext::unavailable('no store')),
            $this->extensionsWith(['mydir'])
        );
        $subject = new TemplateSubject(
            id: 'x', label: 'x', origin: 'test',
            content: 'A{{mydir "v"}}body{{/mydir}}B'
        );

        $findings = $auditor->check([$subject], Mode::Compatible);
        $summaries = array_map(static fn ($f): string => $f->summary, $findings);

        self::assertNotEmpty($findings);
        // Asserted on words only the EXTENSION note uses. `{{mydir}}` alone would pass on the
        // refusal message too - "{{/mydir}} closes nothing here" contains it - so the first
        // version of this test could not fail.
        self::assertStringContainsString(
            'registers a directive processor for',
            implode(' | ', $summaries),
            'a refusal caused by a store extension must name it as the cause'
        );
    }

    /** And diff says it on the refusal path too, where the reason is least obvious. */
    public function testADivergenceFromAHostExtensionSaysSo(): void
    {
        $auditor = new Auditor(
            new EngineFactory(MagentoContext::unavailable('no store')),
            new StoreEmulator(MagentoContext::unavailable('no store')),
            $this->extensionsWith(['mydir'])
        );
        $subject = new TemplateSubject(
            id: 'x', label: 'x', origin: 'test',
            content: 'A{{mydir "v"}}body{{/mydir}}B'
        );

        $divergences = $auditor->diff(
            [$subject],
            Mode::Compatible,
            static fn (): LegacyRender => new LegacyRender('AYDOBPVB', [])
        );

        self::assertCount(1, $divergences);
        self::assertStringContainsString(
            'registers a directive processor for',
            (string)$divergences[0]->note
        );
    }

    /**
     * And when BOTH sides render, which is the void form.
     *
     * `{{mydir "v"}}` with no closing tag parses here as an unknown directive and comes back
     * as its own text, while the store renders the processor's output - so there is an
     * ordinary byte difference, and it is worth saying what caused it.
     */
    public function testABothRenderedDivergenceFromAHostExtensionSaysSo(): void
    {
        $auditor = new Auditor(
            new EngineFactory(MagentoContext::unavailable('no store')),
            new StoreEmulator(MagentoContext::unavailable('no store')),
            $this->extensionsWith(['mydir'])
        );
        $subject = new TemplateSubject(id: 'x', label: 'x', origin: 'test', content: 'A{{mydir "v"}}B');

        $divergences = $auditor->diff(
            [$subject],
            Mode::Compatible,
            static fn (): LegacyRender => new LegacyRender('AVB', [])
        );

        self::assertCount(1, $divergences);
        self::assertStringContainsString(
            'registers a directive processor for',
            (string)$divergences[0]->note
        );
    }

    /** @param string[] $names */
    private function extensionsWith(array $names): HostExtensions
    {
        $processors = [];
        foreach ($names as $name) {
            $processors[$name] = new class ($name) implements \Magento\Framework\Filter\SimpleDirective\ProcessorInterface {
                public function __construct(private string $name) {}
                public function getName(): string { return $this->name; }
                public function process($value, array $parameters, ?string $html): string { return ''; }
                public function getDefaultFilters(): ?array { return null; }
            };
        }

        return new HostExtensions(MagentoContext::fromObjectManager(new class ($processors) {
            public function __construct(private array $processors) {}
            public function get(string $class): ?object
            {
                return $class === \Magento\Framework\Filter\SimpleDirective\ProcessorPool::class
                    ? new \Magento\Framework\Filter\SimpleDirective\ProcessorPool($this->processors)
                    : null;
            }
        }));
    }

    /** A difference with no unwired directive in it gets no such excuse. */
    public function testAnOrdinaryDivergenceIsNotBlamedOnAPort(): void
    {
        $subject = new TemplateSubject(id: 'x', label: 'x', origin: 'test', content: 'plain');

        $divergences = $this->auditor()->diff(
            [$subject],
            Mode::Compatible,
            static fn (): LegacyRender => new LegacyRender('different', [])
        );

        self::assertCount(1, $divergences);
        self::assertNull($divergences[0]->note);
    }

    /** Without a store, the context is unavailable rather than fatal. */
    public function testDetectionDegradesWithoutAStore(): void
    {
        $context = MagentoContext::detect(sys_get_temp_dir());

        self::assertFalse($context->isAvailable());
        self::assertNotNull($context->reason());
        self::assertNull($context->get(\stdClass::class), 'resolving without a store must not throw');
    }

    public function testABrokenTemplateIsReportedWithAFix(): void
    {
        $subject = new TemplateSubject('t', 'broken', 'test', 'Hi {{if a}}there');

        $findings = $this->auditor()->check([$subject], Mode::Compatible);

        self::assertCount(1, $findings);
        self::assertNotNull($findings[0]->fix, 'a finding without advice is not much use');
        self::assertStringContainsString('never closed', $findings[0]->summary);
    }

    public function testStrictModeReportsAnUnknownVariableAndCompatibleDoesNot(): void
    {
        $subject = new TemplateSubject('t', 'typo', 'test', 'Hi {{var custmer}}');

        self::assertNotSame([], $this->auditor()->check([$subject], Mode::Strict));
        self::assertSame([], $this->auditor()->check([$subject], Mode::Compatible));
    }

    public function testAValidTemplateProducesNothing(): void
    {
        $subject = new TemplateSubject('t', 'fine', 'test', 'Hi {{var name}}', ['name' => 'Ada']);

        self::assertSame([], $this->auditor()->check([$subject], Mode::Strict));
    }

    /**
     * The excerpt has to show the difference it is an excerpt of.
     *
     * The lead-in was a fixed 20 bytes while the window is a parameter, so at `--show=20`
     * - the floor `DiffCommand` clamps to - the window ended one byte before the difference
     * and both sides printed identically.
     */
    public function testANarrowExcerptStillReachesTheDifference(): void
    {
        $divergence = new \Cresset\TemplateParser\Console\Divergence(
            new TemplateSubject(id: 'x', label: 'x', origin: 'test', content: ''),
            legacy: str_repeat('same', 10) . 'LEFT',
            candidate: str_repeat('same', 10) . 'RIGHT',
            note: null
        );

        [$legacy, $ours] = $divergence->excerpt(20);

        self::assertNotSame($legacy, $ours);
        self::assertStringContainsString('LEFT', $legacy);
        self::assertStringContainsString('RIGHT', $ours);
    }

    // ---------------------------------------------------------------- commands

    public function testCheckExitsNonZeroOnAnErrorSoCiCanUseIt(): void
    {
        $tester = $this->tester('check');
        $file = $this->writeTemplate('{{if a}}unclosed');

        $exit = $tester->execute(['path' => $file, '--mode' => 'strict']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('ERROR', $tester->getDisplay());
    }

    /**
     * The label carries the line, because it was the only positional information a merchant got.
     *
     * The engine computes a caret excerpt and a line number for every error; the text report
     * printed neither, so `check` over a codebase said which file and not where in it. The
     * JSON report already carried the line.
     */
    public function testAnErrorSaysWhichLineItIsOn(): void
    {
        $tester = $this->tester('check');
        $file = $this->writeTemplate("ok\nstill ok\n{{if a}}unclosed");

        $tester->execute(['path' => $file, '--mode' => 'strict']);

        self::assertMatchesRegularExpression('/ERROR .*:3$/m', $tester->getDisplay());
    }

    public function testCheckExitsZeroWhenTheTemplateIsFine(): void
    {
        $tester = $this->tester('check');
        $file = $this->writeTemplate('Hello, nothing to see here.');

        self::assertSame(0, $tester->execute(['path' => $file, '--mode' => 'strict']));
    }

    /** --fail-on decides what CI treats as a failure, so a first run is not a wall of red. */
    public function testFailOnRaisesTheBarForCi(): void
    {
        $tester = $this->tester('check');
        $file = $this->writeTemplate('Hi {{var custmer}}');

        self::assertSame(0, $tester->execute(['path' => $file, '--mode' => 'strict']), 'a warning is not an error');
        self::assertSame(1, $tester->execute(['path' => $file, '--mode' => 'strict', '--fail-on' => 'warning']));
    }

    /**
     * A misspelled --fail-on used to be a silently passing build.
     *
     * The severities nest, so anything unrecognised fell through to the error-only gate:
     * `--fail-on=warn` ran the whole scan, printed the warnings and exited 0. It is read
     * before the scan now, the way --mode and --source already were.
     */
    public function testAnUnknownFailOnIsRefusedBeforeTheScan(): void
    {
        $tester = $this->tester('check');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Use error, warning or note/');

        $tester->execute([
            'path' => $this->writeTemplate('Hi {{var custmer}}'),
            '--mode' => 'strict',
            '--fail-on' => 'warn',
        ]);
    }

    public function testCheckSpeaksJsonForCi(): void
    {
        $tester = $this->tester('check');
        $file = $this->writeTemplate('{{if a}}unclosed');

        $tester->execute(['path' => $file, '--mode' => 'compatible', '--format' => 'json']);
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('findings', $report);
        self::assertArrayHasKey('counts', $report);
        self::assertNotEmpty($report['findings']);
    }

    /** diff needs a store, and says so instead of pretending. */
    public function testDiffRefusesWithoutAStore(): void
    {
        $tester = $this->tester('diff');

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('needs a Magento store', $tester->getDisplay());
    }

    /** @param string[] $lines */
    private function repl(array $lines, array $arguments = []): string
    {
        $application = Application::create();
        $application->setAutoExit(false);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, implode("\n", [...$lines, ':quit']) . "\n");
        rewind($stream);

        $input = new \Symfony\Component\Console\Input\ArrayInput($arguments);
        $input->setStream($stream);
        $input->setInteractive(false);
        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        $application->find('repl')->run($input, $output);

        return $output->fetch();
    }

    public function testReplRendersPipedInput(): void
    {
        $display = $this->repl([':set name=Ada', 'Dear {{var name}},']);

        self::assertStringContainsString('Dear Ada,', $display);
    }

    public function testReplSwitchesModeAndReportsErrors(): void
    {
        $display = $this->repl([':mode strict', ':set customer_name=Ada', '{{var custmer_name}}']);

        self::assertStringContainsString('Unknown variable', $display);
        // The engine's did-you-mean only fires when there is something to suggest, which is
        // the case worth asserting: the hint has to survive all the way to the terminal.
        self::assertStringContainsString('did you mean', $display);
    }

    public function testReplListsWhatIsWired(): void
    {
        $display = $this->repl([':directives']);

        // No store here, so only the built-ins.
        self::assertStringContainsString('var', $display);
        self::assertStringContainsString('depend', $display);
    }

    /**
     * :set produces typed values, not only strings.
     *
     * The difference is the whole reason the REPL is useful for this engine: {{if qty}}
     * behaves differently for int 0 and string "0", and a REPL that could only make strings
     * could not show either half of it.
     */
    #[DataProvider('typedValues')]
    public function testSetProducesTypedValues(string $literal, string $expectedDescription): void
    {
        $display = $this->repl([sprintf(':set v=%s', $literal), ':vars']);

        self::assertStringContainsString($expectedDescription, $display, $literal);
    }

    public static function typedValues(): array
    {
        return [
            'int'            => ['0', 'int     0'],
            'quoted is text' => ['"0"', "string  '0'"],
            'float'          => ['1.5', 'float   1.5'],
            'bool true'      => ['true', 'bool    true'],
            'bool false'     => ['false', 'bool    false'],
            'null'           => ['null', 'null'],
            'json list'      => ['[1,2]', 'array   [1,2]'],
            'json object'    => ['{"a":1}', 'array   {"a":1}'],
            'bare word'      => ['Ada', "string  'Ada'"],
            'not json after all' => ['[oops', "string  '[oops'"],
        ];
    }

    /** A `:set` int and a JSON list arrive as an int and an array, not as strings. */
    public function testTypedValuesReachTheEngine(): void
    {
        $strict = $this->repl([
            ':set qty=0',
            ':set xs=[1,2]',
            '{{if qty}}truthy{{else}}falsy{{/if}}',
            '{{for i in xs}}[{{var i}}]{{/for}}',
        ], ['--mode' => 'strict']);

        self::assertStringContainsString('falsy', $strict, 'int 0 is falsy under standard truthiness');
        self::assertStringContainsString('[1][2]', $strict, 'a JSON list should be iterable');
    }

    /** ...and compatible mode disagrees about 0, which is the legacy quirk. */
    public function testCompatibleModeTreatsIntZeroAsTruthy(): void
    {
        $display = $this->repl([':set qty=0', '{{if qty}}truthy{{else}}falsy{{/if}}'], ['--mode' => 'compatible']);

        self::assertStringContainsString('truthy', $display);
    }

    public function testReplRejectsAnUnknownColonCommand(): void
    {
        self::assertStringContainsString('unknown command', $this->repl([':nope']));
    }

    public function testEveryCommandIsAvailableInBothEntrypoints(): void
    {
        $standalone = array_map(
            static fn ($c): string => (string)$c->getName(),
            Application::commands()
        );
        sort($standalone);

        self::assertSame(['check', 'diff', 'repl'], $standalone);

        // The magerun subclasses exist for each, renamed into magerun's shared namespace.
        foreach (['Repl', 'Check', 'Diff'] as $name) {
            $class = 'Cresset\\TemplateParser\\Console\\Magerun\\' . $name . 'Command';
            self::assertTrue(class_exists($class), $class . ' is missing');
            self::assertSame('template-parser:' . strtolower($name), (new $class())->getName());
        }
    }

    public function testTheCodebaseSourceIsInertWithoutARoot(): void
    {
        $source = new CodebaseEmailTemplates(null);

        self::assertFalse($source->isAvailable());
        self::assertSame([], iterator_to_array($source->subjects()));
    }

    private function tester(string $command): CommandTester
    {
        $application = Application::create();
        $application->setAutoExit(false);

        return new CommandTester($application->find($command));
    }

    private function writeTemplate(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'tpl') . '.html';
        file_put_contents($file, $content);

        return $file;
    }

}
