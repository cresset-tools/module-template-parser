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

    /**
     * The semicolon is optional on a NUMERIC character reference, and the guard required it.
     *
     * html_entity_decode() decodes `&#58;` and leaves `&#58` alone; the HTML tokenizer emits
     * the colon for both, because the missing-semicolon exception that keeps `&amp` intact
     * next to a letter is written for NAMED references only. So the guard and the browser
     * disagreed about one character, and `javascript&#58alert(1)` shipped as a relative path
     * and arrived as a scheme. Traversal and protocol-relative forms rode the same gap.
     */
    #[DataProvider('unterminatedReferences')]
    public function testAReferenceWithoutItsSemicolonIsDecodedTheWayABrowserDecodesIt(string $payload): void
    {
        self::assertFalse(PathGuard::isSafeRelativePath($payload), $payload);

        foreach (['{{media url=$p}}', '{{store url=$p}}', '{{view url=$p}}', '{{protocol url=$p}}'] as $template) {
            self::assertSame(
                '',
                $this->engine()->render($template, context: new Context(['p' => $payload], RenderPolicy::restricted())),
                $template . ' emitted ' . $payload
            );
        }
    }

    public static function unterminatedReferences(): array
    {
        return [
            'decimal scheme'       => ['javascript&#58alert(document.domain)'],
            'padded decimal'       => ['javascript&#0000058alert(1)'],
            'percent then decimal' => ['javascript%26%2358alert(1)'],
            'uppercase scheme'     => ['JaVaScRiPt&#58alert(1)'],
            'data uri'             => ['data&#58text/html,<svg onload=alert(1)>'],
            'traversal'            => ['&#46&#46/&#46&#46/app/etc/env.php'],
            'protocol relative'    => ['&#47&#47evil.example/x'],
            'named, text content'  => ['a&ltscript&gtalert(1)&lt/script&gt'],
        ];
    }

    /**
     * Terminating references must not corrupt the ones that are already terminated.
     *
     * The first version of the fix used a backtracking `+`, so `&#x3A;` failed the lookahead
     * on the full hex run, gave back the `A`, and was rewritten to `&#x3;A;` - which
     * html_entity_decode leaves alone, U+0003 being a control character. The guard then saw
     * no colon at all, and a payload that was refused BEFORE the fix passed after it.
     */
    public function testTerminatingReferencesDoesNotBreakAlreadyTerminatedOnes(): void
    {
        foreach (['javascript&#x3A;alert(1)', 'javascript&#X3a;alert(1)', '&#x2F;&#x2F;evil.example'] as $payload) {
            self::assertFalse(PathGuard::isSafeRelativePath($payload), $payload);
        }

        // And the hex run really is greedy, in the guard as in the tokenizer: `&#x2Fevil` is
        // U+02FE followed by `vil`, not a slash - so it is not a traversal and is not refused.
        self::assertTrue(PathGuard::isSafeRelativePath('&#x2Fevil'));
    }

    /**
     * `{{protocol http= https=}}` takes absolute URLs, and was guarded as if it took paths.
     *
     * The relative-path guard refuses anything carrying a scheme, so every value legacy
     * ACCEPTS rendered empty, and the only values that got through were the ones with no
     * scheme for the guard to find - which is the payload above. Legacy requires each
     * parameter to parse and to carry its own scheme, and so does this now.
     */
    public function testProtocolPairIsHeldToItsOwnScheme(): void
    {
        $engine = $this->engine();
        $render = static fn (string $t, string $p): string
            => $engine->render($t, context: new Context(['p' => $p], RenderPolicy::restricted()));

        // isSecure() is true on this stub, so the https parameter is the one that renders.
        self::assertSame(
            'https://secure.example/x',
            $engine->render('{{protocol http="http://plain.example/x" https="https://secure.example/x"}}')
        );

        foreach ([
            'no scheme'        => 'plain/page',
            'wrong scheme'     => 'http://plain.example/x',
            'entity scheme'    => 'javascript&#58alert(1)',
            'scheme, no host'  => 'https:alert(1)',
            'markup in a url'  => 'https://a.example/x"><script>alert(1)</script>',
            'entity markup'    => 'https://a.example/x&#34;&#62;&#60;script&#62;',
        ] as $label => $payload) {
            self::assertSame('', $render('{{protocol http=$p https=$p}}', $payload), $label);
        }

        // A scheme with no authority after it. Both parameters carry their OWN correct
        // scheme here, so the scheme check has nothing to say and the host check is the
        // only thing left deciding - `https:alert(1)` is a URI a browser will happily run.
        self::assertSame('', $engine->render(
            '{{protocol http="http:alert(1)" https="https:alert(1)"}}'
        ));
        self::assertSame('', $engine->render(
            '{{protocol http="http:///x" https="https:///x"}}'
        ));
    }

    /**
     * `{{protocol url=}}` is `$scheme . '://' . $value` in legacy, with nothing checked at all.
     *
     * The host pattern here does the checking instead, and everything after the first slash
     * is only `[^\s]*` - which is every markup delimiter there is. The path guard behind it
     * is what actually refuses those, so removing it left the suite green until this existed.
     */
    public function testProtocolUrlRefusesMarkupAfterTheHost(): void
    {
        foreach ([
            'attribute breakout' => 'a.example/x"><script>alert(1)</script>',
            'entity breakout'    => 'a.example/x&#34;&#62;&#60;script&#62;',
            'traversal'          => 'a.example/../../app/etc/env.php',
            'backtick'           => 'a.example/`x`',
            // A payload with no slash has no tail to guard, so the HOST pattern is the only
            // thing standing between it and the output.
            'markup in the host' => 'a.example" onmouseover="alert(1)',
            'scheme as a host'   => 'javascript:alert(1)',
            'newline in the host' => "a.example\n/x",
        ] as $label => $payload) {
            self::assertSame(
                '',
                $this->engine()->render(
                    '{{protocol url=$p}}',
                    context: new Context(['p' => $payload], RenderPolicy::restricted())
                ),
                $label
            );
        }
    }

    /** A port is part of a host, and the pattern used to have no room for one. */
    public function testProtocolUrlKeepsAPort(): void
    {
        self::assertSame(
            'https://example.com:8080/a',
            $this->engine()->render('{{protocol url="example.com:8080/a"}}')
        );
    }

    /**
     * A route parameter is a path segment, so guarding three parameter NAMES guarded nothing.
     *
     * `Url::_getRouteParams()` appends every route parameter as `$key . '/' . $value . '/'`,
     * so any spelling other than the three that were named - and the key as much as the
     * value - walked around the guard.
     */
    #[DataProvider('routeParameterPayloads')]
    public function testEveryRouteParameterIsGuardedNotJustTheThreeThatWereNamed(string $template): void
    {
        self::assertSame(
            '',
            $this->engine()->render(
                $template,
                context: new Context(['p' => '../../../app/etc/env.php'], RenderPolicy::restricted())
            ),
            $template
        );
    }

    public static function routeParameterPayloads(): array
    {
        return [
            'unnamed route param' => ['{{store url="customer/account" anything=$p}}'],
            'the key itself'      => ['{{store url="customer/account" ../../..="1"}}'],
            'view design locale'  => ['{{view url="css/email.css" locale=$p}}'],
            'view design area'    => ['{{view url="css/email.css" area=$p}}'],
            'view design theme'   => ['{{view url="css/email.css" theme=$p}}'],
            'view design module'  => ['{{view url="css/email.css" module=$p}}'],
            'view theme model'    => ['{{view url="css/email.css" themeModel="x"}}'],
            'store type'          => ['{{store url="customer/account" _type=$p}}'],
        ];
    }

    /**
     * A query parameter is not a path, and must not be held to a path guard.
     *
     * storeDirective moves `_query_x` into `_query`, where the URL model escapes it and it
     * lands in the query string - so an apostrophe in a customer name is ordinary data, and
     * refusing it would break the password-reset link that stock templates build this way.
     */
    public function testQueryParametersAreNotRefusedForLookingLikeText(): void
    {
        self::assertSame(
            'https://shop.example/customer/account',
            $this->engine()->render(
                '{{store url="customer/account" _query_name=$p _query_token="a1b2c3"}}',
                context: new Context(['p' => "O'Brien <o@example.com>"], RenderPolicy::restricted())
            )
        );
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
