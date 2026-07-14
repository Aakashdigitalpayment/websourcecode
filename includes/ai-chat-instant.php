<?php
/**
 * Fast DB-grounded answers without LLM — leadership, contact, rates.
 * Returns null when the question should use full AI + context.
 */

if (!function_exists('ai_chat_base_url')) {
    function ai_chat_base_url(): string
    {
        return rtrim(defined('SITE_URL') ? (string)SITE_URL : '/', '/') . '/';
    }
}

if (!function_exists('ai_chat_public_links')) {
    /** Topic → public page URL for answers and prompts. */
    function ai_chat_public_links(): array
    {
        $b = ai_chat_base_url();
        return [
            'about' => $b . 'about.php',
            'team' => $b . 'team.php',
            'contact' => $b . 'contact.php',
            'rates' => $b . 'interest-rates.php',
            'services' => $b . 'services.php',
            'branches' => $b . 'service-centers.php',
            'faqs' => $b . 'faqs.php',
            'notices' => $b . 'notices.php',
            'news' => $b . 'news.php',
            'downloads' => $b . 'downloads.php',
            'reports' => $b . 'reports.php',
            'election' => $b . 'election-information.php',
            'profile' => $b . 'institutional-profile.php',
            'member' => $b . 'member/login.php',
            'tracker' => $b . 'application-tracker.php',
        ];
    }
}

