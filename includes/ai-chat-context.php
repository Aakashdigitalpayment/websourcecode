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

if (!function_exists('ai_chat_expand_tokens')) {
    /**
     * Map English/Nepali leadership & common terms so keyword scoring finds the right DB rows.
     * @return list<string>
     */
    function ai_chat_expand_tokens(string $question): array
    {
        $base = ai_chat_query_tokens($question);
        $q = mb_strtolower(ai_chat_normalize_text($question));
        $extra = [];

        $synonyms = [
            'chairman' => ['chairman', 'chairperson', 'अध्यक्ष', 'अध्यक्षको', 'सञ्चालक', 'board chair'],
            'ceo' => ['ceo', 'chief executive', 'प्रमुख कार्यकारी', 'कार्यकारी अधिकृत', 'pca', 'executive officer', 'व्यवस्थापक'],
            'team' => ['team', 'टोली', 'सदस्य', 'member', 'staff', 'कर्मचारी', 'सञ्चालक समिति', 'board', 'committee', 'समिति'],
            'contact' => ['contact', 'सम्पर्क', 'phone', 'फोन', 'email', 'इमेल', 'officer', 'अधिकारी'],
            'rate' => ['rate', 'ब्याज', 'ब्याजदर', 'interest', 'ऋण', 'बचत', 'loan', 'saving'],
            'service' => ['service', 'सेवा', 'products', 'उत्पादन'],
            'branch' => ['branch', 'शाखा', 'office', 'केन्द्र', 'सेवा केन्द्र'],
        ];

        foreach ($synonyms as $group => $words) {
            foreach ($words as $w) {
                if (mb_strpos($q, mb_strtolower($w)) !== false) {
                    foreach ($words as $w2) {
                        $extra[mb_strtolower($w2)] = true;
                    }
                    break;
                }
            }
        }

        foreach ($base as $t) {
            foreach ($synonyms as $words) {
                if (in_array($t, $words, true)) {
                    foreach ($words as $w) {
                        $extra[$w] = true;
                    }
                }
            }
        }

        return array_values(array_unique(array_merge($base, array_keys($extra))));
    }
}

