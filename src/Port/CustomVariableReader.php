<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Port;

/**
 * Reads a custom variable for {{customvar code="..."}}.
 */
interface CustomVariableReader
{
    public function value(string $code, bool $plainText): ?string;
}
