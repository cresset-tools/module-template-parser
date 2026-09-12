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

    /** @var array<string,mixed> */
    private array $designParams = [];

    /**
     * The state each in-flight filter() call captured, innermost last.
     *
     * filter() is RE-ENTRANT: a {{template}} include builds a child model, and that child
     * calls setVariables() and filter() of its own in the middle of its parent's filter().
     * With a single slot the child's variables overwrite the parent's, and by the time the
     * parent's afterFilter runs it compares the parent's template against the CHILD's scope -
     * which reported 79 divergences on a stock store, none of them a disagreement between the
     * engines. A stack because the nesting is a stack.
     *
     * @var list<array{0:array<string,mixed>,1:bool,2:array<string,mixed>}>
     */
    private array $inFlight = [];

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
     *
     * `$plain` is untyped because the method it plugs into is: forwarded as given, cast only
     * for the copy kept here.
     *
     * @return array{0:mixed}
     */
    public function beforeSetPlainTemplateMode(LegacyTemplate $subject, $plain): array
    {
        $this->plainTemplateMode = (bool)$plain;

        return [$plain];
    }

    /**
     * Captured for the same reason the variables and the plain flag are: it is set on the
     * subject, and by the time filter() returns the emulation it was taken inside is gone.
     *
     * @param array<string,mixed> $designParams
     * @return array{0:array<string,mixed>}
     */
    public function beforeSetDesignParams(LegacyTemplate $subject, array $designParams): array
    {
        $this->designParams = $designParams;

        return [$designParams];
    }

    /**
     * Snapshots the scope this invocation will be compared against.
     *
     * Taken on the way IN, because by the time filter() returns an include may have replaced
     * everything captured above with its own.
     *
     * @return array{0:string}
     */
    public function beforeFilter(LegacyTemplate $subject, $value): array
    {
        $this->inFlight[] = [$this->variables, $this->plainTemplateMode, $this->designParams];

        return [$value];
    }

    /**
     * Compares, and returns the legacy result.
     *
     * The comparator returns its input unchanged on every path, so enabling this changes
     * nothing a customer sees; the candidate render exists only to be logged when it differs.
     */
    public function afterFilter(LegacyTemplate $subject, string $result, string $value): string
    {
        // A CHILD render is not a document, and comparing one is a false positive by
        // construction. `Framework\Filter\Template` defers a directive it cannot finish in a
        // child - {{inlinecss}} being the one every stock email hits - by emitting a SIGNED
        // placeholder for the parent to resolve, and that signature is random per render. This
        // engine records the deferral structurally instead and emits nothing, so a child's
        // output can never match. The parent's comparison covers the same content, because the
        // parent's render contains the child's, so nothing goes unchecked by skipping.
        if (method_exists($subject, 'isChildTemplate') && $subject->isChildTemplate()) {
            array_pop($this->inFlight);

            return $result;
        }

        // The subject inlines its stylesheets before returning, so the result above is a
        // FINISHED document. This engine defers that step, so the candidate has to be put
        // through the same one or every template with a stylesheet reports as a divergence.
        $finish = method_exists($subject, 'applyInlineCss')
            ? static function (string $html) use ($subject): string {
                try {
                    return (string)$subject->applyInlineCss($html);
                } catch (\Throwable) {
                    // A candidate the inliner cannot process is compared as it stands; the
                    // comparison is the point, and a shadow run must never raise.
                    return $html;
                }
            }
            : null;

        // Whatever THIS invocation was called with, not whatever the last one left behind.
        [$variables, $plainTemplateMode, $designParams] = array_pop($this->inFlight)
            ?? [$this->variables, $this->plainTemplateMode, $this->designParams];

        return $this->comparator->compare($value, $result, $variables, $plainTemplateMode, $finish, $designParams);
    }
}
