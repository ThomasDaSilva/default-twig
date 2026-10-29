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

use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Ai\Exception\AiExceptionInterface;
use Thelia\Domain\Ai\Service\AiFailureExplainer;

/**
 * Tells an administrator why the AI layer refused something, in the language of the back office: the sentence of the
 * core when it has one, else the catalogue of the theme (fixed English messages, and the ones that carry a value),
 * else the message itself.
 */
final readonly class AiRefusalMessage
{
    /** Refusals with a variable part: pattern of the English message, then the sentence (also the translation identifier). */
    private const array VARIABLE_SENTENCES = [
        '/^The label of a token holds 1 to (?<max>\d+) characters\.$/' => 'The label of a token holds 1 to %max% characters.',
        '/^A token has 1 to (?<max>\d+) scopes, unless it takes all the rights of its administrator\.$/' => 'A token has 1 to %max% scopes, unless it takes all the rights of its administrator.',
        '/^The administrator does not hold the right "(?<right>.+)"\.$/' => 'The administrator does not hold the right "%right%".',
    ];

    public function __construct(
        private AiFailureExplainer $failures,
        private TranslatorInterface $translator,
    ) {
    }

    public function explain(\Throwable $refusal, ?string $locale = null): string
    {
        $message = $refusal->getMessage();

        if (!$refusal instanceof AiExceptionInterface) {
            return $message;
        }

        $explained = $this->failures->explain($refusal, $locale);

        if ($explained !== $message) {
            return $explained;
        }

        foreach (self::VARIABLE_SENTENCES as $pattern => $sentence) {
            if (1 === preg_match($pattern, $message, $matches)) {
                $parameters = [];
                foreach ($matches as $key => $value) {
                    if (\is_string($key)) {
                        $parameters['%'.$key.'%'] = $value;
                    }
                }

                return $this->translator->trans($sentence, $parameters);
            }
        }

        return $this->translator->trans($message);
    }
}
