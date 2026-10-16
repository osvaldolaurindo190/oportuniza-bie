<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
$pageTitle = 'Microtarefas | Oportuniza Bié';
$assetPrefix = '../../';
$pdo = db_or_null(); $errors = []; $tasks = []; $myApplications = []; $completedTasks = [];

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'report') {
    if (!csrf_is_valid()) { flash('danger', 'O formulário expirou. Actualiza a página e tenta novamente.'); redirect_to('modules/microtarefas/index.php'); }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create_task') {
        require_role(['client','admin']);
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $deadline = allowed_date((string) ($_POST['deadline'] ?? ''));
        if (strlen($title) < 4 || strlen($title) > 180) $errors[] = 'O título deve conter entre 4 e 180 caracteres.';
        if (strlen($description) < 20 || strlen($description) > 8000) $errors[] = 'A descrição deve conter entre 20 e 8.000 caracteres.';
        if ($price === false || $price <= 0 || $price > 1000000000) $errors[] = 'Indica um valor válido, superior a zero.';
        if (!empty($_POST['deadline']) && !$deadline) $errors[] = 'A data limite não é válida.';
        if ($deadline && $deadline < date('Y-m-d')) $errors[] = 'A data limite não pode estar no passado.';
        if (!$errors) {
            try {
                $stmt = $pdo->prepare("INSERT INTO tasks (owner_id, title, description, price, deadline, status) VALUES (?, ?, ?, ?, ?, 'published')");
                $stmt->execute([current_user()['id'], $title, $description, $price, $deadline]);
                $id = (int) $pdo->lastInsertId(); write_audit_log($pdo, (int) current_user()['id'], 'task_created', 'task', $id);
                flash('success', 'A microtarefa foi publicada.'); redirect_to('modules/microtarefas/index.php');
            } catch (Throwable $exception) { error_log('Publicação de tarefa: ' . $exception->getMessage()); $errors[] = 'Não foi possível publicar a microtarefa.'; }
        }
    } elseif ($action === 'apply_task') {
        require_role(['service_provider','candidate','admin']);
        $taskId = filter_var($_POST['task_id'] ?? null, FILTER_VALIDATE_INT);
        $proposal = trim((string) ($_POST['proposal'] ?? ''));
        if (!$taskId || $taskId < 1 || strlen($proposal) > 3000) { flash('danger','Dados inválidos.'); redirect_to('modules/microtarefas/index.php'); }
        $valid = $pdo->prepare("SELECT id FROM tasks WHERE id = ? AND status = 'published' AND owner_id <> ? AND (deadline IS NULL OR deadline >= CURRENT_DATE())");
        $valid->execute([$taskId, current_user()['id']]);
        if (!$valid->fetchColumn()) { flash('warning', 'Esta microtarefa já não está disponível.'); redirect_to('modules/microtarefas/index.php'); }
        try {
            $stmt = $pdo->prepare('INSERT INTO task_applications (task_id, provider_id, proposal) VALUES (?, ?, ?)');
            $stmt->execute([$taskId, current_user()['id'], $proposal ?: null]);
            flash('success', 'A tua manifestação de interesse foi registada.');
        } catch (PDOException $exception) { flash($exception->getCode() === '23000' ? 'warning' : 'danger', $exception->getCode() === '23000' ? 'Já manifestaste interesse nesta tarefa.' : 'Não foi possível registar a candidatura.'); }
        redirect_to('modules/microtarefas/index.php');
    } elseif ($action === 'update_task_application') {
        require_role(['client','admin']);
        $appId = filter_var($_POST['application_id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? '');
        if (!$appId || !in_array($status, ['selected','rejected'], true)) { flash('danger','Actualização inválida.'); redirect_to('modules/microtarefas/index.php'); }
        try {
            $pdo->beginTransaction();
            if (has_role('admin')) {
                $find = $pdo->prepare('SELECT ta.id, ta.task_id, t.status AS task_status FROM task_applications ta INNER JOIN tasks t ON t.id=ta.task_id WHERE ta.id=? FOR UPDATE');
                $find->execute([$appId]);
            } else {
                $find = $pdo->prepare('SELECT ta.id, ta.task_id, t.status AS task_status FROM task_applications ta INNER JOIN tasks t ON t.id=ta.task_id WHERE ta.id=? AND t.owner_id=? FOR UPDATE');
                $find->execute([$appId, current_user()['id']]);
            }
            $application = $find->fetch();
            if (!$application || $application['task_status'] !== 'published') throw new RuntimeException('Só é possível alterar candidaturas enquanto a tarefa aguarda a selecção de um prestador.');
            if ($status === 'selected') {
                $rejectOthers = $pdo->prepare("UPDATE task_applications SET status='rejected' WHERE task_id=? AND id<>? AND status IN ('submitted','selected')");
                $rejectOthers->execute([$application['task_id'], $appId]);
            }
            $update = $pdo->prepare('UPDATE task_applications SET status=? WHERE id=?');
            $update->execute([$status, $appId]);
            if ($status === 'selected') {
                $updateTask = $pdo->prepare("UPDATE tasks SET status='in_progress' WHERE id=? AND status='published'");
                $updateTask->execute([$application['task_id']]);
            }
            $pdo->commit();
            write_audit_log($pdo, (int)current_user()['id'], 'task_application_updated', 'task_application', (int)$appId);
            flash('success', $status === 'selected' ? 'Prestador seleccionado. Quando o trabalho terminar, marca a tarefa como concluída para permitir avaliações.' : 'Manifestação de interesse rejeitada.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Actualização de resposta à tarefa: '.$exception->getMessage());
            flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'Não foi possível actualizar a resposta.');
        }
        redirect_to('modules/microtarefas/index.php');
    } elseif ($action === 'complete_task') {
        require_role(['client','admin']);
        $taskId = filter_var($_POST['task_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$taskId || $taskId < 1) { flash('danger','Tarefa inválida.'); redirect_to('modules/microtarefas/index.php'); }
        if (has_role('admin')) {
            $stmt = $pdo->prepare("UPDATE tasks SET status='completed' WHERE id=? AND status='in_progress' AND EXISTS (SELECT 1 FROM task_applications WHERE task_id=? AND status='selected')");
            $stmt->execute([$taskId, $taskId]);
        } else {
            $stmt = $pdo->prepare("UPDATE tasks SET status='completed' WHERE id=? AND owner_id=? AND status='in_progress' AND EXISTS (SELECT 1 FROM task_applications WHERE task_id=? AND status='selected')");
            $stmt->execute([$taskId, current_user()['id'], $taskId]);
        }
        if ($stmt->rowCount()) { write_audit_log($pdo,(int)current_user()['id'],'task_completed','task',(int)$taskId); flash('success','Tarefa marcada como concluída. Os participantes já podem avaliar a experiência.'); }
        else flash('warning','Só é possível concluir uma tarefa em andamento que tenha um prestador seleccionado.');
        redirect_to('modules/microtarefas/index.php#avaliacoes');
    } elseif ($action === 'create_review') {
        require_login();
        $taskId = filter_var($_POST['task_id'] ?? null, FILTER_VALIDATE_INT);
        $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
        $comment = trim((string)($_POST['comment'] ?? ''));
        if (!$taskId || $taskId < 1 || $rating === false || $rating < 1 || $rating > 5 || strlen($comment) > 1000) { flash('danger','Os dados da avaliação são inválidos.'); redirect_to('modules/microtarefas/index.php#avaliacoes'); }
        $stmt = $pdo->prepare("SELECT t.owner_id, ta.provider_id FROM tasks t INNER JOIN task_applications ta ON ta.task_id=t.id AND ta.status='selected' WHERE t.id=? AND t.status='completed' LIMIT 1");
        $stmt->execute([$taskId]); $eligible = $stmt->fetch();
        if (!$eligible) { flash('warning','A avaliação só está disponível depois da conclusão de uma tarefa com prestador seleccionado.'); redirect_to('modules/microtarefas/index.php#avaliacoes'); }
        $userId = (int)current_user()['id'];
        if ($userId === (int)$eligible['owner_id']) $targetId = (int)$eligible['provider_id'];
        elseif ($userId === (int)$eligible['provider_id']) $targetId = (int)$eligible['owner_id'];
        else { http_response_code(403); exit('Não tens permissão para avaliar esta tarefa.'); }
        try {
            $stmt = $pdo->prepare('INSERT INTO reviews (author_id,target_user_id,task_id,rating,comment) VALUES (?,?,?,?,?)');
            $stmt->execute([$userId,$targetId,$taskId,$rating,$comment ?: null]);
            write_audit_log($pdo,$userId,'review_created','task',(int)$taskId);
            flash('success','A tua avaliação foi registada. Obrigado pelo contributo.');
        } catch (PDOException $exception) {
            flash($exception->getCode()==='23000'?'warning':'danger',$exception->getCode()==='23000'?'Já registaste uma avaliação para esta tarefa.':'Não foi possível guardar a avaliação.');
        }
        redirect_to('modules/microtarefas/index.php#avaliacoes');
    }
}
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report') handle_report_submission($pdo, 'modules/microtarefas/index.php');
$search = trim((string) ($_GET['q'] ?? ''));
if ($pdo) {
    try {
        $sql = "SELECT t.*, u.full_name AS owner_name, (SELECT ROUND(AVG(r.rating),1) FROM reviews r WHERE r.target_user_id=t.owner_id) AS rating_average, (SELECT COUNT(*) FROM reviews r WHERE r.target_user_id=t.owner_id) AS rating_count FROM tasks t INNER JOIN users u ON u.id = t.owner_id WHERE t.status = 'published' AND (t.deadline IS NULL OR t.deadline >= CURRENT_DATE())";
        $params = [];
        if ($search !== '') { $sql .= ' AND (t.title LIKE ? OR t.description LIKE ?)'; $like = '%' . $search . '%'; $params = [$like, $like]; }
        $sql .= ' ORDER BY t.created_at DESC LIMIT 60'; $stmt = $pdo->prepare($sql); $stmt->execute($params); $tasks = $stmt->fetchAll();
        if (current_user() && has_role(['client','admin'])) {
            if (has_role('admin')) {
                $stmt = $pdo->query('SELECT ta.id, ta.task_id, ta.status, ta.proposal, ta.created_at, u.full_name AS provider_name, t.title, t.status AS task_status FROM task_applications ta INNER JOIN tasks t ON t.id = ta.task_id INNER JOIN users u ON u.id = ta.provider_id ORDER BY ta.created_at DESC LIMIT 30');
            } else {
                $stmt = $pdo->prepare('SELECT ta.id, ta.task_id, ta.status, ta.proposal, ta.created_at, u.full_name AS provider_name, t.title, t.status AS task_status FROM task_applications ta INNER JOIN tasks t ON t.id = ta.task_id INNER JOIN users u ON u.id = ta.provider_id WHERE t.owner_id = ? ORDER BY ta.created_at DESC LIMIT 30');
                $stmt->execute([current_user()['id']]);
            }
            $myApplications = $stmt->fetchAll();
        }
        if (current_user()) {
            $stmt = $pdo->prepare("SELECT t.id,t.title,t.owner_id,owner.full_name AS owner_name,ta.provider_id,provider.full_name AS provider_name FROM tasks t INNER JOIN task_applications ta ON ta.task_id=t.id AND ta.status='selected' INNER JOIN users owner ON owner.id=t.owner_id INNER JOIN users provider ON provider.id=ta.provider_id WHERE t.status='completed' AND (t.owner_id=? OR ta.provider_id=?) AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.task_id=t.id AND r.author_id=?) ORDER BY t.created_at DESC LIMIT 20");
            $stmt->execute([current_user()['id'],current_user()['id'],current_user()['id']]);
            $completedTasks = $stmt->fetchAll();
        }
    } catch (Throwable $exception) { error_log('Listagem de tarefas: ' . $exception->getMessage()); $errors[] = 'Não foi possível carregar as microtarefas.'; }
}
?>
<?php require __DIR__ . '/../../includes/header.php'; ?>
<main class="container section module-page">
  <div class="page-heading"><div><span class="eyebrow">02 / Tarefas</span><h1>Microtarefas</h1><p class="muted">Trabalhos de curta duração, com descrição, prazo e remuneração indicativa.</p></div><?php if (has_role(['client','admin'])): ?><a class="btn btn-primary" href="#publicar-tarefa">+ Publicar tarefa</a><?php endif; ?></div>
  <?php if (!$pdo): ?><div class="flash flash-warning">A base de dados não está ligada. Configura o MySQL para publicar e consultar tarefas.</div><?php endif; ?>
  <?php if ($errors): ?><div class="flash flash-danger"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="get" class="search-bar"><label class="sr-only" for="task-q">Pesquisar tarefas</label><input id="task-q" name="q" type="search" value="<?= e($search) ?>" placeholder="Pesquisar tarefa ou competência"><button class="btn btn-primary">Pesquisar</button></form>
  <?php if (has_role(['client','admin'])): ?><section class="content-panel form-panel module-form" id="publicar-tarefa"><h2>Publicar uma microtarefa</h2><form method="post" class="stack-form"><?= csrf_field() ?><input type="hidden" name="action" value="create_task"><div class="form-grid"><label>Título da tarefa<input name="title" required minlength="4" maxlength="180"></label><label>Remuneração prevista (Kz)<input name="price" type="number" min="1" step="0.01" required></label><label>Prazo de conclusão<input type="date" name="deadline" min="<?= e(date('Y-m-d')) ?>"></label></div><label>Descrição e condições<textarea name="description" required minlength="20" maxlength="8000" rows="4" placeholder="Explica a actividade, o resultado esperado e o prazo..."></textarea></label><button class="btn btn-primary">Publicar microtarefa</button></form></section><?php elseif (current_user()): ?><div class="inline-notice">Para publicar tarefas, activa o perfil de Cliente na tua conta.</div><?php endif; ?>
  <div class="section-heading module-section-heading"><div><span class="eyebrow">Trabalhos disponíveis</span><h2><?= count($tasks) ?> <?= count($tasks) === 1 ? 'tarefa disponível' : 'tarefas disponíveis' ?></h2></div></div>
  <?php if (!$tasks): ?><div class="empty-card"><span class="empty-icon">↗</span><h3>Ainda não há microtarefas</h3><p>As novas tarefas publicadas aparecerão aqui.</p></div><?php else: ?><div class="listing-grid"><?php foreach ($tasks as $task): ?><article class="listing-card"><div class="listing-top"><span class="tag tag-orange">Microtarefa</span><span class="listing-date"><?= e(date('d/m/Y', strtotime($task['created_at']))) ?></span></div><h3><?= e($task['title']) ?></h3><p class="listing-subtitle">Publicado por <?= e($task['owner_name']) ?></p><?php if ((int)$task['rating_count'] > 0): ?><p class="rating-line">★ <?= e($task['rating_average']) ?> <span>(<?= e($task['rating_count']) ?> avaliação(ões))</span></p><?php else: ?><p class="rating-line muted">Anunciante sem avaliações</p><?php endif; ?><p class="listing-description"><?= nl2br(e(safe_cut($task['description'], 260))) ?><?= strlen($task['description']) > 260 ? '…' : '' ?></p><div class="price-line"><?= e(format_aoa($task['price'])) ?> <small>remuneração anunciada</small></div><?php if ($task['deadline']): ?><p class="deadline">Prazo: <?= e(date('d/m/Y', strtotime($task['deadline']))) ?></p><?php endif; ?><?php if (current_user() && has_role(['service_provider','candidate','admin'])): ?><form method="post" class="stack-form compact-form"><?= csrf_field() ?><input type="hidden" name="action" value="apply_task"><input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>"><label>Mensagem de apresentação (opcional)<textarea name="proposal" rows="2" maxlength="3000"></textarea></label><button class="btn btn-primary btn-small">Manifestar interesse</button></form><?php elseif (!current_user()): ?><a class="btn btn-primary btn-small" href="../../auth/entrar.php">Entrar para responder</a><?php endif; ?><?= listing_report_form('task', (int)$task['id']) ?></article><?php endforeach; ?></div><?php endif; ?>
  <?php if (has_role(['client','admin'])): ?><section class="content-panel employer-applications"><div class="panel-heading"><h2>Respostas às minhas tarefas</h2></div><?php if (!$myApplications): ?><p class="empty-state">Ainda não recebeste manifestações de interesse.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Prestador</th><th>Tarefa</th><th>Mensagem</th><th>Estado</th><th>Acção</th></tr></thead><tbody><?php foreach ($myApplications as $app): ?><tr><td><?= e($app['provider_name']) ?></td><td><?= e($app['title']) ?></td><td><?= e($app['proposal']) ?></td><td><?= e($app['status']) ?><small><?= e($app['task_status']) ?></small></td><td><?php if ($app['task_status'] === 'published' && $app['status'] !== 'rejected'): ?><form method="post" class="table-action-form"><?= csrf_field() ?><input type="hidden" name="action" value="update_task_application"><input type="hidden" name="application_id" value="<?= (int)$app['id'] ?>"><select name="status"><option value="selected" <?= $app['status']==='selected'?'selected':'' ?>>Seleccionar</option><option value="rejected">Não seleccionar</option></select><button type="submit" class="btn btn-small btn-outline">Guardar</button></form><?php endif; ?><?php if ($app['task_status']==='in_progress' && $app['status']==='selected'): ?><form method="post" class="table-action-form"><?= csrf_field() ?><input type="hidden" name="action" value="complete_task"><input type="hidden" name="task_id" value="<?= (int)$app['task_id'] ?>"><button type="submit" class="btn btn-small btn-primary" data-confirm="Confirmas que esta tarefa foi concluída?">Marcar concluída</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?>
  <?php if (current_user()): ?><section class="content-panel review-panel" id="avaliacoes"><div class="panel-heading"><h2>Avaliações de tarefas concluídas</h2></div><?php if (!$completedTasks): ?><p class="empty-state">Quando uma tarefa partilhada contigo for marcada como concluída, poderás avaliar a experiência.</p><?php else: ?><div class="listing-grid"><?php foreach ($completedTasks as $done): $isOwner = (int)$done['owner_id'] === (int)current_user()['id']; ?><article class="listing-card"><span class="tag tag-green">Tarefa concluída</span><h3><?= e($done['title']) ?></h3><p class="listing-subtitle"><?= $isOwner ? 'Prestador: ' . e($done['provider_name']) : 'Cliente: ' . e($done['owner_name']) ?></p><form method="post" class="stack-form compact-form"><?= csrf_field() ?><input type="hidden" name="action" value="create_review"><input type="hidden" name="task_id" value="<?= (int)$done['id'] ?>"><label>Classificação<select name="rating" required><option value="5">5 — Excelente</option><option value="4">4 — Muito bom</option><option value="3">3 — Satisfatório</option><option value="2">2 — Insatisfatório</option><option value="1">1 — Muito mau</option></select></label><label>Comentário (opcional)<textarea name="comment" rows="3" maxlength="1000"></textarea></label><button class="btn btn-primary btn-small" type="submit">Enviar avaliação</button></form></article><?php endforeach; ?></div><?php endif; ?></section><?php endif; ?>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
<?php function safe_cut(string $value, int $length): string { return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length); } ?>
