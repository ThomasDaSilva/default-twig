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
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiLabels;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Ai\AiJurisdiction;
use Thelia\Domain\Ai\AiProvider;

final class AiConnectionType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator, private readonly AiLabels $labels)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $providers = [];
        foreach (AiProvider::cases() as $provider) {
            $providers[$this->labels->provider($provider)] = $provider->value;
        }

        $builder
            ->add('title', TextType::class, [
                'constraints' => [new NotBlank(), new Length(max: 255)],
                'label' => $this->translator->trans('Name'),
                'help' => $this->translator->trans('The name you will recognise this connection by.'),
            ])
            ->add('code', TextType::class, [
                'constraints' => [new NotBlank(), new Regex('/^[a-z0-9][a-z0-9_-]{0,63}$/', $this->translator->trans('Lower case letters, digits, "-" and "_" only, 64 characters at most.'))],
                'label' => $this->translator->trans('Identifier'),
                'help' => $this->translator->trans('Short and without spaces, for example "mistral-main". Modules and the usage journal refer to the connection by it.'),
            ])
            ->add('provider', ChoiceType::class, [
                'choices' => $providers,
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Provider'),
                'help' => $this->translator->trans('The company or the server that runs the AI model.'),
            ])
            ->add('base_url', UrlType::class, [
                'required' => false,
                'default_protocol' => null,
                'constraints' => [new Length(max: 255)],
                'label' => $this->translator->trans('Server address'),
                'help' => $this->translator->trans('Leave empty to use the usual address of the provider. Fill it in for your own server or a compatible service.'),
            ])
            ->add('jurisdiction', ChoiceType::class, [
                'required' => false,
                'placeholder' => $this->translator->trans('Not needed for this provider'),
                'choices' => [
                    $this->translator->trans('In the European Union') => AiJurisdiction::EuropeanUnion->value,
                    $this->translator->trans('Outside the European Union') => AiJurisdiction::OutsideEuropeanUnion->value,
                ],
                'label' => $this->translator->trans('Where the data is processed'),
                'help' => $this->translator->trans('Only asked for a compatible service or your own server: tell where it runs. For the other providers it is deduced.'),
            ])
            ->add('api_key', PasswordType::class, [
                'required' => false,
                'always_empty' => true,
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [new Length(max: 2048)],
                'label' => $this->translator->trans('API key'),
                'help' => $this->translator->trans($options['has_key']
                    ? 'A key is saved. Leave empty to keep it, or type a new one to replace it. It is never shown again.'
                    : 'Paste the key given by the provider. It is stored encrypted and never shown again.'),
            ])
            ->add('api_key_env', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 255), new Regex('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->translator->trans('Letters, digits and underscores only.'))],
                'label' => $this->translator->trans('Environment variable holding the key'),
                'help' => $this->translator->trans('Optional, for a server that keeps its secrets out of the database: the name of the variable. When it is set, it wins over the saved key.'),
            ])
            ->add('allow_private_network', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('The server is on a private network'),
                'help' => $this->translator->trans('Tick only for a server of your own network (for example Ollama). Otherwise addresses of a private network are refused.'),
            ])
            ->add('active', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Connection in use'),
                'help' => $this->translator->trans('Untick to stop using this connection without deleting it.'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['has_key' => false, 'csrf_token_id' => 'admin.configuration.ai'])
            ->setAllowedTypes('has_key', 'bool');
    }
}
