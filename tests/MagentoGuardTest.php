<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManager\ConfigInterface;
use Magento\Framework\View\Element\BlockInterface;
use Magento\Framework\View\LayoutFactory;
use Magento\Framework\View\LayoutInterface;
use Magento\Variable\Model\Source\Variables;
use Magento\Widget\Block\BlockInterface as WidgetBlockInterface;
use MageOS\TemplateParser\Diagnostics;
use MageOS\TemplateParser\Magento\AllowlistedConfigReader;
use MageOS\TemplateParser\Magento\AllowlistedLayoutRenderer;
use MageOS\TemplateParser\Magento\LayoutBlockRenderer;
use MageOS\TemplateParser\Magento\TemplateFilterAdapter;
use MageOS\TemplateParser\Magento\TypeCheckedWidgetRenderer;
use MageOS\TemplateParser\ParameterParser;
use PHPUnit\Framework\TestCase;

/**
 * The src/Magento adapters are where template text meets the application, so each of their
 * guards is load-bearing. Three of these classes previously had no test at all, which meant
 * every check in them could be deleted with the suite still green.
 */
final class MagentoGuardTest extends TestCase
{
    private function omConfig(): ConfigInterface
    {
        return new class implements ConfigInterface {
            public function getInstanceType($instanceName) { return $instanceName; }
            public function getPreference($type) { return $type; }
        };
    }

    /** Records what was constructed, so "was it built?" is observable. */
    private function layout(array &$built, bool $returnWidget = true): LayoutInterface
    {
        return new class ($built, $returnWidget) implements LayoutInterface {
            public function __construct(private array &$built, private bool $returnWidget) {}
            public function createBlock($type, $name = '', array $arguments = [])
            {
                $this->built[] = $type;
                return $this->returnWidget
                    ? new class implements WidgetBlockInterface {
                        public function toHtml() { return '<w>ok</w>'; }
                    }
                    : new \stdClass();
            }
        };
    }

    // ------------------------------------------------ TypeCheckedWidgetRenderer

    public function testALegitimateWidgetRenders(): void
    {
        $built = [];
        $type = get_class(new class implements WidgetBlockInterface { public function toHtml() { return ''; } });

        $renderer = new TypeCheckedWidgetRenderer($this->layout($built), $this->omConfig());

        self::assertSame('<w>ok</w>', $renderer->render($type, []));
        self::assertSame([$type], $built);
    }

    /** The type check must happen BEFORE construction - an arbitrary constructor is the risk. */
    public function testANonWidgetTypeIsRefusedWithoutBeingConstructed(): void
    {
        $built = [];
        $renderer = new TypeCheckedWidgetRenderer($this->layout($built), $this->omConfig());

        self::assertSame('', $renderer->render(\stdClass::class, []));
        self::assertSame([], $built, 'the class was constructed before being checked');
    }

    public function testTheWidgetAllowlistIsEnforced(): void
    {
        $built = [];
        $type = get_class(new class implements WidgetBlockInterface { public function toHtml() { return ''; } });

        $renderer = new TypeCheckedWidgetRenderer($this->layout($built), $this->omConfig(), ['Some\\Other\\Widget']);

        self::assertSame('', $renderer->render($type, []));
        self::assertSame([], $built, 'an allowlist miss must not be constructed');
    }

    /** A leading backslash must not slip past the allowlist. */
    public function testTheWidgetAllowlistIgnoresALeadingBackslash(): void
    {
        $built = [];
        $type = get_class(new class implements WidgetBlockInterface { public function toHtml() { return ''; } });

        $renderer = new TypeCheckedWidgetRenderer($this->layout($built), $this->omConfig(), [$type]);

        self::assertSame('<w>ok</w>', $renderer->render('\\' . $type, []));
    }

