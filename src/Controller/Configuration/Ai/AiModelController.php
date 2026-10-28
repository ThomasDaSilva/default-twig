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

namespace BackOfficeDefaultTwigBundle\Controller\Configuration\Ai;

use BackOfficeDefaultTwigBundle\Form\Configuration\Ai\AiModelType;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiLabels;
use BackOfficeDefaultTwigBundle\Service\Configuration\Ai\AiModelAdmin;
use BackOfficeDefaultTwigBundle\UiComponents\DataTable\RowAction;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Domain\Ai\AiProvider;
use Thelia\Domain\Ai\Catalog\AiModelSpecification;
use Thelia\Model\AiModel;
use Thelia\Tools\TokenProvider;
use Twig\Environment;

/**
 * Configuration > AI > Models: the price list shipped with Thelia, completed and corrected by the shop. Editing a
 * model of the shipped list saves a row of the shop that overrides it; deleting that row gives the shipped
 * values back.
 */
#[Route('/admin/configuration/ai/models', name: 'admin.configuration.ai.models.')]
final class AiModelController
{
    private const RESOURCE = AdminResources::AI;
    private const LIST_ROUTE = 'admin.configuration.ai.models.default';
    private const EDIT_ROUTE = 'admin.configuration.ai.models.edit';
    private const FORM_NAME = 'thelia_ai_model';

    public function __construct(
        private readonly AiModelAdmin $models,
        private readonly AiLabels $labels,
        private readonly AdminAccessChecker $access,
        private readonly AdminLogger $adminLogger,
        private readonly AdminFlash $flash,
        private readonly Environment $twig,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly TokenProvider $tokens,
    ) {
    }

    #[Route('', name: 'default', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $provider = $this->providerOf((string) $request->query->get('provider', ''));
        $rows = [];

        foreach ($this->models->models($provider) as $entry) {
            $rows[] = $this->toRow($entry['specification'], $entry['row']);
        }

        $providers = [];
        foreach (AiProvider::cases() as $case) {
            $providers[] = ['value' => $case->value, 'label' => $this->labels->provider($case), 'url' => $this->urls->generate(self::LIST_ROUTE, ['provider' => $case->value])];
        }

