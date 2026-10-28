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
use Thelia\Api\Resource\AiConnection as AiConnectionResource;
use Thelia\Api\State\Processor\AiConnectionWriteProcessor;
use Thelia\Domain\Ai\Service\AiConnectionTester;
use Thelia\Domain\Ai\Service\AiSettings;
use Thelia\Model\AiConnection;
use Thelia\Model\AiConnectionQuery;

/**
 * Writes the AI connections of the shop through the rules of the core: the processors of the admin API check the
 * code, the address and the jurisdiction and seal the key in the vault, so the screen cannot differ from the API.
 * A refusal comes back as an HTTP exception whose message the controller shows.
 */
final readonly class AiConnectionAdmin
{
    public function __construct(
        private AiConnectionWriteProcessor $writer,
        private AiConnectionTester $tester,
        private AiSettings $settings,
    ) {
    }

    /**
     * @return list<AiConnection>
     */
    public function all(): array
    {
        return array_values(AiConnectionQuery::create()->orderById()->find()->getArrayCopy());
    }

    /**
     * @param array<string, mixed> $values the fields of AiConnectionType
     */
    public function save(?int $id, array $values): void
    {
        $resource = (new AiConnectionResource())
            ->setCode((string) ($values['code'] ?? ''))
            ->setTitle((string) ($values['title'] ?? ''))
            ->setProvider((string) ($values['provider'] ?? ''))
            ->setJurisdiction(null === ($values['jurisdiction'] ?? null) ? null : (string) $values['jurisdiction'])
            ->setBaseUrl(null === ($values['base_url'] ?? null) ? null : (string) $values['base_url'])
            ->setApiKey(null === ($values['api_key'] ?? null) ? null : (string) $values['api_key'])
            ->setApiKeyEnv(null === ($values['api_key_env'] ?? null) ? null : (string) $values['api_key_env'])
            ->setAllowPrivateNetwork((bool) ($values['allow_private_network'] ?? false))
            ->setActive((bool) ($values['active'] ?? true));

        null === $id
            ? $this->writer->process($resource, new Post())
            : $this->writer->process($resource, new Patch(), ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->writer->process(null, new Delete(), ['id' => $id]);
    }

    /**
     * @return string|null null when the provider answered, the sentence it gave otherwise
     */
    public function test(AiConnection $connection): ?string
    {
        return $this->tester->test($connection);
    }

    public function isEuropeanUnionOnly(): bool
    {
        return $this->settings->isEuropeanUnionOnly();
    }

    public function setEuropeanUnionOnly(bool $europeanUnionOnly): void
    {
        $this->settings->setEuropeanUnionOnly($europeanUnionOnly);
    }
}
