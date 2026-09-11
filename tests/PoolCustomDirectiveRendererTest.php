<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Magento\PoolCustomDirectiveRenderer;
use Magento\Framework\Filter\DirectiveProcessor\Filter\FilterPool;
use Magento\Framework\Filter\DirectiveProcessor\FilterInterface;
use Magento\Framework\Filter\SimpleDirective\ProcessorInterface;
use Magento\Framework\Filter\SimpleDirective\ProcessorPool;
use PHPUnit\Framework\TestCase;

/**
 * {{mydir}} through the store's own ProcessorPool.
 *
 * Reproduces `SimpleDirective::process()` rather than reimplementing it, so the expectations
 * here are the filter's. Each was measured against a store running Magento's own
 * TestModuleSimpleTemplateDirective, whose processor returns
 * `$value . $parameters['param1'] . $html` with a default filter of `foofilter`.
 */
final class PoolCustomDirectiveRendererTest extends TestCase
{
    private function processor(string $name, ?array $defaults = null): ProcessorInterface
    {
        return new class ($name, $defaults) implements ProcessorInterface {
            public function __construct(private string $name, private ?array $defaults) {}
            public function getName(): string { return $this->name; }
            public function process($value, array $parameters, ?string $html): string
            {
                return $value . ($parameters['param1'] ?? '') . $html;
            }
            public function getDefaultFilters(): ?array { return $this->defaults; }
        };
    }

    private function filters(): FilterPool
    {
        return new FilterPool([
            'foofilter' => new class implements FilterInterface {
                public function getName(): string { return 'foofilter'; }
                public function filterValue(string $value, array $params): string
                {
                    return strtoupper(strrev($value . ($params[0] ?? '')));
                }
            },
        ]);
    }

    /** The pool has no getter, so the names come out by reflection or not at all. */
    public function testTheNamesComeFromThePoolItself(): void
    {
        $renderer = new PoolCustomDirectiveRenderer(
            new ProcessorPool(['mydir' => $this->processor('mydir'), 'other' => $this->processor('other')])
        );

        self::assertSame(['mydir', 'other'], $renderer->names());
    }

    public function testAnEmptyPoolOffersNothing(): void
    {
        self::assertSame([], (new PoolCustomDirectiveRenderer(new ProcessorPool()))->names());
    }

    /** Measured: `{{mydir "v" param1=P}}` renders `PV` on a store with that module. */
    public function testTheProcessorsDefaultFiltersApplyWhenTheTemplateNamesNone(): void
    {
        $renderer = new PoolCustomDirectiveRenderer(
            new ProcessorPool(['mydir' => $this->processor('mydir', ['foofilter'])]),
            $this->filters()
        );

        self::assertSame('PV', $renderer->render('mydir', 'v', ['param1' => 'P'], null, []));
    }

    /**
     * And a template naming ANY modifier suppresses them - measured as `vP`, not `PV`.
     *
     * `{{mydir "v" param1=P|raw}}` therefore comes out UNFILTERED, because `raw` is not a
     * Magento filter and an unknown one is skipped. The modifier that looks like it asks for
     * no escaping is really asking for no default filter, and getting that backwards would
     * silently double-escape or un-escape every custom directive on the store.
     */
    public function testNamingAnyModifierSuppressesTheDefaults(): void
    {
        $renderer = new PoolCustomDirectiveRenderer(
            new ProcessorPool(['mydir' => $this->processor('mydir', ['foofilter'])]),
            $this->filters()
        );

        self::assertSame('vP', $renderer->render('mydir', 'v', ['param1' => 'P'], null, ['raw']));
        self::assertSame('PV', $renderer->render('mydir', 'v', ['param1' => 'P'], null, ['foofilter']));
    }

    /** Colon-separated arguments reach the filter, as `escape:html` does. */
    public function testAModifiersArgumentsAreSplitOffAndPassed(): void
    {
        $renderer = new PoolCustomDirectiveRenderer(
            new ProcessorPool(['mydir' => $this->processor('mydir')]),
            $this->filters()
        );

        // foofilter reverses `$value . $params[0]`, so the argument shows up in the result.
        self::assertSame('XPV', $renderer->render('mydir', 'v', ['param1' => 'P'], null, ['foofilter:X']));
    }

    /** A body reaches the processor; an EMPTY one reaches it as null, as the filter's test does. */
    public function testAnEmptyBodyIsNullRatherThanEmptyString(): void
    {
        $seen = [];
        $processor = new class ($seen) implements ProcessorInterface {
            public function __construct(private array &$seen) {}
            public function getName(): string { return 'mydir'; }
            public function process($value, array $parameters, ?string $html): string
            {
                $this->seen[] = $html;
                return '';
            }
            public function getDefaultFilters(): ?array { return null; }
        };
        $renderer = new PoolCustomDirectiveRenderer(new ProcessorPool(['mydir' => $processor]));

        $renderer->render('mydir', null, [], '', []);
        $renderer->render('mydir', null, [], 'BODY', []);
        $renderer->render('mydir', null, [], null, []);

        self::assertSame([null, 'BODY', null], $seen);
    }

    /** A name the pool has lost, or a processor that raises, loses the directive, not the render. */
    public function testAFailureYieldsNothingRatherThanRaising(): void
    {
        $renderer = new PoolCustomDirectiveRenderer(new ProcessorPool([
            'boom' => new class implements ProcessorInterface {
                public function getName(): string { return 'boom'; }
                public function process($value, array $parameters, ?string $html): string
                {
                    throw new \RuntimeException('the module is unhappy');
                }
                public function getDefaultFilters(): ?array { return null; }
            },
        ]));

        self::assertNull($renderer->render('nosuch', null, [], null, []));
        self::assertNull($renderer->render('boom', null, [], null, []));
    }

    /** An unknown filter is skipped, exactly as FilterApplier skips one. */
    public function testAnUnknownModifierIsSkippedRatherThanFatal(): void
    {
        $renderer = new PoolCustomDirectiveRenderer(
            new ProcessorPool(['mydir' => $this->processor('mydir')]),
            $this->filters()
        );

        self::assertSame('vP', $renderer->render('mydir', 'v', ['param1' => 'P'], null, ['nosuchfilter']));

        // Skipped, not aborted - so a known filter AFTER the unknown one still runs. With a
        // single unknown filter the two behaviours produce the same string, which is why the
        // assertion above cannot tell them apart on its own.
        self::assertSame(
            'PV',
            $renderer->render('mydir', 'v', ['param1' => 'P'], null, ['nosuchfilter', 'foofilter'])
        );
    }
}
