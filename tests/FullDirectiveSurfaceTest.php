<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Evaluator;
use MageOS\TemplateParser\HostDirectives;
use MageOS\TemplateParser\HostServices;
use MageOS\TemplateParser\Parser;
use MageOS\TemplateParser\Port\ConfigReader;
use MageOS\TemplateParser\Port\CustomVariableReader;
use MageOS\TemplateParser\Port\LayoutRenderer;
use MageOS\TemplateParser\Port\StylesheetLoader;
use MageOS\TemplateParser\Port\UrlBuilder;
use MageOS\TemplateParser\Port\WidgetRenderer;
use MageOS\TemplateParser\TemplateEngine;
use MageOS\TemplateParser\UnknownDirectiveError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The full Magento directive surface, and the guard on each one.
 *
 * Every port records what it was asked for, so the tests can assert the important half: for
 * rejected input the host is never reached at all. Refusing after the fact is the mistake
 * that made BlockFactory exploitable.
 */
final class FullDirectiveSurfaceTest extends TestCase
{
    /** @var array<string,list<array>> */
    private array $calls = [];

    private function services(): HostServices
    {
        $record = function (string $port, array $args): void {
            $this->calls[$port][] = $args;
        };

        return new HostServices(
            blocks: new class ($record) implements \MageOS\TemplateParser\Port\BlockRenderer {
                public function __construct(private $r) {}
                public function render(string $class, array $data, string $method): string
                { ($this->r)('block', [$class, $data, $method]); return 'BLOCK:' . $class; }
            },
            config: new class ($record) implements ConfigReader {
                public function __construct(private $r) {}
                public function value(string $path): ?string
                { ($this->r)('config', [$path]); return 'CONFIG:' . $path; }
            },
            customVariables: new class ($record) implements CustomVariableReader {
                public function __construct(private $r) {}
                public function value(string $code, bool $plainText): ?string
                { ($this->r)('customvar', [$code]); return 'VAR:' . $code; }
            },
            urls: new class ($record) implements UrlBuilder {
                public function __construct(private $r) {}
                public bool $secure = false;
                public function storeUrl(string $path, array $p): string
                { ($this->r)('storeUrl', [$path, $p]); return 'https://shop/' . $path; }
                public function mediaUrl(string $path): string
                { ($this->r)('mediaUrl', [$path]); return 'https://shop/media/' . $path; }
                public function viewUrl(string $path, array $p): string
                { ($this->r)('viewUrl', [$path, $p]); return 'https://shop/static/' . $path; }
                public function isSecure(): bool { return $this->secure; }
            },
            stylesheets: new class ($record) implements StylesheetLoader {
                public function __construct(private $r) {}
                public function load(string $file): ?string
                { ($this->r)('css', [$file]); return 'body{color:red}'; }
            },
            layouts: new class ($record) implements LayoutRenderer {
                public function __construct(private $r) {}
                public function render(string $handle, string $area, array $p): string
                { ($this->r)('layout', [$handle, $area, $p]); return 'LAYOUT:' . $handle . '@' . $area; }
            },
            widgets: new class ($record) implements WidgetRenderer {
                public function __construct(private $r) {}
                public function render(string $type, array $p): string
                { ($this->r)('widget', [$type, $p]); return 'WIDGET:' . $type; }
            },
        );
    }

    private function engine(?HostServices $services = null): TemplateEngine
    {
        $parser = new Parser();
        $evaluator = new Evaluator();
        HostDirectives::register($evaluator, $services ?? $this->services(), $parser);
        return new TemplateEngine($parser, $evaluator);
    }

    // ------------------------------------------------------------------
    // Each directive works when its port is supplied.
    // ------------------------------------------------------------------

    #[DataProvider('workingDirectives')]
    public function testDirectiveRendersThroughItsPort(string $template, string $expected, string $port): void
    {
        // block, widget and layout are denied by the default policy, so this grants them.
        $policy = \MageOS\TemplateParser\RenderPolicy::unrestricted();

        self::assertSame($expected, $this->engine()->render($template, [], null, $policy));
        self::assertArrayHasKey($port, $this->calls, 'the port should have been reached');
    }

    /** The instantiating directives are refused by the default policy even when wired up. */
    #[DataProvider('instantiatingDirectives')]
    public function testInstantiatingDirectivesNeedExplicitGranting(string $template, string $port): void
    {
        self::assertSame('', $this->engine()->render($template));
        self::assertArrayNotHasKey($port, $this->calls, 'the port must not have been reached');
    }

    public static function instantiatingDirectives(): array
    {
        return [
            'block'  => ['{{block class="Vendor\\Block"}}', 'block'],
            'widget' => ['{{widget type="Vendor\\Widget"}}', 'widget'],
            'layout' => ['{{layout handle="some_handle"}}', 'layout'],
        ];
    }

