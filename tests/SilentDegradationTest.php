<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\HostServices;
use Magento\Framework\Interception\DefinitionInterface;
use MageOS\TemplateParser\Magento\Config\EngineMode;
use MageOS\TemplateParser\Magento\Plugin\TemplateFilterPlugin;
use MageOS\TemplateParser\Magento\Shadow\ShadowOutcome;
use MageOS\TemplateParser\Magento\Shadow\ShadowRecorder;
use MageOS\TemplateParser\Magento\Shadow\TemplateIdentity;
use MageOS\TemplateParser\Magento\ShadowComparator;
use MageOS\TemplateParser\Magento\TemplateFilterAdapter;
use MageOS\TemplateParser\Options;
use MageOS\TemplateParser\Port\BlockRenderer;
use MageOS\TemplateParser\Port\ConfigReader;
use MageOS\TemplateParser\Port\CustomDirectiveRenderer;
use MageOS\TemplateParser\Port\CustomVariableReader;
use MageOS\TemplateParser\Port\LayoutRenderer;
use MageOS\TemplateParser\Port\RefusedByPort;
use MageOS\TemplateParser\Port\StylesheetLoader;
use MageOS\TemplateParser\Port\TemplateLoader;
use MageOS\TemplateParser\Port\TemplateUrlBuilder;
use MageOS\TemplateParser\Port\UrlBuilder;
use MageOS\TemplateParser\Port\WidgetRenderer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filter\Template as LegacyTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every place this engine renders less than the legacy filter would, made visible.
 *
 * A guard stricter than Mage-OS 3.5.0's filter, a port that cannot answer, a directive this
 * engine does not model: each used to render '' or its own text, and a render that looks
 * complete is one Parser mode serves. Each is now recorded as a policy violation, which
 * declines the render - Shadow records a refusal with the reason, and Parser hands the render
 * to the filter.
 *
 * And the places that STAY silent are pinned too, because there legacy renders nothing
 * either, and declining would only cost a second render.
 */
final class SilentDegradationTest extends TestCase
{
    // ---------------------------------------------------------------- guards in the handlers

    /** @return array<string,array{0:string,1:string,2:string}> template, violation kind, name */
    public static function strictGuards(): array
    {
        return [
            // blockDirective builds Magento\Cms\Block\Block for an id; this engine cannot.
            'block by id' => ['{{block id="footer_links"}}', 'block id', 'footer_links'],
            // TemplateDirective hands any config path to the template processor.
            'template, unguarded path' => ['{{template config_path="design/email/../header"}}', 'template config path', 'design/email/../header'],
            // customvarDirective looks up any code.
            'customvar, unguarded code' => ['{{customvar code="a/../b"}}', 'custom variable code', 'a/../b'],
            // storeDirective builds a URL from anything.
            'store, unguarded url' => ['{{store url="../admin"}}', 'store url', '../admin'],
            'store, unguarded parameter' => ['{{store url="a" _direct="//evil.example"}}', 'store url parameters', 'a'],
            // mediaDirective concatenates anything onto the media base URL.
            'media, unguarded url' => ['{{media url="javascript:alert(1)"}}', 'media url', 'javascript:alert(1)'],
            // viewDirective hands anything to the asset repository.
            'view, unguarded url' => ['{{view url="../../app/etc/env.php"}}', 'view url', '../../app/etc/env.php'],
            // protocolDirective is `$protocol . '://' . $url`, unchecked.
            'protocol, unguarded url' => ['{{protocol url="a b"}}', 'protocol url', 'a b'],
            // ... and throws a MailException for an invalid pair, which its catch renders.
            'protocol, invalid pair' => ['{{protocol http="ftp://a" https="https://b"}}', 'protocol url', 'ftp://a https://b'],
            // cssDirective hands any file to the asset repository.
            'css, unguarded file' => ['{{css file="../../app/etc/env.php"}}', 'stylesheet', '../../app/etc/env.php'],
            // layoutDirective emulates any area other than adminhtml...
            'layout, other area' => ['{{layout handle="h" area="crontab"}}', 'layout area', 'crontab'],
            // ... and loads any handle.
            'layout, unguarded handle' => ['{{layout handle="a b"}}', 'layout handle', 'a b'],
            // generateWidget loads a preconfigured widget instance by id; this engine cannot.
            'widget by id' => ['{{widget id="3"}}', 'widget id', '3'],
        ];
    }

