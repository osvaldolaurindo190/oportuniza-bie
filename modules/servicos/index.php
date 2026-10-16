<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
$pageTitle = 'Prestação de serviços | Oportuniza Bié';
$assetPrefix = '../../';
$pdo = db_or_null(); $errors = []; $services = [];
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'report') {
    if (!csrf_is_valid()) { flash('danger','O formulário expirou. Actualiza a página e tenta novamente.'); redirect_to('modules/servicos/index.php'); }
    if (($_POST['action'] ?? '') === 'create_service') {
        require_role(['service_provider','admin']);
        $title = trim((string)($_POST['title'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $priceRaw = trim((string)($_POST['price_from'] ?? ''));
        $price = $priceRaw === '' ? null : filter_var($priceRaw, FILTER_VALIDATE_FLOAT);
        $location = trim((string)($_POST['location'] ?? 'Cuito, Bié'));
        $phone = trim((string)($_POST['contact_phone'] ?? current_user()['phone'] ?? ''));
        if (strlen($title) < 4 || strlen($title) > 180) $errors[] = 'O título deve conter entre 4 e 180 caracteres.';
        if ($category === '' || strlen($category) > 100) $errors[] = 'Indica uma categoria válida.';
        if (strlen($description) < 20 || strlen($description) > 8000) $errors[] = 'A descrição deve conter entre 20 e 8.000 caracteres.';
        if ($priceRaw !== '' && ($price === false || $price < 0 || $price > 1000000000)) $errors[] = 'O preço inicial não é válido.';
        if ($location === '' || strlen($location) > 120) $errors[] = 'Indica uma localização válida.';
        if ($phone !== '' && !preg_match('/^[0-9+()\s.-]{7,30}$/', $phone)) $errors[] = 'Confirma o número de contacto.';
        if (!$errors) {
            try {
                $stmt = $pdo->prepare("INSERT INTO service_listings (user_id, title, category, description, price_from, location, contact_phone, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'published')");
                $stmt->execute([current_user()['id'], $title, $category, $description, $price, $location, $phone ?: null]);
                $id = (int)$pdo->lastInsertId(); write_audit_log($pdo, (int)current_user()['id'], 'service_created', 'service', $id);
                flash('success','O serviço foi publicado.'); redirect_to('modules/servicos/index.php');
            } catch (Throwable $exception) {
                error_log('Publicação de serviço: ' . $exception->getMessage());
                $errors[] = 'Não foi possível publicar o serviço. Tenta novamente.';
            }
        }
    }
}
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report') handle_report_submission($pdo, 'modules/servicos/index.php');
$search = trim((string)($_GET['q'] ?? ''));
if ($pdo) {
    try {
        $sql = "SELECT s.*, u.full_name, (SELECT ROUND(AVG(r.rating),1) FROM reviews r WHERE r.target_user_id=s.user_id) AS rating_average, (SELECT COUNT(*) FROM reviews r WHERE r.target_user_id=s.user_id) AS rating_count FROM service_listings s INNER JOIN users u ON u.id=s.user_id WHERE s.status='published'";
        $params=[];
        if ($search !== '') { $sql .= ' AND (s.title LIKE ? OR s.category LIKE ? OR s.description LIKE ? OR s.location LIKE ?)'; $like='%'.$search.'%'; $params=[$like,$like,$like,$like]; }
        $sql .= ' ORDER BY s.created_at DESC LIMIT 60';
        $stmt=$pdo->prepare($sql); $stmt->execute($params); $services=$stmt->fetchAll();
    } catch (Throwable $exception) { error_log('Listagem de serviços: '.$exception->getMessage()); $errors[]='Não foi possível carregar os serviços.'; }
}
?>
<?php require __DIR__ . '/../../includes/header.php'; ?>
<main class="container section module-page">
  <div class="page-heading"><div><span class="eyebrow">03 / Competências</span><h1>Prestação de serviços</h1><p class="muted">Descobre profissionais locais ou divulga as tuas competências e serviços.</p></div><?php if (has_role(['service_provider','admin'])): ?><a class="btn btn-primary" href="#publicar-servico">+ Publicar serviço</a><?php endif; ?></div>
  <?php if (!$pdo): ?><div class="flash flash-warning">A base de dados não está ligada. Configura o MySQL para consultar ou publicar serviços.</div><?php endif; ?>
  <?php if ($errors): ?><div class="flash flash-danger"><ul><?php foreach($errors as $error): ?><li><?=e($error)?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="get" class="search-bar"><label class="sr-only" for="service-q">Pesquisar serviços</label><input id="service-q" type="search" name="q" value="<?=e($search)?>" placeholder="Ex.: informática, electricidade, explicações"><button class="btn btn-primary">Pesquisar</button></form>
  <?php if (has_role(['service_provider','admin'])): ?><section class="content-panel form-panel module-form" id="publicar-servico"><h2>Divulgar um serviço</h2><form method="post" class="stack-form"><?=csrf_field()?><input type="hidden" name="action" value="create_service"><div class="form-grid"><label>Nome do serviço<input name="title" required minlength="4" maxlength="180" placeholder="Ex.: Reparação de computadores"></label><label>Categoria<select name="category" required><option value="">Selecciona</option><option>Informática e tecnologia</option><option>Construção e reparações</option><option>Educação e explicações</option><option>Design e comunicação</option><option>Transporte e entregas</option><option>Serviços domésticos</option><option>Beleza e bem-estar</option><option>Outro</option></select></label><label>Preço inicial (Kz, opcional)<input type="number" name="price_from" min="0" step="0.01"></label><label>Localização<input name="location" value="Cuito, Bié" required maxlength="120"></label><label>Telefone de contacto (opcional)<input type="tel" name="contact_phone" value="" maxlength="30" placeholder="923 000 000"><small>Se preencheres este campo, o número ficará visível publicamente neste anúncio.</small></label></div><label>Descrição<textarea name="description" required minlength="20" maxlength="8000" rows="4" placeholder="Descreve o serviço, experiência e condições..."></textarea></label><button class="btn btn-primary">Publicar serviço</button></form></section><?php elseif (current_user()): ?><div class="inline-notice">Para divulgar serviços, activa o perfil de Prestador de serviços na tua conta.</div><?php endif; ?>
  <div class="section-heading module-section-heading"><div><span class="eyebrow">Serviços anunciados</span><h2><?=count($services)?> <?=count($services)===1?'serviço encontrado':'serviços encontrados'?></h2></div></div>
  <?php if (!$services): ?><div class="empty-card"><span class="empty-icon">⌂</span><h3>Ainda não há serviços publicados</h3><p>Quando os prestadores anunciarem os seus serviços, estes serão apresentados nesta área.</p></div><?php else: ?><div class="listing-grid"><?php foreach($services as $service): ?><article class="listing-card"><div class="listing-top"><span class="tag tag-green"><?=e($service['category'])?></span><span class="listing-date"><?=e(date('d/m/Y',strtotime($service['created_at'])))?></span></div><h3><?=e($service['title'])?></h3><p class="listing-subtitle"><?=e($service['full_name'])?> · <?=e($service['location'])?></p><?php if ((int)$service['rating_count'] > 0): ?><p class="rating-line">★ <?=e($service['rating_average'])?> <span>(<?=e($service['rating_count'])?> avaliação(ões))</span></p><?php else: ?><p class="rating-line muted">Ainda sem avaliações</p><?php endif; ?><p class="listing-description"><?=nl2br(e(safe_cut_service($service['description'],260)))?><?=strlen($service['description'])>260?'…':''?></p><?php if($service['price_from']!==null):?><div class="price-line">Desde <?=e(format_aoa($service['price_from']))?></div><?php endif;?><?php if($service['contact_phone']):?><a class="btn btn-primary btn-small contact-button" href="tel:<?=e(preg_replace('/[^0-9+]/','',$service['contact_phone']))?>">Contactar prestador</a><?php else:?><p class="form-note">Contacta através da plataforma quando esse canal estiver disponível.</p><?php endif;?><?=listing_report_form('service',(int)$service['id'])?></article><?php endforeach;?></div><?php endif;?>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
<?php function safe_cut_service(string $value,int $length):string{return function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length);}?>
