/**
 * Enhanced Search — Voice + Type + Suggestions
 * File: assets/js/search-improved.js
 *
 * Features:
 *   - Voice search (Web Speech API) — Nepali + English
 *   - Big mic button + inline mic button both work
 *   - Listening status bar with wave animation
 *   - Icon ring toggles between search/mic
 *   - Auto-submit after voice result (1.5s grace period)
 *   - Live suggestions dropdown with fuzzy match
 *   - Recent searches (localStorage मा save हुन्छ)
 *   - Keyboard navigation (↑ ↓ Enter Escape)
 *   - Mobile responsive
 */

(function () {
    'use strict';

    /* ─── Recent searches ─────────────────────────── */
    var STORAGE_KEY = 'coop_recent_searches';
    var MAX_RECENT  = 6;

    function getRecentSearches() {
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]'); }
        catch (e) { return []; }
    }
    function saveRecentSearch(q) {
        if (!q || q.length < 2) return;
        var list = getRecentSearches().filter(function (s) { return s !== q; });
        list.unshift(q);
        list = list.slice(0, MAX_RECENT);
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(list)); } catch (e) {}
    }
    function removeRecentSearch(q) {
        var list = getRecentSearches().filter(function (s) { return s !== q; });
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(list)); } catch (e) {}
    }

    /* ─── Site pages list for suggestions ─────────── */
    var SITE_PAGES = [
        { title: 'हाम्रो बारेमा / About Us',        url: 'about.php',              keywords: ['about','बारेमा','history','itihas'] },
        { title: 'सेवाहरू / Services',               url: 'services.php',           keywords: ['services','seva','loan','bachat'] },
        { title: 'ब्याज दर / Interest Rates',        url: 'interest-rates.php',     keywords: ['interest','byaj','rate','dar'] },
        { title: 'सूचनाहरू / Notices',               url: 'notices.php',            keywords: ['notice','suchana'] },
        { title: 'ग्यालरी / Gallery',                url: 'gallery.php',            keywords: ['gallery','photo','taswir'] },
        { title: 'समाचार / News',                    url: 'news.php',               keywords: ['news','samachar'] },
        { title: 'टोली / Team',                      url: 'team.php',               keywords: ['team','member','sadsya'] },
        { title: 'रोजगारी / Career',                 url: 'career.php',             keywords: ['career','job','rojgari','vacancy'] },
        { title: 'प्रतिवेदन / Reports',              url: 'reports.php',            keywords: ['report','pratibedan'] },
        { title: 'डाउनलोड / Downloads',              url: 'downloads.php',          keywords: ['download','form'] },
        { title: 'प्रश्नोत्तर / FAQs',               url: 'faqs.php',               keywords: ['faq','question','prasna'] },
        { title: 'गुनासो / Grievance',               url: 'grievance.php',          keywords: ['grievance','gunaso','complaint'] },
        { title: 'सम्पर्क / Contact',                url: 'contact.php',            keywords: ['contact','sampark','phone'] },
        { title: 'अनलाइन KYC',                       url: 'online-kyc.php',         keywords: ['kyc','online'] },
        { title: 'ऋण आवेदन / Loan Apply',            url: 'loan-apply.php',         keywords: ['loan','rin','apply'] },
        { title: 'EMI Calculator',                   url: 'emi-calculator.php',     keywords: ['emi','calculator','kisti'] },
        { title: 'विनिमय दर / Exchange Rate',        url: 'exchange-rate.php',      keywords: ['exchange','rate','dollar'] },
        { title: 'मिति परिवर्तक / Date Converter',   url: 'date-converter.php',     keywords: ['date','calendar','miti'] },
        { title: 'खाता खोल्नुहोस् / Open Account',   url: 'online-account.php',     keywords: ['account','khata','open'] },
        { title: 'भेटघाट / Appointment',             url: 'appointment.php',        keywords: ['appointment','bhetghat'] },
        { title: 'लिलामी / Auction',                 url: 'auction.php',            keywords: ['auction','lilami'] },
        { title: 'डिजिटल सेवा / Digital Services',   url: 'digital-services.php',   keywords: ['digital','service'] },
        { title: 'आवेदन ट्र्याक / Track Application',url: 'application-tracker.php',keywords: ['track','tracker','application','awadan'] },
        { title: 'शाखाहरू / Branches',               url: 'service-centers.php',    keywords: ['branch','shakha','location'] },
        { title: 'सदस्य कल्याण / Member Welfare',    url: 'member-welfare.php',     keywords: ['welfare','kalyan','member'] },
        { title: 'समिति / Committee',                url: 'committees.php',         keywords: ['committee','samiti'] },
    ];

    /* ─── Transliteration map: Roman → Nepali keywords ── */
    var TRANSLIT = {
        'byaj':'ब्याज', 'byajdar':'ब्याजदर', 'khata':'खाता', 'khola':'खोल्नुहोस्',
        'loan':'ऋण', 'rin':'ऋण', 'bachat':'बचत', 'besement':'बचत',
        'sewa':'सेवा', 'seva':'सेवा', 'samiti':'समिति', 'shakha':'शाखा',
        'branch':'शाखा', 'samachar':'समाचार', 'galary':'ग्यालरी', 'gallery':'ग्यालरी',
        'sampark':'सम्पर्क', 'contact':'सम्पर्क', 'karya':'कार्य', 'report':'प्रतिवेदन',
        'form':'फारम', 'download':'डाउनलोड', 'job':'रोजगारी', 'vacancy':'रिक्तता',
        'rojgari':'रोजgari', 'team':'टोली', 'sadsya':'सदस्य', 'member':'सदस्य',
        'news':'समाचार', 'notice':'सूचना', 'suchana':'सूचना', 'faq':'प्रश्न',
        'kyc':'kyc', 'emi':'emi', 'tracker':'ट्र्याकर', 'track':'ट्र्याकिङ',
        'auction':'लिलामी', 'lilami':'लिलामी', 'welfare':'कल्याण', 'kalyan':'कल्याण',
        'baremaa':'बारेमा', 'about':'बारेमा', 'digital':'डिजिटल', 'date':'मिति',
        'miti':'मिति', 'calendar':'पात्रो', 'patro':'पात्रो', 'exchange':'विनिमय',
        'dollar':'विनिमय', 'appointment':'भेटघाट', 'bhetghat':'भेटघाट',
        'grievance':'गुनासो', 'gunaso':'गुनासो', 'complaint':'गुनासो',
    };

    /* ─── Levenshtein distance (max 3 to keep fast) ────── */
    function levenshtein(a, b) {
        if (a === b) return 0;
        if (a.length === 0) return b.length;
        if (b.length === 0) return a.length;
        if (Math.abs(a.length - b.length) > 3) return 99;
        var row = [];
        for (var i = 0; i <= b.length; i++) row[i] = i;
        for (var j = 1; j <= a.length; j++) {
            var prev = j;
            for (var k = 1; k <= b.length; k++) {
                var val = (a[j-1] === b[k-1]) ? row[k-1] : (Math.min(row[k-1], row[k], prev) + 1);
                row[k-1] = prev;
                prev = val;
            }
            row[b.length] = prev;
        }
        return row[b.length];
    }

    /* ─── Score a page against a query ─────────────────── */
    /* Returns: 0 = no match, 1-49 = fuzzy match, 50-100 = strong match */
    function scoreQuery(page, rawQuery) {
        rawQuery = rawQuery.toLowerCase().trim();
        if (!rawQuery) return 0;

        /* Build haystack: title + url + keywords */
        var haystack = (page.title + ' ' + page.url + ' ' + page.keywords.join(' ')).toLowerCase();

        var words = rawQuery.split(/\s+/);
        var totalScore = 0;

        words.forEach(function(w) {
            if (!w) return;
            var wScore = 0;

            /* 1. Exact substring match → 100 pts */
            if (haystack.indexOf(w) !== -1) { wScore = Math.max(wScore, 100); }

            /* 2. Transliteration: roman word → Nepali equivalent */
            if (TRANSLIT[w]) {
                var nepWord = TRANSLIT[w].toLowerCase();
                if (haystack.indexOf(nepWord) !== -1) wScore = Math.max(wScore, 90);
            }

            /* 3. Starts-with on each keyword token */
            haystack.split(/\s+/).forEach(function(token) {
                if (!token) return;
                if (token.startsWith(w) || w.startsWith(token)) wScore = Math.max(wScore, 70);
            });

            /* 4. Fuzzy: Levenshtein ≤ 1 against each keyword token */
            if (wScore < 50) {
                var tokens = haystack.split(/[\s\/,\-]+/);
                for (var i = 0; i < tokens.length; i++) {
                    if (tokens[i].length < 2) continue;
                    var dist = levenshtein(w, tokens[i]);
                    if (dist === 1 && tokens[i].length >= 3) { wScore = Math.max(wScore, 45); break; }
                    if (dist === 2 && tokens[i].length >= 5) { wScore = Math.max(wScore, 25); break; }
                }
            }

            totalScore += wScore;
        });

        /* Normalise: average across words */
        return words.length > 0 ? Math.round(totalScore / words.length) : 0;
    }

    /* Legacy helper for backwards compat */
    function matchesQuery(page, query) {
        return scoreQuery(page, query) >= 50;
    }

    /* ─── Helpers ──────────────────────────────────── */
    function debounce(fn, ms) {
        var t;
        return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); };
    }
    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function highlight(text, q) {
        if (!q) return text;
        try { return text.replace(new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')', 'gi'), '<mark>$1</mark>'); }
        catch (e) { return text; }
    }

    /* ─── Voice Search ─────────────────────────────── */
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    var recognition = null;
    var listening = false;
    var autoSubmitTimer = null;

    /* DOM refs — set after modal init */
    var elInput, elForm, elIconRing, elIconSearch, elIconMic,
        elTitle, elSubtitle, elStatusBar, elStatusText, elStopBtn,
        elErrorBar, elErrorText, elVoiceBig, elDropdown;

    var TITLE_DEFAULT  = document.documentElement.lang === 'en' ? 'Search' : 'खोज्नुहोस्';
    var TITLE_LISTEN   = document.documentElement.lang === 'en' ? 'Listening…' : 'सुन्दैछ…';
    var SUB_DEFAULT    = document.documentElement.lang === 'en' ? 'Type below or tap the mic to speak' : 'टाइप गर्नुहोस् वा माइकबाट बोल्नुहोस्';
    var SUB_LISTEN     = document.documentElement.lang === 'en' ? 'Speak clearly — we\'re listening' : 'स्पष्ट बोल्नुहोस् — सुन्दैछौं';

    function setListeningUI(on) {
        listening = on;
        if (!elIconRing) return;
        if (on) {
            elIconRing.classList.add('listening');
            if (elIconSearch) elIconSearch.style.display = 'none';
            if (elIconMic)    elIconMic.style.display    = 'inline-block';
            if (elTitle)      elTitle.textContent  = TITLE_LISTEN;
            if (elSubtitle)   elSubtitle.textContent = SUB_LISTEN;
            if (elStatusBar)  elStatusBar.style.display = 'flex';
            if (elErrorBar)   elErrorBar.style.display  = 'none';
            if (elVoiceBig)   elVoiceBig.classList.add('active');
        } else {
            elIconRing.classList.remove('listening');
            if (elIconSearch) elIconSearch.style.display = 'inline-block';
            if (elIconMic)    elIconMic.style.display    = 'none';
            if (elTitle)      elTitle.textContent  = TITLE_DEFAULT;
            if (elSubtitle)   elSubtitle.textContent = SUB_DEFAULT;
            if (elStatusBar)  elStatusBar.style.display = 'none';
            if (elVoiceBig)   elVoiceBig.classList.remove('active');
        }
    }

    function showVoiceError(msg) {
        setListeningUI(false);
        if (elErrorBar)  elErrorBar.style.display = 'flex';
        if (elErrorText) elErrorText.textContent  = msg;
        setTimeout(function () {
            if (elErrorBar) elErrorBar.style.display = 'none';
        }, 4000);
    }

    var ERROR_MSGS = {
        'not-allowed':   document.documentElement.lang === 'en'
            ? 'Microphone access denied. Please allow microphone in your browser.'
            : 'माइक्रोफोन अनुमति दिइएन। Browser settings मा allow गर्नुहोस्।',
        'no-speech':     document.documentElement.lang === 'en'
            ? 'No speech detected. Please try again.'
            : 'आवाज सुनिएन। पुनः प्रयास गर्नुहोस्।',
        'network':       document.documentElement.lang === 'en'
            ? 'Network error. Please check your connection.'
            : 'नेटवर्क समस्या भयो।',
        'default':       document.documentElement.lang === 'en'
            ? 'Voice search failed. Try typing instead.'
            : 'आवाज खोज असफल। टाइप गरेर प्रयास गर्नुहोस्।',
    };

    function startVoice() {
        if (!SR) {
            showVoiceError(document.documentElement.lang === 'en'
                ? 'Your browser does not support voice search. Please use Chrome.'
                : 'तपाईंको ब्राउजरले आवाज खोज सपोर्ट गर्दैन। Chrome प्रयोग गर्नुहोस्।');
            return;
        }
        if (listening) { stopVoice(); return; }

        if (!recognition) {
            recognition = new SR();
            recognition.continuous    = false;
            recognition.interimResults= true;
            recognition.maxAlternatives = 1;
            /* Try Nepali first; if no result fallback handled by lang list */
            recognition.lang = 'ne-NP';

            recognition.onresult = function (event) {
                clearTimeout(autoSubmitTimer);
                var transcript = '';
                for (var i = event.resultIndex; i < event.results.length; i++) {
                    transcript = event.results[i][0].transcript;
                }
                if (elInput) {
                    elInput.value = transcript;
                    elInput.dispatchEvent(new Event('input')); /* trigger suggestions */
                }
                /* Final result: update status and schedule auto-submit */
                if (event.results[event.resultIndex] && event.results[event.resultIndex].isFinal) {
                    if (elStatusText) {
                        elStatusText.textContent = (document.documentElement.lang === 'en'
                            ? 'Got it: "' : 'सुनियो: "') + transcript + '"';
                    }
                    autoSubmitTimer = setTimeout(function () {
                        if (elInput && elInput.value.trim() && elForm) {
                            saveRecentSearch(elInput.value.trim());
                            elForm.submit();
                        }
                    }, 1500); /* 1.5 seconds to cancel before auto-submit */
                }
            };

            recognition.onend = function () {
                setListeningUI(false);
                recognition = null; /* allow re-init next time */
            };

            recognition.onerror = function (e) {
                recognition = null;
                /* aborted = user switched tab / programmatic stop — silent */
                if (e.error === 'aborted') { setListeningUI(false); return; }
                showVoiceError(ERROR_MSGS[e.error] || ERROR_MSGS['default']);
            };
        }

        try {
            recognition.start();
            setListeningUI(true);
            if (elInput) elInput.focus();
        } catch (e) {
            recognition = null;
            showVoiceError(ERROR_MSGS['default']);
        }
    }

    function stopVoice() {
        clearTimeout(autoSubmitTimer);
        if (recognition) { try { recognition.stop(); } catch (e) {} recognition = null; }
        setListeningUI(false);
    }

    /* ─── Suggestions dropdown ─────────────────────── */
    function buildDropdown(input) {
        var old = document.getElementById('searchSuggestionsDropdown');
        if (old) old.remove();
        var dd = document.createElement('div');
        dd.id = 'searchSuggestionsDropdown';
        dd.className = 'ssd-dropdown';
        dd.style.display = 'none';
        var wrapper = input.closest('.search-input-wrapper') || input.parentElement;
        wrapper.style.position = 'relative';
        wrapper.appendChild(dd);
        return dd;
    }

    /* ─── Render suggestions: exact + fuzzy "मिल्दोजुल्दो" sections ── */
    function renderSuggestions(dd, scored, recent, query) {
        dd.innerHTML = '';
        var has = false;
        var isNep = document.documentElement.lang !== 'en';

        /* Recent searches (empty query) */
        if (!query && recent.length > 0) {
            dd.appendChild(mkSectionHeader('<i class="fas fa-history me-1"></i> '
                + (isNep ? 'हालसालैका खोजहरू' : 'Recent searches')));
            recent.slice(0, 4).forEach(function (q) {
                var item = document.createElement('div');
                item.className = 'ssd-item ssd-recent';
                item.innerHTML = '<i class="fas fa-clock text-muted me-2"></i>'
                    + escHtml(q)
                    + '<button class="ssd-remove" data-q="' + escHtml(q) + '" title="हटाउनुहोस्"><i class="fas fa-times"></i></button>';
                item.addEventListener('click', function (e) {
                    if (e.target.closest('.ssd-remove')) {
                        removeRecentSearch(q);
                        renderSuggestions(dd, [], getRecentSearches(), '');
                        return;
                    }
                    if (elInput) { elInput.value = q; saveRecentSearch(q); if (elForm) elForm.submit(); }
                });
                dd.appendChild(item);
                has = true;
            });
        }

        if (query && scored.length > 0) {
            /* Split: strong (score≥50) vs fuzzy (score 10-49) */
            var strong = scored.filter(function(s){ return s.score >= 50; });
            var fuzzy  = scored.filter(function(s){ return s.score >= 10 && s.score < 50; });

            /* ── Strong matches ── */
            if (strong.length > 0) {
                dd.appendChild(mkSectionHeader(
                    '<i class="fas fa-search me-1"></i> '
                    + (isNep ? 'सुझावहरू' : 'Suggestions')));
                strong.slice(0, 5).forEach(function (s) {
                    dd.appendChild(mkPageItem(s.page, query));
                    has = true;
                });
            }

            /* ── Fuzzy-only: "मिल्दोजुल्दो हुन सक्छ" ── */
            if (strong.length === 0 && fuzzy.length > 0) {
                dd.appendChild(mkSectionHeader(
                    '<i class="fas fa-wand-magic-sparkles me-1 text-warning"></i> '
                    + (isNep ? 'मिल्दोजुल्दो हुन सक्छ:' : 'Did you mean?')));
                fuzzy.slice(0, 4).forEach(function (s) {
                    var item = mkPageItem(s.page, '');
                    item.classList.add('ssd-fuzzy');
                    dd.appendChild(item);
                    has = true;
                });
            }

            /* ── Fuzzy as secondary when strong also exist ── */
            if (strong.length > 0 && fuzzy.length > 0) {
                dd.appendChild(mkSectionHeader(
                    '<i class="fas fa-lightbulb me-1 text-secondary"></i> '
                    + (isNep ? 'सम्बन्धित पेजहरू:' : 'Related pages:')));
                fuzzy.slice(0, 3).forEach(function (s) {
                    var item = mkPageItem(s.page, '');
                    item.classList.add('ssd-related');
                    dd.appendChild(item);
                    has = true;
                });
            }
        }

        dd.style.display = has ? 'block' : 'none';
    }

    function mkPageItem(page, q) {
        var item = document.createElement('div');
        item.className = 'ssd-item';
        item.innerHTML = '<i class="fas fa-file-lines text-muted me-2"></i>'
            + highlight(escHtml(page.title), q);
        item.addEventListener('click', function () {
            saveRecentSearch(page.title);
            window.location.href = page.url;
        });
        return item;
    }

    function mkSectionHeader(html) {
        var el = document.createElement('div');
        el.className = 'ssd-header';
        el.innerHTML = html;
        return el;
    }

    /* ─── Main init (called when modal first opens) ── */
    var initialized = false;

    function initSearch() {
        if (initialized) return;

        elInput     = document.getElementById('searchInput');
        if (!elInput) return;
        initialized = true;

        elForm       = document.getElementById('searchForm');
        elIconRing   = document.getElementById('smbIconRing');
        elIconSearch = document.getElementById('smbIconSearch');
        elIconMic    = document.getElementById('smbIconMic');
        elTitle      = document.getElementById('smbTitle');
        elSubtitle   = document.getElementById('smbSubtitle');
        elStatusBar  = document.getElementById('voiceStatusBar');
        elStatusText = document.getElementById('voiceStatusText');
        elStopBtn    = document.getElementById('voiceStopBtn');
        elErrorBar   = document.getElementById('voiceErrorBar');
        elErrorText  = document.getElementById('voiceErrorText');
        elVoiceBig   = document.getElementById('smbVoiceBig');

        /* Build suggestions dropdown */
        elDropdown = buildDropdown(elInput);

        /* Small inline mic button inside input wrapper */
        if (SR) {
            var slot = document.getElementById('voiceBtnSlot');
            var micBtn = document.createElement('button');
            micBtn.type = 'button';
            micBtn.id   = 'searchInlineMic';
            micBtn.className = 'search-inline-mic';
            micBtn.title = document.documentElement.lang === 'en' ? 'Voice search' : 'आवाजबाट खोज्नुहोस्';
            micBtn.innerHTML = '<i class="fas fa-microphone"></i>';
            micBtn.addEventListener('click', startVoice);
            if (slot) slot.appendChild(micBtn);
        } else {
            /* Voice not supported — hide voice UI elements & update subtitle */
            if (elVoiceBig) elVoiceBig.style.display = 'none';
            var subEl = document.getElementById('smbSubtitle');
            if (subEl) subEl.textContent = document.documentElement.lang === 'en'
                ? 'Type below to search'
                : 'यहाँ टाइप गरेर खोज्नुहोस्';
        }

        /* Big voice button */
        if (elVoiceBig) {
            if (SR) {
                elVoiceBig.addEventListener('click', startVoice);
            } else {
                elVoiceBig.style.display = 'none';
            }
        }

        /* Stop button inside status bar */
        if (elStopBtn) elStopBtn.addEventListener('click', stopVoice);

        /* Input: debounced scored suggestions */
        var onInput = debounce(function () {
            var q = elInput.value.trim().toLowerCase();
            var scored = [];
            if (q.length >= 1) {
                SITE_PAGES.forEach(function(p) {
                    var s = scoreQuery(p, q);
                    if (s >= 10) scored.push({ page: p, score: s });
                });
                /* Sort descending by score */
                scored.sort(function(a, b) { return b.score - a.score; });
            }
            renderSuggestions(elDropdown, scored, getRecentSearches(), q);
        }, 250);
        elInput.addEventListener('input', onInput);

        /* Focus: show recent */
        elInput.addEventListener('focus', function () {
            if (!elInput.value.trim()) renderSuggestions(elDropdown, [], getRecentSearches(), '');
        });

        /* Form submit: save recent */
        if (elForm) {
            elForm.addEventListener('submit', function () {
                var q = elInput.value.trim();
                if (q) saveRecentSearch(q);
            });
        }

        /* Keyboard navigation */
        elInput.addEventListener('keydown', function (e) {
            var items = elDropdown.querySelectorAll('.ssd-item');
            var active = elDropdown.querySelector('.ssd-item.focused');
            var idx = -1;
            if (active) items.forEach(function (it, i) { if (it === active) idx = i; });

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (active) active.classList.remove('focused');
                idx = (idx + 1) % items.length;
                if (items[idx]) { items[idx].classList.add('focused'); items[idx].scrollIntoView({ block: 'nearest' }); }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (active) active.classList.remove('focused');
                idx = (idx - 1 + items.length) % items.length;
                if (items[idx]) { items[idx].classList.add('focused'); items[idx].scrollIntoView({ block: 'nearest' }); }
            } else if (e.key === 'Enter' && active) {
                e.preventDefault(); active.click();
            } else if (e.key === 'Escape') {
                elDropdown.style.display = 'none';
                stopVoice();
            }
        });

        /* Outside click hides dropdown */
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#searchSuggestionsDropdown') && e.target !== elInput) {
                elDropdown.style.display = 'none';
            }
        });

        /* Reset voice when modal closes */
        var modal = document.getElementById('searchModal');
        if (modal) {
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (m) {
                    if (!m.target.classList.contains('active')) {
                        stopVoice();
                        if (elDropdown) elDropdown.style.display = 'none';
                    }
                });
            });
            observer.observe(modal, { attributes: true, attributeFilter: ['class'] });
        }
    }

    /* ─── CSS injection ────────────────────────────── */
    function injectStyles() {
        if (document.getElementById('searchImprovedStyles')) return;
        var s = document.createElement('style');
        s.id = 'searchImprovedStyles';
        s.textContent = `
/* ── Icon ring ─────────────────────────────────────────── */
.smb-icon-ring {
    width: 72px; height: 72px; border-radius: 50%;
    background: linear-gradient(135deg, #1a5f2a, #2e8b4a);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 14px;
    font-size: 1.8rem; color: #fff;
    box-shadow: 0 4px 18px rgba(26,95,42,.3);
    transition: transform .3s, box-shadow .3s;
}
.smb-icon-ring.listening {
    background: linear-gradient(135deg, #c62828, #e53935);
    box-shadow: 0 0 0 0 rgba(229,57,53,.5);
    animation: mic-ring-pulse 1.2s ease-in-out infinite;
}
@keyframes mic-ring-pulse {
    0%   { box-shadow: 0 0 0 0   rgba(229,57,53,.6); }
    70%  { box-shadow: 0 0 0 18px rgba(229,57,53,0); }
    100% { box-shadow: 0 0 0 0   rgba(229,57,53,0); }
}
.smb-title {
    font-size: 1.4rem; font-weight: 700; color: #1a5f2a;
    margin-bottom: 4px; text-align: center; transition: color .3s;
}
.smb-subtitle {
    font-size: .88rem; color: #777; text-align: center;
    margin-bottom: 20px; transition: opacity .3s;
}

/* ── Inline mic button inside input ────────────────────── */
.search-inline-mic {
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    width: 36px; height: 36px;
    background: transparent; border: none;
    color: #aaa; font-size: 1rem; cursor: pointer;
    border-radius: 50%;
    transition: color .2s, background .2s; z-index: 5;
    margin-right: 4px;
}
.search-inline-mic:hover { color: #1a5f2a; background: rgba(26,95,42,.08); }
.search-inline-mic.listening {
    color: #e53935; animation: pulse-mic .8s infinite;
    background: rgba(229,57,53,.1);
}
@keyframes pulse-mic {
    0%,100% { box-shadow: 0 0 0 0   rgba(229,57,53,.5); }
    50%      { box-shadow: 0 0 0 7px rgba(229,57,53,0); }
}

/* ── Big standalone voice button ───────────────────────── */
.smb-voice-big {
    display: flex; align-items: center; gap: 8px;
    margin: 18px auto 6px;
    background: transparent;
    border: 2px solid #1a5f2a;
    color: #1a5f2a; border-radius: 50px;
    padding: 9px 24px; font-size: .92rem; font-weight: 600;
    cursor: pointer; transition: all .25s;
}
.smb-voice-big:hover {
    background: #1a5f2a; color: #fff;
    box-shadow: 0 4px 14px rgba(26,95,42,.3);
}
.smb-voice-big.active {
    background: #e53935; border-color: #e53935; color: #fff;
    animation: big-mic-pulse .9s ease-in-out infinite;
}
@keyframes big-mic-pulse {
    0%,100% { box-shadow: 0 0 0 0   rgba(229,57,53,.5); }
    50%      { box-shadow: 0 0 0 12px rgba(229,57,53,0); }
}
.smb-voice-big i { font-size: 1.1rem; }

/* ── Voice status bar ───────────────────────────────────── */
.voice-status-bar {
    display: none; align-items: center; gap: 10px;
    background: #fff3f3; border: 1px solid #ffd6d6;
    border-radius: 8px; padding: 8px 14px; margin-top: 8px;
    font-size: .85rem; color: #c62828;
}
.vsb-waves { display: flex; align-items: center; gap: 3px; height: 20px; }
.vsb-waves span {
    display: inline-block; width: 3px; background: #e53935;
    border-radius: 3px; animation: wave-bar 1s ease-in-out infinite;
}
.vsb-waves span:nth-child(1){ height: 8px;  animation-delay: 0s; }
.vsb-waves span:nth-child(2){ height: 14px; animation-delay: .1s; }
.vsb-waves span:nth-child(3){ height: 20px; animation-delay: .2s; }
.vsb-waves span:nth-child(4){ height: 14px; animation-delay: .3s; }
.vsb-waves span:nth-child(5){ height: 8px;  animation-delay: .4s; }
@keyframes wave-bar {
    0%,100% { transform: scaleY(.5); }
    50%      { transform: scaleY(1); }
}
.vsb-text { flex: 1; font-weight: 500; }
.vsb-stop {
    background: #e53935; color: #fff; border: none;
    border-radius: 6px; padding: 3px 10px; font-size: .8rem;
    cursor: pointer; transition: background .2s; white-space: nowrap;
}
.vsb-stop:hover { background: #c62828; }

/* ── Voice error bar ────────────────────────────────────── */
.voice-error-bar {
    display: none; align-items: center; gap: 6px;
    background: #fff8e1; border: 1px solid #ffe082;
    border-radius: 8px; padding: 8px 14px; margin-top: 8px;
    font-size: .85rem; color: #b45309;
}

/* ── Suggestions dropdown ───────────────────────────────── */
.ssd-dropdown {
    position: absolute; top: calc(100% + 6px); left: 0; right: 0;
    background: #fff; border-radius: 10px;
    box-shadow: 0 8px 30px rgba(0,0,0,.15);
    z-index: 9999; max-height: 300px; overflow-y: auto;
    border: 1px solid #e8e8e8; animation: ssd-slide .15s ease;
}
@keyframes ssd-slide { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
.ssd-header {
    padding: 7px 14px 5px; font-size: .73rem; color: #888;
    text-transform: uppercase; letter-spacing: .05em;
    background: #f8f9fa; border-bottom: 1px solid #eee;
}
.ssd-item {
    display: flex; align-items: center; padding: 10px 14px;
    font-size: .9rem; color: #333; cursor: pointer;
    border-bottom: 1px solid #f5f5f5; transition: background .15s;
    position: relative;
}
.ssd-item:last-child { border-bottom: none; }
.ssd-item:hover, .ssd-item.focused { background: #f0f8f2; color: #1a5f2a; }
.ssd-remove {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    background: transparent; border: none; color: #bbb;
    font-size: .75rem; cursor: pointer; padding: 4px; border-radius: 50%;
}
.ssd-remove:hover { color: #e53935; }
.ssd-item mark { background: #fff3cd; color: inherit; border-radius: 2px; padding: 0 1px; }
.ssd-fuzzy { opacity: .88; font-style: italic; }
.ssd-fuzzy i.fa-file-lines { color: #f59e0b !important; }
.ssd-fuzzy:hover, .ssd-fuzzy.focused { background: #fffbeb; color: #92400e; }
.ssd-related { opacity: .75; }
.ssd-related:hover, .ssd-related.focused { background: #f0f4ff; color: #3730a3; }

/* ── Mobile adjustments ─────────────────────────────────── */
@media (max-width: 576px) {
    .search-inline-mic { width: 32px; height: 32px; font-size: .9rem; }
    .smb-voice-big { font-size: .85rem; padding: 8px 18px; }
    .smb-icon-ring { width: 60px; height: 60px; font-size: 1.5rem; }
    .smb-title { font-size: 1.2rem; }
}
`;
        document.head.appendChild(s);
    }

    /* ─── Watch modal open and init ────────────────── */
    function watchModal() {
        var modal = document.getElementById('searchModal');
        if (!modal) return;

        /* Init when modal becomes active */
        var obs = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                if (m.target.classList.contains('active')) {
                    initSearch();
                    setTimeout(function () {
                        var inp = document.getElementById('searchInput');
                        if (inp) inp.focus();
                    }, 80);
                }
            });
        });
        obs.observe(modal, { attributes: true, attributeFilter: ['class'] });

        /* Init now if already open */
        if (modal.classList.contains('active')) initSearch();
    }

    /* ─── Boot ──────────────────────────────────────── */
    injectStyles();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', watchModal);
    } else {
        watchModal();
    }

})();
