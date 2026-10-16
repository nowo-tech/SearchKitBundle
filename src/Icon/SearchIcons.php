<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Icon;

use Symfony\UX\Icons\IconRendererInterface;
use Throwable;
use Twig\Markup;

/**
 * Icons of the search box: the toggle states (search / close / loading), one per row kind, and the per-row icons
 * providers name. With `icon_set: ux_icon` and symfony/ux-icons installed they come from the application's icon
 * set; otherwise (or when a name is missing from the set) the built-in SVG is used.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchIcons
{
    private const string SVG_OPEN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" class="nowo-search__svg">';

    /** Built-in SVG bodies (Tabler outline shapes, MIT). */
    public const array BUILTIN = [
        'search'  => '<circle cx="10" cy="10" r="7"/><path d="m21 21-6-6"/>',
        'close'   => '<path d="M18 6 6 18M6 6l12 12"/>',
        'loading' => '<path d="M12 3a9 9 0 1 0 9 9"/>',
        'pages'   => '<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2z"/>',
        'recent'  => '<path d="M12 8v4l2 2"/><path d="M3.05 11a9 9 0 1 1 .5 4m-.5 5v-5h5"/>',
        'action'  => '<path d="M5 12h14M13 18l6-6M13 6l6 6"/>',
        'item'    => '<circle cx="12" cy="12" r="3"/>',
    ];

    /**
     * @param string $iconSet `ux_icon` or `svg_inline`
     * @param array<string, string> $icons Icon key → UX Icons name
     * @param IconRendererInterface|null $renderer UX Icons renderer; null when symfony/ux-icons is not installed
     */
    public function __construct(
        private string $iconSet = 'ux_icon',
        private array $icons = [],
        private ?IconRendererInterface $renderer = null,
    ) {
    }

    /**
     * Every icon key (built-in and configured) with its SVG, for the `<template>`s of the header partial.
     *
     * @return array<string, Markup> Icon key → SVG markup
     */
    public function all(): array
    {
        $rendered = [];
        foreach (array_keys(self::BUILTIN + $this->icons) as $key) {
            $rendered[$key] = new Markup($this->forKey($key), 'UTF-8');
        }

        return $rendered;
    }

    /**
     * SVG of one icon key: the configured UX icon, else the built-in shape (the generic `item` dot when unknown).
     *
     * @param string $key Icon key or row kind
     *
     * @return string SVG markup
     */
    public function forKey(string $key): string
    {
        $name = $this->icons[$key] ?? null;

        return ($name !== null ? $this->render($name) : null)
            ?? self::SVG_OPEN . (self::BUILTIN[$key] ?? self::BUILTIN['item']) . '</svg>';
    }

    /**
     * Renders one UX Icons name (a row icon named by a provider).
     *
     * @param string $name UX Icons name, e.g. `tabler:bug`
     *
     * @return string|null SVG markup, or null with `svg_inline`, without symfony/ux-icons, or when the icon cannot be rendered
     */
    public function render(string $name): ?string
    {
        if ($this->iconSet !== 'ux_icon' || !$this->renderer instanceof IconRendererInterface) {
            return null;
        }
        try {
            return $this->renderer->renderIcon($name, ['aria-hidden' => 'true', 'focusable' => 'false', 'class' => 'nowo-search__svg']);
        } catch (Throwable) {
            // Unknown name, invalid name or icon source failure: the row keeps its kind icon, the search still answers.
            return null;
        }
    }
}
