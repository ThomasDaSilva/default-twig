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

namespace BackOfficeDefaultTwigBundle\Service\Configuration\Ai;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Domain\Ai\AiProvider;
use Thelia\Domain\Ai\Catalog\AiModelCatalog;
use Thelia\Domain\Ai\Catalog\AiModelSpecification;
use Thelia\Domain\Ai\Exception\AiExceptionInterface;
use Thelia\Domain\Ai\Service\AiSettingsWriter;
use Thelia\Model\AiModel;
use Thelia\Model\AiModelQuery;

/**
 * The model catalogue as the merchant edits it: the list shipped with the core, completed by the rows of the
 * shop. Editing a model of the shipped list writes a row of the shop that overrides it; deleting that row gives
 * the shipped values back.
 */
final readonly class AiModelAdmin
{
    public function __construct(
        private AiModelCatalog $catalog,
        private AiSettingsWriter $writer,
    ) {
    }

    /**
     * The models of a provider as the gateway sees them, the ones bound to a connection included.
     *
     * @return list<array{specification: AiModelSpecification, row: AiModel|null}>
     */
    public function models(AiProvider $provider): array
    {
        $rows = [];
        foreach (AiModelQuery::create()->filterByProvider($provider->value)->find() as $row) {
            \assert($row instanceof AiModel);
            $rows[$this->key((string) $row->getName(), $row->getConnectionId())] = $row;
        }

        $entries = [];
        foreach ($this->catalog->models($provider->value) as $name => $specification) {
            $entries[] = ['specification' => $specification, 'row' => $rows[$this->key($name, null)] ?? null];
        }

        foreach ($rows as $row) {
            if (null !== $row->getConnectionId() && $row->getActive()) {
                $entries[] = ['specification' => $this->catalog->find($provider->value, (string) $row->getName(), $row->getConnectionId()) ?? $this->specificationOf($row), 'row' => $row];
            }
        }

        return $entries;
    }

    /**
     * The values the form shows for a model: its row when it has one, else the shipped list.
     *
     * @return array<string, mixed>|null null when the model is nowhere
     */
    public function formData(AiProvider $provider, string $name, ?int $connectionId): ?array
    {
        $row = $this->row($provider, $name, $connectionId);
        $specification = null !== $row ? $this->specificationOf($row) : $this->catalog->find($provider->value, $name, $connectionId);

        if (null === $specification) {
            return null;
        }

        return [
            'provider' => $provider->value,
            'name' => $name,
            'connection_id' => $connectionId,
            'title' => $specification->title,
            'input_price' => $specification->inputPrice,
            'output_price' => $specification->outputPrice,
            'cache_read_price' => $specification->cacheReadPrice,
            'cache_write_price' => $specification->cacheWritePrice,
            'currency' => $specification->currency,
            'context_window' => $specification->contextWindow,
            'max_output_tokens' => $specification->maxOutputTokens,
            'supports_structured_output' => $specification->supportsStructuredOutput,
            'supports_tools' => $specification->supportsTools,
            'supports_vision' => $specification->supportsVision,
            'supports_streaming' => $specification->supportsStreaming,
            'active' => null === $row || (bool) $row->getActive(),
        ];
    }

    public function row(AiProvider $provider, string $name, ?int $connectionId): ?AiModel
    {
        $query = AiModelQuery::create()->filterByProvider($provider->value)->filterByName($name);
        null === $connectionId ? $query->filterByConnectionId(null, Criteria::ISNULL) : $query->filterByConnectionId($connectionId);

        return $query->findOne();
    }

    /**
     * @param array<string, mixed> $values the fields of AiModelType
     *
     * @throws AiExceptionInterface when the core refuses a value
     */
    public function save(array $values): void
    {
        $provider = AiProvider::from((string) $values['provider']);
        $connectionId = null === ($values['connection_id'] ?? null) || '' === $values['connection_id'] ? null : (int) $values['connection_id'];
        $row = $this->row($provider, (string) $values['name'], $connectionId);

        $this->writer->saveModel(
            $row,
            $provider->value,
            (string) $values['name'],
            $connectionId,
            null === ($values['title'] ?? null) ? null : (string) $values['title'],
            $this->price($values['input_price'] ?? null),
            $this->price($values['output_price'] ?? null),
            $this->price($values['cache_read_price'] ?? null),
            $this->price($values['cache_write_price'] ?? null),
            (string) ($values['currency'] ?? 'USD'),
            $this->integer($values['context_window'] ?? null),
            $this->integer($values['max_output_tokens'] ?? null),
            (bool) ($values['supports_structured_output'] ?? false),
            (bool) ($values['supports_tools'] ?? false),
            (bool) ($values['supports_vision'] ?? false),
            (bool) ($values['supports_streaming'] ?? false),
            (bool) ($values['active'] ?? true),
        );
    }

    public function delete(int $id): void
    {
        $row = AiModelQuery::create()->findPk($id);

        if (null !== $row) {
            $this->writer->deleteModel($row);
        }
    }

    private function specificationOf(AiModel $row): AiModelSpecification
    {
        return new AiModelSpecification(
            provider: (string) $row->getProvider(),
            name: (string) $row->getName(),
            title: $row->getTitle(),
            inputPrice: (string) $row->getInputPrice(),
            outputPrice: (string) $row->getOutputPrice(),
            cacheReadPrice: $row->getCacheReadPrice(),
            cacheWritePrice: $row->getCacheWritePrice(),
            currency: (string) $row->getCurrency(),
            contextWindow: $row->getContextWindow(),
            maxOutputTokens: $row->getMaxOutputTokens(),
            supportsStructuredOutput: (bool) $row->getSupportsStructuredOutput(),
            supportsTools: (bool) $row->getSupportsTools(),
            supportsVision: (bool) $row->getSupportsVision(),
            supportsStreaming: (bool) $row->getSupportsStreaming(),
            source: (string) $row->getSource(),
            connectionId: $row->getConnectionId(),
        );
    }

    private function key(string $name, ?int $connectionId): string
    {
        return $name."\0".$connectionId;
    }

    private function price(mixed $value): ?string
    {
        return null === $value || '' === trim((string) $value) ? null : trim((string) $value);
    }

    private function integer(mixed $value): ?int
    {
        return null === $value || '' === $value ? null : (int) $value;
    }
}
