<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\LoginType;
use App\Search\DemoData;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Demo for NowoSearchKitBundle: landing page, in-memory sign-in and the pages the search results open.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DemoController extends AbstractController
{
    private const string LOCALE = 'en|es';

    #[Route('/', name: 'homepage_default', methods: ['GET'])]
    public function homeDefault(): Response
    {
        return $this->redirectToRoute('homepage', ['_locale' => 'en'], Response::HTTP_FOUND);
    }

    #[Route('/{_locale}', name: 'homepage', requirements: ['_locale' => self::LOCALE], methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('demo/home.html.twig');
    }

    #[Route('/{_locale}/projects/{id}', name: 'demo_project', requirements: ['_locale' => self::LOCALE], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function project(string $id): Response
    {
        $user     = $this->getUser();
        $projects = $user === null ? [] : DemoData::projectsFor($user);
        if (!isset($projects[$id])) {
            // Same answer for unknown and forbidden projects: no existence oracle.
            throw new NotFoundHttpException();
        }

        return $this->render('demo/page.html.twig', ['title' => $projects[$id][0], 'text' => $projects[$id][1]]);
    }

    #[Route('/{_locale}/docs/{slug}', name: 'demo_doc', requirements: ['_locale' => self::LOCALE], methods: ['GET'])]
    public function doc(string $slug): Response
    {
        if (!isset(DemoData::DOCS[$slug])) {
            throw new NotFoundHttpException();
        }

        return $this->render('demo/page.html.twig', ['title' => DemoData::DOCS[$slug][0], 'text' => DemoData::DOCS[$slug][1]]);
    }

    #[Route('/{_locale}/users', name: 'demo_users', requirements: ['_locale' => self::LOCALE], methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function users(): Response
    {
        return $this->render('demo/page.html.twig', ['title' => 'Users', 'text' => implode(', ', DemoData::USERS)]);
    }

    #[Route('/{_locale}/login', name: 'demo_login', requirements: ['_locale' => self::LOCALE], methods: ['GET', 'POST'])]
    public function login(Request $request, AuthenticationUtils $auth): Response
    {
        $last = $auth->getLastUsername();

        $form = $this->createForm(LoginType::class, null, [
            'action'        => $this->generateUrl('demo_login', ['_locale' => $request->getLocale()]),
            'method'        => 'POST',
            'last_username' => '' === $last ? 'alice' : $last,
            'target_path'   => $this->generateUrl('homepage', ['_locale' => $request->getLocale()]),
        ]);

        return $this->render('demo/login.html.twig', [
            'login_form'  => $form,
            'login_error' => $auth->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'demo_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by the security logout listener.');
    }
}
