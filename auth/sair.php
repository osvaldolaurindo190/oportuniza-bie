<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid()) {
    http_response_code(405);
    exit('Pedido inválido.');
}
logout_user();
header('Location: ../index.php?logout=1');
exit;
