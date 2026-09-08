<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Ast\DirectiveNode;
use MageOS\TemplateParser\Ast\TextNode;
use MageOS\TemplateParser\Options;
use MageOS\TemplateParser\Parser;
use MageOS\TemplateParser\SyntaxError;
use MageOS\TemplateParser\UnclosedDirective;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function testBlockDirectiveGetsChildren(): void
    {
        $root = (new Parser())->parse('{{if a}}body{{/if}}');
        $children = $root->children();
        self::assertCount(1, $children);
        self::assertInstanceOf(DirectiveNode::class, $children[0]);
        self::assertSame('if', $children[0]->name());
        self::assertCount(1, $children[0]->children());
    }

    public function testElseSplitsTheBody(): void
    {
        $root = (new Parser())->parse('{{if a}}yes{{else}}no{{/if}}');
        $if = $root->children()[0];
        self::assertTrue($if->hasAlternate());
        self::assertSame('yes', $if->children()[0]->raw());
        self::assertSame('no', $if->alternate()[0]->raw());
    }

    public function testNesting(): void
    {
        $root = (new Parser())->parse('{{if a}}{{depend b}}x{{/depend}}{{/if}}');
        $if = $root->children()[0];
        $depend = $if->children()[0];
        self::assertSame('depend', $depend->name());
        self::assertSame('x', $depend->children()[0]->raw());
    }

    /** The legacy backreference let any name close any directive. It cannot here. */
    public function testStrayClosingTagIsNotAllowedToCloseAnotherDirective(): void
    {
        $root = (new Parser(options: Options::lenient()))->parse('{{if a}}x{{/var}}y{{/if}}');
        $if = $root->children()[0];
        self::assertSame('if', $if->name());
        // {{/var}} is inert text inside the if body, not a terminator.
        self::assertSame('x{{/var}}y', implode('', array_map(static fn ($n) => $n->raw(), $if->children())));
    }

    public function testUnclosedBlockDegradesToTextInLenientMode(): void
    {
        $root = (new Parser(options: Options::lenient()))->parse('{{if a}}dangling');
        self::assertInstanceOf(UnclosedDirective::class, $root->children()[0]);
        self::assertSame('{{if a}}dangling', $root->raw());
    }

    public function testUnclosedBlockThrowsInStrictMode(): void
    {
        $this->expectException(SyntaxError::class);
        (new Parser())->parse('{{if a}}dangling');
    }

    public function testStrayClosingTagThrowsInStrictMode(): void
    {
        $this->expectException(SyntaxError::class);
        (new Parser())->parse('text{{/if}}');
    }

    public function testVoidDirectiveTakesNoChildren(): void
    {
        $root = (new Parser())->parse('{{var x}}after');
        self::assertCount(2, $root->children());
        self::assertSame([], $root->children()[0]->children());
        self::assertInstanceOf(TextNode::class, $root->children()[1]);
    }

    /** Parsing must be lossless: the tree can always reproduce its source. */
    public function testParseIsLossless(): void
    {
        foreach ([
            '{{if a}}x{{else}}y{{/if}}',
            'a{{var b}}c',
            '{{depend d}}{{var e|raw}}{{/depend}}',
            '{{if a}}unclosed',
            'plain',
        ] as $source) {
            self::assertSame($source, (new Parser(options: Options::lenient()))->parse($source)->raw(), $source);
        }
    }
}
