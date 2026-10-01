<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\Magento\Config\EngineMode;
use Cresset\TemplateParser\Magento\Config\Source\EngineModeOptions;
use Cresset\TemplateParser\Magento\Plugin\TemplateFilterPlugin;
use Cresset\TemplateParser\Magento\Shadow\ShadowOutcome;
use Cresset\TemplateParser\Magento\Shadow\ShadowRecorder;
use Cresset\TemplateParser\Magento\Shadow\TemplateIdentity;
use Cresset\TemplateParser\Magento\ShadowComparator;
use Cresset\TemplateParser\Magento\TemplateFilterAdapter;
use Cresset\TemplateParser\Magento\TemplateFilterInterface;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\Port\BlockRenderer;
use Cresset\TemplateParser\Port\CustomVariableReader;
use Cresset\TemplateParser\Port\StylesheetLoader;
use Cresset\TemplateParser\RenderPolicy;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Filter\Template as LegacyTemplate;
use PHPUnit\Framework\TestCase;

/**
 * The path an integrator actually takes to adopt this engine.
 *
 * None of this was covered before, and the gaps were not subtle: the adapter defaulted to a
 * policy that refuses {{block}}, which is the item table of every stock order email, and
 * there was no interception point at all - so "declare your own preference once shadow mode
 * is quiet" described something that could not be done.
 */
final class MagentoIntegrationTest extends TestCase
{
    private function blocks(array &$rendered): BlockRenderer
    {
        return new class ($rendered) implements BlockRenderer {
            public function __construct(private array &$rendered) {}
            public function render(string $class, array $parameters, string $method): string
            {
                $this->rendered[] = $class;
                return '[ITEMS]';
            }
        };
    }

    /**
     * A recorder that keeps what it is handed instead of writing it.
     *
     * `$lines` gets every outcome that is NOT an agreement, as [outcome, detail, store,
     * template] - what used to be a log line - so "nothing went wrong" stays an empty array.
     * `$all` gets every outcome, agreements included.
     */
    private function recorder(array &$lines, array &$all = []): ShadowRecorder
    {
        return new class ($lines, $all) extends ShadowRecorder {
            public function __construct(private array &$lines, private array &$all)
            {
                parent::__construct(
                    new ResourceConnection(),
                    new class implements StoreManagerInterface { public function getStore($storeId = null) { return null; } },
                    new CollectingLogger(),
                    false
                );
            }
            public function record(int|string|null $storeId, string $template, ShadowOutcome $outcome): void
            {
                $entry = [$outcome->outcome, $outcome->detail, $storeId, $template];
                $this->all[] = $entry;
                if ($outcome->outcome !== ShadowOutcome::AGREE) {
                    $this->lines[] = $entry;
                }
            }
        };
    }

    /**
     * The configured stage per store id, with every other store - and the current one, asked
     * for as null - on `$default`. Every read is recorded in `$asked`.
     *
     * @param array<int|string,mixed> $byStore
     */
    private function mode(array $byStore = [], mixed $default = EngineMode::SHADOW, array &$asked = []): EngineMode
    {
        return new EngineMode(new class ($byStore, $default, $asked) implements ScopeConfigInterface {
            public function __construct(private array $byStore, private mixed $default, private array &$asked) {}
            public function getValue($path, $scope = 'default', $scopeCode = null)
            {
                $this->asked[] = [$path, $scope, $scopeCode];
                return $scopeCode !== null && array_key_exists($scopeCode, $this->byStore)
                    ? $this->byStore[$scopeCode]
                    : $this->default;
            }
            public function isSetFlag($path, $scope = 'default', $scopeCode = null) { return false; }
        });
    }

    /** Shadow everywhere unless told otherwise, which is what the comparison tests need. */
    private function plugin(
        ShadowComparator $comparator,
        array &$lines,
        ?EngineMode $mode = null,
        ?TemplateIdentity $identity = null,
        array &$all = []
    ): TemplateFilterPlugin {
        return new TemplateFilterPlugin(
            $comparator,
            $mode ?? $this->mode(),
            $identity ?? new TemplateIdentity(),
            $this->recorder($lines, $all)
        );
    }

