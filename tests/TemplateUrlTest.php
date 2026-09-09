<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\HostDirectives;
use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\NestingLimitError;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\Port\TemplateUrlBuilder;
use Cresset\TemplateParser\TemplateEngine;
use PHPUnit\Framework\TestCase;

/**
 * `this.getUrl(...)` - the one method call legacy invokes with its arguments.
 *
 * StrictResolver maps every other getFoo() to getData('foo'). This one is special-cased for
 * AbstractTemplate, and it is where every account link in every stock email comes from, so
 * the arguments have to arrive intact: a store object, a route, and a nested parameter array.
 */
final class TemplateUrlTest extends TestCase
{
    /** @var list<array{object,list<mixed>}> */
    private array $calls = [];

    private function engine(bool $serve = true): TemplateEngine
    {
        $this->calls = [];
        $engine = TemplateEngine::withOptions(Options::compatible());

        $builder = new class ($this->calls) implements TemplateUrlBuilder {
            /** @param list<array{object,list<mixed>}> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function urlFor(object $target, array $arguments): ?string
            {
                // Declining on an unrecognised receiver is the port's contract, and the whole
                // guard on the one place template text reaches a host method.
                if (!property_exists($target, 'isTemplateModel')) {
                    return null;
                }
                $this->calls[] = [$target, $arguments];

                return 'URL';
            }
        };

        HostDirectives::register($engine->evaluator(), new HostServices(templateUrls: $serve ? $builder : null));

        return $engine;
    }

    private function templateModel(): object
    {
        return new class {
            public bool $isTemplateModel = true;
        };
    }

    public function testArgumentsArriveParsedAndResolved(): void
    {
        $store = new \stdClass();
        $out = $this->engine()->render(
            "[{{var this.getUrl(\$store,'customer/account/createPassword/',[_query:[id:\$customer.id],_nosid:1])}}]",
            [
                'this' => $this->templateModel(),
                'store' => $store,
                'customer' => ['id' => 42],
            ]
        );

        self::assertSame('[URL]', $out);
        self::assertCount(1, $this->calls);

        [, $arguments] = $this->calls[0];
        self::assertSame($store, $arguments[0], 'the store is passed through, not the word "$store"');
        self::assertSame('customer/account/createPassword/', $arguments[1]);
        // A bare run of digits is a float there, so it is a float here.
        self::assertSame(['_query' => ['id' => 42], '_nosid' => 1.0], $arguments[2]);
    }

    /**
     * StrictResolver overwrites the first argument with the scope's `store` before calling,
     * so a template cannot aim getUrl() at a store of its own choosing.
     */
    public function testTheStoreArgumentComesFromTheScopeNotTheTemplate(): void
    {
        $store = new \stdClass();
        $this->engine()->render(
            "{{var this.getUrl(\$attacker,'x/')}}",
            ['this' => $this->templateModel(), 'store' => $store, 'attacker' => new \stdClass()]
        );

        [, $arguments] = $this->calls[0];
        self::assertSame($store, $arguments[0]);
    }

    /** A receiver the port declines falls back to the ordinary getData() mapping. */
    public function testADeclinedReceiverFallsBackToTheDataBag(): void
    {
        // DataObject's shape: a key that is optional, as the resolver's bag check requires.
        $bag = new class {
            public function getData(string $key = ''): mixed
            {
                return $key === 'url' ? 'FROM-BAG' : null;
            }

            public function hasData(string $key = ''): bool
            {
                return $key === 'url';
            }
        };

        self::assertSame('FROM-BAG', $this->engine()->render("{{var o.getUrl(\$store,'x/')}}", ['o' => $bag]));
        self::assertSame([], $this->calls);
    }

    /** With no port wired at all, nothing is invoked and nothing changes. */
    public function testWithoutThePortNothingIsCalled(): void
    {
        self::assertSame(
            '[]',
            $this->engine(serve: false)->render(
                "[{{var this.getUrl(\$store,'x/')}}]",
                ['this' => $this->templateModel(), 'store' => new \stdClass()]
            )
        );
    }

    /**
     * A dot inside a method's arguments is not a path separator.
     *
     * Splitting the expression on every dot left `getUrl($store,'x',[id:$customer` as its own
     * segment, which matches nothing - so the whole link resolved to '' and every stock
     * account email rendered href="".
     */
    public function testDotsInsideArgumentsDoNotSplitThePath(): void
    {
        $this->engine()->render(
            "{{var this.getUrl(\$store,'a/b/',[k:\$c.d.e])}}",
            ['this' => $this->templateModel(), 'store' => new \stdClass(), 'c' => ['d' => ['e' => 'DEEP']]]
        );

        self::assertCount(1, $this->calls, 'the expression is two segments, not five');
        self::assertSame(['k' => 'DEEP'], $this->calls[0][1][2]);
    }

    public function testQuotedArgumentsKeepTheirSeparators(): void
    {
        $this->engine()->render(
            "{{var this.getUrl(\$store,'a, b (c).d')}}",
            ['this' => $this->templateModel(), 'store' => new \stdClass()]
        );

        self::assertSame('a, b (c).d', $this->calls[0][1][1]);
    }

    /**
     * A stray `)` in the argument list used to spin for ever.
     *
     * `)` is a string break, so parseString() returned '' without moving the cursor, and the
     * value loop appended '' until memory ran out - 35 bytes of template, under a second.
     * Reachable from every CLI command, because the console wires this port unconditionally
     * and then sweeps every template in the codebase and the email_template table.
     */
    public function testAStrayClosingParenDoesNotHang(): void
    {
        $this->engine()->render(
            "{{var this.getUrl(\$store,'a/b/'))}}",
            ['this' => $this->templateModel(), 'store' => new \stdClass()]
        );

        self::assertCount(1, $this->calls);
        self::assertSame('a/b/', $this->calls[0][1][1]);
    }

    /** Argument nesting is bounded: legacy recurses here until the C stack gives out. */
    public function testDeepArgumentNestingIsRefusedRatherThanFatal(): void
    {
        $this->expectException(NestingLimitError::class);
        $this->engine()->render(
            '{{var this.getUrl(' . str_repeat('[', 500) . ')}}',
            ['this' => $this->templateModel(), 'store' => new \stdClass()]
        );
    }

    /** A trailing `key:` runs the cursor past the end; Magento promotes that warning. */
    public function testATrailingMemberKeyDoesNotReadPastTheEnd(): void
    {
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $this->engine()->render(
                '{{var this.getUrl([a:)}}',
                ['this' => $this->templateModel(), 'store' => new \stdClass()]
            );
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $this->calls);
    }

    /** A context is still a context: an unrelated method with arguments is not served. */
    public function testOnlyGetUrlIsServed(): void
    {
        $this->engine()->render(
            "{{var this.getSomething(\$store,'x')}}",
            ['this' => $this->templateModel(), 'store' => new \stdClass()]
        );

        self::assertSame([], $this->calls);
    }

    /** Nothing about this is available to a template that renders through no port at all. */
    public function testTheHostSeesTheContextItWasGiven(): void
    {
        $engine = $this->engine();
        $context = new Context(['this' => $this->templateModel(), 'store' => new \stdClass()]);
        $engine->render("{{var this.getUrl(\$store,'x/')}}", context: $context);

        self::assertCount(1, $this->calls);
    }
}
