<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Console;

/**
 * A PHP warning or notice that a store's error handler turns into an exception - and the
 * Email filter, catching it, into its error text in place of the template. See
 * StoreErrorHandler.
 */
final class StoreError extends \Exception
{
    private const LEVELS = [
        E_WARNING => 'Warning',
        E_NOTICE => 'Notice',
        E_DEPRECATED => 'Deprecated',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_USER_DEPRECATED => 'User Deprecated',
    ];

    public function __construct(
        public readonly int $level,
        public readonly string $warning,
        string $file = '',
        int $line = 0
    ) {
        parent::__construct(sprintf('%s: %s in %s on line %d', self::LEVELS[$level] ?? "Unknown error ($level)", $warning, $file, $line));
    }

    /** The warning without where it was raised, which differs between trees and machines. */
    public function described(): string
    {
        return (self::LEVELS[$this->level] ?? "Unknown error ({$this->level})") . ': ' . $this->warning;
    }
}
