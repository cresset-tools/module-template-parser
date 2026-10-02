<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\Magento\AllowlistedLayoutRenderer;
use Cresset\TemplateParser\Magento\Config\EngineMode;
use Cresset\TemplateParser\Magento\Plugin\TemplateFilterPlugin;
use Cresset\TemplateParser\Magento\Shadow\ShadowOutcome;
use Cresset\TemplateParser\Magento\Shadow\ShadowRecorder;
use Cresset\TemplateParser\Magento\Shadow\TemplateIdentity;
use Cresset\TemplateParser\Magento\ShadowComparator;
use Cresset\TemplateParser\Magento\TemplateFilterAdapter;
use Cresset\TemplateParser\Magento\TemplateFilterInterface;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\Port\BlockRenderer;
use Cresset\TemplateParser\Port\LayoutRenderer;
use Cresset\TemplateParser\Port\RefusedByPort;
use Cresset\TemplateParser\PolicyViolation;
use Cresset\TemplateParser\RenderPolicy;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filter\Template as LegacyTemplate;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Parser mode: this engine's render is served, and anything it declines goes to the filter.
 *
 * The promise is narrow and checkable. A render this engine completes is served without the
 * filter running; a render it declines - refused, the host raising, a crash - is served by the
 * filter, byte for byte what Legacy would have served. So the only way Parser can do worse
 * than Legacy is by serving different output without raising, which is what Shadow measures
 * beforehand and the sampled comparison keeps measuring afterwards.
 */
final class ParserModeTest extends TestCase
{
    /** @var list<array{0:?string,1:?array,2:int|string|null,3:string,4:bool,5:bool}> */
    private array $records = [];

    /** How many times the filter itself ran. */
    private int $legacyRuns = 0;

    protected function setUp(): void
    {
        $this->records = [];
        $this->legacyRuns = 0;
    }

    // ---------------------------------------------------------------- serving

    public function testARenderThisEngineCompletesIsServedWithoutRunningTheFilter(): void
    {
        $plugin = $this->plugin($this->adapter());
        $subject = $this->filterFor(1);
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        $result = $this->filter($plugin, $subject, 'Dear {{var name}},', 'LEGACY');

        self::assertSame('Dear Ada,', $result);
        self::assertSame(0, $this->legacyRuns, 'the filter ran for a render Parser served');
        self::assertSame([[null, null, 1, 'unidentified:' . LegacyTemplate::class, true, false]], $this->records);
    }

    public function testARefusalFallsBackToTheFilter(): void
    {
        // Strict mode refuses an unknown variable: a TemplateError.
        $plugin = $this->plugin($this->adapter(Options::strict()));
        $subject = $this->filterFor(1);
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        $result = $this->filter($plugin, $subject, 'Dear {{var nope}},', 'LEGACY');

        self::assertSame('LEGACY', $result);
        self::assertSame(1, $this->legacyRuns);
        self::assertSame(ShadowOutcome::REFUSED, $this->records[0][0]);
        self::assertSame([false, true], array_slice($this->records[0], 4), 'not served, fell back');
    }

    public function testACrashFallsBackToTheFilter(): void
    {
        $plugin = $this->plugin($this->raising(new \TypeError('boom')));
        $subject = $this->filterFor(1);

        self::assertSame('LEGACY', $this->filter($plugin, $subject, 'x', 'LEGACY'));
        self::assertSame(ShadowOutcome::CRASHED, $this->records[0][0]);
        self::assertTrue($this->records[0][5]);
    }

    /**
     * The host raising falls back too, though the adapter caught it.
     *
     * The adapter answers a block's exception with its own error text, imitating the filter's
     * catch. The filter's own is what a customer gets today - a different message in production,
     * and a critical log entry - so it is the filter that renders it.
     */
    public function testTheHostRaisingFallsBackRatherThanServingTheAdaptersErrorText(): void
    {
        $adapter = new TemplateFilterAdapter(new HostServices(blocks: new class implements BlockRenderer {
            public function render(string $class, array $data, string $method): string
            {
                throw new \RuntimeException('block fell over');
            }
        }), Options::compatible());
        $plugin = $this->plugin($adapter);
        $subject = $this->filterFor(1);
        $plugin->beforeSetVariables($subject, ['x' => 1]);

        $result = $this->filter($plugin, $subject, '{{block class="Magento\\Cms\\Block\\Block"}}', 'We\'re sorry');

        self::assertSame('We\'re sorry', $result);
        self::assertSame(ShadowOutcome::REFUSED, $this->records[0][0]);
        self::assertStringContainsString('block fell over', $this->records[0][1]['problem']);
        self::assertTrue($this->records[0][5]);
    }