    #[DataProvider('strictGuards')]
    public function testAGuardStricterThanTheFilterIsRecorded(string $template, string $kind, string $name): void
    {
        $adapter = $this->adapter($this->everyPort());

        $adapter->setVariables(['x' => 1])->filter($template);

        self::assertSame(
            [[$kind, $name]],
            array_map(static fn ($v) => [$v->kind, $v->name], $adapter->violations()),
            'rendered less than the filter would, without saying so'
        );
    }

    /** @return array<string,array{0:string,1:string}> template, what renders */
    public static function guardsLegacyShares(): array
    {
        return [
            // Mage-OS 3.5.0 refuses an adminhtml layout handle outright, case-insensitively.
            'layout, adminhtml area' => ['[{{layout handle="sales_email_order_items" area=" AdminHTML "}}]', '[]'],
            // configDirective renders only paths on Magento's config-variable list.
            'config, unguarded path' => ['[{{config path="a/../b"}}]', '[]'],
            // generateWidget renders nothing for a type no widget.xml declares.
            'widget, not an identifier' => ['[{{widget type="a b"}}]', '[]'],
            // Neither class nor id: blockDirective's own ''.
            'block, neither class nor id' => ['[{{block}}]', '[]'],
        ];
    }

    #[DataProvider('guardsLegacyShares')]
    public function testAGuardLegacySharesStaysSilent(string $template, string $expected): void
    {
        $adapter = $this->adapter($this->everyPort());

        self::assertSame($expected, $adapter->setVariables(['x' => 1])->filter($template));
        self::assertSame([], $adapter->violations(), 'declined where the filter renders the same nothing');
    }

    /** The adminhtml handle is not even asked for, as legacy never loads it. */
    public function testAnAdminhtmlHandleNeverReachesThePort(): void
    {
        $asked = [];
        $adapter = $this->adapter(new HostServices(layouts: new class ($asked) implements LayoutRenderer {
            public function __construct(private array &$asked) {}
            public function render(string $handle, string $area, array $parameters): string
            {
                $this->asked[] = [$handle, $area];
                return 'ITEMS';
            }
        }));

        $adapter->setVariables(['x' => 1])->filter('{{layout handle="sales_email_order_items" area="adminhtml"}}');

        self::assertSame([], $asked);
    }

    // ---------------------------------------------------------------- ports that refuse

    /** @return array<string,array{0:string,1:string}> port, template that reaches it */
    public static function refusingPorts(): array
    {
        return [
            'blocks' => ['blocks', '{{block class="Magento\\Cms\\Block\\Block" output="getTitle"}}'],
            'templates' => ['templates', '{{template config_path="vendor/email/header"}}'],
            'config' => ['config', '{{config path="general/store_information/country_id"}}'],
            'stylesheets' => ['stylesheets', '{{css file="css/email.css"}}'],
            'layouts' => ['layouts', '{{layout handle="vendor_email_items"}}'],
            'widgets' => ['widgets', '{{widget type="Vendor\\Widget"}}'],
            'custom directives' => ['customDirectives', '{{mydir value}}'],
            'template urls' => ['templateUrls', '{{var this.getUrl($store, "a b")}}'],
        ];
    }

    #[DataProvider('refusingPorts')]
    public function testAPortThatRefusesIsRecorded(string $port, string $template): void
    {
        $refusing = $this->everyPort(refusing: $port);
        $adapter = $this->adapter($refusing);

        $adapter->setVariables(['this' => new class extends \Magento\Email\Model\AbstractTemplate {}, 'store' => new \Magento\Store\Model\Store()])
            ->filter($template);

        self::assertSame(
            [['refused by ' . $port, 'X']],
            array_map(static fn ($v) => [$v->kind, $v->name], $adapter->violations())
        );
    }

    // ---------------------------------------------------------------- unwired ports

    /** A directive whose port the host left out renders as text, where the filter renders it. */
    public function testADirectiveWithNoPortIsRecorded(): void
    {
        $adapter = $this->adapter(new HostServices());

        $out = $adapter->setVariables(['x' => 1])->filter("Hi\n{{STORE url=\"a\"}} {{stores}}");

        self::assertSame("Hi\n{{STORE url=\"a\"}} {{stores}}", $out, 'unchanged: still its own text');
        self::assertSame(
            ['policy refused unwired directive "store" (line 2, column 1)'],
            array_map(static fn ($v) => $v->describe(), $adapter->violations())
        );
    }

