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

namespace BackOfficeDefaultTwigBundle\Controller\Configuration;

use BackOfficeDefaultTwigBundle\Form\Configuration\ConfigStoreType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFailureMessage;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormErrorRenderer;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormValidator;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\I18n\CountryStateProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\File\Service\FileProcessorService;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Checkout\Enum\GuestCheckoutMode;
use Thelia\Domain\Media\Video\VideoProviderResolver;
use Thelia\Domain\Taxation\Enum\VatExemptionMode;
use Thelia\Model\ConfigQuery;
use Twig\Environment;

#[Route('/admin/configuration/store', name: 'admin.configuration.store.')]
final class ConfigStoreController
{
    private const RESOURCE = AdminResources::STORE;
    private const VIEW_TEMPLATE = '@BackOfficeDefaultTwig/configuration/store/index.html.twig';
    private const FORM_NAME = 'thelia_configuration_store';

    private const MEDIA_FIELDS = [
        'favicon_file' => 'favicon_file',
        'logo_file' => 'logo_file',
        'banner_file' => 'banner_file',
    ];

    /** The favicon keeps the narrower choice its field offers, mapped to the extensions each type may carry. */
    private const FAVICON_TYPES = [
        'image/png' => ['png'],
        'image/x-icon' => ['ico'],
        'image/vnd.microsoft.icon' => ['ico'],
    ];

    public function __construct(
        private readonly AdminAccessChecker $access,
        private readonly AdminFormValidator $validator,
        private readonly AdminFormErrorRenderer $errorRenderer,
        private readonly AdminLogger $adminLogger,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly CountryStateProvider $countryStates,
        private readonly FileProcessorService $fileProcessor,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function default(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $form = $this->createForm($request->getLocale());

        return new Response($this->twig->render(self::VIEW_TEMPLATE, [
            'form' => $form->createView(),
            'media_urls' => $this->storeMediaUrls(),
        ]));
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::UPDATE)) {
            return $denied;
        }

        $form = $this->createForm($request->getLocale());

