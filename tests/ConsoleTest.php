<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Console\Application;
use Cresset\TemplateParser\Console\Auditor;
use Cresset\TemplateParser\Console\EngineFactory;
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

    // ---------------------------------------------------------------- commands

    public function testCheckExitsNonZeroOnAnErrorSoCiCanUseIt(): void
    {
        $tester = $this->tester('check');
        $file = $this->writeTemplate('{{if a}}unclosed');

        $exit = $tester->execute(['path' => $file, '--mode' => 'strict']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('ERROR', $tester->getDisplay());
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

    /** int 0 and string "0" reach the engine as different things. */
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
