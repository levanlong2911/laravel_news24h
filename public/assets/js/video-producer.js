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

function vpAjaxPage(rootId, onRefresh) {
    var root = document.getElementById(rootId);
    var unknown = 'Chưa xác định kết quả — lượt AI có thể vẫn đang chạy hoặc đã xong. Tải lại trang để xem trạng thái, đừng bấm lại ngay.';
    var waiting = 'Đang có một thao tác chạy trên trang này — chờ nó xong rồi bấm tiếp.';
    var pending = false;

    if (root === null) { return; }

    function remember() {
        root.querySelectorAll('[data-busy]').forEach(function (button) {
            if (button.dataset.idle === undefined) { button.dataset.idle = button.textContent; }
        });
    }

    function notify(text, ok) {
        if (window.toastr && text) { window.toastr[ok ? 'success' : 'error'](text); }
    }

    function visible(element) {
        return element !== null && element.offsetParent !== null;
    }

    function triggerOf(form, submitter) {
        var trigger = form.dataset.modal ? root.querySelector('[data-target="#' + form.dataset.modal + '"]') : null;

        if (trigger === null && submitter && root.contains(submitter) && submitter.closest('.modal') === null) {
            trigger = submitter;
        }

        return trigger;
    }

    function placeOf(form, trigger) {
        var selector = null;
        var anchor = null;
        var action = form.getAttribute('action') || '';
        var byAction = 'form[action="' + CSS.escape(action) + '"]';

        if (form.dataset.modal && visible(trigger)) {
            selector = '[data-target="#' + form.dataset.modal + '"]';
            anchor = trigger;
        } else if (form.id && visible(form)) {
            selector = '#' + CSS.escape(form.id);
            anchor = form;
        } else if (visible(form) && root.querySelectorAll(byAction).length === 1) {
            selector = byAction;
            anchor = form;
        }

        return {
            selector: selector,
            top: anchor === null ? 0 : anchor.getBoundingClientRect().top,
            trigger: trigger,
            scrollY: window.scrollY
        };
    }

    function lock(trigger) {
        if (trigger === null) { return; }
        if (trigger.dataset.idle === undefined) { trigger.dataset.idle = trigger.textContent; }
        trigger.disabled = true;
        if (trigger.dataset.busy) { trigger.textContent = trigger.dataset.busy; }
    }

    function restore(place) {
        var element = place.selector ? root.querySelector(place.selector) : null;

        if (element !== null && element.offsetParent !== null) {
            window.scrollBy(0, element.getBoundingClientRect().top - place.top);

            return;
        }

        window.scrollTo(0, place.scrollY);
    }

    function unlock(form, trigger) {
        if (trigger) {
            trigger.disabled = false;
            if (trigger.dataset.idle !== undefined) { trigger.textContent = trigger.dataset.idle; }
        }

        if (form.id) {
            window.setTimeout(function () {
                document.querySelectorAll('[form="' + form.id + '"]').forEach(function (button) { button.disabled = false; });
            }, 0);
        }
    }

    function release(form, place) {
        var host = place.trigger || (visible(form) ? form : null);

        unlock(form, place.trigger);

        if (host !== null) {
            var status = host.parentNode.querySelector('.vp-ajax-status');
            if (status === null) {
                status = document.createElement('div');
                status.className = 'vp-ajax-status';
                host.insertAdjacentElement('afterend', status);
            }
            status.textContent = unknown;
        }

        notify(unknown, false);
    }

    function flash() {
        var holder = root.querySelector('[data-ajax-flash]');

        if (holder === null) { return; }

        notify(holder.dataset.error, false);
        notify(holder.dataset.success, true);
    }

    root.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') { return; }

        event.preventDefault();

        if (window.jQuery && form.dataset.modal) { window.jQuery('#' + form.dataset.modal).modal('hide'); }

        if (pending) {
            unlock(form, triggerOf(form, event.submitter || null));
            notify(waiting, false);

            return;
        }

        pending = true;

        var trigger = triggerOf(form, event.submitter || null);
        var place = placeOf(form, trigger);
        lock(trigger);
        vpLockForm(form);

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'Accept': 'text/html' }
        })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }

                if (new URL(response.url, window.location.href).pathname !== window.location.pathname) {
                    window.location.href = response.url;

                    return null;
                }

                return response.text();
            })
            .then(function (html) {
                if (html === null) { return; }

                var fresh = new DOMParser().parseFromString(html, 'text/html').getElementById(rootId);

                if (fresh === null) { throw new Error('missing ' + rootId); }

                pending = false;
                root.innerHTML = fresh.innerHTML;
                document.body.classList.remove('modal-open');
                document.body.style.paddingRight = '';
                remember();
                restore(place);
                flash();

                if (onRefresh) { onRefresh(root); }
            })
            .catch(function () {
                pending = false;
                release(form, place);
            });
    });

    remember();
}
