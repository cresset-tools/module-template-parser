<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

/**
 * Lets the console serve its own candidate through a filter's real plugin chain, once.
 *
 * `template:diff` renders "today" through the store's filter, so every other module's plugin
 * on `filter()` runs on it - Page Builder's `afterFilter`, which decodes its HTML Code
 * elements, among them. The candidate was this engine's bare output, and 427 of 578 CMS items
 * on one store differed for that reason alone. Calling `filter()` again with this set makes
 * TemplateFilterPlugin hand the render to `$render` where it would have run this engine, so
 * the candidate gets exactly the plugins a Parser render gets, in the same order.
 *
 * Shared, like LegacyOnlyRenders, so the console and the plugin see the same object.
 */
class CandidateRenders
{
    private ?object $subject = null;

    /** @var ?\Closure(string):mixed */
    private ?\Closure $render = null;

    /**
     * Runs `$call`, which calls `$subject->filter()`, with `$render` standing in for this
     * engine on that one filter, for the first filter() call on it to reach the plugin.
     *
     * @param callable(string):mixed $render given the value as the plugins before this one
     *        left it
     * @param callable():mixed $call
     * @return array{0:mixed,1:bool} what `$call` returned, and whether `$render` was used -
     *         false when the plugin is not on the filter's chain, and the result is the
     *         filter's own
     */
    public function run(object $subject, callable $render, callable $call): array
    {
        $previous = [$this->subject, $this->render];
        $this->subject = $subject;
        $this->render = \Closure::fromCallable($render);
        try {
            $result = $call();

            return [$result, $this->render === null];
        } finally {
            [$this->subject, $this->render] = $previous;
        }
    }

    /**
     * The stand-in for this filter, if one is waiting - handed out once.
     *
     * @return ?\Closure(string):mixed
     */
    public function take(object $subject): ?\Closure
    {
        if ($this->render === null || $this->subject !== $subject) {
            return null;
        }
        $render = $this->render;
        $this->render = null;

        return $render;
    }
}
