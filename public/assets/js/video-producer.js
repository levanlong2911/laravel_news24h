function vpLockForm(form) {
    var trigger = document.querySelector('[data-target="#' + form.dataset.modal + '"]');
    if (trigger) {
        trigger.disabled = true;
        if (trigger.dataset.busy) { trigger.textContent = trigger.dataset.busy; }
    }
    window.setTimeout(function () {
        document.querySelectorAll('[form="' + form.id + '"]').forEach(function (btn) { btn.disabled = true; });
    }, 0);
    return true;
}
