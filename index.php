<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
$pageTitle = 'Oportuniza Bié | Oportunidades para todos';
$assetPrefix = '';
if (isset($_GET['logout']) && $_GET['logout'] === '1') flash('success', 'A sessão foi terminada.');
$metrics = ['jobs'=>0,'tasks'=>0,'services'=>0,'products'=>0];
$pdo = db_or_null();
if ($pdo) {
    try {
        $metrics['jobs'] = (int)$pdo->query("SELECT COUNT(*) FROM job_vacancies WHERE status='published' AND (deadline IS NULL OR deadline>=CURRENT_DATE())")->fetchColumn();
        $metrics['tasks'] = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status='published' AND (deadline IS NULL OR deadline>=CURRENT_DATE())")->fetchColumn();
        $metrics['services'] = (int)$pdo->query("SELECT COUNT(*) FROM service_listings WHERE status='published'")->fetchColumn();
        $metrics['products'] = (int)$pdo->query("SELECT COUNT(*) FROM products p INNER JOIN stores s ON s.id=p.store_id WHERE p.status='published' AND s.status='active' AND p.stock_quantity>0")->fetchColumn();
    } catch (Throwable $exception) { error_log('Indicadores da página inicial: '.$exception->getMessage()); }
}
?>
<?php require __DIR__ . '/includes/header.php'; ?>
<main>
  <section class="hero container">
    <div class="hero-content">
      <span class="eyebrow">Oportunidades locais, num só lugar</span>
      <h1>O teu próximo passo começa <span>aqui.</span></h1>
      <p>Descobre vagas de emprego, pequenos trabalhos remunerados, serviços e produtos no Município do Cuito.</p>
      <div class="hero-actions"><a class="btn btn-primary" href="#explorar">Explorar oportunidades <span aria-hidden="true">→</span></a><?php if (!current_user()): ?><a class="btn btn-outline" href="auth/registar.php">Criar conta</a><?php else: ?><a class="btn btn-outline" href="conta.php">A minha conta</a><?php endif; ?></div>
      <div class="hero-note"><span class="status-dot"></span> Uma plataforma pensada para aproximar pessoas e oportunidades no Bié.</div>
    </div>
    <div class="hero-panel" aria-label="Resumo dos módulos da plataforma">
      <div class="panel-top"><span class="panel-icon">OB</span><div><strong>Oportuniza Bié</strong><small>Oportunidades em movimento</small></div><span class="online-pill">Protótipo académico</span></div>
      <div class="panel-search"><span aria-hidden="true">⌕</span><span>O que procuras hoje?</span><span class="search-filter">☷</span></div>
      <div class="quick-stats">
        <a href="modules/emprego/index.php"><span class="stat-icon violet">▣</span><strong>Emprego</strong><small><?= $metrics['jobs'] ?> vagas disponíveis</small></a>
        <a href="modules/microtarefas/index.php"><span class="stat-icon orange">↗</span><strong>Microtarefas</strong><small><?= $metrics['tasks'] ?> trabalhos anunciados</small></a>
        <a href="modules/servicos/index.php"><span class="stat-icon green">⌂</span><strong>Serviços</strong><small><?= $metrics['services'] ?> serviços publicados</small></a>
        <a href="modules/mercado/index.php"><span class="stat-icon blue">◇</span><strong>Mercado</strong><small><?= $metrics['products'] ?> produtos disponíveis</small></a>
      </div>
      <div class="panel-footer"><span class="avatars"><i>U</i><i>O</i><i>+</i></span><span>Ligamos quem procura a quem oferece.</span></div>
    </div>
  </section>

  <section class="trust-strip"><div class="container trust-inner"><span>UMA PLATAFORMA</span><strong>Local</strong><strong>Prática</strong><strong>Organizada</strong><strong>Em evolução</strong></div></section>
  <?php if (!$pdo): ?><section class="container"><div class="flash flash-warning">A página inicial está disponível, mas a base de dados ainda não está ligada neste ambiente. Para activar registos, anúncios e encomendas, configura o MySQL indicado no README.</div></section><?php endif; ?>
  <section class="section container" id="explorar"><div class="section-heading"><div><span class="eyebrow">Explora a plataforma</span><h2>Uma solução. Quatro caminhos.</h2></div><p>Consulta oportunidades e encontra o módulo mais adequado às tuas necessidades.</p></div>
    <div class="module-grid">
      <a class="module-card" href="modules/emprego/index.php"><span class="module-icon violet">▣</span><span class="card-kicker">01 / CARREIRA</span><h3>Emprego e recrutamento</h3><p>Pesquisa vagas, consulta requisitos e organiza as tuas candidaturas.</p><span class="card-link">Explorar vagas <b>↗</b></span></a>
      <a class="module-card" href="modules/microtarefas/index.php"><span class="module-icon orange">↗</span><span class="card-kicker">02 / TAREFAS</span><h3>Microtarefas</h3><p>Descobre trabalhos de curta duração e acompanha as tarefas contratadas.</p><span class="card-link">Ver microtarefas <b>↗</b></span></a>
      <a class="module-card" href="modules/servicos/index.php"><span class="module-icon green">⌂</span><span class="card-kicker">03 / COMPETÊNCIAS</span><h3>Prestação de serviços</h3><p>Divulga competências profissionais e encontra prestadores locais.</p><span class="card-link">Encontrar serviços <b>↗</b></span></a>
      <a class="module-card" href="modules/mercado/index.php"><span class="module-icon blue">◇</span><span class="card-kicker">04 / COMÉRCIO</span><h3>Mercado digital</h3><p>Publica produtos, consulta lojas e prepara pedidos aos vendedores.</p><span class="card-link">Visitar mercado <b>↗</b></span></a>
    </div>
  </section>
  <section class="cta-section"><div class="container cta-inner"><div><span class="eyebrow light">Faz parte da comunidade</span><h2>As tuas competências<br>merecem ser encontradas.</h2><p>Cria o teu perfil e prepara-te para descobrir novas oportunidades.</p></div><a class="btn btn-light" href="<?= current_user() ? 'conta.php' : 'auth/registar.php' ?>"><?= current_user() ? 'Completar perfil' : 'Começar agora' ?> <span aria-hidden="true">→</span></a></div></section>
  <section class="section container how-section"><div class="section-heading"><div><span class="eyebrow">Simples e directo</span><h2>Como funciona?</h2></div><p>O objectivo é facilitar a procura, a divulgação e a ligação entre utilizadores.</p></div><div class="steps-grid"><article><span>1</span><h3>Cria o teu perfil</h3><p>Indica os teus dados e escolhe as actividades que pretendes realizar.</p></article><article><span>2</span><h3>Pesquisa ou publica</h3><p>Encontra oportunidades ou divulga vagas, tarefas, serviços e produtos.</p></article><article><span>3</span><h3>Acompanha as actividades</h3><p>Gere candidaturas, recebe notificações e envia pedidos de contacto aos vendedores.</p></article></div></section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