    /** An object manager that raises must fail closed. */
    public function testAWidgetIsRefusedWhenTheTypeCannotBeResolved(): void
    {
        $built = [];
        $throwing = new class implements ConfigInterface {
            public function getInstanceType($instanceName) { throw new \RuntimeException('boom'); }
            public function getPreference($type) { return $type; }
        };

        $renderer = new TypeCheckedWidgetRenderer($this->layout($built), $throwing);

        self::assertSame('', $renderer->render(\stdClass::class, []));
        self::assertSame([], $built);
    }

    /** The post-construction check still matters when the layout hands back something else. */
    public function testAWidgetThatIsNotAWidgetAfterAllRendersNothing(): void
    {
        $built = [];
        $type = get_class(new class implements WidgetBlockInterface { public function toHtml() { return ''; } });

        $renderer = new TypeCheckedWidgetRenderer($this->layout($built, returnWidget: false), $this->omConfig());

        self::assertSame('', $renderer->render($type, []));
        self::assertSame([$type], $built, 'this case is specifically about a built object');
    }

    // ------------------------------------------------ LayoutBlockRenderer

    /** The post-construction instanceof check, which the pre-construction tests do not reach. */
    public function testABlockThatIsNotABlockAfterAllRendersNothing(): void
    {
        $built = [];
        $layout = new class ($built) implements LayoutInterface {
            public function __construct(private array &$built) {}
            public function createBlock($type, $name = '', array $arguments = [])
            {
                $this->built[] = $type;
                return new \stdClass();       // not a BlockInterface
            }
        };
        $type = get_class(new class implements BlockInterface { public function toHtml() { return ''; } });

        $renderer = new LayoutBlockRenderer($layout, $this->omConfig());

        self::assertSame('', $renderer->render($type, [], 'toHtml'));
        self::assertSame([$type], $built);
    }

    /** A method the block does not have must not be called. */
    public function testAnAbsentOutputMethodRendersNothing(): void
    {
        $built = [];
        $layout = new class ($built) implements LayoutInterface {
            public function __construct(private array &$built) {}
            public function createBlock($type, $name = '', array $arguments = [])
            {
                $this->built[] = $type;
                return new class implements BlockInterface { public function toHtml() { return 'x'; } };
            }
        };
        $type = get_class(new class implements BlockInterface { public function toHtml() { return ''; } });

        $renderer = new LayoutBlockRenderer($layout, $this->omConfig(), ['toHtml', 'toGone']);

        self::assertSame('', $renderer->render($type, [], 'toGone'));
    }

    // ------------------------------------------------ AllowlistedConfigReader

    private function scopeConfig(array $values): ScopeConfigInterface
    {
        return new class ($values) implements ScopeConfigInterface {
            public function __construct(private array $values) {}
            public function getValue($path, $scope = 'default', $scopeCode = null) { return $this->values[$path] ?? null; }
            public function isSetFlag($path, $scope = 'default', $scopeCode = null) { return (bool)$this->getValue($path); }
        };
    }

    private function variables(array $available): Variables
    {
        return new class ($available) extends Variables {
            public function __construct(private array $available) {}
            public function getAvailableVars() { return $this->available; }
        };
    }

    public function testAnAllowlistedConfigPathIsReadable(): void
    {
        $reader = new AllowlistedConfigReader(
            $this->scopeConfig(['web/unsecure/base_url' => 'https://shop.example/']),
            $this->variables(['web/unsecure/base_url'])
        );

        self::assertSame('https://shop.example/', $reader->value('web/unsecure/base_url'));
    }

    /** The allowlist is the whole security of {{config}}. */
    public function testAConfigPathOutsideTheAllowlistIsRefused(): void
    {
        $reader = new AllowlistedConfigReader(
            $this->scopeConfig(['payment/secret/key' => 'SECRET']),
            $this->variables(['web/unsecure/base_url'])
        );

        self::assertNull($reader->value('payment/secret/key'));
    }

    /** If the allowlist cannot be obtained, deny - do not fall through and read anyway. */
    public function testAnUnavailableAllowlistFailsClosed(): void
    {
        $throwing = new class extends Variables {
            public function getAvailableVars() { throw new \RuntimeException('no list'); }
        };

        $reader = new AllowlistedConfigReader($this->scopeConfig(['a/b' => 'V']), $throwing);

        self::assertNull($reader->value('a/b'));
    }

