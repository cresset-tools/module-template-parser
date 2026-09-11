<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Console\LegacyRenderer;
use Cresset\TemplateParser\Console\MagentoContext;
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

        self::assertSame(['start:7:frontend:true', 'stop'], $emulation->calls);
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
        self::assertSame(['start:7:frontend:true', 'stop'], $emulation->calls);
    }

    /** A store with no emulation service still gets compared, just without a theme. */
    public function testAnAbsentEmulationServiceIsNotFatal(): void
    {
        $render = (new LegacyRenderer($this->context($this->model(), null)))->render('X', [], 7);

        self::assertNotNull($render);
        self::assertSame('LEGACY:X', $render->output);
    }
}
