<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\Diagnostics;
use Cresset\TemplateParser\HostDirectives;
use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\Lexer\Lexer;
use Cresset\TemplateParser\Magento\PhraseTranslator;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\PathGuard;
use Cresset\TemplateParser\Port\BlockRenderer;
use Cresset\TemplateParser\Port\UrlBuilder;
use Cresset\TemplateParser\RenderPolicy;
use Cresset\TemplateParser\TemplateEngine;
use Cresset\TemplateParser\TemplateError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One test per issue found in the adversarial review round.
 *
 * Named for the defect rather than the feature, because that is what these are for: each one
 * failed before the corresponding fix, and the point of keeping them is that the fix cannot
 * be undone quietly.
 */
final class SecurityRegressionTest extends TestCase
{
    private const XSS = '<img src=x onerror=alert(1)>';

    // ---------------------------------------------------------------- {{trans}}

    /**
     * {{trans}} arguments were the one path that put a raw value in the output.
     *
     * {{var x}} escaped and {{trans "%n" n=$x}} did not, in every mode - so any template
     * could opt out of escaping just by routing the value through a translation.
     */
    #[DataProvider('allModes')]
    public function testTransArgumentsAreEscaped(string $mode, Options $options): void
    {
        $out = TemplateEngine::withOptions($options)->render('{{trans "Hi %n" n=$x}}', ['x' => self::XSS]);

        self::assertStringNotContainsString('<img', $out, $mode . ' emitted a raw value through {{trans}}');
        self::assertStringContainsString('&lt;img', $out);
    }

    public static function allModes(): array
    {
        return [
            'strict'     => ['strict', Options::strict()],
            'lenient'    => ['lenient', Options::lenient()],
            'compatible' => ['compatible', Options::compatible()],
        ];
    }

    /** The host translator gets the same treatment as the built-in handler. */
    public function testTransArgumentsAreEscapedThroughATranslatorPort(): void
    {
        $engine = TemplateEngine::withOptions(Options::lenient());
        HostDirectives::register($engine->evaluator(), new HostServices(translator: new PhraseTranslator()));

        $out = $engine->render('{{trans "Hi %n" n=$x}}', ['x' => self::XSS]);

        self::assertStringNotContainsString('<img', $out);
    }

    /**
     * PhraseTranslator substituted with str_replace in a loop.
     *
     * The built-in handler has a tripwire for exactly this; the adapter that replaces it
     * did not, so wiring a Translator re-introduced the defect.
     */
    public function testPhraseTranslatorSubstitutesInOnePass(): void
    {
        $translator = new PhraseTranslator();

        self::assertSame('%b-SECRET', $translator->translate('%a-%b', ['%a' => '%b', '%b' => 'SECRET']));
        self::assertSame(
            'N NL',
            $translator->translate('%name %name_long', ['%name' => 'N', '%name_long' => 'NL'])
        );
    }

    // ---------------------------------------------------------------- resource bounds

    /**
     * A close-tag candidate whose peek window is entirely whitespace.
     *
     * `ltrim()` left $rest empty, the window guard passed, and build() rejected the span
     * only after copying it - the third quadratic path found in this lexer. 4.2 MB took 36
     * seconds.
     */
    public function testAWhitespacePaddedCloseTagDoesNotBlowUp(): void
    {
        $unit = '{{/a' . str_repeat(' ', 62);
        $lexer = new Lexer();

        $started = microtime(true);
        $lexer->tokenize(str_repeat($unit, 64000) . '}}');
        $elapsed = microtime(true) - $started;

        // Comfortably linear; the defect was ~2 s at a quarter of this size.
        self::assertLessThan(2.0, $elapsed, sprintf('took %.2fs - the lexer is quadratic again', $elapsed));
    }

    /** ...while every legal spelling of a closing tag still lexes. */
    #[DataProvider('closingTagForms')]
    public function testLegalClosingTagsStillLex(string $source): void
    {
        $tokens = (new Lexer())->tokenize($source);
        $last = $tokens[array_key_last($tokens)];

        self::assertSame('DirectiveClose', $last->type->name, $source . ' stopped closing');
    }

    public static function closingTagForms(): array
    {
        return [
            'plain'     => ['{{if a}}X{{/if}}'],
            'padded'    => ['{{if a}}X{{/if }}'],
            'newline'   => ["{{if a}}X{{/if\n}}"],
            'uppercase' => ['{{if a}}X{{/IF}}'],
        ];
    }

