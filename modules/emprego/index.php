<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
$pageTitle = 'Emprego e recrutamento | Oportuniza Bié';
$assetPrefix = '../../';
$pdo = db_or_null();
$errors = [];
$successText = '';

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'report') {
    if (!csrf_is_valid()) {
        flash('danger', 'O formulário expirou. Actualiza a página e tenta novamente.');
        redirect_to('modules/emprego/index.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create_job') {
        require_role(['employer', 'admin']);
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $companyName = trim((string) ($_POST['company_name'] ?? ''));
        $location = trim((string) ($_POST['location'] ?? 'Cuito, Bié'));
        $type = (string) ($_POST['employment_type'] ?? 'full_time');
        $deadline = allowed_date((string) ($_POST['deadline'] ?? ''));
        if (strlen($title) < 4 || strlen($title) > 180) $errors[] = 'O título deve conter entre 4 e 180 caracteres.';
        if (strlen($description) < 20 || strlen($description) > 10000) $errors[] = 'A descrição deve conter entre 20 e 10.000 caracteres.';
        if ($companyName === '' || strlen($companyName) > 180) $errors[] = 'Indica o nome da empresa ou empregador.';
        if ($location === '' || strlen($location) > 120) $errors[] = 'Indica uma localização válida.';
        if (!in_array($type, ['full_time','part_time','temporary','internship'], true)) $errors[] = 'Selecciona o tipo de emprego.';
        if (!empty($_POST['deadline']) && !$deadline) $errors[] = 'A data limite não é válida.';
        if ($deadline && $deadline < date('Y-m-d')) $errors[] = 'A data limite não pode estar no passado.';
        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $company = $pdo->prepare('INSERT INTO companies (user_id, company_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE company_name = VALUES(company_name)');
                $company->execute([current_user()['id'], $companyName]);
                $companyStmt = $pdo->prepare('SELECT id FROM companies WHERE user_id = ?');
                $companyStmt->execute([current_user()['id']]);
                $companyId = (int) $companyStmt->fetchColumn();
                $stmt = $pdo->prepare('INSERT INTO job_vacancies (company_id, title, description, location, employment_type, deadline, status) VALUES (?, ?, ?, ?, ?, ?, \'published\')');
                $stmt->execute([$companyId, $title, $description, $location, $type, $deadline]);
                $jobId = (int) $pdo->lastInsertId();
                $pdo->commit();
                write_audit_log($pdo, (int) current_user()['id'], 'job_created', 'job', $jobId);
                flash('success', 'A vaga foi publicada.');
                redirect_to('modules/emprego/index.php');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Publicação de vaga: ' . $exception->getMessage());
                $errors[] = 'Não foi possível publicar a vaga. Tenta novamente.';
            }
        }
    } elseif ($action === 'apply_job') {
        require_role(['candidate', 'admin']);
        $jobId = filter_var($_POST['vacancy_id'] ?? null, FILTER_VALIDATE_INT);
        $message = trim((string) ($_POST['cover_message'] ?? ''));
        if (!$jobId || $jobId < 1 || strlen($message) > 3000) {
            flash('danger', 'Os dados da candidatura são inválidos.'); redirect_to('modules/emprego/index.php');
        }
        try {
            $jobStmt = $pdo->prepare("SELECT j.id, j.title, j.description, j.deadline, p.skills, p.education FROM job_vacancies j LEFT JOIN user_profiles p ON p.user_id = ? WHERE j.id = ? AND j.status = 'published' AND (j.deadline IS NULL OR j.deadline >= CURRENT_DATE()) LIMIT 1");
            $jobStmt->execute([current_user()['id'], $jobId]);
            $job = $jobStmt->fetch();
            if (!$job) { flash('warning', 'Esta vaga já não está disponível para candidaturas.'); redirect_to('modules/emprego/index.php'); }
            $score = compatibility_score($job['skills'] ?? null, $job['education'] ?? null, $job['title'] . ' ' . $job['description']);
            $stmt = $pdo->prepare('INSERT INTO applications (vacancy_id, candidate_id, cover_message, compatibility_score) VALUES (?, ?, ?, ?)');
            $stmt->execute([$jobId, current_user()['id'], $message ?: null, $score]);
            flash('success', 'A candidatura foi submetida. Podes acompanhar o estado na tua conta.');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') flash('warning', 'Já te candidataste a esta vaga.');
            else { error_log('Candidatura: ' . $exception->getMessage()); flash('danger', 'Não foi possível submeter a candidatura.'); }
        }
        redirect_to('modules/emprego/index.php');
    } elseif ($action === 'update_application') {
        require_role(['employer','admin']);
        $applicationId = filter_var($_POST['application_id'] ?? null, FILTER_VALIDATE_INT);
        $newStatus = (string) ($_POST['status'] ?? '');
        if (!$applicationId || !in_array($newStatus, ['reviewing','shortlisted','rejected','accepted'], true)) {
            flash('danger', 'Actualização inválida.'); redirect_to('modules/emprego/index.php');
        }
        if (has_role('admin')) {
            $stmt = $pdo->prepare('UPDATE applications SET status = ? WHERE id = ?');
            $stmt->execute([$newStatus, $applicationId]);
        } else {
            $stmt = $pdo->prepare('UPDATE applications a INNER JOIN job_vacancies j ON j.id = a.vacancy_id INNER JOIN companies c ON c.id = j.company_id SET a.status = ? WHERE a.id = ? AND c.user_id = ?');
            $stmt->execute([$newStatus, $applicationId, current_user()['id']]);
        }
        $updated = $stmt->rowCount() > 0;
        flash($updated ? 'success' : 'warning', $updated ? 'Estado da candidatura actualizado.' : 'Não foi possível actualizar esta candidatura.');
        redirect_to('modules/emprego/index.php');
    }
}

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report') handle_report_submission($pdo, 'modules/emprego/index.php');
$jobs = $myApplications = [];
$search = trim((string) ($_GET['q'] ?? ''));
if ($pdo) {
    try {
        $sql = "SELECT j.*, c.company_name, u.full_name AS employer_name FROM job_vacancies j INNER JOIN companies c ON c.id = j.company_id INNER JOIN users u ON u.id = c.user_id WHERE j.status = 'published' AND (j.deadline IS NULL OR j.deadline >= CURRENT_DATE())";
        $params = [];
        if ($search !== '') { $sql .= ' AND (j.title LIKE ? OR j.description LIKE ? OR c.company_name LIKE ? OR j.location LIKE ?)'; $like = '%' . $search . '%'; $params = [$like, $like, $like, $like]; }
        $sql .= ' ORDER BY j.created_at DESC LIMIT 60';
        $stmt = $pdo->prepare($sql); $stmt->execute($params); $jobs = $stmt->fetchAll();
        if (current_user() && has_role(['employer','admin'])) {
            if (has_role('admin')) {
                $stmt = $pdo->query('SELECT a.id, a.status, a.created_at, a.cover_message, a.compatibility_score, u.full_name AS candidate_name, j.title FROM applications a INNER JOIN users u ON u.id = a.candidate_id INNER JOIN job_vacancies j ON j.id = a.vacancy_id ORDER BY a.created_at DESC LIMIT 30');
            } else {
                $stmt = $pdo->prepare('SELECT a.id, a.status, a.created_at, a.cover_message, a.compatibility_score, u.full_name AS candidate_name, j.title FROM applications a INNER JOIN users u ON u.id = a.candidate_id INNER JOIN job_vacancies j ON j.id = a.vacancy_id INNER JOIN companies c ON c.id = j.company_id WHERE c.user_id = ? ORDER BY a.created_at DESC LIMIT 30');
                $stmt->execute([current_user()['id']]);
            }
            $myApplications = $stmt->fetchAll();
        }
    } catch (Throwable $exception) { error_log('Listagem de vagas: ' . $exception->getMessage()); $errors[] = 'Não foi possível carregar as vagas da base de dados.'; }
}
$typeLabels = ['full_time'=>'Tempo inteiro','part_time'=>'Tempo parcial','temporary'=>'Temporário','internship'=>'Estágio'];
?>
<?php require __DIR__ . '/../../includes/header.php'; ?>
<main class="container section module-page">
  <div class="page-heading"><div><span class="eyebrow">01 / Carreira</span><h1>Emprego e recrutamento</h1><p class="muted">Pesquisa oportunidades profissionais no Cuito e acompanha as tuas candidaturas.</p></div><?php if (has_role(['employer','admin'])): ?><a class="btn btn-primary" href="#publicar-vaga">+ Publicar vaga</a><?php endif; ?></div>
  <?php if (!$pdo): ?><div class="flash flash-warning">A base de dados não está ligada. A página abre, mas as vagas e acções precisam de uma ligação MySQL válida.</div><?php endif; ?>
  <?php if ($errors): ?><div class="flash flash-danger"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="get" class="search-bar"><label class="sr-only" for="job-q">Pesquisar vagas</label><input id="job-q" name="q" type="search" value="<?= e($search) ?>" placeholder="Cargo, competência, empresa ou localização"><button class="btn btn-primary" type="submit">Pesquisar</button></form>
  <?php if (has_role(['employer','admin'])): ?><section class="content-panel form-panel module-form" id="publicar-vaga"><h2>Publicar uma vaga</h2><p class="muted">Indica requisitos claros e informações verdadeiras. A vaga será publicada após guardar.</p><form method="post" class="stack-form"><input type="hidden" name="action" value="create_job"><?= csrf_field() ?><div class="form-grid"><label>Nome da empresa/empregador<input name="company_name" required maxlength="180"></label><label>Título da vaga<input name="title" required minlength="4" maxlength="180"></label><label>Localização<input name="location" value="Cuito, Bié" required maxlength="120"></label><label>Tipo de emprego<select name="employment_type"><option value="full_time">Tempo inteiro</option><option value="part_time">Tempo parcial</option><option value="temporary">Temporário</option><option value="internship">Estágio</option></select></label><label>Prazo de candidatura<input type="date" name="deadline" min="<?= e(date('Y-m-d')) ?>"></label></div><label>Descrição e requisitos<textarea name="description" required minlength="20" maxlength="10000" rows="5" placeholder="Responsabilidades, requisitos, formação e modo de candidatura..."></textarea></label><button class="btn btn-primary" type="submit">Publicar vaga</button></form></section><?php elseif (current_user()): ?><div class="inline-notice">Queres publicar vagas? Cria uma conta de tipo Empresa/empregador ou activa esse perfil na gestão de conta.</div><?php endif; ?>
  <div class="section-heading module-section-heading"><div><span class="eyebrow">Oportunidades disponíveis</span><h2><?= count($jobs) ?> <?= count($jobs) === 1 ? 'vaga encontrada' : 'vagas encontradas' ?></h2></div></div>
  <?php if (!$jobs): ?><div class="empty-card"><span class="empty-icon">▣</span><h3>Não há vagas para mostrar</h3><p>Experimenta outra pesquisa ou regressa mais tarde. Empresas registadas podem publicar novas oportunidades.</p></div><?php else: ?><div class="listing-grid"><?php foreach ($jobs as $job): ?><article class="listing-card"><div class="listing-top"><span class="tag tag-violet"><?= e($typeLabels[$job['employment_type']] ?? 'Emprego') ?></span><span class="listing-date"><?= e(date('d/m/Y', strtotime($job['created_at']))) ?></span></div><h3><?= e($job['title']) ?></h3><p class="listing-subtitle"><?= e($job['company_name']) ?> · <?= e($job['location']) ?></p><p class="listing-description"><?= nl2br(e(mb_substr_safe($job['description'], 0, 260))) ?><?= strlen($job['description']) > 260 ? '…' : '' ?></p><?php if ($job['deadline']): ?><p class="deadline">Candidaturas até <?= e(date('d/m/Y', strtotime($job['deadline']))) ?></p><?php endif; ?><div class="listing-actions"><?php if (current_user() && has_role(['candidate','admin'])): ?><form method="post" class="apply-form"><?= csrf_field() ?><input type="hidden" name="action" value="apply_job"><input type="hidden" name="vacancy_id" value="<?= (int) $job['id'] ?>"><textarea name="cover_message" rows="2" maxlength="3000" placeholder="Mensagem de apresentação (opcional)"></textarea><button class="btn btn-primary btn-small" type="submit">Candidatar-me</button></form><?php elseif (!current_user()): ?><a class="btn btn-primary btn-small" href="../../auth/entrar.php">Entrar para candidatar</a><?php else: ?><span class="form-note">A tua função actual não inclui candidaturas a vagas.</span><?php endif; ?></div><?= listing_report_form('job', (int) $job['id']) ?></article><?php endforeach; ?></div><?php endif; ?>
  <?php if (has_role(['employer','admin'])): ?><section class="content-panel employer-applications"><div class="panel-heading"><h2>Candidaturas recebidas</h2></div><?php if (!$myApplications): ?><p class="empty-state">Ainda não recebeste candidaturas para as tuas vagas.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Candidato</th><th>Vaga</th><th>Compatibilidade</th><th>Estado</th><th>Actualizar</th></tr></thead><tbody><?php foreach ($myApplications as $app): ?><tr><td><?= e($app['candidate_name']) ?><small><?= e($app['cover_message']) ?></small></td><td><?= e($app['title']) ?></td><td><?= $app['compatibility_score'] === null ? 'Sem dados de perfil' : e((int)$app['compatibility_score']) . '%' ?></td><td><?= e($app['status']) ?></td><td><form method="post" class="table-action-form"><?= csrf_field() ?><input type="hidden" name="action" value="update_application"><input type="hidden" name="application_id" value="<?= (int)$app['id'] ?>"><select name="status"><option value="reviewing">Em análise</option><option value="shortlisted">Pré-seleccionado</option><option value="accepted">Aceite</option><option value="rejected">Rejeitada</option></select><button class="btn btn-small btn-outline">Guardar</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
<?php function mb_substr_safe(string $value, int $start, int $length): string { return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length); } ?>
