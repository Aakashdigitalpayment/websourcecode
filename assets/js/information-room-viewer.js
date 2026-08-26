/**
 * Information Room — copy / screenshot deterrence (best-effort)
 */
(function () {
    'use strict';

    var wrap = document.getElementById('irViewerWrap');
    if (!wrap || !wrap.classList.contains('ir-restricted')) {
        return;
    }

    function insideWrap(target) {
        return target && wrap.contains(target);
    }

    document.addEventListener('contextmenu', function (e) {
        if (insideWrap(e.target)) {
            e.preventDefault();
        }
    });

    document.addEventListener('copy', function (e) {
        if (insideWrap(e.target)) {
            e.preventDefault();
        }
    });

    document.addEventListener('cut', function (e) {
        if (insideWrap(e.target)) {
            e.preventDefault();
        }
    });

    document.addEventListener('dragstart', function (e) {
        if (insideWrap(e.target)) {
            e.preventDefault();
        }
    });

    document.addEventListener('keydown', function (e) {
        var active = document.activeElement;
        if (!insideWrap(e.target) && !insideWrap(active)) {
            return;
        }
        var key = (e.key || '').toLowerCase();
        var blocked = key === 'printscreen'
            || (e.ctrlKey && ['s', 'p', 'c', 'u', 'a'].indexOf(key) >= 0)
            || (e.metaKey && ['s', 'p', 'c', 'a'].indexOf(key) >= 0);
        if (blocked) {
            e.preventDefault();
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            wrap.classList.add('ir-blurred');
        } else {
            wrap.classList.remove('ir-blurred');
        }
    });

    var imgs = wrap.querySelectorAll('img');
    imgs.forEach(function (img) {
        img.setAttribute('draggable', 'false');
    });
})();
