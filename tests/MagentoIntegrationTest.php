<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\Magento\Plugin\TemplateFilterPlugin;
use Cresset\TemplateParser\Magento\ShadowComparator;
use Cresset\TemplateParser\Magento\TemplateFilterAdapter;
use Cresset\TemplateParser\Magento\TemplateFilterInterface;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\Port\BlockRenderer;
use Cresset\TemplateParser\Port\CustomVariableReader;
use Cresset\TemplateParser\RenderPolicy;
use Magento\Framework\Filter\Template as LegacyTemplate;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

    private function logger(array &$lines): LoggerInterface
    {
        return new class ($lines) implements LoggerInterface {
            public function __construct(private array &$lines) {}
            public function emergency($m, array $c = []) {} public function alert($m, array $c = []) {}
            public function critical($m, array $c = []) {} public function error($m, array $c = []) {}
            public function warning($m, array $c = []) {} public function notice($m, array $c = []) {}
            public function debug($m, array $c = []) {} public function log($l, $m, array $c = []) {}
            public function info($message, array $context = []) { $this->lines[] = [$message, $context]; }
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

    public function testShadowModeReturnsTheLegacyResultAndLogsTheCauses(): void
    {
        $lines = [];
        $rendered = [];
        $adapter = new TemplateFilterAdapter(
            new HostServices(blocks: $this->blocks($rendered)),
            Options::compatible(),
            RenderPolicy::restricted()
        );
        $comparator = new ShadowComparator($adapter, $this->logger($lines), true);

        $returned = $comparator->compare('{{block class="Evil"}}', 'LEGACY-OUTPUT', []);

        self::assertSame('LEGACY-OUTPUT', $returned, 'shadow mode must never change what is returned');
        self::assertCount(1, $lines);
        [$message, $context] = $lines[0];
        self::assertSame('template-parser shadow: divergence', $message);
        // The cause, not just a byte offset.
        self::assertNotEmpty($context['policy_violations']);
        self::assertStringContainsString('block', $context['policy_violations'][0]);
    }

    public function testShadowModeIsSilentWhenDisabled(): void
    {
        $lines = [];
        $comparator = new ShadowComparator(new TemplateFilterAdapter(), $this->logger($lines), false);

        self::assertSame('LEGACY', $comparator->compare('{{var x}}', 'LEGACY', ['x' => 'v']));
        self::assertSame([], $lines);
    }

    public function testShadowModeSurvivesAnEngineFailure(): void
    {
        $lines = [];
        $comparator = new ShadowComparator(
            new TemplateFilterAdapter(new HostServices(), Options::strict()),
            $this->logger($lines),
            true
        );

        // strict mode raises on an unknown variable; the legacy result must still come back.
        self::assertSame('LEGACY', $comparator->compare('{{var nope}}', 'LEGACY', []));
        self::assertSame('template-parser shadow: engine raised', $lines[0][0]);
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
        $plugin = new TemplateFilterPlugin(new ShadowComparator($adapter, $this->logger($lines), true));

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
        $plugin = new TemplateFilterPlugin(new ShadowComparator($adapter, $this->logger($lines), true));
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

    public function testThePluginReportsARealDivergence(): void
    {
        $lines = [];
        $adapter = new TemplateFilterAdapter(new HostServices(), Options::compatible());
        $plugin = new TemplateFilterPlugin(new ShadowComparator($adapter, $this->logger($lines), true));

        $subject = new LegacyTemplate();
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        $result = $plugin->afterFilter($subject, 'Dear SOMEONE ELSE,', 'Dear {{var name}},');

        self::assertSame('Dear SOMEONE ELSE,', $result, 'the legacy result is always what is returned');
        self::assertCount(1, $lines);
        self::assertSame('template-parser shadow: divergence', $lines[0][0]);
    }
}
