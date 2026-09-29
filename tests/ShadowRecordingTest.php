<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\Magento\Config\EngineMode;
use Cresset\TemplateParser\Magento\Plugin\CmsBlockIdentityPlugin;
use Cresset\TemplateParser\Magento\Plugin\CmsPageIdentityPlugin;
use Cresset\TemplateParser\Magento\Plugin\EmailSubjectIdentityPlugin;
use Cresset\TemplateParser\Magento\Plugin\EmailTemplateIdentityPlugin;
use Cresset\TemplateParser\Magento\Plugin\TemplateFilterPlugin;
use Cresset\TemplateParser\Magento\Shadow\ShadowOutcome;
use Cresset\TemplateParser\Magento\Shadow\ShadowRecorder;
use Cresset\TemplateParser\Magento\Shadow\TemplateIdentity;
use Cresset\TemplateParser\Magento\ShadowComparator;
use Cresset\TemplateParser\Magento\TemplateFilterAdapter;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\SyntaxError;
use Magento\Cms\Model\Block;
use Magento\Cms\Model\Page;
use Magento\Email\Model\AbstractTemplate;
use Magento\Email\Model\Template as EmailTemplate;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filter\Template as LegacyTemplate;
use Magento\Newsletter\Model\Template as NewsletterTemplate;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Where a Shadow comparison ends up: named after the template it was, counted against the
 * store view it ran for, and written without ever costing the render.
 */
final class ShadowRecordingTest extends TestCase
{
    // ---------------------------------------------------------------- template identity

    public function testARegisteredSourceIsIdentifiedAndAnUnknownOneIsNot(): void
    {
        $identity = new TemplateIdentity();
        $identity->remember('Dear {{var name}},', 'email:sales_email_order_template');

        self::assertSame('email:sales_email_order_template', $identity->identify('Dear {{var name}},'));
        self::assertNull($identity->identify('Dear {{var name}}'));
        self::assertNull($identity->identify(''), 'empty source names nothing');
    }

    /**
     * Bounded, because a queue consumer sends thousands of emails in one process. The oldest
     * registration goes first, and registering again makes an entry young.
     */
    public function testTheRegistryIsBoundedAndEvictsTheLeastRecentlyRegistered(): void
    {
        $identity = new TemplateIdentity(3);
        $identity->remember('a', 'A');
        $identity->remember('b', 'B');
        $identity->remember('c', 'C');
        $identity->remember('a', 'A');   // a is now the youngest
        $identity->remember('d', 'D');   // so b goes

        self::assertSame('A', $identity->identify('a'));
        self::assertNull($identity->identify('b'));
        self::assertSame('C', $identity->identify('c'));
        self::assertSame('D', $identity->identify('d'));

        $big = new TemplateIdentity();
        for ($i = 0; $i < 10 * TemplateIdentity::CAPACITY; $i++) {
            $big->remember("template $i", "email:$i");
        }
        $held = (fn () => count($this->bySource))->call($big);
        self::assertSame(TemplateIdentity::CAPACITY, $held);
    }

    public function testAnUnidentifiedRenderIsNamedAfterItsFilterClass(): void
    {
        self::assertSame('unidentified:' . LegacyTemplate::class, TemplateIdentity::unidentified(new LegacyTemplate()));
        self::assertSame(
            'unidentified:' . LegacyTemplate::class,
            TemplateIdentity::unidentified(new class extends LegacyTemplate {})
        );
    }

    public function testEmailBodiesAreNamedByTheirIdOrCode(): void
    {
        $identity = new TemplateIdentity();
        $plugin = new EmailTemplateIdentityPlugin($identity);

        $plugin->beforeGetProcessedTemplate($this->emailModel(AbstractTemplate::class, 12, 'saved body'));
        $plugin->beforeGetProcessedTemplate($this->emailModel(AbstractTemplate::class, 'sales_email_order_template', 'file body'));
        $plugin->beforeGetProcessedTemplate($this->emailModel(AbstractTemplate::class, null, 'preview body'));
        $plugin->beforeGetProcessedTemplate($this->emailModel(NewsletterTemplate::class, 3, 'newsletter body'));

        self::assertSame('email:12', $identity->identify('saved body'));
        self::assertSame('email:sales_email_order_template', $identity->identify('file body'));
        self::assertSame('email:unsaved', $identity->identify('preview body'));
        self::assertSame('newsletter:3', $identity->identify('newsletter body'));
    }