    public static function workingDirectives(): array
    {
        return [
            'config'    => ['{{config path="web/unsecure/base_url"}}', 'CONFIG:web/unsecure/base_url', 'config'],
            'customvar' => ['{{customvar code="promo_text"}}', 'VAR:promo_text', 'customvar'],
            'store'     => ['{{store url="checkout/cart"}}', 'https://shop/checkout/cart', 'storeUrl'],
            'media'     => ['{{media url="logo/logo.png"}}', 'https://shop/media/logo/logo.png', 'mediaUrl'],
            'view'      => ['{{view url="images/x.png"}}', 'https://shop/static/images/x.png', 'viewUrl'],
            'css'       => ['{{css file="css/email.css"}}', 'body{color:red}', 'css'],
            'layout'    => ['{{layout handle="sales_email_order_items"}}',
                            'LAYOUT:sales_email_order_items@frontend', 'layout'],
            'widget'    => ['{{widget type="Magento\\Cms\\Block\\Widget\\Block"}}',
                            'WIDGET:Magento\\Cms\\Block\\Widget\\Block', 'widget'],
            'block'     => ['{{block class="Vendor\\Some\\Block"}}', 'BLOCK:Vendor\\Some\\Block', 'block'],
        ];
    }

    public function testProtocolChoosesTheScheme(): void
    {
        $services = $this->services();
        self::assertSame('http://shop.example/x', $this->engine($services)->render('{{protocol url="shop.example/x"}}'));

        $services->urls->secure = true;
        self::assertSame('https://shop.example/x', $this->engine($services)->render('{{protocol url="shop.example/x"}}'));
    }

    public function testProtocolPicksBetweenHttpAndHttpsParameters(): void
    {
        $services = $this->services();
        self::assertSame('plain/page', $this->engine($services)->render('{{protocol http="plain/page" https="secure/page"}}'));

        $services->urls->secure = true;
        self::assertSame('secure/page', $this->engine($services)->render('{{protocol http="plain/page" https="secure/page"}}'));
    }

    // ------------------------------------------------------------------
    // Unsafe input is rejected BEFORE the host is reached.
    // ------------------------------------------------------------------

    #[DataProvider('rejectedInput')]
    public function testUnsafeInputNeverReachesTheHost(string $template, string $port): void
    {
        $out = $this->engine()->render($template, [], null, \MageOS\TemplateParser\RenderPolicy::unrestricted());

        self::assertArrayNotHasKey($port, $this->calls, 'the host must not have been called at all');
        self::assertStringNotContainsString('..', $out);
    }

    public static function rejectedInput(): array
    {
        return [
            'media traversal'      => ['{{media url="../../app/etc/env.php"}}', 'mediaUrl'],
            'media absolute'       => ['{{media url="/etc/passwd"}}', 'mediaUrl'],
            'media scheme'         => ['{{media url="http://evil.example/x"}}', 'mediaUrl'],
            'media entity-encoded' => ['{{media url="&#46;&#46;/x"}}', 'mediaUrl'],
            'view traversal'       => ['{{view url="../../../secret"}}', 'viewUrl'],
            'view protocol-rel'    => ['{{view url="//evil.example/x.js"}}', 'viewUrl'],
            'store traversal'      => ['{{store url="../admin"}}', 'storeUrl'],
            'css traversal'        => ['{{css file="../../app/etc/env.php"}}', 'css'],
            'css php wrapper'      => ['{{css file="php://filter/resource=x"}}', 'css'],
            'config traversal'     => ['{{config path="../../secret"}}', 'config'],
            'config with space'    => ['{{config path="a b"}}', 'config'],
            'customvar traversal'  => ['{{customvar code="../x"}}', 'customvar'],
            'layout bad handle'    => ['{{layout handle="../../etc"}}', 'layout'],
            'layout bad area'      => ['{{layout handle="ok" area="webapi_rest"}}', 'layout'],
            'widget bad type'      => ['{{widget type="../evil"}}', 'widget'],
            'widget with space'    => ['{{widget type="Some Class"}}', 'widget'],
            'protocol scheme'      => ['{{protocol url="//evil.example"}}', 'storeUrl'],
        ];
    }

    public function testProtocolRejectsAnEmbeddedScheme(): void
    {
        self::assertSame('', $this->engine()->render('{{protocol url="javascript:alert(1)"}}'));
        self::assertSame('', $this->engine()->render('{{protocol url="evil.example\n"}}'));
    }

    // ------------------------------------------------------------------
    // A capability not granted is not silently available.
    // ------------------------------------------------------------------

    #[DataProvider('allDirectiveNames')]
    public function testDirectiveIsUnavailableWithoutItsPort(string $template): void
    {
        $engine = $this->engine(new HostServices());   // nothing granted

        $this->expectException(UnknownDirectiveError::class);
        $engine->render($template);
    }

    public static function allDirectiveNames(): array
    {
        return [
            'config'    => ['{{config path="web/x"}}'],
            'customvar' => ['{{customvar code="x"}}'],
            'store'     => ['{{store url="x"}}'],
            'media'     => ['{{media url="x.png"}}'],
            'view'      => ['{{view url="x.png"}}'],
            'css'       => ['{{css file="x.css"}}'],
            'layout'    => ['{{layout handle="x"}}'],
            'widget'    => ['{{widget type="X"}}'],
            'block'     => ['{{block class="X"}}'],
            'template'  => ['{{template config_path="design/email/header"}}'],
        ];
    }

    /** With every port supplied, no stock directive is left unimplemented. */
    public function testTheWholeStockSurfaceIsCovered(): void
    {
        $registered = $this->engine()->evaluator()->registered();

        foreach (['var','if','depend','for','else','trans','inlinecss','config','customvar',
                  'store','media','view','css','layout','widget','protocol','block'] as $name) {
            self::assertContains($name, $registered, "{{{$name}}} should be implemented");
        }
    }
}
