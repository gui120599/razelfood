<?php

namespace App\Exceptions;

use Exception;

/**
 * Transição de status de pedido inválida (avançar pedido sem próximo status,
 * cancelar pedido já finalizado, confirmar entrega fora de "em transporte"...) —
 * mensagem já é segura para mostrar a quem opera o painel (RN-32).
 */
class OrderTransitionException extends Exception {}
