<?php
/**
 * Shared account opening submit helper for public + member portal.
 * Pages keep CSRF / anti-bot / session / KYC prefill; this owns validate + upload + INSERT.
 */

if (!function_exists('accountUploadDocuments')) {
    /**
     * @param array<string,mixed> $files
     * @return array{photo:string,citizenship_front:string,citizenship_back:string,signature:string}
     */
    function accountUploadDocuments(array $files): array
    {
        $out = [
            'photo' => '',
            'citizenship_front' => '',
            'citizenship_back' => '',
            'signature' => '',
        ];
        if (!function_exists('uploadFile')) {
            return $out;
        }
        foreach (array_keys($out) as $key) {
            if (!isset($files[$key]) || ($files[$key]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $result = uploadFile($files[$key], 'accounts');
            if (!empty($result['success']) && !empty($result['path'])) {
                $out[$key] = (string)$result['path'];
            }
        }
        return $out;
    }
}

if (!function_exists('submitAccountApplicationUnified')) {
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $files
     * @return array{ok:bool,tracking_id?:string,error?:string,error_en?:string,id?:int}
     */
    function submitAccountApplicationUnified(PDO $db, array $payload, array $files = []): array
    {
        $fromPortal = !empty($payload['from_member_portal']);
        $skipUploads = array_key_exists('skip_uploads', $payload)
            ? !empty($payload['skip_uploads'])
            : $fromPortal;

        $accountType = trim((string)($payload['account_type'] ?? ''));
        $fullName = trim((string)($payload['full_name'] ?? ''));
        $fullNameEn = trim((string)($payload['full_name_en'] ?? ''));
        $dobBs = trim((string)($payload['dob_bs'] ?? ''));
        $dobAdRaw = $payload['dob_ad'] ?? null;
        $dobAd = ($dobAdRaw === null || $dobAdRaw === '') ? null : trim((string)$dobAdRaw);
        if ($dobBs !== '') {
            $y = (int)substr($dobBs, 0, 4);
            if ($y > 0 && $y < 2070) {
                /* English UI may post A.D. into dob_bs field */
                $dobAd = $dobAd ?: substr($dobBs, 0, 10);
                if (function_exists('adToBs')) {
                    $bs = adToBs((string)$dobAd);
                    if (is_string($bs) && $bs !== '') {
                        $dobBs = $bs;
                    }
                } elseif (function_exists('nepali_ad_to_bs_string')) {
                    $bs = nepali_ad_to_bs_string((string)$dobAd);
                    if (is_string($bs) && $bs !== '') {
                        $dobBs = $bs;
                    }
                }
            } elseif ($y >= 2070 && ($dobAd === null || $dobAd === '')) {
                if (function_exists('bsToAd')) {
                    $ad = bsToAd($dobBs);
                    if (is_string($ad) && $ad !== '') {
                        $dobAd = $ad;
                    }
                } elseif (function_exists('nepali_bs_to_ad_string')) {
                    $ad = nepali_bs_to_ad_string($dobBs);
                    if (is_string($ad) && $ad !== '') {
                        $dobAd = $ad;
                    }
                }
            }
        }
        $gender = trim((string)($payload['gender'] ?? ''));
        $maritalStatus = trim((string)($payload['marital_status'] ?? ''));
        $mobile = preg_replace('/[^0-9]/', '', (string)($payload['mobile'] ?? ''));
        $email = strtolower(trim((string)($payload['email'] ?? '')));
        $permanentAddress = trim((string)($payload['permanent_address'] ?? ''));
        $temporaryAddress = trim((string)($payload['temporary_address'] ?? ''));
        $citizenshipNo = trim((string)($payload['citizenship_no'] ?? ''));
        $citizenshipIssuedDate = trim((string)($payload['citizenship_issued_date'] ?? ''));
        if ($citizenshipIssuedDate !== '' && function_exists('coop_date_ui_normalize_ad')) {
            $citizenshipIssuedDate = coop_date_ui_normalize_ad($citizenshipIssuedDate);
        } elseif ($citizenshipIssuedDate !== '' && function_exists('appointmentNormalizeDate')) {
            $citizenshipIssuedDate = appointmentNormalizeDate($citizenshipIssuedDate);
        }
        $citizenshipIssuedPlace = trim((string)($payload['citizenship_issued_place'] ?? ''));
        $fatherName = trim((string)($payload['father_name'] ?? ''));
        $motherName = trim((string)($payload['mother_name'] ?? ''));
        $occupation = trim((string)($payload['occupation'] ?? ''));
        $monthlyIncome = preg_replace('/[^0-9.]/', '', (string)($payload['monthly_income'] ?? ''));
        $initialDeposit = preg_replace('/[^0-9.]/', '', (string)($payload['initial_deposit'] ?? ''));
        $nomineeName = trim((string)($payload['nominee_name'] ?? ''));
        $nomineeRelation = trim((string)($payload['nominee_relation'] ?? ''));
        $nomineePhone = preg_replace('/[^0-9]/', '', (string)($payload['nominee_phone'] ?? ''));
        $branch = trim((string)($payload['branch'] ?? ''));

        $requireEmail = array_key_exists('require_email', $payload)
            ? !empty($payload['require_email'])
            : !$fromPortal;
        $requireCitizenship = array_key_exists('require_citizenship', $payload)
            ? !empty($payload['require_citizenship'])
            : !$fromPortal;

        if ($accountType === '') {
            return [
                'ok' => false,
                'error' => 'कृपया खाता प्रकार छान्नुहोस्।',
                'error_en' => 'Please select an account type.',
            ];
        }
        if ($fullName === '') {
            return [
                'ok' => false,
                'error' => 'कृपया पूरा नाम भर्नुहोस्।',
                'error_en' => 'Please enter your full name.',
            ];
        }
        if ($mobile === '') {
            return [
                'ok' => false,
                'error' => 'मोबाइल नम्बर अनिवार्य छ।',
                'error_en' => 'Mobile number is required.',
            ];
        }
        if ($fromPortal) {
            if (!preg_match('/^[0-9]{7,15}$/', $mobile)) {
                return [
                    'ok' => false,
                    'error' => 'कृपया मान्य मोबाइल नम्बर राख्नुहोस्।',
                    'error_en' => 'Please enter a valid mobile number.',
                ];
            }
        } elseif (!preg_match('/^[0-9]{10}$/', $mobile)) {
            return [
                'ok' => false,
                'error' => 'कृपया १० अंकको मोबाइल नम्बर राख्नुहोस्।',
                'error_en' => 'Please enter a valid 10-digit mobile number.',
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
        if ($requireCitizenship && $citizenshipNo === '') {
            return [
                'ok' => false,
                'error' => 'नागरिकता नम्बर अनिवार्य छ।',
                'error_en' => 'Citizenship number is required.',
            ];
        }

        $docs = $skipUploads
            ? ['photo' => '', 'citizenship_front' => '', 'citizenship_back' => '', 'signature' => '']
            : accountUploadDocuments($files);

        $trackingId = function_exists('coop_new_tracking_id')
            ? coop_new_tracking_id('ACC')
            : ('ACC' . date('YmdHis') . random_int(1000, 9999));

        try {
            $stmt = $db->prepare('INSERT INTO account_applications (
                tracking_id, account_type, full_name, full_name_en, dob_bs, dob_ad, gender, marital_status,
                mobile, email, permanent_address, temporary_address, citizenship_no, citizenship_issued_date,
                citizenship_issued_place, father_name, mother_name, occupation, monthly_income, initial_deposit,
                nominee_name, nominee_relation, nominee_phone, branch, photo, citizenship_front, citizenship_back, signature
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $trackingId,
                mb_substr($accountType, 0, 40, 'UTF-8'),
                mb_substr($fullName, 0, 200, 'UTF-8'),
                mb_substr($fullNameEn, 0, 200, 'UTF-8'),
                $dobBs !== '' ? mb_substr($dobBs, 0, 40, 'UTF-8') : null,
                $dobAd,
                $gender !== '' ? mb_substr($gender, 0, 30, 'UTF-8') : null,
                $maritalStatus !== '' ? mb_substr($maritalStatus, 0, 30, 'UTF-8') : null,
                mb_substr($mobile, 0, 20, 'UTF-8'),
                mb_substr($email, 0, 120, 'UTF-8'),
                $permanentAddress !== '' ? $permanentAddress : null,
                $temporaryAddress !== '' ? $temporaryAddress : null,
                $citizenshipNo !== '' ? mb_substr($citizenshipNo, 0, 80, 'UTF-8') : null,
                $citizenshipIssuedDate !== '' ? mb_substr($citizenshipIssuedDate, 0, 40, 'UTF-8') : null,
                $citizenshipIssuedPlace !== '' ? mb_substr($citizenshipIssuedPlace, 0, 200, 'UTF-8') : null,
                $fatherName !== '' ? mb_substr($fatherName, 0, 200, 'UTF-8') : null,
                $motherName !== '' ? mb_substr($motherName, 0, 200, 'UTF-8') : null,
                $occupation !== '' ? mb_substr($occupation, 0, 120, 'UTF-8') : null,
                $monthlyIncome !== '' ? $monthlyIncome : null,
                $initialDeposit !== '' ? $initialDeposit : null,
                $nomineeName !== '' ? mb_substr($nomineeName, 0, 200, 'UTF-8') : null,
                $nomineeRelation !== '' ? mb_substr($nomineeRelation, 0, 120, 'UTF-8') : null,
                $nomineePhone !== '' ? mb_substr($nomineePhone, 0, 20, 'UTF-8') : null,
                $branch !== '' ? mb_substr($branch, 0, 200, 'UTF-8') : null,
                $docs['photo'] !== '' ? $docs['photo'] : null,
                $docs['citizenship_front'] !== '' ? $docs['citizenship_front'] : null,
                $docs['citizenship_back'] !== '' ? $docs['citizenship_back'] : null,
                $docs['signature'] !== '' ? $docs['signature'] : null,
            ]);
            $id = (int)$db->lastInsertId();

            if (function_exists('sendAdminNotification')) {
                try {
                    $notifyType = $fromPortal ? 'account_opening' : 'account_application';
                    sendAdminNotification($notifyType, [
                        'नाम' => $fullName,
                        'खाता प्रकार' => $accountType,
                        'फोन' => $mobile,
                        'इमेल' => $email !== '' ? $email : 'N/A',
                        'प्रारम्भिक जम्मा' => 'Rs. ' . number_format((float)($initialDeposit !== '' ? $initialDeposit : 0)),
                        'जम्मा' => 'रु. ' . number_format((float)($initialDeposit !== '' ? $initialDeposit : 0)),
                        'Tracking ID' => $trackingId,
                        'सेवा कार्यालय' => $branch !== '' ? $branch : 'N/A',
                        'मिति' => date('Y-m-d H:i'),
                    ], $trackingId);
                } catch (Throwable $e) {
                    /* non-fatal */
                }
            }

            return ['ok' => true, 'tracking_id' => $trackingId, 'id' => $id];
        } catch (Throwable $e) {
            error_log('[account-submit] ' . $e->getMessage());
            return [
                'ok' => false,
                'error' => 'आवेदन पेश गर्न सकिएन। कृपया पछि प्रयास गर्नुहोस्।',
                'error_en' => 'Could not submit application. Please try again later.',
            ];
        }
    }
}
