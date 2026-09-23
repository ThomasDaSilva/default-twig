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
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Checkout\Enum\GuestCheckoutMode;
use Thelia\Domain\Legal\CompanyIdentifier;
use Thelia\Domain\Media\Video\VideoProvider;
use Thelia\Domain\Taxation\Enum\VatExemptionMode;

final class ConfigStoreType extends AbstractType
{
    /**
     * Image types accepted as the store logo or banner. An SVG is stripped of its
     * active content before it is stored (see ConfigStoreController).
     */
    public const STORE_IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];

    /**
     * Characters stripped from each company identifier before it is validated and stored,
     * so that a number copied from an official document is accepted as typed.
     *
     * @var array<string, string>
     */
    private const IDENTIFIER_SEPARATORS = [
        'store_siret' => '/[\s.]/',
        'store_vat_intracom' => '/\s/',
        'store_ape_code' => '/[\s.-]/',
        'store_eori' => '/\s/',
    ];

    private const UPPERCASED_IDENTIFIERS = [
        'store_vat_intracom',
        'store_ape_code',
        'store_eori',
    ];

    private const BOOLEAN_FIELDS = [
        'store_vat_exempt',
        'store_registration_exempt',
        'admin_two_factor_required',
    ];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('store_name', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Store name'),
                'attr' => ['placeholder' => $this->translator->trans('Used in your store front')],
            ])
            ->add('store_description', TextareaType::class, [
                'required' => false,
                'label' => $this->translator->trans('Store description'),
            ])
            ->add('store_siret', TextType::class, [
                'required' => false,
                'constraints' => [new Callback($this->checkSiret(...))],
                'label' => $this->translator->trans('SIRET number'),
                'help' => $this->translator->trans('14 digits, including the 9 digits of the SIREN number.'),
            ])
            ->add('store_vat_intracom', TextType::class, [
                'required' => false,
                'constraints' => [new Regex(
                    pattern: '/^[A-Z]{2}[0-9A-Z]{2,13}$/',
                    message: $this->translator->trans('The intra-community VAT number must start with a two letter country code followed by 2 to 13 alphanumeric characters.'),
                )],
                'label' => $this->translator->trans('Intra-community VAT number'),
                'help' => $this->translator->trans('Country code followed by the national number, without spaces.'),
            ])
            ->add('store_ape_code', TextType::class, [
                'required' => false,
                'constraints' => [new Regex(
                    pattern: '/^[0-9]{4}[A-Z]$/',
                    message: $this->translator->trans('The APE code must contain 4 digits followed by one letter.'),
                )],
                'label' => $this->translator->trans('APE / NAF code'),
                'help' => $this->translator->trans('Business activity code, 4 digits and one letter.'),
            ])
            ->add('store_eori', TextType::class, [
                'required' => false,
                'constraints' => [new Regex(
                    pattern: '/^[A-Z]{2}[0-9A-Z]{1,15}$/',
                    message: $this->translator->trans('The EORI number must start with a two letter country code followed by up to 15 alphanumeric characters.'),
                )],
                'label' => $this->translator->trans('EORI number'),
                'help' => $this->translator->trans('Required only for customs operations outside the European Union.'),
            ])
            ->add('store_vat_exempt', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('VAT exemption (article 293 B of the French tax code)'),
                'help' => $this->translator->trans('Prints the VAT exemption notice on invoices and disables the intra-community VAT number.'),
            ])
            ->add('vat_exemption_mode', ChoiceType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Intra-community VAT exemption'),
                'help' => $this->translator->trans('Requires a verification module: without one no address is ever verified, so no order is ever exempted.'),
                'choices' => [
                    $this->translator->trans('Disabled — every order is taxed') => VatExemptionMode::DISABLED->value,
                    $this->translator->trans('Exempt an order billed to a verified VAT number in another member state') => VatExemptionMode::VERIFIED_VAT_NUMBER->value,
                ],
                'placeholder' => false,
            ])
            ->add('store_registration_exempt', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Exempt from trade register registration (RCS and RM)'),
                'help' => $this->translator->trans('Prints the registration exemption notice on invoices.'),
            ])
            ->add('admin_two_factor_required', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Require two-step verification for every administrator'),
                'help' => $this->translator->trans('An administrator without it is sent to its activation right after signing in, and the admin API gives such an account no token.'),
            ])
            ->add('store_legal_mentions', TextareaType::class, [
                'required' => false,
                'constraints' => [new Length(max: 500)],
                'label' => $this->translator->trans('Additional legal notice'),
                'help' => $this->translator->trans('Printed as is at the bottom of invoices and delivery notes.'),
            ])
            ->add('store_email', TextType::class, [
                'constraints' => [new NotBlank(), new Email()],
                'label' => $this->translator->trans('Store email address'),
                'help' => $this->translator->trans('This is the contact email address, and the sender email of all e-mails sent by your store.'),
            ])
            ->add('store_notification_emails', TextType::class, [
                'constraints' => [new NotBlank(), new Callback($this->checkEmailList(...))],
                'label' => $this->translator->trans('Email addresses of notification recipients'),
                'help' => $this->translator->trans('A comma separated list of email addresses.'),
            ])
            ->add('store_phone', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Phone'),
            ])
            ->add('store_fax', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Fax'),
            ])
            ->add('store_address1', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Street Address'),
            ])
            ->add('store_address2', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Additional address line'),
            ])
            ->add('store_address3', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Additional address line'),
            ])
            ->add('store_zipcode', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Zip code'),
            ])
            ->add('store_city', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('City'),
            ])
            ->add('store_country', ChoiceType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Country'),
                'choices' => $options['country_choices'],
                'placeholder' => false,
            ])
            ->add('guest_checkout_mode', ChoiceType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Guest checkout'),
                'help' => $this->translator->trans('How far a visitor can go without creating an account.'),
                'choices' => [
                    $this->translator->trans('Disabled — an account is always required to order') => GuestCheckoutMode::Disabled->value,
                    $this->translator->trans('Enabled — any cart may be ordered without an account') => GuestCheckoutMode::Enabled->value,
                    $this->translator->trans('Enabled, except for products that require an account') => GuestCheckoutMode::EnabledUnlessProductForbids->value,
                ],
                'placeholder' => false,
            ])
            ->add('video_providers', ChoiceType::class, [
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'label' => $this->translator->trans('Video platforms'),
                'help' => $this->translator->trans('The platforms a product video address may come from. An address from a platform left unticked is refused.'),
                'choices' => self::videoProviderChoices(),
            ])
            ->add('favicon_file', FileType::class, [
                'required' => false,
                'constraints' => [new Image(mimeTypes: ['image/png', 'image/x-icon'])],
                'label' => $this->translator->trans('Favicon image'),
                'help' => $this->translator->trans('Icon of the website. Only PNG and ICO files are allowed.'),
            ])
            ->add('logo_file', FileType::class, [
                'required' => false,
                'constraints' => [new Image(mimeTypes: self::STORE_IMAGE_MIME_TYPES)],
                'label' => $this->translator->trans('Store logo'),
            ])
            ->add('banner_file', FileType::class, [
                'required' => false,
                'constraints' => [new Image(mimeTypes: self::STORE_IMAGE_MIME_TYPES)],
                'label' => $this->translator->trans('Banner'),
                'help' => $this->translator->trans('Banner of the website. Used in e-mails sent to customers.'),
            ]);

        // The model data of every company identifier is the string stored in the config table:
        // normalizing here, and not in the controller, lets the constraints above run on the
        // normalized value, so that "fr 40 303 265 045" is accepted and stored as "FR40303265045".
        foreach (self::IDENTIFIER_SEPARATORS as $field => $separators) {
            $uppercase = \in_array($field, self::UPPERCASED_IDENTIFIERS, true);

            $builder->get($field)->addModelTransformer(new CallbackTransformer(
                static fn (mixed $value): string => \is_string($value) ? $value : '',
                static fn (mixed $value): string => self::normalizeIdentifier($value, $separators, $uppercase),
            ));
        }

        // The platform list is stored as the CSV the resolver reads; the transformer
        // keeps the form working on the list of codes the checkboxes produce.
        $builder->get('video_providers')->addModelTransformer(new CallbackTransformer(
            static fn (mixed $value): array => self::splitProviders($value),
            static fn (mixed $value): string => \is_array($value) ? implode(',', $value) : '',
        ));

        // Without this, an unchecked box would reach ConfigQuery::write() as an empty string.
        foreach (self::BOOLEAN_FIELDS as $field) {
            $builder->get($field)->addModelTransformer(new CallbackTransformer(
                static fn (mixed $value): bool => '1' === $value,
                static fn (mixed $value): string => true === $value ? '1' : '0',
            ));
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('country_choices')
            ->setAllowedTypes('country_choices', 'array')
            ->setDefaults([
                'csrf_token_id' => 'admin.config-store',
                'constraints' => [new Callback($this->checkVatExemption(...))],
            ]);
    }

    public function checkEmailList(mixed $value, ExecutionContextInterface $context): void
    {
        if (!\is_string($value) || $value === '') {
            return;
        }

        $emailValidator = new Email();
        foreach (preg_split('/[,;]/', $value) ?: [] as $email) {
            $context->getValidator()->validate(trim($email), $emailValidator);
        }
    }

    public function checkSiret(mixed $value, ExecutionContextInterface $context): void
    {
        if (!\is_string($value) || $value === '' || CompanyIdentifier::isValidFrenchSiret($value)) {
            return;
        }

        $context
            ->buildViolation($this->translator->trans('The SIRET number must contain 14 digits and its checksum must be valid.'))
            ->addViolation();
    }

    public function checkVatExemption(mixed $value, ExecutionContextInterface $context): void
    {
        if (!\is_array($value)) {
            return;
        }

        if (($value['store_vat_exempt'] ?? '0') !== '1') {
            return;
        }

        if (($value['store_vat_intracom'] ?? '') !== '') {
            $context
                ->buildViolation($this->translator->trans('A store under VAT exemption cannot declare an intra-community VAT number.'))
                ->atPath('children[store_vat_intracom]')
                ->addViolation();
        }

        if (($value['vat_exemption_mode'] ?? VatExemptionMode::DISABLED->value) === VatExemptionMode::VERIFIED_VAT_NUMBER->value) {
            $context
                ->buildViolation($this->translator->trans('A store that charges no VAT has none to reverse onto its buyers.'))
                ->atPath('children[vat_exemption_mode]')
                ->addViolation();
        }
    }

    /**
     * @return array<string, string>
     */
    private static function videoProviderChoices(): array
    {
        $choices = [];

        foreach (VideoProvider::cases() as $provider) {
            // `file` is not a platform: a hosted video is uploaded, never pasted.
            if (VideoProvider::File === $provider) {
                continue;
            }

            $choices[$provider->label()] = $provider->value;
        }

        return $choices;
    }

    /**
     * @return list<string>
     */
    private static function splitProviders(mixed $value): array
    {
        if (!\is_string($value) || '' === trim($value)) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $value))));
    }

    private static function normalizeIdentifier(mixed $value, string $separators, bool $uppercase): string
    {
        if (!\is_string($value)) {
            return '';
        }

        $normalized = (string) preg_replace($separators, '', $value);

        return $uppercase ? strtoupper($normalized) : $normalized;
    }
}
