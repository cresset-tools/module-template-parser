<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

/**
 * What the filter being stood in for knows about the render, for the ports that need it.
 *
 * The ports are shared and their signatures host-neutral, but two answers depend on WHICH
 * filter is rendering:
 *
 * - `{{store}}` builds its URL with the filter's own URL model. Email\Model\Template gives
 *   its filter the frontend model; a CMS filter built in the admin gets the backend one, so
 *   an admin preview links into the admin. One shared model cannot be both.
 * - `{{widget}}` gets `store_id` from the filter's store, when the filter has one and the
 *   template did not say - generateWidget does exactly that, and a CMS block widget or a
 *   product list reads it.
 *
 * - `{{template}}` includes are loaded and rendered here, so the directives only that filter
 *   can render - a module's own, a plugged stock one - have to be refused inside an include
 *   as they are at the top level, and which those are depends on the filter's class.
 *
 * TemplateFilterPlugin pushes the subject's before this engine renders and pops it after; a
 * stack, because renders nest. A port with no scope - the CLI, a test - uses its own default.
 */
class RenderScope
{
    /** @var list<array{storeId:int|string|null,urlModel:?object,hostOnly:list<string>}> */
    private array $frames = [];

    /** @param list<string> $hostOnlyDirectives directive names only the rendering filter renders */
    public function push(int|string|null $storeId, ?object $urlModel, array $hostOnlyDirectives = []): void
    {
        $this->frames[] = ['storeId' => $storeId, 'urlModel' => $urlModel, 'hostOnly' => $hostOnlyDirectives];
    }

    public function pop(): void
    {
        array_pop($this->frames);
    }

    /** The rendering filter's `_storeId` as it stood, or null when it had none. */
    public function storeId(): int|string|null
    {
        return $this->frames === [] ? null : $this->frames[array_key_last($this->frames)]['storeId'];
    }

    /** The rendering filter's own URL model, or null to use the port's default. */
    public function urlModel(): ?object
    {
        return $this->frames === [] ? null : $this->frames[array_key_last($this->frames)]['urlModel'];
    }

    /** @return list<string> the directives only the rendering filter itself can render */
    public function hostOnlyDirectives(): array
    {
        return $this->frames === [] ? [] : $this->frames[array_key_last($this->frames)]['hostOnly'];
    }
}