    /**
     * A render the policy cut short falls back too: what it skipped, the filter renders.
     *
     * Found on a real store: with no layout handle allowed, {{layout}} rendered nothing and
     * Parser served every order email without its item table. Nothing raised, so "fall back
     * on an exception" did not catch it; the skip is now recorded, and recorded skips decline.
     */
    public function testARenderThatSkippedALayoutHandleFallsBack(): void
    {
        $adapter = new TemplateFilterAdapter(new HostServices(layouts: new class implements LayoutRenderer {
            public function render(string $handle, string $area, array $parameters): string
            {
                throw new RefusedByPort(PolicyViolation::LAYOUT_HANDLE, $handle);
            }
        }), Options::compatible());
        $plugin = $this->plugin($adapter);
        $subject = $this->filterFor(1);
        $plugin->beforeSetVariables($subject, ['order_id' => 1]);

        $result = $this->filter($plugin, $subject, 'Items: {{layout handle="sales_email_order_items" order_id=$order_id}}', 'Items: <table/>');

        self::assertSame('Items: <table/>', $result);
        self::assertSame(ShadowOutcome::REFUSED, $this->records[0][0]);
        self::assertSame('policy', $this->records[0][1]['error']);
        self::assertTrue($this->records[0][5]);
    }

    /**
     * A child belongs to legacy whatever the stage.
     *
     * The filter only renders a child while rendering its parent, and defers the child's
     * {{inlinecss}} to that parent with a signed placeholder - a contract only a legacy parent
     * keeps. This engine loads its own includes, so a parent it serves never reaches one.
     */
    public function testAChildRenderGoesToTheFilter(): void
    {
        $plugin = $this->plugin($this->raising(new \LogicException('must not render')));
        $subject = new class extends LegacyTemplate {
            protected $_storeId = 1;
            public function isChildTemplate() { return true; }
        };

        self::assertSame('SIGNED', $this->filter($plugin, $subject, '{{var x}}', 'SIGNED'));
        self::assertSame([], $this->records);
    }

    /** Legacy is untouched by any of this: the filter runs, and nothing else does. */
    public function testALegacyStoreOnlyRunsTheFilter(): void
    {
        $plugin = $this->plugin($this->raising(new \LogicException('must not render')), $this->mode([1 => EngineMode::LEGACY]));
        $subject = $this->filterFor(1);

        self::assertSame('LEGACY', $this->filter($plugin, $subject, 'x', 'LEGACY'));
        self::assertSame(1, $this->legacyRuns);
        self::assertSame([], $this->records);
    }

    // ---------------------------------------------------------------- finishing

    /**
     * The filter never ran, so the stylesheets this render asked for are registered on the
     * subject through its own method, and the subject inlines them.
     */
    public function testAServedRenderIsInlinedWithTheStylesheetsItAskedFor(): void
    {
        $plugin = $this->plugin($this->adapter());
        $subject = $this->inliningFilter();
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        $result = $this->filter($plugin, $subject, '{{inlinecss file="css/email-inline.css"}}Dear {{var name}},', 'LEGACY');

        self::assertSame('<INLINED css/email-inline.css>Dear Ada,</INLINED>', $result);
        self::assertSame(['css/email-inline.css'], $subject->registered);
    }

    /** And a render that asked for none - an email's subject - is not finished. */
    public function testAServedRenderThatAskedForNoStylesheetIsNotInlined(): void
    {
        $plugin = $this->plugin($this->adapter());
        $subject = $this->inliningFilter();
        $plugin->beforeSetVariables($subject, ['id' => '42']);

        self::assertSame('Your order #42', $this->filter($plugin, $subject, 'Your order #{{var id}}', 'LEGACY'));
        self::assertSame([], $subject->registered);
    }