    public function testAFullyWiredAdapterRecordsNothingForThem(): void
    {
        $adapter = $this->adapter($this->everyPort());

        $adapter->setVariables(['x' => 1])->filter('{{store url="a"}}{{media url="b"}}{{trans "c"}}');

        self::assertSame([], $adapter->violations());
    }

    // ---------------------------------------------------------------- directives only the subject renders

    /** @return array<string,array{0:class-string,1:string,2:string}> */
    public static function subjectsOnly(): array
    {
        require_once __DIR__ . '/fixtures/LegacyOnlyDirectives.php';

        return [
            "a module's own directive" => [\Acme\Promo\Filter::class, 'Use {{COUPON}} today', 'coupon'],
            'a plugged stock directive' => [\Magento\Widget\Model\Template\Filter\Interceptor::class, 'Dear {{var name}}', 'var'],
            "the CMS filter's filesystem {{media}}" => [\Magento\Cms\Model\Template\Filter::class, '{{media url="wysiwyg/a.png"}}', 'media'],
            "a module's protected directive" => [\Acme\Promo\HiddenFilter::class, 'Use {{coupon}} today', 'coupon'],
            "a module's private directive" => [\Acme\Promo\HiddenFilter::class, 'A {{gift}}', 'gift'],
            "a module's static directive" => [\Acme\Promo\HiddenFilter::class, 'A {{promo}}', 'promo'],
            // No widgetDirective() on the transactional Email filter: legacy prints the text.
            '{{widget}} on the Email filter' => [\Magento\Email\Model\Template\Filter::class, '{{widget type="Magento\\CatalogWidget\\Block\\Product\\ProductsList"}}', 'widget'],
            'a name the store reads as a shorter one' => [\Acme\Promo\Filter::class, 'Use {{coupon2 code="x"}}', 'coupon'],
        ];
    }

    #[DataProvider('subjectsOnly')]
    public function testADirectiveOnlyTheSubjectRendersFallsBackInParser(string $class, string $template, string $name): void
    {
        $records = [];
        $plugin = $this->plugin($records, EngineMode::PARSER);
        $subject = new $class();
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        $result = $plugin->aroundFilter($subject, static fn () => 'LEGACY', $template);

        self::assertSame('LEGACY', $result);
        self::assertSame(ShadowOutcome::REFUSED, $records[0][0]);
        self::assertStringContainsString(sprintf('directive "%s"', $name), $records[0][1]['problem']);
        self::assertTrue($records[0][3], 'not recorded as a fallback');
    }

    #[DataProvider('subjectsOnly')]
    public function testShadowRecordsTheSameRefusal(string $class, string $template, string $name): void
    {
        $records = [];
        $plugin = $this->plugin($records, EngineMode::SHADOW);
        $subject = new $class();
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        self::assertSame('LEGACY', $plugin->aroundFilter($subject, static fn () => 'LEGACY', $template));
        self::assertSame(ShadowOutcome::REFUSED, $records[0][0]);
    }

    /** The Widget filter restores the URL {{media}}, so the storefront's CMS renders here. */
    public function testTheWidgetFiltersMediaIsOurs(): void
    {
        require_once __DIR__ . '/fixtures/LegacyOnlyDirectives.php';
        $records = [];
        $plugin = $this->plugin($records, EngineMode::PARSER);
        $subject = new \Magento\Widget\Model\Template\Filter();
        $plugin->beforeSetVariables($subject, ['x' => 1]);

        $plugin->aroundFilter($subject, static fn () => 'LEGACY', 'plain text');
        $plugin->aroundFilter($subject, static fn () => 'LEGACY', '{{coupon}} is not a directive here');

        self::assertSame([true, true], [$records[0][2], $records[1][2]], 'declined a render it can serve');
    }

