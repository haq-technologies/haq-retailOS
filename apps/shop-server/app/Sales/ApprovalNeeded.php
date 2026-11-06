<?php

declare(strict_types=1);

namespace App\Sales;

use App\Identity\ApprovalAction;

/**
 * The action needs a manager's PIN first. The counter asks for the approval of $action on $subjectId,
 * then sends the request again with its token.
 */
final class ApprovalNeeded extends SaleRefused
{
    public function __construct(string $message, public readonly ApprovalAction $action, public readonly string $subjectId)
    {
        parent::__construct($message);
    }
}
