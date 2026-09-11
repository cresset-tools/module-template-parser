<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\DirectiveSpec;
use Cresset\TemplateParser\Evaluator;
use Cresset\TemplateParser\HostDirectives;
use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\LegacyIncompatibleError;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\Parser;
use Cresset\TemplateParser\Port\CustomDirectiveRenderer;
use Cresset\TemplateParser\RenderPolicy;
use Cresset\TemplateParser\TemplateEngine;
use PHPUnit\Framework\TestCase;

/**
 * Directives the HOST registers, which Magento calls a SimpleDirective.
 *
 * A module adding `mydir` to `SimpleDirective\ProcessorPool` makes
 * `{{mydir "v" p=1}}body{{/mydir}}` render on that store and nowhere else, so the surface is
 * not knowable in advance. Every expectation here was measured against a store running
 * Magento's own `TestModuleSimpleTemplateDirective` before it was written down.
 */
final class CustomDirectiveTest extends TestCase
{
    /** @var list<array{0:string,1:?string,2:array,3:?string,4:array}> */
    private array $calls = [];

    private function engine(?Options $options = null): TemplateEngine
    {
        $calls = &$this->calls;
        $renderer = new class ($calls) implements CustomDirectiveRenderer {
            /** @param list<array> $calls */
            public function __construct(private array &$calls) {}
            public function names(): array { return ['mydir', 'other']; }
            public function render(string $name, ?string $value, array $parameters, ?string $body, array $modifiers): ?string
            {
                $this->calls[] = [$name, $value, $parameters, $body, $modifiers];
                return sprintf('[%s|%s|%s|%s]', $name, $value ?? 'NULL',
                    json_encode($parameters), $body ?? 'NULL');
            }
        };

        $options ??= Options::compatible();
        $spec = new DirectiveSpec([], [], $renderer->names());
        $evaluator = new Evaluator(spec: $spec, options: $options);
        HostDirectives::register(
            $evaluator,
            new HostServices(customDirectives: $renderer),
            new Parser($spec, $options)
        );

        return new TemplateEngine(new Parser($spec, $options), $evaluator);
    }

    private function render(string $template, array $variables = []): string
    {
        return $this->engine()->render($template, context: new Context($variables, RenderPolicy::unrestricted()));
    }

    /**
     * The same name is legal both paired and unpaired, which is the third directive kind.
     *
     * `SimpleDirective`'s pattern ends `(?:(?P<content>.*?){{\/(?P=directiveName)}})?` - an
     * optional body - so one registration gives a template both shapes. `{{if}}` without its
     * closer is an error and `{{var}}` with one is a stray tag; this is neither.
     */
    public function testOneNameIsLegalBothPairedAndUnpaired(): void
    {
        self::assertSame('[mydir|v|[]|NULL]', $this->render('{{mydir "v"}}'));
        self::assertSame('[mydir|v|[]|BODY]', $this->render('{{mydir "v"}}BODY{{/mydir}}'));
    }

    /** An unpaired one gets null for its body, not '' - the filter keeps those apart. */
    public function testAnUnpairedDirectiveGetsANullBody(): void
    {
        $this->render('{{mydir "v"}}');

        self::assertNull($this->calls[0][3]);
    }

    /**
     * The body arrives RENDERED, because the filter renders it too.
     *
     * `$filter->filter($construction['content'])` - so a processor is handed the resolved
     * value, never the directive text that produced it.
     */
    public function testTheBodyReachesTheHostAlreadyRendered(): void
    {
        self::assertSame('[mydir|NULL|[]|Ada]', $this->render('{{mydir}}{{var name}}{{/mydir}}', ['name' => 'Ada']));
    }

    /** Parameters are tokenized and `$name` values resolved, as extractParameters() does. */
    public function testParametersAreResolvedLikeTheFilterResolvesThem(): void
    {
        $this->render('{{mydir "v" p1=LIT p2=$who}}', ['who' => 'Grace']);

        self::assertSame(['p1' => 'LIT', 'p2' => 'Grace'], $this->calls[0][2]);
    }

    /** The value is passed as WRITTEN - the host decides what it means, not this engine. */
    public function testTheQuotedValueIsPassedThroughUninterpreted(): void
    {
        $this->render('{{mydir "$who"}}', ['who' => 'Grace']);
        self::assertSame('$who', $this->calls[0][1], 'the value must not be resolved here');

        $this->calls = [];
        $this->render("{{mydir 'single'}}");
        self::assertSame('single', $this->calls[0][1]);
    }

    /**
     * Modifiers go to the HOST, including the fact that there were none.
     *
     * Its rule is not this engine's: a template naming any modifier SUPPRESSES the processor's
     * defaults, so `{{mydir "v"|raw}}` applies nothing at all - `raw` is not a Magento filter
     * and an unknown one is skipped. That belongs where the filter registry is, so all this
     * does is report what the template asked for.
     */
    public function testModifiersArePassedToTheHostRatherThanApplied(): void
    {
        $this->render('{{mydir "v" p=1|foofilter|escape:html}}');

        self::assertSame(['foofilter', 'escape:html'], $this->calls[0][4]);
        self::assertSame(['p' => '1'], $this->calls[0][2], 'the modifiers must not leak into the parameters');
    }

    public function testNoModifiersIsAnEmptyList(): void
    {
        $this->render('{{mydir "v"}}');

        self::assertSame([], $this->calls[0][4]);
    }

    /**
     * A name the host did not register is not a directive at all.
     *
     * Which is the behaviour a store without that module has, and the reason the whole surface
     * has to be asked for rather than assumed.
     */
    public function testAnUnregisteredNameIsStillJustText(): void
    {
        self::assertSame('{{nothere "v"}}', $this->render('{{nothere "v"}}'));
        self::assertSame([], $this->calls);
    }

    /**
     * Nesting a custom directive in itself is a legacy FATAL, so it is refused.
     *
     * The body is lazy: the filter pairs the first opener with the FIRST closer, which leaves
     * the outer `{{/mydir}}` stranded, and a stranded closer captures an empty directive name
     * and raises. Measured on a real store, not reasoned about - this engine happily nested it
     * until that measurement said otherwise.
     */
    public function testNestingACustomDirectiveInItselfIsRefused(): void
    {
        $this->expectException(LegacyIncompatibleError::class);
        $this->render('{{mydir "v"}}a{{mydir "w"}}b{{/mydir}}c{{/mydir}}');
    }

    /** And a closer with no opener, for the same reason. */
    public function testAStrandedCloserIsRefused(): void
    {
        $this->expectException(LegacyIncompatibleError::class);
        $this->render('X{{/mydir}}Y');
    }

    /** Two different custom directives nest normally. */
    public function testTwoDifferentCustomDirectivesNest(): void
    {
        self::assertSame(
            '[mydir|a|[]|[other|b|[]|NULL]]',
            $this->render('{{mydir "a"}}{{other "b"}}{{/mydir}}')
        );
    }
}