        return new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/ai/model/list.html.twig', [
            'rows' => $rows,
            'providers' => $providers,
            'provider' => $provider->value,
            'new_url' => $this->urls->generate('admin.configuration.ai.models.new', ['provider' => $provider->value]),
        ]));
    }

    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::CREATE)) {
            return $denied;
        }

        $provider = $this->providerOf((string) $request->query->get('provider', ''));
        $data = ['provider' => $provider->value, 'currency' => 'USD', 'input_price' => '0', 'output_price' => '0', 'supports_streaming' => true, 'active' => true];

        return $this->render($provider, null, $this->form($data, false));
    }

    #[Route('/edit', name: 'edit', methods: ['GET'])]
    public function edit(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::VIEW)) {
            return $denied;
        }

        $provider = $this->providerOf((string) $request->query->get('provider', ''));
        $connectionId = $request->query->getInt('connection') ?: null;
        $data = $this->models->formData($provider, (string) $request->query->get('name', ''), $connectionId);

        if (null === $data) {
            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE, ['provider' => $provider->value]));
        }

        return $this->render($provider, $this->models->row($provider, (string) $data['name'], $connectionId), $this->form($data, true));
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $form = $this->form([], false);
        $form->handleRequest($request);
        $submitted = (array) $request->request->all(self::FORM_NAME);
        $provider = $this->providerOf((string) ($submitted['provider'] ?? ''));
        $existing = $this->models->row($provider, (string) ($submitted['name'] ?? ''), (int) ($submitted['connection_id'] ?? 0) ?: null);

        if ($denied = $this->access->check(self::RESOURCE, [], null === $existing ? AccessManager::CREATE : AccessManager::UPDATE)) {
            return $denied;
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $values */
            $values = $form->getData();

            try {
                $this->models->save($values);
            } catch (HttpExceptionInterface $refusal) {
                $form->addError(new FormError($refusal->getMessage()));

                return $this->render($provider, $existing, $form);
            }

            $this->adminLogger->log(self::RESOURCE, null === $existing ? AccessManager::CREATE : AccessManager::UPDATE, \sprintf('AI model %s saved', (string) $values['name']));
            $this->flash->add($request, 'success', $this->translator->trans('The model was saved.'));

            return new RedirectResponse($this->urls->generate(self::LIST_ROUTE, ['provider' => $provider->value]));
        }

        return $this->render($provider, $existing, $form);
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        if ($denied = $this->access->check(self::RESOURCE, [], AccessManager::DELETE)) {
            return $denied;
        }

        $id = (int) $request->request->get('model_id', 0);
        $row = \Thelia\Model\AiModelQuery::create()->findPk($id);
        $back = new RedirectResponse($this->urls->generate(self::LIST_ROUTE, ['provider' => (string) $row?->getProvider()]));

        if (null === $row) {
            return $back;
        }

        try {
            $this->tokens->checkToken((string) $request->request->get('_token', ''));
        } catch (\Throwable) {
            $this->flash->add($request, 'danger', $this->translator->trans('Invalid security token, please try again.'));

            return $back;
        }

        try {
            $this->models->delete($id);
        } catch (HttpExceptionInterface $refusal) {
            $this->flash->add($request, 'danger', Response::HTTP_CONFLICT === $refusal->getStatusCode()
                ? $this->translator->trans('This model is still used by a range. Give that range another model first (Configuration > AI > Ranges), then delete the model.')
                : $refusal->getMessage());

            return $back;
        }

        $this->adminLogger->log(self::RESOURCE, AccessManager::DELETE, \sprintf('AI model %s deleted', (string) $row->getName()), $id);
        $this->flash->add($request, 'success', $this->translator->trans('The model was deleted.'));

        return $back;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function form(array $data, bool $nameLocked): FormInterface
    {
        return $this->formFactory->createNamed(self::FORM_NAME, AiModelType::class, $data, ['name_locked' => $nameLocked]);
    }

    private function render(AiProvider $provider, ?AiModel $row, FormInterface $form): Response
    {
        return new Response($this->twig->render('@BackOfficeDefaultTwig/configuration/ai/model/edit.html.twig', [
            'form' => $form->createView(),
            'provider' => $provider->value,
            'provider_label' => $this->labels->provider($provider),
            'row' => $row,
            
            'list_url' => $this->urls->generate(self::LIST_ROUTE, ['provider' => $provider->value]),
        ]));
    }

    private function providerOf(string $value): AiProvider
    {
        return AiProvider::tryFrom($value) ?? AiProvider::Mistral;
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(AiModelSpecification $model, ?AiModel $row): array
    {
        $capabilities = array_filter([
            $model->supportsStructuredOutput ? $this->translator->trans('Strict format') : null,
            $model->supportsTools ? $this->translator->trans('Tools') : null,
            $model->supportsVision ? $this->translator->trans('Images') : null,
        ]);
        $edit = $this->urls->generate(self::EDIT_ROUTE, array_filter(['provider' => $model->provider, 'name' => $model->name, 'connection' => $model->connectionId]));

        $actions = [new RowAction(kind: 'edit', label: $this->translator->trans('Edit'), href: $edit, grantedAttribute: AccessManager::VIEW, grantedSubject: self::RESOURCE)];

        if (null !== $row) {
            $actions[] = new RowAction(kind: 'delete', label: $this->translator->trans('Reset or delete'), modalTarget: '#ai-model-delete-modal', grantedAttribute: AccessManager::DELETE, grantedSubject: self::RESOURCE, dataAttributes: ['model-id' => (int) $row->getId(), 'model-label' => $model->name]);
        }

        return [
            'name' => $model->name,
            'title' => $model->title ?? '',
            'input_price' => $model->inputPrice.' '.$model->currency,
            'output_price' => $model->outputPrice.' '.$model->currency,
            'cache_read_price' => $model->cacheReadPrice ?? '-',
            'context_window' => null === $model->contextWindow ? '-' : number_format($model->contextWindow, 0, '.', ' '),
            'capabilities' => implode(', ', $capabilities),
            'source' => null === $row ? 'catalog' : 'shop',
            'source_label' => $this->translator->trans(null === $row ? 'Shipped' : 'Edited'),
            '_actions' => $actions,
        ];
    }
}
