<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento\Plugin;

use Cresset\TemplateParser\Magento\Shadow\TemplateIdentity;
use Magento\Email\Model\Template;

/**
 * Names an email SUBJECT before it is filtered, as `email:<id>/subject`.
 *
 * A separate plugin because `getProcessedTemplateSubject()` is declared on the concrete email
 * model, not on AbstractTemplate, and Magento's plugin validator refuses a method the
 * intercepted type does not have.
 */
class EmailSubjectIdentityPlugin
{
    public function __construct(private readonly TemplateIdentity $identity)
    {
    }

    /**
     * @param array<string,mixed> $variables
     */
    public function beforeGetProcessedTemplateSubject(Template $subject, array $variables): void
    {
        try {
            $this->identity->remember(
                (string)$subject->getTemplateSubject(),
                EmailTemplateIdentityPlugin::name($subject, '/subject')
            );
        } catch (\Throwable) {
            // Naming is a nicety; the render it precedes is not.
        }
    }
}
