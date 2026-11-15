<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test\DeliberateDifferenceFixtures {
    // The framework filter's constructor, as each line declares it. Only the parameter TYPES
    // are read, so neither class need exist.
    final class PreNeutralizerFilter
    {
        public function __construct(
            \Magento\Framework\Stdlib\StringUtils $string,
            $variables = [],
            $directiveProcessors = [],
            ?\Magento\Framework\Filter\VariableResolverInterface $variableResolver = null,
            ?\Magento\Framework\Filter\Template\SignatureProvider $signatureProvider = null,
            ?\Magento\Framework\Filter\Template\FilteringDepthMeter $filteringDepthMeter = null
        ) {
        }
    }

    final class NeutralizerFilter
    {
        public function __construct(
            \Magento\Framework\Stdlib\StringUtils $string,
            $variables = [],
            $directiveProcessors = [],
            ?\Magento\Framework\Filter\VariableResolverInterface $variableResolver = null,
            ?\Magento\Framework\Filter\Template\SignatureProvider $signatureProvider = null,
            ?\Magento\Framework\Filter\Template\FilteringDepthMeter $filteringDepthMeter = null,
            ?\Magento\Framework\Filter\Template\DirectiveOutputNeutralizer $directiveOutputNeutralizer = null
        ) {
        }
    }

    /** Not the framework filter's shape at all: says nothing. */
    final class UnrecognisedFilter
    {
        public function __construct(array $variables = [])
        {
        }
    }

    final class ConstructorlessFilter
    {
    }
}

namespace MageOS\TemplateParser\Test {

    use MageOS\TemplateParser\Console\Auditor;
    use MageOS\TemplateParser\Console\Command\DiffCommand;
    use MageOS\TemplateParser\Console\Command\ShadowReportCommand;
    use MageOS\TemplateParser\Console\Divergence;
    use MageOS\TemplateParser\Console\EngineFactory;
    use MageOS\TemplateParser\Console\LegacyRender;
    use MageOS\TemplateParser\Console\MagentoContext;
    use MageOS\TemplateParser\Console\Mode;
    use MageOS\TemplateParser\Console\Shadow\ShadowReport;
    use MageOS\TemplateParser\Console\Shadow\ShadowTable;
    use MageOS\TemplateParser\Console\StoreEmulator;
    use MageOS\TemplateParser\Console\TemplateSubject;
    use MageOS\TemplateParser\DeliberateDifference;
    use MageOS\TemplateParser\HostServices;
    use MageOS\TemplateParser\Magento\Config\EngineMode;
    use MageOS\TemplateParser\Magento\HostOutputNeutralizer;
    use MageOS\TemplateParser\Magento\Plugin\TemplateFilterPlugin;
    use MageOS\TemplateParser\Magento\Shadow\ShadowOutcome;
    use MageOS\TemplateParser\Magento\Shadow\ShadowRecorder;
    use MageOS\TemplateParser\Magento\Shadow\TemplateIdentity;
    use MageOS\TemplateParser\Magento\ShadowComparator;
    use MageOS\TemplateParser\Magento\TemplateFilterAdapter;
    use MageOS\TemplateParser\Options;
    use MageOS\TemplateParser\TemplateEngine;
    use MageOS\TemplateParser\Test\DeliberateDifferenceFixtures\ConstructorlessFilter;
    use MageOS\TemplateParser\Test\DeliberateDifferenceFixtures\NeutralizerFilter;
    use MageOS\TemplateParser\Test\DeliberateDifferenceFixtures\PreNeutralizerFilter;
    use MageOS\TemplateParser\Test\DeliberateDifferenceFixtures\UnrecognisedFilter;
    use Magento\Framework\App\Config\ScopeConfigInterface;
    use Magento\Framework\App\ResourceConnection;
    use Magento\Framework\Filter\Template as LegacyTemplate;
    use Magento\Store\Model\StoreManagerInterface;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Console\Output\BufferedOutput;
    use Symfony\Component\Console\Tester\CommandTester;

