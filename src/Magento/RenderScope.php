<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

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
 * TemplateFilterPlugin pushes the subject's before this engine renders and pops it after; a
 * stack, because renders nest. A port with no scope - the CLI, a test - uses its own default.
 */
class RenderScope
{
    /** @var list<array{storeId:int|string|null,urlModel:?object}> */
    private array $frames = [];

    public function push(int|string|null $storeId, ?object $urlModel): void
    {
        $this->frames[] = ['storeId' => $storeId, 'urlModel' => $urlModel];
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
}
