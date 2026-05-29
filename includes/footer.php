
</main><!-- /.main -->
</div><!-- /.layout -->

<!-- jQuery + DataTables -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<!-- HRMS core JS -->
<script src="<?= BASE_URL ?>/assets/js/hrms-core.js"></script>

<!-- PWA Service Worker registration -->
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js')
        .then(reg => {
            console.log('SW registered');
            // Request notification permission
            if (Notification.permission === 'default') {
                Notification.requestPermission();
            }
        })
        .catch(err => console.warn('SW registration failed', err));
}
</script>

<?php if (isset($page_scripts)): ?>
<?= $page_scripts ?>
<?php endif; ?>
</body>
</html>
