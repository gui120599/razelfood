<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Regra de negócio que impede reservar/montar/emitir o documento fiscal.
 * A mensagem é segura e em pt-BR, própria para exibir ao usuário.
 */
class FiscalDocumentException extends RuntimeException {}
