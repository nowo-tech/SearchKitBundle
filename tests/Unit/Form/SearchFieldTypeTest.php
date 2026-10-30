<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\SearchKitBundle\DependencyInjection\NowoSearchKitExtension;
use Nowo\SearchKitBundle\Form\SearchFieldType;
use Nowo\SearchKitBundle\NowoSearchKitBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\PreloadedExtension;

use function basename;
use function class_uses;
use function glob;

/**
 * REQ-TWIG-005 rule 11: every bundle form type (src/Form/*Type.php) is a FormKit Type on the bundle profile.
 */
final class SearchFieldTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function formTypes(): iterable
    {
        $files = glob(__DIR__ . '/../../../src/Form/*Type.php') ?: [];
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            /** @var class-string $class */
            $class = 'Nowo\\SearchKitBundle\\Form\\' . basename($file, '.php');

            yield $class => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('formTypes')]
    public function testFormTypeIsAFormKitType(string $class): void
    {
        $reflection = new ReflectionClass($class);
        self::assertTrue($reflection->implementsInterface(FormTypeInterface::class), $class);
        self::assertTrue(
            $reflection->isSubclassOf(FormKitAbstractType::class) || isset(class_uses($class)[FormOptionsTrait::class]),
            $class . ' must extend a FormKit base or use FormOptionsTrait',
        );
        $attributes = $reflection->getAttributes(FormKitConfig::class);
        self::assertCount(1, $attributes, $class . ' must carry #[FormKitConfig]');
        self::assertSame(NowoSearchKitBundle::FORM_KIT_PROFILE, $attributes[0]->newInstance()->name);
    }

    public function testBuildsTheGetSearchFieldThroughTheProfile(): void
    {
        $profile = NowoSearchKitExtension::formKitProfile();
        unset($profile['alias']);
        $type = new SearchFieldType();
        /** @var array<string, array{translation_domain: string, defaults: array{attr: array<string, mixed>, row_attr: array<string, mixed>}, field_types: array<string, array<string, mixed>>}> $profiles */
        $profiles = [NowoSearchKitBundle::FORM_KIT_PROFILE => $profile + ['by_form' => []]];
        $type->setFormOptionsMerger(new FormOptionsMerger($profiles, NowoSearchKitBundle::FORM_KIT_PROFILE, new ConstraintDefinitionFactory()));
        $form = Forms::createFormFactoryBuilder()->addExtension(new PreloadedExtension([$type], []))->getFormFactory()->create(SearchFieldType::class);

        self::assertSame('GET', $form->getConfig()->getMethod());
        self::assertFalse($form->getConfig()->getOption('csrf_protection'));
        $view = $form->createView();
        self::assertFalse($view->children['q']->vars['label']);
        self::assertSame('nowo-search__input', $view->children['q']->vars['attr']['class']);
        self::assertSame('NowoSearchKitBundle', $view->children['q']->vars['translation_domain']);
        self::assertArrayNotHasKey('placeholder', $view->children['q']->vars['attr']);
    }
}
