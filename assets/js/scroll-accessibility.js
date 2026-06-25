/**
 * Scroll Accessibility Controller  — v3.0
 * File: assets/js/scroll-accessibility.js
 *
 * Features:
 *   1. Nepali Voice Scroll  — "माथि" / "तला" बोल्दा scroll
 *      • Speed control: "छिटो" / "बिस्तारै" / "सामान्य"
 *      • Continuous mode: "जारी माथि" / "जारी तला" → रोकिनुहोस् नभनुञ्जेल
 *      • Panel मा 🐢 / 🚶 / 🚀 speed toggle
 *   2. Camera Gesture Scroll — हात माथि/तल हल्लाउँदा scroll
 *   3. Mobile Tilt Scroll    — phone झुकाउँदा scroll
 *
 * Voice Commands (नेपाली + Hindi + English):
 *   माथि / ऊपर / up         → scroll up
 *   तला / नीचे / down       → scroll down
 *   छिटो / तेज / fast       → 🚀 fast speed
 *   बिस्तारै / धिमा / slow  → 🐢 slow speed
 *   सामान्य / normal        → 🚶 normal speed
 *   जारी माथि / keep up     → continuous scroll up (until "रोक")
 *   जारी तला / keep down    → continuous scroll down (until "रोक")
 *   रोक / stop / pause      → stop continuous scroll
 *   शुरु / top              → page top
 *   अन्त / bottom / end     → page bottom
 *
 * Usage: page को lower-left corner मा ♿ button — थिच्दा panel खुल्छ
 */

