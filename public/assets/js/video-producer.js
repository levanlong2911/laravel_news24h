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

function vpTaskEdited() {
    window.vpTaskForms = (window.vpTaskForms || []).filter(function (form) { return document.contains(form); });

    return window.vpTaskForms.length > 0;
}

function vpTaskHeld(element) {
    return element.querySelector('.modal.show') !== null
        || element.matches('.vp-pending, .vp-saving, .vp-unsaved') || element.querySelector('.vp-pending, .vp-saving, .vp-unsaved') !== null
        || (window.vpTaskForms || []).some(function (form) { return element.contains(form); });
}

function vpTaskParts(fresh, options) {
    var ids = function (doc) { return Array.prototype.map.call(doc.querySelectorAll('[data-ajax-part]'), function (element) { return element.id; }).join(' '); };
    var replaced = [];

    if (ids(document) === '' || ids(fresh) !== ids(document)) {
        var root = options.root ? document.getElementById(options.root) : null;
        var page = root === null ? null : fresh.getElementById(options.root);

        if (root === null || page === null || vpTaskHeld(root)) { return null; }
        root.innerHTML = page.innerHTML;
        replaced.push(root);
    } else {
        fresh.querySelectorAll('[data-ajax-part]').forEach(function (element) {
            var current = document.getElementById(element.id);
            var next = document.importNode(element, true);

            if (current === null || vpTaskHeld(current)) { return; }
            current.replaceWith(next);
            replaced.push(next);
        });
    }

    if (document.querySelector('.modal.show') === null) {
        document.body.classList.remove('modal-open');
        document.body.style.paddingRight = '';
    }

    if (options.refresh) { replaced.forEach(function (element) { options.refresh(element); }); }

    return replaced;
}