    /** A filter for one store, shaped like Email\Model\Template\Filter where it matters here. */
    private function filterFor(int|string|null $storeId): LegacyTemplate
    {
        return new class ($storeId) extends LegacyTemplate {
            public int $getStoreIdCalls = 0;
            protected $_storeId;
            public function __construct($storeId) { $this->_storeId = $storeId; }
            public function storeIdAsSet() { return $this->_storeId; }
            // The real one fills $_storeId from the current store and keeps it.
            public function getStoreId()
            {
                $this->getStoreIdCalls++;
                return $this->_storeId ??= 'PINNED';
            }
        };
    }

    public function testTheAdapterIsSubstitutable(): void
    {
        self::assertInstanceOf(TemplateFilterInterface::class, new TemplateFilterAdapter());

        $reflection = new \ReflectionClass(TemplateFilterAdapter::class);
        self::assertFalse($reflection->isFinal(), 'a final adapter cannot be plugged or extended');
    }

    /**
     * The adapter replaces a filter that has no policy, so it must not impose one silently.
     *
     * Defaulting to RenderPolicy::restricted() here refuses {{block}} and {{layout}} - and
     * an order email whose item table has quietly become empty is the worst possible way to
     * discover a security default.
     */
    public function testTheAdapterRendersBlocksByDefault(): void
    {
        $rendered = [];
        $adapter = new TemplateFilterAdapter(
            new HostServices(blocks: $this->blocks($rendered)),
            Options::compatible()
        );

        $out = $adapter->setVariables(['a' => 1])->filter('{{block class="Magento\\Sales\\Block\\Items"}}');

        self::assertSame('[ITEMS]', $out);
        self::assertSame(['Magento\\Sales\\Block\\Items'], $rendered);
        self::assertSame([], $adapter->violations());
    }

    /** ...but tightening it is one call, and it takes effect. */
    public function testAPolicyCanBeNarrowedPerRender(): void
    {
        $rendered = [];
        $adapter = new TemplateFilterAdapter(
            new HostServices(blocks: $this->blocks($rendered)),
            Options::compatible()
        );

        $out = $adapter
            ->setPolicy(RenderPolicy::restricted())
            ->setVariables(['a' => 1])
            ->filter('{{block class="Magento\\Sales\\Block\\Items"}}');

        self::assertSame('', $out);
        self::assertSame([], $rendered, 'the block was constructed despite the policy');
        self::assertCount(1, $adapter->violations());
    }

    /** A policy given at construction is the default for every render. */
    public function testAConstructedPolicyIsTheDefault(): void
    {
        $rendered = [];
        $adapter = new TemplateFilterAdapter(
            new HostServices(blocks: $this->blocks($rendered)),
            Options::compatible(),
            RenderPolicy::restricted()
        );

        $adapter->setVariables([])->filter('{{block class="Evil"}}');

        self::assertSame([], $rendered);
        self::assertCount(1, $adapter->violations());
    }

    // ---------------------------------------------------------------- shadow mode

    public function testADivergenceCarriesItsCausesAndNoOutput(): void
    {
        $rendered = [];
        $adapter = new TemplateFilterAdapter(
            new HostServices(blocks: $this->blocks($rendered)),
            Options::compatible(),
            RenderPolicy::restricted()
        );
        $comparator = new ShadowComparator($adapter);

        $outcome = $comparator->compare('{{block class="Evil"}}', 'LEGACY-OUTPUT', []);

        self::assertSame(ShadowOutcome::DIVERGE, $outcome->outcome);
        // The cause, not just a byte offset.
        self::assertNotEmpty($outcome->detail['policy_violations']);
        self::assertStringContainsString('block', $outcome->detail['policy_violations'][0]);
        self::assertSame(13, $outcome->detail['legacy_length']);
        self::assertSame(0, $outcome->detail['first_difference_at']);
        // Neither side's output: a rendered email holds a customer's name and address.
        self::assertStringNotContainsString('LEGACY-OUTPUT', (string)json_encode($outcome->detail));
    }

