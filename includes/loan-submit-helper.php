<?php
/**
 * Shared loan application submit helper for public + member portal.
 * Pages keep CSRF / anti-bot / session / KYC prefill; this owns validate + upload + INSERT.
 */

if (!function_exists('loanUploadDocuments')) {
    /**
     * Upload documents[] the same way public loan-apply does.
     *
     * @param array<string,mixed> $files
     * @return array{paths:string,failed:bool}
     */
    function loanUploadDocuments(array $files): array
    {
        if (!isset($files['documents']) || empty($files['documents']['name'][0])) {
            return ['paths' => '', 'failed' => false];
        }
        if (!function_exists('uploadFile')) {
            return ['paths' => '', 'failed' => true];
        }
        $uploadedFiles = [];
        $failed = false;
        $names = $files['documents']['name'];
        if (!is_array($names)) {
            return ['paths' => '', 'failed' => false];
        }
        foreach ($names as $key => $name) {
            $err = (int)($files['documents']['error'][$key] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE || trim((string)$name) === '') {
                continue;
            }
            if ($err !== UPLOAD_ERR_OK) {
                $failed = true;
                continue;
            }
            $singleFile = [
                'name'     => $files['documents']['name'][$key],
                'type'     => $files['documents']['type'][$key],
                'tmp_name' => $files['documents']['tmp_name'][$key],
                'error'    => $files['documents']['error'][$key],
                'size'     => $files['documents']['size'][$key],
            ];
            $result = uploadFile($singleFile, 'loan');
            if (!empty($result['success']) && !empty($result['path'])) {
                $uploadedFiles[] = $result['path'];
            } else {
                $failed = true;
            }
        }
        return ['paths' => implode(',', $uploadedFiles), 'failed' => $failed];
    }
}

