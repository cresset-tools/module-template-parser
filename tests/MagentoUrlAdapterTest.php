<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Magento\AssetStylesheetLoader;
use Cresset\TemplateParser\Magento\StoreUrlBuilder;
use Cresset\TemplateParser\Magento\VariableCustomVariableReader;
use Magento\Email\Model\Template\Css\Processor;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Variable\Model\Variable;
use Magento\Variable\Model\VariableFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The three adapters behind {{store}}, {{media}}, {{view}}, {{protocol}}, {{css}} and
 * {{customvar}}.
 *
 * These matter more than their size suggests. Across the whole Magento tree's shipped
 * templates, {{media}}, {{view}}, {{protocol}} and {{customvar}} appear zero times - they
 * carry merchant-authored content instead. Every image dropped into a CMS block becomes a
 * {{media url="..."}} in the database, because Cms\Helper\Wysiwyg\Images writes one. So this
 * is the code that renders the least auditable input the filter ever sees.
 */
final class MagentoUrlAdapterTest extends TestCase
{
    /** @param array<string,string> $urls */
    private function urlModel(array &$calls): UrlInterface
    {
        return new class ($calls) implements UrlInterface {
            public function __construct(private array &$calls) {}
            public function getUrl($routePath = null, $routeParams = null)
            {
                $this->calls[] = [$routePath, $routeParams];
                return 'https://shop.example/' . $routePath;
            }
        };
    }

    private function storeManager(string $mediaBase = 'https://shop.example/media/', bool $secure = false): StoreManagerInterface
    {
        return new class ($mediaBase, $secure) implements StoreManagerInterface {
            public function __construct(private string $mediaBase, private bool $secure) {}
            public function getStore($storeId = null)
            {
                return new class ($this->mediaBase, $this->secure) {
                    public function __construct(private string $mediaBase, private bool $secure) {}
                    public function getBaseUrl($type = 'link', $secure = null) { return $this->mediaBase; }
                    public function isCurrentlySecure() { return $this->secure; }
                };
            }
        };
    }

    // ------------------------------------------------------------ {{store}}

    /**
     * `_query_*` parameters are lifted into Magento's `_query` array.
     *
     * This is the one piece of real logic in the class, and the password-reset email depends
     * on it: {{store url='admin/auth/resetpassword/' _query_id=... _query_token=...}} only
     * produces a working link if both land in _query.
     */
    public function testQueryParametersAreLiftedIntoTheQueryArray(): void
    {
        $calls = [];
        $builder = new StoreUrlBuilder($this->urlModel($calls), $this->storeManager(), new Repository());

        $url = $builder->storeUrl('admin/auth/resetpassword/', [
            '_query_id' => '42',
            '_query_token' => 'abc',
            '_type' => 'web',
        ]);

        self::assertSame('https://shop.example/admin/auth/resetpassword/', $url);
        self::assertCount(1, $calls);
        [$route, $params] = $calls[0];

        self::assertSame('admin/auth/resetpassword/', $route);
        self::assertSame(['id' => '42', 'token' => 'abc'], $params['_query']);
        self::assertArrayNotHasKey('_query_id', $params, 'the _query_ prefixed key was left behind');
        self::assertArrayNotHasKey('_query_token', $params);
        self::assertSame('web', $params['_type'], 'non-query parameters must be passed through');
    }

    /** Session ids must never be baked into a URL that will sit in someone's inbox. */
    public function testStoreUrlsAreAlwaysBuiltWithoutASessionId(): void
    {
        $calls = [];
        $builder = new StoreUrlBuilder($this->urlModel($calls), $this->storeManager(), new Repository());

        $builder->storeUrl('checkout/cart', []);

        self::assertTrue($calls[0][1]['_nosid'], '_nosid must be set');
    }

    public function testAStoreUrlWithNoParametersStillGetsAnEmptyQuery(): void
    {
        $calls = [];
        $builder = new StoreUrlBuilder($this->urlModel($calls), $this->storeManager(), new Repository());

        $builder->storeUrl('checkout/cart', []);

        self::assertSame([], $calls[0][1]['_query']);
    }

    // ------------------------------------------------------------ {{media}}

    /**
     * The media URL is base + path, concatenated.
     *
     * That is legacy's shape too, and it is why PathGuard runs in the handler rather than
     * here: nothing at this layer would stop `../`. The handler-side refusal is asserted by
     * FullDirectiveSurfaceTest; this pins the concatenation it protects.
     */
    #[DataProvider('mediaPaths')]
    public function testMediaUrlsAreBasePlusPath(string $base, string $path, string $expected): void
    {
        $calls = [];
        $builder = new StoreUrlBuilder($this->urlModel($calls), $this->storeManager($base), new Repository());

        self::assertSame($expected, $builder->mediaUrl($path));
    }

    public static function mediaPaths(): array
    {
        return [
            'plain'      => ['https://shop.example/media/', 'logo.png', 'https://shop.example/media/logo.png'],
            'nested'     => ['https://shop.example/media/', 'wysiwyg/banner.jpg', 'https://shop.example/media/wysiwyg/banner.jpg'],
            'spaces'     => ['https://shop.example/media/', 'my file.png', 'https://shop.example/media/my file.png'],
        ];
    }

