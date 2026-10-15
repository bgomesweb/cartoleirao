<footer class="site-footer">
    <div class="container">
        <strong>🏆 Cartoleirão</strong> · Cartola FC · Brasileirão Série A
    </div>
</footer>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/site.js?v=<?= @filemtime(__DIR__ . '/../assets/js/site.js') ?: time() ?>"></script>
<?php if (getenv('VAPID_PUBLIC_KEY')): ?>
    <div class="modal fade" id="modalNotificacoes" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center py-4 px-4">
                    <div style="font-size:2rem">🔔</div>
                    <p class="mb-3" id="modalNotificacoesTexto"></p>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Ok</button>
                </div>
            </div>
        </div>
    </div>
    <script src="assets/js/push.js?v=<?= @filemtime(__DIR__ . '/../assets/js/push.js') ?: time() ?>"></script>
<?php endif; ?>
<?php if (!empty($extraScripts)): ?>
    <?php foreach ($extraScripts as $src): ?>
        <script src="<?= e($src) ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
