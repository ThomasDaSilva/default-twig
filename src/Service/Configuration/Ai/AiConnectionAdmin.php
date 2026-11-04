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

use Thelia\Domain\Ai\Service\AiConnectionTester;
use Thelia\Domain\Ai\Service\AiSettings;
use Thelia\Domain\Ai\Service\AiSettingsWriter;
use Thelia\Model\AiConnection;
use Thelia\Model\AiConnectionQuery;

/**
 * Writes the AI connections of the shop through the writer of the core, which checks the code, the address and the
 * jurisdiction and seals the key in the vault: the screen cannot differ from the API. A refusal comes back as an
 * AI exception whose message the controller shows; a deletion goes through the writer too, which refuses a
 * connection a range still uses.
 */
final readonly class AiConnectionAdmin
{
    public function __construct(
        private AiSettingsWriter $writer,
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

    public function find(int $id): ?AiConnection
    {
        return AiConnectionQuery::create()->findPk($id);
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

    /**
     * The connection of a provider with its key, as the first step of the setup asks for it: the code is the
     * one of the provider, and a connection that already has it for that provider is given the new key and put
     * back in use instead of a second one being made.
     *
     * @param string $provider a provider whose address and place of hosting Thelia knows (AiProvider value)
     *
     * @throws \Thelia\Domain\Ai\Exception\AiExceptionInterface when the core refuses a value
     */
    public function connect(string $provider, string $title, #[\SensitiveParameter] string $apiKey): AiConnection
    {
        $same = AiConnectionQuery::create()->filterByCode($provider)->findOne();

        if (null !== $same && $same->getProvider() === $provider) {
            $this->save($same->getId(), [
                'code' => $same->getCode(),
                'title' => $same->getTitle(),
                'provider' => $provider,
                'jurisdiction' => $same->getJurisdiction(),
                'base_url' => $same->getBaseUrl(),
                'api_key' => $apiKey,
                'api_key_env' => $same->getApiKeyEnv(),
                'allow_private_network' => (bool) $same->getAllowPrivateNetwork(),
                'active' => true,
            ]);

            return AiConnectionQuery::create()->findPk($same->getId()) ?? $same;
        }

        $code = $provider;

        for ($suffix = 2; null !== AiConnectionQuery::create()->findOneByCode($code); ++$suffix) {
            $code = $provider.'-'.$suffix;
        }

        $this->save(null, ['code' => $code, 'title' => $title, 'provider' => $provider, 'api_key' => $apiKey, 'active' => true]);

        return AiConnectionQuery::create()->findOneByCode($code) ?? throw new \LogicException('The connection that was just saved is missing.');
    }

    public function delete(int $id): void
    {
        $connection = AiConnectionQuery::create()->findPk($id);

        if (null !== $connection) {
            $this->writer->deleteConnection($connection);
        }
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
