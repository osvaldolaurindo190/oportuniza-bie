<?php $basePrefix = $assetPrefix ?? ''; ?>
<footer class="site-footer">
  <div class="container footer-main">
    <div class="footer-brand"><a class="brand brand-footer" href="<?= e($basePrefix . 'index.php') ?>"><span class="brand-mark">O</span><span>oportuniza<span class="brand-accent">.</span><small>BIÉ · ANGOLA</small></span></a><p>Aproximar pessoas, competências e oportunidades no Município do Cuito.</p></div>
    <div><strong>Explorar</strong><a href="<?= e($basePrefix . 'modules/emprego/index.php') ?>">Emprego</a><a href="<?= e($basePrefix . 'modules/microtarefas/index.php') ?>">Microtarefas</a><a href="<?= e($basePrefix . 'modules/servicos/index.php') ?>">Serviços</a><a href="<?= e($basePrefix . 'modules/mercado/index.php') ?>">Mercado digital</a></div>
    <div><strong>Conta</strong><a href="<?= e($basePrefix . 'conta.php') ?>">Minha conta</a><a href="<?= e($basePrefix . 'auth/entrar.php') ?>">Aceder à conta</a><a href="<?= e($basePrefix . 'auth/registar.php') ?>">Criar conta</a></div>
  </div>
  <div class="container footer-bottom"><span>© <span id="current-year">2026</span> Oportuniza Bié. Projecto académico.</span><span>Desenvolvido para o Município do Cuito, Província do Bié.</span></div>
</footer>
</body>
</html>
