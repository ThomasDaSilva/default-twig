<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\Form\Configuration\Ai;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiModelType extends AbstractType
{
    private const string PRICE_PATTERN = '/^\d{1,8}(\.\d{1,6})?$/';

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $price = fn (string $label, string $help, bool $required): array => [
            'required' => $required,
            'constraints' => array_filter([
                $required ? new NotBlank() : null,
                new Regex(self::PRICE_PATTERN, $this->translator->trans('A price such as 0.25 or 3 (at most six decimals).')),
            ]),
            'label' => $this->translator->trans($label),
            'help' => $this->translator->trans($help),
            'attr' => ['inputmode' => 'decimal'],
        ];

        $builder
            ->add('provider', HiddenType::class, ['constraints' => [new NotBlank()]])
            ->add('connection_id', HiddenType::class, ['required' => false])
            ->add('name', TextType::class, [
                'constraints' => [new NotBlank(), new Length(max: 128)],
                'attr' => $options['name_locked'] ? ['readonly' => true] : [],
                'label' => $this->translator->trans('Model identifier'),
                'help' => $this->translator->trans('The exact name the provider gives the model, for example "mistral-small-latest".'),
            ])
            ->add('title', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 255)],
                'label' => $this->translator->trans('Display name'),
            ])
            ->add('input_price', TextType::class, $price('Price of the text you send', 'In dollars per million tokens (a token is about three quarters of a word).', true))
            ->add('output_price', TextType::class, $price('Price of the text the model writes', 'In dollars per million tokens.', true))
            ->add('cache_read_price', TextType::class, $price('Price of text read again from memory', 'Optional. When the provider remembers a text already sent, it bills it less. Empty means the price of the text you send.', false))
            ->add('cache_write_price', TextType::class, $price('Price of text kept in memory', 'Optional. Only some providers bill this. Empty means the price of the text you send.', false))
            ->add('currency', ChoiceType::class, [
                'choices' => ['USD' => 'USD', 'EUR' => 'EUR'],
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Currency of the prices'),
            ])
            ->add('context_window', IntegerType::class, [
                'required' => false,
                'constraints' => [new GreaterThan(0)],
                'label' => $this->translator->trans('Size of the text the model can read at once'),
                'help' => $this->translator->trans('In tokens. Optional.'),
            ])
            ->add('max_output_tokens', IntegerType::class, [
                'required' => false,
                'constraints' => [new GreaterThan(0)],
                'label' => $this->translator->trans('Longest answer'),
                'help' => $this->translator->trans('In tokens. Optional.'),
            ])
            ->add('supports_structured_output', CheckboxType::class, ['required' => false, 'label' => $this->translator->trans('Can answer in a strict format'), 'help' => $this->translator->trans('Needed by the features that read the answer as data.')])
            ->add('supports_tools', CheckboxType::class, ['required' => false, 'label' => $this->translator->trans('Can use tools'), 'help' => $this->translator->trans('Needed by an assistant that acts in the shop.')])
            ->add('supports_vision', CheckboxType::class, ['required' => false, 'label' => $this->translator->trans('Can read images')])
            ->add('supports_streaming', CheckboxType::class, ['required' => false, 'label' => $this->translator->trans('Can answer word by word')])
            ->add('active', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Model available'),
                'help' => $this->translator->trans('Untick to hide this model from the choices.'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['name_locked' => false, 'csrf_token_id' => 'admin.configuration.ai'])
            ->setAllowedTypes('name_locked', 'bool');
    }
}