    public function testEmailSubjectsAreNamedApartFromTheirBodies(): void
    {
        $identity = new TemplateIdentity();
        $subject = new class extends EmailTemplate {
            public function getId() { return 'customer_create_account_email_template'; }
            public function getTemplateSubject() { return 'Welcome to {{var store.frontend_name}}'; }
        };

        (new EmailSubjectIdentityPlugin($identity))->beforeGetProcessedTemplateSubject($subject, []);

        self::assertSame(
            'email:customer_create_account_email_template/subject',
            $identity->identify('Welcome to {{var store.frontend_name}}')
        );
    }

    /** Naming must never break the render it precedes. */
    public function testAModelThatRaisesIsLeftUnnamed(): void
    {
        $identity = new TemplateIdentity();
        $model = new class extends AbstractTemplate {
            public function getTemplateText() { throw new \RuntimeException('no'); }
        };

        (new EmailTemplateIdentityPlugin($identity))->beforeGetProcessedTemplate($model, []);

        self::assertNull($identity->identify('no'));
    }

    public function testCmsContentIsNamedAsItIsHandedOut(): void
    {
        $identity = new TemplateIdentity();
        $block = new class extends Block { public function getId() { return 7; } };
        $page = new class extends Page { public function getId() { return '2'; } };

        self::assertSame('{{widget}}', (new CmsBlockIdentityPlugin($identity))->afterGetContent($block, '{{widget}}'));
        self::assertSame('<h1>Home</h1>', (new CmsPageIdentityPlugin($identity))->afterGetContent($page, '<h1>Home</h1>'));
        self::assertNull((new CmsBlockIdentityPlugin($identity))->afterGetContent($block, null));

        self::assertSame('cms_block:7', $identity->identify('{{widget}}'));
        self::assertSame('cms_page:2', $identity->identify('<h1>Home</h1>'));
    }

    // ---------------------------------------------------------------- the filter plugin

    public function testThePluginRecordsAgainstTheTemplateAndStoreItRendered(): void
    {
        $records = [];
        $identity = new TemplateIdentity();
        $identity->remember('Dear {{var name}},', 'email:sales_email_order_template');
        $plugin = $this->plugin($identity, $records);
        $subject = $this->filterFor(2);

        $plugin->beforeSetVariables($subject, ['name' => 'Ada']);
        $plugin->beforeFilter($subject, 'Dear {{var name}},');
        $plugin->afterFilter($subject, 'Dear Ada,', 'Dear {{var name}},');

        $plugin->beforeFilter($subject, 'Hello');
        $plugin->afterFilter($subject, 'Goodbye', 'Hello');

        self::assertSame([
            [2, 'email:sales_email_order_template', ShadowOutcome::AGREE],
            [2, 'unidentified:' . LegacyTemplate::class, ShadowOutcome::DIVERGE],
        ], $records);
    }

    /** The store is the one the render was decided for, even if the filter's changes mid-render. */
    public function testTheStoreIsTheOneTheRenderStartedIn(): void
    {
        $records = [];
        $plugin = $this->plugin(new TemplateIdentity(), $records);
        $subject = $this->filterFor(2);

        $plugin->beforeFilter($subject, 'x');
        (fn () => $this->_storeId = 5)->call($subject);
        $plugin->afterFilter($subject, 'x', 'x');

        self::assertSame(2, $records[0][0]);
    }

