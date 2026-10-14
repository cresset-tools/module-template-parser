<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Console\StoreError;
use MageOS\TemplateParser\Console\StoreErrorHandler;
use PHPUnit\Framework\TestCase;

/** A legacy render under the rules Magento\Framework\App\ErrorHandler applies on every store. */
final class StoreErrorHandlerTest extends TestCase
{
    public function testAWarningIsAnException(): void
    {
        try {
            StoreErrorHandler::run(static fn () => (string)[1]);
            self::fail('the warning was swallowed');
        } catch (StoreError $e) {
            self::assertSame('Warning: Array to string conversion', $e->described());
        }
    }

    public function testASuppressedWarningIsNot(): void
    {
        self::assertNull(StoreErrorHandler::run(static fn () => @$GLOBALS['no_such_variable_here']));
    }

    /** Magento lets these through: there is no telling a caught exception from a warning. */
    public function testADateTimeZoneWarningIsLetThrough(): void
    {
        self::assertTrue(StoreErrorHandler::run(static function (): bool {
            trigger_error('DateTimeZone::__construct(): Unknown or bad timezone', E_USER_NOTICE);
            return true;
        }));
    }

    public function testTheHandlerAndLevelAreRestored(): void
    {
        $level = error_reporting();
        $handler = set_error_handler(static fn () => true);
        restore_error_handler();

        try {
            StoreErrorHandler::run(static fn () => (string)[1]);
        } catch (StoreError) {
        }

        self::assertSame($level, error_reporting());
        $after = set_error_handler(static fn () => true);
        restore_error_handler();
        self::assertSame($handler, $after);
    }
}
