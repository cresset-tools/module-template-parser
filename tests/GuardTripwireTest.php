<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\DirectiveSpec;
use Cresset\TemplateParser\Evaluator;
use Cresset\TemplateParser\HostDirectives;
use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\Lexer\Lexer;
use Cresset\TemplateParser\Lexer\TokenType;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\Parser;
use Cresset\TemplateParser\PathGuard;
use Cresset\TemplateParser\PolicyViolationError;
use Cresset\TemplateParser\Port\BlockRenderer;
use Cresset\TemplateParser\Port\TemplateLoader;
use Cresset\TemplateParser\RenderPolicy;
use Cresset\TemplateParser\SyntaxError;
use Cresset\TemplateParser\TemplateCycleError;
use Cresset\TemplateParser\TemplateEngine;
use Cresset\TemplateParser\TemplateError;
use Cresset\TemplateParser\UnknownVariableError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One test per guard that a mutation run could delete with the suite still green.
 *
 * A guard nobody asserts on is a guard that gets "simplified" away later, so each of these
 * names the specific single-line deletion it is here to catch. Every case was verified to
 * FAIL against the corresponding mutant before being committed.
 */
final class GuardTripwireTest extends TestCase
{
    // ---------------------------------------------------------------- escaping

    /** Deleting the pre-escape in applyModifiers, or making any modifier opt out of it. */
    #[DataProvider('unknownModifiers')]
    public function testAnUnknownModifierCannotDisableEscaping(string $template): void
    {
        foreach (['strict' => Options::strict(), 'lenient' => Options::lenient()] as $mode => $options) {
            self::assertSame(
                '&lt;script&gt;x&lt;/script&gt;',
                TemplateEngine::withOptions($options)->render($template, ['x' => '<script>x</script>']),
                $mode . ' must escape despite ' . $template
            );
        }
    }

    public static function unknownModifiers(): array
    {
        return [
            'typo'                => ['{{var x|typo}}'],
            'empty'               => ['{{var x|}}'],
            'unknown escape type' => ['{{var x|escape:none}}'],
            'empty escape type'   => ['{{var x|escape:}}'],
            'typo then escape'    => ['{{var x|typo|escape:zz}}'],
            'unknown then nl2br'  => ['{{var x|typo|nl2br}}'],
        ];
    }

    /** Only `raw` and a real escape type may opt out - and `raw` still must. */
    public function testRawIsTheDocumentedWayOut(): void
    {
        self::assertSame(
            '<script>x</script>',
            TemplateEngine::withOptions(Options::strict())->render('{{var x|raw}}', ['x' => '<script>x</script>'])
        );
    }

    /** Dropping ENT_SUBSTITUTE makes htmlspecialchars/htmlentities return '' for invalid UTF-8. */
    #[DataProvider('escapeTypesThatMustSurviveBadUtf8')]
    public function testInvalidUtf8IsSubstitutedNotDeleted(string $template): void
    {
        $out = TemplateEngine::withOptions(Options::lenient())->render($template, ['x' => "ok\xC3\x28bad"]);

        self::assertNotSame('', $out, 'the whole value was silently deleted');
        self::assertStringContainsString('ok', $out);
        self::assertStringContainsString('bad', $out);
    }

    public static function escapeTypesThatMustSurviveBadUtf8(): array
    {
        return [
            'default'      => ['{{var x}}'],
            'escape'       => ['{{var x|escape}}'],
            'escape:html'  => ['{{var x|escape:html}}'],
            'htmlentities' => ['{{var x|escape:htmlentities}}'],
        ];
    }

    /** The htmlentities and url arms of escape() - both were removable. */
    public function testEachEscapeTypeActuallyEscapes(): void
    {
        $engine = TemplateEngine::withOptions(Options::lenient());

        self::assertSame('a%2Fb%20%26c', $engine->render('{{var x|escape:url}}', ['x' => 'a/b &c']));
        self::assertSame(
            '&lt;b&gt;&amp;auml;&lt;/b&gt;',
            $engine->render('{{var x|escape:htmlentities}}', ['x' => '<b>&auml;</b>'])
        );
    }

