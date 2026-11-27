/*
 * Phase 12 — Social Publishing screen.
 *
 * The page holds one package revision at a time. What a person types in the content form is theirs until they save it:
 * the form is compared with what the page was opened with, and while they differ
 *   - the "Xem trước bài đăng" column shows the text being edited, labelled as not saved,
 *   - the buttons that act on the saved revision (approve, review, export, post, AI, cover) are locked,
 *   - leaving the revision — another revision, another package, a link, another form — asks first.
 * When the bar above points at a channel, format and language that have no package, the work area of the package that
 * is open is set aside, so nothing below the bar belongs to another package than the one the bar names.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-social-screen]');

    if (!root) {
        return;
    }

    var store = {
        get: function (key) { try { return window.sessionStorage.getItem(key); } catch (e) { return null; } },
        set: function (key, value) { try { window.sessionStorage.setItem(key, value); } catch (e) { /* storage blocked: the page works without it */ } }
    };

    var escapeHtml = function (text) {
        return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    };
    var highlight = function (text) {
        return escapeHtml(text).replace(/(^|\s)(#[\p{L}\p{N}_]+)/gu, '$1<span class="sp-hashtag">$2</span>');
    };
    var normalise = function (value) {
        return String(value || '').replace(/\r\n?/g, '\n').trim();
    };

    // ---- Tabs: the tab open is remembered for the project, so a button press lands back on it. -------------------
    var tabKey = 'sp-tab:' + root.dataset.spProject;
    var tabs = root.querySelectorAll('[data-sp-tab]');

    var showTab = function (name) {
        var found = false;

        tabs.forEach(function (tab) {
            var on = tab.dataset.spTab === name;
            tab.classList.toggle('on', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            found = found || on;
        });

        if (!found) {
            return false;
        }

        root.querySelectorAll('[data-sp-panel]').forEach(function (panel) {
            panel.hidden = panel.dataset.spPanel !== name;
        });
        store.set(tabKey, name);

        return true;
    };

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            showTab(tab.dataset.spTab);

            if (window.history.replaceState) {
                window.history.replaceState(null, '', '#tab-' + tab.dataset.spTab);
            }
        });
    });

    var fromHash = (window.location.hash.match(/^#tab-([a-z]+)$/) || [])[1];
    showTab(fromHash || store.get(tabKey) || 'content') || showTab('content');

    // ---- The "..." menu and the dialogs. ----------------------------------------------------------------------------
    var menu = root.querySelector('[data-sp-menu]');
    var menuToggle = root.querySelector('[data-sp-menu-toggle]');

    var closeMenu = function () {
        if (menu) {
            menu.hidden = true;
            menuToggle.setAttribute('aria-expanded', 'false');
        }
    };

    if (menuToggle && menu) {
        menuToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            menu.hidden = !menu.hidden;
            menuToggle.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
        });
        document.addEventListener('click', function (event) {
            if (!menu.hidden && !menu.contains(event.target)) {
                closeMenu();
            }
        });
    }

    var closeModal = function (modal) {
        modal.hidden = true;
        document.body.style.overflow = '';
    };

    root.querySelectorAll('[data-sp-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            var modal = document.getElementById(button.dataset.spOpen);
            closeMenu();

            if (modal) {
                modal.hidden = false;
                document.body.style.overflow = 'hidden';
            }
        });
    });
    root.querySelectorAll('.sp-modal').forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal || event.target.closest('[data-sp-close]')) {
                closeModal(modal);
            }
        });
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            root.querySelectorAll('.sp-modal:not([hidden])').forEach(closeModal);
            closeMenu();
        }
    });

    // ---- Counters: a title against the platform limit, hashtags by count, keywords the way the platform counts them. --
    var measure = function (field) {
        var kind = field.dataset.spCount;
        var value = field.value || '';

        if (kind === 'hashtags') {
            return { value: value.split(/[\s,]+/).map(function (tag) { return tag.replace(/^#+/, ''); }).filter(Boolean).length, unit: ' hashtag' };
        }

        if (kind === 'tags') {
            var items = value.split(/[,\r\n]+/).map(function (tag) { return tag.trim(); }).filter(Boolean);
            var length = Math.max(0, items.length - 1);
            items.forEach(function (tag) { length += Array.from(tag).length + (tag.indexOf(' ') >= 0 ? 2 : 0); });

            return { value: length, unit: '' };
        }

        return { value: Array.from(value).length, unit: '' };
    };
    var updateCounter = function (field) {
        var counter = field.parentNode.querySelector('.sp-count');

        if (!counter) {
            return;
        }

        var result = measure(field);
        var max = parseInt(field.dataset.spMax || '', 10);
        counter.textContent = isNaN(max) ? result.value + result.unit : result.value + ' / ' + max + result.unit;
        counter.classList.toggle('over', !isNaN(max) && result.value > max);
    };
    var counted = root.querySelectorAll('[data-sp-count]');
    counted.forEach(updateCounter);

    // ---- The content form: what is not saved yet. ---------------------------------------------------------------------
    var form = document.getElementById('sp-content-form');
    var editable = !!form && !!form.querySelector('[data-social-save]');
    var dirty = false;
    var leaving = false;

    // Every name and value as its own JSON string: no text a person types can make two different forms read the same.
    var serialise = function () {
        var pairs = [];

        new FormData(form).forEach(function (value, key) {
            if (key !== '_token') {
                pairs.push([key, normalise(value)]);
            }
        });

        return JSON.stringify(pairs);
    };
    // What the page was opened with is what the server wrote into the form — not what a browser may have put back into it
    // on a return to the page. The form is set to its written values for a moment, read, and given back what it showed.
    var openedState = function () {
        var shown = Array.prototype.filter.call(form.elements, function (node) {
            return /^(INPUT|TEXTAREA|SELECT)$/.test(node.tagName) && !/^(hidden|submit|reset|button)$/.test(node.type);
        }).map(function (node) {
            return { node: node, value: node.value, checked: node.checked };
        });

        form.reset();
        var state = serialise();

        shown.forEach(function (field) {
            if (field.node.type === 'checkbox' || field.node.type === 'radio') {
                field.node.checked = field.checked;
            } else {
                field.node.value = field.value;
            }
        });

        return state;
    };
    var opened = form ? openedState() : '';

    // The text the platform will get, assembled in the order the server assembles it.
    var live = root.querySelector('[data-sp-live-preview]');
    var liveState = live ? live.querySelector('[data-sp-live-state]') : null;
    var liveSaved = [];

    if (live) {
        live.querySelectorAll('[data-sp-live]').forEach(function (node) {
            liveSaved.push({ node: node, html: node.innerHTML, hidden: node.hidden, className: node.className });
        });
    }

    var field = function (selector) {
        var node = form.querySelector(selector);

        return node && !node.disabled ? normalise(node.value) : '';
    };
    var renderLive = function () {
        if (!live || !form) {
            return;
        }

        if (!dirty) {
            liveSaved.forEach(function (saved) {
                saved.node.innerHTML = saved.html;
                saved.node.hidden = saved.hidden;
                saved.node.className = saved.className;
            });

            if (liveState) {
                liveState.textContent = liveState.dataset.savedLabel;
                liveState.classList.remove('warn');
            }

            return;
        }

        var hashtags = [];
        (form.querySelector('#sp-hashtags') || { value: '' }).value.split(/[\s,]+/).forEach(function (tag) {
            tag = tag.trim().replace(/^#+/, '');

            if (tag !== '' && !/[\s#]/.test(tag) && hashtags.indexOf(tag) < 0) {
                hashtags.push(tag);
            }
        });
        var keywords = [];
        (form.querySelector('#sp-keywords') || { value: '' }).value.split(/[,\r\n]+/).forEach(function (word) {
            word = word.trim();

            if (word !== '' && keywords.indexOf(word) < 0) {
                keywords.push(word);
            }
        });
        var action = [field('#sp-cta'), field('#sp-link')].filter(Boolean).join('\n');
        var text = [field('#sp-opening'), field('#sp-body'), action, field('[data-sp-disclosure-text]'), hashtags.map(function (tag) { return '#' + tag; }).join(' ')].filter(Boolean).join('\n\n');
        var title = field('#sp-title');

        live.querySelectorAll('[data-sp-live="title"]').forEach(function (node) {
            node.textContent = title || node.dataset.spEmpty || '';
            node.hidden = title === '' && !node.dataset.spEmpty;
        });
        live.querySelectorAll('[data-sp-live="text"]').forEach(function (node) {
            node.innerHTML = text !== '' ? highlight(text) : '<span class="sp-sub">' + escapeHtml(node.dataset.spEmpty || '') + '</span>';
        });
        live.querySelectorAll('[data-sp-live="tags"]').forEach(function (node) {
            node.textContent = 'Tags: ' + keywords.join(', ');
            node.hidden = keywords.length === 0;
        });

        // The choices of the form beside the text: the platform's AI declaration and the visibility.
        var synthetic = form.querySelector('input[type=checkbox][name=synthetic_media]');
        live.querySelectorAll('[data-sp-live="synthetic"]').forEach(function (node) {
            var on = !!synthetic && synthetic.checked && !synthetic.disabled;
            node.textContent = 'Khai báo AI: ' + (on ? 'có' : 'không');
            node.classList.toggle('info', on);
        });
        var privacy = field('#sp-privacy');
        live.querySelectorAll('[data-sp-live="privacy"]').forEach(function (node) {
            node.textContent = privacy !== '' ? ' · ' + privacy : '';
        });

        if (liveState) {
            liveState.textContent = 'Bản đang chỉnh — chưa lưu';
            liveState.classList.add('warn');
        }
    };

    var setDirty = function (next) {
        dirty = next;
        root.querySelectorAll('[data-sp-dirty-note], [data-sp-dirty-dot], [data-sp-dirty-warning]').forEach(function (node) {
            node.hidden = !dirty;
        });
        root.querySelectorAll('[data-sp-needs-clean]').forEach(function (node) {
            if (node.tagName === 'A') {
                node.classList.toggle('sp-blocked', dirty);
                node.setAttribute('aria-disabled', dirty ? 'true' : 'false');
            } else {
                node.disabled = dirty;
            }

            node.title = dirty ? 'Có thay đổi chưa lưu ở tab Nội dung — lưu hoặc hoàn tác trước.' : (node.dataset.spTitle || '');
        });
        renderLive();
    };

    root.querySelectorAll('[data-sp-needs-clean]').forEach(function (node) {
        node.dataset.spTitle = node.title || '';

        if (node.tagName === 'A') {
            node.addEventListener('click', function (event) {
                if (dirty) {
                    event.preventDefault();
                }
            });
        }
    });

    var syncDisclosure = function () {
        root.querySelectorAll('[data-sp-disclosure-toggle]').forEach(function (toggle) {
            var input = toggle.closest('.sp-disclosure').querySelector('[data-sp-disclosure-text]');

            if (input) {
                input.disabled = !toggle.checked || toggle.disabled;
            }
        });
    };
    var syncHooks = function () {
        root.querySelectorAll('.sp-hook input[type=radio]').forEach(function (radio) {
            radio.closest('.sp-hook').classList.toggle('on', radio.checked);
        });
    };
    var check = function () {
        if (form && editable) {
            setDirty(serialise() !== opened);
        }
    };

    if (form && editable) {
        // The hooks sit outside the form and belong to it by their form attribute, so events are read on the whole screen.
        var onEdit = function (event) {
            var target = event.target;

            if (!target || target.form !== form) {
                return;
            }

            if (target.matches('[data-sp-disclosure-toggle]')) {
                syncDisclosure();
            }

            if (target.matches('input[type=radio][name=hook_id]') && target.checked) {
                // Choosing a hook puts its text at the opening; the person may still edit it before saving.
                var opening = form.querySelector('#sp-opening');

                if (opening) {
                    opening.value = target.dataset.spHookText || '';
                    updateCounter(opening);
                }

                syncHooks();
            }

            if (target.matches('[data-sp-count]')) {
                updateCounter(target);
            }

            check();
        };

        root.addEventListener('input', onEdit);
        root.addEventListener('change', onEdit);
        form.addEventListener('submit', function () {
            leaving = true;
        });
        form.addEventListener('reset', function () {
            window.setTimeout(function () {
                counted.forEach(updateCounter);
                syncDisclosure();
                syncHooks();
                check();
            }, 0);
        });
        window.addEventListener('beforeunload', function (event) {
            if (dirty && !leaving) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    }

    // Any other form of the page leaves this revision too: the browser asks while something is not saved.
    root.querySelectorAll('form').forEach(function (other) {
        if (other !== form) {
            other.addEventListener('submit', function (event) {
                if (dirty && !window.confirm('Bạn có thay đổi chưa lưu ở tab Nội dung. Thao tác này tải lại trang và các thay đổi đó sẽ mất. Tiếp tục?')) {
                    event.preventDefault();

                    return;
                }

                leaving = true;
            });
        }
    });

    var leaveTo = function (url) {
        if (dirty && !window.confirm('Bạn có thay đổi chưa lưu ở tab Nội dung. Chuyển sang gói hoặc revision khác sẽ mất các thay đổi đó. Tiếp tục?')) {
            return false;
        }

        leaving = true;
        window.location.href = url;

        return true;
    };

    // ---- The bar: open the package of a channel, format and language, or set the open one aside. ---------------------
    var picker = root.querySelector('[data-sp-picker]');

    if (picker) {
        var packages = {};

        try { packages = JSON.parse(picker.dataset.spPackages || '{}'); } catch (e) { packages = {}; }

        var current = picker.dataset.spCurrent || '';
        var channel = picker.querySelector('[data-sp-pick="channel"]');
        var format = picker.querySelector('[data-sp-pick="format"]');
        var language = picker.querySelector('[data-sp-pick="language"]');
        var icon = picker.querySelector('[data-sp-channel-icon]');
        var openNew = picker.querySelector('[data-sp-open-new]');
        var openLabel = picker.querySelector('[data-sp-open-label]');
        var revision = picker.querySelector('[data-sp-revision]');
        var original = channel && format && language ? { channel: channel.value, format: format.value, language: language.value } : null;
        var last = original ? { channel: original.channel, format: original.format, language: original.language } : null;

        var platformOf = function () {
            var option = channel.options[channel.selectedIndex];

            return option ? option.dataset.platform : '';
        };
        var syncFormats = function () {
            var platform = platformOf();
            var first = null;

            Array.prototype.forEach.call(format.options, function (option) {
                var fits = option.dataset.platform === platform;
                option.hidden = !fits;
                option.disabled = !fits;

                if (fits && first === null) {
                    first = option;
                }
            });

            if ((format.selectedIndex < 0 || format.options[format.selectedIndex].disabled) && first) {
                format.value = first.value;
            }

            if (icon) {
                icon.className = platform === 'facebook' ? 'fab fa-facebook sp-fab-facebook' : (platform === 'youtube' ? 'fab fa-youtube sp-fab-youtube' : 'fas fa-globe');
            }
        };
        var setDetached = function (detached) {
            if (detached) {
                root.setAttribute('data-sp-detached', '');
            } else {
                root.removeAttribute('data-sp-detached');
            }

            if (revision && current !== '') {
                revision.disabled = detached;
            }
        };
        var restore = function (values) {
            channel.value = values.channel;
            syncFormats();
            format.value = values.format;
            language.value = values.language;
        };
        var onPick = function () {
            var id = packages[channel.value + '|' + format.value + '|' + language.value];

            if (id && id !== current) {
                if (!leaveTo(picker.dataset.spUrl + '?package=' + encodeURIComponent(id))) {
                    restore(last);
                }

                return;
            }

            last = { channel: channel.value, format: format.value, language: language.value };

            if (current !== '') {
                // The open package, or a choice that has none: then the work area of the open package is set aside.
                setDetached(id !== current);
            }

            if (openNew) {
                openNew.hidden = id !== undefined && id === current;
            }

            if (openLabel) {
                openLabel.textContent = id ? 'Gói cho lựa chọn này đã có.' : 'Chưa có gói cho lựa chọn này.';
            }
        };

        if (channel && format && language) {
            syncFormats();
            [channel, format, language].forEach(function (select) {
                select.addEventListener('change', function () {
                    if (select === channel) {
                        syncFormats();
                    }

                    onPick();
                });
            });
        }

        root.querySelectorAll('[data-sp-reattach]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (original) {
                    restore(original);
                    last = { channel: original.channel, format: original.format, language: original.language };
                }

                setDetached(false);

                if (openNew) {
                    openNew.hidden = true;
                }
            });
        });

        if (revision) {
            var openedRevision = revision.value;

            revision.addEventListener('change', function () {
                if (!leaveTo(picker.dataset.spUrl + '?package=' + encodeURIComponent(revision.value))) {
                    revision.value = openedRevision;
                }
            });
        }
    }

    // ---- Copying an id or a hash. ---------------------------------------------------------------------------------
    root.querySelectorAll('[data-sp-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!navigator.clipboard) {
                return;
            }

            navigator.clipboard.writeText(button.dataset.spCopy).then(function () {
                var mark = button.querySelector('i');
                mark.className = 'fas fa-check';
                window.setTimeout(function () { mark.className = 'far fa-copy'; }, 1200);
            });
        });
    });

    // ---- Cover: the slider and the milliseconds move together; a frame is only looked at, nothing is stored. ------
    root.querySelectorAll('[data-social-cover-form]').forEach(function (coverForm) {
        var range = coverForm.querySelector('[data-sp-at-range]');
        var number = coverForm.querySelector('[data-social-at]');

        if (range && number) {
            range.addEventListener('input', function () { number.value = range.value; });
            number.addEventListener('input', function () { range.value = number.value; });
        }
    });
    root.querySelectorAll('[data-social-frame-button]').forEach(function (button) {
        button.addEventListener('click', function () {
            var coverForm = button.closest('form');
            var image = coverForm.querySelector('[data-social-frame]');
            var at = coverForm.querySelector('[data-social-at]').value || 0;
            image.hidden = false;
            image.src = button.dataset.socialFrameUrl + '?at_ms=' + encodeURIComponent(at) + '&t=' + Date.now();
        });
    });

    syncDisclosure();
    syncHooks();
    // A form the browser filled back in on a return to the page is unsaved from the start, and says so.
    check();
})();
