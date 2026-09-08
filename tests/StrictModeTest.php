<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\SyntaxError;
use Cresset\TemplateParser\TemplateTypeError;
use Cresset\TemplateParser\TemplateEngine;
use Cresset\TemplateParser\UnknownDirectiveError;
use Cresset\TemplateParser\UnknownVariableError;
use PHPUnit\Framework\TestCase;

/**
 * Strict mode is the default. These pin both that the right error is raised and that the
 * message is useful to whoever is editing the template.
 */
final class StrictModeTest extends TestCase
{
    private TemplateEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new TemplateEngine();
    }

    public function testUnclosedBlockReportsPositionAndExcerpt(): void
    {
        $source = "line one\n<p>{{if customer}}</p>\nline three";

        try {
            $this->engine->render($source, ['customer' => 1]);
            self::fail('expected a syntax error');
        } catch (SyntaxError $e) {
            self::assertStringContainsString('Unclosed directive {{if}} — expected {{/if}}', $e->getMessage());
            self::assertSame(2, $e->sourceLine);
            self::assertSame(4, $e->sourceColumn);
            self::assertStringContainsString('{{if customer}}', $e->getMessage());
            self::assertStringContainsString('^', $e->getMessage());
            self::assertStringContainsString('hint: add {{/if}}', $e->getMessage());
        }
    }

    public function testStrayClosingTagIsReported(): void
    {
        try {
            $this->engine->render('hello {{/if}} world');
            self::fail('expected a syntax error');
        } catch (SyntaxError $e) {
            self::assertStringContainsString('Unexpected closing directive {{/if}}', $e->getMessage());
            self::assertStringContainsString('nothing is open here', $e->getMessage());
        }
    }

    public function testStrayClosingTagInsideABlockNamesTheOpenDirective(): void
    {
        try {
            $this->engine->render('{{if a}}x{{/var}}y{{/if}}', ['a' => 1]);
            self::fail('expected a syntax error');
        } catch (SyntaxError $e) {
            self::assertStringContainsString('{{/var}}', $e->getMessage());
            self::assertStringContainsString('the innermost open directive is {{if}}', $e->getMessage());
        }
    }

    public function testUnknownDirectiveNameSuggestsTheClosestMatch(): void
    {
        try {
            $this->engine->render('{{vr x}}', ['x' => 1]);
            self::fail('expected a syntax error');
        } catch (SyntaxError $e) {
            self::assertStringContainsString('Unknown directive {{vr}}', $e->getMessage());
            self::assertStringContainsString('did you mean {{var}}?', $e->getMessage());
        }
    }

    public function testKnownDirectiveWithNoHandlerIsReportedSeparately(): void
    {
        // `layout` is a known name, so it parses - but nothing implements it here.
        $this->expectException(UnknownDirectiveError::class);
        $this->expectExceptionMessageMatches('/No handler registered for \{\{layout\}\}/');
        $this->engine->render('{{layout handle=x}}');
    }

    public function testUnknownVariableSuggestsTheClosestName(): void
    {
        try {
            $this->engine->render('Hi {{var custmer_name}}', ['customer_name' => 'Jan']);
            self::fail('expected an unknown variable error');
        } catch (UnknownVariableError $e) {
            self::assertStringContainsString('Unknown variable "custmer_name"', $e->getMessage());
            self::assertStringContainsString('did you mean {{var customer_name}}?', $e->getMessage());
            self::assertSame(1, $e->sourceLine);
        }
    }

    public function testUnknownVariableListsWhatIsInScopeWhenThereIsNoNearMatch(): void
    {
        try {
            $this->engine->render('{{var zzz}}', ['alpha' => 1, 'beta' => 2]);
            self::fail('expected an unknown variable error');
        } catch (UnknownVariableError $e) {
            self::assertStringContainsString('variables in scope: alpha, beta', $e->getMessage());
        }
    }

    public function testUnknownVariableNamesTheFailingPathSegment(): void
    {
        try {
            $this->engine->render('{{var order.nosuchfield}}', ['order' => ['id' => 1]]);
            self::fail('expected an unknown variable error');
        } catch (UnknownVariableError $e) {
            self::assertStringContainsString('Unknown variable "nosuchfield"', $e->getMessage());
        }
    }

    /**
     * {{if}} and {{depend}} test truthiness, not existence — so a variable that does not
     * resolve at all is a typo, and silently taking the false branch is how those go
     * unnoticed.
     */
    public function testConditionsReportUnknownVariables(): void
    {
        foreach (['{{if nope}}x{{/if}}', '{{depend nope}}x{{/depend}}'] as $source) {
            try {
                $this->engine->render($source);
                self::fail('expected an unknown variable error for ' . $source);
            } catch (UnknownVariableError $e) {
                self::assertStringContainsString('Unknown variable "nope"', $e->getMessage());
            }
        }
    }

    public function testConditionSuggestsTheClosestVariableName(): void
    {
        try {
            $this->engine->render('{{if custmer}}x{{/if}}', ['customer' => 1]);
            self::fail('expected an unknown variable error');
        } catch (UnknownVariableError $e) {
            self::assertStringContainsString('did you mean {{var customer}}?', $e->getMessage());
        }
    }

    /** A variable that exists is tested for truthiness, and never raises. */
    public function testConditionsOnExistingVariablesAreTruthinessTests(): void
    {
        self::assertSame('N', $this->engine->render('{{if a}}Y{{else}}N{{/if}}', ['a' => 0]));
        self::assertSame('N', $this->engine->render('{{if a}}Y{{else}}N{{/if}}', ['a' => '']));
        self::assertSame('N', $this->engine->render('{{if a}}Y{{else}}N{{/if}}', ['a' => []]));
        self::assertSame('Y', $this->engine->render('{{if a}}Y{{else}}N{{/if}}', ['a' => 'x']));
        self::assertSame('Y', $this->engine->render('{{if a}}Y{{else}}N{{/if}}', ['a' => [1]]));
        self::assertSame('', $this->engine->render('{{depend a}}Y{{/depend}}', ['a' => null]));
    }

    public function testForReportsAMissingCollection(): void
    {
        $this->expectException(UnknownVariableError::class);
        $this->engine->render('{{for i in nope}}x{{/for}}');
    }

    public function testForReportsANonIterableCollection(): void
    {
        try {
            $this->engine->render('{{for i in n}}x{{/for}}', ['n' => 5]);
            self::fail('expected a type error');
        } catch (TemplateTypeError $e) {
            self::assertStringContainsString('needs something iterable, but n is int', $e->getMessage());
        }
    }

    public function testEmptyCollectionIsFineAndYieldsNothing(): void
    {
        self::assertSame('', $this->engine->render('{{for i in xs}}x{{/for}}', ['xs' => []]));
    }

    /** Lenient mode keeps the legacy shape: a missing condition variable is just false. */
    public function testLenientModeTreatsMissingConditionsAsFalse(): void
    {
        $lenient = TemplateEngine::lenient();
        self::assertSame('', $lenient->render('{{if nope}}x{{/if}}'));
        self::assertSame('N', $lenient->render('{{if nope}}Y{{else}}N{{/if}}'));
        self::assertSame('', $lenient->render('{{for i in nope}}x{{/for}}'));
        self::assertSame('', $lenient->render('{{for i in n}}x{{/for}}', ['n' => 5]));
    }

    /** Each axis can be relaxed on its own. */
    public function testStrictnessIsGranular(): void
    {
        $vars = TemplateEngine::withOptions(Options::strict()->withVariables(false));
        self::assertSame('[]', $vars->render('[{{var nope}}]'));

        $directives = TemplateEngine::withOptions(Options::strict()->withDirectives(false));
        self::assertSame('{{layout h=1}}', $directives->render('{{layout h=1}}'));

        $syntax = TemplateEngine::withOptions(Options::strict()->withSyntax(false));
        self::assertSame('{{if a}}x', $syntax->render('{{if a}}x', ['a' => 1]));
    }
}
