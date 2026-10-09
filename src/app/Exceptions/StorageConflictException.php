<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A package directory was already taken by another push of the same version.
 * Distinct from a plain RuntimeException (disk full, permissions) because the
 * right answer is different: not a server error, but "someone else got there
 * first" — a 409, the same as publishing a version that already exists.
 */
final class StorageConflictException extends RuntimeException
{
}