    /** double_encode must stay off: an already-escaped entity is not escaped twice. */
    public function testEscapingDoesNotDoubleEncode(): void
    {
        self::assertSame(
            'Tom &amp; Jerry',
            TemplateEngine::withOptions(Options::lenient())->render('{{var x}}', ['x' => 'Tom &amp; Jerry'])
        );
    }

    // ---------------------------------------------------------------- policy scope

    /** Context::withVariables must carry the policy, or every child scope escapes it. */
    public function testAChildScopeCannotEscapeThePolicy(): void
    {
        $templates = new class implements TemplateLoader {
            public function load(string $path): ?string
            {
                return $path === 'child' ? '{{var leak}}' : null;
            }
        };

        $evaluator = new Evaluator(options: Options::lenient());
        HostDirectives::register($evaluator, new HostServices(templates: $templates));
        $engine = new TemplateEngine(new Parser(options: Options::lenient()), $evaluator);

        $context = new Context(
            ['leak' => 'XLEAKY'],
            RenderPolicy::allowing(['template'])
        );
        $out = $engine->render('{{template config_path="child"}}', context: $context);

        self::assertStringNotContainsString('XLEAKY', $out, 'the include escaped the parent policy');
        self::assertCount(1, $context->violations());
    }

    /** {{for}} must absorb its child scope, or a loop hides every violation inside it. */
    public function testALoopCannotHideAViolation(): void
    {
        $evaluator = new Evaluator(options: Options::lenient());
        HostDirectives::register($evaluator, new HostServices(blocks: new class implements BlockRenderer {
            public function render(string $class, array $parameters, string $method): string
            {
                return 'RAN';
            }
        }));
        $engine = new TemplateEngine(new Parser(options: Options::lenient()), $evaluator);

        $context = new Context(['xs' => [1, 2]], RenderPolicy::allowing(['for']));
        $engine->render('{{for i in xs}}{{block class="Evil\\Block"}}{{/for}}', context: $context);

        self::assertCount(2, $context->violations(), 'violations inside a loop were dropped');
    }

    /** Context::absorb must carry incompatibilities, not only deferrals and violations. */
    public function testAnIncludeReportsItsIncompatibilitiesToTheParent(): void
    {
        $templates = new class implements TemplateLoader {
            public function load(string $path): ?string
            {
                return $path === 'child' ? '{{if a}}{{if b}}x{{/if}}{{/if}}' : null;
            }
        };

        $options = Options::compatible()->withRefuseLegacyIncompatible(false);
        $evaluator = new Evaluator(options: $options);
        HostDirectives::register($evaluator, new HostServices(templates: $templates));
        $engine = new TemplateEngine(new Parser(options: $options), $evaluator);

        $context = new Context(['a' => 1, 'b' => 1], RenderPolicy::unrestricted());
        $engine->render('{{template config_path="child"}}', context: $context);

        self::assertNotEmpty($context->incompatibilities());
    }

    /** A denied directive must not reach the output unrecorded on the verbatim path. */
    public function testCompatibleModeRecordsRefusalsItStillEmitsVerbatim(): void
    {
        $evaluator = new Evaluator(options: Options::compatible());
        HostDirectives::register($evaluator, new HostServices(blocks: new class implements BlockRenderer {
            public function render(string $class, array $parameters, string $method): string
            {
                return 'RAN';
            }
        }));
        $engine = new TemplateEngine(new Parser(options: Options::compatible()), $evaluator);

        $context = new Context([], RenderPolicy::allowing([]));
        $out = $engine->render('{{if x}}{{block class="Evil\\Block"}}{{/if}}', context: $context);

        self::assertStringContainsString('{{block', $out, 'parity: the construction is emitted as written');
        self::assertNotEmpty($context->violations(), 'but the refusal must still be recorded');
    }

