<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\HostServices;
use MageOS\TemplateParser\Magento\Config\EngineMode;
use MageOS\TemplateParser\Magento\Plugin\AddressIdentityPlugin;
use MageOS\TemplateParser\Magento\Plugin\StoreAddressIdentityPlugin;
use MageOS\TemplateParser\Magento\Plugin\TemplateFilterPlugin;
use MageOS\TemplateParser\Magento\Shadow\ShadowOutcome;
use MageOS\TemplateParser\Magento\Shadow\ShadowRecorder;
use MageOS\TemplateParser\Magento\Shadow\TemplateIdentity;
use MageOS\TemplateParser\Magento\ShadowComparator;
use MageOS\TemplateParser\Magento\TemplateFilterAdapter;
use MageOS\TemplateParser\Options;
use Magento\Customer\Block\Address\Renderer\DefaultRenderer;
use Magento\Email\Model\Template\Filter as EmailFilter;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filter\Template as LegacyTemplate;
use Magento\Store\Model\Address\Renderer as StoreAddressRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Address formats: the plain filter FilterManager::template() builds, where customer-entered
 * values meet directive processing.
 *
 * The recorded half - tests/fixtures/legacy/address-formats.json, from
 * tools/record-address-formats.php against an unpatched Mage-OS 3.5.0 - is the stock html,
 * text, oneline and pdf formats and the store address, with directive-shaped values typed into
 * the fields a customer fills in. Every one must come out of the plain adapter byte for byte
 * as the filter rendered it, because in Parser mode that output is served.
 */
final class AddressTemplateTest extends TestCase
{
    /** @var list<array{0:?string,1:string,2:?string,3:bool,4:bool}> */
    private array $records = [];

    private int $legacyRuns = 0;

