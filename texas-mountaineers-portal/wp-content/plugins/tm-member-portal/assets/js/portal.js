/* Minimal progressive-enhancement for the TM portal. */
(function () {
    'use strict';

    // Confirm before cancelling a class registration.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.matches('form')) return;
        var actionInput = form.querySelector('input[name="action"]');
        if (!actionInput) return;
        if (actionInput.value === 'tm_class_cancel') {
            if (!confirm('Cancel this class registration?')) {
                e.preventDefault();
            }
        }
    });
})();
