<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento\Plugin;

use Cresset\TemplateParser\Magento\Shadow\TemplateIdentity;
use Magento\Email\Model\AbstractTemplate;

/**
 * Names an email or newsletter body before it is filtered.
 *
 * `getProcessedTemplate()` passes `getTemplateText()` straight to the filter, so registering
 * that text here is what lets the filter plugin say which template it was handed. The id is
 * whatever the model holds: a number for a template saved in the admin, the config code -
 * `sales_email_order_template` - for one loaded from a module's files. Newsletter's model
 * extends this one, so a plugin here covers it too, under its own prefix.
 *
 * Runs in every stage, because a CMS render cannot learn its store before the filter does and
 * so neither side of the registry can be skipped by stage; the cost is one hash of the text.
 */
class EmailTemplateIdentityPlugin
{
    public function __construct(private readonly TemplateIdentity $identity)
    {
    }

    /**
     * @param array<string,mixed> $variables
     */
    public function beforeGetProcessedTemplate(AbstractTemplate $subject, array $variables = []): void
    {
        try {
            $this->identity->remember((string)$subject->getTemplateText(), self::name($subject));
        } catch (\Throwable) {
            // Naming is a nicety; the render it precedes is not.
        }
    }

    public static function name(object $template, string $suffix = ''): string
    {
        $prefix = is_a($template, 'Magento\Newsletter\Model\Template') ? 'newsletter' : 'email';
        $id = method_exists($template, 'getId') ? $template->getId() : null;

        return $prefix . ':' . (is_scalar($id) && (string)$id !== '' ? (string)$id : 'unsaved') . $suffix;
    }
}
