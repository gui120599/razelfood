<?php

namespace App\Exceptions;

use Exception;

/**
 * Regra de negócio da venda violada (pedido já vendido, pagamento que não fecha
 * a conta, estoque insuficiente de item avulso, cancelamento bloqueado...) —
 * a mensagem já é segura para mostrar a quem opera o painel.
 */
class SaleException extends Exception {}
