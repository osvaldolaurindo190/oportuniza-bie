<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$pageTitle = 'Entrar | Oportuniza Bié';
$assetPrefix = '../';
$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    if (!csrf_is_valid()) {
        $error = 'O formulário expirou. Actualiza a página e tenta novamente.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Introduz o e-mail e a palavra-passe.';
    } else {
        $pdo = db_or_null();
        if (!$pdo) {
            $error = 'A base de dados não está disponível. Verifica se o MySQL está activo e configurado.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT id, full_name, email, phone, password_hash, account_status FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                if (!$user || !password_verify($password, $user['password_hash'])) {
                    $error = 'E-mail ou palavra-passe incorrectos.';
                } elseif ($user['account_status'] !== 'active') {
                    $error = 'Esta conta não está activa. Contacta a administração da plataforma.';
                } else {
                    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                        $update = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                        $update->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                    }
                    $rolesQuery = $pdo->prepare('SELECT r.role_key FROM roles r INNER JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?');
                    $rolesQuery->execute([$user['id']]);
                    login_user($user, array_column($rolesQuery->fetchAll(), 'role_key'));
                    flash('success', 'Sessão iniciada com sucesso.');
                    redirect_to('conta.php');
                }
            } catch (Throwable $exception) {
                error_log('Falha na autenticação: ' . $exception->getMessage());
                $error = 'Não foi possível validar a conta. Verifica se a base de dados está actualizada e tenta novamente.';
            }
        }
    }
}
?>
<?php require __DIR__ . '/../includes/header.php'; ?>
<main class="container auth-layout section auth-layout-login">
  <section class="auth-intro"><span class="eyebrow">Bem-vindo de volta</span><h1>As oportunidades continuam.</h1><p>Acede à tua conta para acompanhar candidaturas, gerir anúncios e consultar as tuas actividades no Oportuniza Bié.</p><div class="auth-highlight"><strong>Oportuniza Bié</strong><span>Emprego · Microtarefas · Serviços · Mercado</span></div></section>
  <section class="form-panel"><h2>Entrar na conta</h2><p class="muted">Utiliza o e-mail e a palavra-passe do registo.</p>
    <?php if ($error): ?><div class="flash flash-danger"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="stack-form">
      <?= csrf_field() ?>
      <label>E-mail<input name="email" type="email" value="<?= e($email) ?>" autocomplete="email" required></label>
      <label>Palavra-passe<input name="password" type="password" autocomplete="current-password" required></label>
      <button class="btn btn-primary btn-full" type="submit">Entrar</button>
    </form>
    <p class="form-bottom">Ainda não tens conta? <a href="registar.php">Criar conta</a></p>
  </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
