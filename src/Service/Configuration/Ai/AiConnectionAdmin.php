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
use Thelia\Api\State\Processor\AiConnectionWriteProcessor;
use Thelia\Domain\Ai\Service\AiConnectionTester;
use Thelia\Domain\Ai\Service\AiSettings;
use Thelia\Domain\Ai\Service\AiSettingsWriter;
use Thelia\Model\AiConnection;
use Thelia\Model\AiConnectionQuery;

/**
 * Writes the AI connections of the shop through the writer of the core, which checks the code, the address and the
 * jurisdiction and seals the key in the vault: the screen cannot differ from the API. A refusal comes back as an
 * AI exception whose message the controller shows; a deletion goes through the processor of the admin API, which
 * refuses a connection a range still uses.
 */
final readonly class AiConnectionAdmin
{
    public function __construct(
        private AiSettingsWriter $writer,
        private AiConnectionWriteProcessor $deleter,
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
        $text = static fn (string $field): ?string => null === ($values[$field] ?? null) ? null : (string) $values[$field];

        $this->writer->saveConnection(
            null === $id ? null : AiConnectionQuery::create()->findPk($id),
            (string) ($values['code'] ?? ''),
            (string) ($values['title'] ?? ''),
            (string) ($values['provider'] ?? ''),
            $text('jurisdiction'),
            $text('base_url'),
            $text('api_key'),
            $text('api_key_env'),
            (bool) ($values['allow_private_network'] ?? false),
            (bool) ($values['active'] ?? true),
        );
    }

    public function delete(int $id): void
    {
        $this->deleter->process(null, new Delete(), ['id' => $id]);
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
        $this->writer->setEuropeanUnionOnly($europeanUnionOnly);
    }
}
