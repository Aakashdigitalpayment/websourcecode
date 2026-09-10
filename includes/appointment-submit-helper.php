<?php
/**
 * Shared appointment submit helper for public + member portal.
 * Pages keep CSRF / anti-bot / session / KYC prefill; this owns validate + INSERT.
 */

if (!function_exists('appointmentInsertRow')) {
    /**
     * Insert into appointments using only columns that exist (avoids hard fail on older DBs).
     *
     * @param array<string,mixed> $data
     */
    function appointmentInsertRow(PDO $db, array $data): void
    {
        static $cols = null;
        if ($cols === null) {
            $cols = [];
            try {
                foreach ($db->query('SHOW COLUMNS FROM appointments') as $row) {
                    $cols[strtolower((string)($row['Field'] ?? ''))] = true;
                }
            } catch (Throwable $e) {
                $cols = [];
            }
        }
        $use = [];
        $vals = [];
        foreach ($data as $col => $val) {
            $key = strtolower((string)$col);
            if (!isset($cols[$key])) {
                continue;
            }
            $use[] = '`' . str_replace('`', '', (string)$col) . '`';
            $vals[] = $val;
        }
        if ($use === []) {
            throw new RuntimeException('appointments table has no matching columns');
        }
        $placeholders = implode(',', array_fill(0, count($use), '?'));
        $sql = 'INSERT INTO appointments (' . implode(',', $use) . ') VALUES (' . $placeholders . ')';
        $db->prepare($sql)->execute($vals);
    }
}

if (!function_exists('appointmentNormalizeDate')) {
    /**
     * Public form uses Nepali (BS) datepicker. MySQL DATE needs valid Gregorian (AD).
     */
    function appointmentNormalizeDate(string $raw): string
    {
        $s = trim($raw);
        $s = strtr($s, [
            '०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4',
            '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9',
        ]);
        $s = str_replace(['/', '.'], '-', $s);
        if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            return $s;
        }
        $y = (int)$m[1];
        $mo = (int)$m[2];
        $d = (int)$m[3];
        $ymd = sprintf('%04d-%02d-%02d', $y, $mo, $d);

        $looksLikeBs = ($y >= 2070 && $y <= 2100);
        if ($looksLikeBs) {
            if (!function_exists('nepali_bs_to_ad_string')) {
                $conv = __DIR__ . '/nepali-bs-convert.php';
                if (is_file($conv)) {
                    require_once $conv;
                }
            }
            if (function_exists('nepali_bs_to_ad_string')) {
                $ad = nepali_bs_to_ad_string($ymd);
                if ($ad) {
                    return $ad;
                }
            }
            if (function_exists('bsToAd')) {
                $ad = bsToAd($ymd);
                if ($ad && $ad !== $ymd && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ad)) {
                    return $ad;
                }
            }
        }

        if (checkdate($mo, $d, $y)) {
            return $ymd;
        }

        if (function_exists('nepali_bs_to_ad_string')) {
            $ad = nepali_bs_to_ad_string($ymd);
            if ($ad) {
                return $ad;
            }
        }

        return $ymd;
    }
}

