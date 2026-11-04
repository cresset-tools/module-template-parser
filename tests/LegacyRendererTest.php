<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Console\LegacyRenderer;
use MageOS\TemplateParser\Console\MagentoContext;
use PHPUnit\Framework\TestCase;

/**
 * The `today` column of `template-parser diff`.
 *
 * This class decides what the old engine is TAKEN to do, so a mistake here does not look
 * like a mistake here - it looks like the new engine diverging. It had no tests, and both
 * bugs below were found by comparing its output against a real store rather than against
 * anything in this suite.
 */
final class LegacyRendererTest extends TestCase
{
    private function context(object $model, ?object $emulation = null): MagentoContext
    {
        $factory = new class ($model) {
            public function __construct(private object $model) {}
            public function create(array $data = []): object { return $this->model; }
        };

        return MagentoContext::fromObjectManager(new class ($factory, $emulation) {
            public function __construct(private object $factory, private ?object $emulation) {}
            public function get(string $class): ?object
            {
                if ($class === \Magento\Email\Model\TemplateFactory::class) {
                    return $this->factory;
                }
                if ($class === \Magento\Store\Model\App\Emulation::class) {
                    return $this->emulation;
                }
                if ($class === \Magento\Store\Model\StoreManagerInterface::class) {
                    return new class {
                        public function getStore($id = null): object
                        {
                            return new class { public function getId() { return 1; } };
                        }
                    };
                }
                return null;
            }
        });
    }

    private function emulation(): object
    {
        return new class extends \Magento\Store\Model\App\Emulation {
            public array $calls = [];
            public function startEnvironmentEmulation($storeId, $area = 'frontend', $force = false)
            {
                $this->calls[] = "start:{$storeId}:{$area}:" . var_export($force, true);
                return $this;
            }
            public function stopEnvironmentEmulation()
            {
                $this->calls[] = 'stop';
                return $this;
            }
        };
    }

    private function model(array $templateVars = ['a' => 1]): object
    {
        $filter = new class ($templateVars) extends \Magento\Framework\Filter\Template {
            public function __construct(array $vars) { $this->templateVars = $vars; }
            public function filter($value) { return 'LEGACY:' . $value; }
            public function getDesignParams()
            {
                return [
                    'area' => 'frontend',
                    'theme' => 'Magento/luma',
                    'themeModel' => new \stdClass(),
                    'locale' => 'en_US',
                ];
            }
        };

        return new class ($filter) {
            public array $calls = [];
            public function __construct(public object $filter) {}
            public function setTemplateType($t) { $this->calls[] = 'setTemplateType'; return $this; }
            public function setTemplateText($t) { $this->calls[] = 'setTemplateText'; return $this; }
            public function setDesignConfig($c) { $this->calls[] = 'setDesignConfig'; return $this; }
            public function setUseAbsoluteLinks($f) { $this->calls[] = 'setUseAbsoluteLinks:' . var_export($f, true); return $this; }
            // AbstractTemplate::getProcessedTemplate() opens by calling getTemplateFilter(),
            // which is the whole reason the flag has to be set before it: the filter is built
            // once, and it reads getUseAbsoluteLinks() while building. A stub that did not
            // model that let a late set pass the ordering assertion below.
            public function getProcessedTemplate(array $v = []) { $this->getTemplateFilter(); $this->calls[] = 'getProcessedTemplate'; return ''; }
            public function getTemplateFilter() { $this->calls[] = 'getTemplateFilter'; return $this->filter; }
        };
    }

    /**
     * `Email\Model\Template::processTemplate()` sets this before every send.
     *
     * getTemplateFilter() reads it exactly once, when it first builds the filter, so setting
     * it late is the same as not setting it. Left null, Url::setRouteParams keeps `_absolute`
     * - `isset(null)` being false - and getActionPath() pads `customer/account` out to
     * `customer/account/index/`, which is what no pipeline the store runs produces. The tool
     * was reporting that padding as the new engine's divergence.
     */
    public function testTheModelUsesAbsoluteLinksAndIsToldBeforeItsFilterIsBuilt(): void
    {
        $model = $this->model();
        (new LegacyRenderer($this->context($model)))->render('{{store url="customer/account"}}', []);

        $flag = array_search('setUseAbsoluteLinks:true', $model->calls, true);
        self::assertNotFalse($flag, 'the model was never told to use absolute links');

        $built = array_search('getTemplateFilter', $model->calls, true);
        self::assertNotFalse($built);
        self::assertLessThan($built, $flag, 'the flag was set after the filter had already read it');
    }

