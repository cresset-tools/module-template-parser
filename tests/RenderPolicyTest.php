<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Context;
use MageOS\TemplateParser\Evaluator;
use MageOS\TemplateParser\HostDirectives;
use MageOS\TemplateParser\HostServices;
use MageOS\TemplateParser\Options;
use MageOS\TemplateParser\Parser;
use MageOS\TemplateParser\PolicyViolation;
use MageOS\TemplateParser\PolicyViolationError;
use MageOS\TemplateParser\Port\BlockRenderer;
use MageOS\TemplateParser\Port\TemplateLoader;
use MageOS\TemplateParser\Port\WidgetRenderer;
use MageOS\TemplateParser\RenderPolicy;
use MageOS\TemplateParser\TemplateEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Per-render capability control.
 *
 * A DI-time allowlist is fixed for the whole application, but capability belongs to the
 * template: a stock transactional email and a merchant-edited CMS block reach the same
 * filter and deserve different trust.
 */
final class RenderPolicyTest extends TestCase
{
    /** @var list<string> */
    private array $built = [];

    private function engine(?TemplateLoader $templates = null): TemplateEngine
    {
        $this->built = [];
        $blocks = new class ($this->built) implements BlockRenderer {
            public function __construct(private array &$built) {}
            public function render(string $class, array $data, string $method): string
            { $this->built[] = $class; return '[' . $class . ']'; }
        };
        $widgets = new class ($this->built) implements WidgetRenderer {
            public function __construct(private array &$built) {}
            public function render(string $type, array $parameters): string
            { $this->built[] = $type; return '[' . $type . ']'; }
        };

        $parser = new Parser();
        $evaluator = new Evaluator();
        HostDirectives::register(
            $evaluator,
            new HostServices(blocks: $blocks, widgets: $widgets, templates: $templates),
            $parser
        );
        return new TemplateEngine($parser, $evaluator);
    }

    // ---------------------------------------------------------------- blocks

    public function testAnAllowedBlockRenders(): void
    {
        $policy = RenderPolicy::unrestricted()->withAllowedBlocks('Vendor\\Ok\\Block');
        $out = $this->engine()->render('{{block class="Vendor\\Ok\\Block"}}', [], null, $policy);

        self::assertSame('[Vendor\\Ok\\Block]', $out);
        self::assertSame(['Vendor\\Ok\\Block'], $this->built);
    }

    /** The important half: a refused class never reaches the renderer at all. */
    public function testARefusedBlockIsNeverConstructed(): void
    {
        $context = new Context([], RenderPolicy::unrestricted()->withAllowedBlocks('Vendor\\Ok\\Block'));
        $out = $this->engine()->render('{{block class="Magento\\Email\\Block\\Adminhtml\\Template\\Preview"}}', [], $context);

        self::assertSame('', $out);
        self::assertSame([], $this->built, 'the renderer must not have been called');

        $violations = $context->violations();
        self::assertCount(1, $violations);
        self::assertSame(PolicyViolation::BLOCK, $violations[0]->kind);
        self::assertSame('Magento\\Email\\Block\\Adminhtml\\Template\\Preview', $violations[0]->name);
    }

    public function testAnEmptyBlockAllowlistRefusesEverything(): void
    {
        $context = new Context([], RenderPolicy::unrestricted()->withAllowedBlocks());
        $this->engine()->render('{{block class="Anything"}}', [], $context);

        self::assertSame([], $this->built);
        self::assertCount(1, $context->violations());
    }

    public function testWidgetsShareTheBlockAllowlist(): void
    {
        $context = new Context([], RenderPolicy::unrestricted()->withAllowedBlocks('Vendor\\Ok\\Widget'));
        $engine = $this->engine();

        self::assertSame('[Vendor\\Ok\\Widget]', $engine->render('{{widget type="Vendor\\Ok\\Widget"}}', [], $context));
        self::assertSame('', $engine->render('{{widget type="Vendor\\Evil\\Widget"}}', [], $context));
        self::assertSame(['Vendor\\Ok\\Widget'], $this->built);
    }

