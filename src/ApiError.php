<?php
declare(strict_types=1);

namespace LabAdmin;

final class ApiError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400, public readonly ?array $extra = null)
    {
        parent::__construct($message);
    }
}
