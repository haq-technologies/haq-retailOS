<?php

declare(strict_types=1);

namespace App\Platform\Database\Mutability;

/**
 * How a table may change, from the mutability matrix in spec section 5 [F3, S15].
 * Grants decide what the app role may attempt; triggers hold the rule even if a grant drifts.
 */
enum Kind: string
{
    /** Insert and read only. Corrections are new rows. Triggers reject UPDATE, DELETE and TRUNCATE. */
    case AppendOnly = 'append_only';

    /** Insert and read; the listed columns may each be set once, from NULL, and nothing else changes. */
    case SetOnce = 'set_once';

    /** Insert and read; only the listed columns may change. No DELETE. */
    case MutableColumns = 'mutable_columns';

    /** Insert, read and update until the row is closed; a closed row never changes. No DELETE. */
    case MutableWhileOpen = 'mutable_while_open';

    /** Insert, read and update, but never delete (users are deactivated, never deleted). */
    case NeverDeleted = 'never_deleted';

    /** Insert, read, update and delete. Every change is audited by the application. */
    case Mutable = 'mutable';

    /** Laravel's own working tables: sessions, cache and the queue (D21). */
    case Framework = 'framework';

    /** Read only for the app role (the migrations table). */
    case ReadOnly = 'read_only';
}
