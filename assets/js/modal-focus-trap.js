/**
 * Modal focus trap — additive a11y (Tab cycle + restore focus on close).
 * Targets: search modal, notice popup, public chat, AI chat.
 * Does not replace existing open/close handlers.
 */
(function () {
    'use strict';

    var FOCUSABLE =
        'a[href],button:not([disabled]),textarea:not([disabled]),' +
        'input:not([disabled]):not([type="hidden"]),select:not([disabled]),' +
        '[tabindex]:not([tabindex="-1"])';

    var active = null;

    function isFocusable(el) {
        if (!el || el.disabled || el.getAttribute('aria-hidden') === 'true') {
            return false;
        }
        var style = window.getComputedStyle(el);
        if (style.visibility === 'hidden' || style.display === 'none') {
            return false;
        }
        return true;
    }

    function getFocusables(container) {
        return Array.prototype.slice.call(container.querySelectorAll(FOCUSABLE)).filter(isFocusable);
    }

    function trapTab(e, container) {
        if (e.key !== 'Tab') {
            return;
        }
        var list = getFocusables(container);
        if (!list.length) {
            e.preventDefault();
            return;
        }
        var first = list[0];
        var last = list[list.length - 1];
        if (e.shiftKey) {
            if (document.activeElement === first || !container.contains(document.activeElement)) {
                e.preventDefault();
                last.focus();
            }
        } else if (document.activeElement === last || !container.contains(document.activeElement)) {
            e.preventDefault();
            first.focus();
        }
    }

    function FocusTrap(container, preferSelector) {
        this.container = container;
        this.preferSelector = preferSelector || '';
        this.previous = null;
        this.onKeyDown = null;
    }

    FocusTrap.prototype.activate = function () {
        if (active && active !== this) {
            active.deactivate(false);
        }
        active = this;
        this.previous = document.activeElement;
        this.onKeyDown = function (e) {
            trapTab(e, this.container);
        }.bind(this);
        document.addEventListener('keydown', this.onKeyDown, true);

        if (this.container.contains(document.activeElement)) {
            return;
        }
        var prefer = this.preferSelector ? this.container.querySelector(this.preferSelector) : null;
        if (prefer && isFocusable(prefer)) {
            prefer.focus();
            return;
        }
        var list = getFocusables(this.container);
        if (list.length) {
            list[0].focus();
        }
    };

    FocusTrap.prototype.deactivate = function (restoreFocus) {
        if (this.onKeyDown) {
            document.removeEventListener('keydown', this.onKeyDown, true);
            this.onKeyDown = null;
        }
        if (active === this) {
            active = null;
        }
        if (restoreFocus !== false && this.previous && typeof this.previous.focus === 'function') {
            try {
                if (document.body.contains(this.previous)) {
                    this.previous.focus();
                }
            } catch (err) { /* ignore */ }
        }
        this.previous = null;
    };

    function watchModal(root, isOpen, getContainer, preferSelector) {
        if (!root) {
            return;
        }
        var trap = null;
        var container = null;

        function sync() {
            var open = isOpen(root);
            if (open && !trap) {
                container = getContainer ? getContainer(root) : root;
                if (!container) {
                    return;
                }
                trap = new FocusTrap(container, preferSelector);
                trap.activate();
            } else if (!open && trap) {
                trap.deactivate(true);
                trap = null;
            }
        }

        var observer = new MutationObserver(sync);
        observer.observe(root, { attributes: true, attributeFilter: ['class'] });
        sync();
    }

    document.addEventListener('DOMContentLoaded', function () {
        watchModal(
            document.getElementById('searchModal'),
            function (el) { return el.classList.contains('active'); },
            function (el) { return el.querySelector('.search-modal-content') || el; },
            '#searchInput'
        );

        watchModal(
            document.getElementById('noticePopup'),
            function (el) { return el.classList.contains('show'); },
            function (el) { return el.querySelector('.popup-dialog') || el; },
            '#popupClose'
        );

        watchModal(
            document.getElementById('publicChatPanel'),
            function (el) { return el.classList.contains('open'); },
            null,
            'input[name="name"]'
        );

        watchModal(
            document.getElementById('aiChatPanel'),
            function (el) { return el.classList.contains('open'); },
            null,
            '#aiChatInput'
        );
    });
})();
