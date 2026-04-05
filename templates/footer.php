        </div><!-- /.page-content -->
    </main><!-- /.main-content -->
</div><!-- /.app-layout -->

<script src="<?= url('assets/js/conecta.js') ?>"></script>
<script src="<?= url('assets/js/helpers.js') ?>"></script>
<script src="<?= url('assets/js/drawer.js') ?>"></script>
<script src="<?= url('assets/js/busca_cpf.js') ?>"></script>
<script src="<?= url('assets/js/gamificacao-efeitos.js') ?>"></script>
<script>if('serviceWorker' in navigator){navigator.serviceWorker.register('<?= url('sw.js') ?>').catch(function(){});}</script>
<?php if (!empty($extraJs)): ?>
    <script><?= $extraJs ?></script>
<?php endif; ?>
</body>
</html>
