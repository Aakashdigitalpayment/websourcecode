/* assets/js/init-uniformity.js
   Purpose: Small, conservative runtime fixes that are non-destructive:
   - Ensure inputs marked data-calendar="bs" get Nepali datepicker-ready class
   - Initialize NepaliDatePicker jQuery plugin if present (idempotent)
   - Conservative accessibility tweak: mark icon-only controls' icons aria-hidden
*/
(function(){
    'use strict';

    function initDatepickers() {
        try {
            // Convert BS calendar inputs to text + mark for datepicker
            document.querySelectorAll('input[type="date"][data-calendar="bs"]:not([data-ndp-auto-done])').forEach(function(inp){
                inp.dataset.ndpAutoDone = '1';
                try { inp.type = 'text'; } catch(e) {}
                inp.classList.add('nepali-datepicker');
                if (!inp.getAttribute('placeholder')) inp.setAttribute('placeholder','YYYY-MM-DD');
                inp.setAttribute('autocomplete','off');
            });

            // If jQuery + plugin present, initialize (idempotent)
            if (typeof window.jQuery !== 'undefined' && window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.nepaliDatePicker === 'function') {
                (function($){
                    var inputs = $('.nepali-datepicker');
                    inputs.each(function(){
                        var $inp = $(this);
                        if ($inp.data('ndp-ready')) return;
                        $inp.data('ndp-ready', true);
                        try {
                            $inp.nepaliDatePicker({ dateFormat: 'YYYY-MM-DD', language: 'nepali' });
                        } catch (e) {
                            // plugin init failed — leave silently
                            console && console.debug && console.debug('nepaliDatePicker init failed', e);
                        }

                        // calendar trigger clicks should focus the input
                        $inp.closest('.input-group, .nepali-datepicker-wrapper')
                            .find('.ndp-trigger, .input-group-text')
                            .off('click.ndp')
                            .on('click.ndp', function(){ $inp.trigger('focus'); });
                    });
                })(window.jQuery);
            }
        } catch (err) {
            console && console.error && console.error('init-uniformity datepicker error', err);
        }
    }

    function iconA11ySafety() {
        try {
            ['button','a'].forEach(function(tag){
                document.querySelectorAll(tag).forEach(function(el){
                    // If element contains any visible text nodes, skip
                    var hasText = Array.from(el.childNodes).some(function(n){
                        return n.nodeType === 3 && n.textContent && n.textContent.trim() !== '';
                    });
                    if (hasText) return;

                    // If only icon present, mark icon aria-hidden so screen readers skip it
                    var ico = el.querySelector('i, svg');
                    if (ico && !ico.hasAttribute('aria-hidden')) {
                        ico.setAttribute('aria-hidden', 'true');
                    }
                });
            });
        } catch (err) {
            console && console.error && console.error('init-uniformity a11y error', err);
        }
    }

    document.addEventListener('DOMContentLoaded', function(){
        initDatepickers();
        iconA11ySafety();

        // Also run a second pass shortly after to catch late-inserted nodes
        setTimeout(function(){ initDatepickers(); iconA11ySafety(); }, 600);
    });

    // Expose a safe global hook for dynamic content loaders
    window.__coop_initUniformity = function() { initDatepickers(); iconA11ySafety(); };
})();
