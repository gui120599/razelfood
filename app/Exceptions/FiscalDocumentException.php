<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Regra de negócio que impede reservar/montar/emitir/cancelar o documento fiscal.
 * A mensagem é segura e em pt-BR, própria para exibir ao usuário.
 *
 * `requestMayHaveBeenSent`: quando a falha é de um pedido que PODE ter chegado à NFE.io
 * (timeout, 5xx, outra operação em andamento). Quem chama não deve desfazer estado local
 * como se nada tivesse sido pedido.
 */
class FiscalDocumentException extends RuntimeException
{
    public function __construct(string $message = '', public readonly bool $requestMayHaveBeenSent = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