    /**
     * A refusal and a crash are different findings: one is this engine declining a construct
     * on purpose, which Parser mode answers by falling back to legacy; the other is a bug here.
     */
    public function testAnEngineFailureIsClassifiedAsRefusedOrCrashed(): void
    {
        $refusing = new ShadowComparator(new TemplateFilterAdapter(new HostServices(), Options::strict()));
        // strict mode raises on an unknown variable: a TemplateError.
        $refused = $refusing->compare('{{var nope}}', 'LEGACY', []);
        self::assertSame(ShadowOutcome::REFUSED, $refused->outcome);
        self::assertSame(1, $refused->detail['line']);
        self::assertArrayHasKey('problem', $refused->detail);

        $crashing = new ShadowComparator(new class implements TemplateFilterInterface {
            public function setVariables(array $variables): static { return $this; }
            public function setPolicy(?RenderPolicy $policy): static { return $this; }
            public function setPlainTemplateMode(bool $plain): static { return $this; }
            public function setDesignParams(array $designParams): static { return $this; }
            public function filter(string $value): string { throw new \TypeError('boom'); }
            public function deferred(): array { return []; }
            public function violations(): array { return []; }
            public function incompatibilities(): array { return []; }
            public function lastError(): ?\Exception { return null; }
        });
        $crashed = $crashing->compare('x', 'x');
        self::assertSame(ShadowOutcome::CRASHED, $crashed->outcome);
        self::assertSame(['error' => \TypeError::class, 'message' => 'boom'], $crashed->detail);
    }

    /** The plugin returns the legacy result whatever the comparison found. */
    public function testThePluginReturnsTheLegacyResultWhenTheEngineFails(): void
    {
        $lines = [];
        $plugin = $this->plugin(
            new ShadowComparator(new TemplateFilterAdapter(new HostServices(), Options::strict())),
            $lines
        );
        $subject = $this->filterFor(1);

        $plugin->beforeSetVariables($subject, []);
        $plugin->beforeFilter($subject, '{{var nope}}');
        self::assertSame('LEGACY', $plugin->afterFilter($subject, 'LEGACY', '{{var nope}}'));
        self::assertSame(ShadowOutcome::REFUSED, $lines[0][0]);
    }

    // ---------------------------------------------------------------- the plugin

    /**
     * The legacy filter keeps its variables in a protected property with a setter and no
     * getter, so the plugin has to capture them on the way past or shadow mode renders
     * every template with an empty scope.
     */
    public function testThePluginCapturesVariablesAndReturnsTheLegacyResult(): void
    {
        $lines = [];
        $adapter = new TemplateFilterAdapter(new HostServices(), Options::compatible());
        $plugin = $this->plugin(new ShadowComparator($adapter), $lines);

        $subject = new LegacyTemplate();

        self::assertSame([['name' => 'Ada']], $plugin->beforeSetVariables($subject, ['name' => 'Ada']));

        // Same output both sides: no divergence should be logged.
        $result = $plugin->afterFilter($subject, 'Dear Ada,', 'Dear {{var name}},');

        self::assertSame('Dear Ada,', $result);
        self::assertSame([], $lines, 'identical output should not be reported as a divergence');
    }

    /**
     * Plain-text mode is set on the SUBJECT, so the plugin must capture it too.
     *
     * getProcessedTemplate() calls setPlainTemplateMode() on the filter it holds, and this is
     * a plugin on that filter rather than a replacement for it. Uncaptured, the candidate
     * render would use the HTML value of every custom variable while the legacy render used
     * the text one, and every plain email would report a divergence caused by nothing.
     */
    public function testThePluginCapturesPlainTemplateMode(): void
    {
        $lines = [];
        $adapter = new TemplateFilterAdapter(
            new HostServices(customVariables: new class implements CustomVariableReader {
                public function value(string $code, bool $plainText): ?string
                {
                    return $plainText ? 'TEXT' : '<b>HTML</b>';
                }
            }),
            Options::compatible()
        );
        $plugin = $this->plugin(new ShadowComparator($adapter), $lines);
        $subject = new LegacyTemplate();

        self::assertSame([true], $plugin->beforeSetPlainTemplateMode($subject, true));
        $plugin->beforeSetVariables($subject, []);

        // The legacy side of a plain render produces TEXT, and so must ours - a divergence
        // logged here would be the plugin's own doing.
        $plugin->afterFilter($subject, 'TEXT', '{{customvar code="g"}}');
        self::assertSame([], $lines, 'plain mode did not reach the candidate render');

        // And it is not sticky the wrong way: back to HTML, HTML is what agrees.
        $plugin->beforeSetPlainTemplateMode($subject, false);
        $plugin->afterFilter($subject, '<b>HTML</b>', '{{customvar code="g"}}');
        self::assertSame([], $lines);
    }