    /** A recorder that raises does not reach the render. */
    public function testARecorderThatRaisesLeavesTheLegacyResultStanding(): void
    {
        $recorder = new class extends ShadowRecorder {
            public function __construct() {}
            public function record(int|string|null $storeId, string $template, ShadowOutcome $outcome): void
            {
                throw new \RuntimeException('recorder fell over');
            }
        };
        $plugin = new TemplateFilterPlugin(
            new ShadowComparator(new TemplateFilterAdapter(new HostServices(), Options::compatible())),
            $this->shadowEverywhere(),
            new TemplateIdentity(),
            $recorder
        );
        $subject = $this->filterFor(1);

        $plugin->beforeFilter($subject, 'x');
        self::assertSame('LEGACY', $plugin->afterFilter($subject, 'LEGACY', 'x'));
    }

    // ---------------------------------------------------------------- the recorder

    /**
     * One row per store and template, whatever mix of outcomes the batch held, with the
     * counter reset at the divergence and counting only what came after it.
     */
    public function testOutcomesAggregateIntoOneRowPerStoreAndTemplate(): void
    {
        $queries = [];
        $recorder = $this->recorder($queries, clock: fn () => 1_700_000_000);

        foreach (['agree', 'agree', 'agree', 'diverge', 'agree', 'refused'] as $kind) {
            $recorder->record(1, 'email:12', $this->outcome($kind));
        }
        $recorder->record(1, 'cms_block:7', ShadowOutcome::agreed());
        $recorder->record(2, 'email:12', ShadowOutcome::agreed());
        $recorder->flush();

        self::assertCount(1, $queries, 'one statement per flush');
        $rows = $this->rows($queries[0]);
        self::assertCount(3, $rows);

        $row = $rows[0];
        self::assertSame(1, $row['store_id']);
        self::assertSame('email:12', $row['template']);
        self::assertSame(4, $row['agreed']);
        self::assertSame(1, $row['diverged']);
        self::assertSame(1, $row['refused']);
        self::assertSame(0, $row['crashed']);
        self::assertSame(2, $row['renders_since_divergence'], 'an agreement and a refusal since the divergence');
        self::assertSame('2023-11-14 22:13:20', $row['last_divergence_at']);
        self::assertSame(13, json_decode($row['last_divergence'], true)['legacy_length']);
        self::assertSame('Unexpected token', json_decode($row['last_refusal'], true)['problem']);
        self::assertNull($row['last_crash']);

        self::assertSame(1, $rows[1]['renders_since_divergence']);
        self::assertNull($rows[1]['last_divergence_at']);
        self::assertSame(2, $rows[2]['store_id']);
    }

    public function testACrashResetsTheCounterToo(): void
    {
        $queries = [];
        $recorder = $this->recorder($queries);
        $recorder->record(1, 'email:12', ShadowOutcome::agreed());
        $recorder->record(1, 'email:12', ShadowOutcome::crashed(new \TypeError('boom')));
        $recorder->flush();

        $row = $this->rows($queries[0])[0];
        self::assertSame(0, $row['renders_since_divergence']);
        self::assertNotNull($row['last_divergence_at']);
        self::assertSame('boom', json_decode($row['last_crash'], true)['message']);
    }

    /**
     * The statement merges a batch into what is stored: counters add, the "since" counter adds
     * unless the batch diverged, and every "last" column keeps its stored value unless the
     * batch has a newer one.
     */
    public function testTheUpsertMergesTheBatchIntoTheStoredRow(): void
    {
        $queries = [];
        $recorder = $this->recorder($queries, prefix: 'm2_');
        $recorder->record(1, 'email:12', ShadowOutcome::agreed());
        $recorder->flush();

        [$sql, $bind] = $queries[0];
        self::assertStringStartsWith('INSERT INTO `m2_cresset_template_shadow` (`store_id`, `template`, ', $sql);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        self::assertStringContainsString('`agreed` = `agreed` + VALUES(`agreed`)', $sql);
        self::assertStringContainsString(
            '`renders_since_divergence` = IF(VALUES(`last_divergence_at`) IS NULL, '
            . '`renders_since_divergence` + VALUES(`renders_since_divergence`), VALUES(`renders_since_divergence`))',
            $sql
        );
        self::assertStringContainsString('`last_divergence_at` = COALESCE(VALUES(`last_divergence_at`), `last_divergence_at`)', $sql);
        self::assertStringContainsString('`last_seen` = GREATEST(`last_seen`, VALUES(`last_seen`))', $sql);
        self::assertStringNotContainsString('`first_seen` =', $sql, 'first_seen is set once, on insert');
        self::assertSame(substr_count($sql, '?'), count($bind));
    }

