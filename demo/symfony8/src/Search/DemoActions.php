<?php

declare(strict_types=1);

namespace App\Search;

use Nowo\SearchKitBundle\Contract\SearchActionProviderInterface;
use Nowo\SearchKitBundle\Model\SearchAction;
use Nowo\SearchKitBundle\Model\SearchContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Palette actions of the demo (Ctrl K, then ">"): GET links only, each one access-checked by the bundle.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class DemoActions implements SearchActionProviderInterface
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function actions(SearchContext $context, ?string $scope): iterable
    {
        $locale = $context->locale ?? 'en';
        $t      = fn (string $key): string => $this->translator->trans($key, [], 'messages', $locale);

        yield new SearchAction($t('demo.palette.security'), $this->urls->generate('demo_settings', ['_locale' => $locale, 'section' => 'security']), ['password', 'contraseña', '2fa'], 'tabler:user', 'g s');
        yield new SearchAction($t('demo.palette.users'), $this->urls->generate('demo_users', ['_locale' => $locale]), ['team', 'equipo'], 'tabler:users', 'g u');
        yield new SearchAction($t('demo.palette.docs'), $this->urls->generate('demo_doc', ['_locale' => $locale, 'slug' => 'installation']), ['help', 'ayuda'], 'tabler:book');
        $project = $scope ?? (\in_array($context->routeName, ['demo_project', 'demo_project_tab'], true) ? (string) ($context->routeParameters['id'] ?? '') : null);
        if ($project !== null && $project !== '') {
            yield new SearchAction($t('demo.palette.members'), $this->urls->generate('demo_project_tab', ['_locale' => $locale, 'id' => $project, 'tab' => 'members']), ['team', 'equipo'], 'tabler:users', null, $project);
        }
    }
}
