<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\DirectiveSpec;
use Cresset\TemplateParser\Evaluator;
use Cresset\TemplateParser\HostDirectives;
use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\Parser;
use Cresset\TemplateParser\Port\BlockRenderer;
use Cresset\TemplateParser\Port\TemplateLoader;
use Cresset\TemplateParser\RenderPolicy;
use Cresset\TemplateParser\TemplateCycleError;
use Cresset\TemplateParser\TemplateEngine;
use Cresset\TemplateParser\UnknownVariableError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * {{template}} in full.
 *
 * Includes are the directive with the most moving parts - a scope, a loader, a policy, three
 * separate bounds and a parser of their own - and the least corpus coverage, since the
 * recorder has no template processor to record against. This covers the semantics directly.
 * The bounds themselves (cycle, depth, budget) are tripwired in GuardTripwireTest and
 * NestingLimitTest; what is here is everything else.
 */
final class TemplateIncludeTest extends TestCase
{
    /** @var string[] paths the loader was asked for, most recent engine first */
    private array $loaded = [];

    /** @param array<string,string> $bodies */
    private function engine(array $bodies, Options $options, ?DirectiveSpec $spec = null): TemplateEngine
    {
        $this->loaded = [];
        $loaded = &$this->loaded;
        $loader = new class ($bodies, $loaded) implements TemplateLoader {
            public function __construct(private array $bodies, private array &$loaded) {}
            public function load(string $path): ?string
            {
                $this->loaded[] = $path;
                return $this->bodies[$path] ?? null;
            }
        };

        $evaluator = new Evaluator(spec: $spec ?? new DirectiveSpec(), options: $options);
        HostDirectives::register($evaluator, new HostServices(templates: $loader));

        return new TemplateEngine(new Parser($spec ?? new DirectiveSpec(), $options), $evaluator);
    }

    private function context(array $variables = [], ?RenderPolicy $policy = null): Context
    {
        return new Context($variables, $policy ?? RenderPolicy::unrestricted());
    }

    // ---------------------------------------------------------------- scope

    /**
     * A directive's own parameters become variables inside the include.
     *
     * Legacy hands the processor array_merge_recursive($parameters, $templateVariables), so
     * `{{template config_path="x" store_hours="9-5"}}` makes store_hours available in x.
     * This engine passed an empty scope, so every such parameter was silently dropped.
     */
    public function testParametersBecomeVariablesInTheInclude(): void
    {
        $engine = $this->engine(['greet' => '[{{var greeting}}]'], Options::compatible());

        self::assertSame(
            '[Hi]',
            $engine->render('{{template config_path="greet" greeting="Hi"}}', context: $this->context(['a' => 1]))
        );
    }

    /** The parent's variables are visible too. */
    public function testTheIncludeSeesTheParentScope(): void
    {
        $engine = $this->engine(['greet' => '[{{var name}}]'], Options::compatible());

        self::assertSame(
            '[Ada]',
            $engine->render('{{template config_path="greet"}}', context: $this->context(['name' => 'Ada']))
        );
    }

    /** config_path is consumed by the directive, not handed on as a variable. */
    public function testConfigPathIsNotVisibleInsideTheInclude(): void
    {
        $engine = $this->engine(['greet' => '[{{var config_path}}]'], Options::compatible());

        self::assertSame(
            '[]',
            $engine->render('{{template config_path="greet"}}', context: $this->context(['a' => 1]))
        );
    }

    /**
     * A parameter named after an existing variable collides, and legacy's merge is recursive.
     *
     * array_merge_recursive turns two scalars under one key into an array of both, so the
     * variable renders as "Array" rather than either value. Reproduced in compatible mode;
     * elsewhere the parameter wins, which is what writing one implies.
     */
    #[DataProvider('collisionModes')]
    public function testAParameterCollidingWithAVariable(string $mode, Options $options, string $expected): void
    {
        $engine = $this->engine(['greet' => '[{{var name}}]'], $options);

        self::assertSame(
            $expected,
            $engine->render('{{template config_path="greet" name="Bob"}}', context: $this->context(['name' => 'Ada'])),
            $mode
        );
    }

    public static function collisionModes(): array
    {
        return [
            'compatible reproduces the merge quirk' => ['compatible', Options::compatible(), '[Array]'],
            'lenient lets the parameter win'        => ['lenient', Options::lenient(), '[Bob]'],
        ];
    }

    /** $-prefixed parameters resolve before they are passed on. */
    public function testDollarPrefixedParametersResolveIntoTheInclude(): void
    {
        $engine = $this->engine(['greet' => '[{{var who}}]'], Options::compatible());

        self::assertSame(
            '[Ada]',
            $engine->render('{{template config_path="greet" who=$name}}', context: $this->context(['name' => 'Ada']))
        );
    }

    // ---------------------------------------------------------------- loading