    /** An inliner that raises is the filter's to handle, as it is today: fall back. */
    public function testAnInlinerThatRaisesFallsBack(): void
    {
        $plugin = $this->plugin($this->adapter());
        $subject = new class extends LegacyTemplate {
            protected $_storeId = 1;
            protected function addInlineCssFile($file) { return $this; }
            public function applyInlineCss($html) { throw new \RuntimeException('design params must be set'); }
        };
        $plugin->beforeSetVariables($subject, ['x' => 1]);

        self::assertSame('LEGACY', $this->filter($plugin, $subject, '{{inlinecss file="a.css"}}x', 'LEGACY'));
        self::assertSame(ShadowOutcome::CRASHED, $this->records[0][0]);
    }

    // ---------------------------------------------------------------- sampled comparison

    public function testASampledRenderIsComparedAndOursIsStillServed(): void
    {
        $plugin = $this->plugin($this->adapter(), $this->mode([1 => EngineMode::PARSER], rate: '100'));
        $subject = $this->filterFor(1);
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        self::assertSame('Dear Ada,', $this->filter($plugin, $subject, 'Dear {{var name}},', 'Dear Ada,'));
        self::assertSame('Dear Ada,', $this->filter($plugin, $subject, 'Dear {{var name}},', 'Dear SOMEONE ELSE,'));

        self::assertSame(2, $this->legacyRuns);
        self::assertSame([ShadowOutcome::AGREE, true, false], [$this->records[0][0], $this->records[0][4], $this->records[0][5]]);
        self::assertSame([ShadowOutcome::DIVERGE, true, false], [$this->records[1][0], $this->records[1][4], $this->records[1][5]]);
    }

    public function testAtZeroNothingIsCompared(): void
    {
        $plugin = $this->plugin($this->adapter(), $this->mode([1 => EngineMode::PARSER], rate: '0'));
        $subject = $this->filterFor(1);
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        for ($i = 0; $i < 50; $i++) {
            $this->filter($plugin, $subject, 'Dear {{var name}},', 'Dear Ada,');
        }

        self::assertSame(0, $this->legacyRuns);
    }

    /** The rate is a rate: about half of a thousand renders at 50%. */
    public function testThePartialRateSamplesAboutThatShare(): void
    {
        $plugin = $this->plugin($this->adapter(), $this->mode([1 => EngineMode::PARSER], rate: '50'));
        $subject = $this->filterFor(1);
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        mt_srand(20261001);
        for ($i = 0; $i < 1000; $i++) {
            $this->filter($plugin, $subject, 'Dear {{var name}},', 'Dear Ada,');
        }
        mt_srand();

        self::assertGreaterThan(400, $this->legacyRuns);
        self::assertLessThan(600, $this->legacyRuns);
    }

    /** A sampled render this engine declines serves the sampled legacy result: one filter run. */
    public function testASampledRefusalDoesNotRunTheFilterTwice(): void
    {
        $plugin = $this->plugin($this->adapter(Options::strict()), $this->mode([1 => EngineMode::PARSER], rate: '100'));
        $subject = $this->filterFor(1);
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        self::assertSame('LEGACY', $this->filter($plugin, $subject, '{{var nope}}', 'LEGACY'));
        self::assertSame(1, $this->legacyRuns);
        self::assertSame(ShadowOutcome::REFUSED, $this->records[0][0]);
    }

    /**
     * A legacy fatal in the sample is no reason to give the customer one: ours is served, and
     * there is nothing to compare it with.
     */
    public function testALegacyFatalInTheSampleStillServesOurs(): void
    {
        $plugin = $this->plugin($this->adapter(), $this->mode([1 => EngineMode::PARSER], rate: '100'));
        $subject = $this->filterFor(1);
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        $result = $plugin->aroundFilter($subject, static function (): string {
            throw new \TypeError('legacy fatal');
        }, 'Dear {{var name}},');

        self::assertSame('Dear Ada,', $result);
        self::assertSame([null, true, false], [$this->records[0][0], $this->records[0][4], $this->records[0][5]]);
    }

