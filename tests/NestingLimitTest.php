<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\Evaluator;
use Cresset\TemplateParser\HostDirectives;
use Cresset\TemplateParser\NestingLimitError;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\Parser;
use Cresset\TemplateParser\Port\TemplateLoader;
use Cresset\TemplateParser\RenderPolicy;
use Cresset\TemplateParser\TemplateCycleError;
use Cresset\TemplateParser\TemplateEngine;
use PHPUnit\Framework\TestCase;

final class NestingLimitTest extends TestCase
{
    /** @param string[] $names */
    private function nest(array $names): string
    {
        $open = $close = '';
        foreach ($names as $i => $name) {
            $open .= sprintf('{{%s v%d}}', $name, $i);
            $close = sprintf('{{/%s}}', $name) . $close;
        }
        return $open . 'X' . $close;
    }

    /** @param string[] $names @return array<string,int> */
    private function vars(array $names): array
    {
        $vars = [];
        foreach ($names as $i => $_) {
            $vars['v' . $i] = 1;
        }
        return $vars;
    }

    public function testDefaultLimitIsThree(): void
    {
        self::assertSame(3, (new Options())->maxNestingDepth);
    }

    public function testThreeLevelsIsAllowed(): void
    {
        $names = ['if', 'depend', 'if'];
        self::assertSame('X', (new TemplateEngine())->render($this->nest($names), $this->vars($names)));
    }

    /** The bound is on depth alone - which names are nested does not change it. */
    public function testAnyMixOfNamesNestsToTheLimit(): void
    {
        foreach ([['if','if','if'], ['depend','depend','depend'], ['depend','if','depend']] as $names) {
            self::assertSame('X', (new TemplateEngine())->render($this->nest($names), $this->vars($names)));
        }
    }

    public function testFourLevelsIsRefusedWithAUsefulMessage(): void
    {
        $names = ['depend', 'if', 'if', 'if'];
        try {
            (new TemplateEngine())->render($this->nest($names), $this->vars($names));
            self::fail('expected the nesting limit to be enforced');
        } catch (NestingLimitError $e) {
            self::assertStringContainsString('would be 4 levels deep, limit is 3', $e->getMessage());
            self::assertStringContainsString('enclosing directives are {{depend}} > {{if}} > {{if}}', $e->getMessage());
            self::assertStringContainsString('Options::withMaxNestingDepth()', $e->getMessage());
            self::assertSame(1, $e->sourceLine);
        }
    }

    public function testTheLimitIsConfigurable(): void
    {
        $names = ['if', 'if', 'if', 'if', 'if'];
        $engine = TemplateEngine::withOptions(Options::strict()->withMaxNestingDepth(5));
        self::assertSame('X', $engine->render($this->nest($names), $this->vars($names)));
    }

    public function testLimitOfOneAllowsNoNesting(): void
    {
        $engine = TemplateEngine::withOptions(Options::strict()->withMaxNestingDepth(1));
        self::assertSame('X', $engine->render('{{if a}}X{{/if}}', ['a' => 1]));

        $this->expectException(NestingLimitError::class);
        $engine->render('{{if a}}{{if b}}X{{/if}}{{/if}}', ['a' => 1, 'b' => 1]);
    }