    /**
     * Developer mode, or default mode without setup:di:compile: the generated Interceptor
     * redeclares every public method, so the declaration says nothing about plugins. Classing
     * them all as plugged declined every render with a directive in it - Shadow recorded
     * refusals, which count as clean, and the report said "No divergences" over nothing.
     */
    public function testAnOnDemandInterceptorWithNothingPluggedIsServedInParser(): void
    {
        require_once __DIR__ . '/fixtures/LegacyOnlyDirectives.php';
        $records = [];
        $plugin = $this->plugin($records, EngineMode::PARSER);
        $subject = new \Magento\Email\Model\Template\Filter\Interceptor();
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        $result = $plugin->aroundFilter($subject, static fn () => 'LEGACY', 'Dear {{var name}}{{if name}}!{{/if}}');

        self::assertSame('Dear Ada!', $result);
        self::assertSame([null, true, false], [$records[0][0], $records[0][2], $records[0][3]]);
    }

    public function testAnOnDemandInterceptorWithNothingPluggedIsComparedInShadow(): void
    {
        require_once __DIR__ . '/fixtures/LegacyOnlyDirectives.php';
        $records = [];
        $plugin = $this->plugin($records, EngineMode::SHADOW);
        $subject = new \Magento\Email\Model\Template\Filter\Interceptor();
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        $plugin->aroundFilter($subject, static fn () => 'Dear Ada', 'Dear {{var name}}');

        self::assertSame(ShadowOutcome::AGREE, $records[0][0]);
    }

    /** The same shape, with a plugin really configured on one directive: that one is the filter's. */
    public function testAnOnDemandInterceptorDeclinesOnlyThePluggedDirective(): void
    {
        require_once __DIR__ . '/fixtures/LegacyOnlyDirectives.php';
        $records = [];
        $plugin = $this->plugin($records, EngineMode::PARSER);
        $subject = new \Magento\Email\Model\Template\Filter\Interceptor(['cssDirective']);
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        self::assertSame('LEGACY', $plugin->aroundFilter($subject, static fn () => 'LEGACY', '{{css file="a.css"}}'));
        self::assertStringContainsString('directive "css"', $records[0][1]['problem']);
        self::assertSame('Ada', $plugin->aroundFilter($subject, static fn () => 'LEGACY', '{{var name}}'));
    }

    /** Over a module's subclass, its own directive is still its own however the Interceptor was made. */
    public function testAnOnDemandInterceptorKeepsAModulesOwnDirective(): void
    {
        require_once __DIR__ . '/fixtures/LegacyOnlyDirectives.php';
        $records = [];
        $plugin = $this->plugin($records, EngineMode::PARSER);
        $subject = new \Acme\Promo\Filter\Interceptor();
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        self::assertSame('LEGACY', $plugin->aroundFilter($subject, static fn () => 'LEGACY', 'Use {{coupon}}'));
        self::assertSame('Ada', $plugin->aroundFilter($subject, static fn () => 'LEGACY', '{{var name}}'));
    }

    // ---------------------------------------------------------------- other plugins on filter()

    /**
     * An Email filter Interceptor whose plugin list puts `$chain` on filter(), with this
     * plugin as the around named `mageos_template_parser`.
     *
     * @param array<string,array<string,mixed>> $chain code => what getNext('filter', code) answers
     * @param array<string,object> $plugins code => the other plugins
     * @return array{0:object,1:\Closure(TemplateFilterPlugin):void} the subject, and how to put the plugin in its list
     */
    private function chained(array $chain, array $plugins): array
    {
        require_once __DIR__ . '/fixtures/LegacyOnlyDirectives.php';
        $list = new class ($chain, $plugins) implements \Magento\Framework\Interception\PluginListInterface {
            public ?object $self = null;
            public function __construct(private array $chain, private array $plugins) {}
            public function getPlugin($type, $code) { return $code === 'mageos_template_parser' ? $this->self : $this->plugins[$code]; }
            public function getNext($type, $method, $code = '__self')
            {
                return $method === 'filter' ? ($this->chain[$code] ?? null) : null;
            }
        };

        return [
            new \Magento\Email\Model\Template\Filter\Interceptor([], $list),
            static function (TemplateFilterPlugin $plugin) use ($list): void { $list->self = $plugin; },
        ];
    }

    /** Page Builder's shape: an afterFilter that rewrites the finished render. */
    private static function afterPlugin(): object
    {
        return new class {
            public function afterFilter($subject, $result, $value) { return 'DECODED(' . $result . ')'; }
        };
    }

    private static function beforePlugin(): object
    {
        return new class {
            public function beforeFilter($subject, $value) { return [str_replace('Dear', 'Hi', $value)]; }
        };
    }