(function () {
    'use strict';

    /* ─────────────────────────────────────────────────────────
       SPEED PRESETS
       slow / normal / fast — panel button वा voice command बाट
    ───────────────────────────────────────────────────────── */
    var SPEEDS = {
        slow:   { step: 100,  big: 280,  label: 'बिस्तारै',  tiltMulti: 0.5 },
        normal: { step: 220,  big: 600,  label: 'सामान्य',   tiltMulti: 1.0 },
        fast:   { step: 420,  big: 1100, label: 'छिटो',      tiltMulti: 1.8 }
    };

    var currentSpeed = 'normal';   /* active speed key */

    /* Continuous scroll interval (जारी mode) */
    var continuousTimer = null;
    var continuousDir   = null;    /* 'up' | 'down' | null */
    var CONTINUOUS_INTERVAL_MS = 180; /* scroll fire every N ms */

    /* Camera / Tilt */
    var TILT_DEADZONE    = 7;
    var TILT_MAX         = 28;
    var CAMERA_FPS       = 14;   /* 8→14: faster frame analysis */
    var EYE_FPS_RATE     = 14;   /* override EYE_FPS below */
    var CAMERA_THRESHOLD = 15;   /* 22→15: more sensitive motion detect */
    var TOAST_DURATION   = 1900;

    /* ─────────────────────────────────────────────────────────
       VOICE_MAP — Nepali + Hindi + English + phonetic variants
       Chrome ले Nepali speech लाई अन्य भाषामा return गर्न सक्छ।
    ───────────────────────────────────────────────────────── */
    var VOICE_MAP = [

        /* ── SPEED CONTROL: Fast ── */
        {
            words: [
                'छिटो', 'छिटो गर', 'छिटो स्क्रोल', 'तेज', 'तेज गर',
                'fast', 'faster', 'speed up', 'quick', 'तेजी'
            ],
            action: 'speed-fast', label: '🚀 Fast Speed'
        },

        /* ── SPEED CONTROL: Slow ── */
        {
            words: [
                'बिस्तारै', 'बिस्तारी', 'धिमा', 'ढिलो', 'धिरे', 'धिरे धिरे',
                'slow', 'slower', 'slow down', 'धीरे', 'धीमा'
            ],
            action: 'speed-slow', label: '🐢 Slow Speed'
        },

        /* ── SPEED CONTROL: Normal ── */
        {
            words: [
                'सामान्य', 'ठीक', 'मध्यम', 'normal', 'medium', 'reset speed', 'default'
            ],
            action: 'speed-normal', label: '🚶 Normal Speed'
        },

        /* ── CONTINUOUS SCROLL: Up ── */
        {
            words: [
                'जारी माथि', 'लगातार माथि', 'माथि जारी रहनुहोस्',
                'keep up', 'keep scrolling up', 'continue up', 'auto up',
                'continuously up'
            ],
            action: 'continuous-up', label: '⬆⬆ जारी माथि'
        },

        /* ── CONTINUOUS SCROLL: Down ── */
        {
            words: [
                'जारी तला', 'लगातार तला', 'तला जारी रहनुहोस्',
                'keep down', 'keep scrolling down', 'continue down', 'auto down',
                'continuously down'
            ],
            action: 'continuous-down', label: '⬇⬇ जारी तला'
        },

        /* ── UP ── */
        {
            words: [
                /* Nepali */  'माथि', 'मथि', 'उपर', 'उमाथि', 'माते', 'माती', 'मथ',
                /* Hindi */   'ऊपर', 'ऊपर जाओ', 'ऊपर जा',
                /* English */ 'up', 'scroll up', 'go up', 'move up', 'above', 'mathi',
                /* phonetic */ 'marty', 'matty', 'mati', 'mata', 'mathe', 'mathew', 'math i'
            ],
            action: 'up', label: '⬆ माथि'
        },

        /* ── DOWN ── */
        {
            words: [
                /* Nepali */  'तला', 'तल', 'तल्लो', 'तल्ला', 'तला जाऊ',
                /* Hindi */   'नीचे', 'नीचे जाओ', 'नीचे जा', 'नीचे चलो',
                /* English */ 'down', 'scroll down', 'go down', 'move down', 'below',
                /* phonetic */ 'tala', 'tal', 'tulla', 'tla', 'color', 'dollar', 'talar'
            ],
            action: 'down', label: '⬇ तला'
        },

        /* ── TOP ── */
        {
            words: [
                'धेरै माथि', 'शुरु', 'सुरु', 'सुरुवात', 'शिर्ष', 'शीर्ष', 'सबैभन्दा माथि',
                'शुरू', 'सबसे ऊपर', 'शुरुआत',
                'top', 'go to top', 'beginning', 'start', 'home'
            ],
            action: 'top', label: '⏫ शीर्षमा'
        },

        /* ── BOTTOM ── */
        {
            words: [
                'धेरै तला', 'अन्त', 'अन्तिम', 'सबैभन्दा तला',
                'अंत', 'सबसे नीचे', 'आखिर',
                'bottom', 'end', 'go to bottom', 'last', 'talla'
            ],
            action: 'bottom', label: '⏬ अन्तमा'
        },

        /* ── STOP ── */
        {
            words: [
                'रोकिनुहोस्', 'रोक', 'बन्द', 'बन्द गर्नुहोस्', 'रुकजा',
                'रुको', 'बंद करो', 'रोको',
                'stop', 'pause', 'cancel', 'halt', 'freeze'
            ],
            action: 'stop', label: '⏹ रोकिनुहोस्'
        }
    ];

    /* ─────────────────────────────────────────
       STATE
    ───────────────────────────────────────── */
    var state = {
        voice:  false,
        camera: false,
        eye:    false,    /* नयाँ: Eye/face position tracking */
        tilt:   false
    };

    /* ─────────────────────────────────────────
       SCROLL HELPERS
    ───────────────────────────────────────── */
    function getStep() { return SPEEDS[currentSpeed].step; }
    function getBig()  { return SPEEDS[currentSpeed].big;  }

    /* Single scroll command */
    function doScroll(action) {
        stopContinuous(); /* नयाँ command आउँदा continuous रोक्ने */

        var s = getStep();
        if (action === 'up')     window.scrollBy({ top: -s, behavior: 'smooth' });
        if (action === 'down')   window.scrollBy({ top:  s, behavior: 'smooth' });
        if (action === 'top')    window.scrollTo({ top: 0, behavior: 'smooth' });
        if (action === 'bottom') window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'smooth' });
        if (action === 'stop')   { /* already stopped above */ }

        if (action === 'speed-fast')   setSpeed('fast');
        if (action === 'speed-slow')   setSpeed('slow');
        if (action === 'speed-normal') setSpeed('normal');

        if (action === 'continuous-up')   startContinuous('up');
        if (action === 'continuous-down') startContinuous('down');
    }

    /* Gesture scroll — instant (behavior:'auto') for camera/eye/tilt
       doScroll() uses 'smooth' for voice/buttons; gesture needs immediate response */
    function gestureScroll(dir) {
        var s = getStep();
        if (dir === 'up')   window.scrollBy({ top: -s, behavior: 'auto' });
        if (dir === 'down') window.scrollBy({ top:  s, behavior: 'auto' });
    }

    /* Speed setter */
    function setSpeed(key) {
        currentSpeed = key;
        updateSpeedUI();
        showToast('⚡ ' + SPEEDS[key].label + ' mode', 'info');
    }

    /* Continuous scroll — रोकिनुहोस् वा नयाँ command नभनुञ्जेल */
    function startContinuous(dir) {
        stopContinuous();
        continuousDir = dir;
        var s = getStep();
        /* First fire immediately */
        window.scrollBy({ top: dir === 'up' ? -s : s, behavior: 'smooth' });
        continuousTimer = setInterval(function () {
            var step = getStep(); /* live speed updates पनि reflect हुन्छ */
            window.scrollBy({ top: dir === 'up' ? -step : step, behavior: 'smooth' });
        }, CONTINUOUS_INTERVAL_MS);
        showToast((dir === 'up' ? '⬆⬆' : '⬇⬇') + ' जारी — "रोक" भन्नुहोस् बन्द गर्न', 'success');
        updateContIndicator(dir);
    }

    function stopContinuous() {
        if (continuousTimer) {
            clearInterval(continuousTimer);
            continuousTimer = null;
        }
        if (continuousDir) {
            continuousDir = null;
            updateContIndicator(null);
        }
    }

    /* ─────────────────────────────────────────
       TOAST NOTIFICATION
    ───────────────────────────────────────── */
    var toastEl = null;
    function showToast(msg, type) {
        if (!toastEl) return;
        toastEl.textContent = msg;
        toastEl.className = 'sa-toast sa-toast--' + (type || 'info') + ' sa-toast--show';
        clearTimeout(toastEl._timer);
        toastEl._timer = setTimeout(function () {
            toastEl.classList.remove('sa-toast--show');
        }, TOAST_DURATION);
    }

    /* ─────────────────────────────────────────────────────────
       1. NEPALI VOICE SCROLL — v3.0
       Language cascade: ne-NP → hi-IN → en-IN → en-US
       Multi-alternative matching + continuous scroll + speed
    ───────────────────────────────────────────────────────── */
    var SR           = window.SpeechRecognition || window.webkitSpeechRecognition;
    var recognition  = null;
    var _voiceStopped = false;
    var _langIdx      = 0;
    var LANG_SEQ      = ['ne-NP', 'hi-IN', 'en-IN', 'en-US'];
    var LANG_LABELS   = {
        'ne-NP': 'नेपाली', 'hi-IN': 'हिन्दी', 'en-IN': 'English (IN)', 'en-US': 'English'
    };

    function matchVoiceAction(transcript) {
        var t = transcript.trim().toLowerCase();
        /* Longer / more-specific phrases पहिले match गर्ने (continuous, speed, etc.) */
        var sorted = VOICE_MAP.slice().sort(function (a, b) {
            return Math.max.apply(null, b.words.map(function (w) { return w.length; }))
                 - Math.max.apply(null, a.words.map(function (w) { return w.length; }));
        });
        for (var i = 0; i < sorted.length; i++) {
            var entry = sorted[i];
            for (var j = 0; j < entry.words.length; j++) {
                if (t.indexOf(entry.words[j].toLowerCase()) !== -1) {
                    return entry;
                }
            }
        }
        return null;
    }

    function _buildRecognition(lang) {
        var r          = new SR();
        r.lang         = lang;
        r.continuous   = true;
        r.interimResults = false;
        r.maxAlternatives = 10; /* more alternatives → better match chance */

        r.onresult = function (e) {
            for (var i = e.resultIndex; i < e.results.length; i++) {
                if (!e.results[i].isFinal) continue;

                var matched = null;
                var allHeard = [];
                for (var a = 0; a < e.results[i].length; a++) {
                    var t = e.results[i][a].transcript;
                    allHeard.push('"' + t + '"');
                    if (!matched) matched = matchVoiceAction(t);
                }

                if (matched) {
                    doScroll(matched.action);
                    /* Speed/continuous actions को toast doScroll() भित्रबाटै देखाइन्छ */
                    if (['up','down','top','bottom','stop'].indexOf(matched.action) !== -1) {
                        showToast('🎤 ' + matched.label, 'success');
                    }
                } else {
                    var heard = e.results[i][0].transcript;
                    if (heard && heard.length < 50) {
                        showToast('🎤 "' + heard + '" — "माथि"/"तला"/"छिटो" भन्नुहोस्', 'info');
                    }
                }
            }
        };

        r.onerror = function (e) {
            if (e.error === 'no-speech') return;
            if (e.error === 'aborted')   return;
            if (e.error === 'not-allowed' || e.error === 'permission-denied') {
                showToast('🎤 Microphone permission दिनुहोस् — Browser Settings मा Allow', 'error');
                state.voice = false; updateButtons(); return;
            }
            if (!_voiceStopped) _tryNextLang('Error: ' + e.error);
        };

        r.onend = function () {
            if (!_voiceStopped && state.voice) {
                setTimeout(function () {
                    if (!_voiceStopped && state.voice && recognition === r) {
                        try { r.start(); } catch (ex) { _tryNextLang('restart failed'); }
                    }
                }, 120);  /* faster restart — was 150ms */
            }
        };

        return r;
    }

    function _tryNextLang(reason) {
        _langIdx++;
        if (_langIdx >= LANG_SEQ.length) _langIdx = LANG_SEQ.length - 1;
        var nextLang = LANG_SEQ[_langIdx];

        var old = recognition;
        recognition = null;
        if (old) { try { old.abort(); } catch (ex) {} }

        showToast('🎤 ' + LANG_LABELS[nextLang] + ' मोड — "माथि"/"तला" भन्नुहोस्', 'info');

        setTimeout(function () {
            if (!_voiceStopped && state.voice) {
                recognition = _buildRecognition(nextLang);
                try { recognition.start(); } catch (ex) {
                    showToast('🎤 Voice start भएन। Page reload गर्नुहोस्।', 'error');
                }
            }
        }, 350);
    }

    function startVoice() {
        if (!SR) {
            showToast('⚠ Voice यो browser मा काम गर्दैन। Chrome प्रयोग गर्नुहोस्।', 'error');
            return false;
        }
        _voiceStopped = false;
        _langIdx      = 0;
        recognition   = _buildRecognition(LANG_SEQ[0]);

        /* 5s without result → try hi-IN */
        var _noResultTimer = setTimeout(function () {
            if (state.voice && _langIdx === 0) _tryNextLang('no results in 5s');
        }, 5000);

        var _orig = recognition.onresult;
        recognition.onresult = function (e) { clearTimeout(_noResultTimer); _orig(e); };

        try {
            recognition.start();
            showToast('🎤 सुनिरहेको छु — "माथि" / "तला" / "छिटो" भन्नुहोस्', 'success');
            return true;
        } catch (ex) {
            showToast('🎤 Voice start गर्न सकिएन।', 'error');
            return false;
        }
    }

    function stopVoice() {
        _voiceStopped = true;
        _langIdx      = 0;
        stopContinuous();
        if (recognition) { try { recognition.abort(); } catch (ex) {} recognition = null; }
        showToast('🎤 Voice बन्द भयो', 'info');
    }

    function toggleVoice() {
        state.voice = !state.voice;
        if (state.voice) { var ok = startVoice(); if (!ok) state.voice = false; }
        else stopVoice();
        updateButtons();
    }

    /* ─────────────────────────────────────────
       2. CAMERA GESTURE SCROLL
    ───────────────────────────────────────── */
    var camStream = null, camVideo = null, camCanvas = null, camCtx = null;
    var prevFrame = null, camInterval = null, camPipEl = null;
    var lastCamScroll = 0; /* camera scroll cooldown — तीव्र triggers रोक्न */

    /* Eye tracking vars */
    var eyeStream = null, eyeVideo = null, eyeCanvas = null, eyeCtx = null;
    var eyeInterval = null, eyePipEl = null, lastEyeScroll = 0;
    var EYE_FPS = 14; /* 10→14: faster eye frame analysis */

    function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showToast('📷 Camera यो browser मा उपलब्ध छैन।', 'error');
            return false;
        }
        /* Camera requires secure context (https) on most browsers */
        if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
            showToast('📷 Camera feature https मा मात्र काम गर्छ। (SSL enable गर्नुहोस्)', 'error');
            return false;
        }
        navigator.mediaDevices.getUserMedia({ video: { width: 160, height: 120, facingMode: 'user' }, audio: false })
        .then(function (stream) {
            camStream = stream;
            camVideo  = document.createElement('video');
            camVideo.srcObject = stream;
            camVideo.autoplay  = true;
            camVideo.muted     = true;
            camVideo.width     = 160;
            camVideo.height    = 120;
            camVideo.play().catch(function () {}); /* force play for mobile */
            /* position offscreen instead of display:none — hidden video stays active for analysis */
            camVideo.setAttribute('playsinline', '');
            camVideo.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;';
            document.body.appendChild(camVideo);

            camCanvas = document.createElement('canvas');
            camCanvas.width  = 160;
            camCanvas.height = 120;
            camCtx = camCanvas.getContext('2d');

            camPipEl = document.createElement('div');
            camPipEl.className = 'sa-cam-pip';
            camPipEl.title = 'Camera Gesture: माथि zone हात = scroll up | तला zone = scroll down';
            camPipEl.innerHTML =
                '<video autoplay muted playsinline style="width:100%;height:auto;display:block;transform:scaleX(-1);border-radius:6px 6px 0 0;"></video>' +
                '<div class="sa-cam-zones">' +
                  '<div class="sa-cam-zone sa-cam-zone--up">⬆ माथि</div>' +
                  '<div class="sa-cam-zone sa-cam-zone--down">⬇ तला</div>' +
                '</div>' +
                '<div class="sa-cam-motion" id="saCamMotion">...</div>';
            document.body.appendChild(camPipEl);
            camPipEl.querySelector('video').srcObject = stream;

            camInterval = setInterval(analyzeFrame, 1000 / CAMERA_FPS);
            showToast('📷 Camera active — हात माथि/तल हल्लाउनुहोस्', 'success');
        }).catch(function (err) {
            var msg = err.name === 'NotAllowedError'
                ? 'Camera permission दिनुहोस्'
                : 'Camera खोल्न सकिएन: ' + err.message;
            showToast('📷 ' + msg, 'error');
            state.camera = false; updateButtons();
        });
        return true;
    }

    function analyzeFrame() {
        if (!camVideo || !camCtx || camVideo.readyState < 3) return;
        camCtx.drawImage(camVideo, 0, 0, 160, 120);
        var current = camCtx.getImageData(0, 0, 160, 120).data;
        if (!prevFrame) { prevFrame = current.slice(); return; }

        var topMotion = 0, bottomMotion = 0;
        for (var y = 0; y < 120; y++) {
            for (var x = 0; x < 160; x++) {
                var idx  = (y * 160 + x) * 4;
                var diff = Math.abs(current[idx]   - prevFrame[idx])
                         + Math.abs(current[idx+1] - prevFrame[idx+1])
                         + Math.abs(current[idx+2] - prevFrame[idx+2]);
                if (diff > CAMERA_THRESHOLD) {
                    if (y < 60) topMotion++;
                    else        bottomMotion++;
                }
            }
        }
        prevFrame = current.slice();

        var total = topMotion + bottomMotion;
        if (total < 80) { updateCamUI('...', null, null); return; }   /* 150→80: easier trigger */
        var topRatio = topMotion / total;
        var now = Date.now();
        var camCooldown = { slow: 380, normal: 160, fast: 80 }[currentSpeed] || 160; /* 300→160 normal */
        if (topRatio > 0.58) {          /* 0.65→0.58: wider up zone */
            updateCamUI('⬆ माथि', true, false);
            if (now - lastCamScroll > camCooldown) { lastCamScroll = now; gestureScroll('up'); }
        } else if (topRatio < 0.42) {   /* 0.35→0.42: wider down zone */
            updateCamUI('⬇ तला', false, true);
            if (now - lastCamScroll > camCooldown) { lastCamScroll = now; gestureScroll('down'); }
        } else {
            updateCamUI('~', null, null);
        }
    }

    function updateCamUI(text, upActive, downActive) {
        var m = document.getElementById('saCamMotion');
        if (m) m.textContent = text;
        if (!camPipEl) return;
        var zones = camPipEl.querySelectorAll('.sa-cam-zone');
        if (zones[0]) zones[0].classList.toggle('sa-cam-zone--active', upActive === true);
        if (zones[1]) zones[1].classList.toggle('sa-cam-zone--active', downActive === true);
    }

    function stopCamera() {
        clearInterval(camInterval); camInterval = null; prevFrame = null;
        if (camStream) { camStream.getTracks().forEach(function (t) { t.stop(); }); camStream = null; }
        if (camVideo)  { camVideo.remove();  camVideo  = null; }
        if (camPipEl)  { camPipEl.remove();  camPipEl  = null; }
        showToast('📷 Camera बन्द भयो', 'info');
    }

    /* ─────────────────────────────────────────
       Eye Tracking Scroll (face position)
       अनुहार माथि zone = scroll up
       अनुहार तला zone  = scroll down
    ───────────────────────────────────────── */
    function analyzeEyeFrame() {
        if (!eyeVideo || !eyeCtx || eyeVideo.readyState < 3) return;
        eyeCtx.drawImage(eyeVideo, 0, 0, 160, 120);
        var data = eyeCtx.getImageData(0, 0, 160, 120).data;

        /* Brightness centroid — अनुहारको Y position खोज्ने */
        var sumY = 0, cnt = 0;
        for (var y = 0; y < 120; y++) {
            for (var x = 20; x < 140; x++) { /* edges skip */
                var i = (y * 160 + x) * 4;
                var lum = 0.299 * data[i] + 0.587 * data[i+1] + 0.114 * data[i+2];
                if (lum > 120) { sumY += y; cnt++; }
            }
        }
        if (cnt < 120) { updateEyeUI('...', null, null); return; } /* 200→120: detect face easier */

        var centY = sumY / cnt;        /* 0–119 */
        var ratio = centY / 120;       /* 0.0–1.0 */
        var now   = Date.now();
        var cooldown = { slow: 420, normal: 175, fast: 90 }[currentSpeed] || 175; /* 350→175 normal */

        if (ratio < 0.43) {            /* 0.40→0.43: bigger up zone */
            updateEyeUI('⬆ माथि', true, false);
            if (now - lastEyeScroll > cooldown) { lastEyeScroll = now; gestureScroll('up'); }
        } else if (ratio > 0.57) {     /* 0.60→0.57: bigger down zone */
            updateEyeUI('⬇ तला', false, true);
            if (now - lastEyeScroll > cooldown) { lastEyeScroll = now; gestureScroll('down'); }
        } else {
            updateEyeUI('~', null, null);
        }
    }

    function updateEyeUI(text, upA, downA) {
        var m = document.getElementById('saEyeMotion');
        if (m) m.textContent = text;
        if (!eyePipEl) return;
        var zones = eyePipEl.querySelectorAll('.sa-cam-zone');
        if (zones[0]) zones[0].classList.toggle('sa-cam-zone--active', upA === true);
        if (zones[1]) zones[1].classList.toggle('sa-cam-zone--active', downA === true);
    }

    function startEye() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showToast('👁 Eye tracking यो browser मा उपलब्ध छैन।', 'error');
            return false;
        }
        if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
            showToast('👁 Eye feature https मा मात्र काम गर्छ। (SSL enable गर्नुहोस्)', 'error');
            return false;
        }
        navigator.mediaDevices.getUserMedia({ video: { width: 160, height: 120, facingMode: 'user' }, audio: false })
        .then(function (stream) {
            eyeStream = stream;

            eyeVideo = document.createElement('video');
            eyeVideo.srcObject = stream;
            eyeVideo.autoplay = true;
            eyeVideo.muted    = true;
            eyeVideo.setAttribute('playsinline', '');
            eyeVideo.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;';
            document.body.appendChild(eyeVideo);
            eyeVideo.play().catch(function(){});

            eyeCanvas = document.createElement('canvas');
            eyeCanvas.width  = 160;
            eyeCanvas.height = 120;
            eyeCtx = eyeCanvas.getContext('2d');

            eyePipEl = document.createElement('div');
            eyePipEl.className = 'sa-cam-pip sa-eye-pip';
            eyePipEl.title = 'Eye Tracking: अनुहार माथि = scroll up | अनुहार तला = scroll down';
            eyePipEl.innerHTML =
                '<video autoplay muted playsinline style="width:100%;height:auto;display:block;transform:scaleX(-1);border-radius:6px 6px 0 0;"></video>' +
                '<div class="sa-cam-zones">' +
                  '<div class="sa-cam-zone sa-cam-zone--up">⬆ माथि</div>' +
                  '<div class="sa-cam-zone sa-cam-zone--down">⬇ तला</div>' +
                '</div>' +
                '<div class="sa-cam-motion" id="saEyeMotion">...</div>';
            document.body.appendChild(eyePipEl);
            eyePipEl.querySelector('video').srcObject = stream;

            eyeInterval = setInterval(analyzeEyeFrame, 1000 / EYE_FPS);
            showToast('👁 Eye mode — अनुहार माथि/तल सार्नुहोस्', 'success');
        }).catch(function (err) {
            var msg = err.name === 'NotAllowedError' ? 'Camera permission दिनुहोस्' : 'Camera खोल्न सकिएन';
            showToast('👁 ' + msg, 'error');
            state.eye = false; updateButtons();
        });
        return true;
    }

    function stopEye() {
        clearInterval(eyeInterval); eyeInterval = null;
        if (eyeStream) { eyeStream.getTracks().forEach(function(t){ t.stop(); }); eyeStream = null; }
        if (eyeVideo)  { eyeVideo.remove();  eyeVideo  = null; }
        if (eyePipEl)  { eyePipEl.remove();  eyePipEl  = null; }
        showToast('👁 Eye tracking बन्द भयो', 'info');
    }

    /* ─────────────────────────────────────────────────────────
       PERMISSION GUIDE — Mobile मा "Allow" कहाँ थिच्ने देखाउँछ
       Bottom-sheet overlay → user ले "Allow गर्नुहोस्" थिच्दा
       browser को native permission dialog trigger हुन्छ
    ──────────────────────────────────────────────────────── */
    function showPermissionGuide(type, onAllow, onCancel) {
        /* पहिले नै खुलेको छ भने हटाउने */
        var old = document.getElementById('saPermGuide');
        if (old) old.remove();

        var icons = { camera: '📷', eye: '👁', tilt: '📱' };
        var titles = {
            camera: 'क्यामेरा अनुमति चाहिन्छ',
            eye:    'आँखा ट्र्याकिङ अनुमति चाहिन्छ',
            tilt:   'झुकाव Sensor अनुमति चाहिन्छ',
        };
        var descs = {
            camera: 'Browser ले क्यामेरा प्रयोग गर्न अनुमति माग्नेछ।\nमाथि देखिने popup मा <b>Allow / अनुमति दिनुहोस्</b> थिच्नुहोस्।',
            eye:    'Browser ले क्यामेरा प्रयोग गर्न अनुमति माग्नेछ।\nमाथि देखिने popup मा <b>Allow / अनुमति दिनुहोस्</b> थिच्नुहोस्।',
            tilt:   'iOS Safari ले Motion Sensor अनुमति माग्नेछ।\nदेखिने dialog मा <b>Allow / अनुमति दिनुहोस्</b> थिच्नुहोस्।',
        };

        var guide = document.createElement('div');
        guide.id = 'saPermGuide';
        guide.setAttribute('role', 'dialog');
        guide.setAttribute('aria-modal', 'true');
        var isDesktop = (window.innerWidth || 0) >= 768;
        guide.style.cssText = isDesktop
            ? [
                'position:fixed','top:50%','left:50%','transform:translate(-50%,-50%)',
                'z-index:99999','background:#fff','border-radius:18px',
                'box-shadow:0 18px 70px rgba(0,0,0,0.35)',
                'padding:18px 18px 18px','text-align:center',
                'animation:saGuideInCenter .18s ease',
                'width:min(520px,92vw)','max-height:min(520px,86vh)','overflow-y:auto'
              ].join(';')
            : [
                'position:fixed','bottom:0','left:0','right:0','z-index:99999',
                'background:#fff','border-radius:20px 20px 0 0',
                'box-shadow:0 -8px 40px rgba(0,0,0,0.25)',
                'padding:24px 20px 32px','text-align:center',
                'animation:saGuideIn .25s ease',
                'max-height:90vh','overflow-y:auto'
              ].join(';');

        /* Backdrop */
        var backdrop = document.createElement('div');
        backdrop.id = 'saPermBackdrop';
        backdrop.style.cssText = 'position:fixed;inset:0;z-index:99998;background:rgba(0,0,0,0.45);';
        document.body.appendChild(backdrop);

        guide.innerHTML =
            /* Drag handle (mobile only) */
            (isDesktop ? '' : '<div style="width:40px;height:4px;background:#ddd;border-radius:2px;margin:0 auto 20px;"></div>') +
            /* Icon */
            '<div style="font-size:' + (isDesktop ? '2.6rem' : '3rem') + ';margin-bottom:10px;">' + (icons[type] || '🔐') + '</div>' +
            /* Title */
            '<h3 style="margin:0 0 10px;font-size:' + (isDesktop ? '1.05rem' : '1.15rem') + ';color:#1a5f2a;font-weight:700;">' + (titles[type] || 'अनुमति चाहिन्छ') + '</h3>' +
            /* Browser indicator arrow — shows where Allow appears */
            '<div style="background:#f0f8f2;border:2px solid #1a5f2a;border-radius:12px;padding:12px 14px;margin:12px 0;font-size:.85rem;color:#333;text-align:left;">' +
                '<div style="display:flex;align-items:flex-start;gap:10px;">' +
                    '<span style="font-size:1.5rem;flex-shrink:0;">☝️</span>' +
                    '<div>' +
                        '<div style="font-weight:700;color:#1a5f2a;margin-bottom:4px;">Browser मा यसरी आउँछ:</div>' +
                        '<div style="display:flex;align-items:center;background:#e8f5e9;border-radius:8px;padding:8px 10px;margin-bottom:6px;gap:8px;">' +
                            '<span style="font-size:.8rem;flex:1;color:#444;">"[Site] wants to use your camera"</span>' +
                            '<span style="background:#1a6ec8;color:#fff;padding:4px 10px;border-radius:5px;font-size:.8rem;font-weight:700;white-space:nowrap;">Allow</span>' +
                        '</div>' +
                        '<div style="font-size:.78rem;color:#888;line-height:1.45;">' + (descs[type] || '') + '</div>' +
                        '<div style="margin-top:8px;font-size:.76rem;color:#6b7280;line-height:1.4;">' +
                          'यदि पहिले <b>Block</b> भएको छ भने Browser को site settings बाट Camera/Motion permission पुन: Allow गर्नुहोस्।' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            /* Action buttons */
            '<button id="saPermAllow" style="display:block;width:100%;padding:' + (isDesktop ? '12px' : '15px') + ';background:linear-gradient(135deg,#1a5f2a,#2e8b4a);color:#fff;border:none;border-radius:12px;font-size:' + (isDesktop ? '.95rem' : '1rem') + ';font-weight:800;cursor:pointer;margin-bottom:10px;box-shadow:0 4px 15px rgba(26,95,42,.3);">✅ &nbsp;Allow गर्नुहोस् (अनुमति दिनुहोस्)</button>' +
            '<button id="saPermCancel" style="display:block;width:100%;padding:' + (isDesktop ? '10px' : '12px') + ';background:transparent;color:#6b7280;border:1px solid #ddd;border-radius:12px;font-size:' + (isDesktop ? '.85rem' : '.9rem') + ';cursor:pointer;">रद्द गर्नुहोस्</button>';

        document.body.appendChild(guide);

        /* Inject keyframe if not done */
        if (!document.getElementById('saPermGuideStyle')) {
            var s = document.createElement('style');
            s.id = 'saPermGuideStyle';
            s.textContent =
                '@keyframes saGuideIn { from { transform:translateY(100%); opacity:0; } to { transform:translateY(0); opacity:1; } }' +
                '@keyframes saGuideInCenter { from { transform:translate(-50%,-46%); opacity:0; } to { transform:translate(-50%,-50%); opacity:1; } }';
            document.head.appendChild(s);
        }

        function closeGuide() {
            guide.style.animation = 'none';
            guide.style.transform = 'translateY(100%)';
            guide.style.transition = 'transform .2s ease';
            setTimeout(function () { guide.remove(); backdrop.remove(); }, 200);
        }

        document.getElementById('saPermAllow').addEventListener('click', function () {
            closeGuide();
            onAllow && onAllow();
        });
        document.getElementById('saPermCancel').addEventListener('click', function () {
            closeGuide();
            onCancel && onCancel();
        });
        backdrop.addEventListener('click', function () {
            closeGuide();
            onCancel && onCancel();
        });
    }

    function toggleEye() {
        if (!state.eye) {
            showPermissionGuide('eye',
                function () { /* Allow clicked */
                    var ok = startEye();
                    if (ok) { state.eye = true; } else { state.eye = false; }
                    updateButtons();
                },
                function () { /* Cancel */
                    state.eye = false; updateButtons();
                }
            );
        } else {
            state.eye = false;
            stopEye();
            updateButtons();
        }
    }

    function toggleCamera() {
        if (!state.camera) {
            showPermissionGuide('camera',
                function () { /* Allow clicked */
                    var ok = startCamera();
                    if (ok) { state.camera = true; } else { state.camera = false; }
                    updateButtons();
                },
                function () { /* Cancel */
                    state.camera = false; updateButtons();
                }
            );
        } else {
            state.camera = false;
            stopCamera();
            updateButtons();
        }
    }

    /* ─────────────────────────────────────────
       3. MOBILE TILT SCROLL
    ───────────────────────────────────────── */
    var tiltBase = null, lastTiltScroll = 0;

    function handleOrientation(e) {
        var beta = e.beta;
        if (beta === null) return;
        if (tiltBase === null) {
            tiltBase = beta;
            showToast('📱 Tilt calibrated — phone अगाडि झुकाउनुहोस् तला जान', 'success');
            return;
        }
        var delta = beta - tiltBase;
        if (Math.abs(delta) < TILT_DEADZONE) return;
        var now = Date.now();
        var tiltCooldown = { slow: 220, normal: 130, fast: 70 }[currentSpeed] || 130; /* 280→130 normal */
        if (now - lastTiltScroll < tiltCooldown) return;
        lastTiltScroll = now;

        var speedFactor = SPEEDS[currentSpeed].tiltMulti;
        var intensity   = Math.min(Math.abs(delta) / TILT_MAX, 1.0);
        var base        = SPEEDS[currentSpeed].step;
        var scrollAmt   = Math.round(base * (0.5 + 0.5 * intensity) * speedFactor);

        window.scrollBy({ top: delta > 0 ? scrollAmt : -scrollAmt, behavior: 'auto' }); /* smooth→auto */
    }

    /* Orientation change handler — portrait/landscape rotate गर्दा tiltBase reset */
    function _onOrientationChange() {
        tiltBase = null; /* recalibrate on next event */
        showToast('📱 Phone घुमाइयो — tilt recalibrated', 'info');
    }

    function startTilt() {
        if (typeof DeviceOrientationEvent !== 'undefined' &&
            typeof DeviceOrientationEvent.requestPermission === 'function') {
            DeviceOrientationEvent.requestPermission()
            .then(function (perm) {
                if (perm === 'granted') {
                    tiltBase = null;
                    window.addEventListener('deviceorientation', handleOrientation);
                    window.addEventListener('orientationchange', _onOrientationChange);
                    showToast('📱 Tilt active — phone झुकाउनुहोस्', 'success');
                } else {
                    showToast('📱 Permission denied — Settings मा Motion Allow गर्नुहोस्', 'error');
                    state.tilt = false; updateButtons();
                }
            }).catch(function () {
                showToast('📱 Permission error — Page reload गरी retry गर्नुहोस्', 'error');
                state.tilt = false; updateButtons();
            });
        } else if ('DeviceOrientationEvent' in window) {
            tiltBase = null;
            window.addEventListener('deviceorientation', handleOrientation);
            window.addEventListener('orientationchange', _onOrientationChange);
            showToast('📱 Tilt active — phone अगाडि झुकाउनुहोस् तला जान', 'success');
        } else {
            showToast('📱 यो device मा Tilt sensor काम गर्दैन।', 'error');
            return false;
        }
        return true;
    }

    function stopTilt() {
        window.removeEventListener('deviceorientation', handleOrientation);
        window.removeEventListener('orientationchange', _onOrientationChange);
        tiltBase = null;
        showToast('📱 Tilt बन्द भयो', 'info');
    }

    function toggleTilt() {
        if (!state.tilt) {
            /* iOS मा requestPermission() चाहिन्छ — guide पहिले देखाउने */
            var needsGuide = (typeof DeviceOrientationEvent !== 'undefined' &&
                typeof DeviceOrientationEvent.requestPermission === 'function');
            if (needsGuide) {
                showPermissionGuide('tilt',
                    function () { /* Allow clicked — now trigger iOS permission */
                        var ok = startTilt();
                        if (ok) { state.tilt = true; } else { state.tilt = false; }
                        updateButtons();
                    },
                    function () { state.tilt = false; updateButtons(); }
                );
            } else {
                /* Android/others — direct (no system dialog needed) */
                var ok = startTilt();
                state.tilt = ok ? true : false;
                updateButtons();
            }
        } else {
            state.tilt = false;
            stopTilt();
            updateButtons();
        }
    }

    /* ─────────────────────────────────────────
       UI — Floating Control Panel
    ───────────────────────────────────────── */
    function updateButtons() {
        var btnV = document.getElementById('saBtnVoice');
        var btnC = document.getElementById('saBtnCamera');
        var btnE = document.getElementById('saBtnEye');
        var btnT = document.getElementById('saBtnTilt');
        if (btnV) {
            btnV.classList.toggle('sa-btn--active', state.voice);
            btnV.setAttribute('aria-pressed', String(state.voice));
        }
        if (btnC) {
            btnC.classList.toggle('sa-btn--active', state.camera);
            btnC.setAttribute('aria-pressed', String(state.camera));
        }
        if (btnE) {
            btnE.classList.toggle('sa-btn--active', state.eye);
            btnE.setAttribute('aria-pressed', String(state.eye));
        }
        if (btnT) {
            btnT.classList.toggle('sa-btn--active', state.tilt);
            btnT.setAttribute('aria-pressed', String(state.tilt));
        }
        /* Toggle button glow when any feature is active */
        var tog = document.getElementById('saPanelToggle');
        if (tog) {
            var anyActive = state.voice || state.camera || state.eye || state.tilt;
            tog.classList.toggle('sa-toggle-btn--live', anyActive);
        }
        updateSpeedUI();
    }

    /* Speed toggle pills */
    function updateSpeedUI() {
        ['slow','normal','fast'].forEach(function (key) {
            var el = document.getElementById('saSpeed-' + key);
            if (!el) return;
            el.classList.toggle('sa-speed--active', currentSpeed === key);
        });
        /* Continuous indicator */
        updateContIndicator(continuousDir);
    }

    /* Continuous scroll indicator dot */
    function updateContIndicator(dir) {
        var ind = document.getElementById('saContInd');
        if (!ind) return;
        if (dir) {
            ind.textContent = dir === 'up' ? '⬆⬆ जारी' : '⬇⬇ जारी';
            ind.style.display = 'block';
        } else {
            ind.style.display = 'none';
        }
    }

    function buildUI() {
        var panel = document.createElement('div');
        panel.id        = 'scrollAccessibilityPanel';
        panel.className = 'sa-panel sa-collapsed';
        panel.setAttribute('role', 'toolbar');
        panel.setAttribute('aria-label', 'Scroll Accessibility Controls');

        panel.innerHTML =
            /* ── Toggle handle (always visible) ── */
            '<button id="saPanelToggle" class="sa-toggle-btn" aria-expanded="false" title="Accessibility Scroll Controls&#10;आवाज / क्यामेरा / छिटो / बिस्तारै">' +
                '<i class="fas fa-universal-access sa-toggle-icon"></i>' +
            '</button>' +

            /* ── Expandable section ── */
            '<div id="saPanelControls" class="sa-controls" aria-hidden="true">' +

                /* Section label */
                '<div class="sa-section-label">स्क्रोल</div>' +

                /* ── UP / DOWN scroll buttons ── */
                '<div class="sa-scroll-row">' +
                    '<button id="saBtnUp"   class="sa-scroll-btn" title="माथि जानुहोस् (Scroll Up)">' +
                        '<i class="fas fa-chevron-up"></i>' +
                        '<span class="sa-scroll-label">माथि</span>' +
                    '</button>' +
                    '<button id="saBtnDown" class="sa-scroll-btn" title="तला जानुहोस् (Scroll Down)">' +
                        '<i class="fas fa-chevron-down"></i>' +
                        '<span class="sa-scroll-label">तला</span>' +
                    '</button>' +
                '</div>' +

                /* Divider */
                '<div class="sa-divider"></div>' +

                /* Section label */
                '<div class="sa-section-label">गति</div>' +

                /* Speed control row — 3 pills */
                '<div class="sa-speed-row">' +
                    '<button id="saSpeed-slow"   class="sa-speed" data-speed="slow"   title="बिस्तारै (slow)">' +
                        '<i class="fas fa-gauge-simple-low"></i>' +
                    '</button>' +
                    '<button id="saSpeed-normal" class="sa-speed sa-speed--active" data-speed="normal" title="सामान्य (normal)">' +
                        '<i class="fas fa-gauge"></i>' +
                    '</button>' +
                    '<button id="saSpeed-fast"   class="sa-speed" data-speed="fast"   title="छिटो (fast)">' +
                        '<i class="fas fa-bolt"></i>' +
                    '</button>' +
                '</div>' +

                /* Divider */
                '<div class="sa-divider"></div>' +

                /* Section label */
                '<div class="sa-section-label">नियन्त्रण</div>' +

                /* Continuous indicator (hidden by default) */
                '<div id="saContInd" class="sa-cont-ind" style="display:none;"></div>' +

                /* Voice + Camera — top row */
                '<div class="sa-btn-row">' +
                    '<button id="saBtnVoice" class="sa-btn" aria-pressed="false" ' +
                        'title="नेपाली Voice Scroll&#10;माथि / तला / छिटो / बिस्तारै / जारी माथि / रोक">' +
                        '<i class="fas fa-microphone sa-btn-icon"></i>' +
                        '<span class="sa-btn-label">आवाज</span>' +
                    '</button>' +
                    '<button id="saBtnCamera" class="sa-btn" aria-pressed="false" ' +
                        'title="हात Gesture Scroll&#10;हात माथि = scroll up&#10;हात तला = scroll down">' +
                        '<i class="fas fa-hand sa-btn-icon"></i>' +
                        '<span class="sa-btn-label">हात</span>' +
                    '</button>' +
                '</div>' +

                /* Eye + Tilt — bottom row */
                '<div class="sa-btn-row">' +
                    '<button id="saBtnEye" class="sa-btn" aria-pressed="false" ' +
                        'title="अनुहार Tracking Scroll&#10;अनुहार माथि = scroll up&#10;अनुहार तला = scroll down">' +
                        '<i class="fas fa-eye sa-btn-icon"></i>' +
                        '<span class="sa-btn-label">आँखा</span>' +
                    '</button>' +
                    '<button id="saBtnTilt" class="sa-btn" aria-pressed="false" ' +
                        'title="Mobile Tilt Scroll&#10;फोन अगाडि झुकाउनुस् = scroll down&#10;फोन पछाडि = scroll up">' +
                        '<i class="fas fa-mobile-screen-button sa-btn-icon"></i>' +
                        '<span class="sa-btn-label">झुकाव</span>' +
                    '</button>' +
                '</div>' +

                /* Help row */
                '<div class="sa-hint"><i class="fas fa-circle-info me-1"></i>आवाज · हात · आँखा · झुकाव</div>' +

            '</div>';

        toastEl = document.createElement('div');
        toastEl.className = 'sa-toast';
        toastEl.setAttribute('role', 'status');
        toastEl.setAttribute('aria-live', 'polite');

        document.body.appendChild(panel);
        document.body.appendChild(toastEl);

        /* Panel toggle */
        var panelToggle   = document.getElementById('saPanelToggle');
        var panelControls = document.getElementById('saPanelControls');
        panelToggle.addEventListener('click', function () {
            var collapsed = panel.classList.contains('sa-collapsed');
            panel.classList.toggle('sa-collapsed',  !collapsed);
            panel.classList.toggle('sa-expanded',    collapsed);
            panelToggle.setAttribute('aria-expanded', collapsed ? 'true' : 'false');
            panelControls.setAttribute('aria-hidden',  collapsed ? 'false' : 'true');
        });

        /* Speed pills */
        ['slow','normal','fast'].forEach(function (key) {
            document.getElementById('saSpeed-' + key).addEventListener('click', function () {
                setSpeed(key);
                updateButtons();
            });
        });

        /* ── माथि / तला scroll buttons — click + hold ── */
        (function () {
            var holdTimer = null;
            var holdInterval = null;
            function startHold(dir) {
                doScroll(dir);
                holdTimer = setTimeout(function () {
                    holdInterval = setInterval(function () { doScroll(dir); }, 180);
                }, 400);
            }
            function stopHold() {
                clearTimeout(holdTimer);
                clearInterval(holdInterval);
                holdTimer = null; holdInterval = null;
            }
            ['Up','Down'].forEach(function (d) {
                var btn = document.getElementById('saBtn' + d);
                var dir = d.toLowerCase();
                /* mousedown/touchstart handles scroll (removed redundant click to prevent double-scroll) */
                btn.addEventListener('mousedown',  function () { startHold(dir); });
                btn.addEventListener('touchstart', function (e) { e.preventDefault(); startHold(dir); }, { passive: false });
                btn.addEventListener('mouseup',   stopHold);
                btn.addEventListener('mouseleave', stopHold);
                btn.addEventListener('touchend',  stopHold);
            });
        })();

        /* Voice / Camera */
        document.getElementById('saBtnVoice').addEventListener('click',  toggleVoice);
        document.getElementById('saBtnCamera').addEventListener('click', toggleCamera);
        document.getElementById('saBtnEye').addEventListener('click',    toggleEye);
        document.getElementById('saBtnTilt').addEventListener('click',   toggleTilt);

        /* Keyboard */
        panel.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') e.target.click();
        });
    }

    /* ─────────────────────────────────────────
       INLINE STYLES — full design
    ───────────────────────────────────────── */
    function injectStyles() {
        var css = [
            /* Panel wrapper */
            '#scrollAccessibilityPanel.sa-panel {',
            '  position: fixed;',
            '  bottom: 20px;',
            '  left: 15px;',
            '  z-index: 9980;',
            '  display: flex;',
            '  flex-direction: column;',
            '  align-items: center;',
            '  gap: 0;',
            '  background: transparent;',
            '}',

            /* Toggle button */
            '.sa-toggle-btn {',
            '  width: 42px;',
            '  height: 42px;',
            '  border-radius: 50%;',
            '  background: linear-gradient(135deg, #1a5f2a, #2eb554);',
            '  border: 2px solid rgba(255,255,255,0.35);',
            '  color: #fff;',
            '  font-size: 1.1rem;',
            '  cursor: pointer;',
            '  display: flex;',
            '  align-items: center;',
            '  justify-content: center;',
            '  box-shadow: 0 3px 14px rgba(26,95,42,0.55);',
            '  transition: transform 0.2s, box-shadow 0.2s, border-color 0.3s;',
            '  flex-shrink: 0;',
            '  order: 2;',
            '  position: relative;',
            '}',
            '.sa-toggle-btn:hover { transform: scale(1.1); box-shadow: 0 5px 20px rgba(26,95,42,0.7); }',
            '.sa-toggle-btn--live { border-color: #ffd700 !important; box-shadow: 0 0 0 3px rgba(255,215,0,0.35), 0 3px 14px rgba(26,95,42,0.55) !important; animation: sa-live-pulse 2s ease-in-out infinite; }',
            '@keyframes sa-live-pulse { 0%,100%{ box-shadow: 0 0 0 3px rgba(255,215,0,0.35), 0 3px 14px rgba(26,95,42,0.55); } 50%{ box-shadow: 0 0 0 6px rgba(255,215,0,0.15), 0 3px 14px rgba(26,95,42,0.55); } }',
            '.sa-toggle-icon { line-height: 1; display: block; }',

            /* Controls container */
            '.sa-controls {',
            '  display: flex;',
            '  flex-direction: column;',
            '  gap: 5px;',
            '  background: rgba(20,50,28,0.95);',
            '  backdrop-filter: blur(10px);',
            '  border-radius: 14px;',
            '  padding: 9px 7px;',
            '  box-shadow: 0 6px 24px rgba(0,0,0,0.3);',
            '  margin-bottom: 7px;',
            '  order: 1;',
            '  transform-origin: bottom center;',
            '  transition: opacity 0.22s ease, transform 0.22s ease, max-height 0.32s ease, padding 0.22s ease;',
            '  overflow: hidden;',
            '  border: 1px solid rgba(74,222,128,0.15);',
            '  min-width: 96px;',
            '}',

            /* Section label */
            '.sa-section-label {',
            '  font-size: 0.5rem;',
            '  font-weight: 800;',
            '  letter-spacing: 0.7px;',
            '  text-transform: uppercase;',
            '  color: rgba(74,222,128,0.55);',
            '  text-align: center;',
            '  padding: 0 2px 1px;',
            '}',

            /* Divider */
            '.sa-divider {',
            '  height: 1px;',
            '  background: rgba(255,255,255,0.08);',
            '  margin: 1px 2px;',
            '}',

            /* ── Scroll UP / DOWN row ── */
            '.sa-scroll-row {',
            '  display: flex;',
            '  gap: 5px;',
            '  justify-content: center;',
            '}',
            '.sa-scroll-btn {',
            '  flex: 1;',
            '  display: flex;',
            '  flex-direction: column;',
            '  align-items: center;',
            '  justify-content: center;',
            '  gap: 2px;',
            '  background: rgba(255,255,255,0.08);',
            '  border: 1px solid rgba(255,255,255,0.14);',
            '  border-radius: 9px;',
            '  color: #fff;',
            '  padding: 7px 4px;',
            '  cursor: pointer;',
            '  font-size: 0.85rem;',
            '  transition: all 0.16s;',
            '  user-select: none;',
            '  -webkit-user-select: none;',
            '}',
            '.sa-scroll-btn:hover { background: rgba(74,222,128,0.2); border-color: rgba(74,222,128,0.4); transform: scale(1.05); }',
            '.sa-scroll-btn:active { background: rgba(74,222,128,0.35); transform: scale(0.97); }',
            '.sa-scroll-label {',
            '  font-size: 0.52rem;',
            '  font-weight: 700;',
            '  letter-spacing: 0.2px;',
            '  color: rgba(255,255,255,0.75);',
            '}',

            /* ── Voice + Camera btn row ── */
            '.sa-btn-row {',
            '  display: flex;',
            '  gap: 5px;',
            '}',
            '.sa-btn-row .sa-btn {',
            '  flex: 1;',
            '}',

            /* Collapsed */
            '.sa-collapsed .sa-controls {',
            '  opacity: 0;',
            '  max-height: 0;',
            '  transform: scaleY(0.8) translateY(8px);',
            '  padding: 0 8px;',
            '  margin-bottom: 0;',
            '  pointer-events: none;',
            '}',

            /* Expanded */
            '.sa-expanded .sa-controls {',
            '  opacity: 1;',
            '  max-height: 420px;',
            '  transform: scaleY(1) translateY(0);',
            '  pointer-events: auto;',
            '}',

            /* ── Speed row ── */
            '.sa-speed-row {',
            '  display: flex;',
            '  gap: 5px;',
            '  justify-content: center;',
            '  background: rgba(255,255,255,0.06);',
            '  border-radius: 10px;',
            '  padding: 5px 4px;',
            '}',

            '.sa-speed {',
            '  flex: 1;',
            '  height: 32px;',
            '  border: 1px solid rgba(255,255,255,0.12);',
            '  border-radius: 8px;',
            '  background: transparent;',
            '  color: rgba(255,255,255,0.65);',
            '  font-size: 1rem;',
            '  cursor: pointer;',
            '  display: flex;',
            '  align-items: center;',
            '  justify-content: center;',
            '  transition: all 0.18s;',
            '  line-height: 1;',
            '}',

            '.sa-speed:hover {',
            '  background: rgba(255,255,255,0.12);',
            '  color: #fff;',
            '  transform: scale(1.06);',
            '}',

            /* Active speed pill — glowing gold */
            '.sa-speed--active {',
            '  background: rgba(255,215,0,0.18) !important;',
            '  border-color: #ffd700 !important;',
            '  color: #ffd700 !important;',
            '  box-shadow: 0 0 8px rgba(255,215,0,0.35);',
            '}',

            /* ── Continuous scroll indicator ── */
            '.sa-cont-ind {',
            '  text-align: center;',
            '  font-size: 0.62rem;',
            '  font-weight: 700;',
            '  color: #4ade80;',
            '  background: rgba(74,222,128,0.12);',
            '  border-radius: 8px;',
            '  padding: 3px 6px;',
            '  letter-spacing: 0.3px;',
            '  animation: sa-blink 1s ease-in-out infinite;',
            '}',
            '@keyframes sa-blink {',
            '  0%,100% { opacity: 1; }',
            '  50%      { opacity: 0.45; }',
            '}',

            /* ── Feature buttons ── */
            '.sa-btn {',
            '  display: flex;',
            '  flex-direction: column;',
            '  align-items: center;',
            '  gap: 3px;',
            '  background: rgba(255,255,255,0.08);',
            '  border: 1px solid rgba(255,255,255,0.15);',
            '  border-radius: 9px;',
            '  color: #fff;',
            '  padding: 7px 6px;',
            '  cursor: pointer;',
            '  transition: all 0.18s;',
            '  min-width: 42px;',
            '  font-family: inherit;',
            '}',
            '.sa-btn:hover { background: rgba(74,222,128,0.18); border-color: rgba(74,222,128,0.35); transform: scale(1.06); }',
            '.sa-btn--active { background: linear-gradient(135deg,#f59e0b,#ffd700) !important; border-color: #ffc107 !important; color: #14532d !important; box-shadow: 0 0 12px rgba(255,215,0,0.55); }',
            '.sa-btn-icon  { font-size: 1.1rem; line-height: 1; }',
            '.sa-btn-label { font-size: 0.58rem; font-weight: 700; letter-spacing: 0.3px; line-height: 1; }',

            /* ── Hint text ── */
            '.sa-hint {',
            '  text-align: center;',
            '  font-size: 0.58rem;',
            '  color: rgba(255,255,255,0.3);',
            '  letter-spacing: 0.2px;',
            '  padding: 0 2px;',
            '}',

            /* ── Toast ── */
            '.sa-toast {',
            '  position: fixed;',
            '  bottom: 100px;',
            '  left: 50%;',
            '  transform: translateX(-50%) translateY(20px);',
            '  background: rgba(20,20,20,0.92);',
            '  color: #fff;',
            '  padding: 10px 20px;',
            '  border-radius: 50px;',
            '  font-size: 0.88rem;',
            '  font-weight: 600;',
            '  white-space: nowrap;',
            '  z-index: 10000;',
            '  opacity: 0;',
            '  transition: opacity 0.22s ease, transform 0.22s ease;',
            '  pointer-events: none;',
            '  max-width: 90vw;',
            '  text-align: center;',
            '  box-shadow: 0 4px 20px rgba(0,0,0,0.35);',
            '}',
            '.sa-toast--show { opacity: 1 !important; transform: translateX(-50%) translateY(0) !important; }',
            '.sa-toast--success { border-left: 4px solid #4caf50; }',
            '.sa-toast--error   { border-left: 4px solid #f44336; }',
            '.sa-toast--info    { border-left: 4px solid #2196f3; }',

            /* ── Camera PIP ── */
            '.sa-cam-pip { position:fixed; top:70px; right:12px; width:130px; z-index:9985; border-radius:10px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.3); border:2px solid #1a5f2a; background:#000; }',
            '.sa-eye-pip { top:240px; border-color:#7c3aed; }', /* Eye PIP below camera PIP */
            '.sa-cam-zones { display:flex; flex-direction:column; position:absolute; top:0; left:0; right:0; height:calc(100% - 22px); pointer-events:none; }',
            '.sa-cam-zone { flex:1; display:flex; align-items:center; justify-content:center; font-size:0.65rem; font-weight:700; color:rgba(255,255,255,0.5); border-bottom:1px dashed rgba(255,255,255,0.2); transition:background 0.15s, color 0.15s; }',
            '.sa-cam-zone--active { background:rgba(255,215,0,0.3) !important; color:#ffd700 !important; }',
            '.sa-cam-zone--down { border-bottom:none; }',
            '.sa-cam-motion { background:rgba(26,95,42,0.9); color:#fff; text-align:center; font-size:0.7rem; padding:3px 4px; font-weight:700; }',

            /* ── Mobile responsive ── */
            '@media (max-width: 576px) {',
            '  #scrollAccessibilityPanel.sa-panel { bottom: 18px; left: 6px; }',
            '  .sa-toggle-btn { width: 40px; height: 40px; font-size: 1.05rem; }',
            '  .sa-controls { min-width: 104px; padding: 7px 5px; }',
            '  .sa-btn { min-width: 34px; padding: 7px 3px; }',
            '  .sa-btn-icon { font-size: 1rem; }',
            '  .sa-btn-label { font-size: 0.5rem; }',
            '  .sa-scroll-btn { padding: 6px 3px; font-size: 0.82rem; }',
            '  .sa-cam-pip { width: 100px; top: 60px; right: 8px; }',
            '  .sa-eye-pip { top: 210px; width: 100px; }',
            '  .sa-speed { height: 28px; font-size: 0.82rem; }',
            '}'
        ].join('\n');

        var s    = document.createElement('style');
        s.id     = 'sa-styles';
        s.textContent = css;
        document.head.appendChild(s);
    }

    /* ─────────────────────────────────────────
       INIT
    ───────────────────────────────────────── */
    function init() {
        injectStyles();
        buildUI();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
