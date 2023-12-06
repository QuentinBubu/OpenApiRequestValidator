<?php
namespace OpenApiRequestValidator;

enum ValidRequestErrorEnum : int
{
    case BAD_METHOD = 405;
    case BAD_URL = 404;
    case BAD_REQUEST = 400;

    public function getCode(): int
    {
        return $this->value;
    }
}
