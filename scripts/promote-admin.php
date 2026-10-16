<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Not found\n"); }
$email = strtolower(trim((string)($argv[1] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php scripts/promote-admin.php email@exemplo.com\nRegista primeiro uma conta normal e depois executa este comando.\n");
    exit(2);
}
try {
    $pdo = db();
    $pdo->beginTransaction();
    $userStmt = $pdo->prepare('SELECT id, full_name FROM users WHERE email = ? LIMIT 1');
    $userStmt->execute([$email]);
    $user = $userStmt->fetch();
    if (!$user) { $pdo->rollBack(); fwrite(STDERR, "Não foi encontrada uma conta com esse e-mail.\n"); exit(1); }
    $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE role_key='admin' LIMIT 1");
    $roleStmt->execute(); $roleId = $roleStmt->fetchColumn();
    if (!$roleId) { $pdo->rollBack(); fwrite(STDERR, "A função admin não existe. Importa database/schema.sql.\n"); exit(1); }
    $assign = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)');
    $assign->execute([$user['id'], $roleId]);
    write_audit_log($pdo, (int)$user['id'], 'admin_role_granted', 'user', (int)$user['id']);
    $pdo->commit();
    fwrite(STDOUT, "A conta {$user['full_name']} ({$email}) recebeu a função de administrador. Termina a sessão e volta a entrar.\n");
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Falha ao atribuir administrador: {$exception->getMessage()}\n");
    exit(1);
}
