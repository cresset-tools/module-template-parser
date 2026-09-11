<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Evaluator;
use Cresset\TemplateParser\HostDirectives;
use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\Parser;
use Cresset\TemplateParser\Port\ConfigReader;
use Cresset\TemplateParser\Port\CustomVariableReader;
use Cresset\TemplateParser\Port\LayoutRenderer;
use Cresset\TemplateParser\Port\StylesheetLoader;
use Cresset\TemplateParser\Port\UrlBuilder;
use Cresset\TemplateParser\Port\WidgetRenderer;
use Cresset\TemplateParser\TemplateEngine;
use Cresset\TemplateParser\UnknownDirectiveError;
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
            blocks: new class ($record) implements \Cresset\TemplateParser\Port\BlockRenderer {
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
                public function load(string $file, array $designParams = []): ?string
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
        $policy = \Cresset\TemplateParser\RenderPolicy::unrestricted();

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
        $pair = '{{protocol http="http://plain.example/page" https="https://secure.example/page"}}';

        $services = $this->services();
        self::assertSame('http://plain.example/page', $this->engine($services)->render($pair));

        $services->urls->secure = true;
        self::assertSame('https://secure.example/page', $this->engine($services)->render($pair));
    }

    /**
     * These parameters are DEFINED to carry absolute URLs, and this test used to assert the
     * opposite - that `http="plain/page"` rendered `plain/page` - which is a value
     * validateProtocolDirectiveHttpScheme refuses outright, so nothing that reached it here
     * could ever have reached a real store.
     */
    public function testProtocolRefusesParametersThatAreNotAbsoluteUrlsOnTheirOwnScheme(): void
    {
        $engine = $this->engine($this->services());

        foreach ([
            '{{protocol http="plain/page" https="secure/page"}}',
            '{{protocol http="https://a.example/x" https="https://a.example/x"}}',
            '{{protocol http="http://a.example/x" https="http://a.example/x"}}',
            '{{protocol http="javascript&#58;alert(1)" https="javascript&#58;alert(1)"}}',
        ] as $template) {
            self::assertSame('', $engine->render($template), $template);
        }
    }

    /**
     * A custom variable code is a lookup key, and merchants namespace them with separators.
     *
     * Variable::validate() checks a code for existence and uniqueness and nothing else, so
     * `checkout/tos` is a legal code and holding it to the identifier shape refused it - the
     * directive rendered nothing, forever, with nothing to say why. A traversal run is still
     * refused, because PathGuard's contract is that the handler guards so no port has to.
     */
    public function testACustomVariableCodeIsNotHeldToTheIdentifierShape(): void
    {
        $services = $this->services();
        $engine = $this->engine($services);

        foreach (['checkout/tos', 'store hours', 'a.b-c_d'] as $code) {
            self::assertSame(
                'VAR:' . $code,
                $engine->render(sprintf('{{customvar code="%s"}}', $code)),
                $code
            );
        }
    }

    /**
     * An absent path is the base URL, not a refusal.
     *
     * mediaDirective is `getBaseUrl(MEDIA) . $params['url']`, so with no `url` it concatenates
     * nothing and the directive IS the media root. Refusing it instead was a divergence on a
     * shape any merchant reaches by typo'ing a variable name into `{{media url=$p}}` - and
     * refusing a base URL protects nothing, because there is no attacker-controlled part left.
     */
    public function testAnAbsentPathIsTheBaseUrlRatherThanARefusal(): void
    {
        $engine = $this->engine($this->services());

        self::assertSame('https://shop/media/', $engine->render('{{media}}'));
        self::assertSame('https://shop/media/', $engine->render('{{media url=""}}'));
        self::assertSame('https://shop/media/', $engine->render('{{media url=$empty}}', ['empty' => '']));
        self::assertSame('https://shop/static/', $engine->render('{{view}}'));
        self::assertSame('https://shop/static/', $engine->render('{{view url=""}}'));
    }

    /**
     * A missing `file` gets cssDirective's own words; a refused one gets this engine's.
     *
     * The distinction matters: the first is a message the old filter produces and templates
     * have always rendered, so it must come out the same. The second is a REFUSAL made here,
     * and dressing it in legacy's wording would attribute it to a filter that would have
     * tried to load the file.
     */
    public function testTheCssMessageForAMissingFileIsTheFiltersOwn(): void
    {
        $engine = $this->engine($this->services());

        self::assertSame('/* "file" parameter must be specified */', $engine->render('{{css}}'));
        self::assertSame('/* "file" parameter must be specified */', $engine->render('{{css file=""}}'));
        self::assertSame('/* invalid file parameter */', $engine->render('{{css file="../../app/etc/env.php"}}'));
    }

    /** A bare {{protocol}} is the scheme itself - the form `{{protocol}}://{{store url=''}}` uses. */
    public function testBareProtocolIsTheScheme(): void
    {
        $services = $this->services();
        self::assertSame('http', $this->engine($services)->render('{{protocol}}'));

        $services->urls->secure = true;
        self::assertSame('https', $this->engine($services)->render('{{protocol}}'));
    }

    // ------------------------------------------------------------------
    // Unsafe input is rejected BEFORE the host is reached.
    // ------------------------------------------------------------------

    #[DataProvider('rejectedInput')]
    public function testUnsafeInputNeverReachesTheHost(string $template, string $port): void
    {
        $out = $this->engine()->render($template, [], null, \Cresset\TemplateParser\RenderPolicy::unrestricted());

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
