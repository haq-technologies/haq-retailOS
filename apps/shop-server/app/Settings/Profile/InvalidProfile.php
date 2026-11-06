<?php

declare(strict_types=1);

namespace App\Settings\Profile;

use InvalidArgumentException;

final class InvalidProfile extends InvalidArgumentException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct("The shop profile is not valid:\n- ".implode("\n- ", $errors));
    }
}
