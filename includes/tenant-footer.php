</main> <!-- Close tp-main -->

<!-- Footer -->
<footer class="tp-footer">
    <small><?php echo htmlspecialchars(getSetting('company_name', APP_NAME)); ?> &copy; <?php echo date('Y'); ?> &mdash; <?php _e('tp_powered_by', 'Powered by FIS Real Estate'); ?></small>
</footer>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script>
// Auto-dismiss alerts after 5s
setTimeout(function() {
    document.querySelectorAll('.alert-dismissible').forEach(function(el) {
        el.classList.remove('show');
        setTimeout(() => el.remove(), 150);
    });
}, 5000);
</script>

</body>
</html>