    /**
     * The area is ensured here, not assumed to have been set by whoever called first.
     *
     * An email template model will not render without one. EngineFactory sets it when it
     * builds its ports, so the Auditor - which creates the engine before calling this - worked
     * by accident of ordering. Called on its own, the first render of the process threw, was
     * swallowed, and came back as null, which reads downstream as a divergence in the engine.
     */
    public function testTheAreaIsEnsuredRatherThanAssumed(): void
    {
        $asked = [];
        $context = MagentoContext::fromObjectManager(new class ($this->model(), $asked) {
            public function __construct(private object $model, private array &$asked) {}
            public function get(string $class): ?object
            {
                $this->asked[] = $class;
                if ($class === \Magento\Email\Model\TemplateFactory::class) {
                    return new class ($this->model) {
                        public function __construct(private object $model) {}
                        public function create(array $data = []): object { return $this->model; }
                    };
                }
                if ($class === \Magento\Framework\App\State::class) {
                    return new class {
                        public function getAreaCode() { return null; }
                        public function setAreaCode($code) {}
                    };
                }
                return null;
            }
        });

        (new LegacyRenderer($context))->render('X', []);

        self::assertContains(
            \Magento\Framework\App\State::class,
            $asked,
            'the renderer never asked for App\\State, so it cannot have ensured an area'
        );
    }

    /** The filter really is the thing being compared, not the model's own output. */
    public function testTheLegacyRenderIsTheFiltersOwn(): void
    {
        $render = (new LegacyRenderer($this->context($this->model())))->render('X', []);

        self::assertNotNull($render);
        self::assertSame('LEGACY:X', $render->output);
    }

    /**
     * The design the filter used travels back, because nothing downstream can look it up.
     *
     * getProcessedTemplate() takes it inside the model's own emulation and cancels that
     * emulation before returning, so a caller reading DesignInterface afterwards resolves a
     * different theme than the filter did - which made the same template resolve a different
     * stylesheet depending on who rendered it.
     */
    public function testTheDesignTheFilterUsedTravelsBack(): void
    {
        $render = (new LegacyRenderer($this->context($this->model())))->render('X', []);

        self::assertNotNull($render);
        self::assertSame('frontend', $render->designParams['area']);
        self::assertSame('Magento/luma', $render->designParams['theme']);
        self::assertSame('en_US', $render->designParams['locale']);
    }

    /**
     * And `themeModel` is dropped, because it is an object.
     *
     * No fixture can carry one and no replay can rebuild it - it serialised to `[]` and made
     * the recorded tape unreproducible. Asset\Repository re-resolves the model from `theme`
     * when it is absent, so what travels is a portable description of the same design.
     */
    public function testTheUnportableThemeModelIsNotCarried(): void
    {
        $render = (new LegacyRenderer($this->context($this->model())))->render('X', []);

        self::assertNotNull($render);
        self::assertArrayNotHasKey('themeModel', $render->designParams);
    }

    /**
     * An empty read-back is an ANSWER, and was being treated as a failure.
     *
     * When addEmailVariables() throws, the filter genuinely renders with no variables - the
     * documented quirk where a template comes back verbatim. Substituting the caller's set
     * then rendered the candidate against variables the legacy side never saw, so every
     * template using one reported as a divergence in an engine that had done nothing wrong.
     */
    public function testAnEmptyVariableReadBackIsNotReplacedByTheCallersSet(): void
    {
        $render = (new LegacyRenderer($this->context($this->model([]))))->render('X', ['name' => 'Ada']);

        self::assertNotNull($render);
        self::assertSame([], $render->variables);
    }

