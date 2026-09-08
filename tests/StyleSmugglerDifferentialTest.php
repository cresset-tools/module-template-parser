<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Ast\DirectiveNode;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\TemplateEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The case this engine exists for, as a paired differential.
 *
 * tools/record-stylesmuggler.php runs the StyleSmuggler payload through the REAL unpatched
 * Magento filter and records the outcome. This asserts two halves:
 *
 *   1. the recording really is the vulnerable behaviour - legacy mints a signature, the
 *      signature reaches attacker-controlled data, and the smuggled {{block}} executes;
 *   2. this engine, given the identical two-stage flow, executes nothing.
 *
 * Half 1 matters as much as half 2. Without it, half 2 could pass because the payload was
 * malformed rather than because the engine is sound.
 */
final class StyleSmugglerDifferentialTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $fixture;

    public static function setUpBeforeClass(): void
    {
        self::$fixture = json_decode(
            (string)file_get_contents(__DIR__ . '/fixtures/legacy/stylesmuggler.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    // -------------------------------------------------------------------------
    // Half 1: the recorded legacy behaviour is genuinely the vulnerability.
    // -------------------------------------------------------------------------

    public function testLegacyMintedASignatureIntoAttackerData(): void
    {
        $legacy = self::$fixture['legacy'];

        self::assertNotSame('', $legacy['signature']);
        self::assertTrue(
            $legacy['signature_in_address_html'],
            'the recording should show the per-request signature reaching the formatted address'
        );
        self::assertStringContainsString(
            $legacy['signature'] . '{{block',
            $legacy['address_html'],
            'the signature should end up bracketing the attacker\'s {{block}} directive'
        );
    }

    public function testLegacyExecutedTheSmuggledBlock(): void
    {
        $legacy = self::$fixture['legacy'];

        self::assertSame(
            [],
            $legacy['blocks_during_address'],
            'the address formatter has no blockDirective, so nothing runs there'
        );
        self::assertSame(
            ['Magento\Email\Block\Adminhtml\Template\Preview'],
            $legacy['blocks_during_email'],
            'the email filter should have executed the smuggled block'
        );
        self::assertStringContainsString('[BLOCK-RENDERED]', $legacy['email_output']);
    }

    // -------------------------------------------------------------------------
    // Half 2: the same flow through this engine executes nothing.
    // -------------------------------------------------------------------------

    /**
     * @return array<string,array{0:callable():TemplateEngine}>
     */
    public static function modes(): array
    {
        return [
            'compatible' => [static fn () => TemplateEngine::compatible()],
            'lenient'    => [static fn () => TemplateEngine::lenient()],
            'strict variables off' => [
                static fn () => TemplateEngine::withOptions(Options::strict()->withVariables(false)),
            ],
        ];
    }

    /** @param callable():TemplateEngine $factory */
    #[DataProvider('modes')]
    public function testThisEngineNeverExecutesTheSmuggledBlock(callable $factory): void
    {
        $executed = [];
        $engine = $factory();
        $engine->evaluator()->register('block', function (DirectiveNode $n) use (&$executed, $engine): string {
            $executed[] = $engine->evaluator()->params($n)['class'] ?? '';
            return '[BLOCK-RENDERED]';
        });

        // Stage 1: the address formatter, with the poisoned fields.
        $addressHtml = $engine->render(
            self::$fixture['address_format'],
            self::$fixture['address_variables']
        );

        self::assertSame([], $executed, 'nothing may execute while formatting the address');

        // Stage 2: the email render, taking that output as a variable - the exact step
        // where legacy executes the smuggled directive.
        $emailOutput = $engine->render(
            self::$fixture['email_template'],
            ['billingAddressHtml' => $addressHtml]
        );

        self::assertSame(
            [],
            $executed,
            'a directive that arrived through a variable was executed'
        );
        self::assertStringNotContainsString('[BLOCK-RENDERED]', $emailOutput);
    }

    /** There is no signature to smuggle, because the engine has no signature mechanism. */
    public function testNoSignatureAppearsAnywhere(): void
    {
        $engine = TemplateEngine::compatible();
        $signature = self::$fixture['legacy']['signature'];

        $addressHtml = $engine->render(self::$fixture['address_format'], self::$fixture['address_variables']);
        $emailOutput = $engine->render(self::$fixture['email_template'], ['billingAddressHtml' => $addressHtml]);

        foreach ([$addressHtml, $emailOutput] as $output) {
            self::assertStringNotContainsString($signature, $output);
            // Nor any 32-character token of the shape a signature would take.
            self::assertDoesNotMatchRegularExpression('/[A-Za-z0-9]{32}/', $output);
        }
    }

    /** The payload survives as literal text - it is customer data, so that is correct. */
    public function testThePayloadRendersAsInertText(): void
    {
        $engine = TemplateEngine::compatible();

        $addressHtml = $engine->render(self::$fixture['address_format'], self::$fixture['address_variables']);
        $emailOutput = $engine->render(self::$fixture['email_template'], ['billingAddressHtml' => $addressHtml]);

        self::assertStringContainsString(
            '{{block class=Magento\Email\Block\Adminhtml\Template\Preview}}',
            $emailOutput,
            'the payload should appear verbatim, as the customer-supplied text it is'
        );
    }

    /**
     * Legacy's over-long signed match swallowed the city, so a poisoned address rendered
     * with fields missing. Here the address renders complete - a useful post-fix tell.
     */
    public function testTheAddressRendersCompletely(): void
    {
        $legacyAddress = self::$fixture['legacy']['address_html'];
        $ours = TemplateEngine::compatible()->render(
            self::$fixture['address_format'],
            self::$fixture['address_variables']
        );

        self::assertStringNotContainsString('Andorra la Vella', $legacyAddress, 'legacy loses the city');
        self::assertStringContainsString('Andorra la Vella', $ours, 'this engine keeps it');
    }
}
