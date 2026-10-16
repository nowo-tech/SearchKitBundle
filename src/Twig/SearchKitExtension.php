<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Twig;

use Nowo\SearchKitBundle\Contract\SearchScopeResolverInterface;
use Nowo\SearchKitBundle\Controller\SearchController;
use Nowo\SearchKitBundle\Form\SearchFieldType;
use Nowo\SearchKitBundle\Icon\SearchIcons;
use Nowo\SearchKitBundle\Model\SearchItem;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

use function is_array;
use function is_string;

/**
 * Twig functions of the header partial (`@NowoSearchKitBundle/search.html.twig`):
 *
 * - `nowo_search_kit_config(actions = [])`: what the browser needs (endpoint, limits, scope, quick actions, UI
 *   strings, lazy stylesheet URL), or null for guests and users without `role` (the partial then renders nothing);
 * - `nowo_search_kit_scope()`: the scope of the current page (`{id, label}`) or null;
 * - `nowo_search_kit_form()`: the Form view of the search field;
 * - `nowo_search_kit_icons()`: icon key → SVG markup (UX Icons or the built-in fallback).
 *
 * Stateless (no per-request caches): safe in FrankenPHP worker mode.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class SearchKitExtension extends AbstractExtension
{
    public const string DOMAIN = 'NowoSearchKitBundle';

    /**
     * @param string $secret Keys the per-user suffix of the recents storage key (`kernel.secret`)
     * @param array{role: string, min_length: int, max_length: int, debounce_ms: int, keyboard_shortcuts: bool, recent: array{enabled: bool, max: int, storage_key: string}, quick_actions: list<array{label: string, translation_domain: string|null, route: string|null, route_parameters: array<string, scalar>, url: string|null, kind: string, role: string|null}>, assets: array{stylesheet: bool, script: bool}} $settings Bundle configuration
     */
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        private readonly SearchScopeResolverInterface $scopes,
        private readonly FormFactoryInterface $forms,
        private readonly SearchIcons $icons,
        private readonly Packages $packages,
        private readonly array $settings,
        private readonly string $secret = '',
    ) {
    }

    /**
     * Registers the four functions.
     *
     * @return list<TwigFunction> The Twig functions
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_search_kit_config', $this->config(...)),
            new TwigFunction('nowo_search_kit_scope', $this->scope(...)),
            new TwigFunction('nowo_search_kit_form', $this->form(...)),
            new TwigFunction('nowo_search_kit_icons', $this->icons->all(...)),
        ];
    }

    /**
     * Browser configuration of the search box.
     *
     * @param array<mixed> $actions Extra quick actions from the host template: `{title, url, subtitle?, kind?}` each
     *                              (rows with an unsafe URL or no title are dropped); listed before the configured ones
     *
     * @return array<string, mixed>|null The configuration, or null when the user may not search
     */
    public function config(array $actions = []): ?array
    {
        if (!$this->canSearch()) {
            return null;
        }
        $settings = $this->settings;
        $user     = $this->security->getUser();
        // Recents are per browser: a per-user suffix keeps one account's picks away from the next on a shared device
        // (keyed hash: the identifier itself never reaches the browser).
        $storageKey = $settings['recent']['storage_key'] . '.' . substr(hash_hmac('sha256', $user instanceof UserInterface ? $user->getUserIdentifier() : '', $this->secret), 0, 16);

        return [
            'url'        => $this->urls->generate(SearchController::ROUTE),
            'minLength'  => $settings['min_length'],
            'maxLength'  => $settings['max_length'],
            'debounceMs' => $settings['debounce_ms'],
            'shortcuts'  => $settings['keyboard_shortcuts'],
            'scope'      => $this->scope(),
            'actions'    => [...$this->hostActions($actions), ...$this->configuredActions()],
            'recent'     => [
                'enabled'    => $settings['recent']['enabled'],
                'max'        => $settings['recent']['max'],
                'storageKey' => $storageKey,
            ],
            'assets'     => $settings['assets'],
            'popoverCss' => $settings['assets']['stylesheet'] ? $this->packages->getUrl('css/nowo-search-popover.css', 'nowo_search_kit') : null,
            'labels'     => $this->labels(),
        ];
    }

    /**
     * Scope of the current page, from the application's SearchScopeResolverInterface.
     *
     * @return array{id: string, label: string}|null The scope, or null (no request, no user, or nothing to scope to)
     */
    public function scope(): ?array
    {
        $request = $this->requestStack->getMainRequest();
        $user    = $this->security->getUser();
        if (!$request instanceof Request || !$user instanceof UserInterface) {
            return null;
        }

        return $this->scopes->resolve($request, $user)?->toArray();
    }

    /**
     * A fresh view of the search field (a view renders once).
     *
     * @return FormView The form view (child `q`)
     */
    public function form(): FormView
    {
        return $this->forms->create(SearchFieldType::class)->createView();
    }

    /**
     * Whether the current user may use the search box.
     */
    private function canSearch(): bool
    {
        return $this->security->getUser() instanceof UserInterface && $this->security->isGranted($this->settings['role']);
    }

    /**
     * @param array<mixed> $actions
     *
     * @return list<array{title: string, subtitle: string, url: string, kind: string}>
     */
    private function hostActions(array $actions): array
    {
        $rows = [];
        foreach ($actions as $action) {
            if (!is_array($action) || !is_string($action['title'] ?? null) || $action['title'] === '' || !is_string($action['url'] ?? null) || !SearchItem::isSafeUrl($action['url'])) {
                continue;
            }
            $rows[] = [
                'title'    => $action['title'],
                'subtitle' => is_string($action['subtitle'] ?? null) ? $action['subtitle'] : '',
                'url'      => $action['url'],
                'kind'     => is_string($action['kind'] ?? null) ? $action['kind'] : 'action',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{title: string, subtitle: string, url: string, kind: string}>
     */
    private function configuredActions(): array
    {
        $rows = [];
        foreach ($this->settings['quick_actions'] as $action) {
            if ($action['role'] !== null && !$this->security->isGranted($action['role'])) {
                continue;
            }
            $rows[] = [
                'title'    => $this->translator->trans($action['label'], [], $action['translation_domain']),
                'subtitle' => '',
                'url'      => $action['route'] !== null ? $this->urls->generate($action['route'], $action['route_parameters']) : (string) $action['url'],
                'kind'     => $action['kind'],
            ];
        }

        return $rows;
    }

    /**
     * UI strings of the browser script (placeholders `__QUERY__` / `__COUNT__` are filled client side).
     *
     * @return array<string, string>
     */
    private function labels(): array
    {
        $t = fn (string $key, array $parameters = []): string => $this->translator->trans('search.' . $key, $parameters, self::DOMAIN);

        return [
            'empty'       => $t('empty', ['%query%' => '__QUERY__']),
            'minChars'    => $t('min_chars', ['%min%' => $this->settings['min_length']]),
            'error'       => $t('error'),
            'rateLimited' => $t('rate_limited'),
            'loading'     => $t('loading'),
            'results'     => $t('results', ['%count%' => '__COUNT__']),
            'recent'      => $t('group.recent'),
            'actions'     => $t('group.actions'),
            'clearRecent' => $t('clear_recent'),
        ];
    }
}
