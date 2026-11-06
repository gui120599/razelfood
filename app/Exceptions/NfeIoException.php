<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Falha na comunicação com a NFE.io. NUNCA carrega request, headers ou a chave
 * de API: só uma mensagem segura e o status HTTP.
 */
class NfeIoException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