    /**
     * filter() is RE-ENTRANT, and the plugin kept one slot of captured state.
     *
     * A {{template}} include builds a child model which calls setVariables() and filter() of
     * its own in the middle of its parent's filter(). With a single slot the child's variables
     * overwrite the parent's, so the parent's afterFilter compared the parent's template
     * against the CHILD's scope. On a stock store that reported divergences in templates where
     * the engines agreed perfectly.
     */
    public function testThePluginComparesEachRenderAgainstItsOwnScope(): void
    {
        $lines = [];
        $adapter = new TemplateFilterAdapter(new HostServices(), Options::compatible());
        $plugin = $this->plugin(new ShadowComparator($adapter), $lines);
        $subject = new LegacyTemplate();

        // Parent takes its scope and starts rendering.
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);
        $plugin->beforeFilter($subject, 'Dear {{var name}},');

        // An include renders inside it, with a scope of its own.
        $plugin->beforeSetVariables($subject, ['name' => 'Grace']);
        $plugin->beforeFilter($subject, 'Hi {{var name}}');
        $plugin->afterFilter($subject, 'Hi Grace', 'Hi {{var name}}');

        // The parent finishes. Its comparison must use ADA, which is what it was called with.
        $plugin->afterFilter($subject, 'Dear Ada,', 'Dear {{var name}},');

