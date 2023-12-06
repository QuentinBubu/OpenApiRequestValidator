<?php
namespace OpenApiRequestValidator;

use OpenApiRequestValidator\ValidRequestErrorEnum;

class ValidRequestError
{

    private ValidRequestErrorEnum $error;
    private string $message;

    public function __construct(
        ValidRequestErrorEnum $error,
        string $message
    ) {
        $this->error = $error;
        $this->message = $message;
    }

    public function getError(): ValidRequestErrorEnum
    {
        return $this->error;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