    public function testLeadingBackslashesAreNormalised(): void
    {
        $policy = RenderPolicy::unrestricted()->withAllowedBlocks('\\Vendor\\Ok\\Block');
        $out = $this->engine()->render('{{block class="Vendor\\Ok\\Block"}}', [], null, $policy);
        self::assertSame('[Vendor\\Ok\\Block]', $out);
    }

    // ------------------------------------------------------------ directives

    public function testADirectiveAllowlistRestrictsTheSurface(): void
    {
        $context = new Context(['name' => 'Jan'], RenderPolicy::allowing('var', 'if'));
        $out = $this->engine()->render(
            'Hi {{var name}}{{if name}}!{{/if}}{{block class="X"}}',
            [],
            $context
        );

        self::assertSame('Hi Jan!', $out);
        self::assertSame([], $this->built);
        self::assertSame(PolicyViolation::DIRECTIVE, $context->violations()[0]->kind);
        self::assertSame('block', $context->violations()[0]->name);
    }

    public function testUnrestrictedIsTheDefault(): void
    {
        self::assertSame('[X]', $this->engine()->render('{{block class="X"}}'));
        self::assertSame(['X'], $this->built);
    }

    #[DataProvider('restrictedSurfaces')]
    public function testOnlyPermittedDirectivesRun(array $allowed, string $template, string $expected): void
    {
        $policy = RenderPolicy::allowing(...$allowed);
        self::assertSame($expected, $this->engine()->render($template, ['a' => 1, 'b' => 'B'], null, $policy));
    }

    public static function restrictedSurfaces(): array
    {
        return [
            'var only'        => [['var'], '{{var b}}{{if a}}Y{{/if}}', 'B'],
            'if only'         => [['if'], '{{var b}}{{if a}}Y{{/if}}', 'Y'],
            'none'            => [[], '{{var b}}{{if a}}Y{{/if}}', ''],
            'var and depend'  => [['var', 'depend'], '{{depend a}}{{var b}}{{/depend}}', 'B'],
            'text unaffected' => [[], 'just text', 'just text'],
        ];
    }

    // ------------------------------------------------------------ escalation

    /** A nested template inherits the policy - an include cannot widen it. */
    public function testAnIncludedTemplateCannotEscapeThePolicy(): void
    {
        $loader = new class implements TemplateLoader {
            public function load(string $configPath): ?string
            {
                return 'child:{{block class="Sneaky\\Block"}}';
            }
        };
        $context = new Context([], RenderPolicy::unrestricted()->withAllowedBlocks('Vendor\\Ok\\Block'));

        $out = $this->engine($loader)->render('{{template config_path="x"}}', [], $context);

        self::assertSame('child:', $out);
        self::assertSame([], $this->built, 'the included template must not escape the policy');
        self::assertCount(1, $context->violations());
    }

    public function testViolationsFromAChildAreAbsorbedByTheParent(): void
    {
        $parent = new Context([], RenderPolicy::unrestricted()->withAllowedBlocks());
        $child = $parent->withVariables([]);

        $this->engine()->render('{{block class="X"}}', [], $child);
        $parent->absorb($child);

        self::assertCount(1, $parent->violations());
    }

    // -------------------------------------------------------------- fail-fast

    public function testViolationsCanBeMadeFatal(): void
    {
        $parser = new Parser();
        $options = (new Options())->withFailOnPolicyViolation(true);
        $evaluator = new Evaluator(options: $options);
        HostDirectives::register($evaluator, new HostServices(), $parser);
        $engine = new TemplateEngine(new Parser(options: $options), $evaluator);

        try {
            $engine->render('{{var a}}', ['a' => 1], new Context(['a' => 1], RenderPolicy::allowing('if')));
            self::fail('expected a policy violation');
        } catch (PolicyViolationError $e) {
            self::assertStringContainsString('does not permit directive "var"', $e->getMessage());
            self::assertStringContainsString('RenderPolicy::allowing', $e->getMessage());
        }
    }

    public function testViolationsRecordTheirLocation(): void
    {
        $context = new Context([], RenderPolicy::allowing('var'));
        $this->engine()->render("line one\n{{block class=\"X\"}}", [], $context);

        $violation = $context->violations()[0];
        self::assertSame(2, $violation->line);
        self::assertStringContainsString('line 2', $violation->describe());
    }
}
