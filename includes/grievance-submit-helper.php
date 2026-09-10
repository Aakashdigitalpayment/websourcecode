<?php
/**
 * Shared grievance submit helper for public + member portal.
 * Pages keep CSRF / anti-bot / session / KYC prefill; this owns validate + upload + INSERT.
 */

if (!function_exists('grievanceUploadAttachment')) {
    /**
     * @param array<string,mixed> $files
     */
    function grievanceUploadAttachment(array $files): string
    {
        if (!isset($files['attachment']) || ($files['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return '';
        }
        if (!function_exists('uploadFile')) {
            return '';
        }
        $result = uploadFile($files['attachment'], 'grievances');
        if (!empty($result['success']) && !empty($result['path'])) {
            return (string)$result['path'];
        }
        return '';
    }
}

if (!function_exists('submitGrievanceUnified')) {
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $files
     * @return array{ok:bool,tracking_id?:string,error?:string,error_en?:string,id?:int}
     */
    function submitGrievanceUnified(PDO $db, array $payload, array $files = []): array
    {
        $fromPortal = !empty($payload['from_member_portal']);
        $isAnonymous = !empty($payload['is_anonymous']) ? 1 : 0;

        $name = trim((string)($payload['name'] ?? ''));
        $memberId = trim((string)($payload['member_id'] ?? ''));
        if ($memberId === '' && isset($payload['member_portal_id']) && (int)$payload['member_portal_id'] > 0) {
            $memberId = (string)(int)$payload['member_portal_id'];
        }
        $phone = preg_replace('/[^0-9]/', '', (string)($payload['phone'] ?? ''));
        $email = strtolower(trim((string)($payload['email'] ?? '')));
        $category = trim((string)($payload['category'] ?? 'other'));
        if ($category === '') {
            $category = 'other';
        }
        $subject = trim((string)($payload['subject'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));

        $requireContact = array_key_exists('require_contact', $payload)
            ? !empty($payload['require_contact'])
            : !$fromPortal;

        if ($subject === '') {
            return [
                'ok' => false,
                'error' => 'कृपया विषय लेख्नुहोस्।',
                'error_en' => 'Please enter a subject.',
            ];
        }
        if ($description === '') {
            return [
                'ok' => false,
                'error' => 'कृपया गुनासोको विवरण लेख्नुहोस्।',
                'error_en' => 'Please describe your grievance.',
            ];
        }

        if ($requireContact) {
            if ($isAnonymous) {
                if ($phone === '') {
                    return [
                        'ok' => false,
                        'error' => 'ट्र्याकिङ/जवाफका लागि मोबाइल अनिवार्य छ।',
                        'error_en' => 'Mobile number is required (for tracking updates).',
                    ];
                }
                if (!preg_match('/^[0-9]{10}$/', $phone)) {
                    return [
                        'ok' => false,
                        'error' => 'कृपया १० अंकको मोबाइल नम्बर राख्नुहोस्।',
                        'error_en' => 'Please enter a valid 10-digit mobile number.',
                    ];
                }
                if ($email === '') {
                    return [
                        'ok' => false,
                        'error' => 'ट्र्याकिङ/जवाफका लागि इमेल अनिवार्य छ।',
                        'error_en' => 'Email is required (for tracking updates).',
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
                if ($name === '') {
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
                        'error_en' => 'Please enter a valid 10-digit mobile number.',
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
            }
        } elseif ($email !== '' && function_exists('isValidEmail') && !isValidEmail($email)) {
            return [
                'ok' => false,
                'error' => 'कृपया सही इमेल ठेगाना राख्नुहोस्।',
                'error_en' => 'Please enter a valid email address.',
            ];
        } elseif ($phone !== '' && !$fromPortal && !preg_match('/^[0-9]{10}$/', $phone)) {
            return [
                'ok' => false,
                'error' => 'कृपया १० अंकको मोबाइल नम्बर राख्नुहोस्।',
                'error_en' => 'Please enter a valid 10-digit mobile number.',
            ];
        }

        if ($isAnonymous) {
            if ($fromPortal) {
                $name = '';
                $memberId = '';
                $phone = '';
                $email = '';
            } else {
                $name = 'Anonymous';
                $memberId = '';
            }
        }

        $attachment = grievanceUploadAttachment($files);
        $trackingId = function_exists('coop_new_tracking_id')
            ? coop_new_tracking_id('GRV')
            : ('GRV' . date('YmdHis') . random_int(1000, 9999));

        try {
            $stmt = $db->prepare("INSERT INTO grievances (
                tracking_id, name, member_id, phone, email, category, subject, description, attachment, is_anonymous, status
            ) VALUES (?,?,?,?,?,?,?,?,?,?,'pending')");
            $stmt->execute([
                $trackingId,
                mb_substr($name, 0, 200, 'UTF-8'),
                mb_substr($memberId, 0, 80, 'UTF-8'),
                mb_substr($phone, 0, 20, 'UTF-8'),
                mb_substr($email, 0, 120, 'UTF-8'),
                mb_substr($category, 0, 40, 'UTF-8'),
                mb_substr($subject, 0, 300, 'UTF-8'),
                mb_substr($description, 0, 8000, 'UTF-8'),
                $attachment !== '' ? $attachment : null,
                $isAnonymous,
            ]);
            $id = (int)$db->lastInsertId();

            if (function_exists('sendAdminNotification')) {
                try {
                    sendAdminNotification('grievance', [
                        'नाम' => $isAnonymous ? ($fromPortal ? 'गुमनाम' : 'Anonymous') : $name,
                        'सदस्य नं.' => $memberId !== '' ? $memberId : 'N/A',
                        'फोन' => $phone !== '' ? $phone : 'N/A',
                        'इमेल' => $email !== '' ? $email : 'N/A',
                        'Category' => $category,
                        'विषय' => $subject,
                        'वर्ग' => $category,
                        'मिति' => date('Y-m-d H:i'),
                    ], $trackingId);
                } catch (Throwable $e) {
                    /* non-fatal */
                }
            }

            return ['ok' => true, 'tracking_id' => $trackingId, 'id' => $id];
        } catch (Throwable $e) {
            error_log('[grievance-submit] ' . $e->getMessage());
            return [
                'ok' => false,
                'error' => 'दर्ता गर्दा त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।',
                'error_en' => 'Could not submit grievance. Please try again later.',
            ];
        }
    }
}