function vpTaskWatch(options) {
    options = options || window.vpTaskOptions || {};
    window.vpTaskOptions = options;

    var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-task-endpoint][data-task-kind][data-task-id]:not([data-task-settled])'));
    var round = (window.vpTaskRound || 0) + 1;

    window.vpTaskRound = round;

    if (window.vpTaskTimer) {
        window.clearTimeout(window.vpTaskTimer);
        window.vpTaskTimer = null;
    }

    if (!window.vpTaskFormsBound) {
        window.vpTaskFormsBound = true;
        ['input', 'change'].forEach(function (type) {
            document.addEventListener(type, function (event) {
                var form = event.isTrusted && event.target.closest ? event.target.closest('form') : null;
                window.vpTaskForms = window.vpTaskForms || [];
                if (form !== null && window.vpTaskForms.indexOf(form) === -1) { window.vpTaskForms.push(form); }
            }, true);
        });
        document.addEventListener('submit', function (event) {
            window.vpTaskForms = (window.vpTaskForms || []).filter(function (form) { return form !== event.target; });
        }, true);
    }

    if (boxes.length === 0) { return; }

    var endpoint = boxes[0].getAttribute('data-task-endpoint');

    function current() {
        return round === window.vpTaskRound;
    }

    function schedule() {
        if (current() && boxes.length > 0) { window.vpTaskTimer = window.setTimeout(poll, 5000); }
    }

    function clock(seconds) {
        return new Date(seconds * 1000).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
    }

    function describe(data) {
        var text = data.status === 'running' ? 'Đang chạy' : 'Đang chờ';

        if (data.requested_by) { text += ' · ' + data.requested_by; }
        if (data.status === 'running' && data.progress && data.progress.text_chars) { text += ' · đã nhận ' + data.progress.text_chars.toLocaleString('vi-VN') + ' ký tự'; }
        if (data.status === 'pending' && data.retry_at) { text += ' · chờ hạn mức nhà cung cấp tới ' + clock(data.retry_at); }

        return text + ' — trang tự cập nhật khi xong, đóng trang vẫn chạy tiếp';
    }

    function settle(box, data) {
        var label = box.querySelector('[data-task-label]');
        var link = document.createElement('a');

        box.setAttribute('data-task-settled', '1');
        if (label === null) { return; }
        label.textContent = (data.status === 'failed' ? 'Lỗi' + (data.error ? ': ' + data.error : '') : 'Đã xong') + ' — phần này đang được sửa nên không tự cập nhật. ';
        label.style.color = data.status === 'failed' ? 'var(--vp-red)' : 'var(--vp-green-fg)';
        link.href = data.result_url || window.location.href;
        link.textContent = 'Xem kết quả';
        label.appendChild(link);
    }

    function refresh(finished) {
        var target = new URL(finished[0].state.result_url || window.location.href, window.location.href);

        if (target.origin + target.pathname !== window.location.origin + window.location.pathname) {
            if (vpTaskEdited()) {
                finished.forEach(function (item) { settle(item.box, item.state); });
                schedule();
            } else {
                window.location.href = target.href;
            }

            return;
        }

        fetch(target.origin + target.pathname + target.search, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }

                return response.text();
            })
            .then(function (html) {
                if (!current()) { return; }

                var replaced = vpTaskParts(new DOMParser().parseFromString(html, 'text/html'), options);

                if (replaced === null) {
                    finished.forEach(function (item) { settle(item.box, item.state); });
                    schedule();

                    return;
                }

                window.history.replaceState(null, '', target.pathname + target.search + window.location.hash);
                finished.forEach(function (item) {
                    if (document.contains(item.box)) { settle(item.box, item.state); }
                });
                vpTaskWatch(options);
            })
            .catch(function () {
                if (!current()) { return; }
                finished.forEach(function (item) { settle(item.box, item.state); });
                schedule();
            });
    }

    function query() {
        var groups = {};

        boxes.forEach(function (box) {
            var kind = box.getAttribute('data-task-kind');
            groups[kind] = groups[kind] || [];
            groups[kind].push(box.getAttribute('data-task-id'));
        });

        return Object.keys(groups).map(function (kind) { return encodeURIComponent(kind) + '=' + encodeURIComponent(groups[kind].join(',')); }).join('&');
    }

    function poll() {
        window.vpTaskTimer = null;
        fetch(endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + query(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                var finished = [];

                if (!current()) { return; }
                if (data === null) { schedule(); return; }

                boxes = boxes.filter(function (box) {
                    var state = (data[box.getAttribute('data-task-kind')] || {})[box.getAttribute('data-task-id')];
                    var label = box.querySelector('[data-task-label]');

                    if (!state) { return true; }
                    if (!state.done) {
                        if (label) { label.textContent = describe(state); }
                        return true;
                    }
                    finished.push({ box: box, state: state });
                    return false;
                });

                if (finished.length > 0) {
                    refresh(finished);
                    return;
                }

                schedule();
            })
            .catch(function () {
                if (current()) { schedule(); }
            });
    }

    schedule();
}

function vpSubmitSoon(form, delay) {
    var part = form.closest('[data-ajax-part]');

    if (part !== null) {
        part.classList.remove('vp-unsaved');
        part.classList.add('vp-pending');
    }

    window.clearTimeout(form.vpSoon);
    form.vpSoon = window.setTimeout(function () {
        var current = form.closest('[data-ajax-part]');

        if (current !== null) {
            current.classList.remove('vp-pending');
            current.classList.add('vp-saving');
        }

        form.requestSubmit();
    }, delay === undefined ? 500 : delay);
}

