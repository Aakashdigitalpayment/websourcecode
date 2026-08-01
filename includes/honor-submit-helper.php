<?php
/**
 * Shared honor (सम्मान दरखास्त) submit helper for public + member portal.
 */
require_once __DIR__ . '/honor-tables.php';

if (!function_exists('honorUploadAttachment')) {
    function honorUploadAttachment(array $files): string
    {
        if (!isset($files['attachment']) || ($files['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return '';
        }
        if (!function_exists('uploadFile')) {
            return '';
        }
        $result = uploadFile($files['attachment'], 'honor_applications');
        if (!empty($result['success']) && !empty($result['path'])) {
            return (string)$result['path'];
        }
        return '';
    }
}

if (!function_exists('submitHonorApplicationUnified')) {
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $files
     * @return array{ok:bool,tracking_id?:string,error?:string,error_en?:string,id?:int}
     */
    function submitHonorApplicationUnified(PDO $db, array $payload, array $files = []): array
    {
        ensureHonorTables($db);

        $programId = (int)($payload['program_id'] ?? 0);
        $categoryId = (int)($payload['category_id'] ?? 0);
        $program = honorFetchProgramById($db, $programId);
        if (!$program || !honorIsProgramOpenRow($program)) {
            return [
                'ok' => false,
                'error' => 'हाल कुनै खुला सम्मान दरखास्त कार्यक्रम छैन।',
                'error_en' => 'No honor application program is currently open.',
            ];
        }
        $category = honorFetchCategoryById($db, $categoryId);
        if (!$category || empty($category['is_active']) || !honorProgramAllowsCategory($db, $programId, $categoryId)) {
            return [
                'ok' => false,
                'error' => 'कृपया मान्य सम्मान कोटि छान्नुहोस्।',
                'error_en' => 'Please select a valid honor category.',
            ];
        }

        $applicantName = trim((string)($payload['applicant_name'] ?? ''));
        $phone = preg_replace('/[^0-9]/', '', (string)($payload['phone'] ?? ''));
        $email = strtolower(trim((string)($payload['email'] ?? '')));
        $address = trim((string)($payload['address'] ?? ''));
        $isMember = !empty($payload['is_member']) ? 1 : 0;
        $memberId = trim((string)($payload['member_id'] ?? ''));
        $memberPortalId = isset($payload['member_portal_id']) && (int)$payload['member_portal_id'] > 0
            ? (int)$payload['member_portal_id']
            : null;
        $nomineeName = trim((string)($payload['nominee_name'] ?? ''));
        $nomineeRelation = trim((string)($payload['nominee_relation'] ?? ''));
        $examYear = trim((string)($payload['exam_year'] ?? ''));
        $institution = trim((string)($payload['institution'] ?? ''));
        $businessNote = trim((string)($payload['business_note'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));

        if ($applicantName === '' || $phone === '') {
            return [
                'ok' => false,
                'error' => 'कृपया नाम र फोन नम्बर भर्नुहोस्।',
                'error_en' => 'Please fill name and phone number.',
            ];
        }
        if (strlen($phone) < 7) {
            return [
                'ok' => false,
                'error' => 'कृपया मान्य फोन नम्बर दिनुहोस्।',
                'error_en' => 'Please provide a valid phone number.',
            ];
        }
        if ($isMember && $memberId === '') {
            return [
                'ok' => false,
                'error' => 'सदस्य भएमा सदस्य नम्बर अनिवार्य छ।',
                'error_en' => 'Member number is required when applying as a member.',
            ];
        }
        if (!empty($category['requires_nominee'])) {
            if ($nomineeName === '') {
                return [
                    'ok' => false,
                    'error' => 'यस कोटिका लागि नामांकित व्यक्तिको नाम आवश्यक छ।',
                    'error_en' => 'Nominee name is required for this category.',
                ];
            }
            if ($nomineeRelation === '') {
                $nomineeRelation = 'अन्य';
            }
        } else {
            // Self-oriented categories (senior member / business): default nominee to applicant
            if ($nomineeName === '') {
                $nomineeName = $applicantName;
            }
            if ($nomineeRelation === '') {
                $nomineeRelation = 'आफैं';
            }
        }
        if (!empty($category['requires_document'])) {
            $hasFile = isset($files['attachment']) && ($files['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
            if (!$hasFile) {
                return [
                    'ok' => false,
                    'error' => 'यस कोटिका लागि प्रमाण कागजात (attachment) आवश्यक छ।',
                    'error_en' => 'A supporting document attachment is required for this category.',
                ];
            }
        }

        $attachment = honorUploadAttachment($files);
        if (!empty($category['requires_document']) && $attachment === '') {
            return [
                'ok' => false,
                'error' => 'फाइल अपलोड असफल भयो। कृपया फेरि प्रयास गर्नुहोस्।',
                'error_en' => 'File upload failed. Please try again.',
            ];
        }

        $trackingId = 'HNR-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));

        try {
            $stmt = $db->prepare('INSERT INTO honor_applications (
                tracking_id, program_id, category_id,
                applicant_name, phone, email, address, is_member, member_id, member_portal_id,
                nominee_name, nominee_relation, exam_year, institution, business_note,
                description, attachment, status
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'pending\')');
            $stmt->execute([
                $trackingId,
                $programId,
                $categoryId,
                mb_substr($applicantName, 0, 160, 'UTF-8'),
                mb_substr($phone, 0, 20, 'UTF-8'),
                mb_substr($email, 0, 120, 'UTF-8'),
                mb_substr($address, 0, 255, 'UTF-8'),
                $isMember,
                mb_substr($memberId, 0, 50, 'UTF-8'),
                $memberPortalId,
                mb_substr($nomineeName, 0, 160, 'UTF-8'),
                mb_substr($nomineeRelation, 0, 80, 'UTF-8'),
                mb_substr($examYear, 0, 40, 'UTF-8'),
                mb_substr($institution, 0, 200, 'UTF-8'),
                mb_substr($businessNote, 0, 255, 'UTF-8'),
                mb_substr($description, 0, 4000, 'UTF-8'),
                $attachment,
            ]);
            $id = (int)$db->lastInsertId();

            if (function_exists('sendAdminNotification')) {
                try {
                    sendAdminNotification('honor_application', [
                        'name' => $applicantName,
                        'phone' => $phone,
                        'category' => honorCategoryLabel($category, false),
                        'program' => honorProgramLabel($program, false),
                    ], $trackingId);
                } catch (Throwable $e) {
                    /* non-fatal */
                }
            }

            return ['ok' => true, 'tracking_id' => $trackingId, 'id' => $id];
        } catch (Throwable $e) {
            error_log('[honor-submit] ' . $e->getMessage());
            return [
                'ok' => false,
                'error' => 'दर्ता गर्दा त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।',
                'error_en' => 'Could not submit application. Please try again later.',
            ];
        }
    }
}