    /** render() must refuse a context AND loose variables rather than dropping either. */
    public function testRenderRefusesAmbiguousArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TemplateEngine())->render('{{var x}}', ['x' => 1], new Context(['x' => 2]));
    }

    // ---------------------------------------------------------------- resolver

    /** Every reflection guard in invokeAccessor, one test each. */
    #[DataProvider('unreachableMethods')]
    public function testOnlyPublicNonStaticArglessAccessorsAreCallable(string $expression, string $marker): void
    {
        $object = new class {
            public static function getFactory(): string { return 'STATIC-REACHED'; }
            protected function getSecret(): string { return 'PROTECTED-REACHED'; }
            public function getWithArg(string $x): string { return 'ARG-REACHED'; }
            public function getOk(): string { return 'OK'; }
        };

        $out = TemplateEngine::withOptions(Options::lenient())->render('[{{var o.' . $expression . '}}]', ['o' => $object]);

        self::assertSame('[]', $out, $marker . ' was reachable from template text');
    }

    public static function unreachableMethods(): array
    {
        return [
            'static'    => ['getFactory()', 'a static factory'],
            'protected' => ['getSecret()', 'a protected method'],
            'arguments' => ['getWithArg()', 'a method requiring arguments'],
        ];
    }

    public function testAnOrdinaryAccessorStillWorks(): void
    {
        $object = new class {
            public function getOk(): string { return 'OK'; }
        };
        self::assertSame(
            '[OK]',
            TemplateEngine::withOptions(Options::lenient())->render('[{{var o.getOk()}}]', ['o' => $object])
        );
    }

    /** getData must be public, non-static, and able to take a key. */
    #[DataProvider('unusableDataBags')]
    public function testOnlyAUsableDataBagIsWalked(object $object, string $marker): void
    {
        $out = TemplateEngine::withOptions(Options::lenient())->render('[{{var o.title}}]', ['o' => $object]);

        self::assertSame('[REAL]', $out, $marker);
    }

    public static function unusableDataBags(): array
    {
        return [
            'static getData' => [new class {
                public static function getData($k = null) { return 'STATIC-BAG'; }
                public function getTitle(): string { return 'REAL'; }
            }, 'a static getData was used as a bag'],
            'getData taking no key' => [new class {
                public function getData() { return ['title' => 'BAG']; }
                public function getTitle(): string { return 'REAL'; }
            }, 'a zero-parameter getData returned the whole bag for a member'],
            'getData rejecting a string key' => [new class {
                public function getData(int $i = 0): array { return []; }
                public function getTitle(): string { return 'REAL'; }
            }, 'getData(int) should not have been called with a string key'],
        ];
    }

    /** A private getData must not be called at all. */
    public function testAPrivateDataBagIsNotReachable(): void
    {
        $object = new class {
            private function getData($k = null) { return 'PRIVATE-BAG'; }
        };

        self::assertSame(
            '[]',
            TemplateEngine::withOptions(Options::lenient())->render('[{{var o.title}}]', ['o' => $object])
        );
    }

    /** The getFooBar() -> getData('foo_bar') mapping, including legacy's digit rule. */
    #[DataProvider('getterKeyMappings')]
    public function testGetterNamesMapToDataKeysTheWayLegacyDoes(string $call, string $expected): void
    {
        $bag = new \FakeDataObject([
            'first_name' => 'Ada',
            'address_1'  => 'A1',
            'url_key'    => 'UK',
        ]);

        self::assertSame(
            '[' . $expected . ']',
            TemplateEngine::withOptions(Options::compatible())->render('[{{var o.' . $call . '}}]', ['o' => $bag])
        );
    }

    public static function getterKeyMappings(): array
    {
        return [
            'camel'          => ['getFirstName()', 'Ada'],
            'trailing digit' => ['getAddress1()', 'A1'],
            'acronym'        => ['getUrlKey()', 'UK'],
            'ignored args'   => ['getFirstName("x")', 'Ada'],
        ];
    }

    /** array_key_exists, not isset: a key holding null exists. */
    public function testAnArrayKeyHoldingNullIsFoundNotMissing(): void
    {
        // Strict mode would raise UnknownVariableError if the key read as missing.
        self::assertSame(
            '[]',
            TemplateEngine::withOptions(Options::strict())->render('[{{var a.k}}]', ['a' => ['k' => null]])
        );
    }

    /** ...and a member the bag does not hold must still be reported. */
    public function testStrictModeStillReportsAMissingDataBagMember(): void
    {
        $this->expectException(UnknownVariableError::class);
        TemplateEngine::withOptions(Options::strict())
            ->render('{{var o.nope}}', ['o' => new \FakeDataObject(['a' => 1])]);
    }

    /** A host accessor that raises must not escape the render as a host exception. */
    public function testAnAccessorExceptionIsReportedAsATemplateError(): void
    {
        $object = new class {
            public function getBoom(): string
            {
                throw new \RuntimeException('accessor blew up: /etc/secret');
            }
        };

        try {
            TemplateEngine::withOptions(Options::lenient())->render('{{var o.boom}}', ['o' => $object]);
            self::fail('expected a TemplateError');
        } catch (TemplateError $e) {
            self::assertStringNotContainsString('/etc/secret', $e->getMessage());
            self::assertStringContainsString('getBoom', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- PathGuard

    /** The decode loop must run more than once. */
    #[DataProvider('multiplyEncodedPaths')]
    public function testRepeatedlyEncodedTraversalIsRefused(string $path): void
    {
        self::assertFalse(PathGuard::isSafeRelativePath($path));
    }

    public static function multiplyEncodedPaths(): array
    {
        return [
            'single'       => ['%2e%2e/etc/passwd'],
            'double'       => ['%252e%252e/etc/passwd'],
            'triple'       => ['%25252e%25252e/etc/passwd'],
            'encoded null' => ['logo%2500.php'],
            'null between' => ['..%00/..%00/x'],
            'dot run'      => ['....//etc/passwd'],
            'inner run'    => ['a/....//b'],
            'leading space absolute' => [' //evil.example'],
            'leading space scheme'   => [' javascript:alert(1)'],
            'leading space traversal' => [' ../etc/passwd'],
            'trailing space'         => ['/etc/passwd '],
        ];
    }

    /**
     * Surrounding whitespace is refused on its own terms.
     *
     * The stripped-form check happens to catch the dangerous cases anyway, so without this
     * the outright rejection reads as removable - and then a value the guard passed no
     * longer matches the value the browser fetches.
     */
    public function testSurroundingWhitespaceIsRefusedOutright(): void
    {
        self::assertTrue(PathGuard::isSafeRelativePath('logo.png'));
        self::assertFalse(PathGuard::isSafeRelativePath(' logo.png'));
        self::assertFalse(PathGuard::isSafeRelativePath('logo.png '));
        self::assertFalse(PathGuard::isSafeRelativePath("logo.png\t"));
    }

    public function testOrdinaryPathsRemainSafe(): void
    {
        foreach (['logo.png', 'a/b/c.jpg', 'my file.jpg', 'a.b/c-d_e.png'] as $path) {
            self::assertTrue(PathGuard::isSafeRelativePath($path), $path . ' should be safe');
        }
    }

    // ---------------------------------------------------------------- includes

    /** The include DEPTH bound, on a chain of distinct paths that cycle detection cannot see. */
    public function testDistinctIncludesAreStillBounded(): void
    {
        $templates = new class implements TemplateLoader {
            public function load(string $path): ?string
            {
                return '{{template config_path="' . $path . 'x"}}';   // always a new name
            }
        };

        $evaluator = new Evaluator(options: Options::lenient());
        HostDirectives::register($evaluator, new HostServices(templates: $templates));
        $engine = new TemplateEngine(new Parser(options: Options::lenient()), $evaluator);

        $context = new Context([], RenderPolicy::unrestricted());
        try {
            $engine->render('{{template config_path="a"}}', context: $context);
            self::fail('an unbounded chain of distinct includes was allowed');
        } catch (TemplateCycleError $e) {
            // Specifically the DEPTH bound, not the budget catching it much later: the two
            // are separate guards and each has to be able to fail on its own.
            self::assertStringContainsString('deep', $e->getMessage());
            self::assertLessThanOrEqual(
                Options::DEFAULT_MAX_INCLUDE_DEPTH,
                $context->includesSpent(),
                'the depth bound did not fire; something else stopped the chain'
            );
        }
    }

    /** The include BUDGET: depth alone does not bound fan-out. */
    public function testTotalIncludesAreBounded(): void
    {
        $templates = new class implements TemplateLoader {
            public function load(string $path): ?string
            {
                return match ($path) {
                    'a' => str_repeat('{{template config_path="b"}}', 12),
                    'b' => str_repeat('{{template config_path="c"}}', 12),
                    'c' => 'x',
                    default => null,
                };
            }
        };

        $evaluator = new Evaluator(options: Options::lenient());
        HostDirectives::register($evaluator, new HostServices(templates: $templates));
        $engine = new TemplateEngine(new Parser(options: Options::lenient()), $evaluator);

        $context = new Context([], RenderPolicy::unrestricted());
        try {
            $engine->render('{{template config_path="a"}}', context: $context);
            self::fail('expected the include budget to stop this');
        } catch (TemplateCycleError $e) {
            self::assertStringContainsString('budget', $e->getMessage());
            self::assertLessThanOrEqual(Options::DEFAULT_MAX_INCLUDES, $context->includesSpent());
        }
    }

    /** config_path is guarded like {{config path=}} is. */
    #[DataProvider('unsafeConfigPaths')]
    public function testTemplateIncludePathsAreGuarded(string $path): void
    {
        $reached = [];
        $templates = new class ($reached) implements TemplateLoader {
            public function __construct(private array &$reached) {}
            public function load(string $path): ?string
            {
                $this->reached[] = $path;
                return 'LOADED';
            }
        };

        $evaluator = new Evaluator(options: Options::lenient());
        HostDirectives::register($evaluator, new HostServices(templates: $templates));
        $engine = new TemplateEngine(new Parser(options: Options::lenient()), $evaluator);

        $out = $engine->render(
            '{{template config_path="' . $path . '"}}',
            context: new Context([], RenderPolicy::unrestricted())
        );

        self::assertSame('', $out);
        self::assertSame([], $reached, 'the path reached the loader');
    }

    public static function unsafeConfigPaths(): array
    {
        return [
            'traversal' => ['../../app/etc/env.php'],
            'scheme'    => ['php://filter/x'],
            'absolute'  => ['/etc/passwd'],
            'dotted'    => ['a.b'],
        ];
    }

    /** The included template must be parsed with the host's spec, not a default one. */
    public function testAnIncludedTemplateSeesTheHostsDirectiveSpec(): void
    {
        $spec = new DirectiveSpec(extraBlocks: ['panel' => false]);
        $templates = new class implements TemplateLoader {
            public function load(string $path): ?string
            {
                return '{{panel}}inner{{/panel}}';
            }
        };

        $evaluator = new Evaluator(spec: $spec, options: Options::lenient());
        $evaluator->register('panel', static fn ($n, $c, Evaluator $e): string
            => '[PANEL:' . $e->renderNodes($n->children(), $c) . ']');
        HostDirectives::register($evaluator, new HostServices(templates: $templates));
        $engine = new TemplateEngine(new Parser($spec, Options::lenient()), $evaluator);

        $out = $engine->render('{{template config_path="child"}}', context: new Context([], RenderPolicy::unrestricted()));

        self::assertSame('[PANEL:inner]', $out);
        self::assertStringNotContainsString('{{/panel}}', $out, 'the closing tag leaked as text');
    }

    /** Error positions must return to the outer source after an include. */
    public function testDiagnosticsPointAtTheOuterSourceAfterAnInclude(): void
    {
        $templates = new class implements TemplateLoader {
            public function load(string $path): ?string { return 'child body'; }
        };

        $evaluator = new Evaluator(options: Options::strict());
        HostDirectives::register($evaluator, new HostServices(templates: $templates));
        $engine = new TemplateEngine(new Parser(options: Options::strict()), $evaluator);

        $source = "line one\nline two\n{{template config_path=\"child\"}}\n{{var nope}}";
        try {
            $engine->render($source, context: new Context([], RenderPolicy::unrestricted()));
            self::fail('expected an UnknownVariableError');
        } catch (UnknownVariableError $e) {
            self::assertSame(4, $e->sourceLine, 'the position came from the included source');
        }
    }

    // ---------------------------------------------------------------- lexer

    /**
     * Every guard that keeps the lexer cheap on brace-dense input.
     *
     * Timing is expressed in units of a calibration loop measured on the same machine in the
     * same run, so the budget means "this much work relative to a plain substr", not "this
     * many seconds on the author's laptop". Measured with each guard removed in turn:
     * baseline 8-17 units, an unbounded peek window 78-160, and a missing close-tag
     * rejection 2128. A budget of 40 separates them with room to spare in both directions.
     *
     * A ratio-of-two-sizes test cannot do this job: an unbounded window is a constant-factor
     * regression, not an asymptotic one, so it keeps a linear-looking 4x while being 10x
     * slower everywhere.
     */
    #[DataProvider('braceDenseShapes')]
    public function testBraceDenseInputStaysWithinBudget(string $unit, string $tail): void
    {
        $calibration = self::calibrationUnit();

        $source = str_repeat($unit, 200000) . $tail;
        $started = microtime(true);
        (new Lexer())->tokenize($source);
        $elapsed = microtime(true) - $started;

        self::assertLessThan(
            40 * $calibration,
            $elapsed,
            sprintf(
                '%s: %d bytes took %.1f calibration units (budget 40) - a lexer guard is gone',
                $unit,
                strlen($source),
                $elapsed / $calibration
            )
        );
    }

    public static function braceDenseShapes(): array
    {
        return [
            // Each of these is a 4-6 byte unit that reaches a different rejection. The
            // trailing }} matters: without a closer anywhere the lexer stops at the first
            // {{ and none of this is exercised.
            'name then brace'   => ['{{a}', '}}'],
            'close then junk'   => ['{{/a}x', '}}'],
            'name run-on'       => ['{{A', '}}'],
            'known name run-on' => ['{{var', '}}'],
        ];
    }

    /** Time for a fixed amount of non-lexer work, as a machine-speed reference. */
    private static function calibrationUnit(): float
    {
        $blob = str_repeat('x', 200000);
        $started = microtime(true);
        for ($i = 0; $i < 200000; $i++) {
            substr($blob, $i % 1000, 64);
        }

        return max(microtime(true) - $started, 1e-6);
    }

    /** A source ending in a bare `{{` must not warn or reclassify. */
    public function testASourceEndingInAnOpenBraceIsText(): void
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            self::assertSame('abc{{', TemplateEngine::withOptions(Options::lenient())->render('abc{{'));
        } finally {
            restore_error_handler();
        }
        self::assertSame([], $warnings, 'lexing a trailing {{ raised a PHP diagnostic');

        $tokens = (new Lexer())->tokenize('abc{{');
        foreach ($tokens as $token) {
            self::assertSame(TokenType::Text, $token->type);
        }
    }

    /** Closing tags are only recognised for names the spec knows, case-insensitively. */
    public function testAnUnknownUppercaseClosingTagStaysText(): void
    {
        $tokens = (new Lexer())->tokenize('x{{/ZZZ}}y');
        foreach ($tokens as $token) {
            self::assertSame(TokenType::Text, $token->type, 'an unknown closing tag became a directive');
        }

        // ...while a known one still closes, whatever its case.
        $known = (new Lexer())->tokenize('{{if a}}x{{/IF}}');
        self::assertSame(TokenType::DirectiveClose, $known[array_key_last($known)]->type);
    }

    /** Directive names are length-bounded. */
    public function testAnOverlongNameIsNotADirective(): void
    {
        $name = str_repeat('a', 40);
        foreach ([sprintf('{{%s x}}', $name), sprintf('{{/%s}}', $name)] as $source) {
            foreach ((new Lexer())->tokenize($source) as $token) {
                self::assertSame(TokenType::Text, $token->type, $source . ' lexed as a directive');
            }
        }
    }

    /** A name that does not end where the window thought it did is not a directive. */
    #[DataProvider('nearMissConstructs')]
    public function testNearMissConstructsStayText(string $source): void
    {
        self::assertSame(
            $source,
            TemplateEngine::withOptions(Options::lenient())->render($source, ['x' => 'V']),
            $source . ' was reinterpreted'
        );
    }

    public static function nearMissConstructs(): array
    {
        return [
            'params run on'     => ['a{{var}x}}b'],
            'close with params' => ['a{{/if x}}b'],
            'name into brace'   => ['a{{var{{if}}b'],
        ];
    }

    // ---------------------------------------------------------------- parser

    /** A closing tag must match its opener by name. */
    public function testAClosingTagOnlyClosesItsOwnName(): void
    {
        $source = '{{if a}}{{depend b}}X{{/if}}Y{{/depend}}';
        $rendered = TemplateEngine::withOptions(Options::lenient())
            ->render($source, ['a' => 1, 'b' => 1]);

        // {{/if}} closes the {{if}} that is open further out, so {{depend}} never closes and
        // survives verbatim; Y is outside it. If a closing tag could close whatever happens
        // to be innermost, this would render as 'XY' instead.
        self::assertSame('{{depend b}}XY{{/depend}}', $rendered);
    }

    /** Parser::parse validates its own nesting argument, not just Options. */
    #[DataProvider('badNestingLimits')]
    public function testParseRejectsANonsenseNestingLimit(int $limit): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Parser())->parse('{{var a}}', $limit);
    }

    public static function badNestingLimits(): array
    {
        return ['zero' => [0], 'negative' => [-5]];
    }

    /** A second {{else}} in one {{if}} is a mistake, not a second branch. */
    public function testASecondElseIsReported(): void
    {
        $this->expectException(SyntaxError::class);
        TemplateEngine::withOptions(Options::strict())
            ->render('{{if x}}A{{else}}B{{else}}C{{/if}}', ['x' => false]);
    }

    /** {{else}} round-trips exactly as written. */
    #[DataProvider('elseForms')]
    public function testElseRoundTripsVerbatim(string $source): void
    {
        self::assertSame($source, (new Parser(options: Options::lenient()))->parse($source)->raw());
    }

    public static function elseForms(): array
    {
        return [
            'plain'      => ['{{if x}}a{{else}}b{{/if}}'],
            'spaced'     => ['{{if x}}a{{else }}b{{/if}}'],
            'uppercase'  => ['{{IF x}}a{{ELSE}}b{{/IF}}'],
        ];
    }

    // ---------------------------------------------------------------- evaluator misc

    /** A malformed loop header is not silently an empty string. */
    #[DataProvider('malformedLoopHeaders')]
    public function testAMalformedLoopHeaderIsReported(string $source): void
    {
        $this->expectException(SyntaxError::class);
        TemplateEngine::withOptions(Options::strict())->render($source, ['xs' => [1]]);
    }

    public static function malformedLoopHeaders(): array
    {
        return [
            'no in'        => ['{{for i xs}}x{{/for}}'],
            'empty'        => ['{{for}}x{{/for}}'],
            'no item'      => ['{{for in xs}}x{{/for}}'],
            'trailing'     => ['{{for i in xs extra}}x{{/for}}'],
            'uppercase in' => ['{{for i IN xs}}x{{/for}}'],
        ];
    }

    /** An exhausted Generator is a TemplateError, not a bare PHP Exception. */
    public function testAConsumedGeneratorIsReportedAsATemplateError(): void
    {
        $generator = (static function () { yield 1; yield 2; })();
        foreach ($generator as $ignored) {
            break;                            // leave it partly consumed
        }
        $generator->next();
        $generator->next();                   // and now closed

        $this->expectException(TemplateError::class);
        TemplateEngine::withOptions(Options::lenient())
            ->render('{{for i in xs}}x{{/for}}', ['xs' => $generator]);
    }

    /**
     * {{trans}} substitutes in one pass.
     *
     * Sequential str_replace has two faults: a shorter placeholder rewrites the front of a
     * longer one, and a value containing a placeholder becomes a live placeholder for a
     * later argument - a variable's value turning back into template syntax.
     */
    #[DataProvider('transSubstitutions')]
    public function testTransSubstitutesInOnePass(string $template, array $variables, string $expected): void
    {
        self::assertSame(
            $expected,
            TemplateEngine::withOptions(Options::lenient())->render($template, $variables)
        );
    }

    public static function transSubstitutions(): array
    {
        return [
            'prefix collision' => [
                '{{trans "%name %name_long" name=$x name_long=$y}}',
                ['x' => 'N', 'y' => 'NL'],
                'N NL',
            ],
            'letter prefix' => [
                '{{trans "%a and %ab" a=$x ab=$y}}',
                ['x' => 'AAA', 'y' => 'BBB'],
                'AAA and BBB',
            ],
            'numeric prefix' => [
                '{{trans "%1 %10" 1=$x 10=$y}}',
                ['x' => 'ONE', 'y' => 'TEN'],
                'ONE TEN',
            ],
            'a value is not a placeholder' => [
                '{{trans "%a %b" a=$x b=$y}}',
                ['x' => '%b', 'y' => 'PWNED'],
                '%b PWNED',
            ],
        ];
    }

    /** An object with no __toString yields '', not a serialisation of its innards. */
    public function testAnUnstringableObjectRendersEmpty(): void
    {
        self::assertSame(
            '[]',
            TemplateEngine::withOptions(Options::lenient())->render('[{{var o}}]', ['o' => new \ArrayObject([1, 2])])
        );
    }

    /** A leading backslash on the requested class must not defeat the allowlist. */
    public function testALeadingBackslashStillMatchesTheAllowlist(): void
    {
        $policy = RenderPolicy::unrestricted()->withAllowedBlocks(['Vendor\\Block']);

        self::assertTrue($policy->permitsBlock('\\Vendor\\Block'));
        self::assertTrue($policy->permitsBlock('Vendor\\Block'));
        self::assertFalse($policy->permitsBlock('Vendor\\Other'));
    }

    /** Policy violations can be made fatal, and the fatal path must fire. */
    public function testAViolationCanBeFatal(): void
    {
        $options = Options::lenient()->withFailOnPolicyViolation(true);
        $evaluator = new Evaluator(options: $options);
        HostDirectives::register($evaluator, new HostServices());
        $engine = new TemplateEngine(new Parser(options: $options), $evaluator);

        $this->expectException(PolicyViolationError::class);
        $engine->render('{{var a}}', context: new Context(['a' => 1], RenderPolicy::allowing(['if'])));
    }
}
