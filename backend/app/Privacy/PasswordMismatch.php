<?php

namespace App\Privacy;

use GraphQL\Error\ClientAware;
use RuntimeException;

final class PasswordMismatch extends RuntimeException implements ClientAware
{
    public function isClientSafe(): bool
    {
        return true;
    }
}