    /**
     * The one difference from a filter without the output neutralizer that this engine makes
     * on purpose, and nothing else passing for it.
     *
     * On Mage-OS before 3.5.0 a value containing `{{` is printed as written or re-parsed;
     * this engine encodes it and never re-parses a value. Those renders are kept as they are -
     * recorded as deliberate, never as a divergence, never a reason to fall back - but only
     * when that is shown to be the whole difference.
     */
    final class DeliberateDifferenceTest extends TestCase
    {
        private const FIXTURE_340 = __DIR__ . '/fixtures/legacy/address-formats-3.4.0.json';

        /** @var list<array{0:?string,1:?array,2:bool,3:bool}> outcome, detail, served, fell back */
        private array $records = [];

        protected function setUp(): void
        {
            $this->records = [];
        }

        // ------------------------------------------------------------ recorded on 3.4.0

        /**
         * Every address format, rendered by Mage-OS 3.4.0's own FilterManager::template() -
         * recorded with tools/record-address-formats.php - with directive-shaped values in the
         * fields a customer types. A render either agrees, or differs only by the deliberate
         * difference. Re-parsing included: 3.4.0 prints `Dr Jansen Jansen` for a first name of
         * `{{var lastname}}`.
         *
         * @return iterable<string,array{0:string,1:array<string,mixed>,2:string}>
         */
        public static function recordedOn340(): iterable
        {
            $fixture = json_decode((string)file_get_contents(self::FIXTURE_340), true, 512, JSON_THROW_ON_ERROR);
            foreach ($fixture['cases'] as $name => $case) {
                yield $name => [$fixture['formats'][$case['format']], $case['set'] + $fixture['base'], $case['legacy']];
            }
        }

        /** @param array<string,mixed> $variables */
        #[DataProvider('recordedOn340')]
        public function testEveryRecorded340AddressAgreesOrDiffersOnlyDeliberately(string $format, array $variables, string $legacy): void
        {
            [$ours, $marked] = $this->plainRenders($format, $variables);

            if ($ours === $legacy) {
                self::assertFalse(DeliberateDifference::explains($ours, $marked, $legacy . 'x'));
                return;
            }

            self::assertStringContainsString('&#123;', $ours, 'differs without anything the neutralizer encoded');
            self::assertTrue(DeliberateDifference::explains($ours, $marked, $legacy), "not explained:\n$legacy\n---\n$ours");
        }

        /** The share the 3.4.0 store showed, so the fixture is the one meant: most of these differ. */
        public function testThe340RecordingDiffersWhereverAValueHoldsAnOpener(): void
        {
            $agree = $deliberate = 0;
            foreach (self::recordedOn340() as [$format, $variables, $legacy]) {
                [$ours] = $this->plainRenders($format, $variables);
                $ours === $legacy ? $agree++ : $deliberate++;
            }

            self::assertSame([82, 234], [$agree, $deliberate]);
        }

        /**
         * Precision: anything else differing in the same render is a divergence. Each recorded
         * deliberate difference, given one more change - before the value, after it, at either
         * end - is no longer explained.
         *
         * @param array<string,mixed> $variables
         */
        #[DataProvider('recordedOn340')]
        public function testAnyOtherChangeInTheSameRenderIsNotExplained(string $format, array $variables, string $legacy): void
        {
            [$ours, $marked] = $this->plainRenders($format, $variables);
            if ($ours === $legacy) {
                self::assertTrue(true);
                return;
            }

            // Not inside the value's own construct: there legacy may print anything, which is
            // what re-parsing it is. A value at the very start of the render is all construct.
            $at = $this->firstDifference($legacy, $ours);
            $mutations = [
                'appended' => $legacy . 'X',
                'last byte dropped' => substr($legacy, 0, -1),
            ];
            if ($at > 0) {
                $mutations['first byte'] = "\x01" . substr($legacy, 1);
                $mutations['just before the value'] = substr($legacy, 0, $at - 1) . "\x01" . substr($legacy, $at);
            }
            foreach ($mutations as $what => $mutated) {
                if ($mutated === $legacy) {
                    continue;
                }
                self::assertFalse(DeliberateDifference::explains($ours, $marked, $mutated), $what);
            }
        }

        // ------------------------------------------------------------ the rule itself

