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
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Checkout\Enum\CheckoutDisplayMode;

/**
 * How the theme lays the checkout out. The choices are the cases of
 * {@see CheckoutDisplayMode} and nothing else, so a layout no theme knows how to
 * render is refused by the form rather than written to the configuration table.
 */
final class CheckoutDisplayModeType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('checkout_display_mode', EnumType::class, [
            'class' => CheckoutDisplayMode::class,
            'constraints' => [new NotNull()],
            'label' => $this->translator->trans('Checkout layout'),
            'help' => $this->translator->trans('How the theme shows the tunnel. It says nothing about which steps are asked for.'),
            'choice_label' => $this->modeLabel(...),
            'placeholder' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'admin.checkout-display-mode',
        ]);
    }

    public function modeLabel(CheckoutDisplayMode $mode): string
    {
        return match ($mode) {
            CheckoutDisplayMode::Steps => $this->translator->trans('In steps — one screen per step'),
            CheckoutDisplayMode::OnePage => $this->translator->trans('One page — every step one under the other'),
        };
    }
}
