<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento\Plugin;

use Magento\Email\Model\Template\Filter;

/**
 * The Email filter's own setters, captured for TemplateFilterPlugin.
 *
 * TemplateFilterPlugin is declared on `Framework\Filter\Template`, and Magento's compiler
 * refuses a plugin method for a method the class it is declared on does not have -
 * setPlainTemplateMode() and setDesignParams() are the Email filter's. So these two are
 * declared here, on the Email filter, and handed to the same shared plugin instance the
 * filter's interceptor uses.
 */
class EmailFilterStatePlugin
{
    public function __construct(private readonly TemplateFilterPlugin $filterPlugin)
    {
    }

    /**
     * `$plain` is untyped because the method it plugs into is: forwarded as given, cast only
     * for the copy kept.
     *
     * @return array{0:mixed}
     */
    public function beforeSetPlainTemplateMode(Filter $subject, $plain): array
    {
        $this->filterPlugin->rememberPlainTemplateMode($subject, (bool)$plain);

        return [$plain];
    }

    /**
     * @param array<string,mixed> $designParams
     * @return array{0:array<string,mixed>}
     */
    public function beforeSetDesignParams(Filter $subject, array $designParams): array
    {
        $this->filterPlugin->rememberDesignParams($subject, $designParams);

        return [$designParams];
    }
}
