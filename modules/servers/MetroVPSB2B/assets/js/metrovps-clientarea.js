/**
 * MetroVPS B2B — Client Area Dashboard
 *
 * Drives the VPS dashboard rendered on clientarea.php?action=productdetails
 * (both the Overview widget and the Manage-tab dashboard):
 *   - root password reveal toggle
 *   - copy-to-clipboard
 *   - action buttons (poweron / poweroff / restart / resetpassword / reinstall)
 *   - reinstall modal with OS template dropdown
 *
 * Delegated events only; safe to include more than once per page.
 */
(function () {
    'use strict';

    if (window.MetroVPSB2BClientArea) {
        return;
    }
    window.MetroVPSB2BClientArea = true;

    var ENDPOINT = '/modules/servers/MetroVPSB2B/client.php';

    /* ---------------------------------------------------------------- */
    /* Toast                                                             */
    /* ---------------------------------------------------------------- */

    function toast(message, type) {
        var wrap = document.getElementById('metrovps-toasts');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.id = 'metrovps-toasts';
            document.body.appendChild(wrap);
        }

        var el = document.createElement('div');
        el.className = 'metrovps-toast metrovps-toast--' + (type || 'info');
        el.textContent = message;

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'metrovps-toast-close';
        close.setAttribute('aria-label', 'Dismiss');
        close.innerHTML = '&times;';
        close.addEventListener('click', function () { el.remove(); });

        el.appendChild(close);
        wrap.appendChild(el);

        setTimeout(function () { el.remove(); }, 6000);
    }

    /* ---------------------------------------------------------------- */
    /* Password toggle                                                   */
    /* ---------------------------------------------------------------- */

    function togglePassword(trigger) {
        var target = document.getElementById(trigger.getAttribute('data-target'));
        if (!target) {
            return;
        }

        var password = target.getAttribute('data-password');

        if (target.classList.contains('metrovps-password-visible')) {
            target.classList.remove('metrovps-password-visible');
            target.textContent = '';
            target.appendChild(document.createTextNode('\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022'));
        } else {
            target.classList.add('metrovps-password-visible');
            target.textContent = '';
            target.appendChild(document.createTextNode(JSON.parse(password || '""')));
        }

        var eye = trigger.querySelector('.metrovps-password-eye');
        if (eye) {
            var revealing = target.classList.contains('metrovps-password-visible');
            eye.classList.toggle('fa-eye', !revealing);
            eye.classList.toggle('fa-eye-slash', revealing);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Copy to clipboard                                                 */
    /* ---------------------------------------------------------------- */

    function copyValue(trigger) {
        var value = trigger.getAttribute('data-copy-value') || '';

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(function () {
                toast('Copied to clipboard', 'success');
            }, function () {
                fallbackCopy(value);
            });
        } else {
            fallbackCopy(value);
        }
    }

    function fallbackCopy(value) {
        var input = document.createElement('textarea');
        input.value = value;
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        try {
            document.execCommand('copy');
            toast('Copied to clipboard', 'success');
        } catch (err) {
            toast('Copy failed — select the text manually.', 'error');
        }
        input.remove();
    }

    /* ---------------------------------------------------------------- */
    /* Action buttons                                                    */
    /* ---------------------------------------------------------------- */

    function setLoading(button, loading) {
        button.disabled = !!loading;

        if (loading) {
            button.setAttribute('data-original-icon', button.innerHTML);
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + (button.getAttribute('data-loading-text') || 'Working...');
        } else if (button.hasAttribute('data-original-icon')) {
            button.innerHTML = button.getAttribute('data-original-icon');
            button.removeAttribute('data-original-icon');
        }
    }

    function performAction(button) {
        var action = button.getAttribute('data-metrovps-action');
        var serviceId = button.getAttribute('data-serviceid');

        var payload = new URLSearchParams();
        payload.append('action', action);
        payload.append('id', serviceId);

        setLoading(button, true);

        fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: payload
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                setLoading(button, false);

                if (data && data.success) {
                    toast(data.message || 'Action completed successfully.', 'success');

                    if (action === 'poweron' || action === 'poweroff' || action === 'restart') {
                        lockPowerBarsAfterAction();
                        setTransientStatus(action, serviceId);
                    }
                } else {
                    toast((data && data.error) || 'Action failed. Please try again.', 'error');
                }
            })
            .catch(function () {
                setLoading(button, false);
                toast('Network error — please try again.', 'error');
            });
    }

    /* ---------------------------------------------------------------- */
    /* Reinstall modal                                                   */
    /* ---------------------------------------------------------------- */

    var modalOpen = false;

    function openModal(button) {
        var modalId = (button.getAttribute('data-modal') || '').replace(/^#/, '');
        var modal = document.getElementById(modalId);
        if (!modal) {
            return;
        }

        modal.style.display = 'flex';
        modalOpen = true;
        loadTemplates(modal, button.getAttribute('data-serviceid'));
    }

    function closeModal(modal) {
        modal.style.display = 'none';
        modalOpen = false;
    }

    function loadTemplates(modal, serviceId) {
        var select = modal.querySelector('[data-metrovps-os-select]');

        if (!select || select.dataset.loaded === '1') {
            return;
        }

        select.dataset.loaded = '1';
        select.innerHTML = '<option value="">Loading operating systems…</option>';

        fetch(ENDPOINT + '?action=ostemplates&id=' + encodeURIComponent(serviceId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                select.innerHTML = '';

                if (!data || !data.success || !Array.isArray(data.templates) || data.templates.length === 0) {
                    var empty = document.createElement('option');
                    empty.value = '';
                    empty.textContent = 'No templates available';
                    select.appendChild(empty);
                    return;
                }

                var placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Select operating system…';
                select.appendChild(placeholder);

                data.templates.forEach(function (tpl) {
                    var opt = document.createElement('option');
                    opt.value = tpl.id;
                    opt.textContent = tpl.name;
                    select.appendChild(opt);
                });
            })
            .catch(function () {
                select.innerHTML = '<option value="">Failed to load templates</option>';
            });
    }

    function confirmReinstall(button) {
        var select = button.closest('.metrovps-modal').querySelector('[data-metrovps-os-select]');

        if (!select || !select.value) {
            toast('Please select an operating system first.', 'error');
            return;
        }

        var action = button.getAttribute('data-metrovps-action');
        var serviceId = button.getAttribute('data-serviceid');

        var payload = new URLSearchParams();
        payload.append('action', action);
        payload.append('id', serviceId);
        payload.append('os_id', select.value);

        setLoading(button, true);

        fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: payload
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                setLoading(button, false);
                closeModal(button.closest('.metrovps-modal'));

                if (data && data.success) {
                    toast(data.message || 'Reinstall started.', 'success');
                    lockRebuildBarsAfterAction();
                    applyStateToPage(null, 'installing');
                    pollState(serviceId, true);
                } else {
                    toast((data && data.error) || 'Reinstall failed.', 'error');
                }
            })
            .catch(function () {
                setLoading(button, false);
                toast('Network error — please try again.', 'error');
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initPowerLocks(60);
            checkTransientOnLoad();
        });
    } else {
        initPowerLocks(60);
        checkTransientOnLoad();
    }

    /* ---------------------------------------------------------------- */
    /* Power-action cooldown lock                                        */
    /* ---------------------------------------------------------------- */

    function lockPowerBar(bar, seconds, lockReinstall) {
        lockReinstall = !!lockReinstall;
        var powerButtons = bar.querySelectorAll('[data-metrovps-action="poweron"], [data-metrovps-action="poweroff"], [data-metrovps-action="restart"]');

        var buttons = Array.prototype.slice.call(powerButtons);

        if (lockReinstall) {
            var reinstallButtons = bar.querySelectorAll('[data-metrovps-action="reinstall-modal"]');
            Array.prototype.forEach.call(reinstallButtons, function (btn) {
                buttons.push(btn);
            });
        }

        if (!buttons.length) {
            return;
        }

        var indicator = bar.querySelector('.metrovps-lock-note');
        if (!indicator) {
            indicator = document.createElement('span');
            indicator.className = 'metrovps-lock-note';
            bar.appendChild(indicator);
        }

        var remaining = seconds;

        function render() {
            indicator.textContent = remaining > 0
                ? (lockReinstall ? 'Actions locked: ' : 'Power actions locked: ') + remaining + 's'
                : '';
        }

        buttons.forEach(function (btn) {
            btn.disabled = true;
            btn.classList.add('metrovps-btn--locked');
        });

        render();

        if (bar._metrovpsLockTimer) {
            clearInterval(bar._metrovpsLockTimer);
        }

        bar._metrovpsLockTimer = setInterval(function () {
            remaining--;

            if (remaining <= 0) {
                clearInterval(bar._metrovpsLockTimer);
                bar._metrovpsLockTimer = null;

                buttons.forEach(function (btn) {
                    btn.disabled = false;
                    btn.classList.remove('metrovps-btn--locked');
                });

                if (lastRemoteState !== null) {
                    updatePowerToggle(lastRemoteState === 'running');
                }

                render();
                return;
            }

            render();
        }, 1000);
    }

    function initPowerLocks() {
        document.querySelectorAll('.metrovps-actionbar[data-metrovps-lock-seconds]').forEach(function (bar) {
            var seconds = parseInt(bar.getAttribute('data-metrovps-lock-seconds'), 10) || 0;
            if (seconds > 0) {
                lockPowerBar(bar, seconds, false);
            }
        });

        document.querySelectorAll('.metrovps-actionbar[data-metrovps-rebuild-lock]').forEach(function (bar) {
            var seconds = parseInt(bar.getAttribute('data-metrovps-rebuild-lock'), 10) || 0;
            if (seconds > 0) {
                lockPowerBar(bar, seconds, true);
            }
        });
    }

    function lockPowerBarsAfterAction() {
        document.querySelectorAll('.metrovps-actionbar').forEach(function (bar) {
            lockPowerBar(bar, 60, false);
        });
    }

    function lockRebuildBarsAfterAction() {
        document.querySelectorAll('.metrovps-actionbar').forEach(function (bar) {
            lockPowerBar(bar, 180, true);
        });
    }

    /* ---------------------------------------------------------------- */
    /* Live state refresh while a power/rebuild action is in flight       */
    /* ---------------------------------------------------------------- */

    var TRANSITIONS = ['starting', 'restarting', 'stopping', 'installing'];

    var STATE_MAP = {
        running:    { cls: 'metrovps-badge--power',      icon: 'fa-play',            label: 'Running' },
        stopped:    { cls: 'metrovps-badge--stopped',    icon: 'fa-stop',            label: 'Stopped' },
        starting:   { cls: 'metrovps-badge--installing', icon: 'fa-spinner fa-pulse', label: 'Starting' },
        restarting: { cls: 'metrovps-badge--installing', icon: 'fa-sync-alt fa-spin', label: 'Restarting' },
        stopping:   { cls: 'metrovps-badge--installing', icon: 'fa-spinner fa-pulse', label: 'Stopping' },
        installing: { cls: 'metrovps-badge--installing', icon: 'fa-sync-alt fa-spin', label: 'Installing' }
    };

    var LIFECYCLE_MAP = {
        active:     { cls: 'metrovps-badge--active',     icon: 'fa-check-circle',   label: 'Active' },
        processing: { cls: 'metrovps-badge--processing', icon: 'fa-spinner fa-pulse', label: 'Provisioning' },
        suspended:  { cls: 'metrovps-badge--suspended',  icon: 'fa-pause-circle',   label: 'Suspended' },
        inactive:   { cls: 'metrovps-badge--unknown',    icon: 'fa-minus-circle',   label: 'Inactive' },
        terminated: { cls: 'metrovps-badge--unknown',    icon: 'fa-ban',            label: 'Terminated' },
        failed:     { cls: 'metrovps-badge--failed',     icon: 'fa-times-circle',   label: 'Failed' }
    };

    var statePollTimer = null;
    var statePollCount = 0;
    var lastRemoteState = null;

    function setBadge(el, entry, rawState) {
        var badge = el.closest('.metrovps-badge') || el;
        badge.className = 'metrovps-badge ' + entry.cls;
        el.innerHTML = '<i class="fas ' + entry.icon + '"></i> ' + entry.label;
        el.setAttribute('data-state', rawState || '');
    }

    function applyStateToPage(status, remoteState) {
        if (remoteState !== undefined && remoteState !== null) {
            lastRemoteState = remoteState;
        }

        var suspended = status === 'suspended';

        // Suspended: hide the power-state badge (the lifecycle badge carries
        // the Suspended label) and disable every action button.
        document.querySelectorAll('[data-metrovps-state-badge]').forEach(function (el) {
            var badge = el.closest('.metrovps-badge') || el;
            badge.style.display = suspended ? 'none' : '';
        });

        if (suspended) {
            document.querySelectorAll('.metrovps-actionbar [data-metrovps-action]').forEach(function (btn) {
                btn.disabled = true;
                btn.classList.add('metrovps-btn--locked');
            });
        }

        var stateEntry = STATE_MAP[remoteState];

        if (stateEntry && !suspended) {
            document.querySelectorAll('[data-metrovps-state-badge]').forEach(function (el) {
                setBadge(el, stateEntry, remoteState);
            });
        }

        var lifeEntry = LIFECYCLE_MAP[status];

        if (lifeEntry) {
            document.querySelectorAll('[data-metrovps-lifecycle-badge]').forEach(function (el) {
                setBadge(el, lifeEntry, status);
            });
        }
    }

    function updatePowerToggle(running) {
        document.querySelectorAll('[data-metrovps-action="poweron"], [data-metrovps-action="poweroff"]').forEach(function (btn) {
            if (btn.classList.contains('metrovps-btn--locked')) {
                return;
            }

            if (running) {
                btn.setAttribute('data-metrovps-action', 'poweroff');
                btn.classList.remove('metrovps-btn--success');
                btn.classList.add('metrovps-btn--danger');
                btn.innerHTML = '<i class="fas fa-power-off"></i> Power Off';
            } else {
                btn.setAttribute('data-metrovps-action', 'poweron');
                btn.classList.remove('metrovps-btn--danger');
                btn.classList.add('metrovps-btn--success');
                btn.innerHTML = '<i class="fas fa-power-off"></i> Power On';
            }
        });
    }

    function stopStatePolling() {
        if (statePollTimer) {
            clearInterval(statePollTimer);
            statePollTimer = null;
        }
    }

    function pollState(serviceId, immediate) {
        if (!serviceId) {
            return;
        }

        stopStatePolling();
        statePollCount = 0;

        var tick = function () {
            statePollCount++;

            // ~5 minutes without settling is enough — stop silently.
            if (statePollCount > 30) {
                stopStatePolling();
                return;
            }

            fetch(ENDPOINT + '?action=state&id=' + encodeURIComponent(serviceId), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || !data.success) {
                        return;
                    }

                    applyStateToPage(data.status, data.remote_state);
                    updatePowerToggle(data.remote_state === 'running');

                    if (TRANSITIONS.indexOf(data.remote_state) === -1) {
                        stopStatePolling();
                    }
                })
                .catch(function () { /* keep polling */ });
        };

        if (immediate) {
            tick();
        }

        statePollTimer = setInterval(tick, 10000);
    }

    function transientStatusFromAction(action) {
        return action === 'poweron' ? 'starting' : (action === 'poweroff' ? 'stopping' : 'restarting');
    }

    function setTransientStatus(action, serviceId) {
        var state = transientStatusFromAction(action);

        applyStateToPage(null, state);

        if (serviceId) {
            pollState(serviceId, true);
        }
    }

    function checkTransientOnLoad() {
        var transient = false;

        document.querySelectorAll('[data-metrovps-state-badge][data-state]').forEach(function (el) {
            if (TRANSITIONS.indexOf(el.getAttribute('data-state')) !== -1) {
                transient = true;
            }
        });

        if (!transient) {
            return;
        }

        var firstActionButton = document.querySelector('[data-metrovps-action][data-serviceid]');
        if (firstActionButton) {
            pollState(firstActionButton.getAttribute('data-serviceid'), false);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Delegated events                                                  */
    /* ---------------------------------------------------------------- */

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && modalOpen) {
            var modal = document.querySelector('.metrovps-modal[style*="flex"]');
            if (modal) {
                closeModal(modal);
            }
        }
    });

    document.addEventListener('click', function (ev) {
        var closeBtn = ev.target.closest('.metrovps-modal-close, .metrovps-modal-cancel');
        if (closeBtn) {
            var modal = closeBtn.closest('.metrovps-modal');
            if (modal) {
                closeModal(modal);
            }
            return;
        }

        var trigger = ev.target.closest('[data-metrovps-toggle-password], [data-metrovps-copy], [data-metrovps-action]');
        if (!trigger) {
            return;
        }

        ev.preventDefault();

        if (trigger.hasAttribute('data-metrovps-toggle-password')) {
            togglePassword(trigger);
            return;
        }

        if (trigger.hasAttribute('data-metrovps-copy')) {
            copyValue(trigger);
            return;
        }

        var action = trigger.getAttribute('data-metrovps-action');

        if (action === 'reinstall-modal') {
            openModal(trigger);
            return;
        }

        if (action === 'reinstall') {
            confirmReinstall(trigger);
            return;
        }

        if (action === 'resetpassword') {
            if (!window.confirm('Reset the root password for this VPS? The current password will stop working.')) {
                return;
            }
        }

        if (action === 'poweroff' || action === 'restart') {
            if (!window.confirm((action === 'poweroff' ? 'Power off' : 'Restart') + ' this VPS?')) {
                return;
            }
        }

        performAction(trigger);
    });

    // Close modal on backdrop click
    document.addEventListener('click', function (ev) {
        if (!ev.target.classList || !ev.target.classList.contains('metrovps-modal')) {
            return;
        }
        closeModal(ev.target);
    });
})();