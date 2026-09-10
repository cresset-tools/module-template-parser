<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Magento\Framework\ObjectManager\ConfigInterface;
use Magento\Framework\View\Element\BlockInterface;
use Magento\Framework\View\LayoutInterface;
use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\Port\BlockRenderer;
use Cresset\TemplateParser\TemplateError;
use Cresset\TemplateParser\Magento\LayoutBlockRenderer;
use Cresset\TemplateParser\Magento\ShadowComparator;
use Cresset\TemplateParser\Magento\TemplateFilterAdapter;
use Cresset\TemplateParser\Options;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MagentoAdapterTest extends TestCase
{
    /** Tripwire: records construction, so "was it built?" is observable. */
    private function layout(array &$built): LayoutInterface
    {
        return new class ($built) implements LayoutInterface {
            public function __construct(private array &$built) {}
            public function createBlock($type, $name = '', array $arguments = [])
            {
                $this->built[] = $type;
                return new class implements BlockInterface {
                    public function toHtml() { return '<b>ok</b>'; }
                    public function getCacheKey() { return 'secret'; }
                };
            }
        };
    }

    private function omConfig(): ConfigInterface
    {
        return new class implements ConfigInterface {
            public function getInstanceType($instanceName) { return $instanceName; }
            public function getPreference($type) { return $type; }
        };
    }

    private function renderer(array &$built, array $methods = ['toHtml'], ?array $classes = null): LayoutBlockRenderer
    {
        return new LayoutBlockRenderer($this->layout($built), $this->omConfig(), $methods, $classes);
    }

    public function testLegitimateBlockRenders(): void
    {
        $built = [];
        $class = get_class(new class implements BlockInterface { public function toHtml() { return ''; } });
        self::assertSame('<b>ok</b>', $this->renderer($built)->render($class, [], 'toHtml'));
        self::assertSame([$class], $built);
    }

    /** The type check must happen before the object is built. */
    public function testNonBlockTypeIsRefusedWithoutBeingConstructed(): void
    {
        $built = [];
        self::assertSame('', $this->renderer($built)->render(\stdClass::class, [], 'toHtml'));
        self::assertSame([], $built, 'the class must never have been constructed');
    }

    public function testNonExistentClassIsRefusedWithoutBeingConstructed(): void
    {
        $built = [];
        self::assertSame('', $this->renderer($built)->render('No\\Such\\Class', [], 'toHtml'));
        self::assertSame([], $built);
    }

    /** `output=` is an allowlist, not "any public no-arg method". */
    public function testDisallowedOutputMethodIsRefused(): void
    {
        $built = [];
        $class = get_class(new class implements BlockInterface { public function toHtml() { return ''; } });
        self::assertSame('', $this->renderer($built)->render($class, [], 'getCacheKey'));
        self::assertSame([], $built, 'refusal must happen before construction');
    }

    public function testOptionalClassAllowlistIsEnforced(): void
    {
        $built = [];
        $class = get_class(new class implements BlockInterface { public function toHtml() { return ''; } });
        $renderer = $this->renderer($built, ['toHtml'], ['Only\\This\\One']);
        self::assertSame('', $renderer->render($class, [], 'toHtml'));
        self::assertSame([], $built);
    }

    public function testAdapterExposesFilterShapeAndDeferredWork(): void
    {
        $adapter = new TemplateFilterAdapter(options: Options::lenient());
        $out = $adapter->setVariables(['name' => 'Jan'])->filter('Hi {{var name}}{{inlinecss file="a.css"}}');

        self::assertSame('Hi Jan', $out);
        self::assertSame([['kind' => 'inlinecss', 'payload' => ['file' => 'a.css']]], $adapter->deferred());
    }

    public function testShadowComparatorAlwaysReturnsLegacyOutputAndLogsDivergence(): void
    {
        $logger = new class implements LoggerInterface {
            public array $records = [];
            public function emergency($m, array $c = []) {} public function alert($m, array $c = []) {}
            public function critical($m, array $c = []) {} public function error($m, array $c = []) {}
            public function warning($m, array $c = []) {} public function notice($m, array $c = []) {}
            public function debug($m, array $c = []) {}  public function log($l, $m, array $c = []) {}
            public function info($m, array $c = []): void { $this->records[] = [$m, $c]; }
        };

        $comparator = new ShadowComparator(
            new TemplateFilterAdapter(options: Options::lenient()),
            $logger,
            true
        );

        $result = $comparator->compare('Hi {{var name}}', 'LEGACY OUTPUT', ['name' => 'Jan']);

        self::assertSame('LEGACY OUTPUT', $result, 'shadow mode must never change what is returned');
        self::assertCount(1, $logger->records);
        self::assertStringContainsString('divergence', $logger->records[0][0]);
        self::assertArrayHasKey('template_hash', $logger->records[0][1]);
    }

    public function testShadowComparatorIsInertWhenDisabled(): void
    {
        $logger = new class implements LoggerInterface {
            public array $records = [];
            public function emergency($m, array $c = []) {} public function alert($m, array $c = []) {}
            public function critical($m, array $c = []) {} public function error($m, array $c = []) {}
            public function warning($m, array $c = []) {} public function notice($m, array $c = []) {}
            public function debug($m, array $c = []) {}  public function log($l, $m, array $c = []) {}
            public function info($m, array $c = []): void { $this->records[] = [$m, $c]; }
        };
        $comparator = new ShadowComparator(new TemplateFilterAdapter(), $logger, false);

        self::assertSame('L', $comparator->compare('{{if broken}}', 'L'));
        self::assertSame([], $logger->records);
    }

    /** A strict-mode failure in the candidate engine must never break the render. */
    public function testShadowComparatorSwallowsEngineErrors(): void
    {
        $logger = new class implements LoggerInterface {
            public array $records = [];
            public function emergency($m, array $c = []) {} public function alert($m, array $c = []) {}
            public function critical($m, array $c = []) {} public function error($m, array $c = []) {}
            public function warning($m, array $c = []) {} public function notice($m, array $c = []) {}
            public function debug($m, array $c = []) {}  public function log($l, $m, array $c = []) {}
            public function info($m, array $c = []): void { $this->records[] = [$m, $c]; }
        };
        $comparator = new ShadowComparator(new TemplateFilterAdapter(), $logger, true);

        self::assertSame('L', $comparator->compare('{{if unclosed}}', 'L'));
        self::assertStringContainsString('engine raised', $logger->records[0][0]);
    }

    /**
     * A host exception degrades the template, as the filter it replaces does.
     *
     * Email\Model\Template\Filter::filter() catches \Exception and substitutes an error
     * string, so one bad directive costs a template rather than the request. This adapter let
     * it out, turning a degraded email into a 500 - and a raising block is not rare: 595 of
     * the 1480 block classes in a stock store raise when built with no data, 52 of them
     * ordinary frontend blocks a CMS editor could name.
     */
    public function testAHostExceptionDegradesRatherThanEscaping(): void
    {
        $blocks = new class implements BlockRenderer {
            public function render(string $class, array $parameters, string $method): string
            {
                throw new \InvalidArgumentException('Expected value of `identifier` was not provided');
            }
        };
        $adapter = new TemplateFilterAdapter(new HostServices(blocks: $blocks));

        self::assertSame(
            'Error filtering template: Expected value of `identifier` was not provided',
            $adapter->filter('{{block class="Vendor\\Mod\\Block\\Thing"}}')
        );
        self::assertInstanceOf(\InvalidArgumentException::class, $adapter->lastError());
    }

    /**
     * But this engine's own diagnostics are the product, not a failure to hide.
     *
     * Shadow mode reports them as "engine raised" and `check` turns them into findings;
     * swallowing them into an error string would make an engine refusal look like a
     * divergence, which is the opposite of useful.
     */
    public function testTheEnginesOwnDiagnosticsStillEscape(): void
    {
        $this->expectException(TemplateError::class);
        (new TemplateFilterAdapter())->filter('{{if unclosed}}');
    }
}