    public function testTheLoaderReceivesExactlyTheRequestedPath(): void
    {
        $engine = $this->engine(['design/email/header' => 'H'], Options::compatible());

        $engine->render('{{template config_path="design/email/header"}}', context: $this->context(['a' => 1]));

        self::assertSame(['design/email/header'], $this->loaded);
    }

    /** Nested includes render, and each is loaded once per use. */
    public function testIncludesNest(): void
    {
        $engine = $this->engine([
            'outer' => 'A{{template config_path="middle"}}C',
            'middle' => 'B{{template config_path="inner"}}B',
            'inner' => '-',
        ], Options::compatible());

        self::assertSame(
            'AB-BC',
            $engine->render('{{template config_path="outer"}}', context: $this->context(['a' => 1]))
        );
        self::assertSame(['outer', 'middle', 'inner'], $this->loaded);
    }

    /** A cycle through two templates is still a cycle. */
    public function testMutuallyRecursiveIncludesAreRefused(): void
    {
        $engine = $this->engine([
            'a' => 'A{{template config_path="b"}}',
            'b' => 'B{{template config_path="a"}}',
        ], Options::lenient());

        $this->expectException(TemplateCycleError::class);
        $engine->render('{{template config_path="a"}}', context: $this->context());
    }

    // ---------------------------------------------------------------- inheritance

    /** An include cannot widen the policy it was called under. */
    public function testTheIncludeInheritsThePolicy(): void
    {
        $rendered = [];
        $blocks = new class ($rendered) implements BlockRenderer {
            public function __construct(private array &$rendered) {}
            public function render(string $class, array $parameters, string $method): string
            {
                $this->rendered[] = $class;
                return 'RAN';
            }
        };

        $evaluator = new Evaluator(options: Options::lenient());
        $loader = new class implements TemplateLoader {
            public function load(string $path): ?string { return '{{block class="Evil"}}'; }
        };
        HostDirectives::register($evaluator, new HostServices(blocks: $blocks, templates: $loader));
        $engine = new TemplateEngine(new Parser(options: Options::lenient()), $evaluator);

        $context = $this->context([], RenderPolicy::allowing(['template']));
        $out = $engine->render('{{template config_path="child"}}', context: $context);

        self::assertSame('', $out);
        self::assertSame([], $rendered, 'the include escaped the policy');
        self::assertCount(1, $context->violations());
    }

    /** Work deferred inside an include is handed up to the caller. */
    public function testDeferralsInsideAnIncludeReachTheParent(): void
    {
        $engine = $this->engine(['child' => '{{inlinecss file="css/email.css"}}'], Options::lenient());
        $context = $this->context();

        $engine->render('{{template config_path="child"}}', context: $context);

        self::assertCount(1, $context->deferred());
        self::assertSame('css/email.css', $context->deferred()[0]['payload']['file']);
    }

    /** So is a construct the legacy filter could not have rendered. */
    public function testIncompatibilitiesInsideAnIncludeReachTheParent(): void
    {
        $options = Options::compatible()->withRefuseLegacyIncompatible(false);
        $engine = $this->engine(['child' => '{{if a}}{{if b}}x{{/if}}{{/if}}'], $options);
        $context = $this->context(['a' => 1, 'b' => 1]);

        $engine->render('{{template config_path="child"}}', context: $context);

        self::assertNotEmpty($context->incompatibilities());
    }

    /** A diagnostic after an include is positioned in the OUTER source. */
    public function testDiagnosticsReturnToTheOuterSourceAfterAnInclude(): void
    {
        $engine = $this->engine(['child' => 'child body'], Options::strict());

        try {
            $engine->render(
                "one\ntwo\n{{template config_path=\"child\"}}\n{{var nope}}",
                context: $this->context()
            );
            self::fail('expected an UnknownVariableError');
        } catch (UnknownVariableError $e) {
            self::assertSame(4, $e->sourceLine);
        }
    }

    // ---------------------------------------------------------------- output

    /**
     * Include output is directive output, so compatible mode neutralises it.
     *
     * The hardened legacy filter encodes `{{` in whatever a resolved directive produced, and
     * an include is no exception - otherwise a template stored in the database could carry
     * one through an include and have it re-read.
     */
    public function testIncludeOutputIsNeutralisedInCompatibleMode(): void
    {
        $bodies = ['child' => 'X{{var payload}}X'];
        $variables = ['payload' => '{{block class=Evil}}'];

        self::assertSame(
            'X&#123;&#123;block class=Evil}}X',
            $this->engine($bodies, Options::compatible())->render('{{template config_path="child"}}', context: $this->context($variables))
        );
        self::assertSame(
            'X{{block class=Evil}}X',
            $this->engine($bodies, Options::compatible()->withOutputNeutralizer(false))
                ->render('{{template config_path="child"}}', context: $this->context($variables))
        );
    }
}
