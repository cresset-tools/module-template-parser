<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\DirectiveSpec;
use PHPUnit\Framework\TestCase;

/**
 * What this engine claims the stock directive surface is.
 *
 * The list is not decoration. `knownNames()` decides which directives `diff` will call an
 * unwired port, `isBlock()` decides which run-ons are legacy fatals, and the render policy is
 * built out of the same names - so a name in here that does not exist makes all three wrong
 * about a directive nobody can write.
 */
final class DirectiveSpecTest extends TestCase
{
    /**
     * `filter` was on this list and is not a directive.
     *
     * Magento has two extension points that are easy to confuse. `SimpleDirective\ProcessorPool`
     * registers arbitrary NAMED directives, so a module adding `mydir` gets `{{mydir}}`;
     * `DirectiveProcessor\Filter\FilterPool` registers MODIFIERS, so one adding `foofilter`
     * gets `{{var x|foofilter}}`. Neither produces a `{{filter}}`, no stock template uses one,
     * and the engine never had a handler for it.
     */
    public function testTheKnownNamesAreExactlyTheStockSurface(): void
    {
        $expected = [
            'block', 'config', 'css', 'customvar', 'depend', 'else', 'for', 'if', 'inlinecss',
            'layout', 'media', 'protocol', 'store', 'template', 'trans', 'var', 'view', 'widget',
        ];

        $actual = (new DirectiveSpec())->knownNames();
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            'the claimed directive surface changed - if a directive was genuinely added to '
            . 'Magento, add it here; if not, this list has grown a name nobody can write'
        );
    }

    /** The three that take a body, which is what makes a missing brace a legacy fatal. */
    public function testOnlyTheThreePairedDirectivesAreBlocks(): void
    {
        $spec = new DirectiveSpec();

        foreach (['if', 'depend', 'for'] as $paired) {
            self::assertTrue($spec->isBlock($paired), $paired);
        }

        foreach (['var', 'trans', 'block', 'template', 'else', 'widget'] as $void) {
            self::assertFalse($spec->isBlock($void), $void);
        }
    }

    /** Only {{if}} takes an {{else}}; the filter's CONSTRUCTION_IF_PATTERN is the only one with a divider. */
    public function testOnlyIfAcceptsAnElse(): void
    {
        $spec = new DirectiveSpec();

        self::assertTrue($spec->acceptsElse('if'));
        self::assertFalse($spec->acceptsElse('depend'));
        self::assertFalse($spec->acceptsElse('for'));
    }

    /** An integrator's own directive joins the surface without editing this class. */
    public function testExtraNamesAreAccepted(): void
    {
        // The blocks argument is a MAP of name => accepts-an-{{else}}, not a list. Passing a
        // list gives it the key 0 and registers nothing, silently.
        $spec = new DirectiveSpec(['mypaired' => false], ['mydir']);

        self::assertTrue($spec->isKnown('mydir'));
        self::assertTrue($spec->isBlock('mypaired'));
        self::assertFalse($spec->acceptsElse('mypaired'));
        self::assertFalse($spec->isKnown('nothing_registered_this'));

        // Which is the shape a ProcessorPool directive would take: a store whose module
        // registered `mydir` has a `{{mydir}}` the filter renders, and this engine leaves it
        // verbatim until a host declares it here and wires a port for it.
        self::assertTrue((new DirectiveSpec())->isKnown('var'));
        self::assertFalse((new DirectiveSpec())->isKnown('mydir'));
    }
}
