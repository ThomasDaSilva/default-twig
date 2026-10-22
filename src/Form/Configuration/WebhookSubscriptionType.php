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

namespace BackOfficeDefaultTwigBundle\Form\Configuration;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Webhook\WebhookEventCatalog;
use Thelia\Domain\Webhook\WebhookStatus;

final class WebhookSubscriptionType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly WebhookEventCatalog $catalog,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $statuses = [
            $this->translator->trans('Active') => WebhookStatus::SUBSCRIPTION_ACTIVE,
            $this->translator->trans('Inactive') => WebhookStatus::SUBSCRIPTION_INACTIVE,
        ];

        if ($options['include_error']) {
            $statuses[$this->translator->trans('In error')] = WebhookStatus::SUBSCRIPTION_ERROR;
        }

        $builder
            ->add('title', TextType::class, [
                'constraints' => [new NotBlank(), new Length(max: 255)],
                'label' => $this->translator->trans('Title'),
            ])
            ->add('url', UrlType::class, [
                'constraints' => [new NotBlank(), new Length(max: 2048)],
                'label' => $this->translator->trans('Address that receives the requests'),
                'default_protocol' => null,
            ])
            ->add('events', ChoiceType::class, [
                'choices' => array_combine($this->catalog->names(), $this->catalog->names()),
                'multiple' => true,
                'expanded' => true,
                'constraints' => [new Count(min: 1, minMessage: $this->translator->trans('Pick at least one event.'))],
                'label' => $this->translator->trans('Events'),
            ])
            ->add('payload_mode', ChoiceType::class, [
                'choices' => [
                    $this->translator->trans('Full object, as the admin API shows it') => WebhookStatus::PAYLOAD_FULL,
                    $this->translator->trans('Identifiers only') => WebhookStatus::PAYLOAD_IDENTIFIERS,
                ],
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Content of the request'),
            ])
            ->add('status', ChoiceType::class, [
                'choices' => $statuses,
                'invalid_message' => $this->translator->trans('This state cannot be chosen: only an exhausted delivery puts a subscription in error.'),
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Status'),
            ]);

        if ($options['include_id']) {
            $builder->add('id', HiddenType::class, [
                'constraints' => [new NotBlank(), new GreaterThan(0)],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['include_id' => false, 'include_error' => false, 'csrf_token_id' => 'admin.webhook'])
            ->setAllowedTypes('include_id', 'bool')
            ->setAllowedTypes('include_error', 'bool');
    }
}
