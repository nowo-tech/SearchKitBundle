<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Twig;

use Nowo\UiKitBundle\Theme\Theme;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Optional UiKit theme integration of the header search widget (REQ-UI-003 "theme slots"):
 *
 * - `nowo_search_kit_ui_class(slot)`: the classes the host configured for a UiKit theme slot
 *   (`nowo_ui_kit.theme.classes.<slot>`, or the per-bundle exception `theme.bundles.NowoSearchKitBundle.classes.<slot>`);
 * - `nowo_search_kit_ui_attrs(slot)`: the attributes configured for that slot (` name="value"…`, escaped).
 *
 * Both return an empty string when `nowo-tech/ui-kit-bundle` is not installed (the service is optional), so the bundle
 * keeps working without UiKit. Only the **host** classes are emitted — no UiKit semantic or framework default classes
 * (`form-control`, `btn` …): the compact header widget keeps its own geometry and is painted by nowo-search.css from
 * the `--nowo-search-*` tokens (which default to the UiKit `--nowo-ui-*` tokens).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class SearchKitThemeExtension extends AbstractExtension
{
    /** Bundle name of the UiKit per-bundle theme exceptions. */
    public const string BUNDLE = 'NowoSearchKitBundle';

    /**
     * @param Theme|null $theme The UiKit theme (`nowo_ui_kit.theme`); null without UiKit
     */
    public function __construct(private readonly ?Theme $theme = null)
    {
    }

    /**
     * Registers `nowo_search_kit_ui_class()` and `nowo_search_kit_ui_attrs()`.
     *
     * @return list<TwigFunction> The Twig functions
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_search_kit_ui_class', $this->slotClass(...)),
            new TwigFunction('nowo_search_kit_ui_attrs', $this->slotAttributes(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Host classes of a UiKit theme slot, with a leading space when there are any (append it to the BEM class).
     *
     * @param string $slot UiKit slot name (`input`, `btn_icon`, `card`, `muted`, `badge_neutral`…)
     *
     * @return string `' host-class…'` or `''`
     */
    public function slotClass(string $slot): string
    {
        $classes = $this->theme instanceof Theme ? trim($this->theme->extraClasses($slot, self::BUNDLE)) : '';

        return $classes === '' ? '' : ' ' . $classes;
    }

    /**
     * Host attributes of a UiKit theme slot (leading space, escaped), or an empty string.
     *
     * @param string $slot UiKit slot name
     *
     * @return string The rendered attributes
     */
    public function slotAttributes(string $slot): string
    {
        return $this->theme instanceof Theme ? $this->theme->renderAttributes($slot, self::BUNDLE) : '';
    }
}
