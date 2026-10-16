<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
$pageTitle = 'Minha conta | Oportuniza Bié';
$user = current_user();
if (!$user) { flash('warning', 'Inicia sessão para aceder à tua conta.'); redirect_to('auth/entrar.php'); }
$assetPrefix = '';
$errors = [];
$profile = ['location' => 'Cuito, Bié', 'biography' => '', 'skills' => '', 'education' => ''];
$applications = $orders = $myJobs = $myTasks = $notifications = $incomingOrders = [];
$pdo = db_or_null();
if (!$pdo) {
    $errors[] = 'A base de dados não está disponível. Os dados da conta não podem ser carregados neste momento.';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_is_valid()) {
            $errors[] = 'O formulário expirou. Actualiza a página e tenta novamente.';
        } elseif (($_POST['action'] ?? '') === 'update_roles') {
            $allowedRoles = ['candidate', 'employer', 'service_provider', 'client', 'seller', 'buyer'];
            $selectedRoles = array_values(array_unique(array_filter((array) ($_POST['roles'] ?? []), static fn ($role) => is_string($role) && in_array($role, $allowedRoles, true))));
            if ($selectedRoles === []) {
                $errors[] = 'Selecciona pelo menos uma actividade para a tua conta.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $delete = $pdo->prepare("DELETE ur FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.role_key <> 'admin'");
                    $delete->execute([$user['id']]);
                    $roleLookup = $pdo->prepare('SELECT id FROM roles WHERE role_key = ?');
                    $assign = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
                    foreach ($selectedRoles as $roleKey) {
                        $roleLookup->execute([$roleKey]);
                        $roleId = $roleLookup->fetchColumn();
                        if ($roleId) $assign->execute([$user['id'], $roleId]);
                    }
                    $pdo->commit();
                    $_SESSION['user_roles'] = array_values(array_unique(array_merge($selectedRoles, has_role('admin') ? ['admin'] : [])));
                    flash('success', 'As actividades da tua conta foram actualizadas.');
                    redirect_to('conta.php');
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Actualização de actividades: ' . $exception->getMessage());
                    $errors[] = 'Não foi possível actualizar as actividades da conta.';
                }
            }
        } elseif (($_POST['action'] ?? '') === 'update_profile') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $profile = [
                'location' => trim((string) ($_POST['location'] ?? 'Cuito, Bié')),
                'biography' => trim((string) ($_POST['biography'] ?? '')),
                'skills' => trim((string) ($_POST['skills'] ?? '')),
                'education' => trim((string) ($_POST['education'] ?? '')),
            ];
            if (strlen($fullName) < 3 || strlen($fullName) > 160) $errors[] = 'O nome deve conter entre 3 e 160 caracteres.';
            if ($phone !== '' && !preg_match('/^[0-9+()\s.-]{7,30}$/', $phone)) $errors[] = 'O número de telefone não parece válido.';
            if (strlen($profile['biography']) > 3000 || strlen($profile['skills']) > 2000 || strlen($profile['education']) > 180 || strlen($profile['location']) > 120) $errors[] = 'Um dos campos ultrapassa o limite permitido.';
            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    $updateUser = $pdo->prepare('UPDATE users SET full_name = ?, phone = ? WHERE id = ?');
                    $updateUser->execute([$fullName, $phone ?: null, $user['id']]);
                    $updateProfile = $pdo->prepare('INSERT INTO user_profiles (user_id, location, biography, skills, education) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE location = VALUES(location), biography = VALUES(biography), skills = VALUES(skills), education = VALUES(education)');
                    $updateProfile->execute([$user['id'], $profile['location'] ?: 'Cuito, Bié', $profile['biography'] ?: null, $profile['skills'] ?: null, $profile['education'] ?: null]);
                    $pdo->commit();
                    $_SESSION['user']['full_name'] = $fullName;
                    $_SESSION['user']['phone'] = $phone;
                    flash('success', 'O teu perfil foi actualizado.');
                    redirect_to('conta.php');
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Actualização de perfil: ' . $exception->getMessage());
                    $errors[] = 'Não foi possível actualizar o perfil. Tenta novamente.';
                }
            }
        }
    }
    try {
        $stmt = $pdo->prepare('SELECT location, biography, skills, education FROM user_profiles WHERE user_id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $profile = array_merge($profile, $stmt->fetch() ?: []);
        $stmt = $pdo->prepare('SELECT a.id, a.status, a.created_at, a.compatibility_score, j.title, c.company_name FROM applications a INNER JOIN job_vacancies j ON j.id = a.vacancy_id INNER JOIN companies c ON c.id = j.company_id WHERE a.candidate_id = ? ORDER BY a.created_at DESC LIMIT 10');
        $stmt->execute([$user['id']]); $applications = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT id, order_reference, total_amount, status, created_at FROM orders WHERE buyer_id = ? ORDER BY created_at DESC LIMIT 10');
        $stmt->execute([$user['id']]); $orders = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT id, title, status, created_at FROM job_vacancies WHERE company_id IN (SELECT id FROM companies WHERE user_id = ?) ORDER BY created_at DESC LIMIT 5');
        $stmt->execute([$user['id']]); $myJobs = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT id, title, status, created_at FROM tasks WHERE owner_id = ? ORDER BY created_at DESC LIMIT 5');
        $stmt->execute([$user['id']]); $myTasks = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT title, message, created_at, read_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
        $stmt->execute([$user['id']]); $notifications = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT o.id, o.order_reference, o.status, o.created_at, o.notes, o.share_contact, b.full_name AS buyer_name, b.phone AS buyer_phone, GROUP_CONCAT(DISTINCT p.title ORDER BY p.title SEPARATOR ", ") AS products, SUM(oi.quantity * oi.unit_price) AS seller_total FROM orders o INNER JOIN order_items oi ON oi.order_id = o.id INNER JOIN products p ON p.id = oi.product_id INNER JOIN users b ON b.id = o.buyer_id WHERE oi.seller_id = ? GROUP BY o.id, o.order_reference, o.status, o.created_at, o.notes, o.share_contact, b.full_name, b.phone ORDER BY o.created_at DESC LIMIT 10');
        $stmt->execute([$user['id']]); $incomingOrders = $stmt->fetchAll();
    } catch (Throwable $exception) {
        error_log('Carregamento de conta: ' . $exception->getMessage());
        $errors[] = 'Algumas informações da conta não puderam ser carregadas.';
    }
}
?>
<?php require __DIR__ . '/includes/header.php'; ?>
<main class="container section account-page">
  <div class="page-heading"><div><span class="eyebrow">Área pessoal</span><h1>Olá, <?= e(explode(' ', $user['full_name'])[0]) ?>.</h1><p class="muted">Gere o teu perfil e acompanha a tua actividade no Oportuniza Bié.</p></div><span class="role-chip"><?= e(implode(' · ', array_map('role_label', $_SESSION['user_roles'] ?? []))) ?></span></div>
  <?php if ($errors): ?><div class="flash flash-danger"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <div class="account-grid">
    <section class="form-panel account-profile"><h2>O teu perfil</h2><p class="muted">Mantém os teus dados profissionais actualizados.</p>
      <form method="post" class="stack-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="update_profile">
        <label>Nome completo<input type="text" name="full_name" value="<?= e($user['full_name']) ?>" required maxlength="160"></label>
        <label>E-mail<input type="email" value="<?= e($user['email']) ?>" disabled><small>O e-mail é utilizado como identificador da conta.</small></label>
        <label>Telefone<input type="tel" name="phone" value="<?= e($user['phone']) ?>" maxlength="30"></label>
        <label>Localização<input type="text" name="location" value="<?= e($profile['location']) ?>" maxlength="120"></label>
        <label>Formação académica/profissional<input type="text" name="education" value="<?= e($profile['education']) ?>" maxlength="180"></label>
        <label>Competências<textarea name="skills" rows="3" maxlength="2000" placeholder="Ex.: informática, contabilidade, electricidade..."><?= e($profile['skills']) ?></textarea></label>
        <label>Apresentação breve<textarea name="biography" rows="4" maxlength="3000"><?= e($profile['biography']) ?></textarea></label>
        <button class="btn btn-primary" type="submit">Guardar alterações</button>
      </form>
    </section>
    <div class="account-side">
      <?php if (in_array('candidate', $_SESSION['user_roles'] ?? [], true)): ?><section class="content-panel"><div class="panel-heading"><h2>As minhas candidaturas</h2><a href="modules/emprego/index.php">Ver vagas</a></div><?php if (!$applications): ?><p class="empty-state">Ainda não submeteste candidaturas.</p><?php else: ?><div class="mini-list"><?php foreach ($applications as $item): ?><article><div><strong><?= e($item['title']) ?></strong><small><?= e($item['company_name']) ?> · <?= e(date('d/m/Y', strtotime($item['created_at']))) ?></small></div><span class="status-chip"><?= e($item['status']) ?></span><?php if ($item['compatibility_score'] !== null): ?><small>Compatibilidade indicativa: <?= e((int) $item['compatibility_score']) ?>%</small><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?></section><?php endif; ?>
      <?php if ($myJobs || has_role(['employer','admin'])): ?><section class="content-panel"><div class="panel-heading"><h2>As minhas vagas</h2><a href="modules/emprego/index.php">Gerir vagas</a></div><?php if (!$myJobs): ?><p class="empty-state">Ainda não publicaste vagas.</p><?php else: ?><div class="mini-list"><?php foreach ($myJobs as $item): ?><article><strong><?= e($item['title']) ?></strong><span class="status-chip"><?= e($item['status']) ?></span></article><?php endforeach; ?></div><?php endif; ?></section><?php endif; ?>
      <?php if ($myTasks || has_role(['client','admin'])): ?><section class="content-panel"><div class="panel-heading"><h2>As minhas microtarefas</h2><a href="modules/microtarefas/index.php">Ver tarefas</a></div><?php if (!$myTasks): ?><p class="empty-state">Ainda não publicaste microtarefas.</p><?php else: ?><div class="mini-list"><?php foreach ($myTasks as $item): ?><article><strong><?= e($item['title']) ?></strong><span class="status-chip"><?= e($item['status']) ?></span></article><?php endforeach; ?></div><?php endif; ?></section><?php endif; ?>
      <?php if ($orders || in_array('buyer', $_SESSION['user_roles'] ?? [], true)): ?><section class="content-panel"><div class="panel-heading"><h2>As minhas encomendas</h2><a href="modules/mercado/index.php">Visitar mercado</a></div><?php if (!$orders): ?><p class="empty-state">Ainda não tens encomendas.</p><?php else: ?><div class="mini-list"><?php foreach ($orders as $item): ?><article><div><strong><?= e($item['order_reference']) ?></strong><small><?= e(date('d/m/Y', strtotime($item['created_at']))) ?> · <?= e(format_aoa($item['total_amount'])) ?></small></div><span class="status-chip"><?= e(str_replace('_', ' ', $item['status'])) ?></span></article><?php endforeach; ?></div><p class="form-note">Os pagamentos electrónicos não estão ligados; as encomendas ficam pendentes.</p><?php endif; ?></section><?php endif; ?>
      <?php if ($incomingOrders || in_array('seller', $_SESSION['user_roles'] ?? [], true)): ?><section class="content-panel"><div class="panel-heading"><h2>Pedidos recebidos</h2><a href="modules/mercado/index.php">Mercado</a></div><?php if (!$incomingOrders): ?><p class="empty-state">Ainda não recebeste pedidos de produtos.</p><?php else: ?><div class="mini-list"><?php foreach ($incomingOrders as $item): ?><article><div><strong><?= e($item['order_reference']) ?> · <?= e($item['buyer_name']) ?></strong><small><?= e($item['products']) ?></small><?php if ($item['notes']): ?><small><?= e($item['notes']) ?></small><?php endif; ?><small><?= e(date('d/m/Y H:i', strtotime($item['created_at']))) ?> · Itens da tua loja: <?= e(format_aoa($item['seller_total'])) ?></small><small>Estado: <?= e(str_replace('_', ' ', $item['status'])) ?></small><?php if ((int)$item['share_contact'] === 1 && $item['buyer_phone']): ?><small>Telefone autorizado: <?= e($item['buyer_phone']) ?></small><?php else: ?><small>O comprador não autorizou a partilha do telefone.</small><?php endif; ?></div></article><?php endforeach; ?></div><p class="form-note">Os pedidos não representam pagamentos recebidos. Combina pagamento e entrega com o comprador apenas depois de confirmares a disponibilidade.</p><?php endif; ?></section><?php endif; ?>
      <section class="content-panel"><div class="panel-heading"><h2>Notificações</h2></div><?php if (!$notifications): ?><p class="empty-state">Não tens notificações.</p><?php else: ?><div class="mini-list"><?php foreach ($notifications as $item): ?><article><div><strong><?= e($item['title']) ?></strong><small><?= e($item['message']) ?></small></div></article><?php endforeach; ?></div><?php endif; ?></section>
    </div>
  </div>
  <section class="content-panel roles-panel"><div class="panel-heading"><h2>Actividades da conta</h2></div><p class="muted">Podes usar a mesma conta para mais do que uma finalidade. Assinala os módulos que pretendes utilizar; a função de administrador não pode ser atribuída por este formulário.</p><form method="post" class="role-options-form"><?= csrf_field() ?><input type="hidden" name="action" value="update_roles"><?php $availableRoles = ['candidate'=>'Candidatar-me a vagas','employer'=>'Publicar vagas de emprego','service_provider'=>'Divulgar serviços e responder a microtarefas','client'=>'Publicar microtarefas','seller'=>'Publicar produtos no mercado','buyer'=>'Comprar produtos e enviar pedidos']; foreach ($availableRoles as $roleKey => $roleText): ?><label class="role-option"><input type="checkbox" name="roles[]" value="<?= e($roleKey) ?>" <?= in_array($roleKey, $_SESSION['user_roles'] ?? [], true) ? 'checked' : '' ?>><span><?= e($roleText) ?></span></label><?php endforeach; ?><button class="btn btn-primary" type="submit">Guardar actividades</button></form></section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
