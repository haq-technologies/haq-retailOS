<?php

declare(strict_types=1);

namespace App\Shifts;

use App\Identity\ApprovalAction;

/**
 * The drawer action needs a manager's PIN first. The counter asks for the approval of $action on $subjectId
 * (its own request ID) for $amountKobo, then sends the request again with its token.
 */
final class NeedsApproval extends DrawerRefused
{
    public function __construct(string $message, public readonly ApprovalAction $action, public readonly string $subjectId, public readonly int $amountKobo)
    {
        parent::__construct($message);
    }

    /**
     * @return array{action: string, subject_id: string, item_id: null, price_kobo: int}
     */
    public function toArray(): array
    {
        return ['action' => $this->action->value, 'subject_id' => $this->subjectId, 'item_id' => null, 'price_kobo' => $this->amountKobo];
    }
}
