<?php
/**
 * Shared authenticated layout footer. Expects optional $pageScripts (raw HTML).
 */
$layoutFooterYear = isset($layoutToday) && $layoutToday instanceof DateTimeImmutable
    ? $layoutToday->format('Y')
    : date('Y');
$layoutFooterCompany = $layoutCompanyName ?? APP_NAME;
$layoutFooterScripts = $pageScripts ?? '';
?>
    </main>

    <footer class="dash-foot">
        <span>&copy; <?php echo e($layoutFooterYear); ?> <?php echo e($layoutFooterCompany); ?></span>
        <span>Inventory Management System &middot; v<?php echo e(APP_VERSION); ?></span>
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.js"
        integrity="sha384-n1AULnKdMJlK1oQCLNDL9qZsDgXtH6jRYFCpBtWFc+a9Yve0KSoMn575rk755NJZ" crossorigin="anonymous"></script>
<script src="<?php echo e(asset('vendor/sweetalert2/sweetalert2.all.min.js')); ?>"></script>
<script src="<?php echo e(asset('js/app.js')); ?>?v=<?php echo e(APP_VERSION); ?>"></script>
<?php echo $layoutFooterScripts; ?>
</body>
</html>
