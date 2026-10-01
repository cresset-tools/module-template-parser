<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\LegacyReading;
use Cresset\TemplateParser\Magento\Config\EngineMode;
use Cresset\TemplateParser\Magento\ConfigTemplateLoader;
use Cresset\TemplateParser\Magento\Plugin\TemplateFilterPlugin;
use Cresset\TemplateParser\Magento\RenderScope;
use Cresset\TemplateParser\Magento\Shadow\ShadowOutcome;
use Cresset\TemplateParser\Magento\Shadow\ShadowRecorder;
use Cresset\TemplateParser\Magento\Shadow\TemplateIdentity;
use Cresset\TemplateParser\Magento\ShadowComparator;
use Cresset\TemplateParser\Magento\StoreUrlBuilder;
use Cresset\TemplateParser\Magento\TemplateFilterAdapter;
use Cresset\TemplateParser\Magento\TypeCheckedWidgetRenderer;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\Port\UrlBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\ObjectManager\ConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Widget\Block\BlockInterface as WidgetBlockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Known differences from Mage-OS 3.5.0 that Parser mode would have served.
 *
 * A difference this engine knows about is one it must never serve: fixed to match the filter
 * where that is small and checkable, declined - so the filter renders it - where it is not.
 */
final class LegacyFaithfulnessTest extends TestCase
{
    use AssertsRefusals;

    // ---------------------------------------------------------------- {{widget}} data

    /**
     * generateWidget hands the block exactly what it was given - `type` included - adds the
     * filter's store as `store_id` when the template did not, and names the block `name`.
     */
    public function testAWidgetGetsWhatGenerateWidgetGivesIt(): void
    {
        $scope = new RenderScope();
        $created = [];
        $renderer = new TypeCheckedWidgetRenderer($this->layout($created), $this->widgetTypes(), null, $scope);
        $type = '\\' . $this->widgetClass();

        $scope->push(3, null);
        $renderer->render($type, ['block_id' => '7', 'name' => 'promo']);
        $renderer->render($type, ['block_id' => '7', 'store_id' => '1']);
        $scope->pop();
        $renderer->render($type, ['block_id' => '7']);

        self::assertSame(
            [
                ['promo', ['type' => $type, 'block_id' => '7', 'name' => 'promo', 'store_id' => 3]],
                [null, ['type' => $type, 'block_id' => '7', 'store_id' => '1']],
                [null, ['type' => $type, 'block_id' => '7']],
            ],
            $created
        );
    }

    /** The store is the filter's own, pushed by the plugin around this engine's render. */
    public function testThePluginPushesTheFiltersStoreForTheRender(): void
    {
        $scope = new RenderScope();
        $seen = [];
        $urls = new class ($scope, $seen) implements UrlBuilder {
            public function __construct(private RenderScope $scope, private array &$seen) {}
            public function storeUrl(string $path, array $parameters): string { $this->seen[] = $this->scope->storeId(); return 'U'; }
            public function mediaUrl(string $path): string { return ''; }
            public function viewUrl(string $path, array $parameters): string { return ''; }
            public function isSecure(): bool { return false; }
        };
        $plugin = $this->plugin(new HostServices(urls: $urls), $scope);
        $subject = $this->filter(storeId: 4);
        $plugin->beforeSetVariables($subject, ['x' => 1]);

        $plugin->aroundFilter($subject, static fn () => 'LEGACY', '{{store url="a"}}');

        self::assertSame([4], $seen);
        self::assertNull($scope->storeId(), 'the frame outlived the render');
    }

    // ---------------------------------------------------------------- the filter's URL model

