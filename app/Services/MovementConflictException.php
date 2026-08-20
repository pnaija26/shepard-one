<?php

namespace App\Services;

/**
 * Story 1.5: a movement request conflicts with existing state (e.g. the person
 * already has an open pending/approved movement, or the decision was already
 * made). Rendered as HTTP 409 Conflict — the active association is untouched.
 */
class MovementConflictException extends \RuntimeException
{
}
