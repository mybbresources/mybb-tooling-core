<?php
declare(strict_types=1);
namespace MyBB\Tooling;

use RuntimeException;

final class CatalogException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable,
        public readonly ?int $httpStatus = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }

    public function displayMessage(): string
    {
        $message = '['.$this->errorCode.'] '.($this->retryable ? 'Retryable: ' : '').$this->getMessage();
        if ($this->requestId !== null) $message .= ' (request ID: '.$this->requestId.')';
        return $message;
    }
}
