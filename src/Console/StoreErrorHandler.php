<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Console;

/**
 * Runs a legacy render the way a store runs it: every PHP error the error level reports is an
 * exception.
 *
 * Magento\Framework\App\ErrorHandler, which every store installs at bootstrap with E_ALL,
 * throws `\Exception` for any of them - and Email\Model\Template\Filter::filter() catches
 * that and replaces the WHOLE template with its error text. So `{{var a}}` holding an array,
 * or `{{var a.b}}` on an array with no `b`, is not "Array" or "" on a store: it is the error
 * page. Swallowing the warning instead, as the recorder and the CLI did, pinned output no
 * store produces - and compatible mode was built to reproduce it.
 *
 * The same rules as Magento's handler: a DateTimeZone::__construct warning is let through,
 * and an error the current level does not report (an `@`-suppressed one) is ignored.
 */
final class StoreErrorHandler
{
    /**
     * @template T
     * @param callable():T $render
     * @return T
     * @throws StoreError where the store's handler would have thrown
     */
    public static function run(callable $render): mixed
    {
        $level = error_reporting(E_ALL);
        set_error_handler(static function (int $number, string $message, string $file = '', int $line = 0): bool {
            if (str_contains($message, 'DateTimeZone::__construct')) {
                return false;
            }
            if (($number & error_reporting()) === 0) {
                return false;
            }
            throw new StoreError($number, $message, $file, $line);
        });

        try {
            return $render();
        } finally {
            restore_error_handler();
            error_reporting($level);
        }
    }
}
