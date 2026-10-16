<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pageTitle = $pageTitle ?? APP_NAME;
$assetPrefix = $assetPrefix ?? '';
$user = current_user();
$flashes = consume_flashes();
?>
<!doctype html>
<html lang="pt-AO">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Oportuniza Bié: emprego, microtarefas, serviços e comércio electrónico no Município do Cuito.">
  <meta name="theme-color" content="#147d55">
  <title><?= e($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= e($assetPrefix) ?>assets/css/style.css">
  <script src="<?= e($assetPrefix) ?>assets/js/app.js" defer></script>
</head>
<body>
<header class="site-header">
  <div class="container nav-wrap">
    <a class="brand" href="<?= e($assetPrefix) ?>index.php" aria-label="Oportuniza Bié - página inicial"><span class="brand-mark">O</span><span>oportuniza<span class="brand-accent">.</span><small>BIÉ · ANGOLA</small></span></a>
    <button class="menu-toggle" aria-label="Abrir menu" aria-expanded="false" type="button"><span></span><span></span><span></span></button>
    <nav class="main-nav" aria-label="Navegação principal">
      <a href="<?= e($assetPrefix) ?>modules/emprego/index.php">Emprego</a>
      <a href="<?= e($assetPrefix) ?>modules/microtarefas/index.php">Microtarefas</a>
      <a href="<?= e($assetPrefix) ?>modules/servicos/index.php">Serviços</a>
      <a href="<?= e($assetPrefix) ?>modules/mercado/index.php">Mercado</a>
    </nav>
    <div class="nav-actions">
      <?php if ($user): ?>
        <a class="login-link" href="<?= e($assetPrefix) ?>conta.php"><?= e(explode(' ', $user['full_name'])[0]) ?></a>
        <?php if (has_role('admin')): ?><a class="login-link" href="<?= e($assetPrefix) ?>admin/index.php">Admin</a><?php endif; ?>
        <form class="inline-form" method="post" action="<?= e($assetPrefix) ?>auth/sair.php"><?= csrf_field() ?><button type="submit" class="text-button">Sair</button></form>
      <?php else: ?>
        <a class="login-link" href="<?= e($assetPrefix) ?>auth/entrar.php">Entrar</a>
        <a class="btn btn-small btn-primary" href="<?= e($assetPrefix) ?>auth/registar.php">Criar conta</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<?php if ($flashes): ?><div class="container flash-stack" aria-live="polite"><?php foreach ($flashes as $message): ?><div class="flash flash-<?= e($message['type']) ?>"><?= e($message['message']) ?></div><?php endforeach; ?></div><?php endif; ?>
