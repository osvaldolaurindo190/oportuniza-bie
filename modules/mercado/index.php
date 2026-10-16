<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
$pageTitle = 'Mercado digital | Oportuniza Bié';
$assetPrefix = '../../';
$pdo = db_or_null(); $errors = []; $products = []; $cartProducts = [];
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'report') {
    if (!csrf_is_valid()) { flash('danger','O formulário expirou. Actualiza a página e tenta novamente.'); redirect_to('modules/mercado/index.php'); }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'add_cart') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($_POST['quantity'] ?? 1, FILTER_VALIDATE_INT);
        if (!$productId || $productId < 1 || !$quantity || $quantity < 1 || $quantity > 20) { flash('danger','Indica uma quantidade entre 1 e 20.'); redirect_to('modules/mercado/index.php'); }
        $stmt = $pdo->prepare("SELECT p.id, p.stock_quantity, s.owner_id FROM products p INNER JOIN stores s ON s.id=p.store_id WHERE p.id=? AND p.status='published' AND s.status='active'");
        $stmt->execute([$productId]); $product = $stmt->fetch();
        if (!$product || (int)$product['stock_quantity'] < 1) { flash('warning','Este produto está indisponível.'); redirect_to('modules/mercado/index.php'); }
        if (current_user() && (int)$product['owner_id'] === (int)current_user()['id']) { flash('warning','Não podes enviar um pedido de compra da tua própria loja.'); redirect_to('modules/mercado/index.php'); }
        $currentQty = (int)($_SESSION['cart'][(string)$productId] ?? 0);
        $_SESSION['cart'][(string)$productId] = min(20, $currentQty + $quantity, (int)$product['stock_quantity']);
        flash('success','Produto adicionado ao carrinho.'); redirect_to('modules/mercado/index.php#carrinho');
    } elseif ($action === 'update_cart') {
        $submittedQuantities = $_POST['quantities'] ?? [];
        if (!is_array($submittedQuantities)) $submittedQuantities = [];
        foreach ($submittedQuantities as $productId => $quantityRaw) {
            $id = filter_var((string)$productId, FILTER_VALIDATE_INT);
            $quantity = filter_var($quantityRaw, FILTER_VALIDATE_INT);
            if (!$id || $id < 1 || !array_key_exists((string)$id, $_SESSION['cart'])) continue;
            if ($quantity === false || $quantity <= 0) { unset($_SESSION['cart'][(string)$id]); continue; }
            $stockCheck = $pdo->prepare("SELECT stock_quantity FROM products WHERE id = ? AND status = 'published'");
            $stockCheck->execute([$id]);
            $availableStock = $stockCheck->fetchColumn();
            if ($availableStock === false || (int)$availableStock < 1) {
                unset($_SESSION['cart'][(string)$id]);
                continue;
            }
            $_SESSION['cart'][(string)$id] = min(20, (int)$quantity, (int)$availableStock);
        }
        flash('success','Carrinho actualizado.'); redirect_to('modules/mercado/index.php#carrinho');
    } elseif ($action === 'remove_cart') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        if ($productId) unset($_SESSION['cart'][(string)$productId]);
        flash('success','Produto removido do carrinho.'); redirect_to('modules/mercado/index.php#carrinho');
    } elseif ($action === 'create_product') {
        require_role(['seller','admin']);
        $storeName = trim((string)($_POST['store_name'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $stock = filter_var($_POST['stock_quantity'] ?? null, FILTER_VALIDATE_INT);
        $upload = $_FILES['image'] ?? null;
        $imageInfo = null;
        if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $errors[] = 'Não foi possível receber a imagem. Tenta uma imagem mais pequena.';
            } elseif (($upload['size'] ?? 0) > 2 * 1024 * 1024) {
                $errors[] = 'A imagem não pode ultrapassar 2 MB.';
            } else {
                $tmpName = (string)($upload['tmp_name'] ?? '');
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $tmpName !== '' ? $finfo->file($tmpName) : false;
                $imageSize = $tmpName !== '' ? @getimagesize($tmpName) : false;
                $allowedImages = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                if (!is_uploaded_file($tmpName) || !is_string($mime) || !isset($allowedImages[$mime]) || $imageSize === false) {
                    $errors[] = 'Formato de imagem inválido. Utiliza JPG, PNG ou WEBP.';
                } elseif (($imageSize[0] ?? 0) > 6000 || ($imageSize[1] ?? 0) > 6000) {
                    $errors[] = 'A imagem não pode ultrapassar 6.000 píxeis de largura ou altura.';
                } else {
                    $imageInfo = ['tmp_name'=>$tmpName,'mime'=>$mime,'extension'=>$allowedImages[$mime],'size'=>(int)$upload['size']];
                }
            }
        }
        if (strlen($storeName) < 2 || strlen($storeName) > 160) $errors[]='O nome da loja deve conter entre 2 e 160 caracteres.';
        if (strlen($title) < 2 || strlen($title) > 180) $errors[]='O nome do produto deve conter entre 2 e 180 caracteres.';
        if (strlen($description) > 5000) $errors[]='A descrição não pode ultrapassar 5.000 caracteres.';
        if ($price === false || $price <= 0 || $price > 1000000000) $errors[]='Indica um preço válido.';
        if ($stock === false || $stock < 1 || $stock > 10000000) $errors[]='Indica uma quantidade de stock entre 1 e 10.000.000.';
        if (!$errors) {
            $savedImagePath = null;
            try {
                $pdo->beginTransaction();
                $stmt=$pdo->prepare("INSERT INTO stores (owner_id,name,status) VALUES (?,?,'active') ON DUPLICATE KEY UPDATE name=VALUES(name), status='active'");
                $stmt->execute([current_user()['id'],$storeName]);
                $stmt=$pdo->prepare('SELECT id FROM stores WHERE owner_id=?'); $stmt->execute([current_user()['id']]); $storeId=(int)$stmt->fetchColumn();
                $stmt=$pdo->prepare("INSERT INTO products (store_id,title,description,price,stock_quantity,status) VALUES (?,?,?,?,?,'published')");
                $stmt->execute([$storeId,$title,$description ?: null,$price,$stock]); $productId=(int)$pdo->lastInsertId();
                if ($imageInfo !== null) {
                    $uploadDirectory = dirname(__DIR__, 2) . '/uploads/products';
                    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) throw new RuntimeException('Não foi possível preparar a pasta de imagens.');
                    $fileName = bin2hex(random_bytes(16)) . '.' . $imageInfo['extension'];
                    $savedImagePath = $uploadDirectory . '/' . $fileName;
                    if (!move_uploaded_file($imageInfo['tmp_name'], $savedImagePath)) throw new RuntimeException('Não foi possível guardar a imagem do produto.');
                    $imageInsert = $pdo->prepare('INSERT INTO product_images (product_id,file_name,mime_type,file_size) VALUES (?,?,?,?)');
                    $imageInsert->execute([$productId,$fileName,$imageInfo['mime'],$imageInfo['size']]);
                }
                $pdo->commit(); write_audit_log($pdo,(int)current_user()['id'],'product_created','product',$productId);
                flash('success','Produto publicado no mercado digital.'); redirect_to('modules/mercado/index.php');
            } catch(Throwable $exception) { if($pdo->inTransaction())$pdo->rollBack(); if(isset($savedImagePath) && is_string($savedImagePath) && is_file($savedImagePath)) @unlink($savedImagePath); error_log('Publicação de produto: '.$exception->getMessage()); $errors[]='Não foi possível publicar o produto. Confirma as permissões de gravação de imagens.'; }
        }
    } elseif ($action === 'checkout') {
        require_login();
        $cart = $_SESSION['cart'] ?? [];
        $buyerNote = trim((string)($_POST['notes'] ?? ''));
        if (function_exists('mb_substr')) $buyerNote = mb_substr($buyerNote, 0, 1000, 'UTF-8');
        elseif (strlen($buyerNote) > 1000) $buyerNote = substr($buyerNote, 0, 1000);
        $shareContact = isset($_POST['share_contact']) && (string)$_POST['share_contact'] === '1' ? 1 : 0;
        if (!$cart) { flash('warning','O carrinho está vazio.'); redirect_to('modules/mercado/index.php#carrinho'); }
        try {
            $pdo->beginTransaction();
            $buyerRole = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE role_key='buyer'");
            $buyerRole->execute([current_user()['id']]);
            $validated=[]; $totalCents=0;
            $productStmt=$pdo->prepare("SELECT p.id,p.title,p.price,p.stock_quantity,p.status,s.owner_id,s.status AS store_status FROM products p INNER JOIN stores s ON s.id=p.store_id WHERE p.id=? FOR UPDATE");
            foreach ($cart as $productIdRaw=>$quantityRaw) {
                $productId=(int)$productIdRaw; $quantity=(int)$quantityRaw;
                if ($productId < 1 || $quantity < 1 || $quantity > 20) throw new RuntimeException('O carrinho contém uma quantidade inválida.');
                $productStmt->execute([$productId]); $product=$productStmt->fetch();
                if (!$product || $product['status']!=='published' || $product['store_status']!=='active') throw new RuntimeException('Um dos produtos já não está disponível. Actualiza o carrinho.');
                if ((int)$product['owner_id'] === (int)current_user()['id']) throw new RuntimeException('Não podes enviar um pedido de compra de um produto da tua própria loja.');
                if ((int)$product['stock_quantity'] < $quantity) throw new RuntimeException('O stock de “'.$product['title'].'” é insuficiente para a quantidade pedida.');
                $product['quantity']=$quantity; $validated[]=$product; $totalCents += (int)round(((float)$product['price']) * 100) * $quantity;
            }
            if (!$validated || $totalCents <= 0) throw new RuntimeException('O carrinho não contém produtos válidos.');
            if ($totalCents > 999999999999) throw new RuntimeException('O total do pedido ultrapassa o limite permitido. Divide a compra em pedidos menores.');
            $totalAmount = number_format($totalCents / 100, 2, '.', '');
            $reference='OB-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(6)));
            $note='Pedido registado para contacto. Pagamento electrónico não está configurado; não foi cobrado qualquer valor.';
            if ($buyerNote !== '') $note .= ' Nota do comprador: ' . $buyerNote;
            $stmt=$pdo->prepare("INSERT INTO orders (buyer_id,order_reference,total_amount,status,notes,share_contact) VALUES (?,?,?,'pending_payment',?,?)");
            $stmt->execute([current_user()['id'],$reference,$totalAmount,$note,$shareContact]); $orderId=(int)$pdo->lastInsertId();
            $itemStmt=$pdo->prepare('INSERT INTO order_items (order_id,product_id,seller_id,quantity,unit_price) VALUES (?,?,?,?,?)');
            $notificationStmt=$pdo->prepare('INSERT INTO notifications (user_id,title,message) VALUES (?,?,?)');
            $notified=[];
            foreach($validated as $item) {
                $itemStmt->execute([$orderId,$item['id'],$item['owner_id'],$item['quantity'],$item['price']]);
                if (!isset($notified[(int)$item['owner_id']])) {
                    $notificationStmt->execute([(int)$item['owner_id'],'Novo pedido recebido','Foi criado o pedido '.$reference.'. Contacta o comprador para combinar pagamento e entrega. Nenhum pagamento foi processado.']);
                    $notified[(int)$item['owner_id']]=true;
                }
            }
            $pdo->commit();
            if (!in_array('buyer', $_SESSION['user_roles'] ?? [], true)) $_SESSION['user_roles'][] = 'buyer';
            $_SESSION['cart']=[];
            flash('success','Pedido '.$reference.' registado. Não foi realizado qualquer pagamento; os vendedores deverão confirmar a disponibilidade e combinar as condições contigo.');
            redirect_to('modules/mercado/index.php');
        } catch(Throwable $exception) {
            if($pdo->inTransaction())$pdo->rollBack();
            error_log('Checkout: '.$exception->getMessage());
            flash('danger',$exception instanceof RuntimeException ? $exception->getMessage() : 'Não foi possível registar o pedido. Tenta novamente.');
            redirect_to('modules/mercado/index.php#carrinho');
        }
    }
}
if ($pdo && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='report') handle_report_submission($pdo, 'modules/mercado/index.php');
$search=trim((string)($_GET['q']??''));
if ($pdo) {
    try {
        $sql="SELECT p.*,s.name AS store_name,s.owner_id,u.full_name AS seller_name, (SELECT pi.file_name FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.id ASC LIMIT 1) AS image_file, (SELECT ROUND(AVG(r.rating),1) FROM reviews r WHERE r.target_user_id=s.owner_id) AS rating_average, (SELECT COUNT(*) FROM reviews r WHERE r.target_user_id=s.owner_id) AS rating_count FROM products p INNER JOIN stores s ON s.id=p.store_id INNER JOIN users u ON u.id=s.owner_id WHERE p.status='published' AND s.status='active' AND p.stock_quantity>0";
        $params=[];
        if($search!==''){$sql.=' AND (p.title LIKE ? OR p.description LIKE ? OR s.name LIKE ?)';$like='%'.$search.'%';$params=[$like,$like,$like];}
        $sql.=' ORDER BY p.created_at DESC LIMIT 60';$stmt=$pdo->prepare($sql);$stmt->execute($params);$products=$stmt->fetchAll();
        $cart=$_SESSION['cart']??[];
        if($cart){$ids=array_map('intval',array_keys($cart));$placeholders=implode(',',array_fill(0,count($ids),'?'));$stmt=$pdo->prepare("SELECT p.id,p.title,p.price,p.stock_quantity,s.name AS store_name FROM products p INNER JOIN stores s ON s.id=p.store_id WHERE p.id IN ($placeholders) AND p.status='published'");$stmt->execute($ids);$cartProducts=$stmt->fetchAll();}
    } catch(Throwable $exception){error_log('Mercado digital: '.$exception->getMessage());$errors[]='Não foi possível carregar os produtos do mercado.';}
}
?>
<?php require __DIR__ . '/../../includes/header.php'; ?>
<main class="container section module-page">
  <div class="page-heading"><div><span class="eyebrow">04 / Comércio</span><h1>Mercado digital</h1><p class="muted">Consulta produtos e lojas locais. O carrinho permite enviar um pedido de contacto ao vendedor.</p></div><?php if(has_role(['seller','admin'])):?><a class="btn btn-primary" href="#publicar-produto">+ Publicar produto</a><?php endif;?></div>
  <div class="inline-notice"><strong>Nota sobre pagamentos:</strong> o pagamento electrónico ainda não está integrado. Um pedido não significa pagamento confirmado e nenhum valor será cobrado por esta versão.</div>
  <?php if(!$pdo):?><div class="flash flash-warning">A base de dados não está ligada. Configura o MySQL para publicar ou consultar produtos.</div><?php endif;?>
  <?php if($errors):?><div class="flash flash-danger"><ul><?php foreach($errors as $error):?><li><?=e($error)?></li><?php endforeach;?></ul></div><?php endif;?>
  <form method="get" class="search-bar"><label class="sr-only" for="market-q">Pesquisar produtos</label><input id="market-q" type="search" name="q" value="<?=e($search)?>" placeholder="Pesquisar produto ou loja"><button class="btn btn-primary">Pesquisar</button></form>
  <?php if(has_role(['seller','admin'])):?><section class="content-panel form-panel module-form" id="publicar-produto"><h2>Publicar produto</h2><form method="post" enctype="multipart/form-data" class="stack-form"><?=csrf_field()?><input type="hidden" name="action" value="create_product"><div class="form-grid"><label>Nome da loja<input name="store_name" required minlength="2" maxlength="160"></label><label>Nome do produto<input name="title" required minlength="2" maxlength="180"></label><label>Preço (Kz)<input name="price" type="number" min="0.01" step="0.01" required></label><label>Quantidade em stock<input name="stock_quantity" type="number" min="1" max="10000000" step="1" required value="1"></label></div><label>Fotografia do produto (opcional)<input type="file" name="image" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG ou WEBP; máximo 2 MB e 6.000 × 6.000 píxeis.</small></label><label>Descrição (opcional)<textarea name="description" rows="3" maxlength="5000"></textarea></label><button class="btn btn-primary" type="submit">Publicar produto</button></form></section><?php elseif(current_user()):?><div class="inline-notice">Para publicar produtos, activa o perfil de Vendedor na tua conta.</div><?php endif;?>
  <div class="section-heading module-section-heading"><div><span class="eyebrow">Produtos disponíveis</span><h2><?=count($products)?> <?=count($products)===1?'produto encontrado':'produtos encontrados'?></h2></div></div>
  <?php if(!$products):?><div class="empty-card"><span class="empty-icon">◇</span><h3>Ainda não há produtos no mercado</h3><p>Quando os vendedores publicarem produtos, estes serão apresentados aqui.</p></div><?php else:?><div class="listing-grid product-grid"><?php foreach($products as $product):?><article class="listing-card product-card"><?php if (!empty($product['image_file'])): ?><img class="product-image" src="../../uploads/products/<?=e($product['image_file'])?>" alt="Fotografia de <?=e($product['title'])?>" loading="lazy" width="640" height="360"><?php else: ?><div class="product-placeholder" aria-hidden="true">◇</div><?php endif;?><div class="listing-top"><span class="tag tag-blue"><?=e($product['store_name'])?></span><span class="listing-date">Stock: <?=e($product['stock_quantity'])?></span></div><h3><?=e($product['title'])?></h3><p class="listing-subtitle">Vendedor: <?=e($product['seller_name'])?></p><?php if ((int)$product['rating_count'] > 0): ?><p class="rating-line">★ <?=e($product['rating_average'])?> <span>(<?=e($product['rating_count'])?> avaliação(ões))</span></p><?php else: ?><p class="rating-line muted">Vendedor sem avaliações</p><?php endif; ?><p class="listing-description"><?=e(safe_market_cut((string)($product['description']??''),180))?></p><div class="price-line"><?=e(format_aoa($product['price']))?></div><form method="post" class="cart-add-form"><?=csrf_field()?><input type="hidden" name="action" value="add_cart"><input type="hidden" name="product_id" value="<?=(int)$product['id']?>"><label>Quantidade<input name="quantity" type="number" min="1" max="20" value="1" required></label><button class="btn btn-primary btn-small">Adicionar ao carrinho</button></form><?=listing_report_form('product',(int)$product['id'])?></article><?php endforeach;?></div><?php endif;?>
  <section class="content-panel cart-panel" id="carrinho"><div class="panel-heading"><div><span class="eyebrow">O teu pedido</span><h2>Carrinho</h2></div><span class="status-chip"><?=count($_SESSION['cart']??[])?> artigo(s)</span></div>
    <?php if(!$cartProducts):?><p class="empty-state">O carrinho está vazio. Adiciona um produto para preparar um pedido.</p><?php else:?><form method="post" class="cart-table-form"><?=csrf_field()?><input type="hidden" name="action" value="update_cart"><div class="table-wrap"><table><thead><tr><th>Produto</th><th>Preço unitário</th><th>Quantidade</th><th>Subtotal</th><th></th></tr></thead><tbody><?php $cartTotal=0;foreach($cartProducts as $item):$qty=(int)($_SESSION['cart'][(string)$item['id']]??1);$subtotal=(float)$item['price']*$qty;$cartTotal+=$subtotal;?><tr><td><?=e($item['title'])?><small><?=e($item['store_name'])?></small></td><td><?=e(format_aoa($item['price']))?></td><td><input class="quantity-input" type="number" min="0" max="20" name="quantities[<?=(int)$item['id']?>]" value="<?=$qty?>" aria-label="Quantidade de <?=e($item['title'])?>"></td><td><?=e(format_aoa($subtotal))?></td><td><button class="text-button danger-text" type="submit" form="remove-<?= (int)$item['id'] ?>">Remover</button></td></tr><?php endforeach;?></tbody></table></div><div class="cart-summary"><span>Total estimado</span><strong><?=e(format_aoa($cartTotal))?></strong></div><div class="cart-actions"><button class="btn btn-outline" type="submit">Actualizar carrinho</button></div></form><?php foreach($cartProducts as $item):?><form id="remove-<?=(int)$item['id']?>" method="post" class="hidden-form"><?=csrf_field()?><input type="hidden" name="action" value="remove_cart"><input type="hidden" name="product_id" value="<?=(int)$item['id']?>"></form><?php endforeach;?>
      <?php if(current_user()):?><form method="post" class="checkout-form"><?=csrf_field()?><input type="hidden" name="action" value="checkout"><label>Nota para os vendedores (opcional)<textarea name="notes" rows="2" maxlength="1000" placeholder="Preferência de contacto ou entrega"></textarea></label><label class="check-label"><input type="checkbox" name="share_contact" value="1"><span>Autorizo a partilha do meu telefone de conta com os vendedores deste pedido, apenas para combinar a entrega e o pagamento.</span></label><button class="btn btn-primary" type="submit" data-confirm="Registar o pedido sem efectuar pagamento electrónico?">Enviar pedido aos vendedores</button><p class="form-note">O pedido ficará pendente. Não será cobrado qualquer valor e o stock ainda não é reservado.</p></form><?php else:?><a class="btn btn-primary" href="../../auth/entrar.php">Entrar para enviar pedido</a><?php endif;?>
    <?php endif;?>
  </section>
</main>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
<?php function safe_market_cut(string $value,int $length):string{return function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length);}?>
