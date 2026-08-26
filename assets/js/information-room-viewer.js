/**
 * Information Room — copy / screenshot deterrence (best-effort)
 */
(function () {
    'use strict';

    var wrap = document.getElementById('irViewerWrap');
    if (!wrap || !wrap.classList.contains('ir-restricted')) {
        return;
    }

    document.addEventListener('contextmenu', function (e) {
        if (wrap.contains(e.target)) {
            e.preventDefault();
        }
    });

    document.addEventListener('copy', function (e) {
        if (wrap.contains(e.target)) {
            e.preventDefault();
        }
    });

    document.addEventListener('cut', function (e) {
        if (wrap.contains(e.target)) {
            e.preventDefault();
        }
    });

    document.addEventListener('dragstart', function (e) {
        if (wrap.contains(e.target)) {
            e.preventDefault();
        }
    });

    document.addEventListener('keydown', function (e) {
        var key = (e.key || '').toLowerCase();
        var blocked = key === 'printscreen'
            || (e.ctrlKey && ['s', 'p', 'c', 'u', 'a'].indexOf(key) >= 0)
            || (e.metaKey && ['s', 'p', 'c', 'a'].indexOf(key) >= 0)
            || (e.ctrlKey && e.shiftKey && ['i', 'j', 'c'].indexOf(key) >= 0)
            || (e.metaKey && e.altKey && key === 'i');
        if (blocked) {
            e.preventDefault();
        }
    });

    /* Blur content briefly when tab hidden (discourages casual screen share peek) */
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