        /** @return iterable<string,array{0:string,1:array<string,mixed>,2:string,3:bool}> */
        public static function rules(): iterable
        {
            $street = ['street' => 'Hoofdstraat 1 {{var city}}', 'city' => 'Utrecht'];
            $format = '{{var street}}, {{var city}}';

            yield 're-parsed, as 3.4.0 does it' => [$format, $street, 'Hoofdstraat 1 Utrecht, Utrecht', true];
            yield 'printed as written' => [$format, $street, 'Hoofdstraat 1 {{var city}}, Utrecht', true];
            yield 're-parsed to nothing' => [$format, $street, 'Hoofdstraat 1 , Utrecht', true];
            yield 'a bare opener, printed' => [$format, ['street' => 'a {{ b', 'city' => 'U'], 'a {{ b, U', true];
            yield 'a bare opener is not a wildcard' => [$format, ['street' => 'a {{ b', 'city' => 'U'], 'a ANYTHING b, U', false];
            yield 'the value around it differs' => [$format, $street, 'Hoofdstraat 2 Utrecht, Utrecht', false];
            yield 'the template around it differs' => [$format, $street, 'Hoofdstraat 1 Utrecht; Utrecht', false];
            yield 'another value differs' => [$format, $street, 'Hoofdstraat 1 Utrecht, Amsterdam', false];
            yield 'text after the construct differs' => [
                '{{var street}}!',
                ['street' => '{{var city}} <b>', 'city' => 'U'],
                'U &lt;b&gt;!',
                false,
            ];
            yield 'a re-parse reaching into the template' => [
                '{{var street}}var city}}',
                ['street' => 'x {{', 'city' => 'U'],
                'x U',
                false,
            ];
        }

        /** @param array<string,mixed> $variables */
        #[DataProvider('rules')]
        public function testOnlyTheValuesConstructsMayDiffer(string $format, array $variables, string $legacy, bool $explained): void
        {
            [$ours, $marked] = $this->plainRenders($format, $variables);

            self::assertSame($explained, DeliberateDifference::explains($ours, $marked, $legacy));
        }

        public function testMarksAnywhereButTheMarkedRenderAreRefused(): void
        {
            [$ours, $marked] = $this->plainRenders('{{var a}}', ['a' => '{{var b}}', 'b' => 'B']);

            self::assertTrue(DeliberateDifference::explains($ours, $marked, 'B'));
            self::assertFalse(DeliberateDifference::explains($ours, $marked, 'B' . DeliberateDifference::OPEN));
            self::assertFalse(DeliberateDifference::explains($ours, $marked, DeliberateDifference::END . 'B'));
            self::assertFalse(DeliberateDifference::explains($ours, str_replace(DeliberateDifference::OPEN, '{{', $marked), 'B'), 'no marks, nothing explained');
        }

        /** A marked render that is not the candidate's - a block drawing something new - explains nothing. */
        public function testAMarkedRenderThatIsNotTheCandidatesExplainsNothing(): void
        {
            [$ours, $marked] = $this->plainRenders('{{var a}}-{{var n}}', ['a' => '{{var b}}', 'b' => 'B', 'n' => '1']);
            self::assertTrue(DeliberateDifference::explains($ours, $marked, 'B-1'));

            [, $later] = $this->plainRenders('{{var a}}-{{var n}}', ['a' => '{{var b}}', 'b' => 'B', 'n' => '2']);
            self::assertFalse(DeliberateDifference::explains($ours, $later, 'B-2'));
        }

        /** Marking is a comparison posture: it does nothing outside compatible mode, and never parses a value. */
        public function testMarkingNeverParsesAValue(): void
        {
            $marked = TemplateEngine::withOptions(Options::compatible()->withNeutralizedOutputMarked(true))
                ->render('[{{var a}}]', ['a' => '{{var b}}', 'b' => 'SECRET']);

            self::assertSame('[' . DeliberateDifference::OPEN . 'var b}}' . DeliberateDifference::END . ']', $marked);
            self::assertSame(
                '[{{var b}}]',
                TemplateEngine::withOptions(Options::lenient()->withNeutralizedOutputMarked(true))->render('[{{var a}}]', ['a' => '{{var b}}'])
            );
        }

