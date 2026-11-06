<?php

namespace App\Exceptions;

use Exception;

/**
 * Os pedidos de uma venda mudaram entre a leitura dos ids (feita ANTES da transação, para poder
 * travar os pedidos antes da venda) e o lock da venda. Interna às Actions de venda: elas
 * refazem a operação e só então viram SaleException para quem chamou.
 */
class SaleOrdersChanged extends Exception {}