    public function testNothingPendingWritesNothing(): void
    {
        $queries = [];
        $this->recorder($queries)->flush();

        self::assertSame([], $queries);
    }

    /** A long-running process reports as it goes, and holds a bounded buffer. */
    public function testItFlushesWhenTheBufferFillsOrAMinutePasses(): void
    {
        $queries = [];
        $recorder = $this->recorder($queries);
        for ($i = 0; $i < ShadowRecorder::FLUSH_AT_ROWS; $i++) {
            $recorder->record(1, "email:$i", ShadowOutcome::agreed());
        }
        self::assertCount(1, $queries);
        self::assertCount(ShadowRecorder::FLUSH_AT_ROWS, $this->rows($queries[0]));

        $now = 1_000;
        $queries = [];
        $recorder = $this->recorder($queries, clock: function () use (&$now) { return $now; });
        $recorder->record(1, 'email:1', ShadowOutcome::agreed());
        $now += ShadowRecorder::FLUSH_AFTER_SECONDS - 1;
        $recorder->record(1, 'email:1', ShadowOutcome::agreed());
        self::assertSame([], $queries);
        $now += 1;
        $recorder->record(1, 'email:1', ShadowOutcome::agreed());
        self::assertCount(1, $queries);
        self::assertSame(3, $this->rows($queries[0])[0]['agreed']);
    }

    /**
     * Recording must never cost a render. A failed write is dropped, and warned about once -
     * a missing table must not write a line per page view.
     */
    public function testAFailedWriteIsWarnedAboutOnceAndNeverThrows(): void
    {
        $logger = new CollectingLogger();
        $resource = new class extends ResourceConnection {
            public int $attempts = 0;
            public function getConnection($resourceName = 'default')
            {
                $this->attempts++;
                throw new \RuntimeException("Table 'cresset_template_shadow' doesn't exist");
            }
        };
        $recorder = new ShadowRecorder($resource, $this->stores(), $logger, false);

        $recorder->record(1, 'email:1', ShadowOutcome::agreed());
        $recorder->flush();
        $recorder->record(1, 'email:1', ShadowOutcome::agreed());
        $recorder->flush();
        $recorder->flush();

        self::assertSame(2, $resource->attempts, 'the failed batch was retried');
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertStringContainsString("doesn't exist", $logger->records[0][2]['error']);
    }

    /** "The current store" is resolved when the render happens, and a failure is store 0. */
    public function testTheStoreIsResolvedAtRecordTime(): void
    {
        $queries = [];
        $stores = new class implements StoreManagerInterface {
            public ?int $current = 3;
            public function getStore($storeId = null)
            {
                $id = match ($storeId) { null => $this->current, 'nl' => 4, default => throw new \RuntimeException('no store') };
                return new class ($id) { public function __construct(private ?int $id) {} public function getId() { return $this->id; } };
            }
        };
        $recorder = new ShadowRecorder($this->resource($queries), $stores, new CollectingLogger(), false);

        $recorder->record(null, 'a', ShadowOutcome::agreed());
        $stores->current = 9;
        $recorder->record('2', 'b', ShadowOutcome::agreed());
        $recorder->record('nl', 'c', ShadowOutcome::agreed());
        $recorder->record('gone', 'd', ShadowOutcome::agreed());
        $recorder->flush();

        self::assertSame([3, 2, 4, 0], array_column($this->rows($queries[0]), 'store_id'));
    }

