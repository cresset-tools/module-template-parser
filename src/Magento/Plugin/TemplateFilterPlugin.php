<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento\Plugin;

use Cresset\TemplateParser\Magento\ShadowComparator;
use Magento\Framework\Filter\Template as LegacyTemplate;

/**
 * The integration point.
 *
 * A DI preference cannot be used to adopt this engine. Emails render through
 * Magento\Email\Model\Template\Filter, CMS extends that, and Newsletter extends
 * Widget\Model\Template\FilterEmulate - all concrete subclasses that DI instantiates
 * directly, so a preference for the Framework base class never applies. Interception is the
 * only mechanism that reaches them, which is what this is.
 *
 * It is inert until configured. Wire it in a project module against whichever filter you
 * want to cover, and switch it on through ShadowComparator's `enabled` argument:
 *
 *     <type name="Magento\Email\Model\Template\Filter">
 *         <plugin name="cresset_template_parser" sortOrder="10"
 *                 type="Cresset\TemplateParser\Magento\Plugin\TemplateFilterPlugin"/>
 *     </type>
 *
 * The variables are captured on the way past because the legacy filter keeps them in a
 * protected property with a setter and no getter, so an `after filter()` plugin cannot
 * otherwise see what the template was rendered with.
 */
class TemplateFilterPlugin
{
    /** @var array<string,mixed> */
    private array $variables = [];

    private bool $plainTemplateMode = false;

    public function __construct(private readonly ShadowComparator $comparator)
    {
    }

    /**
     * @param array<string,mixed> $variables
     * @return array{0:array<string,mixed>}
     */
    public function beforeSetVariables(LegacyTemplate $subject, array $variables): array
    {
        $this->variables = $variables;

        return [$variables];
    }

    /**
     * Captured for the same reason the variables are: it is set on the subject, not on us.
     *
     * getProcessedTemplate() calls setPlainTemplateMode() on the filter it holds - which is
     * the LEGACY filter, this being a plugin on it rather than a replacement for it - so
     * without capturing it here the candidate render would use the HTML value of every custom
     * variable while the legacy render used the text one, and every plain email would report
     * as a divergence caused by nothing.
     */
    public function beforeSetPlainTemplateMode(LegacyTemplate $subject, $plain): array
    {
        $this->plainTemplateMode = (bool)$plain;

        return [$plain];
    }

    /**
     * Compares, and returns whatever the comparator decides.
     *
     * In shadow mode that is always the legacy result, so enabling this changes nothing a
     * customer sees. The comparator is the only place that decides otherwise.
     */
    public function afterFilter(LegacyTemplate $subject, string $result, string $value): string
    {
        return $this->comparator->compare($value, $result, $this->variables, $this->plainTemplateMode);
    }
}
