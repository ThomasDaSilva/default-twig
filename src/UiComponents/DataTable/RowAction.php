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

namespace BackOfficeDefaultTwigBundle\UiComponents\DataTable;

final readonly class RowAction
{
    /**
     * @param string                          $kind             One of the kinds of cells/actions.html.twig ('edit', 'delete', 'view', 'send', 'activate', 'deactivate', 'custom'…). Drives the Bootstrap-icon glyph + variant; 'activate' and 'deactivate' show the switch in the state the row is in, the action turning it over.
     * @param ?string                         $href             Destination URL. Mutually exclusive with $modalTarget - if both null, the action becomes a no-op button.
     * @param ?string                         $modalTarget      CSS selector of a Bootstrap modal to open (e.g. '#delete_dialog'). Adds data-bs-toggle/target.
     * @param ?string                         $grantedAttribute Symfony voter attribute (VIEW/UPDATE/DELETE) required to render the action. Null = no permission gate.
     * @param string|array<string,mixed>|null $grantedSubject   symfony voter subject (resource code string OR { resource, module } array as supported by AdminVoter)
     * @param array<string, scalar>           $dataAttributes   Extra `data-*` attributes (without the `data-` prefix). Useful to pass `lang-id` etc.
     * @param bool                            $inMenu           if true, the action is hidden inside the row kebab dropdown instead of being rendered inline
     * @param ?string                         $disabledReason   When set, the action is rendered inert and this sentence says why. Prefer it over
     *                                                          dropping the action: an admin who expects to be able to act needs to read the reason,
     *                                                          not to discover the refusal after confirming.
     * @param ?string                         $inlineFrom       Smallest Bootstrap breakpoint at which this action is shown inline, one of
     *                                                          Column::BREAKPOINT_ORDER excluding 'always'. Null (default) keeps the action inline
     *                                                          at every width. Below its breakpoint, the action moves into the kebab dropdown instead.
     *
     * @throws \InvalidArgumentException if $inlineFrom is combined with $inMenu: true, is "always", or is not one of Column::BREAKPOINT_ORDER
     */
    public function __construct(
        public string $kind,
        public string $label,
        public ?string $href = null,
        public ?string $modalTarget = null,
        public ?string $grantedAttribute = null,
        public string|array|null $grantedSubject = null,
        public array $dataAttributes = [],
        public bool $inMenu = false,
        public ?string $disabledReason = null,
        public ?string $inlineFrom = null,
    ) {
        if ($this->inlineFrom !== null) {
            if ($this->inMenu) {
                throw new \InvalidArgumentException(sprintf(
                    'RowAction "%s" cannot combine inMenu: true with inlineFrom: unconditional overflow and breakpoint-based collapsing are mutually exclusive.',
                    $this->kind,
                ));
            }

            if ($this->inlineFrom === 'always') {
                throw new \InvalidArgumentException(sprintf(
                    'RowAction "%s" has inlineFrom: "always", which is meaningless (equivalent to the default always-inline behaviour); omit inlineFrom instead.',
                    $this->kind,
                ));
            }

            Column::breakpointRank($this->inlineFrom);
        }
    }

    public function isDisabled(): bool
    {
        return $this->disabledReason !== null;
    }

    /**
     * Largest inlineFrom breakpoint declared among $actions that are collapsible (inlineFrom set,
     * inMenu false), or null if none is collapsible.
     *
     * @param list<self> $actions
     */
    public static function maxCollapseBelowBreakpoint(array $actions): ?string
    {
        $maxRank = 0;
        $max = null;

        foreach ($actions as $action) {
            if ($action->inlineFrom === null) {
                continue;
            }

            $rank = Column::breakpointRank($action->inlineFrom);
            if ($rank > $maxRank) {
                $maxRank = $rank;
                $max = $action->inlineFrom;
            }
        }

        return $max;
    }
}
