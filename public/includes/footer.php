<footer class="site-footer">
    <div class="container">
        <strong>🏆 Cartoleirão</strong> · Cartola FC · Brasileirão Série A
    </div>
</footer>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/site.js?v=<?= @filemtime(__DIR__ . '/../assets/js/site.js') ?: time() ?>"></script>
<?php if (!empty($extraScripts)): ?>
    <?php foreach ($extraScripts as $src): ?>
        <script src="<?= e($src) ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