    /**
     * Diagnostics::locate() copied the prefix on every call, which is O(offset).
     *
     * One diagnostic is fine; a template that reports thousands is quadratic. A policy
     * refusing {{block}} is the default posture of the shipped Magento adapter, so an
     * ordinary email full of blocks hit it: 2.7 MB took over ten seconds.
     */
    public function testManyDiagnosticsInOneRenderStayLinear(): void
    {
        $engine = TemplateEngine::withOptions(Options::lenient());
        HostDirectives::register($engine->evaluator(), new HostServices(blocks: new class implements BlockRenderer {
            public function render(string $class, array $parameters, string $method): string { return ''; }
        }));

        // Two sizes and a ratio, not a stopwatch. A wall-clock bar measures the machine as
        // much as the code: this failed on the CI job that happens to load xdebug while
        // passing everywhere else, for a change that cost 10% rather than the 10x this
        // exists to catch. O(offset) means doubling the input roughly quadruples the work,
        // so the ratio is the thing being asserted and it is machine-independent.
        //
        // 128k violations: 10.6 s with the prefix-copy implementation, 0.18 s indexed.
        $elapsed = [];
        foreach ([64000, 128000] as $count) {
            $context = new Context([], RenderPolicy::restricted());
            $started = microtime(true);
            $engine->render(str_repeat('{{block class="Foo"}}', $count), context: $context);
            $elapsed[$count] = microtime(true) - $started;

            self::assertCount($count, $context->violations());
        }

        $ratio = $elapsed[128000] / max($elapsed[64000], 0.001);

        self::assertLessThan(
            3.0,
            $ratio,
            sprintf(
                'doubling the input multiplied the work by %.1f (%.2fs then %.2fs) - locate() is O(offset) again',
                $ratio,
                $elapsed[64000],
                $elapsed[128000]
            )
        );
    }

    public function testDiagnosticsStillReportTheRightPosition(): void
    {
        self::assertSame(['line' => 1, 'column' => 1], Diagnostics::locate("one\ntwo\nthree", 0));
        self::assertSame(['line' => 2, 'column' => 1], Diagnostics::locate("one\ntwo\nthree", 4));
        self::assertSame(['line' => 3, 'column' => 5], Diagnostics::locate("one\ntwo\nthree", 12));
        self::assertSame(['line' => 1, 'column' => 1], Diagnostics::locate("one\ntwo", -5));
        self::assertSame(['line' => 2, 'column' => 4], Diagnostics::locate("one\ntwo", 9999));
    }

    // ---------------------------------------------------------------- path guards

    /**
     * {{inlinecss}} handed its path to the caller with no check.
     *
     * Deferring is still handing a path outward, and PathGuard's contract is that handlers
     * apply it so a host cannot forget to.
     */
    #[DataProvider('unsafeStylesheetPaths')]
    public function testInlineCssPathsAreGuarded(string $file): void
    {
        $context = new Context([], RenderPolicy::unrestricted());
        TemplateEngine::withOptions(Options::lenient())
            ->render('{{inlinecss file="' . $file . '"}}', context: $context);

        self::assertSame([], $context->deferred(), $file . ' was deferred to the host');
    }

    public static function unsafeStylesheetPaths(): array
    {
        return [
            'traversal' => ['../../../../etc/passwd'],
            'absolute'  => ['/etc/shadow'],
            'scheme'    => ['http://evil.example/x.css'],
            'file url'  => ['file:///etc/passwd'],
        ];
    }

    public function testALegitimateInlineCssPathIsStillDeferred(): void
    {
        $context = new Context([], RenderPolicy::unrestricted());
        TemplateEngine::withOptions(Options::lenient())
            ->render('{{inlinecss file="css/email.css"}}', context: $context);

        self::assertCount(1, $context->deferred());
    }

    /**
     * `_direct` is concatenated onto the base URL by Magento's Url::getRouteUrl() with no
     * filtering, so guarding `url=` alone guarded nothing.
     */
    public function testForwardedPathParametersAreGuarded(): void
    {
        $seen = [];
        $urls = new class ($seen) implements UrlBuilder {
            public function __construct(private array &$seen) {}
            public function storeUrl(string $path, array $parameters): string
            {
                $this->seen[] = $parameters;
                return 'https://shop.example/' . $path . ($parameters['_direct'] ?? '');
            }
            public function mediaUrl(string $path): string { return $path; }
            public function viewUrl(string $path, array $parameters): string { return $path; }
            public function isSecure(): bool { return true; }
        };

        $engine = TemplateEngine::withOptions(Options::lenient());
        HostDirectives::register($engine->evaluator(), new HostServices(urls: $urls));

        $out = $engine->render(
            '{{store url="" _direct="../../../../etc/passwd"}}',
            context: new Context([], RenderPolicy::unrestricted())
        );

        self::assertSame('', $out);
        self::assertSame([], $seen, 'the traversal reached the UrlBuilder');
    }

