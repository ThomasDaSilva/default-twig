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

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Thelia\Api\Resource\AiTierAssignment as AiTierAssignmentResource;
use Thelia\Api\State\Processor\AiTierAssignmentWriteProcessor;
use Thelia\Domain\Ai\AiProvider;
use Thelia\Domain\Ai\AiTier;
use Thelia\Domain\Ai\Catalog\AiModelCatalog;
use Thelia\Model\AiConnectionQuery;
use Thelia\Model\AiModel;
use Thelia\Model\AiModelQuery;
use Thelia\Model\AiTierAssignment;
use Thelia\Model\AiTierAssignmentQuery;

/**
 * The model each range (tier) runs on. A range is assigned to a model row, and the shipped list gives a model no
 * row: choosing one imports it first.
 */
final readonly class AiTierAdmin
{
    private const string SEPARATOR = '|';

    public function __construct(
        private AiModelCatalog $catalog,
        private AiTierAssignmentWriteProcessor $writer,
    ) {
    }

    public function assignment(AiTier $tier): ?AiTierAssignment
    {
        return AiTierAssignmentQuery::create()->findOneByTier($tier->value);
    }

    /**
     * The choices of a range: for each active connection, the models its provider offers (the shipped list, the
     * rows of the shop and the ones bound to that connection).
     *
     * @return array<string, array<string, string>> connection title => model label => "connection id|model name"
     */
    public function choices(): array
    {
        $choices = [];

        foreach (AiConnectionQuery::create()->filterByActive(true)->orderById()->find() as $connection) {
            $provider = AiProvider::tryFrom((string) $connection->getProvider());

            if (null === $provider) {
                continue;
            }

            $group = \sprintf('%s (%s)', $connection->getTitle(), $connection->getProvider());

            foreach ($this->catalog->models($provider->value, $connection->getId()) as $name => $specification) {
                $choices[$group][$specification->title ?? $name] = $connection->getId().self::SEPARATOR.$name;
            }
        }

        return $choices;
    }

    public function choiceOf(AiTier $tier): ?string
    {
        $assignment = $this->assignment($tier);
        $model = null === $assignment?->getModelId() ? null : AiModelQuery::create()->findPk($assignment->getModelId());

        return null === $assignment || null === $model ? null : $assignment->getConnectionId().self::SEPARATOR.$model->getName();
    }

    /**
     * The model row of a choice of choices(); the shipped list gives a model no row, so it is imported first.
     *
     * @param string $choice "connection id|model name"
     *
     * @throws \InvalidArgumentException when the choice is not one of choices()
     */
    public function resolve(string $choice): AiModel
    {
        [$connectionId, $name] = explode(self::SEPARATOR, $choice, 2) + [1 => ''];
        $connection = AiConnectionQuery::create()->findPk((int) $connectionId);
        $provider = null === $connection ? null : AiProvider::tryFrom((string) $connection->getProvider());

        if (null === $connection || null === $provider || '' === $name || null === ($this->catalog->find($provider->value, $name, $connection->getId()))) {
            throw new \InvalidArgumentException('Unknown model for this connection.');
        }

        return AiModelQuery::create()->filterByProvider($provider->value)->filterByName($name)->filterByConnectionId($connection->getId())->findOne()
            ?? $this->catalog->import($provider->value, $name)
            ?? throw new \InvalidArgumentException('Unknown model for this connection.');
    }

    /**
     * @param string|null $choice "connection id|model name", null or empty to leave the range without a model
     *
     * @throws \InvalidArgumentException when the choice is not one of choices()
     */
    public function assign(AiTier $tier, ?string $choice): void
    {
        $assignment = $this->assignment($tier);

        if (null === $choice || '' === $choice) {
            if (null !== $assignment) {
                $this->writer->process(null, new Delete(), ['id' => $assignment->getId()]);
            }

            return;
        }

        $row = $this->resolve($choice);
        $connection = (int) explode(self::SEPARATOR, $choice, 2)[0];

        $resource = (new AiTierAssignmentResource())
            ->setTier($tier->value)
            ->setConnectionId($connection)
            ->setModelId($row->getId());

        null === $assignment
            ? $this->writer->process($resource, new Post())
            : $this->writer->process($resource, new Patch(), ['id' => $assignment->getId()]);
    }
}