    /**
     * {{store}} builds with the RENDERING filter's URL model - the one Email\Model\Template, or
     * a module, gave it - and the port's own only outside a render.
     */
    public function testStoreUrlsUseTheRenderingFiltersUrlModel(): void
    {
        $scope = new RenderScope();
        $default = $this->urlModel('https://shop.example/');
        $builder = new StoreUrlBuilder($default, $this->stores(), new Repository(), $scope);
        $plugin = $this->plugin(new HostServices(urls: $builder), $scope);
        $subject = $this->filter(storeId: 1, urlModel: $this->urlModel('https://brand.example/'));
        $plugin->beforeSetVariables($subject, ['x' => 1]);

        $served = $plugin->aroundFilter($subject, static fn () => 'LEGACY', '{{store url="sales/order"}}');

        self::assertSame('https://brand.example/sales/order', $served);
        self::assertSame('https://shop.example/sales/order', $builder->storeUrl('sales/order', []), 'outside a render, the default');
    }

    /**
     * The backend URL model keeps its route between calls, so what `{{store url=""}}` builds
     * through it is whatever it built last. Not reproducible, so not answered.
     */
    public function testStoreUrlsThroughTheBackendUrlModelAreRefused(): void
    {
        $scope = new RenderScope();
        $builder = new StoreUrlBuilder($this->urlModel('https://shop.example/'), $this->stores(), new Repository(), $scope);
        $backend = new class extends \Magento\Backend\Model\Url {
            public function getUrl($routePath = null, $routeParams = null) { return 'https://admin.example/swatches/ajax/media/'; }
            public function setScope($params) { return $this; }
        };

        $scope->push(1, $backend);
        self::assertRefused(fn () => $builder->storeUrl('', []), 'store url through the backend URL model');
        $scope->pop();
    }

    // ---------------------------------------------------------------- {{protocol store=}}

    public function testProtocolStoreIsAnsweredForThatStore(): void
    {
        $builder = new StoreUrlBuilder($this->urlModel('x'), $this->stores(['2' => true, '1' => false]), new Repository());
        $adapter = new TemplateFilterAdapter(new HostServices(urls: $builder), Options::compatible());

        self::assertSame('https', $adapter->setVariables(['x' => 1])->filter('{{protocol store="2"}}'));
        self::assertSame('http', $adapter->filter('{{protocol store="1"}}'));
        self::assertSame([], $adapter->violations());
    }

    /** A store that does not exist makes the filter raise; this declines. */
    public function testProtocolForAMissingStoreIsDeclined(): void
    {
        $builder = new StoreUrlBuilder($this->urlModel('x'), $this->stores(['1' => false]), new Repository());
        $adapter = new TemplateFilterAdapter(new HostServices(urls: $builder), Options::compatible());

        $adapter->setVariables(['x' => 1])->filter('{{protocol store="nope"}}');

        self::assertSame([['protocol store', 'nope']], array_map(static fn ($v) => [$v->kind, $v->name], $adapter->violations()));
    }

    /** A UrlBuilder that cannot answer for another store declines rather than answer for this one. */
    public function testProtocolStoreWithAPortThatCannotAnswerIsDeclined(): void
    {
        $adapter = new TemplateFilterAdapter(new HostServices(urls: new class implements UrlBuilder {
            public function storeUrl(string $path, array $parameters): string { return ''; }
            public function mediaUrl(string $path): string { return ''; }
            public function viewUrl(string $path, array $parameters): string { return ''; }
            public function isSecure(): bool { return true; }
        }), Options::compatible());

        $adapter->setVariables(['x' => 1])->filter('{{protocol store="2"}}');

        self::assertSame([['protocol store', '2']], array_map(static fn ($v) => [$v->kind, $v->name], $adapter->violations()));
    }

    // ---------------------------------------------------------------- the newsletter filter's widgets