if (!function_exists('submitAppointmentUnified')) {
    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,tracking_id?:string,error?:string,error_en?:string,visit_kind?:string}
     */
    function submitAppointmentUnified(PDO $db, array $payload): array
    {
        $fromPortal = !empty($payload['from_member_portal']);
        $visitKind = (($payload['visit_kind'] ?? 'member') === 'cooperative') ? 'cooperative' : 'member';

        $name = trim((string)($payload['name'] ?? ''));
        $phone = preg_replace('/[^0-9]/', '', (string)($payload['phone'] ?? ''));
        $email = strtolower(trim((string)($payload['email'] ?? '')));
        $memberId = trim((string)($payload['member_id'] ?? ''));
        if ($memberId === '' && isset($payload['member_portal_id']) && (int)$payload['member_portal_id'] > 0) {
            $memberId = (string)(int)$payload['member_portal_id'];
        }
        $purposeRaw = trim((string)($payload['purpose'] ?? 'other'));
        $allowedPurpose = ['account_inquiry', 'loan_inquiry', 'kyc_update', 'loan_repayment', 'account_opening', 'other'];
        $purpose = in_array($purposeRaw, $allowedPurpose, true) ? $purposeRaw : 'other';
        $purposeDetail = trim((string)($payload['purpose_detail'] ?? ''));
        $preferredDate = trim((string)($payload['preferred_date'] ?? ''));
        $preferredTime = trim((string)($payload['preferred_time'] ?? ''));
        $branch = trim((string)($payload['branch'] ?? ''));

        $contactPerson = trim((string)($payload['contact_person'] ?? ''));
        $orgAddress = trim((string)($payload['organization_address'] ?? ''));
        $orgWebsite = trim((string)($payload['organization_website'] ?? ''));
        if ($orgWebsite !== '' && function_exists('safe_http_url')) {
            $orgWebsite = (string)safe_http_url($orgWebsite);
        }

        $requireEmail = array_key_exists('require_email', $payload)
            ? !empty($payload['require_email'])
            : !$fromPortal;

        if ($visitKind === 'cooperative') {
            if ($name === '') {
                return [
                    'ok' => false,
                    'error' => 'कृपया सहकारीको नाम भर्नुहोस्।',
                    'error_en' => 'Please enter the cooperative name.',
                ];
            }
            if ($contactPerson === '') {
                return [
                    'ok' => false,
                    'error' => 'कृपया सम्पर्क व्यक्तिको नाम भर्नुहोस्।',
                    'error_en' => 'Please enter a contact person.',
                ];
            }
            if ($phone === '') {
                return [
                    'ok' => false,
                    'error' => 'फोन नम्बर अनिवार्य छ।',
                    'error_en' => 'Phone number is required.',
                ];
            }
            if (!preg_match('/^[0-9]{10}$/', $phone)) {
                return [
                    'ok' => false,
                    'error' => 'कृपया १० अंकको मोबाइल नम्बर राख्नुहोस्।',
                    'error_en' => 'Please enter a valid 10-digit mobile number.',
                ];
            }
            if ($email !== '' && function_exists('isValidEmail') && !isValidEmail($email)) {
                return [
                    'ok' => false,
                    'error' => 'कृपया सही इमेल ठेगाना राख्नुहोस्।',
                    'error_en' => 'Please enter a valid email address.',
                ];
            }
            if ($orgAddress === '') {
                return [
                    'ok' => false,
                    'error' => 'कृपया सहकारीको ठेगाना भर्नुहोस्।',
                    'error_en' => 'Please enter the cooperative address.',
                ];
            }
            if ($purposeDetail === '') {
                return [
                    'ok' => false,
                    'error' => 'कृपया भ्रमण विवरण भर्नुहोस्।',
                    'error_en' => 'Please enter visit details.',
                ];
            }
            $purpose = 'other';
            $memberId = '';
        } else {
            if ($name === '') {
                return [
                    'ok' => false,
                    'error' => 'कृपया पूरा नाम भर्नुहोस्।',
                    'error_en' => 'Please enter your full name.',
                ];
            }
            if (!$fromPortal) {
                if ($phone === '') {
                    return [
                        'ok' => false,
                        'error' => 'फोन नम्बर अनिवार्य छ।',
                        'error_en' => 'Phone number is required.',
                    ];
                }
                if (!preg_match('/^[0-9]{10}$/', $phone)) {
                    return [
                        'ok' => false,
                        'error' => 'कृपया १० अंकको मोबाइल नम्बर राख्नुहोस्।',
                        'error_en' => 'Please enter a valid 10-digit mobile number.',
                    ];
                }
            } elseif ($phone !== '' && !preg_match('/^[0-9]{7,15}$/', $phone)) {
                return [
                    'ok' => false,
                    'error' => 'कृपया मान्य फोन नम्बर राख्नुहोस्।',
                    'error_en' => 'Please enter a valid phone number.',
                ];
            }
            if ($requireEmail && $email === '') {
                return [
                    'ok' => false,
                    'error' => 'इमेल ठेगाना अनिवार्य छ।',
                    'error_en' => 'Email address is required.',
                ];
            }
            if ($email !== '' && function_exists('isValidEmail') && !isValidEmail($email)) {
                return [
                    'ok' => false,
                    'error' => 'कृपया सही इमेल ठेगाना राख्नुहोस्।',
                    'error_en' => 'Please enter a valid email address.',
                ];
            }
            if ($fromPortal && $purposeRaw === '') {
                return [
                    'ok' => false,
                    'error' => 'उद्देश्य छान्नुहोस्।',
                    'error_en' => 'Please select a purpose.',
                ];
            }
        }

        if ($preferredDate === '') {
            return [
                'ok' => false,
                'error' => 'कृपया मिति छान्नुहोस्।',
                'error_en' => 'Please select a preferred date.',
            ];
        }
        if ($preferredTime === '') {
            return [
                'ok' => false,
                'error' => 'कृपया समय छान्नुहोस्।',
                'error_en' => 'Please select a preferred time.',
            ];
        }

        $preferredDate = appointmentNormalizeDate($preferredDate);
        $preferredTime = mb_substr($preferredTime, 0, 50, 'UTF-8');
        $name = mb_substr($name, 0, 200, 'UTF-8');
        $branch = mb_substr($branch, 0, 200, 'UTF-8');

        $trackingId = function_exists('coop_new_tracking_id')
            ? coop_new_tracking_id('APT')
            : ('APT' . date('YmdHis') . random_int(1000, 9999));

        $row = [
            'tracking_id' => $trackingId,
            'name' => $name,
            'phone' => mb_substr($phone, 0, 20, 'UTF-8'),
            'email' => mb_substr($email, 0, 120, 'UTF-8'),
            'member_id' => mb_substr($memberId, 0, 80, 'UTF-8'),
            'purpose' => $purpose,
            'purpose_detail' => $purposeDetail !== '' ? mb_substr($purposeDetail, 0, 1000, 'UTF-8') : null,
            'preferred_date' => $preferredDate,
            'preferred_time' => $preferredTime,
            'branch' => $branch !== '' ? $branch : null,
            'visit_kind' => $visitKind,
            'status' => 'pending',
        ];
        if ($visitKind === 'cooperative') {
            $row['organization_address'] = mb_substr($orgAddress, 0, 500, 'UTF-8');
            $row['organization_website'] = $orgWebsite !== '' ? mb_substr($orgWebsite, 0, 255, 'UTF-8') : null;
            $row['contact_person'] = mb_substr($contactPerson, 0, 120, 'UTF-8');
        }

        try {
            appointmentInsertRow($db, $row);

            if (function_exists('sendAdminNotification')) {
                try {
                    if ($visitKind === 'cooperative') {
                        sendAdminNotification('appointment', [
                            'नाम' => $name,
                            'प्रकार' => 'सहकारी भ्रमण',
                            'सहकारी' => $name,
                            'सम्पर्क व्यक्ति' => $contactPerson,
                            'फोन' => $phone,
                            'ठेगाना' => $orgAddress,
                            'वेबसाइट' => $orgWebsite !== '' ? $orgWebsite : 'N/A',
                            'मिति' => $preferredDate . ' ' . $preferredTime,
                        ], $trackingId);
                    } else {
                        sendAdminNotification('appointment', [
                            'नाम' => $name,
                            'फोन' => $phone !== '' ? $phone : 'N/A',
                            'इमेल' => $email !== '' ? $email : 'N/A',
                            'सदस्य नं.' => $memberId !== '' ? $memberId : 'N/A',
                            'उद्देश्य' => $purpose,
                            'मिति' => $preferredDate . ' ' . $preferredTime,
                            'सेवा कार्यालय' => $branch !== '' ? $branch : 'N/A',
                        ], $trackingId);
                    }
                } catch (Throwable $e) {
                    /* non-fatal */
                }
            }

            return ['ok' => true, 'tracking_id' => $trackingId, 'visit_kind' => $visitKind];
        } catch (Throwable $e) {
            error_log('[appointment-submit] ' . $e->getMessage());
            return [
                'ok' => false,
                'error' => 'भेटघाट बुक गर्न सकिएन। कृपया फेरि प्रयास गर्नुहोस्।',
                'error_en' => 'Failed to book appointment. Please try again.',
            ];
        }
    }
}