if (!function_exists('submitLoanApplicationUnified')) {
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $files
     * @return array{ok:bool,tracking_id?:string,error?:string,error_en?:string,id?:int}
     */
    function submitLoanApplicationUnified(PDO $db, array $payload, array $files = []): array
    {
        $fromPortal = !empty($payload['from_member_portal']);

        $fullName = trim((string)($payload['full_name'] ?? ''));
        $memberId = trim((string)($payload['member_id'] ?? ''));
        /* Prefer explicit member_id; else portal id string for member-history queries */
        if ($memberId === '' && isset($payload['member_portal_id']) && (int)$payload['member_portal_id'] > 0) {
            $memberId = (string)(int)$payload['member_portal_id'];
        }
        $mobile = preg_replace('/[^0-9]/', '', (string)($payload['mobile'] ?? ''));
        $email = strtolower(trim((string)($payload['email'] ?? '')));
        $address = trim((string)($payload['address'] ?? ''));
        $citizenshipNo = trim((string)($payload['citizenship_no'] ?? ''));

        $loanType = trim((string)($payload['loan_type'] ?? ''));
        $loanAmountRaw = preg_replace('/[^0-9.]/', '', (string)($payload['loan_amount'] ?? ''));
        $loanAmount = (float)$loanAmountRaw;
        $loanPurpose = trim((string)($payload['loan_purpose'] ?? ''));
        $loanTenureRaw = trim((string)($payload['loan_tenure'] ?? ''));
        $loanTenure = ($loanTenureRaw !== '' && is_numeric($loanTenureRaw)) ? (int)$loanTenureRaw : null;
        $repaymentMethod = trim((string)($payload['repayment_method'] ?? ''));

        $occupation = trim((string)($payload['occupation'] ?? ''));
        $organizationName = trim((string)($payload['organization_name'] ?? ''));
        $monthlyIncomeRaw = preg_replace('/[^0-9.]/', '', (string)($payload['monthly_income'] ?? ''));
        $monthlyIncome = $monthlyIncomeRaw !== '' ? $monthlyIncomeRaw : null;
        $otherIncome = trim((string)($payload['other_income'] ?? ''));

        $collateralType = trim((string)($payload['collateral_type'] ?? ''));
        $collateralDescription = trim((string)($payload['collateral_description'] ?? $payload['collateral_desc'] ?? ''));
        $collateralValueRaw = preg_replace('/[^0-9.]/', '', (string)($payload['collateral_value'] ?? ''));
        $collateralValue = $collateralValueRaw !== '' ? $collateralValueRaw : null;

        $guarantorName = trim((string)($payload['guarantor_name'] ?? ''));
        $guarantorRelation = trim((string)($payload['guarantor_relation'] ?? ''));
        $guarantorPhone = preg_replace('/[^0-9]/', '', (string)($payload['guarantor_phone'] ?? ''));
        $guarantorAddress = trim((string)($payload['guarantor_address'] ?? ''));

        $branch = trim((string)($payload['branch'] ?? ''));

        $requireEmail = array_key_exists('require_email', $payload)
            ? !empty($payload['require_email'])
            : !$fromPortal;

        if ($fullName === '') {
            return [
                'ok' => false,
                'error' => 'कृपया पूरा नाम भर्नुहोस्।',
                'error_en' => 'Please enter your full name.',
            ];
        }
        if (!$fromPortal) {
            if ($mobile === '') {
                return [
                    'ok' => false,
                    'error' => 'मोबाइल नम्बर अनिवार्य छ।',
                    'error_en' => 'Mobile number is required.',
                ];
            }
            if (!preg_match('/^[0-9]{10}$/', $mobile)) {
                return [
                    'ok' => false,
                    'error' => 'कृपया १० अंकको मोबाइल नम्बर राख्नुहोस्।',
                    'error_en' => 'Please enter a valid 10-digit mobile number.',
                ];
            }
        } elseif ($mobile !== '' && !preg_match('/^[0-9]{7,15}$/', $mobile)) {
            return [
                'ok' => false,
                'error' => 'कृपया मान्य मोबाइल नम्बर राख्नुहोस्।',
                'error_en' => 'Please enter a valid mobile number.',
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
        if ($loanType === '') {
            return [
                'ok' => false,
                'error' => 'कृपया ऋण प्रकार छान्नुहोस्।',
                'error_en' => 'Please select a loan type.',
            ];
        }
        if ($fromPortal) {
            if ($loanAmount < 1000) {
                return [
                    'ok' => false,
                    'error' => 'ऋण रकम कम्तिमा रु. १,००० हुनुपर्छ।',
                    'error_en' => 'Loan amount must be at least Rs. 1,000.',
                ];
            }
        } elseif ($loanAmount <= 0) {
            return [
                'ok' => false,
                'error' => 'कृपया ऋण रकम भर्नुहोस्।',
                'error_en' => 'Please enter the loan amount.',
            ];
        }

        $upload = loanUploadDocuments($files);
        if (!empty($upload['failed'])) {
            return [
                'ok' => false,
                'error' => 'कागजात अपलोड असफल भयो। फाइल प्रकार/साइज जाँचेर पुनः प्रयास गर्नुहोस्।',
                'error_en' => 'Document upload failed. Check file type/size and try again.',
            ];
        }
        $documents = (string)($upload['paths'] ?? '');
        $trackingId = function_exists('coop_new_tracking_id')
            ? coop_new_tracking_id('LNP')
            : ('LNP' . date('YmdHis') . random_int(1000, 9999));

        try {
            $stmt = $db->prepare('INSERT INTO loan_applications (
                tracking_id,
                full_name, member_id, mobile, email, address, citizenship_no,
                loan_type, loan_amount, loan_purpose, loan_tenure, repayment_method,
                occupation, organization_name, monthly_income, other_income,
                collateral_type, collateral_description, collateral_value,
                guarantor_name, guarantor_relation, guarantor_phone, guarantor_address,
                branch, documents
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

            $stmt->execute([
                $trackingId,
                mb_substr($fullName, 0, 100, 'UTF-8'),
                mb_substr($memberId, 0, 50, 'UTF-8'),
                mb_substr($mobile, 0, 20, 'UTF-8'),
                mb_substr($email, 0, 100, 'UTF-8'),
                $address,
                mb_substr($citizenshipNo, 0, 50, 'UTF-8'),
                mb_substr($loanType, 0, 100, 'UTF-8'),
                $loanAmount,
                $loanPurpose !== '' ? $loanPurpose : null,
                $loanTenure,
                $repaymentMethod !== '' ? mb_substr($repaymentMethod, 0, 50, 'UTF-8') : null,
                $occupation !== '' ? mb_substr($occupation, 0, 100, 'UTF-8') : null,
                $organizationName !== '' ? mb_substr($organizationName, 0, 200, 'UTF-8') : null,
                $monthlyIncome,
                $otherIncome !== '' ? $otherIncome : null,
                $collateralType !== '' ? mb_substr($collateralType, 0, 100, 'UTF-8') : null,
                $collateralDescription !== '' ? $collateralDescription : null,
                $collateralValue,
                $guarantorName !== '' ? mb_substr($guarantorName, 0, 100, 'UTF-8') : null,
                $guarantorRelation !== '' ? mb_substr($guarantorRelation, 0, 100, 'UTF-8') : null,
                $guarantorPhone !== '' ? mb_substr($guarantorPhone, 0, 20, 'UTF-8') : null,
                $guarantorAddress !== '' ? $guarantorAddress : null,
                $branch !== '' ? mb_substr($branch, 0, 100, 'UTF-8') : null,
                $documents !== '' ? $documents : null,
            ]);
            $id = (int)$db->lastInsertId();

            if (function_exists('sendAdminNotification')) {
                try {
                    sendAdminNotification('loan_application', [
                        'नाम' => $fullName,
                        'फोन' => $mobile,
                        'ऋण रकम' => 'Rs. ' . number_format($loanAmount),
                        'ऋण प्रकार' => $loanType,
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
            error_log('[loan-submit] ' . $e->getMessage());
            return [
                'ok' => false,
                'error' => 'दर्ता गर्दा त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।',
                'error_en' => 'Could not submit application. Please try again later.',
            ];
        }
    }
}