if (!function_exists('ai_chat_links_prompt_block')) {
    function ai_chat_links_prompt_block(): string
    {
        $lines = [];
        foreach (ai_chat_public_links() as $topic => $url) {
            $lines[] = "{$topic}: {$url}";
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('ai_chat_leader_contact_row')) {
    /**
     * @return array{phone:string,email:string}|null
     */
    function ai_chat_leader_contact_row(?PDO $db, string $role): ?array
    {
        if (!$db instanceof PDO) {
            return null;
        }
        $col = $role === 'ceo' ? 'is_ceo' : 'is_chairman';
        try {
            $row = $db->query(
                "SELECT phone, email FROM team_members WHERE {$col} = 1 AND is_active = 1 LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            return [
                'phone' => trim((string)($row['phone'] ?? '')),
                'email' => trim((string)($row['email'] ?? '')),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ai_chat_format_instant_rates')) {
    function ai_chat_format_instant_rates(?PDO $db, bool $english): ?string
    {
        if (!$db instanceof PDO) {
            return null;
        }
        try {
            $rows = $db->query(
                'SELECT name, name_np, category, rate FROM interest_rates WHERE is_active = 1 ORDER BY display_order, id LIMIT 12'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }
        if ($rows === []) {
            return null;
        }
        $lines = [];
        foreach ($rows as $r) {
            $name = (string)(($r['name_np'] ?? '') !== '' ? $r['name_np'] : ($r['name'] ?? ''));
            $rate = (string)($r['rate'] ?? '');
            $cat = (string)($r['category'] ?? '');
            if ($name === '' || $rate === '') {
                continue;
            }
            $lines[] = $english
                ? ("• {$name}" . ($cat !== '' ? " ({$cat})" : '') . ": {$rate}%")
                : ("• {$name}" . ($cat !== '' ? " ({$cat})" : '') . ": {$rate}%");
        }
        if ($lines === []) {
            return null;
        }
        $links = ai_chat_public_links();
        $hdr = $english ? 'Published interest rates on our website:' : 'वेबसाइटमा प्रकाशित ब्याजदर:';
        $more = $english
            ? ('Full list: ' . $links['rates'])
            : ('पूरा सूची: ' . $links['rates']);
        return $hdr . "\n" . implode("\n", $lines) . "\n" . $more;
    }
}

if (!function_exists('ai_chat_format_instant_member_count')) {
    function ai_chat_format_instant_member_count(?PDO $db, bool $english): ?string
    {
        $links = ai_chat_public_links();
        $profileUrl = $links['profile'] ?? '';
        $fy = '';
        $count = 0;
        $branch = '';

        if ($db instanceof PDO) {
            try {
                $row = $db->query(
                    'SELECT fiscal_year, total_members, branch_count FROM institutional_profile WHERE is_active = 1 ORDER BY fiscal_year DESC, id DESC LIMIT 1'
                )->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $count = (int)($row['total_members'] ?? 0);
                    $fy = trim((string)($row['fiscal_year'] ?? ''));
                    $branch = trim((string)($row['branch_count'] ?? ''));
                }
            } catch (Throwable $e) {
                /* fallback below */
            }
        }

        if ($count <= 0) {
            $raw = trim((string)getSetting('total_members', ''));
            if ($raw !== '' && preg_match('/\d/', $raw)) {
                $digits = preg_replace('/[^\d]/', '', $raw) ?? '';
                $count = $digits !== '' ? (int)$digits : 0;
            }
        }

        if ($count <= 0) {
            return null;
        }

        $countStr = function_exists('toNepaliNumeral') && !$english
            ? (string)toNepaliNumeral((string)$count)
            : number_format($count);

        if ($english) {
            $parts = ['Published total members on our website: ' . $countStr];
            if ($fy !== '') {
                $parts[] = 'Fiscal year: ' . $fy;
            }
            if ($branch !== '' && (int)$branch > 0) {
                $parts[] = 'Branches: ' . $branch;
            }
            if ($profileUrl !== '') {
                $parts[] = 'Details: ' . $profileUrl;
            }
            return implode("\n", $parts);
        }

        $parts = ['वेबसाइटमा प्रकाशित कुल सदस्य संख्या: ' . $countStr];
        if ($fy !== '') {
            $parts[] = 'आ.व.: ' . $fy;
        }
        if ($branch !== '' && (int)$branch > 0) {
            $branchStr = function_exists('toNepaliNumeral')
                ? (string)toNepaliNumeral((string)$branch)
                : (string)$branch;
            $parts[] = 'शाखा: ' . $branchStr;
        }
        if ($profileUrl !== '') {
            $parts[] = 'थप: ' . $profileUrl;
        }
        return implode("\n", $parts);
    }
}

if (!function_exists('ai_chat_try_instant_answer')) {
    /**
     * @return array{answer:string,sources:list<string>,links:list<string>}|null
     */
    function ai_chat_try_instant_answer(string $message, ?PDO $db, bool $english): ?array
    {
        $q = mb_strtolower(trim(strip_tags($message)));
        $links = ai_chat_public_links();
        $sources = [];
        $outLinks = [];

        /* CEO / PCA */
        if (preg_match('/\b(ceo|pca|chief\s*executive|प्रमुख\s*कार्यकारी|कार्यकारी\s*अधिकृत)\b/ui', $q)
            && !preg_match('/\b(chairman|अध्यक्ष)\b/ui', $q)) {
            $lead = ai_chat_resolve_leadership($db, $english);
            if ($lead['ceo_name'] === '') {
                return null;
            }
            $contact = ai_chat_leader_contact_row($db, 'ceo');
            $parts = [];
            if ($english) {
                $parts[] = 'CEO (' . $lead['ceo_role'] . '): ' . $lead['ceo_name'];
            } else {
                $parts[] = 'प्रमुख कार्यकारी अधिकृत (' . $lead['ceo_role'] . '): ' . $lead['ceo_name'];
            }
            if ($contact) {
                if ($contact['phone'] !== '') {
                    $parts[] = ($english ? 'Phone: ' : 'फोन: ') . $contact['phone'];
                }
                if ($contact['email'] !== '') {
                    $parts[] = ($english ? 'Email: ' : 'इमेल: ') . $contact['email'];
                }
            }
            $parts[] = ($english ? 'More: ' : 'थप: ') . $links['team'];
            $sources[] = 'leadership: CEO';
            $outLinks[] = $links['team'];
            return ['answer' => implode("\n", $parts), 'sources' => $sources, 'links' => $outLinks];
        }

        /* Chairman */
        if (preg_match('/\b(chairman|chairperson|अध्यक्ष)\b/ui', $q)
            && !preg_match('/\b(ceo|प्रमुख\s*कार्यकारी)\b/ui', $q)) {
            $lead = ai_chat_resolve_leadership($db, $english);
            if ($lead['chairman_name'] === '') {
                return null;
            }
            $contact = ai_chat_leader_contact_row($db, 'chairman');
            $parts = [];
            $parts[] = ($english ? 'Chairman: ' : 'अध्यक्ष: ') . $lead['chairman_name'];
            if ($contact && $contact['phone'] !== '') {
                $parts[] = ($english ? 'Phone: ' : 'फोन: ') . $contact['phone'];
            }
            if ($contact && $contact['email'] !== '') {
                $parts[] = ($english ? 'Email: ' : 'इमेल: ') . $contact['email'];
            }
            $parts[] = ($english ? 'More: ' : 'थप: ') . $links['about'] . '#chairman';
            $sources[] = 'leadership: Chairman';
            $outLinks[] = $links['about'];
            return ['answer' => implode("\n", $parts), 'sources' => $sources, 'links' => $outLinks];
        }

        /* Contact / phone / address (general) */
        if (preg_match('/\b(contact|phone|email|address|सम्पर्क|फोन|इमेल|ठेगाना|whatsapp)\b/ui', $q)
            && !preg_match('/\b(ceo|chairman|अध्यक्ष|officer|अधिकृत)\b/ui', $q)) {
            $phone = trim((string)getSetting('phone', getSetting('contact_phone', '')));
            $email = trim((string)getSetting('email', getSetting('contact_email', '')));
            $addr = trim((string)getSetting($english ? 'address_en' : 'address', getSetting('address', '')));
            $wa = trim((string)getSetting('whatsapp', getSetting('whatsapp_number', '')));
            if ($phone === '' && $email === '' && $addr === '') {
                return null;
            }
            $parts = [$english ? 'Public contact (website):' : 'सार्वजनिक सम्पर्क (वेबसाइट):'];
            if ($phone !== '') {
                $parts[] = ($english ? 'Phone: ' : 'फोन: ') . $phone;
            }
            if ($email !== '') {
                $parts[] = ($english ? 'Email: ' : 'इमेल: ') . $email;
            }
            if ($wa !== '') {
                $parts[] = 'WhatsApp: ' . $wa;
            }
            if ($addr !== '') {
                $parts[] = ($english ? 'Address: ' : 'ठेगाना: ') . $addr;
            }
            $parts[] = ($english ? 'Contact page: ' : 'सम्पर्क पृष्ठ: ') . $links['contact'];
            $sources[] = 'site: contact';
            $outLinks[] = $links['contact'];
            return ['answer' => implode("\n", $parts), 'sources' => $sources, 'links' => $outLinks];
        }

        /* Interest rates */
        if (preg_match('/\b(ब्याजदर|ब्याज|interest\s*rate|loan\s*rate|saving\s*rate|ऋण\s*दर)\b/ui', $q)) {
            $body = ai_chat_format_instant_rates($db, $english);
            if ($body === null) {
                return null;
            }
            $sources[] = 'rate: instant list';
            $outLinks[] = $links['rates'];
            return ['answer' => $body, 'sources' => $sources, 'links' => $outLinks];
        }

        /* Total members (public aggregate from institutional profile) */
        if (preg_match('/\b(total\s*members?|member\s*total|how\s*many\s*members?|member\s*count|कुल\s*सदस्य|सदस्य\s*कति|कति\s*सदस्य|सदस्य\s*संख्या|member\s*kati|sadasy|sadshy)\b/ui', $q)
            && !preg_match('/\b(कसरी|how\s*to\s*become|बन्ने|join|आवेदन|apply|registration|दर्ता)\b/ui', $q)) {
            $body = ai_chat_format_instant_member_count($db, $english);
            if ($body === null) {
                return null;
            }
            $sources[] = 'profile: total members';
            $outLinks[] = $links['profile'] ?? ($links['about'] ?? '');
            $outLinks = array_values(array_filter($outLinks));
            return ['answer' => $body, 'sources' => $sources, 'links' => $outLinks];
        }

        return null;
    }
}

if (!function_exists('ai_chat_suggest_links_for_answer')) {
    /**
     * @return list<string>
     */
    function ai_chat_suggest_links_for_answer(string $answer, string $question): array
    {
        $links = ai_chat_public_links();
        $q = mb_strtolower($question . ' ' . $answer);
        $out = [];

        $map = [
            'rates' => '/ब्याज|interest|rate|ऋण|बचत/',
            'services' => '/सेवा|service|product|ऋण आवेदन|loan/',
            'branches' => '/शाखा|branch|office|केन्द्र|service center/',
            'team' => '/ceo|chairman|अध्यक्ष|team|टोली|officer|अधिकृत/',
            'contact' => '/सम्पर्क|contact|phone|फोन/',
            'faqs' => '/faq|प्रश्न|question|help/',
            'notices' => '/सूचना|notice/',
            'downloads' => '/download|फारम|form/',
            'reports' => '/report|प्रतिवेदन/',
            'about' => '/about|बारेमा|vision|mission/',
            'election' => '/निर्वाचन|election|उम्मेदवार|candidate|मतदान|vote/',
            'profile' => '/सदस्य|member|कुल|total|संस्थागत|institutional|profile/',
        ];

        foreach ($map as $key => $pattern) {
            if (isset($links[$key]) && preg_match($pattern, $q)) {
                $out[] = $links[$key];
            }
        }

        /* URLs already inside answer */
        if (preg_match_all('#https?://[^\s<>"\']+#u', $answer, $m)) {
            foreach ($m[0] as $u) {
                $out[] = $u;
            }
        }

        return array_values(array_unique(array_slice($out, 0, 3)));
    }
}