    /** But a read-back that fails outright still falls back, or the comparison loses variables. */
    public function testTheCallersVariablesStandWhenTheReadBackCannotHappen(): void
    {
        $model = new class {
            public function setTemplateType($t) { return $this; }
            public function setTemplateText($t) { return $this; }
            public function setDesignConfig($c) { return $this; }
            public function setUseAbsoluteLinks($f) { return $this; }
            public function getProcessedTemplate(array $v = []) { return ''; }
            // Not a Filter\Template at all, so the reflected property has no value to give.
            public function getTemplateFilter() {
                return new class { public function filter($v) { return $v; } };
            }
        };

        $render = (new LegacyRenderer($this->context($model)))->render('X', ['name' => 'Ada']);

        self::assertNotNull($render);
        self::assertSame(['name' => 'Ada'], $render->variables);
    }

    /** A model that throws mid-pipeline still has a filter worth comparing. */
    public function testAThrowingModelStillYieldsAFilterComparison(): void
    {
        $filter = new class extends \Magento\Framework\Filter\Template {
            public function filter($value) { return 'LEGACY:' . $value; }
        };
        $model = new class ($filter) {
            public function __construct(private object $filter) {}
            public function setTemplateType($t) { return $this; }
            public function setTemplateText($t) { return $this; }
            public function setDesignConfig($c) { return $this; }
            public function setUseAbsoluteLinks($f) { return $this; }
            public function getProcessedTemplate(array $v = []) { throw new \RuntimeException('no such entity'); }
            public function getTemplateFilter() { return $this->filter; }
        };

        $render = (new LegacyRenderer($this->context($model)))->render('X', []);

        self::assertNotNull($render);
        self::assertSame('LEGACY:X', $render->output);
    }

    /**
     * processTemplate() renders inside store emulation, and that emulation is the only reason
     * DesignInterface has a theme.
     *
     * getProcessedTemplate() on its own does not apply it - applyDesignConfig() is what does,
     * and it is protected - so the filter resolved {{css}} and {{view}} against an empty theme
     * and every stock template carrying a stylesheet reported as a divergence in an engine
     * that had done nothing.
     */
    public function testTheRenderHappensInsideStoreEmulation(): void
    {
        $emulation = $this->emulation();
        (new LegacyRenderer($this->context($this->model(), $emulation)))->render('X', [], 7);

        // Started, started again after getProcessedTemplate() - see the next test - and stopped once.
        self::assertSame(['start:7:frontend:true', 'start:7:frontend:true', 'stop'], $emulation->calls);
    }

    /**
     * getProcessedTemplate() ends in cancelDesignConfig(), which stops the SHARED emulation -
     * so the filter() the comparison is made from ran in the CLI's own store, and
     * `diff --store=2` compared store 1's translations and theme.
     */
    public function testTheFilterRendersInsideTheEmulationEvenAfterTheModelStoppedIt(): void
    {
        $emulation = $this->emulation();
        $filter = new class ($emulation) extends \Magento\Framework\Filter\Template {
            public function __construct(private object $emulation) {}
            public function filter($value) { $this->emulation->calls[] = 'filter'; return 'LEGACY:' . $value; }
        };
        $model = new class ($filter, $emulation) {
            public function __construct(private object $filter, private object $emulation) {}
            public function setTemplateType($t) { return $this; }
            public function setTemplateText($t) { return $this; }
            public function setDesignConfig($c) { return $this; }
            public function setUseAbsoluteLinks($f) { return $this; }
            public function getProcessedTemplate(array $v = []) { $this->emulation->stopEnvironmentEmulation(); return ''; }
            public function getTemplateFilter() { return $this->filter; }
        };

        (new LegacyRenderer($this->context($model, $emulation)))->render('X', [], 2);

        self::assertSame(['start:2:frontend:true', 'stop', 'start:2:frontend:true', 'filter', 'stop'], $emulation->calls);
    }

