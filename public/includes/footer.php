<footer class="text-center text-muted small py-4 mt-5">
    🏆 Cartoleirão — Campeonato Brasileiro Série A
</footer>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="assets/js/compartilhar.js?v=<?= @filemtime(__DIR__ . '/../assets/js/compartilhar.js') ?: time() ?>"></script>
<?php if (!empty($extraScripts)): ?>
    <?php foreach ($extraScripts as $src): ?>
        <script src="<?= e($src) ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