    /**
     * A plugin sorted after this one runs only inside `$proceed` - which Parser does not call.
     * Magento's sort puts Page Builder's afterFilter, which has no sortOrder, on either side of
     * this one by module load order; inside, Parser served its HTML Code elements undecoded.
     */
    public function testPluginsInsideThisOneRunOnAParserRender(): void
    {
        [$subject, $wire] = $this->chained(
            ['__self' => [DefinitionInterface::LISTENER_AROUND => 'mageos_template_parser'], 'mageos_template_parser' => [DefinitionInterface::LISTENER_BEFORE => ['b'], DefinitionInterface::LISTENER_AFTER => ['pb']]],
            ['b' => self::beforePlugin(), 'pb' => self::afterPlugin()]
        );
        $records = [];
        $wire($plugin = $this->plugin($records, EngineMode::PARSER));
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        self::assertSame('DECODED(Hi Ada)', $plugin->aroundFilter($subject, static fn () => 'LEGACY', 'Dear {{var name}}'));
        self::assertSame([null, true, false], [$records[0][0], $records[0][2], $records[0][3]]);
    }

    /** Shadow compares legacy's render, which they ran on, with ours after the same plugins. */
    public function testShadowComparesAfterThePluginsInsideThisOne(): void
    {
        [$subject, $wire] = $this->chained(
            ['__self' => [DefinitionInterface::LISTENER_AROUND => 'mageos_template_parser'], 'mageos_template_parser' => [DefinitionInterface::LISTENER_BEFORE => ['b'], DefinitionInterface::LISTENER_AFTER => ['pb']]],
            ['b' => self::beforePlugin(), 'pb' => self::afterPlugin()]
        );
        $records = [];
        $wire($plugin = $this->plugin($records, EngineMode::SHADOW));
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        self::assertSame('DECODED(Hi Ada)', $plugin->aroundFilter($subject, static fn () => 'DECODED(Hi Ada)', 'Dear {{var name}}'));
        self::assertSame(ShadowOutcome::AGREE, $records[0][0]);
    }

    /**
     * A legacy render that still holds a `:deferred:` placeholder was not resolved as a root.
     * The shared depth meter leaks a level on every render that raises, so in a long-running
     * process every later render is taken for a child - Magento's output, not this engine's
     * difference, and not compared.
     */
    public function testShadowDoesNotCompareALegacyRenderThatLeftADeferral(): void
    {
        [$subject, $wire] = $this->chained([], []);
        $records = [];
        $wire($plugin = $this->plugin($records, EngineMode::SHADOW));
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);
        $unresolved = 'Dear Ada :deferred:' . str_repeat('ab', 32) . ':';

        self::assertSame($unresolved, $plugin->aroundFilter($subject, static fn () => $unresolved, 'Dear {{var name}}'));
        self::assertSame([], $records);