    /** @return iterable<string,array{0:string,1:array<string,mixed>,2:string}> */
    public static function recordedAddresses(): iterable
    {
        $fixture = json_decode(
            (string)file_get_contents(__DIR__ . '/fixtures/legacy/address-formats.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($fixture['cases'] as $name => $case) {
            yield $name => [$fixture['formats'][$case['format']], $case['set'] + $fixture['base'], $case['legacy']];
        }
    }

    /** @param array<string,mixed> $variables */
    #[DataProvider('recordedAddresses')]
    public function testEveryRecordedAddressRendersAsTheFilterRenderedIt(string $format, array $variables, string $legacy): void
    {
        $adapter = $this->plainAdapter();

        self::assertSame($legacy, $adapter->setVariables($variables)->filter($format));
        self::assertSame([], $adapter->violations(), 'declined a render the filter renders');
    }

    /**
     * The property this package claims, with the neutralizer that hides a failure of it
     * turned off: a value is data. Mage-OS 3.5.0 encodes `{{` in directive output, so an
     * engine that DID parse values could still match the fixture by accident; without the
     * encoding, a parsed value would put "SECRET" in the output.
     */
    public function testAValueIsNeverParsedAsTemplateSource(): void
    {
        $adapter = new TemplateFilterAdapter(
            new HostServices(),
            Options::compatible()->withOutputNeutralizer(false),
            plainFilter: true
        );
        $values = [
            '{{var secret}}',
            '{{depend secret}}{{var secret}}{{/depend}}',
            '{{if secret}}{{var secret}}{{/if}}',
            '}}{{var secret}}',
            '{{var firstname}}}}{{var secret}}{{',
        ];

        foreach ($values as $value) {
            $rendered = $adapter->setVariables(['firstname' => $value, 'secret' => 'SECRET'])
                ->filter('<b>{{var firstname}}</b>{{depend firstname}}!{{/depend}}');

            self::assertSame('<b>' . $value . '</b>!', $rendered, $value);
            self::assertStringNotContainsString('SECRET', $rendered);
        }
    }

    /**
     * What the plain filter has, and what it does not.
     *
     * Each expectation is what Mage-OS 3.5.0's FilterManager::template() returned for it, or
     * a decline where this adapter does not model the filter's behaviour.
     *
     * @return iterable<string,array{0:string,1:array<string,mixed>,2:?string}>
     */
    public static function plainSurface(): iterable
    {
        yield 'var is not escaped' => ['{{var a}}', ['a' => '<b>x</b>'], '<b>x</b>'];
        yield 'nested path' => ['[{{var a.b}}]', ['a' => ['b' => 'deep']], '[deep]'];
        yield 'unknown variable' => ['[{{var nope}}]', ['a' => '1'], '[]'];
        yield 'no variables: var renders empty' => ['[{{var nope}}]', [], '[]'];
        yield 'no variables: depend is verbatim' => ['{{depend a}}x{{/depend}}', [], '{{depend a}}x{{/depend}}'];
        yield 'if/else' => ['{{if a}}Y{{else}}N{{/if}}', ['a' => ''], 'N'];
        yield 'trans is text' => ['{{trans "Hello"}}', ['a' => '1'], '{{trans "Hello"}}'];
        yield 'store is text' => ['{{store url=""}}', ['a' => '1'], '{{store url=""}}'];
        yield 'block is text' => ['{{block id="footer"}}', ['a' => '1'], '{{block id="footer"}}'];
        yield 'inlinecss is text' => ['{{inlinecss file="x.css"}}', ['a' => '1'], '{{inlinecss file="x.css"}}'];
        yield 'unknown paired is text' => ['{{unknown x}}b{{/unknown}}', ['a' => '1'], '{{unknown x}}b{{/unknown}}'];
        yield 'a modifier is declined' => ['{{var a|escape}}', ['a' => '<b>'], null];
        yield 'an empty var is declined' => ['[{{var}}]', ['a' => '1'], null];
        yield 'template is declined' => ['{{template config_path="design/email/header_template"}}', ['a' => '1'], null];
        yield 'for is declined' => ['{{for x in l}}<{{var x}}>{{/for}}', ['l' => ['p']], null];
        // The resolver hands back nothing for an object, even one with __toString.
        yield 'an object renders empty' => ['[{{var a}}]', ['a' => new \stdClass()], '[]'];
        yield 'a stringable object renders empty' => [
            '[{{var a}}]',
            ['a' => new class { public function __toString(): string { return 'TS'; } }],
            '[]',
        ];
    }

    /** @param array<string,mixed> $variables */
    #[DataProvider('plainSurface')]
    public function testThePlainFiltersSurface(string $source, array $variables, ?string $expected): void
    {
        $adapter = $this->plainAdapter();
        $rendered = $adapter->setVariables($variables)->filter($source);

        if ($expected === null) {
            self::assertNotSame([], $adapter->violations(), 'served a construct it does not model');

            return;
        }
        self::assertSame($expected, $rendered);
        self::assertSame([], $adapter->violations());
    }

    /** The Email filter's adapter is untouched by any of it: {{var}} there still escapes. */
    public function testTheEmailProfileStillEscapes(): void
    {
        $adapter = new TemplateFilterAdapter(new HostServices());

        self::assertSame('&lt;b&gt;', $adapter->setVariables(['a' => '<b>'])->filter('{{var a}}'));
    }

    // -------------------------------------------------------------------------- the plugin

    public function testShadowRecordsAnAddressUnderTheNameItsRendererHeld(): void
    {
        $identity = new TemplateIdentity();
        $plugin = $this->plugin(EngineMode::SHADOW, $identity);
        $subject = new LegacyTemplate();
        $plugin->beforeSetVariables($subject, ['firstname' => 'Jan']);

        $served = $identity->during('address:html', fn () => $this->filter($plugin, $subject, '{{var firstname}}', 'Jan'));

        self::assertSame('Jan', $served);
        self::assertSame([['agree', 'address:html', false, false]], $this->recorded());
    }

    public function testParserServesThePlainRenderWithoutRunningTheFilter(): void
    {
        $plugin = $this->plugin(EngineMode::PARSER, new TemplateIdentity());
        $subject = new LegacyTemplate();
        $plugin->beforeSetVariables($subject, ['street1' => 'Main & 1']);

        self::assertSame('<p>Main & 1</p>', $this->filter($plugin, $subject, '<p>{{var street1}}</p>', 'legacy'));
        self::assertSame(0, $this->legacyRuns);
    }

    public function testParserFallsBackForWhatThePlainAdapterDeclines(): void
    {
        $plugin = $this->plugin(EngineMode::PARSER, new TemplateIdentity());
        $subject = new LegacyTemplate();
        $plugin->beforeSetVariables($subject, ['a' => 'x']);

        self::assertSame('legacy', $this->filter($plugin, $subject, '{{var a|escape}}', 'legacy'));
        self::assertSame(1, $this->legacyRuns);
        self::assertSame('refused', $this->recorded()[0][0]);
    }

    /**
     * Any other subclass of the framework filter adds directives neither adapter models -
     * Catalog's, a module's - so the plugin hands it to the filter in every stage, and records
     * nothing for it.
     */
    public function testAnyOtherSubclassIsLeftToTheFilter(): void
    {
        $plugin = $this->plugin(EngineMode::PARSER, new TemplateIdentity());
        $subject = new class extends LegacyTemplate {};
        $plugin->beforeSetVariables($subject, ['a' => 'x']);

        self::assertSame('legacy', $this->filter($plugin, $subject, '{{var a}}', 'legacy'));
        self::assertSame(1, $this->legacyRuns);
        self::assertSame([], $this->records);
    }

    /** Without the plain adapter wired, the plain filter is left alone, as it was before. */
    public function testThePlainFilterIsLeftAloneWithoutItsAdapter(): void
    {
        $plugin = $this->plugin(EngineMode::PARSER, new TemplateIdentity(), wirePlain: false);

        self::assertSame('legacy', $this->filter($plugin, new LegacyTemplate(), '{{var a}}', 'legacy'));
        self::assertSame([], $this->records);
    }

    /** The name an address renderer holds is for the plain filter; an email inside one keeps its own. */
    public function testAnEmailRenderedInsideAnAddressCallDoesNotBorrowItsName(): void
    {
        $identity = new TemplateIdentity();
        $plugin = $this->plugin(EngineMode::SHADOW, $identity);
        $email = new EmailFilter();

        $identity->during('address:html', fn () => $this->filter($plugin, $email, 'text', 'text'));

        self::assertSame('unidentified:' . EmailFilter::class, $this->recorded()[0][1]);
    }

    public function testTheAddressRendererHoldsItsFormatCodeForTheCall(): void
    {
        $identity = new TemplateIdentity();
        // Shaped like the DataObject the address config hands over: getCode() through __call.
        $renderer = new DefaultRenderer(new class {
            public function __call(string $method, array $arguments): mixed
            {
                return $method === 'getCode' ? 'oneline' : null;
            }
        });

        $seen = (new AddressIdentityPlugin($identity))->aroundRenderArray(
            $renderer,
            static fn () => $identity->ambient(),
            ['firstname' => 'Jan']
        );

        self::assertSame('address:oneline', $seen);
        self::assertNull($identity->ambient(), 'the name outlived the call');
    }

    public function testTheNameIsReleasedWhenTheRenderRaises(): void
    {
        $identity = new TemplateIdentity();

        try {
            (new StoreAddressIdentityPlugin($identity))->aroundFormat(
                new StoreAddressRenderer(),
                static fn () => throw new \RuntimeException('render failed'),
                new \stdClass()
            );
            self::fail('the exception was swallowed');
        } catch (\RuntimeException) {
        }

        self::assertNull($identity->ambient());
    }

    public function testTheStoreAddressIsNamed(): void
    {
        $identity = new TemplateIdentity();

        $seen = (new StoreAddressIdentityPlugin($identity))->aroundFormat(
            new StoreAddressRenderer(),
            static fn () => $identity->ambient(),
            new \stdClass()
        );

        self::assertSame('store_address', $seen);
    }

    // -------------------------------------------------------------------------- helpers

    private function plainAdapter(): TemplateFilterAdapter
    {
        return new TemplateFilterAdapter(new HostServices(), plainFilter: true);
    }

    private function plugin(string $stage, TemplateIdentity $identity, bool $wirePlain = true): TemplateFilterPlugin
    {
        $mode = new EngineMode(new class ($stage) implements ScopeConfigInterface {
            public function __construct(private string $stage) {}
            public function getValue($path, $scope = 'default', $scopeCode = null)
            {
                return $path === EngineMode::XML_PATH_PARSER_SHADOW_RATE ? '0' : $this->stage;
            }
            public function isSetFlag($path, $scope = 'default', $scopeCode = null) { return false; }
        });
        $recorder = new class ($this->records) extends ShadowRecorder {
            public function __construct(private array &$records) {}
            public function record(
                int|string|null $storeId,
                string $template,
                ?ShadowOutcome $outcome,
                bool $served = false,
                bool $fellBack = false
            ): void {
                $this->records[] = [$outcome?->outcome, $template, $outcome?->detail, $served, $fellBack];
            }
        };

        return new TemplateFilterPlugin(
            new ShadowComparator(new TemplateFilterAdapter(new HostServices())),
            $mode,
            $identity,
            $recorder,
            null,
            $wirePlain ? new ShadowComparator($this->plainAdapter()) : null
        );
    }

    /** @return list<array{0:?string,1:string,2:bool,3:bool}> outcome, template, served, fell back */
    private function recorded(): array
    {
        return array_map(static fn (array $r): array => [$r[0], $r[1], $r[3], $r[4]], $this->records);
    }

    private function filter(TemplateFilterPlugin $plugin, LegacyTemplate $subject, string $source, string $legacy): mixed
    {
        return $plugin->aroundFilter($subject, function () use ($legacy): string {
            $this->legacyRuns++;

            return $legacy;
        }, $source);
    }
}
