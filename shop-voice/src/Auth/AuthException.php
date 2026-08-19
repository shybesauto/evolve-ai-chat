<?php
declare(strict_types=1);

namespace ShopVoice\Auth;

final class AuthException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 401)
    {
        parent::__construct($message);
    }
}
