/* wp-admin → Achievements: icon picker and badge preview of the "Grant Achievement" form. */
(function () {
    var icon = document.getElementById('wfo-ach-icon');
    var preview = document.getElementById('wfo-ach-preview');
    var style = document.getElementById('wfo-ach-style');
    if (!icon || !preview || !style) return;
    document.querySelectorAll('.wfo-ach-icon').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelectorAll('.wfo-ach-icon').forEach(function (other) { other.classList.remove('selected'); });
            button.classList.add('selected');
            icon.value = button.dataset.icon;
            preview.textContent = button.dataset.icon;
        });
    });
    style.addEventListener('change', function () { preview.className = 'wfo-ach-preview-badge ' + style.value; });
})();
