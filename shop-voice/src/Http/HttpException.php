<?php
declare(strict_types=1);

namespace ShopVoice\Http;

final class HttpException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