    /** A non-scalar config value must not be stringified into the template. */
    public function testANonScalarConfigValueIsRefused(): void
    {
        $reader = new AllowlistedConfigReader(
            $this->scopeConfig(['a/b' => ['nested' => 'x']]),
            $this->variables(['a/b'])
        );

        self::assertNull($reader->value('a/b'));
    }

    // ------------------------------------------------ AllowlistedLayoutRenderer

    private function layoutFactory(array &$loaded): LayoutFactory
    {
        return new class ($loaded) extends LayoutFactory {
            public function __construct(private array &$loaded) {}
            public function create(array $data = [])
            {
                return new class ($this->loadedRef()) {
                    public function __construct(private array &$loaded) {}
                    public function getUpdate(): object
                    {
                        return new class ($this->loaded) {
                            public function __construct(private array &$loaded) {}
                            public function addHandle($handle): self { $this->loaded[] = $handle; return $this; }
                            public function load(): self { return $this; }
                        };
                    }
                    public function generateXml(): void {}
                    public function generateElements(): void {}
                    public function getOutput(): string { return 'LAYOUT-OUTPUT'; }
                };
            }
            private function &loadedRef(): array { return $this->loaded; }
        };
    }

    public function testAnAllowlistedHandleRenders(): void
    {
        $loaded = [];
        $renderer = new AllowlistedLayoutRenderer($this->layoutFactory($loaded), new State(), ['ok_handle']);

        self::assertSame('LAYOUT-OUTPUT', $renderer->render('ok_handle', 'frontend', []));
        self::assertSame(['ok_handle'], $loaded);
    }

    /** A layout handle decides which blocks get built; template text may not choose freely. */
    public function testAHandleOutsideTheAllowlistIsRefused(): void
    {
        $loaded = [];
        $renderer = new AllowlistedLayoutRenderer($this->layoutFactory($loaded), new State(), ['ok_handle']);

        self::assertSame('', $renderer->render('customer_account_edit', 'frontend', []));
        self::assertSame([], $loaded, 'the handle was loaded despite being refused');
    }

    // ------------------------------------------------ adapter scope

    /** deferred() means the LAST render, not every render this adapter has ever done. */
    public function testDeferredWorkDoesNotAccumulateAcrossRenders(): void
    {
        $adapter = new TemplateFilterAdapter();

        $adapter->filter('{{inlinecss file="one.css"}}');
        self::assertCount(1, $adapter->deferred());

        $adapter->filter('{{inlinecss file="two.css"}}');
        $deferred = $adapter->deferred();

        self::assertCount(1, $deferred, 'deferrals accumulated across filter() calls');
        self::assertSame('inlinecss', $deferred[0]['kind']);
        self::assertSame('two.css', $deferred[0]['payload']['file'], 'it should be the LAST render');
    }

    public function testVariablesSurviveAcrossRenders(): void
    {
        $adapter = (new TemplateFilterAdapter())->setVariables(['name' => 'Ada']);

        self::assertSame('Ada', $adapter->filter('{{var name}}'));
        self::assertSame('Ada', $adapter->filter('{{var name}}'), 'the scope was lost after the first render');
    }

    // ------------------------------------------------ small guards

    /** A value ending in a backslash must not run on into the next parameter. */
    public function testABackslashEndedValueDoesNotSwallowTheNextParameter(): void
    {
        $parsed = (new ParameterParser())->parse('file="a\\\\" other="b"');

        self::assertSame(['file' => 'a\\', 'other' => 'b'], $parsed);
    }

    /** Offsets outside the source must be clamped rather than producing nonsense. */
    public function testDiagnosticsClampOutOfRangeOffsets(): void
    {
        $source = "one\ntwo";

        self::assertSame(['line' => 1, 'column' => 1], Diagnostics::locate($source, -50));
        self::assertSame(['line' => 2, 'column' => 4], Diagnostics::locate($source, 9999));
    }
}
