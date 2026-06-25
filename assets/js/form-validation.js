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
            parent.parentNode && parent.parentNode.insertBefore(fb, parent.nextSibling);
        }
        if (isValid) {
            fb.textContent = '';
            fb.style.color = '#198754';
            input.classList.remove('is-invalid');
            input.classList.add('is-valid');
        } else {
            fb.textContent = message;
            fb.style.color = '#dc3545';
            input.classList.remove('is-valid');
            input.classList.add('is-invalid');
        }
    }

    /* Clear validation state */
    function clearFeedback(input) {
        input.classList.remove('is-valid', 'is-invalid');
        var parent = input.closest('.input-group') || input.parentNode;
        var fb = parent.querySelector('.univ-feedback') ||
                 (parent.parentNode && parent.parentNode.querySelector('.univ-feedback'));
        if (fb) fb.textContent = '';
    }

    /* =====================================================
       Phone inputs — attach validation
    ===================================================== */
    document.querySelectorAll(PHONE_FIELDS_SELECTOR).forEach(function (input) {
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
                var val = inp.value.trim();
                if (val && !isValidNepalPhone(val)) {
                    inp.classList.add('is-invalid');
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

            if (hasError) {
                e.preventDefault();
                /* Scroll to first invalid field */
                var firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) {
                    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return false;
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
