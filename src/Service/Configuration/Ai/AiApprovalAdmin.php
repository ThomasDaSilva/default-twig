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
use Thelia\Domain\Ai\Tool\AiToolApprovalServiceInterface;
use Thelia\Domain\Ai\Tool\AiToolApprovalStatus;
use Thelia\Domain\Ai\Tool\AiToolApprovalView;

/**
 * What the approval screens show of the changes the write tools proposed. The summary is written by the tool from a
 * request of the model, so it never suffices to decide: the screen gives the arguments and the state after the
 * change in full. Everything here is plain text; the templates escape it.
 */
final readonly class AiApprovalAdmin
{
    public function __construct(
        private AiToolApprovalServiceInterface $approvals,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array<string, mixed>> the proposals this administrator may decide
     */
    public function pending(int $adminId): array
    {
        return array_map($this->row(...), $this->approvals->pendingFor($adminId));
    }

    /**
     * @return list<array<string, mixed>> the changes left applying that this administrator may close
     */
    public function applying(int $adminId): array
    {
        return array_map($this->row(...), $this->approvals->applyingFor($adminId));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(int $adminId, int $approvalId): ?array
    {
        $view = $this->approvals->viewFor($adminId, $approvalId);

        if (!$view instanceof AiToolApprovalView) {
            return null;
        }

        return $this->row($view) + [
            'arguments_json' => self::json($view->arguments),
            'original_arguments_json' => self::json($view->originalArguments),
            'before_state_json' => self::json($view->beforeState),
            'after_state_json' => self::json($view->afterState),
            'amended' => $view->amended,
            'channel' => $view->channel->value,
            'usage_id' => $view->usageId,
            'proposed_by' => $view->proposedByAdminLogin ?? $view->proposedByType->value,
            'token_prefix' => $view->tokenPrefix,
            'decided_by' => $view->decidedByAdminLogin,
            'decided_at' => $view->decidedAt?->format('Y-m-d H:i'),
            'decision_note' => $view->decisionNote,
            'error' => $view->error,
            'can_decide' => AiToolApprovalStatus::Pending === $view->status && $view->expiresAt > new \DateTimeImmutable(),
            'is_applying' => AiToolApprovalStatus::Applying === $view->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AiToolApprovalView $view): array
    {
        return [
            'id' => $view->id,
            'tool' => $view->toolName,
            'module' => $view->module,
            'target' => $view->target,
            'summary' => $view->summary,
            'status' => $view->status->value,
            'status_label' => $this->translator->trans(match ($view->status) {
                AiToolApprovalStatus::Pending => 'Waiting for a decision',
                AiToolApprovalStatus::Applying => 'Interrupted while applying',
                AiToolApprovalStatus::Applied => 'Applied',
                AiToolApprovalStatus::Rejected => 'Rejected',
                AiToolApprovalStatus::Failed => 'Failed',
                AiToolApprovalStatus::Expired => 'Expired',
            }),
            'created_at' => $view->createdAt->format('Y-m-d H:i'),
            'expires_at' => $view->expiresAt->format('Y-m-d H:i'),
        ];
    }

    /**
     * @param array<mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }
}