        self::assertSame('Dear Bob', $plugin->aroundFilter($subject, static fn () => 'Dear Bob', 'Dear {{var name}}'));
        self::assertSame(ShadowOutcome::DIVERGE, $records[0][0]);
    }

    public function testASampledParserRenderDoesNotCompareALegacyRenderThatLeftADeferral(): void
    {
        [$subject, $wire] = $this->chained([], []);
        $records = [];
        $wire($plugin = $this->plugin($records, EngineMode::PARSER, shadowRate: '100'));
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);
        $unresolved = 'Dear Ada :deferred:' . str_repeat('ab', 32) . ':';

        self::assertSame('Dear Ada', $plugin->aroundFilter($subject, static fn () => $unresolved, 'Dear {{var name}}'));
        self::assertSame([[null, null, true, false]], $records);
    }

    /** One outside this plugin is the Interceptor's to run, on whatever this returns: not run twice. */
    public function testPluginsOutsideThisOneAreLeftToTheInterceptor(): void
    {
        [$subject, $wire] = $this->chained(
            ['__self' => [DefinitionInterface::LISTENER_AFTER => ['pb'], DefinitionInterface::LISTENER_AROUND => 'mageos_template_parser']],
            ['pb' => self::afterPlugin()]
        );
        $records = [];
        $wire($plugin = $this->plugin($records, EngineMode::PARSER));
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        self::assertSame('Dear Ada', $plugin->aroundFilter($subject, static fn () => 'LEGACY', 'Dear {{var name}}'));
    }

    /** An around plugin inside wraps only the filter's own body: the filter renders. */
    public function testAnAroundPluginInsideThisOneLeavesTheRenderToTheFilter(): void
    {
        [$subject, $wire] = $this->chained(
            ['__self' => [DefinitionInterface::LISTENER_AROUND => 'mageos_template_parser'], 'mageos_template_parser' => [DefinitionInterface::LISTENER_AROUND => 'wrapper']],
            []
        );
        $records = [];
        $wire($plugin = $this->plugin($records, EngineMode::PARSER));
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        self::assertSame('LEGACY', $plugin->aroundFilter($subject, static fn () => 'LEGACY', 'Dear {{var name}}'));
        self::assertStringContainsString('around plugin "wrapper"', $records[0][1]['problem']);
    }

    /**
     * template:diff's candidate is served where a Parser render would be, so it gets the
     * same plugins - once, and on that filter only: a render nested inside is legacy's.
     */
    public function testTheConsolesCandidateIsServedThroughThePluginsInsideThisOne(): void
    {
        [$subject, $wire] = $this->chained(
            ['__self' => [DefinitionInterface::LISTENER_AROUND => 'mageos_template_parser'], 'mageos_template_parser' => [DefinitionInterface::LISTENER_AFTER => ['pb']]],
            ['pb' => self::afterPlugin()]
        );
        $records = [];
        $candidates = new \MageOS\TemplateParser\Magento\CandidateRenders();
        $wire($plugin = $this->plugin($records, EngineMode::LEGACY, null, $candidates));

        $nested = null;
        [$result, $used] = $candidates->run(
            $subject,
            function (string $value) use ($plugin, $subject, &$nested): string {
                $nested = $plugin->aroundFilter($subject, static fn () => 'LEGACY', 'inner');
                return 'OURS:' . $value;
            },
            static fn () => $plugin->aroundFilter($subject, static fn () => 'LEGACY', 'X')
        );

        self::assertSame(['DECODED(OURS:X)', true], [$result, $used]);
        self::assertSame('LEGACY', $nested);
        self::assertSame([], $records);
        self::assertNull($candidates->take($subject));
    }

    public function testTheConsoleIsToldWhenThePluginNeverSawItsCandidate(): void
    {
        $candidates = new \MageOS\TemplateParser\Magento\CandidateRenders();
        $subject = new \Magento\Email\Model\Template\Filter();

        self::assertSame(['LEGACY', false], $candidates->run($subject, static fn (string $v): string => 'OURS', static fn () => 'LEGACY'));
    }

    /**
     * template:diff renders "today" through the store's filter, which carries this plugin: on
     * a Parser store view "today" was this engine, compared with itself, and in Shadow every
     * CLI render went into the live table. Inside LegacyOnlyRenders the plugin stands aside.
     */
    #[DataProvider('stages')]
    public function testARenderMarkedLegacyOnlyIsTheFiltersAndIsNotRecorded(string $stage): void
    {
        $records = [];
        $legacyOnly = new \MageOS\TemplateParser\Magento\LegacyOnlyRenders();
        $plugin = $this->plugin($records, $stage, $legacyOnly);
        $subject = new \Magento\Email\Model\Template\Filter();
        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);

        $result = $legacyOnly->run(static fn () => $plugin->aroundFilter($subject, static fn () => 'LEGACY', 'Dear {{var name}}'));

        self::assertSame('LEGACY', $result);
        self::assertSame([], $records);

        // The control: outside it, the same render is the plugin's again, and recorded.
        $plugin->aroundFilter($subject, static fn () => 'LEGACY', 'Dear {{var name}}');
        self::assertCount(1, $records);
    }

    public static function stages(): array
    {
        return ['shadow' => [EngineMode::SHADOW], 'parser' => [EngineMode::PARSER]];
    }

    // ---------------------------------------------------------------- helpers

    private function adapter(HostServices $services): TemplateFilterAdapter
    {
        return new TemplateFilterAdapter($services, Options::compatible());
    }

    /** Every port, answering - or, for `$refusing`, refusing as `refused by <port>` "X". */
    private function everyPort(?string $refusing = null): HostServices
    {
        $refuse = static function (string $port) use ($refusing): void {
            if ($port === $refusing) {
                throw new RefusedByPort('refused by ' . $port, 'X');
            }
        };

        return new HostServices(
            blocks: new class ($refuse) implements BlockRenderer {
                public function __construct(private \Closure $refuse) {}
                public function render(string $class, array $data, string $method): string { ($this->refuse)('blocks'); return 'BLOCK'; }
            },
            translator: new class implements \MageOS\TemplateParser\Port\Translator {
                public function translate(string $text, array $arguments): string { return $text; }
            },
            templates: new class ($refuse) implements TemplateLoader {
                public function __construct(private \Closure $refuse) {}
                public function load(string $configPath): ?string { ($this->refuse)('templates'); return 'INCLUDED'; }
            },
            config: new class ($refuse) implements ConfigReader {
                public function __construct(private \Closure $refuse) {}
                public function value(string $path): ?string { ($this->refuse)('config'); return null; }
            },
            customVariables: new class implements CustomVariableReader {
                public function value(string $code, bool $plainText): ?string { return 'VAR'; }
            },
            urls: new class implements UrlBuilder {
                public function storeUrl(string $path, array $parameters): string { return 'https://shop.example/' . $path; }
                public function mediaUrl(string $path): string { return 'https://shop.example/media/' . $path; }
                public function viewUrl(string $path, array $parameters): string { return 'https://shop.example/static/' . $path; }
                public function isSecure(): bool { return true; }
            },
            stylesheets: new class ($refuse) implements StylesheetLoader {
                public function __construct(private \Closure $refuse) {}
                public function load(string $file, array $designParams = []): ?string { ($this->refuse)('stylesheets'); return 'CSS'; }
            },
            layouts: new class ($refuse) implements LayoutRenderer {
                public function __construct(private \Closure $refuse) {}
                public function render(string $handle, string $area, array $parameters): string { ($this->refuse)('layouts'); return 'ITEMS'; }
            },
            widgets: new class ($refuse) implements WidgetRenderer {
                public function __construct(private \Closure $refuse) {}
                public function render(string $type, array $parameters): string { ($this->refuse)('widgets'); return 'WIDGET'; }
            },
            templateUrls: new class ($refuse) implements TemplateUrlBuilder {
                public function __construct(private \Closure $refuse) {}
                public function urlFor(object $target, array $arguments): ?string
                {
                    if ($arguments[1] === 'a b') {
                        ($this->refuse)('templateUrls');
                    }
                    return 'https://shop.example/u';
                }
            },
            customDirectives: new class ($refuse) implements CustomDirectiveRenderer {
                public function __construct(private \Closure $refuse) {}
                public function names(): array { return ['mydir']; }
                public function render(string $name, ?string $value, array $parameters, ?string $body, array $modifiers): ?string
                {
                    ($this->refuse)('customDirectives');
                    return 'MINE';
                }
            },
        );
    }

    /** @param list<array{0:?string,1:?array,2:bool,3:bool}> $records outcome, detail, served, fell back */
    private function plugin(
        array &$records,
        string $stage,
        ?\MageOS\TemplateParser\Magento\LegacyOnlyRenders $legacyOnly = null,
        ?\MageOS\TemplateParser\Magento\CandidateRenders $candidates = null,
        string $shadowRate = '0'
    ): TemplateFilterPlugin {
        $recorder = new class ($records) extends ShadowRecorder {
            public function __construct(private array &$records) {}
            public function record(int|string|null $storeId, string $template, ?ShadowOutcome $outcome, bool $served = false, bool $fellBack = false): void
            {
                $this->records[] = [$outcome?->outcome, $outcome?->detail, $served, $fellBack];
            }
        };
        $mode = new EngineMode(new class ($stage, $shadowRate) implements ScopeConfigInterface {
            public function __construct(private string $stage, private string $rate) {}
            public function getValue($path, $scope = 'default', $scopeCode = null)
            {
                return match ($path) {
                    EngineMode::XML_PATH => $this->stage,
                    EngineMode::XML_PATH_PARSER_SHADOW_RATE => $this->rate,
                    default => '0',
                };
            }
            public function isSetFlag($path, $scope = 'default', $scopeCode = null) { return false; }
        });

        return new TemplateFilterPlugin(
            new ShadowComparator($this->adapter($this->everyPort())),
            $mode,
            new TemplateIdentity(),
            $recorder,
            legacyOnlyRenders: $legacyOnly,
            candidateRenders: $candidates
        );
    }
}
