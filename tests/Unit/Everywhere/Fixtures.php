<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Everywhere;

use Closure;
use Nowo\SearchKitBundle\Contract\SearchAccessCheckerInterface;
use Nowo\SearchKitBundle\Model\SearchAccessRequest;
use Nowo\SearchKitBundle\Search\Access\SearchAccess;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

use function in_array;
use function is_scalar;

/**
 * Builders shared by the 1.8.0 unit tests: a router, a translator, access checkers and a recording logger.
 */
final class Fixtures
{
    /**
     * A router with the test routes (`page`, `settings`, `issue`, `tab`, `post_only`).
     */
    public static function router(string $method = 'GET'): Router
    {
        $routes = new RouteCollection();
        $routes->add('page', new Route('/page'));
        $routes->add('settings', new Route('/settings/{section}'));
        $routes->add('issue', new Route('/issues/{id}', [], ['id' => '\d+']));
        $routes->add('tab', new Route('/projects/{id}/tabs/{tab}'));
        $routes->add('post_only', new Route('/post-only', [], [], [], '', [], ['POST']));
        $routes->add('defaults', new Route('/defaults', ['_controller' => 'x', 'flag' => true, 'list' => ['a']]));

        return new Router(new ClosureLoader(), static fn (): RouteCollection => $routes, [], new RequestContext('', $method));
    }

    /**
     * A translator with English and Spanish test messages.
     *
     * @param array<string, array<string, string>> $messages Locale → key → message
     */
    public static function translator(array $messages = []): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        foreach ($messages + [
            'en' => ['page.profile' => 'Profile', 'page.profile.kw' => 'password, avatar', 'crumb.settings' => 'Settings', 'search.group.pages' => 'Pages', 'search.group.actions' => 'Actions'],
            'es' => ['page.profile' => 'Perfil', 'page.profile.kw' => 'contraseña, clave', 'crumb.settings' => 'Ajustes'],
        ] as $locale => $catalogue) {
            $translator->addResource('array', $catalogue, $locale, 'messages');
            $translator->addResource('array', $catalogue, $locale, 'NowoSearchKitBundle');
        }

        return $translator;
    }

    /**
     * A checker answering with a closure.
     *
     * @param Closure(SearchAccessRequest): ?bool $decide
     */
    public static function checker(Closure $decide): SearchAccessCheckerInterface
    {
        return new class($decide) implements SearchAccessCheckerInterface {
            /** @var list<SearchAccessRequest> */
            public array $asked = [];

            /**
             * @param Closure(SearchAccessRequest): ?bool $decide
             */
            public function __construct(private readonly Closure $decide)
            {
            }

            public function decide(SearchAccessRequest $request): ?bool
            {
                $this->asked[] = $request;

                return ($this->decide)($request);
            }
        };
    }

    /**
     * Access that allows everything except the URLs listed.
     *
     * @param list<string> $denied
     */
    public static function access(array $denied = [], ?Router $router = null, ?AbstractLogger $logger = null): SearchAccess
    {
        return new SearchAccess([self::checker(static fn (SearchAccessRequest $request): bool => !in_array($request->url, $denied, true))], $router ?? self::router(), $logger);
    }

    /**
     * A logger that keeps the interpolated messages.
     */
    public static function logger(): RecordingLogger
    {
        return new RecordingLogger();
    }
}

/**
 * Keeps the log lines (`level: message` with the context interpolated).
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $replace = [];
        foreach ($context as $key => $value) {
            $replace['{' . $key . '}'] = is_scalar($value) ? (string) $value : get_debug_type($value);
        }
        $this->lines[] = $level . ': ' . strtr((string) $message, $replace);
    }
}
