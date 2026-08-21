<?php
/**
 * Intern CTC guard — shared by the employee Add form (modules/employee/add_form.php)
 * and Edit form (modules/employee/edit.php).
 *
 * An intern has no CTC, so when the selected designation is an intern one the
 * "CTC per Month" input is disabled, zeroed and visually greyed, with a note
 * explaining why. Re-selecting a normal designation restores the field and the
 * value the user had typed.
 *
 * This is a convenience only — modules/employee/create.php and edit.php
 * independently force the salary to 0 for an intern, so the rule holds even
 * with JavaScript off or a hand-crafted POST.
 *
 * The intern test mirrors designation_is_intern() in includes/helpers.php:
 * a whole-word "intern", so "Internal Auditor" is NOT treated as an intern.
 */
?>
<script>
(function () {
    var desig = document.getElementById('designation_id');
    var ctc   = document.querySelector('input[name="fixed_salary"]');
    if (!desig || !ctc) return;

    // Mirrors designation_is_intern() in includes/helpers.php.
    function isInternName(name) { return /\bintern\b/i.test(String(name || '')); }

    var group    = ctc.closest('.input-group') || ctc.parentNode;
    var wrap     = ctc.closest('.col-md-4') || ctc.parentNode;
    var restore  = ctc.value;   // what to put back if the designation changes again
    var wasRequired = ctc.required;

    var note = document.createElement('div');
    note.className   = 'form-text text-warning fw-semibold';
    note.style.display = 'none';
    note.innerHTML   = '<i class="fa fa-circle-info me-1"></i>Interns have no CTC — this field is disabled.';
    wrap.appendChild(note);

    function apply() {
        var opt    = desig.options[desig.selectedIndex];
        var intern = opt ? isInternName(opt.text) : false;

        if (intern) {
            if (!ctc.disabled) restore = ctc.value;   // remember before we zero it
            ctc.value    = '0';
            ctc.disabled = true;
            ctc.required = false;                     // a disabled field skips validation anyway
            group.style.opacity = '.55';
            note.style.display  = '';
        } else {
            if (ctc.disabled) ctc.value = restore;
            ctc.disabled = false;
            ctc.required = wasRequired;
            group.style.opacity = '';
            note.style.display  = 'none';
        }
    }

    desig.addEventListener('change', apply);

    // The Department → Designation cascade REBUILDS these options over AJAX and
    // restores the selection programmatically, which fires no 'change' event.
    // Watching the option list keeps the guard correct through that rebuild.
    if (window.MutationObserver) {
        new MutationObserver(apply).observe(desig, { childList: true });
    }

    apply();            // run on load so an intern's saved record opens correctly
})();
</script>