if (!function_exists('ai_chat_question_is_leadership')) {
    function ai_chat_question_is_leadership(string $question): bool
    {
        return (bool)preg_match(
            '/chairman|chairperson|ceo|chief\s*executive|अध्यक्ष|प्रमुख\s*कार्यकारी|कार्यकारी\s*अधिकृत|नेतृत्व|leadership|who\s+is\s+the/i',
            ai_chat_normalize_text($question)
        );
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

if (!function_exists('ai_chat_resolve_leadership')) {
    /**
     * Same sources as about.php / team.php — settings first, team_members fallback.
     * @return array{chairman_name:string,chairman_role:string,ceo_name:string,ceo_role:string,chairman_msg:string,ceo_msg:string}
     */
    function ai_chat_resolve_leadership(?PDO $db, bool $english): array
    {
        $chairmanName = trim((string)getSetting('chairman_name', ''));
        $ceoName = trim((string)getSetting('ceo_name', ''));
        $chairmanMsg = trim((string)getSetting($english ? 'chairman_message_en' : 'chairman_message_np', getSetting('chairman_message_np', '')));
        $ceoMsg = trim((string)getSetting($english ? 'ceo_message_en' : 'ceo_message_np', getSetting('ceo_message_np', '')));
        $ceoRoleNp = trim((string)getSetting('ceo_designation_np', 'प्रमुख कार्यकारी अधिकृत'));
        $ceoRoleEn = trim((string)getSetting('ceo_designation_en', 'Chief Executive Officer'));
        $ceoRole = $english ? $ceoRoleEn : $ceoRoleNp;
        $chairRole = $english ? 'Chairman' : 'अध्यक्ष';

        if ($db instanceof PDO) {
            try {
                if ($chairmanName === '') {
                    $row = $db->query('SELECT name, name_en, position, position_np, position_en FROM team_members WHERE is_chairman = 1 AND is_active = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $chairmanName = trim((string)($english
                            ? (($row['name_en'] ?? '') !== '' ? $row['name_en'] : ($row['name'] ?? ''))
                            : ($row['name'] ?? $row['name_en'] ?? '')));
                        if ($chairmanMsg === '') {
                            $chairmanMsg = trim((string)($english
                                ? (($row['position_en'] ?? '') !== '' ? $row['position_en'] : ($row['position'] ?? $row['position_np'] ?? ''))
                                : (($row['position_np'] ?? '') !== '' ? $row['position_np'] : ($row['position'] ?? ''))));
                        }
                    }
                }
                if ($ceoName === '') {
                    $row = $db->query('SELECT name, name_en, position, position_np, position_en FROM team_members WHERE is_ceo = 1 AND is_active = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $ceoName = trim((string)($english
                            ? (($row['name_en'] ?? '') !== '' ? $row['name_en'] : ($row['name'] ?? ''))
                            : ($row['name'] ?? $row['name_en'] ?? '')));
                        if ($ceoMsg === '') {
                            $ceoMsg = trim((string)($english
                                ? (($row['position_en'] ?? '') !== '' ? $row['position_en'] : ($row['position'] ?? $row['position_np'] ?? ''))
                                : (($row['position_np'] ?? '') !== '' ? $row['position_np'] : ($row['position'] ?? ''))));
                        }
                    }
                }
            } catch (Throwable $e) {
                /* ignore */
            }
        }

        return [
            'chairman_name' => $chairmanName,
            'chairman_role' => $chairRole,
            'ceo_name' => $ceoName,
            'ceo_role' => $ceoRole,
            'chairman_msg' => $chairmanMsg,
            'ceo_msg' => $ceoMsg,
        ];
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
        $tokens = ai_chat_expand_tokens($question);
        $english = function_exists('isEnglish') && isEnglish();
        $leadershipQ = ai_chat_question_is_leadership($question);
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

        /* Leadership — always in context pack (chairman / CEO from settings + team_members) */
        $lead = ai_chat_resolve_leadership($db, $english);
        $leadLines = [];
        if ($lead['chairman_name'] !== '') {
            $leadLines[] = ($english ? 'Chairman' : 'अध्यक्ष') . ': ' . $lead['chairman_name'];
        }
        if ($lead['ceo_name'] !== '') {
            $leadLines[] = ($english ? 'CEO' : 'प्रमुख कार्यकारी अधिकृत') . ' (' . $lead['ceo_role'] . '): ' . $lead['ceo_name'];
        }
        if ($lead['chairman_msg'] !== '') {
            $leadLines[] = ($english ? 'Chairman message excerpt' : 'अध्यक्षको सन्देश') . ': ' . ai_chat_snip($lead['chairman_msg'], 280);
        }
        if ($lead['ceo_msg'] !== '') {
            $leadLines[] = ($english ? 'CEO message excerpt' : 'प्रमुख कार्यकारीको सन्देश') . ': ' . ai_chat_snip($lead['ceo_msg'], 280);
        }
        if ($leadLines !== []) {
            $leadBody = implode("\n", $leadLines);
            $leadScore = ai_chat_score_text(
                'chairman ceo अध्यक्ष प्रमुख कार्यकारी leadership team ' . $leadBody,
                $tokens
            ) + ($leadershipQ ? 12 : 4);
            $push('leadership', $english ? 'Leadership' : 'नेतृत्व', $leadBody, $leadScore);
        }

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

        /* Team / board / staff — published on team.php */
        foreach ($safeQuery($db, 'SELECT name, name_en, position, position_np, position_en, phone, email, category, is_chairman, is_ceo, is_information_officer, is_grievance_officer FROM team_members WHERE is_active = 1 ORDER BY is_chairman DESC, is_ceo DESC, display_order, id LIMIT 80') as $row) {
            $name = $english
                ? (string)(($row['name_en'] ?? '') !== '' ? $row['name_en'] : ($row['name'] ?? ''))
                : (string)($row['name'] ?? $row['name_en'] ?? '');
            $pos = $english
                ? (string)(($row['position_en'] ?? '') !== '' ? $row['position_en'] : (($row['position'] ?? '') !== '' ? $row['position'] : ($row['position_np'] ?? '')))
                : (string)(($row['position_np'] ?? '') !== '' ? $row['position_np'] : (($row['position'] ?? '') !== '' ? $row['position'] : ($row['position_en'] ?? '')));
            $roles = [];
            if (!empty($row['is_chairman'])) {
                $roles[] = $english ? 'Chairman' : 'अध्यक्ष';
            }
            if (!empty($row['is_ceo'])) {
                $roles[] = $english ? 'CEO' : 'प्रमुख कार्यकारी अधिकृत';
            }
            if (!empty($row['is_information_officer'])) {
                $roles[] = $english ? 'Information Officer' : 'सूचना अधिकारी';
            }
            if (!empty($row['is_grievance_officer'])) {
                $roles[] = $english ? 'Grievance Officer' : 'गुनासो अधिकारी';
            }
            $cat = (string)($row['category'] ?? '');
            $bits = array_filter([$pos, $roles !== [] ? implode(', ', $roles) : '', $cat !== '' ? "category: {$cat}" : '', (string)($row['phone'] ?? ''), (string)($row['email'] ?? '')]);
            $body = implode(' | ', $bits);
            $label = $name !== '' ? $name : ($english ? 'Team member' : 'टोली सदस्य');
            $push('team', $label, $body, ai_chat_score_text($name . ' ' . $body . ' team chairman ceo अध्यक्ष समिति board staff', $tokens) + (!empty($row['is_chairman']) || !empty($row['is_ceo']) ? 3 : 1));
        }

        /* Committee members (committees.php / team.php) */
        foreach ($safeQuery($db, 'SELECT m.name, m.name_en, m.position, m.position_np, m.position_en, m.phone, m.email, ct.name AS type_en, ct.name_np AS type_np
            FROM committee_members m
            LEFT JOIN committee_tenures t ON m.tenure_id = t.id
            LEFT JOIN committee_types ct ON t.committee_type_id = ct.id
            WHERE m.is_active = 1
            ORDER BY ct.display_order, m.display_order, m.id
            LIMIT 60') as $row) {
            $name = $english
                ? (string)(($row['name_en'] ?? '') !== '' ? $row['name_en'] : ($row['name'] ?? ''))
                : (string)($row['name'] ?? $row['name_en'] ?? '');
            $pos = $english
                ? (string)(($row['position_en'] ?? '') !== '' ? $row['position_en'] : (($row['position'] ?? '') !== '' ? $row['position'] : ($row['position_np'] ?? '')))
                : (string)(($row['position_np'] ?? '') !== '' ? $row['position_np'] : (($row['position'] ?? '') !== '' ? $row['position'] : ($row['position_en'] ?? '')));
            $ctype = $english
                ? (string)(($row['type_en'] ?? '') !== '' ? $row['type_en'] : ($row['type_np'] ?? ''))
                : (string)(($row['type_np'] ?? '') !== '' ? $row['type_np'] : ($row['type_en'] ?? ''));
            $body = trim(implode(' | ', array_filter([$pos, $ctype !== '' ? $ctype : '', (string)($row['phone'] ?? ''), (string)($row['email'] ?? '')])));
            $push('committee', $name !== '' ? $name : 'समिति सदस्य', $body, ai_chat_score_text($name . ' ' . $body . ' committee समिति board', $tokens) + 1);
        }

        /* CMS pages (about, contact, policies…) */
        foreach ($safeQuery($db, 'SELECT slug, title, title_np, content, content_np FROM pages WHERE is_active = 1 ORDER BY menu_order, id LIMIT 25') as $row) {
            $t = $english
                ? (string)(($row['title_np'] ?? '') !== '' ? $row['title_np'] : ($row['title'] ?? ''))
                : (string)(($row['title_np'] ?? '') !== '' ? $row['title_np'] : ($row['title'] ?? ''));
            $c = $english
                ? (string)(($row['content_np'] ?? '') !== '' ? $row['content_np'] : ($row['content'] ?? ''))
                : (string)(($row['content_np'] ?? '') !== '' ? $row['content_np'] : ($row['content'] ?? ''));
            $slug = (string)($row['slug'] ?? '');
            $push('page', ($t !== '' ? $t : $slug) . ($slug !== '' ? " ({$slug})" : ''), ai_chat_snip($c, 400), ai_chat_score_text($t . ' ' . $c . ' ' . $slug, $tokens) + ($slug === 'about' ? 2 : 0));
        }

        /* Recent news */
        foreach ($safeQuery($db, 'SELECT title, title_np, content, content_np FROM news WHERE is_active = 1 ORDER BY created_at DESC, id DESC LIMIT 15') as $row) {
            $t = (string)(($row['title_np'] ?? '') !== '' ? $row['title_np'] : ($row['title'] ?? ''));
            $c = (string)(($row['content_np'] ?? '') !== '' ? $row['content_np'] : ($row['content'] ?? ''));
            $push('news', $t !== '' ? $t : 'समाचार', ai_chat_snip($c, 320), ai_chat_score_text($t . ' ' . $c . ' समाचार news', $tokens));
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

        // Always keep baseline: site + leadership + top matches even if score 0
        $picked = [];
        $sources = [];
        $used = 0;
        $minKeep = 16;
        $forcedSources = ['site', 'leadership'];
        $forced = [];
        $rest = [];
        foreach ($cands as $c) {
            if (in_array($c['source'], $forcedSources, true)) {
                $forced[] = $c;
            } else {
                $rest[] = $c;
            }
        }
        usort($forced, static function ($a, $b) {
            $prio = ['leadership' => 0, 'site' => 1];
            $pa = $prio[$a['source']] ?? 9;
            $pb = $prio[$b['source']] ?? 9;
            return $pa <=> $pb;
        });
        usort($rest, static function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return 0;
            }
            return $a['score'] > $b['score'] ? -1 : 1;
        });
        $ordered = array_merge($forced, $rest);
        foreach ($ordered as $i => $c) {
            if ($c['score'] <= 0 && $i >= $minKeep && !in_array($c['source'], $forcedSources, true)) {
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
            if (count($picked) >= 32) {
                break;
            }
        }

        return [
            'context' => implode("\n\n---\n\n", $picked),
            'sources' => array_values(array_unique($sources)),
        ];
    }
}
