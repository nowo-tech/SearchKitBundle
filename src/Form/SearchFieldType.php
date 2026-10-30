<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\SearchKitBundle\NowoSearchKitBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The search field of the header partial (`q`), rendered with `form_widget` (REQ-TWIG-005). It is never submitted:
 * the browser script reads it and calls the read-only GET endpoint, so the form has no CSRF token (REQ-SEC-005
 * covers mutations only).
 *
 * @extends AbstractType<array{q?: string|null}|null>
 */
#[FormKitConfig(NowoSearchKitBundle::FORM_KIT_PROFILE)]
final class SearchFieldType extends AbstractType
{
    use FormKitFieldTrait;
    use FormOptionsTrait;

    /**
     * Adds the `q` search input.
     *
     * @param FormBuilderInterface<array{q?: string|null}|null> $builder The form builder
     * @param array<string, mixed> $options The resolved options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addKitField($builder, 'q', SearchType::class, [
            'label'    => false,
            'required' => false,
            'attr'     => ['class' => 'nowo-search__input', 'data-part' => 'input', 'autocomplete' => 'off'],
        ]);
    }

    /**
     * Translation domain, no CSRF (never submitted), GET.
     *
     * @param OptionsResolver $resolver The options resolver
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoSearchKitBundle::TRANSLATION_DOMAIN,
            'csrf_protection'    => false,
            'method'             => 'GET',
        ]);
    }

    /**
     * Block prefix of the form theme and field names.
     *
     * @return string `nowo_search`
     */
    public function getBlockPrefix(): string
    {
        return 'nowo_search';
    }
}
