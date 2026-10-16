<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
ob_start(); // Não enviar saída antes de testar a regeneração da sessão.
$tests = 0;
function check(bool $condition, string $message): void {
    global $tests;
    $tests++;
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    echo "PASS: {$message}\n";
}
check(e('<script>') === '&lt;script&gt;', 'escapa conteúdo HTML');
check(allowed_date('2026-10-16') === '2026-10-16', 'aceita data válida');
check(allowed_date('2026-02-31') === null, 'rejeita data inexistente');
check(format_aoa(1234.5) === '1.234,50 Kz', 'formata valores em Kz');
check(compatibility_score('MySQL', 'Informática', 'Experiência com MySQL e Informática') === 100, 'calcula compatibilidade indicativa quando existem correspondências');
check(compatibility_score('', '', 'qualquer vaga') === null, 'não calcula compatibilidade sem perfil profissional');
$token = csrf_token();
$_POST['_csrf'] = $token;
check(csrf_is_valid(), 'aceita token CSRF válido');
$_POST['_csrf'] = 'token-inválido';
check(!csrf_is_valid(), 'rejeita token CSRF inválido');
$_SESSION['user_roles'] = ['candidate'];
check(has_role('candidate'), 'detecta função existente');
check(!has_role('admin'), 'não concede função não atribuída');
check(role_label('seller') === 'Vendedor', 'apresenta nome da função');
$csrfBeforeLogin = csrf_token();
login_user(['id' => 17, 'full_name' => 'Utilizador de Teste', 'email' => 'teste@example.com', 'phone' => ''], ['candidate']);
$csrfAfterLogin = csrf_token();
check($csrfBeforeLogin !== $csrfAfterLogin, 'renova o token CSRF após iniciar sessão');
check((int) (current_user()['id'] ?? 0) === 17, 'guarda o utilizador autenticado na sessão');
echo "Concluído: {$tests} testes unitários passaram.\n";
ob_end_flush();
