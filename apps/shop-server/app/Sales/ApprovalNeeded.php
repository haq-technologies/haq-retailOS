<?php

declare(strict_types=1);

namespace App\Sales;

use App\Identity\ApprovalAction;

/**
 * The action needs a manager's PIN first. The counter asks for the approval of $action on $subjectId
 * (and, for a price, that item at that price), then sends the request again with its token.
 */
final class ApprovalNeeded extends SaleRefused
{
    public function __construct(
        string $message,
        public readonly ApprovalAction $action,
        public readonly string $subjectId,
        public readonly ?string $itemId = null,
        public readonly ?int $priceKobo = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array{action: string, subject_id: string, item_id: ?string, price_kobo: ?int}
     */
    public function toArray(): array
    {
        return ['action' => $this->action->value, 'subject_id' => $this->subjectId, 'item_id' => $this->itemId, 'price_kobo' => $this->priceKobo];
    }
}
