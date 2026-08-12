<?php
/**
 * Smoke: scroll-accessibility.js v3.1 — voice match + UI harden checks.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;
$pass = 0;

function ok(string $m): void { global $pass; $pass++; echo "OK  $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "FAIL $m\n"; }

$js = (string) file_get_contents($root . '/assets/js/scroll-accessibility.js');

if (strpos($js, 'v3.3') !== false || strpos($js, 'v3.2') !== false || strpos($js, 'v3.1') !== false) {
    ok('version banner present (v3.1+)');
} else {
    bad('expected v3.1+ banner');
}
if (strpos($js, 'v3.3') !== false) {
    ok('version banner v3.3');
} else {
    bad('expected v3.3');
}

foreach (["'color'", "'dollar'", "'mathew'", "'marty'"] as $badWord) {
    if (strpos($js, $badWord) === false) {
        ok("removed noisy phonetic $badWord");
    } else {
        bad("still contains $badWord");
    }
}

if (strpos($js, 'phraseInTranscript') !== false || strpos($js, '(?:^|[^a-z0-9])') !== false) {
    ok('word-boundary voice matching present');
} else {
    bad('word-boundary match missing');
}

if (substr_count($js, 'type="button"') >= 8) {
    ok('buttons use type=button');
} else {
    bad('missing type=button on SA controls');
}

if (strpos($js, 'isTypingInForm') !== false) {
    ok('form-focus pause helper present');
} else {
    bad('isTypingInForm missing');
}

if (strpos($js, 'if (state.camera)') !== false && strpos($js, 'if (state.eye)') !== false) {
    ok('camera/eye mutual exclusion hooks present');
} else {
    bad('camera/eye mutex missing');
}

if (strpos($js, "e.key === 'Escape'") !== false) {
    ok('Escape closes panel');
} else {
    bad('Escape handler missing');
}

if (strpos($js, 'has-bottomnav #scrollAccessibilityPanel') !== false) {
    ok('bottom-nav clearance in injectStyles');
} else {
    bad('bottom-nav clearance missing');
}

if (strpos($js, 'prefers-reduced-motion') !== false) {
    ok('prefers-reduced-motion respected');
} else {
    bad('reduced-motion missing');
}

if (strpos($js, 'lastTouchAt') !== false) {
    ok('touch/mouse double-fire guard present');
} else {
    bad('touch/mouse double-fire guard missing');
}

if (strpos($js, "getElementById('scrollAccessibilityPanel')") !== false
    && preg_match("/if \(document\.getElementById\('scrollAccessibilityPanel'\)\) return/", $js)
) {
    ok('duplicate panel init guard present');
} else {
    bad('duplicate panel init guard missing');
}

if (strpos($js, 'pauseMediaForHiddenTab') !== false) {
    ok('tab-hide pauses camera/mic');
} else {
    bad('tab-hide media pause missing');
}
if (strpos($js, '_eyeCentYSmooth') !== false && strpos($js, 'mean * 0.88') !== false) {
    ok('adaptive eye luminance + EMA present');
} else {
    bad('adaptive eye tracking missing');
}
if (strpos($js, 'VOICE_DEBOUNCE_MS') !== false && strpos($js, 'bestConf') !== false) {
    ok('voice confidence + debounce present');
} else {
    bad('voice harden missing');
}

/* Mirror phrase match for regression cases */
$match = static function (string $transcript, array $words): bool {
    $t = mb_strtolower(trim($transcript), 'UTF-8');
    foreach ($words as $p) {
        $p = mb_strtolower(trim($p), 'UTF-8');
        if ($p === '') continue;
        if (str_contains($p, ' ')) {
            if (str_contains($t, $p)) return true;
            continue;
        }
        if (preg_match('/^[a-z0-9\']+$/i', $p)) {
            if (preg_match('/(?:^|[^a-z0-9])' . preg_quote($p, '/') . '(?:$|[^a-z0-9])/i', $t)) {
                return true;
            }
            continue;
        }
        if ($t === $p || preg_match('/(?:^|[\s,.!?])' . preg_quote($p, '/') . '(?:$|[\s,.!?])/u', $t)) {
            return true;
        }
    }
    return false;
};

$up = ['up', 'scroll up', 'माथि'];
$down = ['down', 'scroll down', 'तला'];

if (!$match('open the group menu', $up)) {
    ok('group does not match up');
} else {
    bad('group still matches up');
}
if (!$match('please download the form', $down)) {
    ok('download does not match down');
} else {
    bad('download still matches down');
}
if ($match('scroll up please', $up)) {
    ok('scroll up still matches');
} else {
    bad('scroll up failed');
}
if ($match('माथि', $up)) {
    ok('माथि still matches');
} else {
    bad('माथि failed');
}
if ($match('go down', $down)) {
    ok('go down still matches');
} else {
    bad('go down failed');
}

/* speed-normal must have words array */
if (preg_match("/SPEED CONTROL: Normal[\s\S]{0,120}words:\s*\[/", $js)) {
    ok('speed-normal words intact');
} else {
    bad('speed-normal words broken');
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);