    // ------------------------------------------------------------ {{view}}

    public function testViewUrlsGoThroughTheAssetRepositoryWithTheirParameters(): void
    {
        $seen = [];
        $repository = new class ($seen) extends Repository {
            public function __construct(private array &$seen) {}
            public function getUrlWithParams($fileId, array $params)
            {
                $this->seen[] = [$fileId, $params];
                return 'https://shop.example/static/' . $fileId;
            }
        };
        $calls = [];
        $builder = new StoreUrlBuilder($this->urlModel($calls), $this->storeManager(), $repository);

        $url = $builder->viewUrl('images/logo.png', ['area' => 'frontend']);

        self::assertSame('https://shop.example/static/images/logo.png', $url);
        self::assertSame([['images/logo.png', ['area' => 'frontend']]], $seen);
    }

    // ------------------------------------------------------------ {{protocol}}

    #[DataProvider('secureStates')]
    public function testIsSecureReflectsTheStore(bool $secure): void
    {
        $calls = [];
        $builder = new StoreUrlBuilder($this->urlModel($calls), $this->storeManager(secure: $secure), new Repository());

        self::assertSame($secure, $builder->isSecure());
    }

    public static function secureStates(): array
    {
        return ['http' => [false], 'https' => [true]];
    }

    // ------------------------------------------------------------ {{css}}

    public function testStylesheetsAreLoadedThroughTheCssProcessor(): void
    {
        $processed = [];
        $processor = new class ($processed) extends Processor {
            public function __construct(private array &$processed) {}
            public function process($css) { $this->processed[] = $css; return 'PROCESSED:' . $css; }
        };
        $repository = new class extends Repository {
            public function createAsset($fileId, array $params = [])
            {
                return new class { public function getContent() { return 'body{color:red}'; } };
            }
        };

        $loader = new AssetStylesheetLoader($processor, $repository);

        self::assertSame('PROCESSED:body{color:red}', $loader->load('css/email.css'));
        self::assertSame(['body{color:red}'], $processed, 'the raw asset must reach the processor');
    }

    /** A missing or unreadable asset yields null rather than taking the render down. */
    public function testAnAssetThatCannotBeLoadedYieldsNull(): void
    {
        $repository = new class extends Repository {
            public function createAsset($fileId, array $params = [])
            {
                throw new \RuntimeException('no such asset');
            }
        };

        $loader = new AssetStylesheetLoader(new Processor(), $repository);

        self::assertNull($loader->load('css/missing.css'));
    }

    /** Empty CSS is null, not '', so the directive renders nothing rather than an empty tag. */
    public function testEmptyStylesheetContentYieldsNull(): void
    {
        $repository = new class extends Repository {
            public function createAsset($fileId, array $params = [])
            {
                return new class { public function getContent() { return ''; } };
            }
        };
        $processor = new class extends Processor {
            public function process($css) { return ''; }
        };

        self::assertNull((new AssetStylesheetLoader($processor, $repository))->load('css/empty.css'));
    }

    // ------------------------------------------------------------ {{customvar}}

    /**
     * The plain-text flag selects which of the two stored values is returned.
     *
     * Getting this backwards would put HTML into a text/plain email part, or escape-free
     * text where HTML was expected.
     */
    #[DataProvider('variableTypes')]
    public function testCustomVariablesSelectTheRequestedType(bool $plainText, string $expectedType, string $expected): void
    {
        $asked = [];
        $factory = new class ($asked) extends VariableFactory {
            public function __construct(private array &$asked) {}
            public function create(array $data = [])
            {
                return new class ($this->asked) extends Variable {
                    public function __construct(private array &$asked) {}
                    public function setStoreId($storeId) { $this->asked[] = ['store', $storeId]; return $this; }
                    public function loadByCode($code) { $this->asked[] = ['code', $code]; return $this; }
                    public function getValue($type = null)
                    {
                        $this->asked[] = ['type', $type];
                        return $type === Variable::TYPE_TEXT ? 'PLAIN' : '<b>HTML</b>';
                    }
                };
            }
        };

        $reader = new VariableCustomVariableReader($factory, 7);

        self::assertSame($expected, $reader->value('promo', $plainText));
        self::assertContains(['store', 7], $asked, 'the store id must be applied before loading');
        self::assertContains(['code', 'promo'], $asked);
        self::assertContains(['type', $expectedType], $asked);
    }

    public static function variableTypes(): array
    {
        return [
            'plain text' => [true, Variable::TYPE_TEXT, 'PLAIN'],
            'html'       => [false, Variable::TYPE_HTML, '<b>HTML</b>'],
        ];
    }

    /** An unknown code loads an empty Variable, which must read as absent, not as ''. */
    public function testAnUnknownCustomVariableYieldsNull(): void
    {
        $reader = new VariableCustomVariableReader(new VariableFactory());

        self::assertNull($reader->value('no_such_code', false));
    }
}