        // ------------------------------------------------------------ reading the host

        public function testAFilterWithoutTheNeutralizerArgumentPredatesIt(): void
        {
            self::assertFalse(HostOutputNeutralizer::encodesValues(PreNeutralizerFilter::class));
            self::assertTrue(HostOutputNeutralizer::encodesValues(NeutralizerFilter::class));
        }

        /** Anything it cannot read is the current filter, so a misreading can only hide nothing. */
        public function testWhatItCannotReadIsTheCurrentFilter(): void
        {
            self::assertTrue(HostOutputNeutralizer::encodesValues('No\\Such\\Filter'));
            self::assertTrue(HostOutputNeutralizer::encodesValues(UnrecognisedFilter::class));
            self::assertTrue(HostOutputNeutralizer::encodesValues(ConstructorlessFilter::class));
            // The test stub of Framework\Filter\Template has no constructor.
            self::assertTrue(HostOutputNeutralizer::encodesValues());
        }

        /** Read off the real trees: 3.4.0 has no neutralizer argument, 3.5.0 and 3.6.0 do. */
        public function testTheRealFiltersReadAsTheyShould(): void
        {
            $repo = getenv('MAGEOS_SOURCE') ?: (getenv('HOME') . '/mageos-magento2');
            if (!is_dir($repo . '/.git')) {
                self::markTestSkipped('no Mage-OS checkout to read the filters from');
            }

            foreach (['3.4.0' => false, '3.5.0' => true, '3.6.0' => true] as $tag => $encodes) {
                $source = shell_exec(sprintf('git -C %s show %s:lib/internal/Magento/Framework/Filter/Template.php 2>/dev/null', escapeshellarg($repo), escapeshellarg($tag)));
                if (!is_string($source) || $source === '') {
                    self::markTestSkipped("no $tag in $repo");
                }
                $namespace = 'MageOS\\TemplateParser\\Test\\Real' . str_replace('.', '', $tag);
                if (!class_exists($namespace . '\\Template', false)) {
                    // The constructor alone, under a namespace of its own: the rest of the
                    // class needs the framework.
                    preg_match('/\n(use [^\n]+;\n)+/', $source, $uses);
                    preg_match('/public function __construct\((.*?)\)\s*\{/s', $source, $constructor);
                    eval(sprintf("namespace %s;\n%s\nclass Template { public function __construct(%s) {} }", $namespace, $uses[0] ?? '', $constructor[1]));
                }
                self::assertSame($encodes, HostOutputNeutralizer::encodesValues($namespace . '\\Template'), $tag);
            }
        }

        // ------------------------------------------------------------ Shadow and Parser

        public function testShadowOnAPreNeutralizerHostRecordsItAsDeliberate(): void
        {
            $plugin = $this->plugin(EngineMode::SHADOW, encodes: false);
            $subject = $this->plainFilter(['street' => 'Hoofdstraat 1 {{var city}}', 'city' => 'Utrecht'], $plugin);

            self::assertSame('Hoofdstraat 1 Utrecht, Utrecht', $this->filter($plugin, $subject, '{{var street}}, {{var city}}', 'Hoofdstraat 1 Utrecht, Utrecht'));

            self::assertSame(ShadowOutcome::DELIBERATE, $this->records[0][0]);
            self::assertSame(['legacy_length' => 30, 'candidate_length' => 45, 'first_difference_at' => 14], $this->records[0][1]);
        }

        public function testShadowStillReportsAnyOtherDifferenceInTheSameRender(): void
        {
            $plugin = $this->plugin(EngineMode::SHADOW, encodes: false);
            $subject = $this->plainFilter(['street' => 'Hoofdstraat 1 {{var city}}', 'city' => 'Utrecht'], $plugin);

            $this->filter($plugin, $subject, '{{var street}}, {{var city}}', 'Hoofdstraat 1 Utrecht; Utrecht');

            self::assertSame(ShadowOutcome::DIVERGE, $this->records[0][0]);
        }