    /** FilterEmulate renders each widget in an emulated frontend area; that is the filter's to do. */
    public function testANewsletterWidgetFallsBack(): void
    {
        require_once __DIR__ . '/fixtures/LegacyOnlyDirectives.php';
        $records = [];
        $plugin = $this->plugin(new HostServices(), new RenderScope(), $records);
        $subject = new \Magento\Widget\Model\Template\FilterEmulate();
        $plugin->beforeSetVariables($subject, ['x' => 1]);

        self::assertSame('LEGACY', $plugin->aroundFilter($subject, static fn () => 'LEGACY', 'Hi {{widget type="X"}}'));
        self::assertSame(ShadowOutcome::REFUSED, $records[0]);
        self::assertSame('Hi there', $plugin->aroundFilter($subject, static fn () => 'LEGACY', 'Hi there'), 'a newsletter without one is ours');
    }

    // ---------------------------------------------------------------- what the filter reads differently

    /** @return array<string,array{0:string,1:string}> */
    public static function readingsTheFilterDoesNotShare(): array
    {
        return [
            'a {{for}} loop' => ['<ul>{{for item in items}}<li>{{var item.name}}</li>{{/for}}</ul>', 'a {{for}} loop'],
            'a brace before the opener' => ['.a{{{var color}}}', 'a construct the filter reads to the first }}'],
            'a stray opener' => ['{{A{{var x}}', 'a construct the filter reads to the first }}'],
            'one missing brace' => ['Hi {{var a}, bye {{var a}}', 'a construct the filter reads to the first }}'],
            'a quoted opener' => ['{{trans "a {{b}}"}}', 'a construct the filter reads to the first }}'],
            'a quoted closer' => ['{{trans "a }}b"}}', 'a quoted }} the filter ends the directive at'],
            'an apostrophe in a single-quoted string' => ["{{trans 'you'd like'}}", 'a quoted }} the filter ends the directive at'],
            'a name the filter reads as {{if}}' => ['{{iframe}}x{{if a}}y{{/if}}', 'a name the filter reads as {{if}}'],
        ];
    }

    #[DataProvider('readingsTheFilterDoesNotShare')]
    public function testAReadingTheFilterDoesNotShareIsRecorded(string $template, string $rule): void
    {
        self::assertSame($rule, LegacyReading::firstDifference($template)['rule'] ?? null);

        $adapter = new TemplateFilterAdapter(new HostServices(), Options::compatible());
        $adapter->setVariables(['x' => 1])->filter($template);

        self::assertContains(
            ['construct the filter reads differently', $rule],
            array_map(static fn ($v) => [$v->kind, $v->name], $adapter->violations())
        );
    }

    /** @return array<string,array{0:string}> */
    public static function readingsTheFilterShares(): array
    {
        return [
            'prose that starts like a loop' => ['{{Forgot Your Password?}}'],
            'a quoted apostrophe' => ['{{trans "it\'s here"}}'],
            'an ordinary template' => ['Dear {{var name}}, {{if a}}{{trans "Hi"}}{{else}}x{{/if}} {{depend b}}y{{/depend}}'],
            'a directive name merely starting with if' => ['{{iframe}} with no closer'],
            'no directives at all' => ['plain text { } }}'],
        ];
    }

    #[DataProvider('readingsTheFilterShares')]
    public function testAReadingTheFilterSharesIsNotFlagged(string $template): void
    {
        self::assertNull(LegacyReading::firstDifference($template));
    }

    /** Only the compatible posture claims to render as the filter does. */
    public function testOnlyTheCompatiblePostureRecordsIt(): void
    {
        $adapter = new TemplateFilterAdapter(new HostServices(), Options::lenient());
        $adapter->setVariables(['x' => 1])->filter('{{for i in x}}a{{/for}}');

        self::assertSame([], $adapter->violations());
    }

