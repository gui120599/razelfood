<?php

namespace App\Exceptions;

use Exception;

/**
 * Erro de negócio do checkout (fechado, esgotado, limite excedido...) —
 * a mensagem já é segura para mostrar ao cliente final.
 */
class CheckoutException extends Exception {}