function vpAjaxPage(rootId, onRefresh, onParts, only) {
    var root = document.getElementById(rootId);
    var unknown = 'Chưa xác định kết quả — lượt AI có thể vẫn đang chạy hoặc đã xong. Tải lại trang để xem trạng thái, đừng bấm lại ngay.';
    var waiting = 'Đang có một thao tác chạy trên trang này — chờ nó xong rồi bấm tiếp.';
    var whole = false;
    var running = 0;
    var sequence = 0;
    var sent = {};
    var drawn = {};
    var flying = {};
    var queue = {};

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

    function viewOf() {
        var marks = root.querySelectorAll('[id]');

        for (var index = 0; index < marks.length; index++) {
            var box = marks[index].getBoundingClientRect();

            if (marks[index].offsetParent !== null && box.top >= 0 && box.top < window.innerHeight) {
                return { id: marks[index].id, top: box.top, scrollY: window.scrollY };
            }
        }

        return { id: null, top: 0, scrollY: window.scrollY };
    }

    function keep(view) {
        var mark = view.id ? document.getElementById(view.id) : null;

        if (mark !== null && mark.offsetParent !== null) {
            window.scrollBy(0, mark.getBoundingClientRect().top - view.top);

            return;
        }

        window.scrollTo(0, view.scrollY);
    }

    function controlsOf(form) {
        return form.id ? Array.prototype.slice.call(document.querySelectorAll('[form="' + form.id + '"]')) : [];
    }

    function unlock(trigger, controls, states) {
        if (trigger) {
            trigger.disabled = false;
            if (trigger.dataset.idle !== undefined) { trigger.textContent = trigger.dataset.idle; }
        }

        window.setTimeout(function () {
            controls.forEach(function (control, index) { control.disabled = states[index]; });
        }, 0);
    }

    function release(form, place, controls, states) {
        var host = place.trigger || (visible(form) ? form : null);

        unlock(place.trigger, controls, states);

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

    function parts(doc) {
        return Array.prototype.map.call(doc.querySelectorAll('[data-ajax-part]'), function (element) { return element.id; }).join(' ');
    }

    function busy(id, form) {
        var holder = flying[id];

        if (!holder || holder === form) { return false; }

        return typeof holder === 'number' || holder.isConnected !== false;
    }

    function released(id) {
        var part = document.getElementById(id);
        var held = queue[id] || [];
        var next = held.shift();

        while (next && next.isConnected === false) { next = held.shift(); }

        delete flying[id];

        if (part !== null) { part.classList.remove('vp-saving'); }

        if (next) {
            flying[id] = next;
            vpSubmitSoon(next, 0);
        }
    }

    function dropped(id) {
        var part = document.getElementById(id);

        delete flying[id];
        delete queue[id];

        if (part !== null) {
            part.classList.remove('vp-saving');
            part.classList.remove('vp-pending');
            part.classList.add('vp-unsaved');
        }
    }

    function landed(current, element, seq) {
        var values = {};

        if (flying[current.id] !== seq) { return; }

        element.querySelectorAll('[data-ajax-fresh]').forEach(function (field) { values[field.name] = field.value; });
        current.querySelectorAll('[data-ajax-fresh]').forEach(function (field) {
            if (values[field.name] !== undefined) { field.value = values[field.name]; }
        });

        released(current.id);
    }

    function replaceParts(fresh, seq, conflict) {
        var replaced = [];

        fresh.querySelectorAll('[data-ajax-part]').forEach(function (element) {
            var current = document.getElementById(element.id);
            var stale = seq < (sent[element.id] || 0) || seq < (drawn[element.id] || 0);

            if (current !== null && !stale && !conflict && current.classList.contains('vp-pending')) {
                landed(current, element, seq);

                return;
            }

            if (current !== null && !stale && current.querySelector('.modal.show') === null && (conflict || !current.classList.contains('vp-pending'))) {
                current.outerHTML = element.outerHTML;
                drawn[element.id] = seq;
                delete queue[element.id];
                replaced.push(document.getElementById(element.id));
            }
        });

        return replaced;
    }

    function replaceWhole(fresh) {
        var page = fresh.getElementById(rootId);

        if (page === null) { throw new Error('missing ' + rootId); }

        root.innerHTML = page.innerHTML;
    }

    root.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') { return; }
        if (only && !form.matches(only)) { return; }

        event.preventDefault();

        if (window.jQuery && form.dataset.modal) { window.jQuery('#' + form.dataset.modal).modal('hide'); }

        var exclusive = form.hasAttribute('data-ajax-whole') || root.querySelector('[data-ajax-part]') === null;
        var trigger = triggerOf(form, event.submitter || null);
        var controls = controlsOf(form);
        var states = controls.map(function (control) { return control.disabled; });
        var ownPart = form.closest('[data-ajax-part]');
        var ownId = ownPart === null ? null : ownPart.id;
        var queued = !!ownId && form.querySelector('[data-ajax-fresh]') !== null;

        if (whole || (exclusive && running > 0)) {
            if (queued && flying[ownId] === form) { dropped(ownId); }

            unlock(trigger, controls, states);
            notify(waiting, false);

            return;
        }

        if (queued && busy(ownId, form)) {
            queue[ownId] = (queue[ownId] || []).filter(function (held) { return held !== form; }).concat([form]);
            ownPart.classList.remove('vp-unsaved');
            ownPart.classList.add('vp-pending');

            return;
        }

        var place = placeOf(form, trigger);
        var target = null;
        var fragment = false;
        var conflict = false;
        var settled = false;
        var seq = ++sequence;

        if (ownId) { sent[ownId] = seq; }

        if (queued) {
            flying[ownId] = seq;
            ownPart.classList.add('vp-saving');

            if ((queue[ownId] || []).length > 0) { ownPart.classList.add('vp-pending'); }
        }

        function settle() {
            if (settled) { return; }
            settled = true;
            running--;
            if (exclusive) { whole = false; }
        }

        whole = exclusive;
        running++;
        lock(trigger);
        vpLockForm(form);

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'Accept': 'text/html', 'X-Vp-Ajax': '1' }
        })
            .then(function (response) {
                fragment = response.headers.get('X-Vp-Fragment') === '1';

                conflict = fragment && response.status === 409;

                if (!response.ok && !conflict) { throw new Error('HTTP ' + response.status); }

                if (!fragment && new URL(response.url, window.location.href).pathname !== window.location.pathname) {
                    window.location.href = response.url;

                    return null;
                }

                target = fragment ? window.location.href : response.url;

                return response.text();
            })
            .then(function (html) {
                if (html === null) { return; }

                var fresh = new DOMParser().parseFromString(html, 'text/html');
                var swapped = !fragment && (exclusive || parts(fresh) !== parts(document));
                var replaced = [];
                var elsewhere = Math.abs(window.scrollY - place.scrollY) > 4 ? viewOf() : null;
                var note = fresh.querySelector('[data-ajax-flash]');
                var done = !conflict && note !== null && !!note.dataset.success && !note.dataset.error;

                if (swapped) {
                    replaceWhole(fresh);
                    flying = {};
                    queue = {};
                } else {
                    replaced = replaceParts(fresh, seq, conflict);
                    unlock(place.trigger, controls, states);
                }

                if (ownId && flying[ownId] === seq) {
                    if (Array.prototype.some.call(fresh.querySelectorAll('[data-ajax-part]'), function (element) { return element.id === ownId; })) {
                        released(ownId);
                    } else {
                        dropped(ownId);
                    }
                }

                settle();

                if (document.querySelector('.modal.show') === null) {
                    document.body.classList.remove('modal-open');
                    document.body.style.paddingRight = '';
                }

                remember();
                window.history.replaceState(null, '', target);
                if (elsewhere === null) { restore(place); } else { keep(elsewhere); }
                if (swapped || fresh.querySelector('[data-ajax-flash]') === null || replaced.some(function (part) { return part !== null && part.hasAttribute('data-ajax-flash'); })) { flash(); }

                if (swapped && onRefresh) { onRefresh(root, form, done); }
                if (!swapped && onParts) { onParts(replaced, form, done); }
            })
            .catch(function () {
                var own = ownId ? document.getElementById(ownId) : null;

                settle();

                if (own !== null && sent[ownId] === seq && own.classList.contains('vp-saving')) {
                    own.classList.remove('vp-saving');
                    own.classList.add('vp-unsaved');
                }

                if (ownId && flying[ownId] === seq) { released(ownId); }

                release(form, place, controls, states);
            });
    });

    remember();
}
