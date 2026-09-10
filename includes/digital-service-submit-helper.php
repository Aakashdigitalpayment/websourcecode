<?php
/**
 * Shared digital service request submit helper for public + member portal.
 * Pages keep CSRF / anti-bot / session / KYC prefill; this owns validate + upload + INSERT.
 */

$_dsrtFile = __DIR__ . '/digital-service-requests-tables.php';
if (is_file($_dsrtFile)) {
    require_once $_dsrtFile;
}
unset($_dsrtFile);
$_dstypes = __DIR__ . '/digital-service-types.php';
if (is_file($_dstypes)) {
    require_once $_dstypes;
}
unset($_dstypes);

if (!function_exists('digitalServiceUploadAttachment')) {
    /**
     * @param array<string,mixed> $files
     */
    function digitalServiceUploadAttachment(array $files): string
    {
        if (!isset($files['attachment']) || ($files['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return '';
        }
        if (!function_exists('uploadFile')) {
            return '';
        }
        $result = uploadFile($files['attachment'], 'digital_services');
        if (!empty($result['success']) && !empty($result['path'])) {
            return (string)$result['path'];
        }
        return '';
    }
}

if (!function_exists('submitDigitalServiceRequestUnified')) {
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $files
     * @param array<string,array<string,mixed>>|null $serviceTypes
     * @return array{ok:bool,tracking_id?:string,error?:string,error_en?:string,id?:int}
     */
    function submitDigitalServiceRequestUnified(PDO $db, array $payload, array $files = [], ?array $serviceTypes = null): array
    {
        if (function_exists('ensureDigitalServiceRequestsTables')) {
            try {
                ensureDigitalServiceRequestsTables($db);
            } catch (Throwable $e) {
                /* best-effort */
            }
        }

        $fromPortal = !empty($payload['from_member_portal']);
        if ($serviceTypes === null) {
            $serviceTypes = function_exists('digitalServiceTypesMap')
                ? digitalServiceTypesMap($db)
                : (function_exists('digitalServiceTypesDefaults') ? digitalServiceTypesDefaults() : []);
        }

        $requesterName = trim((string)($payload['requester_name'] ?? ''));
        $memberId = trim((string)($payload['member_id'] ?? ''));
        if ($memberId === '' && isset($payload['member_portal_id']) && (int)$payload['member_portal_id'] > 0) {
            $memberId = (string)(int)$payload['member_portal_id'];
        }
        $phone = preg_replace('/[^0-9]/', '', (string)($payload['phone'] ?? ''));
        $email = strtolower(trim((string)($payload['email'] ?? '')));
        $serviceType = trim((string)($payload['service_type'] ?? ''));
        $accountNumber = trim((string)($payload['account_number'] ?? ''));
        $statementFrom = trim((string)($payload['statement_from'] ?? ''));
        $statementTo = trim((string)($payload['statement_to'] ?? ''));
        if ($statementFrom !== '' && function_exists('coop_date_ui_normalize_ad')) {
            $statementFrom = coop_date_ui_normalize_ad($statementFrom);
        } elseif ($statementFrom !== '' && function_exists('appointmentNormalizeDate')) {
            $statementFrom = appointmentNormalizeDate($statementFrom);
        }
        if ($statementTo !== '' && function_exists('coop_date_ui_normalize_ad')) {
            $statementTo = coop_date_ui_normalize_ad($statementTo);
        } elseif ($statementTo !== '' && function_exists('appointmentNormalizeDate')) {
            $statementTo = appointmentNormalizeDate($statementTo);
        }
        if ((trim((string)($payload['statement_from'] ?? '')) !== '' && $statementFrom === '')
            || (trim((string)($payload['statement_to'] ?? '')) !== '' && $statementTo === '')) {
            return [
                'ok' => false,
                'error' => 'स्टेटमेन्ट मिति अमान्य छ। कृपया सही मिति छान्नुहोस्।',
                'error_en' => 'Statement date is invalid. Please pick a valid date.',
            ];
        }
        $billerName = trim((string)($payload['biller_name'] ?? ''));
        $billReference = trim((string)($payload['bill_reference'] ?? ''));
        $rechargeNumber = preg_replace('/[^0-9]/', '', (string)($payload['recharge_number'] ?? ''));
        $rechargeAmountRaw = $payload['recharge_amount'] ?? '';
        $rechargeAmount = ($rechargeAmountRaw !== '' && $rechargeAmountRaw !== null)
            ? (float)$rechargeAmountRaw
            : null;
        $serviceAmountRaw = $payload['service_amount'] ?? '';
        $serviceAmount = ($serviceAmountRaw !== '' && $serviceAmountRaw !== null)
            ? (float)$serviceAmountRaw
            : null;
        $requestDetails = trim((string)($payload['request_details'] ?? ''));
        $rawPref = trim((string)($payload['preferred_contact'] ?? 'phone'));
        $preferredContact = in_array($rawPref, ['phone', 'email', 'branch'], true) ? $rawPref : 'phone';

        $requireContact = array_key_exists('require_contact', $payload)
            ? !empty($payload['require_contact'])
            : !$fromPortal;

        if ($requireContact) {
            if ($requesterName === '') {
                return [
                    'ok' => false,
                    'error' => 'कृपया पूरा नाम भर्नुहोस्।',
                    'error_en' => 'Please enter your full name.',
                ];
            }
            if ($phone === '') {
                return [
                    'ok' => false,
                    'error' => 'मोबाइल नम्बर अनिवार्य छ।',
                    'error_en' => 'Mobile number is required.',
                ];
            }
            if (!preg_match('/^[0-9]{10}$/', $phone)) {
                return [
                    'ok' => false,
                    'error' => 'कृपया १० अंकको मोबाइल नम्बर राख्नुहोस्।',
                    'error_en' => 'Enter a valid 10-digit mobile number.',
                ];
            }
            if ($email === '') {
                return [
                    'ok' => false,
                    'error' => 'इमेल ठेगाना अनिवार्य छ।',
                    'error_en' => 'Email address is required.',
                ];
            }
            if (function_exists('isValidEmail') && !isValidEmail($email)) {
                return [
                    'ok' => false,
                    'error' => 'कृपया सही इमेल ठेगाना राख्नुहोस्।',
                    'error_en' => 'Please enter a valid email address.',
                ];
            }
        } else {
            if ($requesterName === '') {
                return [
                    'ok' => false,
                    'error' => 'कृपया पूरा नाम भर्नुहोस्।',
                    'error_en' => 'Please enter your full name.',
                ];
            }
            if ($email !== '' && function_exists('isValidEmail') && !isValidEmail($email)) {
                return [
                    'ok' => false,
                    'error' => 'कृपया सही इमेल ठेगाना राख्नुहोस्।',
                    'error_en' => 'Please enter a valid email address.',
                ];
            }
        }

        if ($serviceType === '' || !isset($serviceTypes[$serviceType])) {
            return [
                'ok' => false,
                'error' => 'कृपया सही सेवा छान्नुहोस्।',
                'error_en' => 'Please select a valid service.',
            ];
        }
        if (in_array($serviceType, ['share_refund', 'share_increase'], true)
            && ($serviceAmount === null || $serviceAmount <= 0)) {
            return [
                'ok' => false,
                'error' => 'छानिएको शेयर सेवाको लागि सही रकम राख्नुहोस्।',
                'error_en' => 'Please enter a valid amount for selected share service.',
            ];
        }

        $attachment = digitalServiceUploadAttachment($files);
        $needDoc = !empty($serviceTypes[$serviceType]['requires_document']);
        if ($needDoc && $attachment === '') {
            return [
                'ok' => false,
                'error' => 'यो सेवाको लागि मान्य संलग्न कागजात अनिवार्य छ।',
                'error_en' => 'Please attach a valid supporting document for this service.',
            ];
        }

        $serviceTypeNp = (string)($serviceTypes[$serviceType]['np'] ?? $serviceType);
        $trackingId = function_exists('coop_new_tracking_id')
            ? coop_new_tracking_id('DSR')
            : ('DSR' . date('YmdHis') . random_int(1000, 9999));

        try {
            $stmt = $db->prepare('INSERT INTO digital_service_requests (
                tracking_id, requester_name, member_id, phone, email,
                service_type, service_type_np, account_number,
                statement_from, statement_to, biller_name, bill_reference,
                recharge_number, recharge_amount, service_amount, request_details, attachment, preferred_contact
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $trackingId,
                mb_substr($requesterName, 0, 120, 'UTF-8'),
                mb_substr($memberId, 0, 50, 'UTF-8'),
                mb_substr($phone, 0, 20, 'UTF-8'),
                mb_substr($email, 0, 120, 'UTF-8'),
                mb_substr($serviceType, 0, 60, 'UTF-8'),
                mb_substr($serviceTypeNp, 0, 120, 'UTF-8'),
                $accountNumber !== '' ? mb_substr($accountNumber, 0, 50, 'UTF-8') : null,
                $statementFrom !== '' ? $statementFrom : null,
                $statementTo !== '' ? $statementTo : null,
                $billerName !== '' ? mb_substr($billerName, 0, 120, 'UTF-8') : null,
                $billReference !== '' ? mb_substr($billReference, 0, 120, 'UTF-8') : null,
                $rechargeNumber !== '' ? mb_substr($rechargeNumber, 0, 20, 'UTF-8') : null,
                $rechargeAmount,
                $serviceAmount,
                $requestDetails !== '' ? mb_substr($requestDetails, 0, 4000, 'UTF-8') : null,
                $attachment !== '' ? $attachment : null,
                $preferredContact,
            ]);
            $id = (int)$db->lastInsertId();

            if (function_exists('sendAdminNotification')) {
                try {
                    $notifyType = $fromPortal ? 'digital_service_request' : 'digital_service';
                    sendAdminNotification($notifyType, [
                        'नाम' => $requesterName,
                        'फोन' => $phone !== '' ? $phone : 'N/A',
                        'इमेल' => $email !== '' ? $email : 'N/A',
                        'सेवा प्रकार' => (string)($serviceTypes[$serviceType]['en'] ?? $serviceType),
                        'सेवा' => $serviceTypeNp,
                        'सम्पर्क माध्यम' => $preferredContact,
                        'सम्पर्क' => $preferredContact,
                        'मिति' => date('Y-m-d H:i'),
                    ], $trackingId);
                } catch (Throwable $e) {
                    /* non-fatal */
                }
            }

            return ['ok' => true, 'tracking_id' => $trackingId, 'id' => $id];
        } catch (Throwable $e) {
            error_log('[digital-service-submit] ' . $e->getMessage());
            return [
                'ok' => false,
                'error' => 'अनुरोध पेश गर्न सकिएन। पुनः प्रयास गर्नुहोस्।',
                'error_en' => 'Unable to submit. Please try again.',
            ];
        }
    }
}
