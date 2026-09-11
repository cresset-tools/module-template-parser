<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Console\HostExtensions;
use Cresset\TemplateParser\Console\MagentoContext;
use Magento\Framework\Filter\DirectiveProcessor\Filter\FilterPool;
use Magento\Framework\Filter\SimpleDirective\ProcessorPool;
use PHPUnit\Framework\TestCase;

/**
 * What a store can render that this engine cannot.
 *
 * Both of Magento's template extension points are invisible by default: an unknown directive
 * comes back verbatim here and an unknown modifier is skipped, which is exactly what the
 * filter does on a store with no such extension. So a store that HAS one diverges silently,
 * and the only way to know is to ask it what it has.
 */
final class HostExtensionsTest extends TestCase
{
    /** @param array<string,object> $processors @param array<string,object> $filters */
    private function extensions(array $processors = [], array $filters = []): HostExtensions
    {
        return new HostExtensions(MagentoContext::fromObjectManager(new class ($processors, $filters) {
            public function __construct(private array $processors, private array $filters) {}
            public function get(string $class): ?object
            {
                if ($class === ProcessorPool::class) {
                    return new ProcessorPool($this->processors);
                }
                if ($class === FilterPool::class) {
                    return new FilterPool($this->filters);
                }
                return null;
            }
        }));
    }

    private function processor(string $name): object
    {
        return new class ($name) implements \Magento\Framework\Filter\SimpleDirective\ProcessorInterface {
            public function __construct(private string $name) {}
            public function getName(): string { return $this->name; }
            public function process($value, array $parameters, ?string $html): string { return ''; }
            public function getDefaultFilters(): ?array { return null; }
        };
    }

    private function filter(string $name): object
    {
        return new class ($name) implements \Magento\Framework\Filter\DirectiveProcessor\FilterInterface {
            public function __construct(private string $name) {}
            public function getName(): string { return $this->name; }
            public function filterValue(string $value, array $params): string { return $value; }
        };
    }

    public function testAStockStoreHasNothingToReport(): void
    {
        $extensions = $this->extensions([], ['escape' => $this->filter('escape'), 'nl2br' => $this->filter('nl2br')]);

        self::assertSame([], $extensions->directives());
        self::assertSame([], $extensions->modifiers(), 'the modifiers this engine implements are not a gap');
        self::assertNull($extensions->noteFor('{{var x|escape}}{{if a}}Y{{/if}}'));
    }

    public function testARegisteredDirectiveIsReportedWhenATemplateUsesIt(): void
    {
        $extensions = $this->extensions(['mydir' => $this->processor('mydir')]);

        self::assertSame(['mydir'], $extensions->directives());
        self::assertSame(['directives' => ['mydir'], 'modifiers' => []], $extensions->usedBy('a{{mydir "v" p=1}}b{{/mydir}}'));

        $note = $extensions->noteFor('a{{mydir "v"}}b');
        self::assertNotNull($note);
        self::assertStringContainsString('{{mydir}}', $note);
        self::assertStringContainsString('does not implement', $note);
    }

    public function testARegisteredModifierIsReportedWhenATemplateUsesIt(): void
    {
        $extensions = $this->extensions([], ['foofilter' => $this->filter('foofilter')]);

        self::assertSame(['foofilter'], $extensions->modifiers());

        $note = $extensions->noteFor('{{var x|foofilter}}');
        self::assertNotNull($note);
        self::assertStringContainsString('|foofilter', $note);
        self::assertStringContainsString('skips', $note);
    }

    /**
     * The note says the store has it, not what this engine does with it.
     *
     * Which depends on the shape: `{{mydir "v"}}` comes back as its own text, and
     * `{{mydir}}x{{/mydir}}` is REFUSED, because the closing tag has no opener this engine
     * knows about. Naming one outcome would be wrong half the time.
     */
    public function testTheNoteDoesNotClaimAnOutcomeThatDependsOnTheShape(): void
    {
        $extensions = $this->extensions(['mydir' => $this->processor('mydir')]);

        foreach (['{{mydir "v"}}', '{{mydir}}x{{/mydir}}'] as $template) {
            $note = $extensions->noteFor($template);
            self::assertNotNull($note, $template);
            self::assertStringNotContainsString('verbatim', $note);
            self::assertStringNotContainsString('refus', $note);
        }
    }

    /** A store that has an extension is only interesting for templates that USE it. */
    public function testAnUnusedExtensionIsNotReported(): void
    {
        $extensions = $this->extensions(['mydir' => $this->processor('mydir')], ['foofilter' => $this->filter('foofilter')]);

        self::assertNull($extensions->noteFor('{{var x}}{{if a}}Y{{/if}}'));
    }

    /** A name that merely starts the same is a different directive. */
    public function testAPrefixIsNotAMatch(): void
    {
        $extensions = $this->extensions(['my' => $this->processor('my')]);

        self::assertNull($extensions->noteFor('{{mydir "v"}}'));
        self::assertNotNull($extensions->noteFor('{{my "v"}}'));
    }

    /** No store, or a pool this cannot read, reports nothing rather than guessing. */
    public function testNoStoreReportsNothing(): void
    {
        $extensions = new HostExtensions(MagentoContext::unavailable('no store in tests'));

        self::assertSame([], $extensions->directives());
        self::assertSame([], $extensions->modifiers());
        self::assertNull($extensions->noteFor('{{mydir}}'));
    }
}