    /** Emulation left running would leak the store into everything the tool renders next. */
    public function testEmulationIsStoppedEvenWhenTheRenderThrows(): void
    {
        $emulation = $this->emulation();
        $model = new class {
            public function setTemplateType($t) { return $this; }
            public function setTemplateText($t) { return $this; }
            public function setDesignConfig($c) { return $this; }
            public function setUseAbsoluteLinks($f) { return $this; }
            public function getProcessedTemplate(array $v = []) { return ''; }
            public function getTemplateFilter() { throw new \RuntimeException('no filter'); }
        };

        self::assertNull((new LegacyRenderer($this->context($model, $emulation)))->render('X', [], 7));
        self::assertSame(['start:7:frontend:true', 'start:7:frontend:true', 'stop'], $emulation->calls);
    }

    /** A store with no emulation service still gets compared, just without a theme. */
    public function testAnAbsentEmulationServiceIsNotFatal(): void
    {
        $render = (new LegacyRenderer($this->context($this->model(), null)))->render('X', [], 7);

        self::assertNotNull($render);
        self::assertSame('LEGACY:X', $render->output);
    }

    /** What `Email\Model\Template\Filter::filter()` prints in place of a template it raised on. */
    public function testTheFiltersErrorMessagesAreRecognised(): void
    {
        self::assertNotNull(LegacyRenderer::failureIn("Error filtering template: Invalid template file: 'x.phtml'"));
        self::assertNotNull(LegacyRenderer::failureIn("We're sorry, an error has occurred while generating this content."));
        self::assertNull(LegacyRenderer::failureIn('<p>Error filtering template: is what we print</p>'));
        self::assertNull(LegacyRenderer::failureIn('Dear Ada'));
    }

    public function testAnEmailRenderedAsTheErrorMessageIsAFailure(): void
    {
        $model = $this->model();
        $model->filter = new class extends \Magento\Framework\Filter\Template {
            public function __construct() {}
            public function filter($value) { return 'Error filtering template: Warning: Array to string conversion'; }
        };

        $render = (new LegacyRenderer($this->context($model)))->render('X', []);

        self::assertNotNull($render?->failure);
    }

    /**
     * The CMS page filter is one shared instance, and getStoreId() keeps the first store it
     * fills in - so every store view after the first rendered the first one's phone number.
     */
    public function testEachCmsRenderIsGivenItsOwnStore(): void
    {
        $filter = new class {
            public array $stores = [];
            public function setStoreId($id) { $this->stores[] = $id; return $this; }
            public function filter($value) { return (string)$value; }
        };
        $context = MagentoContext::fromObjectManager(new class ($filter) {
            public function __construct(private object $filter) {}
            public function get(string $class): ?object
            {
                return match ($class) {
                    \Magento\Email\Model\TemplateFactory::class => new \stdClass(),
                    \Magento\Cms\Model\Template\FilterProvider::class => new class ($this->filter) {
                        public function __construct(private object $filter) {}
                        public function getPageFilter() { return $this->filter; }
                    },
                    default => null,
                };
            }
        });
        $renderer = new LegacyRenderer($context);

        $renderer->render('X', [], 2, \MageOS\TemplateParser\Console\TemplateSubject::KIND_CMS);
        $renderer->render('X', [], 3, \MageOS\TemplateParser\Console\TemplateSubject::KIND_CMS);

        self::assertSame([2, 3], $filter->stores);
    }

    /**
     * The candidate is handed back into the same filter, where the plugin serves it - so the
     * plugins on filter() treat it as they treated legacy's. Where the plugin is not on the
     * filter, the bare candidate stands.
     */
    public function testTheCandidateIsServedThroughTheSameFilter(): void
    {
        $candidates = new \MageOS\TemplateParser\Magento\CandidateRenders();
        $filter = new class ($candidates) {
            public bool $plugged = true;
            public function __construct(private \MageOS\TemplateParser\Magento\CandidateRenders $candidates) {}
            public function filter($value)
            {
                $ours = $this->plugged ? $this->candidates->take($this) : null;
                return 'DECODED(' . ($ours !== null ? $ours($value) : 'LEGACY:' . $value) . ')';
            }
        };
        $context = MagentoContext::fromObjectManager(new class ($filter, $candidates) {
            public function __construct(private object $filter, private object $candidates) {}
            public function get(string $class): ?object
            {
                return match ($class) {
                    \Magento\Email\Model\TemplateFactory::class => new \stdClass(),
                    \MageOS\TemplateParser\Magento\CandidateRenders::class => $this->candidates,
                    \Magento\Cms\Model\Template\FilterProvider::class => new class ($this->filter) {
                        public function __construct(private object $filter) {}
                        public function getPageFilter() { return $this->filter; }
                    },
                    default => null,
                };
            }
        });

        $render = (new LegacyRenderer($context))->render('X', [], 1, \MageOS\TemplateParser\Console\TemplateSubject::KIND_CMS);

        self::assertSame('DECODED(LEGACY:X)', $render?->output);
        self::assertSame('DECODED(OURS:X)', ($render->through)(static fn (string $v): string => 'OURS:' . $v));
        $filter->plugged = false;
        self::assertSame('OURS:X', ($render->through)(static fn (string $v): string => 'OURS:' . $v));
    }