    public function testATemplateNameLongerThanTheColumnIsCut(): void
    {
        $queries = [];
        $recorder = $this->recorder($queries);
        $recorder->record(1, str_repeat('é', 200), ShadowOutcome::agreed());
        $recorder->flush();

        $template = $this->rows($queries[0])[0]['template'];
        self::assertLessThanOrEqual(255, strlen($template));
        self::assertTrue(mb_check_encoding($template, 'UTF-8'), 'cut through a character');
    }

    // ---------------------------------------------------------------- the shipped configuration

    /**
     * Magento's plugin validator refuses a plugin whose subject is not typed as the class it
     * is declared on, or whose method that class does not have - at compile time, on the
     * merchant's server. So check the same thing here.
     */
    public function testEveryDeclaredPluginFitsTheClassItIsDeclaredOn(): void
    {
        $di = simplexml_load_file(__DIR__ . '/../etc/di.xml');
        $declared = [];
        foreach ($di->xpath('//type[plugin]') as $type) {
            foreach ($type->plugin as $plugin) {
                $declared[(string)$plugin['type']] = (string)$type['name'];
            }
        }

        self::assertSame([
            TemplateFilterPlugin::class => 'Magento\\Email\\Model\\Template\\Filter',
            EmailTemplateIdentityPlugin::class => AbstractTemplate::class,
            EmailSubjectIdentityPlugin::class => EmailTemplate::class,
            CmsBlockIdentityPlugin::class => Block::class,
            CmsPageIdentityPlugin::class => Page::class,
        ], $declared);

        foreach ($declared as $pluginClass => $intercepted) {
            // The Email filter has no stub; the filter plugin's own tests exercise it against
            // the framework base class it extends.
            if (!class_exists($intercepted)) {
                continue;
            }
            foreach ((new \ReflectionClass($pluginClass))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!preg_match('/^(before|after|around)([A-Z]\w*)$/', $method->getName(), $m)) {
                    continue;
                }
                $subjectType = (string)$method->getParameters()[0]->getType();
                self::assertTrue(
                    $subjectType === $intercepted || is_subclass_of($intercepted, $subjectType),
                    "$pluginClass::{$method->getName()} types its subject as $subjectType"
                );
                self::assertTrue(method_exists($intercepted, lcfirst($m[2])), "$intercepted has no " . lcfirst($m[2]));
            }
        }
    }

    public function testTheSchemaTheWhitelistAndTheRecorderAgree(): void
    {
        $schema = simplexml_load_file(__DIR__ . '/../etc/db_schema.xml');
        $table = $schema->xpath('//table[@name="' . ShadowRecorder::TABLE . '"]');
        self::assertCount(1, $table);

        $columns = array_map(static fn ($c) => (string)$c['name'], $table[0]->xpath('column'));
        $written = (new \ReflectionClassConstant(ShadowRecorder::class, 'COLUMNS'))->getValue();
        self::assertSame([], array_diff($written, $columns), 'the recorder writes a column the schema lacks');

        $unique = $table[0]->xpath('constraint[@xsi:type="unique"]/column')
            ?: $table[0]->xpath('constraint[@*[local-name()="type"]="unique"]/column');
        self::assertSame(['store_id', 'template'], array_map(static fn ($c) => (string)$c['name'], $unique));

        $template = $table[0]->xpath('column[@name="template"]')[0];
        self::assertSame('255', (string)$template['length']);

        $whitelist = json_decode((string)file_get_contents(__DIR__ . '/../etc/db_schema_whitelist.json'), true);
        self::assertSame($columns, array_keys($whitelist[ShadowRecorder::TABLE]['column']));
        $constraints = array_map(static fn ($c) => (string)$c['referenceId'], $table[0]->xpath('constraint'));
        self::assertSame($constraints, array_keys($whitelist[ShadowRecorder::TABLE]['constraint']));
    }

    public function testTheModuleLoadsAfterEverythingItsConfigurationBuildsOn(): void
    {
        $module = simplexml_load_file(__DIR__ . '/../etc/module.xml');
        $sequence = array_map(static fn ($m) => (string)$m['name'], $module->xpath('//sequence/module'));

        foreach (['Magento_Backend', 'Magento_Cms', 'Magento_Config', 'Magento_Email', 'Magento_Store'] as $needed) {
            self::assertContains($needed, $sequence);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function outcome(string $kind): ShadowOutcome
    {
        return match ($kind) {
            'agree' => ShadowOutcome::agreed(),
            'diverge' => ShadowOutcome::diverged(13, 6, 0, [], []),
            'refused' => ShadowOutcome::refused(SyntaxError::at('{{if', 0, 'Unexpected token')),
        };
    }

    /**
     * @param class-string $class
     */
    private function emailModel(string $class, int|string|null $id, string $text): AbstractTemplate
    {
        $model = new class extends NewsletterTemplate {
            public $id;
            public $text;
            public function getId() { return $this->id; }
            public function getTemplateText() { return $this->text; }
        };
        if ($class === AbstractTemplate::class) {
            $model = new class extends AbstractTemplate {
                public $id;
                public $text;
                public function getId() { return $this->id; }
                public function getTemplateText() { return $this->text; }
            };
        }
        $model->id = $id;
        $model->text = $text;

        return $model;
    }

    private function stores(): StoreManagerInterface
    {
        return new class implements StoreManagerInterface {
            public function getStore($storeId = null)
            {
                return new class { public function getId() { return 1; } };
            }
        };
    }

    /** A connection that keeps every query it is given, as [sql, bind]. */
    private function resource(array &$queries, string $prefix = ''): ResourceConnection
    {
        return new class ($queries, $prefix) extends ResourceConnection {
            public function __construct(private array &$queries, private string $prefix) {}
            public function getTableName($modelEntity, $connectionName = 'default') { return $this->prefix . $modelEntity; }
            public function getConnection($resourceName = 'default')
            {
                return new class ($this->queries) {
                    public function __construct(private array &$queries) {}
                    public function query($sql, $bind = []) { $this->queries[] = [$sql, $bind]; }
                };
            }
        };
    }

    private function recorder(array &$queries, ?\Closure $clock = null, string $prefix = ''): ShadowRecorder
    {
        return new ShadowRecorder($this->resource($queries, $prefix), $this->stores(), new CollectingLogger(), false, $clock);
    }

    /**
     * The bound rows of one captured INSERT, keyed by column.
     *
     * @param array{0:string,1:list<mixed>} $query
     * @return list<array<string,mixed>>
     */
    private function rows(array $query): array
    {
        [$sql, $bind] = $query;
        preg_match('/\(([^)]*)\) VALUES/', $sql, $m);
        $columns = array_map(static fn ($c) => trim($c, ' `'), explode(',', $m[1]));

        return array_map(static fn ($values) => array_combine($columns, $values), array_chunk($bind, count($columns)));
    }

    private function shadowEverywhere(): EngineMode
    {
        return new EngineMode(new class implements ScopeConfigInterface {
            public function getValue($path, $scope = 'default', $scopeCode = null) { return EngineMode::SHADOW; }
            public function isSetFlag($path, $scope = 'default', $scopeCode = null) { return false; }
        });
    }

    /** @param list<array{0:int|string|null,1:string,2:string}> $records */
    private function plugin(TemplateIdentity $identity, array &$records): TemplateFilterPlugin
    {
        $recorder = new class ($records) extends ShadowRecorder {
            public function __construct(private array &$records) {}
            public function record(int|string|null $storeId, string $template, ShadowOutcome $outcome): void
            {
                $this->records[] = [$storeId, $template, $outcome->outcome];
            }
        };

        return new TemplateFilterPlugin(
            new ShadowComparator(new TemplateFilterAdapter(new HostServices(), Options::compatible())),
            $this->shadowEverywhere(),
            $identity,
            $recorder
        );
    }

    private function filterFor(int $storeId): LegacyTemplate
    {
        return new class ($storeId) extends LegacyTemplate {
            protected $_storeId;
            public function __construct($storeId) { $this->_storeId = $storeId; }
        };
    }
}