        try {
            $validated = $this->validator->validate($form);

            $this->writeStoreMedia($validated);
            $this->writeStoreConfig($validated);

            $this->adminLogger->log(self::RESOURCE, AccessManager::UPDATE, 'Store configuration changed', null);

            return new RedirectResponse($this->urls->generate(
                $request->request->get('save_mode') === 'stay'
                    ? 'admin.configuration.store.default'
                    : 'admin.configuration.index',
            ));
        } catch (\Throwable $exception) {
            $this->errorRenderer->setup(
                $this->translator->trans('Store configuration failed.'),
                AdminFailureMessage::of($exception, $this->translator),
                $form,
                $exception,
            );

            return new Response(
                $this->twig->render(self::VIEW_TEMPLATE, ['form' => $form->createView()]),
                Response::HTTP_BAD_REQUEST,
            );
        }
    }

    private function createForm(string $locale): FormInterface
    {
        return $this->formFactory->createNamed(self::FORM_NAME, ConfigStoreType::class, $this->readStoreData(), [
            'country_choices' => $this->countryChoices($locale),
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private function readStoreData(): array
    {
        return [
            'store_name' => (string) ConfigQuery::read('store_name', ''),
            'store_description' => (string) ConfigQuery::read('store_description', ''),
            'store_siret' => (string) ConfigQuery::read('store_siret', ''),
            'store_vat_intracom' => (string) ConfigQuery::read('store_vat_intracom', ''),
            'store_ape_code' => (string) ConfigQuery::read('store_ape_code', ''),
            'store_eori' => (string) ConfigQuery::read('store_eori', ''),
            'store_vat_exempt' => (string) ConfigQuery::read('store_vat_exempt', '0'),
            'vat_exemption_mode' => VatExemptionMode::fromShopConfiguration()->value,
            'store_registration_exempt' => (string) ConfigQuery::read('store_registration_exempt', '0'),
            'store_legal_mentions' => (string) ConfigQuery::read('store_legal_mentions', ''),
            'store_email' => ConfigQuery::read('store_email'),
            'store_notification_emails' => ConfigQuery::read('store_notification_emails'),
            'store_phone' => ConfigQuery::read('store_phone'),
            'store_fax' => ConfigQuery::read('store_fax'),
            'store_address1' => ConfigQuery::read('store_address1'),
            'store_address2' => ConfigQuery::read('store_address2'),
            'store_address3' => ConfigQuery::read('store_address3'),
            'store_zipcode' => ConfigQuery::read('store_zipcode'),
            'store_city' => ConfigQuery::read('store_city'),
            'store_country' => ConfigQuery::read('store_country'),
            'guest_checkout_mode' => ConfigQuery::read('guest_checkout_mode', GuestCheckoutMode::Disabled->value),
            'admin_two_factor_required' => (string) ConfigQuery::read('admin_two_factor_required', '0'),
            'video_providers' => (string) ConfigQuery::read(VideoProviderResolver::PROVIDERS_VARIABLE, VideoProviderResolver::DEFAULT_PROVIDERS),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function countryChoices(string $locale): array
    {
        $choices = [];

        foreach ($this->countryStates->countries($locale) as $country) {
            if ($country['title'] === '') {
                continue;
            }

            $choices[$country['title']] = $country['id'];
        }

        ksort($choices);

        return $choices;
    }

    private function writeStoreMedia(FormInterface $form): void
    {
        $uploadDir = $this->storeMediaUploadDir();
        $filesystem = new Filesystem();

        /** @var array<string, UploadedFile> $uploads */
        $uploads = [];
        foreach (self::MEDIA_FIELDS as $field => $configKey) {
            $file = $form->get($field)->getData();
            if ($file instanceof UploadedFile) {
                $uploads[$configKey] = $file;
            }
        }

        // Every file goes through the upload policy and the sanitizer, as any other image
        // upload does, before any of them is stored.
        foreach ($uploads as $configKey => $file) {
            $this->fileProcessor->validateUpload($file, 'image', $configKey === 'favicon_file' ? self::FAVICON_TYPES : null);
            $this->fileProcessor->sanitizeUpload($file);
        }

        foreach ($uploads as $configKey => $file) {
            $previous = ConfigQuery::read($configKey);
            if (\is_string($previous) && $previous !== '') {
                $previousPath = $uploadDir.\DIRECTORY_SEPARATOR.$previous;
                if ($filesystem->exists($previousPath)) {
                    $filesystem->remove($previousPath);
                }
            }

            $filename = uniqid().'-'.$file->getClientOriginalName();
            $file->move($uploadDir, $filename);
            ConfigQuery::write($configKey, $filename, false);
        }
    }

    private function writeStoreConfig(FormInterface $form): void
    {
        foreach ($form->getData() ?? [] as $name => $value) {
            if (\array_key_exists($name, self::MEDIA_FIELDS)) {
                continue;
            }

            ConfigQuery::write((string) $name, (string) ($value ?? ''), false);
        }
    }

    private function storeMediaUploadDir(): string
    {
        $configured = ConfigQuery::read('images_library_path');
        $base = \is_string($configured) && $configured !== ''
            ? THELIA_ROOT.$configured
            : THELIA_LOCAL_DIR.'media'.\DIRECTORY_SEPARATOR.'images';

        return $base.\DIRECTORY_SEPARATOR.'store';
    }

    /** @return array{favicon: ?string, logo: ?string, banner: ?string} */
    private function storeMediaUrls(): array
    {
        $uploadDir = $this->storeMediaUploadDir();
        $urls = [];

        foreach (['favicon' => 'favicon_file', 'logo' => 'logo_file', 'banner' => 'banner_file'] as $field => $configKey) {
            $filename = ConfigQuery::read($configKey);
            if (!\is_string($filename) || $filename === '' || !is_file($uploadDir.\DIRECTORY_SEPARATOR.$filename)) {
                $urls[$field] = null;
                continue;
            }
            $urls[$field] = $this->urls->generate('admin.store-media.show', ['field' => $field]);
        }

        return $urls;
    }
}
