<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\HostDirectives;
use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\Magento\TemplateModelUrlBuilder;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\PathGuard;
use Cresset\TemplateParser\Port\UrlBuilder;
use Cresset\TemplateParser\RenderPolicy;
use Cresset\TemplateParser\TemplateEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Four confirmed exploits, found by adversarial review of the URL directives.
 *
 * All four shared one root cause: the guards treated their input as a PATH, checked a form
 * of it that was not the form that got shipped, and every one of these directives emits its
 * result unescaped. None of them had a test - deleting the guards left the suite green,
 * which is how they got in.
 */
final class UrlGuardRegressionTest extends TestCase
{
    private function engine(): TemplateEngine
    {
        $engine = TemplateEngine::withOptions(Options::compatible());
        $urls = new class implements UrlBuilder {
            public function storeUrl(string $path, array $parameters): string
            {
                return 'https://shop.example/' . $path;
            }

            public function mediaUrl(string $path): string
            {
                return 'https://shop.example/media/' . $path;
            }

            public function viewUrl(string $path, array $parameters): string
            {
                return 'https://shop.example/static/' . $path;
            }

            public function isSecure(): bool
            {
                return true;
            }
        };
        HostDirectives::register($engine->evaluator(), new HostServices(urls: $urls));

        return $engine;
    }

    /**
     * A relative path carrying markup breaks out of the attribute it is written into.
     *
     * `<img src="{{media url=$p}}">` with `x"><script>…` closed the attribute and opened a
     * tag. Refused rather than escaped, so a legitimate URL still renders byte-for-byte.
     */
    #[DataProvider('markupPayloads')]
    public function testMarkupDelimitersAreRefusedInAPath(string $payload): void
    {
        self::assertFalse(PathGuard::isSafeRelativePath($payload));

        foreach (['{{media url=$p}}', '{{store url=$p}}', '{{view url=$p}}'] as $template) {
            self::assertSame(
                '',
                $this->engine()->render($template, context: new Context(['p' => $payload], RenderPolicy::restricted())),
                $template . ' emitted ' . $payload
            );
        }
    }

    public static function markupPayloads(): array
    {
        return [
            'attribute breakout' => ['x"><script>alert(document.domain)</script>'],
            'single quote'       => ["x'onerror=alert(1)"],
            'angle brackets'     => ['a<b>c'],
            'backtick'           => ['a`b'],
        ];
    }

    /**
     * {{protocol}} returns its value AS the whole output, with no base URL in front.
     *
     * An entity-encoded scheme passed the guard - the string starts with `&` - and the
     * browser decoded it back to `javascript:`. It ran under the default restricted policy,
     * in every mode, recording no violation.
     */
    public function testAnEntityEncodedSchemeCannotReachProtocol(): void
    {
        $payload = '&#106;avascript&#58;alert(document.domain)';

        self::assertFalse(PathGuard::isSafeRelativePath($payload));
        self::assertSame('', $this->engine()->render(
            '{{protocol http=$p https=$p}}',
            context: new Context(['p' => $payload], RenderPolicy::restricted())
        ));
    }

    /**
     * Decoding once let a value encoded twice through.
     *
     * The guard saw `&#46;&#46;/x` and called it safe; the browser saw `../x`. PathGuard now
     * decodes to a fixed point, and the ORIGINAL value is what reaches the port.
     */
    public function testDoubleEncodedTraversalIsRefused(): void
    {
        $payload = '&amp;#46;&amp;#46;&amp;#47;app&amp;#47;etc&amp;#47;env&amp;#46;php';

        self::assertFalse(PathGuard::isSafeRelativePath($payload));
        self::assertSame('', $this->engine()->render(
            '{{media url=$p}}',
            context: new Context(['p' => $payload], RenderPolicy::restricted())
        ));
    }

    /** A value needing no decoding reaches the port exactly as written. */
    public function testAnOrdinaryPathIsShippedUnchanged(): void
    {
        self::assertSame(
            'https://shop.example/media/logo&amp;x.png',
            $this->engine()->render(
                '{{media url=$p}}',
                context: new Context(['p' => 'logo&amp;x.png'], RenderPolicy::restricted())
            )
        );
    }

    /**
     * `_direct` is a second route wearing a different name.
     *
     * Url::getRouteUrl() returns `getBaseUrl() . $routeParams['_direct']` unfiltered, so
     * guarding only the route left the parameter array open.
     */
    #[DataProvider('directPayloads')]
    public function testGetUrlParametersAreGuarded(string $payload): void
    {
        $model = new class extends \Magento\Email\Model\AbstractTemplate {};

        self::assertSame('', (new TemplateModelUrlBuilder())->urlFor(
            $model,
            [new \Magento\Store\Model\Store(), 'customer/account/', ['_direct' => $payload]]
        ));
    }

    public static function directPayloads(): array
    {
        return [
            'traversal'        => ['../../../admin/'],
            'protocol relative' => ['//evil.example/phish'],
            'markup'           => ['x"><script>alert(1)</script>'],
        ];
    }

    /** The shipped builder still builds a URL for parameters that are fine. */
    public function testGetUrlStillBuildsAnOrdinaryUrl(): void
    {
        $model = new class extends \Magento\Email\Model\AbstractTemplate {};

        self::assertSame(
            'https://shop.example/customer/account/',
            (new TemplateModelUrlBuilder())->urlFor(
                $model,
                [new \Magento\Store\Model\Store(), 'customer/account/', ['_nosid' => 1.0]]
            )
        );
    }

    /** The receiver check is the whole guard on the one host method a template can call. */
    public function testANonTemplateReceiverIsDeclined(): void
    {
        self::assertNull((new TemplateModelUrlBuilder())->urlFor(
            new \stdClass(),
            [new \Magento\Store\Model\Store(), 'customer/account/', []]
        ));
    }
}