        /** On the current filter nothing is looked for: the same render is a divergence. */
        public function testOnACurrentHostTheSameRenderIsADivergence(): void
        {
            $plugin = $this->plugin(EngineMode::SHADOW, encodes: true);
            $subject = $this->plainFilter(['street' => 'Hoofdstraat 1 {{var city}}', 'city' => 'Utrecht'], $plugin);

            $this->filter($plugin, $subject, '{{var street}}, {{var city}}', 'Hoofdstraat 1 Utrecht, Utrecht');

            self::assertSame(ShadowOutcome::DIVERGE, $this->records[0][0]);
        }

        /** And on the current filter, the two agree - the module's output did not change. */
        public function testOnACurrentHostTheEncodedRenderAgrees(): void
        {
            $plugin = $this->plugin(EngineMode::SHADOW, encodes: true);
            $subject = $this->plainFilter(['street' => 'Hoofdstraat 1 {{var city}}', 'city' => 'Utrecht'], $plugin);

            $this->filter($plugin, $subject, '{{var street}}, {{var city}}', 'Hoofdstraat 1 &#123;&#123;var city}}, Utrecht');

            self::assertSame(ShadowOutcome::AGREE, $this->records[0][0]);
        }

        /** Parser serves its own render - never the re-parsed one - and the sample records it as deliberate. */
        public function testParserServesItsOwnRenderAndRecordsTheSampleAsDeliberate(): void
        {
            $plugin = $this->plugin(EngineMode::PARSER, encodes: false, rate: '100');
            $subject = $this->plainFilter(['street' => 'Hoofdstraat 1 {{var city}}', 'city' => 'Utrecht'], $plugin);

            $served = $this->filter($plugin, $subject, '{{var street}}, {{var city}}', 'Hoofdstraat 1 Utrecht, Utrecht');

            self::assertSame('Hoofdstraat 1 &#123;&#123;var city}}, Utrecht', $served);
            self::assertSame([ShadowOutcome::DELIBERATE, true, false], [$this->records[0][0], $this->records[0][2], $this->records[0][3]]);
        }

        public function testAnAdapterThatCannotMarkLeavesItADivergence(): void
        {
            $adapter = new class (new HostServices(), plainFilter: true) extends TemplateFilterAdapter {
                public function filterMarked(string $value): ?array { return null; }
            };
            $comparator = new ShadowComparator($adapter, hostEncodesValues: false);

            $outcome = $comparator->compare('{{var a}}', 'B', ['a' => '{{var b}}', 'b' => 'B']);

            self::assertSame(ShadowOutcome::DIVERGE, $outcome->outcome);
        }

        // ------------------------------------------------------------ recorded and reported

        public function testADeliberateDifferenceIsCountedApartAndAsClean(): void
        {
            $queries = [];
            $recorder = new ShadowRecorder(
                new class ($queries) extends ResourceConnection {
                    public function __construct(private array &$queries) {}
                    public function getTableName($modelEntity, $connectionName = 'default') { return $modelEntity; }
                    public function getConnection($resourceName = 'default')
                    {
                        return new class ($this->queries) {
                            public function __construct(private array &$queries) {}
                            public function query($sql, $bind = []) { $this->queries[] = [$sql, $bind]; }
                        };
                    }
                },
                new class implements StoreManagerInterface { public function getStore($storeId = null) { return null; } },
                new CollectingLogger(),
                false
            );

            $recorder->record(1, 'address:html', ShadowOutcome::agreed());
            $recorder->record(1, 'address:html', ShadowOutcome::deliberate(30, 43, 14));
            $recorder->record(1, 'address:html', ShadowOutcome::deliberate(30, 43, 14), served: true);
            $recorder->flush();

            [$sql, $bind] = $queries[0];
            preg_match('/\(([^)]*)\) VALUES/', $sql, $m);
            $row = array_combine(array_map(static fn ($c) => trim($c, ' `'), explode(',', $m[1])), $bind);

            self::assertSame([1, 2, 0, 3, null], [$row['agreed'], $row['deliberate'], $row['diverged'], $row['renders_since_divergence'], $row['last_divergence_at']]);
            self::assertStringContainsString('`deliberate` = `deliberate` + VALUES(`deliberate`)', $sql);
        }

