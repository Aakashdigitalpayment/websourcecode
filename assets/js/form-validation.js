/**
 * =====================================================
 * AAKASH COOPERATIVE — Universal Form Validation
 * सबै public forms मा automatic लागू हुन्छ
 *
 * Phone: 10 digit, Nepal mobile (9XXXXXXXXX)
 * Email: valid email format
 * Date:  past date check for deadline fields
 *
 * यो file footer.php बाट load हुन्छ
 * =====================================================
 */

(function () {
    'use strict';

    /* =====================================================
       PHONE FIELD VALIDATION
       name="phone", name="mobile", name="bidder_phone",
       name="applicant_phone", name="requester_phone",
       name="contact_phone" — सबैमा लागू
    ===================================================== */
    var PHONE_FIELDS_SELECTOR = [
        'input[name="phone"]',
        'input[name="mobile"]',
        'input[name="bidder_phone"]',
        'input[name="applicant_phone"]',
        'input[name="requester_phone"]',
        'input[name="contact_phone"]',
        'input[name="member_phone"]',
        'input[name="guardian_phone"]',
        'input[name="emergency_contact"]',
        /* अतिरिक्त phone fields — loan/account/career/tracker forms */
        'input[name="guarantor_phone"]',
        'input[name="nominee_phone"]',
        'input[name="sec_phone"]',
        'input[name="witness_phone"]',
        'input[name="family_phone"]',
    ].join(',');

    var EMAIL_FIELDS_SELECTOR = [
        'input[name="email"]',
        'input[name="bidder_email"]',
        'input[name="applicant_email"]',
        'input[name="requester_email"]',
        'input[name="contact_email"]',
    ].join(',');

    /* Phone: only numbers, max 10 digits, must start with 9 */
    function isValidNepalPhone(val) {
        return /^[9][0-9]{9}$/.test(val);
    }

    /* Email: standard email format */
    function isValidEmail(val) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(val);
    }

    /* Show/hide validation feedback message */
    function showFeedback(input, isValid, message) {
        /* Existing feedback div खोज्ने वा नयाँ बनाउने */
        var parent = input.closest('.input-group') || input.parentNode;
        var fb = parent.querySelector('.univ-feedback');
        if (!fb) {
            fb = document.createElement('div');
            fb.className = 'univ-feedback';
            fb.style.cssText = 'font-size:12px; margin-top:3px;';
            fb.setAttribute('role', 'alert');
            parent.parentNode && parent.parentNode.insertBefore(fb, parent.nextSibling);
        }
        if (!fb.id) {
            fb.id = 'univ-fb-' + (input.id || ('f' + Math.random().toString(36).slice(2, 8)));
        }
        input.setAttribute('aria-describedby', fb.id);
        if (isValid) {
            fb.textContent = '';
            fb.style.color = '#198754';
            input.classList.remove('is-invalid');
            input.classList.add('is-valid');
            input.removeAttribute('aria-invalid');
        } else {
            fb.textContent = message;
            fb.style.color = '#dc3545';
            input.classList.remove('is-valid');
            input.classList.add('is-invalid');
            input.setAttribute('aria-invalid', 'true');
        }
    }

    /* Clear validation state */
    function clearFeedback(input) {
        input.classList.remove('is-valid', 'is-invalid');
        input.removeAttribute('aria-invalid');
        var parent = input.closest('.input-group') || input.parentNode;
        var fb = parent.querySelector('.univ-feedback') ||
                 (parent.parentNode && parent.parentNode.querySelector('.univ-feedback'));
        if (fb) fb.textContent = '';
    }

    /* =====================================================
       Phone inputs — attach validation
    ===================================================== */
    document.querySelectorAll(PHONE_FIELDS_SELECTOR).forEach(function (input) {
        /* Contact / optional landline — skip Nepal-mobile-only rule */
        if (input.classList.contains('no-univ-phone') || input.getAttribute('data-univ-phone') === 'off') {
            return;
        }
        /* Skip already-has-pattern inputs — auction.php already handles them */
        /* But still add input event to clean non-numeric chars */

        /* Real-time: number मात्र allow */
        input.addEventListener('input', function () {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);
            if (this.value.length === 0) {
                clearFeedback(this);
            }
        });

        /* Blur: full validation */
        input.addEventListener('blur', function () {
            var val = this.value.trim();
            if (val.length === 0) {
                clearFeedback(this);
                return;
            }
            if (isValidNepalPhone(val)) {
                showFeedback(this, true, '✓');
            } else {
                var msg = document.documentElement.lang === 'ne'
                    ? '९ बाट शुरु हुने १० अंकको मोबाइल नम्बर राख्नुहोस् (जस्तै: 9827157000)'
                    : 'Enter 10-digit Nepal mobile starting with 9 (e.g. 9827157000)';
                showFeedback(this, false, msg);
            }
        });

        /* Add HTML attributes if not already set */
        if (!input.getAttribute('pattern')) {
            input.setAttribute('pattern', '[9][0-9]{9}');
        }
        if (!input.getAttribute('maxlength')) {
            input.setAttribute('maxlength', '10');
        }
        if (!input.getAttribute('minlength')) {
            input.setAttribute('minlength', '10');
        }
        if (!input.getAttribute('inputmode')) {
            input.setAttribute('inputmode', 'numeric');
        }
        if (!input.getAttribute('placeholder') || input.getAttribute('placeholder').trim() === '') {
            input.setAttribute('placeholder', '98XXXXXXXX');
        }
    });

    /* =====================================================
       Email inputs — attach validation
    ===================================================== */
    document.querySelectorAll(EMAIL_FIELDS_SELECTOR).forEach(function (input) {
        input.addEventListener('blur', function () {
            var val = this.value.trim();
            if (val.length === 0) {
                clearFeedback(this);
                return;
            }
            if (isValidEmail(val)) {
                showFeedback(this, true, '✓');
            } else {
                var msg = document.documentElement.lang === 'ne'
                    ? 'सही इमेल ठेगाना राख्नुहोस् (जस्तै: name@example.com)'
                    : 'Enter a valid email address (e.g. name@example.com)';
                showFeedback(this, false, msg);
            }
        });
    });

    /* =====================================================
       Form submit — block if invalid phone/email
    ===================================================== */
    document.querySelectorAll('form').forEach(function (form) {
        /* Skip admin forms — admin/ URL check */
        if (window.location.pathname.includes('/admin/')) return;
        /* Skip search forms */
        if (form.method === 'get') return;
        /* Skip forms with class "no-univ-validate" */
        if (form.classList.contains('no-univ-validate')) return;

        form.addEventListener('submit', function (e) {
            var hasError = false;

            /* Validate all phone fields in this form */
            form.querySelectorAll(PHONE_FIELDS_SELECTOR).forEach(function (inp) {
                if (inp.classList.contains('no-univ-phone') || inp.getAttribute('data-univ-phone') === 'off') {
                    return;
                }
                var val = inp.value.trim();
                if (val && !isValidNepalPhone(val)) {
                    inp.classList.add('is-invalid');
                    inp.setAttribute('aria-invalid', 'true');
                    inp.focus();
                    hasError = true;
                }
            });

            /* Validate all email fields in this form */
            form.querySelectorAll(EMAIL_FIELDS_SELECTOR).forEach(function (inp) {
                var val = inp.value.trim();
                if (val && !isValidEmail(val)) {
                    inp.classList.add('is-invalid');
                    if (!hasError) inp.focus();
                    hasError = true;
                }
            });

            /* Math anti-bot — forms use novalidate so required must be checked in JS too */
            form.querySelectorAll('input.coop-anti-bot-math-input, input[name="math_answer"]').forEach(function (inp) {
                var raw = (inp.value || '').trim();
                var empty = raw === '';
                var badNum = !empty && (Number.isNaN(Number(raw)) || !/^-?\d+$/.test(raw));
                if (empty || badNum || (typeof inp.checkValidity === 'function' && !inp.checkValidity())) {
                    inp.classList.add('is-invalid');
                    if (!hasError) inp.focus();
                    hasError = true;
                } else {
                    inp.classList.remove('is-invalid');
                }
            });

            if (hasError) {
                e.preventDefault();
                form.classList.add('was-validated');
                /* Scroll to first invalid field */
                var firstInvalid = form.querySelector('.is-invalid, :invalid');
                if (firstInvalid) {
                    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    firstInvalid.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
                    try { firstInvalid.focus({ preventScroll: true }); } catch (err) { firstInvalid.focus(); }
                }
                return false;
            }

            /* Bootstrap / other handlers may already have cancelled (empty required fields) */
            if (e.defaultPrevented) {
                return false;
            }

            /* Valid submit — prevent double-click / double POST */
            if (form.getAttribute('data-submitting') === '1') {
                e.preventDefault();
                return false;
            }
            form.setAttribute('data-submitting', '1');
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
                if (btn.disabled) return;
                btn.disabled = true;
                btn.setAttribute('aria-busy', 'true');
                if (btn.tagName === 'BUTTON' && !btn.dataset.origLabel) {
                    btn.dataset.origLabel = btn.innerHTML;
                    var en = document.documentElement.lang === 'en';
                    var isSend = /contact|message|send|chat|inquiry|bid/i.test(form.id + ' ' + (form.className || ''));
                    btn.innerHTML = en
                        ? (isSend ? 'Sending…' : 'Saving…')
                        : (isSend ? 'पठाउँदै…' : 'सुरक्षित गर्दैछ…');
                }
            });
        });
    });

    /* bfcache / back-forward: never leave forms stuck on Saving… */
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-submitting="1"]').forEach(function (form) {
            form.removeAttribute('data-submitting');
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
                if (btn.getAttribute('aria-busy') !== 'true' && !btn.dataset.origLabel && !btn.dataset.origHtml) {
                    return;
                }
                btn.disabled = false;
                btn.removeAttribute('aria-busy');
                if (btn.dataset.origLabel) {
                    btn.innerHTML = btn.dataset.origLabel;
                    delete btn.dataset.origLabel;
                } else if (btn.dataset.origHtml) {
                    btn.innerHTML = btn.dataset.origHtml;
                    delete btn.dataset.origHtml;
                }
            });
        });
        /* Also reset footer-spinner-only busy buttons (no data-submitting) */
        document.querySelectorAll('button[type="submit"][aria-busy="true"], input[type="submit"][aria-busy="true"]').forEach(function (btn) {
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
            if (btn.dataset.origHtml) {
                btn.innerHTML = btn.dataset.origHtml;
                delete btn.dataset.origHtml;
            } else if (btn.dataset.origLabel) {
                btn.innerHTML = btn.dataset.origLabel;
                delete btn.dataset.origLabel;
            }
        });
    });

    /* =====================================================
       Date fields — past date warning for deadline-style
       inputs with name="deadline" or data-type="future"
    ===================================================== */
    document.querySelectorAll('input[type="date"]').forEach(function (input) {
        input.addEventListener('blur', function () {
            /* यदि date field मा "future" data-type छ भने past dates block गर्ने */
            if (this.dataset.type !== 'future') return;
            var val = this.value;
            if (!val) return;
            if (new Date(val) < new Date()) {
                var msg = document.documentElement.lang === 'ne'
                    ? 'भविष्यको मिति छान्नुहोस्'
                    : 'Please select a future date';
                showFeedback(this, false, msg);
            } else {
                clearFeedback(this);
            }
        });
    });

})();
