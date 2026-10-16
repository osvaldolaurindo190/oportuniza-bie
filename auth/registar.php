<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$pageTitle = 'Criar conta | Oportuniza Bié';
$assetPrefix = '../';
$errors = [];
$old = ['full_name' => '', 'email' => '', 'phone' => '', 'role' => 'candidate'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
    $old['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));
    $old['phone'] = trim((string) ($_POST['phone'] ?? ''));
    $old['role'] = (string) ($_POST['role'] ?? 'candidate');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (!csrf_is_valid()) {
        $errors[] = 'O formulário expirou. Actualiza a página e tenta novamente.';
    } else {
        if (mb_strlen_safe($old['full_name']) < 3 || mb_strlen_safe($old['full_name']) > 160) $errors[] = 'Indica o nome completo (entre 3 e 160 caracteres).';
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Indica um endereço de e-mail válido.';
        if ($old['phone'] !== '' && !preg_match('/^[0-9+()\s.-]{7,30}$/', $old['phone'])) $errors[] = 'Confirma o número de telefone.';
        $allowedRoles = ['candidate', 'employer', 'service_provider', 'client', 'seller', 'buyer'];
        if (!in_array($old['role'], $allowedRoles, true)) $errors[] = 'Selecciona um tipo de conta válido.';
        if (strlen($password) < 8) $errors[] = 'A palavra-passe deve ter pelo menos 8 caracteres.';
        if ($password !== $confirm) $errors[] = 'As palavras-passe não coincidem.';
        if (empty($_POST['consent'])) $errors[] = 'Confirma que compreendeste a finalidade da recolha dos dados.';
    }

    if (!$errors) {
        $pdo = db_or_null();
        if (!$pdo) {
            $errors[] = 'Não foi possível aceder à base de dados. Confirma se o MySQL está activo e se as credenciais estão configuradas.';
        } else {
            try {
                $pdo->beginTransaction();
                $statement = $pdo->prepare('INSERT INTO users (full_name, email, phone, password_hash, account_status) VALUES (?, ?, ?, ?, \'active\')');
                $statement->execute([$old['full_name'], $old['email'], $old['phone'] ?: null, password_hash($password, PASSWORD_DEFAULT)]);
                $userId = (int) $pdo->lastInsertId();
                $roleStatement = $pdo->prepare('SELECT id FROM roles WHERE role_key = ? LIMIT 1');
                $roleStatement->execute([$old['role']]);
                $roleId = $roleStatement->fetchColumn();
                if (!$roleId) throw new RuntimeException('A função seleccionada não existe na base de dados. Importa novamente database/schema.sql.');
                $assign = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
                $assign->execute([$userId, $roleId]);
                $profile = $pdo->prepare('INSERT INTO user_profiles (user_id, location) VALUES (?, ?)');
                $profile->execute([$userId, 'Cuito, Bié']);
                $pdo->commit();

                $roles = [$old['role']];
                login_user(['id' => $userId, 'full_name' => $old['full_name'], 'email' => $old['email'], 'phone' => $old['phone']], $roles);
                flash('success', 'A tua conta foi criada. Bem-vindo(a) ao Oportuniza Bié!');
                redirect_to('conta.php');
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($exception->getCode() === '23000') $errors[] = 'Já existe uma conta com este endereço de e-mail.';
                else { error_log('Falha no registo: ' . $exception->getMessage()); $errors[] = 'Não foi possível concluir o registo. Verifica a base de dados e tenta novamente.'; }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Falha no registo: ' . $exception->getMessage());
                $errors[] = 'O registo não pôde ser concluído. Confirma se o esquema da base de dados está actualizado.';
            }
        }
    }
}

function mb_strlen_safe(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value); }
?>
<?php require __DIR__ . '/../includes/header.php'; ?>
<main class="container auth-layout section">
  <section class="auth-intro"><span class="eyebrow">Começa por aqui</span><h1>Cria a tua conta.</h1><p>Entra numa comunidade digital para encontrar oportunidades, divulgar competências, prestar serviços ou comercializar produtos no Cuito.</p><div class="auth-benefit"><span>✓</span> Perfil simples e organizado</div><div class="auth-benefit"><span>✓</span> Acesso aos módulos adequados à tua actividade</div><div class="auth-benefit"><span>✓</span> Gestão de candidaturas e anúncios</div></section>
  <section class="form-panel"><h2>Criar conta</h2><p class="muted">Preenche os teus dados para começar.</p>
    <?php if ($errors): ?><div class="flash flash-danger"><strong>Verifica os seguintes pontos:</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" class="stack-form" novalidate>
      <?= csrf_field() ?>
      <label>Nome completo<input name="full_name" type="text" value="<?= e($old['full_name']) ?>" minlength="3" maxlength="160" autocomplete="name" required></label>
      <label>E-mail<input name="email" type="email" value="<?= e($old['email']) ?>" maxlength="190" autocomplete="email" required></label>
      <label>Telefone (opcional)<input name="phone" type="tel" value="<?= e($old['phone']) ?>" maxlength="30" autocomplete="tel" placeholder="Ex.: 923 000 000"></label>
      <label>Como pretendes utilizar a plataforma?
        <select name="role" required>
          <?php foreach (['candidate'=>'Candidato a emprego','employer'=>'Empresa ou empregador','service_provider'=>'Prestador de serviços','client'=>'Cliente — publicar tarefas','seller'=>'Vendedor — comercializar produtos','buyer'=>'Comprador'] as $key => $label): ?><option value="<?= e($key) ?>" <?= $old['role'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Palavra-passe<input name="password" type="password" minlength="8" autocomplete="new-password" required><small>Pelo menos 8 caracteres. Evita utilizar uma palavra-passe que já uses noutro serviço.</small></label>
      <label>Confirmar palavra-passe<input name="password_confirm" type="password" minlength="8" autocomplete="new-password" required></label>
      <label class="check-label"><input type="checkbox" name="consent" value="1" required><span>Compreendo que os dados serão utilizados para criar e gerir a minha conta nesta plataforma académica.</span></label>
      <button class="btn btn-primary btn-full" type="submit">Criar conta</button>
    </form>
    <p class="form-bottom">Já tens conta? <a href="entrar.php">Entrar</a></p>
  </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
