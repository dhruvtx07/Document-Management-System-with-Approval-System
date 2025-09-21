</div> <!-- /.container -->

<footer class="bg-light text-center text-lg-start mt-auto py-3">
  <div class="text-center p-3" style="background-color: rgba(0, 0, 0, 0.05);">
    © <?php echo date("Y"); ?> Document Management System
  </div>
</footer>

<script src="<?php echo BASE_URL; ?>/assets/js/bootstrap.bundle.min.js"></script>
<script>
// For bulk actions: toggle all checkboxes
function toggleCheckboxes(source, formId) {
    const form = document.getElementById(formId);
    const checkboxes = form.querySelectorAll('input[type="checkbox"][name="selected_ids[]"]');
    checkboxes.forEach(checkbox => {
        checkbox.checked = source.checked;
    });
}
</script>
</body>
</html>