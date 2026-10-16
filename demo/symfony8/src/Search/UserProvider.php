<?php

declare(strict_types=1);

namespace App\Search;

use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Users" group: administrators only (everyone else never sees the group, not even empty).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsTaggedItem(priority: 10)]
final readonly class UserProvider implements SearchProviderInterface
{
    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private RequestStack $requests,
    ) {
    }

    public function search(SearchQuery $query): ?SearchGroup
    {
        if ($query->isScoped() || !$this->security->isGranted('ROLE_ADMIN')) {
            return null;
        }
        $locale = $this->requests->getCurrentRequest()?->getLocale() ?? 'en';
        $items  = [];
        foreach (DemoData::USERS as $username => $name) {
            if ($query->matches($username, $name)) {
                $items[] = new SearchItem($name, $this->urls->generate('demo_users', ['_locale' => $locale, 'q' => $username]), $username, 'tabler:user');
            }
        }

        return new SearchGroup('users', $this->translator->trans('demo.group.users'), $items);
    }
}
