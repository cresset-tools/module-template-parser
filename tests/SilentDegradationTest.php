<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\Magento\Config\EngineMode;
use Cresset\TemplateParser\Magento\Plugin\TemplateFilterPlugin;
use Cresset\TemplateParser\Magento\Shadow\ShadowOutcome;
use Cresset\TemplateParser\Magento\Shadow\ShadowRecorder;
use Cresset\TemplateParser\Magento\Shadow\TemplateIdentity;
use Cresset\TemplateParser\Magento\ShadowComparator;
use Cresset\TemplateParser\Magento\TemplateFilterAdapter;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\Port\BlockRenderer;
use Cresset\TemplateParser\Port\ConfigReader;
use Cresset\TemplateParser\Port\CustomDirectiveRenderer;
use Cresset\TemplateParser\Port\CustomVariableReader;
use Cresset\TemplateParser\Port\LayoutRenderer;
use Cresset\TemplateParser\Port\RefusedByPort;
use Cresset\TemplateParser\Port\StylesheetLoader;
use Cresset\TemplateParser\Port\TemplateLoader;
use Cresset\TemplateParser\Port\TemplateUrlBuilder;
use Cresset\TemplateParser\Port\UrlBuilder;
use Cresset\TemplateParser\Port\WidgetRenderer;
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
            translator: new class implements \Cresset\TemplateParser\Port\Translator {
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
    private function plugin(array &$records, string $stage): TemplateFilterPlugin
    {
        $recorder = new class ($records) extends ShadowRecorder {
            public function __construct(private array &$records) {}
            public function record(int|string|null $storeId, string $template, ?ShadowOutcome $outcome, bool $served = false, bool $fellBack = false): void
            {
                $this->records[] = [$outcome?->outcome, $outcome?->detail, $served, $fellBack];
            }
        };
        $mode = new EngineMode(new class ($stage) implements ScopeConfigInterface {
            public function __construct(private string $stage) {}
            public function getValue($path, $scope = 'default', $scopeCode = null)
            {
                return $path === EngineMode::XML_PATH ? $this->stage : '0';
            }
            public function isSetFlag($path, $scope = 'default', $scopeCode = null) { return false; }
        });

        return new TemplateFilterPlugin(
            new ShadowComparator($this->adapter($this->everyPort())),
            $mode,
            new TemplateIdentity(),
            $recorder
        );
    }
}
