<?php

namespace App\Services;

use RuntimeException;

/**
 * Story 1.6 AC4: thrown when a proposed role/permission change would remove
 * the last viable super-administrator path and no approved break-glass code
 * was supplied. The attempt is always recorded in authorization_audit_log
 * before this exception propagates.
 */
class LastSuperAdminException extends RuntimeException
{
    public function __construct(string $message = 'This change would remove the last viable super-administrator path. An approved break-glass procedure is required.')
    {
        parent::__construct($message);
    }
}