        self::assertSame([], $lines, 'the parent was compared against the child\'s variables');
    }

    /**
     * A child render is not a document, and comparing one is a false positive by construction.
     *
     * The filter defers a directive it cannot finish in a child - {{inlinecss}}, which every
     * stock email hits - by emitting a SIGNED placeholder for the parent to resolve, and the
     * signature is random per render. This engine records the deferral structurally and emits
     * nothing, so a child's output can never match. 78 of the 79 divergences a stock store
     * reported were this and nothing else.
     */
    public function testAChildTemplateRenderIsNotCompared(): void
    {
        $lines = [];
        $adapter = new TemplateFilterAdapter(new HostServices(), Options::compatible());
        $plugin = $this->plugin(new ShadowComparator($adapter), $lines);

        $subject = new class extends LegacyTemplate {
            public function isChildTemplate() { return true; }
        };

        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);
        $plugin->beforeFilter($subject, '{{var name}}');
        $result = $plugin->afterFilter($subject, 'SIGNATURE{{inlinecss file="x.css"}}SIGNATURE', '{{var name}}');

        self::assertSame('SIGNATURE{{inlinecss file="x.css"}}SIGNATURE', $result);
        self::assertSame([], $lines, 'a child render was compared');
    }

    /**
     * The legacy result is a FINISHED document and the candidate is not.
     *
     * Email\Model\Template\Filter::filter() runs Emogrifier over the whole document before
     * returning; this engine defers that to its host. Comparing the two directly reports a
     * divergence for every template carrying a stylesheet, none of which is a disagreement
     * between the engines.
     */
    public function testTheCandidateGoesThroughTheSameFinishingStep(): void
    {
        $lines = [];
        $adapter = new TemplateFilterAdapter(new HostServices(), Options::compatible());
        $comparator = new ShadowComparator($adapter);

        $outcome = $comparator->compare(
            '{{inlinecss file="css/email-inline.css"}}{{var name}}',
            '<INLINED>Ada</INLINED>',
            ['name' => 'Ada'],
            false,
            static fn (string $html): string => '<INLINED>' . $html . '</INLINED>'
        );

        self::assertSame(ShadowOutcome::AGREE, $outcome->outcome, 'the finishing step was not applied to the candidate');
    }

    /**
     * And the PLUGIN is what has to build that finisher, from the subject it is wrapping.
     *
     * Testing the comparator with a finisher handed to it directly leaves the one line that
     * decides whether a finisher exists at all completely uncovered - which is where it would
     * actually go wrong.
     */
    public function testThePluginBuildsTheFinisherFromTheSubject(): void
    {
        $lines = [];
        $adapter = new TemplateFilterAdapter(new HostServices(), Options::compatible());
        $plugin = $this->plugin(new ShadowComparator($adapter), $lines);

        $subject = new class extends LegacyTemplate {
            public function applyInlineCss($html) { return '<INLINED>' . $html . '</INLINED>'; }
        };

        $source = '{{inlinecss file="css/email-inline.css"}}Dear {{var name}},';
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);
        $plugin->beforeFilter($subject, $source);
        $plugin->afterFilter($subject, '<INLINED>Dear Ada,</INLINED>', $source);

        self::assertSame([], $lines, 'the plugin did not put the candidate through applyInlineCss');
    }

    /**
     * But only a render that asked for inlining is finished.
     *
     * The legacy filter resets its after-filter callbacks after every render and keeps its
     * inline CSS file list, so an email's SUBJECT - filtered by the same instance straight
     * after the body - reaches applyInlineCss() with the body's stylesheets still set. Legacy
     * never calls it for the subject; finishing the candidate anyway wrapped every subject in
     * an HTML document, and a real store reported each one as a divergence.
     */
    public function testARenderThatDidNotAskForInliningIsNotFinished(): void
    {
        $adapter = new TemplateFilterAdapter(new HostServices(), Options::compatible());
        $comparator = new ShadowComparator($adapter);

        $outcome = $comparator->compare(
            'Your order #{{var id}}',
            'Your order #42',
            ['id' => '42'],
            false,
            static fn (string $html): string => '<html><body>' . $html . '</body></html>'
        );

        self::assertSame(ShadowOutcome::AGREE, $outcome->outcome, 'a subject was finished as if it were a body');
    }

    /**
     * Design params are set on the SUBJECT, so the plugin captures them like the rest.
     *
     * And per-invocation, because filter() is re-entrant: an include's child model sets its own
     * before the parent's comparison runs.
     */
    public function testThePluginCapturesDesignParamsPerInvocation(): void
    {
        $lines = [];
        $seen = [];
        $adapter = new TemplateFilterAdapter(new HostServices(
            stylesheets: new class ($seen) implements StylesheetLoader {
                public function __construct(private array &$seen) {}
                public function load(string $file, array $designParams = []): ?string
                {
                    $this->seen[] = $designParams['theme'] ?? 'none';
                    return 'CSS';
                }
            }
        ), Options::compatible());
        $plugin = $this->plugin(new ShadowComparator($adapter), $lines);
        $subject = new LegacyTemplate();

        self::assertSame(
            [['theme' => 'Magento/luma']],
            $plugin->beforeSetDesignParams($subject, ['theme' => 'Magento/luma'])
        );
        $plugin->beforeSetVariables($subject, []);
        $plugin->beforeFilter($subject, '{{css file="a.css"}}');

        // A child render with a design of its own, in the middle of the parent's.
        $plugin->beforeSetDesignParams($subject, ['theme' => 'Vendor/child']);
        $plugin->beforeFilter($subject, '{{css file="b.css"}}');
        $plugin->afterFilter($subject, 'CSS', '{{css file="b.css"}}');

        // The parent must still be compared against ITS design, not the child's.
        $plugin->afterFilter($subject, 'CSS', '{{css file="a.css"}}');

        self::assertSame(['Vendor/child', 'Magento/luma'], $seen);
        self::assertSame([], $lines);
    }

    /** A finisher that raises must not take a shadow run down with it. */
    public function testAFinisherThatRaisesIsRecordedNotThrown(): void
    {
        $adapter = new TemplateFilterAdapter(new HostServices(), Options::compatible());
        $comparator = new ShadowComparator($adapter);

        $outcome = $comparator->compare('{{inlinecss file="css/email-inline.css"}}{{var name}}', 'Ada', ['name' => 'Ada'], false, static function (): string {
            throw new \RuntimeException('emogrifier fell over');
        });

        self::assertSame(ShadowOutcome::CRASHED, $outcome->outcome);
    }

    public function testThePluginReportsARealDivergence(): void
    {
        $lines = [];
        $adapter = new TemplateFilterAdapter(new HostServices(), Options::compatible());
        $plugin = $this->plugin(new ShadowComparator($adapter), $lines);

        $subject = new LegacyTemplate();
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        $result = $plugin->afterFilter($subject, 'Dear SOMEONE ELSE,', 'Dear {{var name}},');

        self::assertSame('Dear SOMEONE ELSE,', $result, 'the legacy result is always what is returned');
        self::assertCount(1, $lines);
        self::assertSame(ShadowOutcome::DIVERGE, $lines[0][0]);
    }

    // ---------------------------------------------------------------- the configured stage

    /**
     * Legacy is the default, and under it nothing past the config read happens.
     *
     * The adapter here raises on anything, so a candidate render of any kind would log
     * "engine raised" - an empty log is proof there was none, not merely that it agreed.
     */
    public function testALegacyStoreIsNeverRendered(): void
    {
        $lines = [];
        $adapter = new TemplateFilterAdapter(new HostServices(), Options::strict());
        $plugin = $this->plugin(new ShadowComparator($adapter), $lines, $this->mode(default: EngineMode::LEGACY));
        $subject = $this->filterFor(1);

        $plugin->beforeSetVariables($subject, []);
        $plugin->beforeFilter($subject, 'Dear {{var nope}},');
        $result = $plugin->afterFilter($subject, 'LEGACY', 'Dear {{var nope}},');

        self::assertSame('LEGACY', $result);
        self::assertSame([], $lines, 'a Legacy store was rendered through the new engine');
    }

    /** The stage is the render's own store's, not the installation's. */
    public function testEachRenderReadsTheStageOfItsOwnStore(): void
    {
        $lines = [];
        $asked = [];
        $adapter = new TemplateFilterAdapter(new HostServices(), Options::compatible());
        $plugin = $this->plugin(
            new ShadowComparator($adapter),
            $lines,
            $this->mode([1 => 'legacy', 2 => 'shadow'], EngineMode::LEGACY, $asked)
        );

        foreach ([1, 2] as $storeId) {
            $subject = $this->filterFor($storeId);
            $plugin->beforeSetVariables($subject, ['name' => 'Ada']);
            $plugin->beforeFilter($subject, 'Dear {{var name}},');
            $plugin->afterFilter($subject, 'Dear SOMEONE ELSE,', 'Dear {{var name}},');
        }

        self::assertCount(1, $lines, 'only the Shadow store should have been compared');
        self::assertSame(
            [[EngineMode::XML_PATH, 'store', 1], [EngineMode::XML_PATH, 'store', 2]],
            $asked
        );
    }

    /**
     * Finding the store must not change it.
     *
     * The filter's getStoreId() fills an unset store from the current one and keeps it, and
     * the CMS filters are shared instances never given a store - so asking would pin every
     * later CMS render to the first store that rendered, in Legacy mode as much as in Shadow.
     */
    public function testReadingTheStoreDoesNotPinIt(): void
    {
        $asked = [];
        $lines = [];
        $plugin = $this->plugin(
            new ShadowComparator(new TemplateFilterAdapter()),
            $lines,
            $this->mode(default: EngineMode::LEGACY, asked: $asked)
        );
        $subject = $this->filterFor(null);

        $plugin->beforeFilter($subject, 'x');
        $plugin->afterFilter($subject, 'x', 'x');

        self::assertSame(0, $subject->getStoreIdCalls);
        self::assertNull($subject->storeIdAsSet());
        self::assertSame([[EngineMode::XML_PATH, 'store', null]], $asked, 'an unset store is the current one');
    }

    /**
     * Every beforeFilter pushes and every afterFilter pops, whatever the stage.
     *
     * A render nested in another store's - a Legacy child in a Shadow parent and the reverse -
     * is where a stack that only tracked compared renders would pop the wrong frame.
     */
    public function testNestingAcrossStagesKeepsEachRenderInItsOwnFrame(): void
    {
        $lines = [];
        $adapter = new TemplateFilterAdapter(new HostServices(), Options::compatible());
        $plugin = $this->plugin(
            new ShadowComparator($adapter),
            $lines,
            $this->mode([1 => 'legacy', 2 => 'shadow'], EngineMode::LEGACY)
        );
        $shadow = $this->filterFor(2);
        $legacy = $this->filterFor(1);

        // Shadow parent, Legacy child that would diverge: nothing logged, parent agrees.
        $plugin->beforeSetVariables($shadow, ['name' => 'Ada']);
        $plugin->beforeFilter($shadow, 'Dear {{var name}},');
        $plugin->beforeSetVariables($legacy, ['name' => 'Grace']);
        $plugin->beforeFilter($legacy, 'Hi {{var name}}');
        $plugin->afterFilter($legacy, 'Hi SOMEONE ELSE', 'Hi {{var name}}');
        $plugin->afterFilter($shadow, 'Dear Ada,', 'Dear {{var name}},');
        self::assertSame([], $lines);

        // Legacy parent, Shadow child that diverges: the child is logged, the parent is not.
        $plugin->beforeSetVariables($legacy, ['name' => 'Ada']);
        $plugin->beforeFilter($legacy, 'Dear {{var name}},');
        $plugin->beforeSetVariables($shadow, ['name' => 'Grace']);
        $plugin->beforeFilter($shadow, 'Hi {{var name}}');
        $plugin->afterFilter($shadow, 'Hi SOMEONE ELSE', 'Hi {{var name}}');
        $plugin->afterFilter($legacy, 'Dear NOBODY,', 'Dear {{var name}},');
        self::assertCount(1, $lines);
    }

    /** Anything unrecognised is Legacy: the stage that renders exactly as before. */
    public function testAnUnknownStageReadsAsLegacy(): void
    {
        foreach ([null, '', 'parser', 'bogus', 1, ['shadow']] as $value) {
            self::assertSame(EngineMode::LEGACY, $this->mode(default: $value)->forStore(1), var_export($value, true));
        }
        self::assertSame(EngineMode::SHADOW, $this->mode(default: ' Shadow ')->forStore(1));
    }

    /** Parser is not offered until it can fall back to legacy on a refusal (issue #2). */
    public function testTheAdminOffersLegacyAndShadowOnly(): void
    {
        $values = array_column((new EngineModeOptions())->toOptionArray(), 'value');

        self::assertSame([EngineMode::LEGACY, EngineMode::SHADOW], $values);
    }

    /** The shipped configuration: wired, and defaulted to the stage that does nothing. */
    public function testTheModuleWiresThePluginAndDefaultsToLegacy(): void
    {
        $di = simplexml_load_file(__DIR__ . '/../etc/di.xml');
        $plugins = $di->xpath('//type[@name="Magento\\Email\\Model\\Template\\Filter"]/plugin');
        self::assertCount(1, $plugins);
        self::assertSame(TemplateFilterPlugin::class, (string)$plugins[0]['type']);
        self::assertSame([], $di->xpath('//type[@name="' . ShadowComparator::class . '"]'));

        $config = simplexml_load_file(__DIR__ . '/../etc/config.xml');
        self::assertSame(EngineMode::LEGACY, (string)$config->default->system->template_engine->mode);

        $system = simplexml_load_file(__DIR__ . '/../etc/adminhtml/system.xml');
        $field = $system->xpath('//section[@id="system"]/group[@id="template_engine"]/field[@id="mode"]');
        self::assertCount(1, $field);
        self::assertSame('1', (string)$field[0]['showInStore']);
        self::assertSame(EngineModeOptions::class, (string)$field[0]->source_model);
        self::assertSame(EngineMode::XML_PATH, 'system/template_engine/mode');
    }
}
