<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Twig;

use Nowo\SearchKitBundle\Contract\SearchContextScopeResolverInterface;
use Nowo\SearchKitBundle\Contract\SearchScopeResolverInterface;
use Nowo\SearchKitBundle\Controller\FilterValuesController;
use Nowo\SearchKitBundle\Controller\RecentController;
use Nowo\SearchKitBundle\Controller\ResultsController;
use Nowo\SearchKitBundle\Controller\SearchController;
use Nowo\SearchKitBundle\Form\SearchFieldType;
use Nowo\SearchKitBundle\Icon\SearchIcons;
use Nowo\SearchKitBundle\Model\SearchContext;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchScope;
use Nowo\SearchKitBundle\Scope\NullScopeResolver;
use Nowo\SearchKitBundle\Scope\SearchContextFactory;
use Nowo\SearchKitBundle\Search\Action\ActionCollector;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
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
 * 1.8.0: the empty-query list comes from the SearchActionProviderInterface services (context-aware, access-checked)
 * when there are any, the `quick_actions` otherwise; the configuration gains `actionPrefix`, `filters`, `results` and
 * the recents revalidation keys **only when those features are on**, so a 1.7 host renders the same attribute.
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
     * @param SearchScopeResolverInterface $scopes Deprecated resolver port (the host's alias, NullScopeResolver by default)
     * @param string $secret Keys the per-user suffix of the recents storage key (`kernel.secret`)
     * @param SearchContextScopeResolverInterface|null $contextScopes Resolver port on the SearchContext VO (1.7.0); wins
     *                                                                over $scopes when the host aliases it
     * @param ActionCollector|null $actions Provider actions / quick actions (1.8.0; the bundle always wires it)
     * @param CsrfTokenManagerInterface|null $csrf Token of the recents revalidation (1.8.0)
     * @param array{action_prefix?: string, filter_keys?: list<string>, results?: bool, revalidate?: bool} $features 1.8.0 switches
     * @param array{role: string, min_length: int, min_length_exempt_patterns?: list<string>, max_length: int, debounce_ms: int, keyboard_shortcuts: bool, recent: array{enabled: bool, max: int, storage_key: string}, quick_actions: list<array{label: string, translation_domain: string|null, route: string|null, route_parameters: array<string, scalar>, url: string|null, kind: string, role: string|null}>, assets: array{stylesheet: bool, script: bool, popover_stylesheet?: false|string|null}} $settings Bundle configuration
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
        private readonly ?SearchContextScopeResolverInterface $contextScopes = null,
        private readonly ?ActionCollector $actions = null,
        private readonly ?CsrfTokenManagerInterface $csrf = null,
        private readonly array $features = [],
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

        $scope  = $this->scope();
        $config = [
            'url'        => $this->urls->generate(SearchController::ROUTE),
            'minLength'  => $settings['min_length'],
            'maxLength'  => $settings['max_length'],
            'debounceMs' => $settings['debounce_ms'],
            'shortcuts'  => $settings['keyboard_shortcuts'],
            'scope'      => $scope,
            'actions'    => [...$this->hostActions($actions), ...$this->emptyQueryActions($user, $scope)],
            'recent'     => [
                'enabled'    => $settings['recent']['enabled'],
                'max'        => $settings['recent']['max'],
                'storageKey' => $storageKey,
            ],
            'assets'     => $settings['assets'],
            'popoverCss' => $this->popoverCss($settings['assets']),
            'labels'     => $this->labels(),
        ];
        // Absent, not empty: the rendered attribute stays byte-identical for a host that does not use the option.
        $exemptPatterns = $settings['min_length_exempt_patterns'] ?? [];
        if ($exemptPatterns !== []) {
            $config['minLengthExemptPatterns'] = $exemptPatterns;
        }

        return $this->features($config);
    }

    /**
     * Adds the keys of the 1.8.0 features that are on (none for a 1.7 configuration).
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function features(array $config): array
    {
        $t      = fn (string $key): string => $this->translator->trans('search.' . $key, [], self::DOMAIN);
        $prefix = $this->features['action_prefix'] ?? '';
        if ($prefix !== '' && $this->actions instanceof ActionCollector && $this->actions->hasProviders()) {
            $config['actionPrefix'] = $prefix;
            $config['labels']       = [...$config['labels'], 'actionsOnly' => $t('actions_only')];
        }
        $keys = $this->features['filter_keys'] ?? [];
        if ($keys !== []) {
            $config['filters'] = [
                'keys'      => $keys,
                'valuesUrl' => $this->urls->generate(FilterValuesController::ROUTE),
                'remove'    => $t('filter.remove'),
                'values'    => $t('group.filter_values'),
            ];
        }
        if ($this->features['results'] ?? false) {
            $config['results'] = ['url' => $this->urls->generate(ResultsController::ROUTE), 'label' => $t('see_all_results')];
        }
        if (($this->features['revalidate'] ?? false) && $config['recent']['enabled']) {
            $config['recent'] = [
                ...$config['recent'],
                'revalidateUrl' => $this->urls->generate(RecentController::ROUTE),
                'csrfToken'     => $this->csrf instanceof CsrfTokenManagerInterface ? $this->csrf->getToken(RecentController::CSRF_TOKEN_ID)->getValue() : '',
            ];
        }

        return $config;
    }

    /**
     * The empty-query rows: provider actions for this page (access-checked), or the quick actions.
     *
     * @param array{id: string, label: string}|null $scope
     *
     * @return list<array<string, string>>
     */
    private function emptyQueryActions(?UserInterface $user, ?array $scope): array
    {
        if (!$this->actions instanceof ActionCollector || !$this->actions->hasProviders() || !$user instanceof UserInterface) {
            return $this->actions?->quickActionRows() ?? [];
        }
        $request = $this->requestStack->getMainRequest();
        $context = $request instanceof Request ? SearchContextFactory::create($request, $user) : new SearchContext($user->getUserIdentifier(), '');

        return $this->actions->emptyQueryRows($context, $scope['id'] ?? null);
    }

    /**
     * Scope of the current page, from the application's resolver.
     *
     * Guests and calls outside a request get no scope and never reach a resolver. Otherwise one resolver decides, in
     * this order: the host's SearchContextScopeResolverInterface alias (when it is not the bundle default), then the
     * SearchScopeResolverInterface alias through resolveFor() when it implements the new port too, and last the
     * deprecated resolve(Request, UserInterface) of a resolver that implements only the old port.
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

        return $this->resolveScope($request, $user)?->toArray();
    }

    /**
     * Asks the one resolver that decides (see scope()). The context is built per call and never kept.
     */
    private function resolveScope(Request $request, UserInterface $user): ?SearchScope
    {
        if ($this->contextScopes instanceof SearchContextScopeResolverInterface && !$this->contextScopes instanceof NullScopeResolver) {
            return $this->contextScopes->resolveFor(SearchContextFactory::create($request, $user));
        }
        if ($this->scopes instanceof SearchContextScopeResolverInterface) {
            return $this->scopes->resolveFor(SearchContextFactory::create($request, $user));
        }

        trigger_deprecation('nowo-tech/search-kit-bundle', '1.7.0', 'The scope resolver "%s" implements only "%s", which is removed in 2.0. Implement "%s" instead.', $this->scopes::class, SearchScopeResolverInterface::class, SearchContextScopeResolverInterface::class);

        return $this->scopes->resolve($request, $user);
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
     * URL of the lazy dropdown stylesheet: the configured one, none (`false`), or the bundle file when the bundle
     * stylesheet is on.
     *
     * @param array{stylesheet: bool, script: bool, popover_stylesheet?: false|string|null} $assets
     */
    private function popoverCss(array $assets): ?string
    {
        $configured = $assets['popover_stylesheet'] ?? null;
        if ($configured === false || is_string($configured)) {
            return $configured === false ? null : $configured;
        }

        return $assets['stylesheet'] ? $this->packages->getUrl('css/nowo-search-popover.css', 'nowo_search_kit') : null;
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
