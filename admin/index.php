<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$pageTitle = 'Administração | Oportuniza Bié';
$assetPrefix = '../';
if (!current_user()) { flash('warning','Inicia sessão com uma conta administrativa.'); redirect_to('auth/entrar.php'); }
if (!has_role('admin')) { http_response_code(403); require __DIR__ . '/../includes/header.php'; echo '<main class="container section"><div class="empty-card"><h1>Acesso restrito</h1><p>Esta área está disponível apenas para utilizadores com função de administrador.</p><a class="btn btn-primary" href="../conta.php">Voltar à conta</a></div></main>'; require __DIR__ . '/../includes/footer.php'; exit; }
$pdo = db_or_null(); $errors=[]; $metrics=['users'=>0,'jobs'=>0,'tasks'=>0,'products'=>0,'reports'=>0]; $reports=[]; $users=[];
if ($pdo && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrf_is_valid()) { flash('danger','O formulário expirou.'); redirect_to('admin/index.php'); }
    $action=(string)($_POST['action']??'');
    if ($action==='resolve_report') {
        $reportId=filter_var($_POST['report_id']??null,FILTER_VALIDATE_INT);$status=(string)($_POST['status']??'');
        if($reportId && in_array($status,['reviewing','resolved','dismissed'],true)){$stmt=$pdo->prepare('UPDATE reports SET status=? WHERE id=?');$stmt->execute([$status,$reportId]);write_audit_log($pdo,(int)current_user()['id'],'report_status_changed','report',(int)$reportId);flash('success','Estado da denúncia actualizado.');}else flash('danger','Dados da denúncia inválidos.');
        redirect_to('admin/index.php#denuncias');
    } elseif ($action==='moderate_listing') {
        $entity=(string)($_POST['entity_type']??'');$id=filter_var($_POST['entity_id']??null,FILTER_VALIDATE_INT);$status=(string)($_POST['status']??'');
        $map=['job'=>['job_vacancies',['published','paused','closed']], 'task'=>['tasks',['published','cancelled','completed']], 'service'=>['service_listings',['published','paused','closed']], 'product'=>['products',['published','unavailable','archived']]];
        if($id && isset($map[$entity]) && in_array($status,$map[$entity][1],true)){$table=$map[$entity][0];$stmt=$pdo->prepare("UPDATE {$table} SET status=? WHERE id=?");$stmt->execute([$status,$id]);write_audit_log($pdo,(int)current_user()['id'],'listing_moderated',$entity,(int)$id);flash('success','Anúncio actualizado.');}else flash('danger','Pedido de moderação inválido.');
        redirect_to('admin/index.php#anuncios');
    }
}
if($pdo){try{
    $metrics['users']=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $metrics['jobs']=(int)$pdo->query('SELECT COUNT(*) FROM job_vacancies')->fetchColumn();
    $metrics['tasks']=(int)$pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
    $metrics['products']=(int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $metrics['reports']=(int)$pdo->query("SELECT COUNT(*) FROM reports WHERE status IN ('open','reviewing')")->fetchColumn();
    $reports=$pdo->query('SELECT r.*,u.full_name AS reporter_name FROM reports r INNER JOIN users u ON u.id=r.reporter_id ORDER BY FIELD(r.status,\'open\',\'reviewing\',\'resolved\',\'dismissed\'),r.created_at DESC LIMIT 50')->fetchAll();
    $users=$pdo->query('SELECT id,full_name,email,account_status,created_at FROM users ORDER BY created_at DESC LIMIT 20')->fetchAll();
}catch(Throwable $exception){error_log('Painel administrativo: '.$exception->getMessage());$errors[]='Não foi possível carregar os indicadores da administração.';}}
?>
<?php require __DIR__ . '/../includes/header.php'; ?>
<main class="container section module-page">
  <div class="page-heading"><div><span class="eyebrow">Área restrita</span><h1>Administração</h1><p class="muted">Acompanha contas, denúncias e estado dos anúncios da plataforma.</p></div><span class="role-chip">Administrador</span></div>
  <?php if(!$pdo):?><div class="flash flash-warning">A base de dados não está ligada; o painel não pode apresentar dados.</div><?php endif;?>
  <?php if($errors):?><div class="flash flash-danger"><?php foreach($errors as $error):?><p><?=e($error)?></p><?php endforeach;?></div><?php endif;?>
  <div class="admin-metrics"><article><span>Utilizadores</span><strong><?=$metrics['users']?></strong></article><article><span>Vagas</span><strong><?=$metrics['jobs']?></strong></article><article><span>Microtarefas</span><strong><?=$metrics['tasks']?></strong></article><article><span>Produtos</span><strong><?=$metrics['products']?></strong></article><article><span>Denúncias por analisar</span><strong><?=$metrics['reports']?></strong></article></div>
  <section class="content-panel" id="denuncias"><div class="panel-heading"><h2>Denúncias</h2></div><?php if(!$reports):?><p class="empty-state">Não existem denúncias registadas.</p><?php else:?><div class="table-wrap"><table><thead><tr><th>Data</th><th>Reportado</th><th>Motivo</th><th>Utilizador</th><th>Estado</th><th>Acção</th></tr></thead><tbody><?php foreach($reports as $report):?><tr><td><?=e(date('d/m/Y H:i',strtotime($report['created_at'])))?></td><td><?=e($report['reported_type'])?> #<?=(int)$report['reported_id']?></td><td><?=e($report['reason'])?><small><?=e($report['details'])?></small></td><td><?=e($report['reporter_name'])?></td><td><?=e($report['status'])?></td><td><form method="post" class="table-action-form"><?=csrf_field()?><input type="hidden" name="action" value="resolve_report"><input type="hidden" name="report_id" value="<?=(int)$report['id']?>"><select name="status"><option value="reviewing">Em análise</option><option value="resolved">Resolvida</option><option value="dismissed">Arquivar</option></select><button class="btn btn-small btn-outline">Guardar</button></form></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
  <section class="content-panel" id="anuncios"><div class="panel-heading"><h2>Moderação rápida de anúncios</h2></div><p class="muted">Introduz o tipo de anúncio e o respectivo ID para alterar o estado. Os IDs encontram-se nas tabelas da base de dados.</p><form method="post" class="stack-form admin-moderation-form"><?=csrf_field()?><input type="hidden" name="action" value="moderate_listing"><div class="form-grid"><label>Tipo<select name="entity_type"><option value="job">Vaga de emprego</option><option value="task">Microtarefa</option><option value="service">Serviço</option><option value="product">Produto</option></select></label><label>ID do anúncio<input name="entity_id" type="number" min="1" required></label><label>Novo estado<select name="status"><option value="published" data-for="job,task,service,product">Publicado</option><option value="paused" data-for="job,service">Pausado</option><option value="closed" data-for="job,service">Encerrado</option><option value="cancelled" data-for="task">Cancelado</option><option value="completed" data-for="task">Concluído</option><option value="unavailable" data-for="product">Indisponível</option><option value="archived" data-for="product">Arquivado</option></select></label></div><button class="btn btn-primary">Actualizar anúncio</button><p class="form-note">A plataforma valida o estado consoante o tipo de anúncio. Um estado incompatível será rejeitado pela base de dados.</p></form></section>
  <section class="content-panel"><div class="panel-heading"><h2>Contas recentes</h2></div><?php if(!$users):?><p class="empty-state">Ainda não existem contas.</p><?php else:?><div class="table-wrap"><table><thead><tr><th>Nome</th><th>E-mail</th><th>Estado</th><th>Registo</th></tr></thead><tbody><?php foreach($users as $item):?><tr><td><?=e($item['full_name'])?></td><td><?=e($item['email'])?></td><td><?=e($item['account_status'])?></td><td><?=e(date('d/m/Y',strtotime($item['created_at'])))?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