    /**
     * The filter's nesting depth is the store's one shared meter, and `filter()` steps back
     * up only when it returns. A legacy render that raised part-way - under the store's error
     * handler a warning does - left it a level deep, and every later render in the run then
     * acted as a child: it left its includes' `:deferred:` placeholders unresolved, and the
     * diff called the engine wrong for 112 templates on one store.
     */
    public function testARenderThatRaisesDoesNotLeaveTheNextOneNested(): void
    {
        $meter = new \Magento\Framework\Filter\Template\FilteringDepthMeter();
        $filter = new class ($meter) extends \Magento\Framework\Filter\Template {
            public array $depths = [];
            public function __construct(private object $meter) {}
            public function filter($value)
            {
                // Template::filter()'s own shape: descend, render, ascend - no finally.
                $this->meter->descend();
                $this->depths[] = $this->meter->showMark();
                if ($value === 'RAISES') {
                    throw new \RuntimeException('a warning, under the store\'s handler');
                }
                $this->meter->ascend();

                return 'LEGACY:' . $value;
            }
        };
        $context = MagentoContext::fromObjectManager(new class ($filter, $meter) {
            public function __construct(private object $filter, private object $meter) {}
            public function get(string $class): ?object
            {
                return match ($class) {
                    \Magento\Email\Model\TemplateFactory::class => new \stdClass(),
                    \Magento\Framework\Filter\Template\FilteringDepthMeter::class => $this->meter,
                    \Magento\Cms\Model\Template\FilterProvider::class => new class ($this->filter) {
                        public function __construct(private object $filter) {}
                        public function getPageFilter() { return $this->filter; }
                    },
                    default => null,
                };
            }
        });
        $renderer = new LegacyRenderer($context);

        self::assertNull($renderer->render('RAISES', [], 1, \MageOS\TemplateParser\Console\TemplateSubject::KIND_CMS));
        self::assertSame(0, $meter->showMark());
        self::assertSame('LEGACY:X', $renderer->render('X', [], 1, \MageOS\TemplateParser\Console\TemplateSubject::KIND_CMS)?->output);
        self::assertSame([1, 1], $filter->depths, 'the second render ran as a child of the first');
        self::assertSame(0, $meter->showMark());
    }

    /** Put back to where it was, not to zero: a render inside a render keeps its parent's level. */
    public function testTheDepthIsRestoredToWhereItWas(): void
    {
        $meter = new \Magento\Framework\Filter\Template\FilteringDepthMeter();
        $meter->descend();
        $depth = new \MageOS\TemplateParser\Console\FilteringDepth(MagentoContext::fromObjectManager(new class ($meter) {
            public function __construct(private object $meter) {}
            public function get(string $class): ?object { return $class === \Magento\Framework\Filter\Template\FilteringDepthMeter::class ? $this->meter : null; }
        }));

        try {
            $depth->keep(static function () use ($meter): void {
                $meter->descend();
                $meter->descend();
                throw new \RuntimeException();
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(1, $meter->showMark());

        self::assertSame('done', $depth->keep(static function () use ($meter): string {
            $meter->ascend();
            return 'done';
        }));
        self::assertSame(1, $meter->showMark());
    }
}
