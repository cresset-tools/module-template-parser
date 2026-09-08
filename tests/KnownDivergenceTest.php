<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\TemplateEngine;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural differences from the legacy filter, found by tools/differential.php.
 *
 * Each is deliberate. They are pinned here so a migration has an explicit list to weigh,
 * rather than discovering them in production.
 */
final class KnownDivergenceTest extends TestCase
{
    private TemplateEngine $engine;

    protected function setUp(): void
    {
        $this->engine = TemplateEngine::lenient();
    }

    /**
     * DIVERGENCE 1 - partially resolved variable paths.
     *
     * Legacy `StrictResolver` only advances its `$last` cursor on a successful step, so
     * when a path segment cannot be walked it returns the value resolved *so far*.
     * `{{var store.frontend_name}}` against a scalar `store` therefore renders the store
     * value itself. This engine resolves the whole path or nothing.
     *
     * Legacy: 'Demo'   This engine: ''
     */
    public function testUnwalkablePathSegmentResolvesToNothing(): void
    {
        self::assertSame('', $this->engine->render('{{var store.frontend_name}}', ['store' => 'Demo']));
        // The full path still resolves normally when it exists.
        self::assertSame(
            'Demo Store',
            $this->engine->render('{{var store.frontend_name}}', ['store' => ['frontend_name' => 'Demo Store']])
        );
    }

    /**
     * DIVERGENCE 2 - what counts as false in a condition.
     *
     * The legacy filter tests `resolve(...) == ''`. On PHP 8 `0 == ''` and `'0' == ''` are
     * both false, and an array never equals '', so legacy renders the TRUE branch for 0,
     * '0' and []. (On PHP 7 `0 == ''` was true, so this silently flipped on upgrade.)
     * This engine uses standard PHP truthiness, which is what {{if qty}} plainly means.
     *
     * Legacy on PHP 8:  0 -> true branch    This engine: 0 -> false branch
     */
    public function testZeroAndEmptyArrayAreFalsy(): void
    {
        self::assertSame('N', $this->engine->render('{{if a}}Y{{else}}N{{/if}}', ['a' => 0]));
        self::assertSame('N', $this->engine->render('{{if a}}Y{{else}}N{{/if}}', ['a' => '0']));
        self::assertSame('N', $this->engine->render('{{if a}}Y{{else}}N{{/if}}', ['a' => []]));
        // Unchanged from legacy for the cases that already agreed.
        self::assertSame('N', $this->engine->render('{{if a}}Y{{else}}N{{/if}}', ['a' => '']));
        self::assertSame('Y', $this->engine->render('{{if a}}Y{{else}}N{{/if}}', ['a' => 'x']));
    }

    /**
     * DIVERGENCE 3 - a closing tag closes only a block that is actually open.
     *
     * Legacy `CONSTRUCTION_PATTERN` matched the closing tag with a backreference and made
     * it optional, so a stray `{{/var}}` could terminate an unrelated construct. That is
     * the mechanic the StyleSmuggler payload used to unbalance signature pairs.
     */
    public function testStrayClosingTagIsText(): void
    {
        self::assertSame(
            'a{{/var}}b',
            $this->engine->render('{{if x}}a{{/var}}b{{/if}}', ['x' => 1])
        );
    }

    /**
     * DIVERGENCE 4 - a directive nested inside itself.
     *
     * Legacy cannot express nesting: CONSTRUCTION_DEPEND_PATTERN's body is a lazy `(.*?)`,
     * so the outer {{depend}} stops at the FIRST {{/depend}} and its body carries an
     * unclosed inner one. That fragment is then re-filtered, where the generic
     * CONSTRUCTION_PATTERN matches it (its closing tag is optional), reflects to
     * dependDirective(), which re-matches with the strict pattern, gets nothing, and hands
     * null to StrictResolver::resolve(string) - a fatal TypeError on PHP 8.
     *
     * Stock templates happen never to nest a directive inside itself; they only nest
     * different names ({{depend}} around {{if}}), which works because each has its own
     * pattern. A merchant writing {{depend}} inside {{depend}} gets a fatal.
     */
    public function testSameNameNestingWorks(): void
    {
        $vars = ['a' => 1, 'b' => 1, 'c' => 0];
        self::assertSame('ABZ', $this->engine->render('{{depend a}}A{{depend b}}B{{/depend}}Z{{/depend}}', $vars));
        self::assertSame('AZ', $this->engine->render('{{depend a}}A{{depend c}}B{{/depend}}Z{{/depend}}', $vars));
        self::assertSame('AZ', $this->engine->render('{{if a}}A{{if c}}B{{/if}}Z{{/if}}', $vars));
        self::assertSame('ABZ', $this->engine->render('{{if a}}A{{if b}}B{{/if}}Z{{/if}}', $vars));
    }

    /** Mixed-name nesting, which stock templates do use, behaves the same as legacy. */
    public function testMixedNameNestingMatchesLegacy(): void
    {
        self::assertSame(
            'phone: 123 open',
            $this->engine->render(
                '{{depend store_phone}}phone: {{var store_phone}}{{if store_hours}} open{{/if}}{{/depend}}',
                ['store_phone' => '123', 'store_hours' => 1]
            )
        );
    }

    /**
     * DIVERGENCE 5 - an unknown directive round-trips instead of being dispatched.
     *
     * Legacy resolved directives by reflecting `<name>Directive` onto the filter object,
     * so any public method matching that shape was reachable from template text. Here the
     * handler table is explicit; anything absent from it is text.
     */
    public function testUnknownDirectiveRoundTrips(): void
    {
        self::assertSame('{{nosuchthing a=1}}', $this->engine->render('{{nosuchthing a=1}}'));
    }

    /**
     * DIVERGENCE 6 - directive names must be lower-case and start immediately after `{{`.
     *
     * CONSTRUCTION_PATTERN carries /si, so `[a-z]{0,10}` matches upper case too:
     * `{{Forgot Your Password?}}` captures the name `Forgot`, fails to resolve, and comes
     * back verbatim on the legacy filter. An EMPTY name is the fatal case, and needs a
     * non-letter immediately after the braces - `{{100}}`, `{{ var x }}`, `{{}}`.
     * Either way, prose stays prose here.
     */
    public function testProseIsNotADirective(): void
    {
        foreach (['{{Forgot Your Password?}}', '{{ var x }}', '{{Sign In}}'] as $source) {
            self::assertSame($source, $this->engine->render($source, ['x' => 'v']), $source);
        }
    }
}