    /**
     * The sample runs the filter FIRST. A legacy {{block}} can reach another filter and render
     * through the same shared adapter; rendered after ours, that left the adapter describing
     * the inner render, and judge() would have read its causes as ours.
     */
    public function testTheSampledComparisonReadsTheCausesOfOurRender(): void
    {
        $adapter = new TemplateFilterAdapter(new HostServices(blocks: new class implements BlockRenderer {
            public function render(string $class, array $data, string $method): string { return 'BLOCK'; }
        }), Options::compatible());
        $plugin = $this->plugin($adapter, $this->mode([1 => EngineMode::PARSER], rate: '100'));
        $subject = $this->filterFor(1);
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        // The "legacy" render renders something else through the shared adapter, as a CMS
        // block reached from a {{block}} would - one that records a policy refusal - and then
        // answers differently from ours, whose render refused nothing.
        $this->filter($plugin, $subject, 'Dear {{var name}},', function () use ($adapter): string {
            $adapter->setPolicy(RenderPolicy::restricted())->setVariables(['a' => 1])->filter('{{block class="X"}}');
            $adapter->setPolicy(null);

            return 'Dear SOMEONE ELSE,';
        });

        self::assertSame(ShadowOutcome::DIVERGE, $this->records[0][0]);
        self::assertSame([], $this->records[0][1]['policy_violations'], 'judged with the inner render\'s causes');
    }

    // ---------------------------------------------------------------- configuration

    public function testTheRateReadsAsAClampedPercentage(): void
    {
        foreach (['5' => 5.0, ' 0.5 ' => 0.5, '250' => 100.0, '-3' => 0.0, 'lots' => 0.0, '' => 0.0] as $value => $rate) {
            self::assertSame($rate, $this->mode([1 => EngineMode::PARSER], rate: (string)$value)->parserShadowRate(1), var_export($value, true));
        }
        self::assertSame(0.0, $this->mode([1 => EngineMode::PARSER], rate: null)->parserShadowRate(1));
    }

    public function testTheAdminShowsTheRateOnlyForParser(): void
    {
        $system = simplexml_load_file(__DIR__ . '/../etc/adminhtml/system.xml');
        $field = $system->xpath('//group[@id="template_engine"]/field[@id="parser_shadow_rate"]');
        self::assertCount(1, $field);
        self::assertSame('1', (string)$field[0]['showInStore']);
        self::assertSame(EngineMode::PARSER, (string)$field[0]->depends->field);

        $config = simplexml_load_file(__DIR__ . '/../etc/config.xml');
        self::assertSame('1', (string)$config->default->system->template_engine->parser_shadow_rate);
        self::assertSame(EngineMode::XML_PATH_PARSER_SHADOW_RATE, 'system/template_engine/parser_shadow_rate');
    }

    /**
     * The stock sales emails' layout handles are allowed out of the box, and they are the
     * CLI's `stock-email` handles too. Without them Parser declined every order, invoice,
     * shipment and credit memo email - found on a real store, where the item table was the
     * 5.5 KB difference between the two engines' output.
     */
    public function testTheModuleAllowsTheStockEmailLayoutHandles(): void
    {
        $di = simplexml_load_file(__DIR__ . '/../etc/di.xml');
        $items = $di->xpath('//type[@name="Cresset\\TemplateParser\\Magento\\AllowlistedLayoutRenderer"]/arguments/argument[@name="allowedHandles"]/item');

        self::assertSame(
            AllowlistedLayoutRenderer::STOCK_EMAIL_HANDLES,
            array_map(static fn ($item): string => (string)$item, $items)
        );
    }

    // ---------------------------------------------------------------- recording

    /**
     * A served render that was not sampled says nothing about agreement, so it is counted as
     * served and leaves "renders since the last divergence" alone.
     */
    public function testServesAndFallbacksAreCountedWithoutMovingTheCleanCounter(): void
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

        $recorder->record(1, 'email:12', null, served: true);
        $recorder->record(1, 'email:12', null, served: true);
        $recorder->record(1, 'email:12', ShadowOutcome::agreed(), served: true);
        $recorder->record(1, 'email:12', ShadowOutcome::crashed(new \TypeError('x')), fellBack: true);
        $recorder->record(1, 'email:12', null, served: true);
        $recorder->flush();