    public function testZeroOrNegativeLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Options(maxNestingDepth: 0);
    }

    /**
     * The bound applies in lenient mode too. It is a limit on input complexity, not a
     * strictness setting: deeply nested input is refused rather than recovered.
     */
    public function testTheLimitAppliesInLenientModeAsWell(): void
    {
        $names = ['if', 'if', 'if', 'if'];
        $this->expectException(NestingLimitError::class);
        TemplateEngine::lenient()->render($this->nest($names), $this->vars($names));
    }

    /** Void directives do not consume nesting budget. */
    public function testOnlyBlockDirectivesCount(): void
    {
        $out = (new TemplateEngine())->render(
            '{{if a}}{{if b}}{{if c}}{{var x}}{{/if}}{{/if}}{{/if}}',
            ['a' => 1, 'b' => 1, 'c' => 1, 'x' => 'v']
        );
        self::assertSame('v', $out);
    }

    // ------------------------------------------------------------------
    // Per-render override
    // ------------------------------------------------------------------

    public function testThePolicyCanRaiseTheBoundForOneRender(): void
    {
        $names = ['if', 'depend', 'if', 'depend'];
        $policy = RenderPolicy::restricted()->withMaxNestingDepth(4);

        self::assertSame(
            'X',
            (new TemplateEngine())->render($this->nest($names), $this->vars($names), null, $policy)
        );
    }

    public function testThePolicyCanLowerTheBoundForOneRender(): void
    {
        $names = ['if', 'depend'];
        $policy = RenderPolicy::restricted()->withMaxNestingDepth(1);

        $this->expectException(NestingLimitError::class);
        (new TemplateEngine())->render($this->nest($names), $this->vars($names), null, $policy);
    }

    public function testTheOverrideAppliesOnlyToThatRender(): void
    {
        $names = ['if', 'depend', 'if', 'depend'];
        $engine = new TemplateEngine();

        // Raised for this one.
        self::assertSame('X', $engine->render(
            $this->nest($names),
            $this->vars($names),
            null,
            RenderPolicy::restricted()->withMaxNestingDepth(4)
        ));

        // Back to the engine default of 3 for the next.
        $this->expectException(NestingLimitError::class);
        $engine->render($this->nest($names), $this->vars($names));
    }

    public function testTheErrorReportsTheEffectiveBoundNotTheEngineDefault(): void
    {
        $names = ['if', 'depend', 'if'];
        try {
            (new TemplateEngine())->render(
                $this->nest($names),
                $this->vars($names),
                null,
                RenderPolicy::restricted()->withMaxNestingDepth(2)
            );
            self::fail('expected the per-render bound to be enforced');
        } catch (NestingLimitError $e) {
            self::assertStringContainsString('would be 3 levels deep, limit is 2', $e->getMessage());
        }
    }

    public function testNullLeavesTheEngineDefaultInForce(): void
    {
        $names = ['if', 'depend', 'if'];
        $policy = RenderPolicy::restricted()->withMaxNestingDepth(null);

        self::assertNull($policy->maxNestingDepth());
        self::assertSame('X', (new TemplateEngine())->render($this->nest($names), $this->vars($names), null, $policy));
    }

    public function testAnInvalidOverrideIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RenderPolicy::restricted()->withMaxNestingDepth(0);
    }

    /** The override survives the other withers. */
    public function testTheOverrideIsCarriedThroughPolicyChanges(): void
    {
        $policy = RenderPolicy::restricted()
            ->withMaxNestingDepth(4)
            ->alsoAllowing(['block'])
            ->withAllowedBlocks(['X'])
            ->withAllowedDirectives(['var', 'if', 'depend']);

        self::assertSame(4, $policy->maxNestingDepth());
    }

    /** An included template inherits the render's bound, not the engine default. */
    public function testIncludedTemplatesInheritTheOverride(): void
    {
        $loader = new class implements TemplateLoader {
            public function load(string $configPath): ?string
            {
                return '{{if a}}{{depend a}}{{if a}}{{depend a}}deep{{/depend}}{{/if}}{{/depend}}{{/if}}';
            }
        };
        $parser = new Parser();
        $evaluator = new Evaluator();
        HostDirectives::register($evaluator, new \Cresset\TemplateParser\HostServices(templates: $loader), $parser);
        $engine = new TemplateEngine($parser, $evaluator);

        $context = new Context(['a' => 1], RenderPolicy::restricted()->withMaxNestingDepth(4));
        self::assertSame('deep', $engine->render('{{template config_path="x"}}', [], $context));

        // Without the override the same include exceeds the default of 3.
        $this->expectException(NestingLimitError::class);
        $engine->render('{{template config_path="x"}}', [], new Context(['a' => 1]));
    }

    /** A template that includes itself is a cycle, not infinite recursion. */
    public function testSelfIncludingTemplateIsRefused(): void
    {
        $loader = new class implements TemplateLoader {
            public function load(string $configPath): ?string
            {
                return '{{template config_path="loop"}}';
            }
        };
        $parser = new Parser();
        $evaluator = new Evaluator();
        HostDirectives::register($evaluator, new \Cresset\TemplateParser\HostServices(templates: $loader), $parser);
        $engine = new TemplateEngine($parser, $evaluator);

        $this->expectException(TemplateCycleError::class);
        $this->expectExceptionMessageMatches('/includes itself/');
        $engine->render('{{template config_path="loop"}}', [], new Context());
    }

    /** Including the same template twice in sequence is fine; only a cycle is refused. */
    public function testSiblingIncludesOfTheSameTemplateAreAllowed(): void
    {
        $loader = new class implements TemplateLoader {
            public function load(string $configPath): ?string { return '[' . $configPath . ']'; }
        };
        $parser = new Parser();
        $evaluator = new Evaluator();
        HostDirectives::register($evaluator, new \Cresset\TemplateParser\HostServices(templates: $loader), $parser);
        $engine = new TemplateEngine($parser, $evaluator);

        $out = $engine->render(
            '{{template config_path="a"}}{{template config_path="a"}}',
            [],
            new Context()
        );
        self::assertSame('[a][a]', $out);
    }
}