        public function testTheReportListsThemExplainsThemAndStaysClean(): void
        {
            $db = (new SqliteConnection())->createShadowTable()
                ->insertShadow(['store_id' => 1, 'template' => 'address:html', 'agreed' => 17, 'deliberate' => 8, 'renders_since_divergence' => 25]);

            $command = new ShadowReportCommand();
            $command->setMagentoContext(MagentoContext::unavailable('tests'));
            $command->setShadowTable(new ShadowTable($db));
            $tester = new CommandTester($command);
            $tester->execute([]);
            $display = $tester->getDisplay();

            self::assertSame(0, $tester->getStatusCode(), $display);
            self::assertStringContainsString('1 template(s), 25 render(s)', $display);
            self::assertStringContainsString('17 agreed, 0 diverged, 0 refused, 0 crashed, 8 deliberate', $display);
            self::assertStringContainsString('DELIBERATE address:html', $display);
            self::assertStringContainsString('8 of 25 render(s)', $display);
            self::assertStringContainsString('predates the output neutralizer', $display);
            self::assertStringNotContainsString('DIVERGED', $display);

            $json = new CommandTester((new ShadowReportCommand())->setMagentoContext(MagentoContext::unavailable('tests'))->setShadowTable(new ShadowTable($db)));
            $json->execute(['--format' => 'json']);
            $report = json_decode($json->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($report['clean']);
            self::assertSame(8, $report['stores'][0]['deliberate']);
            self::assertSame(8, $report['stores'][0]['templates_detail'][0]['deliberate']);
            self::assertSame(ShadowReportCommand::DELIBERATE, $report['deliberate_note']);

            self::assertStringContainsString(', 8 deliberate', ShadowReport::oneLine((new ShadowReport((new ShadowTable($db))->rows(), (new ShadowTable($db))->stores(true)))->stores()[0]));
        }

        public function testAReportWithoutAnyKeepsItsOldShape(): void
        {
            $db = (new SqliteConnection())->createShadowTable()
                ->insertShadow(['store_id' => 1, 'template' => 'address:html', 'agreed' => 5, 'renders_since_divergence' => 5]);

            $tester = new CommandTester((new ShadowReportCommand())->setMagentoContext(MagentoContext::unavailable('tests'))->setShadowTable(new ShadowTable($db)));
            $tester->execute([]);

            self::assertStringContainsString("5 agreed, 0 diverged, 0 refused, 0 crashed\n", $tester->getDisplay());
            self::assertStringNotContainsString('DELIBERATE', $tester->getDisplay());
        }

        // ------------------------------------------------------------ template:diff

        public function testDiffLabelsItDeliberateOnAPreNeutralizerHost(): void
        {
            $divergences = $this->diff(encodes: false, legacy: 'Hoofdstraat 1 Utrecht, Utrecht');

            self::assertCount(1, $divergences);
            self::assertTrue($divergences[0]->deliberate);
            self::assertSame(Auditor::DELIBERATE_NOTE, $divergences[0]->note);
        }

        public function testDiffKeepsAnyOtherDifferenceADivergence(): void
        {
            self::assertFalse($this->diff(encodes: false, legacy: 'Hoofdstraat 1 Utrecht; Utrecht')[0]->deliberate);
            self::assertFalse($this->diff(encodes: true, legacy: 'Hoofdstraat 1 Utrecht, Utrecht')[0]->deliberate);
        }

        public function testDiffListsThemApartAndTheyDoNotFail(): void
        {
            $subject = new TemplateSubject(id: 'a', label: 'address', origin: 'test', content: '');
            $deliberate = new Divergence($subject, 'Dr Jansen Jansen', 'Dr &#123;&#123;var lastname}} Jansen', Auditor::DELIBERATE_NOTE, deliberate: true);
            $real = new Divergence(new TemplateSubject(id: 'b', label: 'other', origin: 'test', content: ''), 'LEFT', 'RIGHT', null);

            [$exit, $text] = $this->report('reportText', [$deliberate], true);
            self::assertSame(0, $exit, 'a deliberate difference failed --fail-on-divergence');
            self::assertStringContainsString('deliberate address', $text);
            self::assertStringContainsString('0 of 2 templates differ, 1 more deliberately', $text);
            self::assertStringNotContainsString('differs address', $text);

            [$exit] = $this->report('reportText', [$deliberate, $real], true);
            self::assertSame(1, $exit);

            [$exit, $json] = $this->report('reportJson', [$deliberate, $real], true);
            $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $exit);
            self::assertSame([1, 1], [$report['divergent'], $report['deliberate']]);
            self::assertSame('b', $report['divergences'][0]['id']);
            self::assertSame('a', $report['deliberate_differences'][0]['id']);
        }