        [$sql, $bind] = $queries[0];
        preg_match('/\(([^)]*)\) VALUES/', $sql, $m);
        $row = array_combine(array_map(static fn ($c) => trim($c, ' `'), explode(',', $m[1])), $bind);

        self::assertSame(4, $row['served']);
        self::assertSame(1, $row['fell_back']);
        self::assertSame(1, $row['agreed']);
        self::assertSame(1, $row['crashed']);
        self::assertSame(0, $row['renders_since_divergence'], 'a bare serve counted as clean');
        self::assertStringContainsString('`served` = `served` + VALUES(`served`)', $sql);
        self::assertStringContainsString('`fell_back` = `fell_back` + VALUES(`fell_back`)', $sql);
    }

    // ---------------------------------------------------------------- helpers

    private function adapter(?Options $options = null): TemplateFilterAdapter
    {
        return new TemplateFilterAdapter(new HostServices(), $options ?? Options::compatible());
    }

    private function raising(\Throwable $error): TemplateFilterInterface
    {
        return new class ($error) implements TemplateFilterInterface {
            public function __construct(private \Throwable $error) {}
            public function setVariables(array $variables): static { return $this; }
            public function setPolicy(?RenderPolicy $policy): static { return $this; }
            public function setPlainTemplateMode(bool $plain): static { return $this; }
            public function setDesignParams(array $designParams): static { return $this; }
            public function filter(string $value): string { throw $this->error; }
            public function deferred(): array { return []; }
            public function violations(): array { return []; }
            public function incompatibilities(): array { return []; }
            public function lastError(): ?\Exception { return null; }
        };
    }

    /** @param array<int,string> $byStore every other store is Parser, sampled at `$rate` */
    private function mode(array $byStore = [], ?string $rate = '0'): EngineMode
    {
        return new EngineMode(new class ($byStore, $rate) implements ScopeConfigInterface {
            public function __construct(private array $byStore, private ?string $rate) {}
            public function getValue($path, $scope = 'default', $scopeCode = null)
            {
                if ($path === EngineMode::XML_PATH_PARSER_SHADOW_RATE) {
                    return $this->rate;
                }
                return $this->byStore[$scopeCode] ?? EngineMode::PARSER;
            }
            public function isSetFlag($path, $scope = 'default', $scopeCode = null) { return false; }
        });
    }

    private function plugin(TemplateFilterInterface $adapter, ?EngineMode $mode = null): TemplateFilterPlugin
    {
        $recorder = new class ($this->records) extends ShadowRecorder {
            public function __construct(private array &$records) {}
            public function record(
                int|string|null $storeId,
                string $template,
                ?ShadowOutcome $outcome,
                bool $served = false,
                bool $fellBack = false
            ): void {
                $this->records[] = [$outcome?->outcome, $outcome?->detail, $storeId, $template, $served, $fellBack];
            }
        };

        return new TemplateFilterPlugin(new ShadowComparator($adapter), $mode ?? $this->mode(), new TemplateIdentity(), $recorder);
    }

    /** One filter() through the plugin, the filter answering `$legacy` and counting its runs. */
    private function filter(TemplateFilterPlugin $plugin, LegacyTemplate $subject, string $source, string|callable $legacy): mixed
    {
        return $plugin->aroundFilter($subject, function () use ($legacy): string {
            $this->legacyRuns++;

            return is_callable($legacy) ? $legacy() : $legacy;
        }, $source);
    }

    private function filterFor(int $storeId): LegacyTemplate
    {
        return new class ($storeId) extends LegacyTemplate {
            protected $_storeId;
            public function __construct($storeId) { $this->_storeId = $storeId; }
        };
    }

    /** Shaped like Email\Model\Template\Filter's inline-CSS step: files registered, then applied. */
    private function inliningFilter(): LegacyTemplate
    {
        return new class extends LegacyTemplate {
            public array $registered = [];
            protected $_storeId = 1;
            protected function addInlineCssFile($file)
            {
                $this->registered[] = $file;
                return $this;
            }
            public function applyInlineCss($html)
            {
                return $this->registered === []
                    ? $html
                    : '<INLINED ' . implode(',', array_unique($this->registered)) . '>' . $html . '</INLINED>';
            }
        };
    }
}
