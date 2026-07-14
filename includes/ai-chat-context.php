<?php
/**
 * Build a live, keyword-ranked context pack from this site's public DB content.
 * No member/financial PII. Cap ~7k chars for LLM grounding.
 */

if (!function_exists('ai_chat_normalize_text')) {
    function ai_chat_normalize_text(string $s): string
    {
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }
}

if (!function_exists('ai_chat_query_tokens')) {
    /** @return list<string> */
    function ai_chat_query_tokens(string $q): array
    {
        $q = mb_strtolower(ai_chat_normalize_text($q));
        $parts = preg_split('/[\s\-–,.;:!?\/\\\\|()\[\]{}"\'«»]+/u', $q) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || mb_strlen($p) < 2) {
                continue;
            }
            $out[$p] = true;
        }
        return array_keys($out);
    }
}

if (!function_exists('ai_chat_score_text')) {
    function ai_chat_score_text(string $haystack, array $tokens): int
    {
        if ($tokens === []) {
            return 0;
        }
        $h = mb_strtolower($haystack);
        $score = 0;
        foreach ($tokens as $t) {
            if (mb_strpos($h, $t) !== false) {
                $score += mb_strlen($t) >= 4 ? 3 : 2;
            }
        }
        return $score;
    }
}

if (!function_exists('ai_chat_snip')) {
    function ai_chat_snip(string $s, int $max = 320): string
    {
        $s = ai_chat_normalize_text($s);
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 1) . '…';
    }
}