        // ------------------------------------------------------------ helpers

        /**
         * The module's render of an address format, and the same render marked.
         *
         * @param array<string,mixed> $variables
         * @return array{0:string,1:string}
         */
        private function plainRenders(string $format, array $variables): array
        {
            $adapter = new TemplateFilterAdapter(new HostServices(), plainFilter: true);
            $ours = $adapter->setVariables($variables)->filter($format);
            self::assertSame([], $adapter->violations());
            $marked = $adapter->filterMarked($format);
            self::assertNotNull($marked);

            return [$ours, $marked[0]];
        }

        private function firstDifference(string $a, string $b): int
        {
            $n = min(strlen($a), strlen($b));
            for ($i = 0; $i < $n && $a[$i] === $b[$i]; $i++);

            return $i;
        }

        private function plugin(string $stage, bool $encodes, string $rate = '0'): TemplateFilterPlugin
        {
            $mode = new EngineMode(new class ($stage, $rate) implements ScopeConfigInterface {
                public function __construct(private string $stage, private string $rate) {}
                public function getValue($path, $scope = 'default', $scopeCode = null)
                {
                    return $path === EngineMode::XML_PATH_PARSER_SHADOW_RATE ? $this->rate : $this->stage;
                }
                public function isSetFlag($path, $scope = 'default', $scopeCode = null) { return false; }
            });
            $recorder = new class ($this->records) extends ShadowRecorder {
                public function __construct(private array &$records) {}
                public function record(int|string|null $storeId, string $template, ?ShadowOutcome $outcome, bool $served = false, bool $fellBack = false): void
                {
                    $this->records[] = [$outcome?->outcome, $outcome?->detail, $served, $fellBack];
                }
            };

            return new TemplateFilterPlugin(
                new ShadowComparator(new TemplateFilterAdapter(new HostServices()), hostEncodesValues: $encodes),
                $mode,
                new TemplateIdentity(),
                $recorder,
                null,
                new ShadowComparator(new TemplateFilterAdapter(new HostServices(), plainFilter: true), hostEncodesValues: $encodes)
            );
        }

        /** @param array<string,mixed> $variables */
        private function plainFilter(array $variables, TemplateFilterPlugin $plugin): LegacyTemplate
        {
            $subject = new LegacyTemplate();
            $plugin->beforeSetVariables($subject, $variables);

            return $subject;
        }

        private function filter(TemplateFilterPlugin $plugin, LegacyTemplate $subject, string $source, string $legacy): mixed
        {
            return $plugin->aroundFilter($subject, static fn (): string => $legacy, $source);
        }

        /** @return Divergence[] */
        private function diff(bool $encodes, string $legacy): array
        {
            $magento = MagentoContext::unavailable('no store in tests');
            $auditor = new Auditor(new EngineFactory($magento, hostEncodesValues: $encodes), new StoreEmulator($magento));
            $variables = ['street' => 'Hoofdstraat 1 {{var city}}', 'city' => 'Utrecht'];

            return $auditor->diff(
                [new TemplateSubject(id: 'x', label: 'x', origin: 'test', content: '{{var street}}, {{var city}}')],
                Mode::Compatible,
                static fn (): LegacyRender => new LegacyRender($legacy, $variables)
            );
        }

        /**
         * @param Divergence[] $divergences
         * @return array{0:int,1:string}
         */
        private function report(string $method, array $divergences, bool $failOnDivergence): array
        {
            $output = new BufferedOutput();
            $exit = (new \ReflectionMethod(DiffCommand::class, $method))
                ->invoke(new DiffCommand(), $divergences, 2, $output, 60, $failOnDivergence);

            return [$exit, $output->fetch()];
        }
    }
}