    /** `?`, `#` and Magento's `::` module separator end a path segment too. */
    #[DataProvider('traversalBoundaries')]
    public function testTraversalIsRefusedAtEverySegmentBoundary(string $path): void
    {
        self::assertFalse(PathGuard::isSafeRelativePath($path));
    }

    public static function traversalBoundaries(): array
    {
        return [
            'module separator' => ['Magento_Email::../secret.css'],
            'query'            => ['a/..?'],
            'fragment'         => ['a/..#'],
            'bare query'       => ['..?'],
            'bare fragment'    => ['..#'],
        ];
    }

    /** ...without breaking the module-scoped asset paths Magento actually uses. */
    #[DataProvider('legitimateAssetPaths')]
    public function testModuleScopedAssetPathsRemainSafe(string $path): void
    {
        self::assertTrue(PathGuard::isSafeRelativePath($path));
    }

    public static function legitimateAssetPaths(): array
    {
        return [
            'module css'   => ['Magento_Email::css/email.css'],
            'module image' => ['Magento_Theme::images/logo.svg'],
            'plain'        => ['css/email.css'],
        ];
    }

    // ---------------------------------------------------------------- host code that raises

    /**
     * getData() and hasData() are host code too.
     *
     * invokeAccessor() wrapped a throwing accessor; the data-bag path called straight into
     * the object, so a Magento model with lazy-loading getData() could take the render down
     * and put its exception message in front of the caller.
     */
    public function testAThrowingDataBagIsReportedAsATemplateError(): void
    {
        $object = new class {
            public function getData($key = null)
            {
                throw new \RuntimeException('SECRET db credential: pass=hunter2');
            }
        };

        try {
            TemplateEngine::withOptions(Options::lenient())->render('{{var o.a}}', ['o' => $object]);
            self::fail('expected a TemplateError');
        } catch (TemplateError $e) {
            self::assertStringNotContainsString('hunter2', $e->getMessage());
            self::assertStringContainsString('getData', $e->getMessage());
        }
    }

    public function testAThrowingHasDataIsReportedAsATemplateError(): void
    {
        $object = new class {
            public function getData($key = null) { return 'v'; }
            public function hasData($key = null) { throw new \LogicException('boom from hasData'); }
        };

        $this->expectException(TemplateError::class);
        TemplateEngine::withOptions(Options::lenient())->render('{{var o.a}}', ['o' => $object]);
    }

    /** A hasData() that cannot take a string key must not be called with one. */
    public function testAnIntKeyedHasDataIsNotCalledWithAStringKey(): void
    {
        $object = new class {
            public function getData($key = null) { return 'FROM-BAG'; }
            public function hasData(int $key = 0) { return true; }
        };

        self::assertSame(
            'FROM-BAG',
            TemplateEngine::withOptions(Options::lenient())->render('{{var o.a}}', ['o' => $object])
        );
    }

    /**
     * Any Traversable in {{for}} is host code, not just a Generator.
     *
     * A Magento collection that fails to load is an IteratorAggregate whose getIterator()
     * throws, which escaped even lenient mode.
     */
    #[DataProvider('failingCollections')]
    public function testAFailingCollectionIsReportedAsATemplateError(object $collection): void
    {
        $this->expectException(TemplateError::class);
        TemplateEngine::withOptions(Options::lenient())
            ->render('{{for i in xs}}X{{/for}}', ['xs' => $collection]);
    }

    public static function failingCollections(): array
    {
        return [
            'aggregate throws' => [new class implements \IteratorAggregate {
                public function getIterator(): \Iterator { throw new \RuntimeException('load failed'); }
            }],
            'iterator throws' => [new class implements \IteratorAggregate {
                public function getIterator(): \Iterator
                {
                    return new class implements \Iterator {
                        public function current(): mixed { throw new \RuntimeException('current blew up'); }
                        public function next(): void {}
                        public function key(): mixed { return 0; }
                        public function valid(): bool { return true; }
                        public function rewind(): void {}
                    };
                }
            }],
        ];
    }

    /** An ordinary Traversable still loops. */
    public function testAnOrdinaryTraversableStillIterates(): void
    {
        $collection = new \ArrayIterator([['n' => 'a'], ['n' => 'b']]);

        self::assertSame(
            'ab',
            TemplateEngine::withOptions(Options::lenient())->render('{{for i in xs}}{{var i.n}}{{/for}}', ['xs' => $collection])
        );
    }
}
