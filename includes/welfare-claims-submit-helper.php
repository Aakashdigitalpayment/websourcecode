<?php
/**
 * Shared welfare claim submit helper.
 * Keeps DB insert + upload logic consistent across public/member flows.
 */

if (!function_exists('welfareClaimTypeLabelNp')) {
    function welfareClaimTypeLabelNp($claimType)
    {
        $map = [
            'maternity' => 'सुत्केरी सुविधा',
            'death' => 'मृत्यु सुविधा',
            'insurance' => 'बीमा दाबी',
            'medical' => 'उपचार खर्च',
            'accident' => 'दुर्घटना सुविधा',
            'other' => 'अन्य सुविधा',
        ];
        return $map[$claimType] ?? 'अन्य';
    }
}

if (!function_exists('welfareUploadSupportingDocuments')) {
    function welfareUploadSupportingDocuments($files)
    {
        $uploadedFiles = [];
        if (!isset($files['documents']) || !isset($files['documents']['name']) || !is_array($files['documents']['name'])) {
            return '';
        }
        foreach ($files['documents']['tmp_name'] as $key => $tmp) {
            if (($files['documents']['error'][$key] ?? 1) !== 0) {
                continue;
            }
            $singleFile = [
                'name' => $files['documents']['name'][$key],
                'type' => $files['documents']['type'][$key],
                'tmp_name' => $tmp,
                'error' => $files['documents']['error'][$key],
                'size' => $files['documents']['size'][$key],
            ];
            $uploadResult = uploadFile($singleFile, 'welfare_claims');
            if (!empty($uploadResult['success']) && !empty($uploadResult['path'])) {
                $uploadedFiles[] = $uploadResult['path'];
            }
        }
        return implode(',', $uploadedFiles);
    }
}

if (!function_exists('welfareUploadDeathCertificate')) {
    function welfareUploadDeathCertificate($files)
    {
        if (!isset($files['death_certificate']) || ($files['death_certificate']['error'] ?? 1) !== 0) {
            return '';
        }
        $uploadResult = uploadFile($files['death_certificate'], 'welfare_claims');
        if (!empty($uploadResult['success']) && !empty($uploadResult['path'])) {
            return $uploadResult['path'];
        }
        return '';
    }
}

if (!function_exists('submitWelfareClaimUnified')) {
    function submitWelfareClaimUnified($db, $payload, $files)
    {
        $trackingId = 'WLF-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
        $claimType = $payload['claim_type'] ?? 'other';
        $claimTypeNp = welfareClaimTypeLabelNp($claimType);
        $documents = welfareUploadSupportingDocuments($files);
        $deathCertificate = welfareUploadDeathCertificate($files);

        $stmt = $db->prepare("INSERT INTO member_welfare_claims (
            tracking_id, member_name, member_id, member_portal_id, phone, email, address,
            claim_type, claim_type_np, beneficiary_name, beneficiary_relation,
            claim_amount, description, supporting_documents,
            deceased_name, deceased_relation, death_date, death_certificate,
            delivery_date, hospital_name, disease_illness, treatment_date, hospital_clinic,
            policy_number, insurer_name, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");

        $stmt->execute([
            $trackingId,
            $payload['member_name'] ?? '',
            $payload['member_id'] ?? '',
            $payload['member_portal_id'] ?? null,
            $payload['phone'] ?? '',
            $payload['email'] ?? '',
            $payload['address'] ?? '',
            $claimType,
            $claimTypeNp,
            $payload['beneficiary_name'] ?? '',
            $payload['beneficiary_relation'] ?? '',
            $payload['claim_amount'] ?? 0,
            $payload['description'] ?? '',
            $documents ?: null,
            $payload['deceased_name'] ?? '',
            $payload['deceased_relation'] ?? '',
            $payload['death_date'] ?? null,
            $deathCertificate ?: null,
            $payload['delivery_date'] ?? null,
            $payload['hospital_name'] ?? '',
            $payload['disease_illness'] ?? '',
            $payload['treatment_date'] ?? null,
            $payload['hospital_clinic'] ?? '',
            $payload['policy_number'] ?? null,
            $payload['insurer_name'] ?? null,
        ]);

        return ['tracking_id' => $trackingId, 'claim_type_np' => $claimTypeNp];
    }
}