if (!function_exists('ai_chat_collect_candidates')) {
    /**
     * @return list<array{score:int,source:string,label:string,body:string}>
     */
    function ai_chat_collect_candidates(string $question, ?PDO $db = null): array
    {
        $db = $db ?: (function_exists('getDB') ? getDB() : null);
        if (!$db instanceof PDO) {
            return [];
        }
        $tokens = ai_chat_query_tokens($question);
        $english = function_exists('isEnglish') && isEnglish();
        $cands = [];

        $push = static function (string $source, string $label, string $body, int $score) use (&$cands): void {
            $body = ai_chat_normalize_text($body);
            $label = ai_chat_normalize_text($label);
            if ($body === '' && $label === '') {
                return;
            }
            $cands[] = [
                'score' => $score,
                'source' => $source,
                'label' => $label,
                'body' => $body,
            ];
        };

        /* Site identity */
        $site = (string)getSetting($english ? 'site_name_en' : 'site_name', getSetting('site_name', 'सहकारी'));
        $phone = (string)getSetting('phone', getSetting('contact_phone', ''));
        $email = (string)getSetting('email', getSetting('contact_email', ''));
        $addr = (string)getSetting($english ? 'address_en' : 'address', getSetting('address', ''));
        $idBody = "संस्था: {$site}";
        if ($phone !== '') {
            $idBody .= "\nफोन: {$phone}";
        }
        if ($email !== '') {
            $idBody .= "\nइमेल: {$email}";
        }
        if ($addr !== '') {
            $idBody .= "\nठेगाना: {$addr}";
        }
        $push('site', 'संस्था जानकारी', $idBody, max(1, ai_chat_score_text($idBody, $tokens) + 1));

        /* Vision / mission */
        $vision = (string)getSetting($english ? 'vision_content_en' : 'vision_content_np', getSetting('vision_content_np', ''));
        $mission = (string)getSetting($english ? 'mission_content_en' : 'mission_content_np', getSetting('mission_content_np', ''));
        if ($vision !== '') {
            $push('about', 'भिजन', ai_chat_snip($vision, 500), ai_chat_score_text('भिजन vision ' . $vision, $tokens) + 1);
        }
        if ($mission !== '') {
            $push('about', 'मिसन', ai_chat_snip($mission, 500), ai_chat_score_text('मिसन mission ' . $mission, $tokens) + 1);
        }

        $safeQuery = static function (PDO $db, string $sql) {
            try {
                return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                return [];
            }
        };

        foreach ($safeQuery($db, 'SELECT * FROM chatbot_faqs WHERE is_active = 1 ORDER BY display_order, id LIMIT 80') as $row) {
            $q = $english
                ? (string)(($row['question_en'] ?? '') !== '' ? $row['question_en'] : (($row['question_np'] ?? '') !== '' ? $row['question_np'] : ($row['question'] ?? '')))
                : (string)(($row['question_np'] ?? '') !== '' ? $row['question_np'] : (($row['question'] ?? '') !== '' ? $row['question'] : ($row['question_en'] ?? '')));
            $a = $english
                ? (string)(($row['answer_en'] ?? '') !== '' ? $row['answer_en'] : (($row['answer_np'] ?? '') !== '' ? $row['answer_np'] : ($row['answer'] ?? '')))
                : (string)(($row['answer_np'] ?? '') !== '' ? $row['answer_np'] : (($row['answer'] ?? '') !== '' ? $row['answer'] : ($row['answer_en'] ?? '')));
            $kw = (string)($row['keywords'] ?? '');
            $blob = $q . ' ' . $a . ' ' . $kw;
            $score = ai_chat_score_text($blob, $tokens) + ($kw !== '' ? 1 : 0);
            $push('chatbot_faq', $q !== '' ? $q : 'FAQ', ai_chat_snip($a, 420), $score);
        }

        foreach ($safeQuery($db, 'SELECT * FROM faqs WHERE is_active = 1 ORDER BY display_order, id LIMIT 60') as $row) {
            $q = $english
                ? (string)(($row['question_en'] ?? '') !== '' ? $row['question_en'] : (($row['question_np'] ?? '') !== '' ? $row['question_np'] : ($row['question'] ?? '')))
                : (string)(($row['question_np'] ?? '') !== '' ? $row['question_np'] : (($row['question'] ?? '') !== '' ? $row['question'] : ($row['question_en'] ?? '')));
            $a = $english
                ? (string)(($row['answer_en'] ?? '') !== '' ? $row['answer_en'] : (($row['answer_np'] ?? '') !== '' ? $row['answer_np'] : ($row['answer'] ?? '')))
                : (string)(($row['answer_np'] ?? '') !== '' ? $row['answer_np'] : (($row['answer'] ?? '') !== '' ? $row['answer'] : ($row['answer_en'] ?? '')));
            $push('faq', $q !== '' ? $q : 'FAQ', ai_chat_snip($a, 420), ai_chat_score_text($q . ' ' . $a, $tokens));
        }

        foreach ($safeQuery($db, 'SELECT * FROM notices WHERE is_active = 1 ORDER BY id DESC LIMIT 25') as $row) {
            $t = $english
                ? (string)(($row['title_en'] ?? '') !== '' ? $row['title_en'] : (($row['title_np'] ?? '') !== '' ? $row['title_np'] : ($row['title'] ?? '')))
                : (string)(($row['title_np'] ?? '') !== '' ? $row['title_np'] : (($row['title'] ?? '') !== '' ? $row['title'] : ($row['title_en'] ?? '')));
            $c = $english
                ? (string)(($row['content_en'] ?? '') !== '' ? $row['content_en'] : (($row['content_np'] ?? '') !== '' ? $row['content_np'] : ($row['content'] ?? $row['description'] ?? '')))
                : (string)(($row['content_np'] ?? '') !== '' ? $row['content_np'] : (($row['content'] ?? '') !== '' ? $row['content'] : ($row['description'] ?? $row['content_en'] ?? '')));
            $push('notice', $t !== '' ? $t : 'सूचना', ai_chat_snip($c, 360), ai_chat_score_text($t . ' ' . $c . ' सूचना notice', $tokens) + 1);
        }

        foreach ($safeQuery($db, 'SELECT * FROM services WHERE is_active = 1 ORDER BY display_order, id LIMIT 40') as $row) {
            $t = $english
                ? (string)(($row['title_en'] ?? '') !== '' ? $row['title_en'] : (($row['title_np'] ?? '') !== '' ? $row['title_np'] : ($row['title'] ?? '')))
                : (string)(($row['title_np'] ?? '') !== '' ? $row['title_np'] : (($row['title'] ?? '') !== '' ? $row['title'] : ($row['title_en'] ?? '')));
            $d = $english
                ? (string)(($row['description_en'] ?? '') !== '' ? $row['description_en'] : (($row['description_np'] ?? '') !== '' ? $row['description_np'] : (($row['description'] ?? '') !== '' ? $row['description'] : ($row['short_description'] ?? ''))))
                : (string)(($row['description_np'] ?? '') !== '' ? $row['description_np'] : (($row['description'] ?? '') !== '' ? $row['description'] : (($row['short_description'] ?? '') !== '' ? $row['short_description'] : ($row['description_en'] ?? ''))));
            $push('service', $t !== '' ? $t : 'सेवा', ai_chat_snip($d, 360), ai_chat_score_text($t . ' ' . $d . ' सेवा service ऋण बचत', $tokens) + 1);
        }

        foreach ($safeQuery($db, 'SELECT * FROM interest_rates WHERE is_active = 1 ORDER BY display_order, id LIMIT 50') as $row) {
            $t = $english
                ? (string)(($row['title_en'] ?? '') !== '' ? $row['title_en'] : (($row['title_np'] ?? '') !== '' ? $row['title_np'] : ($row['title'] ?? '')))
                : (string)(($row['title_np'] ?? '') !== '' ? $row['title_np'] : (($row['title'] ?? '') !== '' ? $row['title'] : ($row['title_en'] ?? '')));
            $cat = (string)($row['category'] ?? '');
            $rate = (string)($row['rate'] ?? $row['interest_rate'] ?? '');
            $body = trim(($cat !== '' ? "श्रेणी: {$cat}. " : '') . "दर: {$rate}");
            $push('rate', $t !== '' ? $t : 'ब्याजदर', $body, ai_chat_score_text($t . ' ' . $body . ' ब्याजदर interest rate', $tokens) + 2);
        }

        if (is_file(__DIR__ . '/service-centers-helpers.php')) {
            require_once __DIR__ . '/service-centers-helpers.php';
        }
        if (function_exists('fetchActiveServiceCenters')) {
            try {
                $centers = fetchActiveServiceCenters($db, 30);
                foreach ($centers as $sc) {
                    $name = $english
                        ? (string)(($sc['name_en'] ?? '') !== '' ? $sc['name_en'] : ($sc['name'] ?? $sc['name_np'] ?? ''))
                        : (string)(($sc['name_np'] ?? '') !== '' ? $sc['name_np'] : ($sc['name'] ?? $sc['name_en'] ?? ''));
                    $bits = array_filter([
                        $name,
                        (string)($sc['address'] ?? $sc['address_np'] ?? ''),
                        (string)($sc['phone'] ?? ''),
                        (string)($sc['email'] ?? ''),
                    ]);
                    $body = implode(' | ', $bits);
                    $push('branch', $name !== '' ? $name : 'शाखा', $body, ai_chat_score_text($body . ' शाखा branch सेवा केन्द्र', $tokens) + 1);
                }
            } catch (Throwable $e) {
                /* ignore */
            }
        }

        return $cands;
    }
}

if (!function_exists('ai_chat_build_context')) {
    /**
     * @return array{context:string,sources:list<string>}
     */
    function ai_chat_build_context(string $question, ?PDO $db = null, int $maxChars = 7500): array
    {
        $cands = ai_chat_collect_candidates($question, $db);
        usort($cands, static function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return 0;
            }
            return $a['score'] > $b['score'] ? -1 : 1;
        });

        // Always keep a baseline of top identity + rates/services even if score 0
        $picked = [];
        $sources = [];
        $used = 0;
        $minKeep = 12;
        foreach ($cands as $i => $c) {
            if ($c['score'] <= 0 && $i >= $minKeep) {
                continue;
            }
            $block = '[' . $c['source'] . '] ' . $c['label'] . "\n" . $c['body'];
            $len = mb_strlen($block) + 2;
            if ($used + $len > $maxChars && $picked !== []) {
                break;
            }
            $picked[] = $block;
            $sources[] = $c['source'] . ': ' . mb_substr($c['label'], 0, 60);
            $used += $len;
            if (count($picked) >= 28) {
                break;
            }
        }

        return [
            'context' => implode("\n\n---\n\n", $picked),
            'sources' => array_values(array_unique($sources)),
        ];
    }
}