    /** An include is rendered as part of its parent, so one the filter reads differently declines it. */
    public function testAnIncludeTheFilterReadsDifferentlyIsRefused(): void
    {
        $template = new class extends \Magento\Email\Model\Template {
            public function getTemplateText() { return '{{for i in items}}{{var i}}{{/for}}'; }
        };
        $loader = new ConfigTemplateLoader(
            new class implements ScopeConfigInterface {
                public function getValue($path, $scope = 'default', $scopeCode = null) { return 'design_email_header'; }
                public function isSetFlag($path, $scope = 'default', $scopeCode = null) { return false; }
            },
            ['design/email/'],
            new class ($template) extends \Magento\Email\Model\TemplateFactory {
                public function __construct(private object $template) {}
                public function create(array $data = []) { return $this->template; }
            }
        );

        self::assertRefused(fn () => $loader->load('design/email/header_template'), 'template include the filter reads differently');
    }

    // ---------------------------------------------------------------- helpers

    private function widgetClass(): string
    {
        return get_class(new class implements WidgetBlockInterface { public function toHtml() { return 'W'; } });
    }

    private function widgetTypes(): ConfigInterface
    {
        return new class implements ConfigInterface {
            public function getInstanceType($instanceName) { return $instanceName; }
            public function getPreference($type) { return $type; }
        };
    }

    /** @param list<array{0:?string,1:array}> $created */
    private function layout(array &$created): LayoutInterface
    {
        return new class ($created) implements LayoutInterface {
            public function __construct(private array &$created) {}
            public function createBlock($type, $name = '', array $arguments = [])
            {
                $this->created[] = [$name, $arguments['data']];
                return new class implements WidgetBlockInterface { public function toHtml() { return 'W'; } };
            }
        };
    }

    private function urlModel(string $base): UrlInterface
    {
        return new class ($base) implements UrlInterface {
            public function __construct(private string $base) {}
            public function getUrl($routePath = null, $routeParams = null) { return $this->base . $routePath; }
            public function setScope($params) { return $this; }
        };
    }

    /** @param array<string,bool> $secure store => currently secure; any other store raises */
    private function stores(array $secure = ['1' => false]): StoreManagerInterface
    {
        return new class ($secure) implements StoreManagerInterface {
            public function __construct(private array $secure) {}
            public function getStore($storeId = null)
            {
                $id = $storeId === null ? '1' : (string)$storeId;
                if (!array_key_exists($id, $this->secure)) {
                    throw new \RuntimeException('no such store');
                }
                return new class ($this->secure[$id]) {
                    public function __construct(private bool $secure) {}
                    public function isCurrentlySecure() { return $this->secure; }
                    public function getCode() { return 'default'; }
                    public function getBaseUrl($type = 'link', $secure = null) { return ''; }
                };
            }
        };
    }

    private function filter(int $storeId, ?UrlInterface $urlModel = null): \Magento\Framework\Filter\Template
    {
        return new class ($storeId, $urlModel) extends \Magento\Framework\Filter\Template {
            protected $_storeId;
            protected $urlModel;
            public function __construct($storeId, $urlModel) { $this->_storeId = $storeId; $this->urlModel = $urlModel; }
        };
    }

    /** Parser everywhere, nothing sampled; `$records` gets each outcome. */
    private function plugin(HostServices $services, RenderScope $scope, array &$records = []): TemplateFilterPlugin
    {
        $recorder = new class ($records) extends ShadowRecorder {
            public function __construct(private array &$records) {}
            public function record(int|string|null $storeId, string $template, ?ShadowOutcome $outcome, bool $served = false, bool $fellBack = false): void
            {
                $this->records[] = $outcome?->outcome;
            }
        };
        $mode = new EngineMode(new class implements ScopeConfigInterface {
            public function getValue($path, $scope = 'default', $scopeCode = null) { return $path === EngineMode::XML_PATH ? EngineMode::PARSER : '0'; }
            public function isSetFlag($path, $scope = 'default', $scopeCode = null) { return false; }
        });

        return new TemplateFilterPlugin(
            new ShadowComparator(new TemplateFilterAdapter($services, Options::compatible())),
            $mode,
            new TemplateIdentity(),
            $recorder,
            $scope
        );
    }
}
