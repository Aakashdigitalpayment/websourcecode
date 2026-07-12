<?php
require_once 'includes/config.php';
require_once 'includes/ensure-tables.php';
require_once 'includes/member-auth.php';
require_once 'includes/kyc-capture-helpers.php';   // v10.4 — base64 capture helper
require_once 'includes/nepal-address.php';         // v10.4 — Province/District/Municipality data

// Nepal district options for issue-district dropdowns
$nepalDistricts = [];
foreach (getNepalAddressData() as $provinceRows) {
    foreach ($provinceRows as $districtName => $municipalities) {
        $nepalDistricts[$districtName] = $districtName;
    }
}
ksort($nepalDistricts, SORT_NATURAL | SORT_FLAG_CASE);

// Public + member both allowed.
// Member logged-in हुँदा prefill/edit experience राम्रो हुन्छ.
$loggedMember = currentMember() ?: [];
$isMemberLoggedIn = !empty($loggedMember);

$pageTitle = isEnglish() ? 'Online KYC Form' : 'अनलाइन केवाइसी फारम';
require_once 'includes/header.php';
$L = getLangStrings();

$success = false;
$error = '';
$kycTrackingId = '';
$oldInput = [];
$prefillInput = [];
$kycWasUpdate = false;
$isEmbed = !empty($_GET['embed']);
$trackerUrl = ($isEmbed || $isMemberLoggedIn)
    ? (rtrim(SITE_URL, '/') . '/member/tracker.php')
    : 'application-tracker.php';
$kycFollowUpUrl = ($isEmbed || $isMemberLoggedIn)
    ? (rtrim(SITE_URL, '/') . '/member/profile.php')
    : 'online-kyc.php';
$kycFollowUpLabel = ($isEmbed || $isMemberLoggedIn)
    ? (isEnglish() ? 'Back to Profile' : 'प्रोफाइलमा फर्कनुहोस्')
    : (isEnglish() ? 'New KYC' : 'नयाँ KYC');
$kycFollowUpIcon = ($isEmbed || $isMemberLoggedIn) ? 'fa-user' : 'fa-plus';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* error आए पनि भरिएको data retain गर्न */
    $oldInput = $_POST;
    // CSRF Protection
    if (!verifyCSRFToken()) {
        $error = isEnglish() ? 'Security check failed. Please try again.' : 'सुरक्षा जाँच असफल। कृपया पुन: प्रयास गर्नुहोस्।';
    }
    // Rate Limiting
    elseif (!checkRateLimit('kyc_form', 10, 3600)) {
        $error = isEnglish() ? 'Too many requests. Please try again after 1 hour.' : 'धेरै अनुरोधहरू। कृपया १ घण्टापछि पुनः प्रयास गर्नुहोस्।';
    }
    else {
        try {
            $db = getDB();

            // Public quick KYC (minimal fields only)
            if (!$isMemberLoggedIn && isset($_POST['public_quick_submit'])) {
                $full_name = clean_text($_POST['full_name'] ?? '', 200);
                $member_id = strtoupper(trim(clean_text($_POST['member_id'] ?? '', 80)));
                $mobile = preg_replace('/[^0-9]/', '', clean_text($_POST['mobile'] ?? '', 15));
                $email = strtolower(clean_text($_POST['email'] ?? '', 254));
                $national_id_number = clean_text($_POST['national_id_number'] ?? '', 80);

                if ($full_name === '') {
                    $error = isEnglish() ? 'Please enter your full name.' : 'कृपया पूरा नाम भर्नुहोस्।';
                } elseif ($member_id === '') {
                    $error = isEnglish() ? 'Member ID is required.' : 'सदस्यता नम्बर (Member ID) अनिवार्य छ।';
                } elseif (!preg_match('/^[0-9]{10}$/', $mobile)) {
                    $error = isEnglish() ? 'Please enter a valid 10-digit mobile number.' : 'कृपया १० अंकको मोबाइल नम्बर राख्नुहोस्।';
                } elseif (!isValidEmail($email)) {
                    $error = isEnglish() ? 'Please enter a valid email address.' : 'कृपया सही इमेल ठेगाना राख्नुहोस्।';
                } elseif ($national_id_number === '') {
                    $error = isEnglish() ? 'National ID number is required.' : 'National ID नम्बर अनिवार्य छ।';
                } else {
                    $existingKyc = null;
                    $q = $db->prepare("SELECT id, tracking_id FROM kyc_applications
                                       WHERE member_id=? OR mobile=? OR LOWER(email)=?
                                       ORDER BY id DESC LIMIT 1");
                    $q->execute([$member_id, $mobile, strtolower($email)]);
                    $existingKyc = $q->fetch(PDO::FETCH_ASSOC) ?: null;

                    $kycTrackingId = $existingKyc['tracking_id'] ?? ('KYC-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6)));
                    if ($existingKyc) {
                        $kycWasUpdate = true;
                        $u = $db->prepare("UPDATE kyc_applications SET
                            tracking_id=?, member_id=?, full_name=?, mobile=?, email=?, national_id_number=?,
                            status='partial', risk_category='medium', updated_at=NOW()
                            WHERE id=?");
                        $u->execute([$kycTrackingId, $member_id, $full_name, $mobile, $email, $national_id_number, (int)$existingKyc['id']]);
                    } else {
                        $i = $db->prepare("INSERT INTO kyc_applications
                            (tracking_id, member_id, full_name, mobile, email, national_id_number, risk_category, status, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, 'medium', 'partial', NOW())");
                        $i->execute([$kycTrackingId, $member_id, $full_name, $mobile, $email, $national_id_number]);
                    }
                    $success = true;
                    logSecurityEvent('kyc_quick_public', 'Public quick KYC submitted: ' . $full_name . ' (' . $member_id . ')');
                }
            } else {

            // Personal Information
            $full_name = clean_text($_POST['full_name'] ?? '');
            $full_name_en = clean_text($_POST['full_name_en'] ?? '');
            $member_id = strtoupper(trim(clean_text($_POST['member_id'] ?? '')));
            $dob_bs = clean_text($_POST['dob_bs'] ?? '');
            $dob_ad = clean_text($_POST['dob_ad'] ?? '');
            $dob_ad = ($dob_ad === '' ? null : $dob_ad);
            $gender = clean_text($_POST['gender'] ?? '');
            $marital_status = clean_text($_POST['marital_status'] ?? '');
            $nationality = clean_text($_POST['nationality'] ?? 'नेपाली');

            // Contact Information
            $mobile = clean_text($_POST['mobile'] ?? '');
            $email = clean_text($_POST['email'] ?? '');
            // v10.4 — Structured address (Province/District/Municipality/Ward/Tole) auto-composed
            $sameAddress = !empty($_POST['same_as_permanent']);
            $permanent_address = composeAddress('permanent') ?: ($existingKyc['permanent_address'] ?? '');
            // Fallback: keep textarea support if user used legacy form
            if ($permanent_address === '' && !empty($_POST['permanent_address'])) {
                $permanent_address = clean_text($_POST['permanent_address']);
            }
            if ($sameAddress) {
                $temporary_address = $permanent_address;
            } else {
                $temporary_address = composeAddress('temporary') ?: ($existingKyc['temporary_address'] ?? '');
                if ($temporary_address === '' && !empty($_POST['temporary_address'])) {
                    $temporary_address = clean_text($_POST['temporary_address']);
                }
            }

            // Identity Information
            $citizenship_no = clean_text($_POST['citizenship_no'] ?? '');
            $citizenship_issued_date = clean_text($_POST['citizenship_issued_date'] ?? '');
            $citizenship_issued_place = clean_text($_POST['citizenship_issued_place'] ?? '');
            $national_id_number = clean_text($_POST['national_id_number'] ?? '');
            $risk_category = strtolower(trim((string)($_POST['risk_category'] ?? 'medium')));
            if (!in_array($risk_category, ['low', 'medium', 'high'], true)) $risk_category = 'medium';
            $passport_face_confirm = !empty($_POST['passport_face_confirm']);
            $citizenshipDigits = preg_replace('/[^0-9]/', '', $citizenship_no);
            $nidDigits = preg_replace('/[^0-9]/', '', $national_id_number);
            $photo_quality_score = (int)($_POST['photo_quality_score'] ?? 0);
            if ($photo_quality_score < 0) $photo_quality_score = 0;
            if ($photo_quality_score > 100) $photo_quality_score = 100;

            // Family Information
            $father_name = clean_text($_POST['father_name'] ?? '');
            $mother_name = clean_text($_POST['mother_name'] ?? '');
            $grandfather_name = clean_text($_POST['grandfather_name'] ?? '');
            $spouse_name = clean_text($_POST['spouse_name'] ?? '');
            $family_details_json = null;
            $familyRows = [];
            $relList = $_POST['family_relation'] ?? [];
            $nameList = $_POST['family_member_name'] ?? [];
            $phoneList = $_POST['family_member_phone'] ?? [];
            $rowCount = max(count((array)$relList), count((array)$nameList), count((array)$phoneList));
            for ($i = 0; $i < $rowCount; $i++) {
                $relation = clean_text((string)($relList[$i] ?? ''));
                $name = clean_text((string)($nameList[$i] ?? ''));
                $phone = clean_text((string)($phoneList[$i] ?? ''));
                if ($relation === '' && $name === '' && $phone === '') {
                    continue;
                }
                if ($name === '') {
                    continue;
                }
                $familyRows[] = [
                    'relation' => $relation,
                    'name' => $name,
                    'phone' => $phone,
                ];
            }

            if (!empty($familyRows)) {
                foreach ($familyRows as $frow) {
                    $r = strtolower(trim((string)($frow['relation'] ?? '')));
                    $n = trim((string)($frow['name'] ?? ''));
                    if ($n === '') continue;
                    if ($r === 'father' && $father_name === '') $father_name = $n;
                    if ($r === 'mother' && $mother_name === '') $mother_name = $n;
                    if ($r === 'grandfather' && $grandfather_name === '') $grandfather_name = $n;
                    if ($r === 'spouse' && $spouse_name === '') $spouse_name = $n;
                }
                $family_details_json = json_encode($familyRows, JSON_UNESCAPED_UNICODE);
            }

            // Occupation
            $occupation = clean_text($_POST['occupation'] ?? '');
            $organization_name = clean_text($_POST['organization_name'] ?? '');
            $monthly_income = clean_text($_POST['monthly_income'] ?? '');
            $isRented = clean_text($_POST['is_rented'] ?? '');
            if ($isRented !== 'yes') {
                $_POST['landlord_name'] = '';
                $_POST['landlord_contact'] = '';
            }
            if ($occupation !== 'business') {
                $_POST['occupation_location'] = '';
                $_POST['occupation_business_name'] = '';
                $_POST['business_pan_no'] = '';
                $_POST['business_registration_type'] = '';
                $_POST['business_registration_no'] = '';
                $_POST['business_registration_office'] = '';
                $_POST['business_registration_date_bs'] = '';
                $_POST['business_nature'] = '';
                $_POST['estimated_annual_income'] = '';
            }
            if (clean_text($_POST['self_other_coop_member'] ?? '') !== 'yes') {
                $_POST['self_other_coop_details'] = '';
            }
            if (clean_text($_POST['family_same_coop_member'] ?? '') !== 'yes') {
                $_POST['family_same_coop_details'] = '';
                $_POST['family_same_member_name'] = '';
                $_POST['family_same_member_id'] = '';
            }

            $incomeItems = [];
            $incomeNames = $_POST['income_source_name'] ?? [];
            $incomeAmts = $_POST['income_source_amount'] ?? [];
            $incomeCount = max(count((array)$incomeNames), count((array)$incomeAmts));
            $incomeTotal = 0.0;
            for ($i = 0; $i < $incomeCount; $i++) {
                $nm = clean_text((string)($incomeNames[$i] ?? ''));
                $amtRaw = (string)($incomeAmts[$i] ?? '');
                $amtClean = preg_replace('/[^0-9.]/', '', $amtRaw);
                $amt = (float)$amtClean;
                if ($nm === '' && $amt <= 0) continue;
                $incomeItems[] = ['name' => $nm, 'amount' => $amt];
                $incomeTotal += $amt;
            }

            $expenseItems = [];
            $expenseNames = $_POST['expense_source_name'] ?? [];
            $expenseAmts = $_POST['expense_source_amount'] ?? [];
            $expenseCount = max(count((array)$expenseNames), count((array)$expenseAmts));
            $expenseTotal = 0.0;
            for ($i = 0; $i < $expenseCount; $i++) {
                $nm = clean_text((string)($expenseNames[$i] ?? ''));
                $amtRaw = (string)($expenseAmts[$i] ?? '');
                $amtClean = preg_replace('/[^0-9.]/', '', $amtRaw);
                $amt = (float)$amtClean;
                if ($nm === '' && $amt <= 0) continue;
                $expenseItems[] = ['name' => $nm, 'amount' => $amt];
                $expenseTotal += $amt;
            }

            // AML / CFT extended details (KYM format)
            $amlExtra = [
                'passport_no' => clean_text($_POST['passport_no'] ?? ''),
                'pan_no' => clean_text($_POST['pan_no'] ?? ''),
                'driving_license_no' => clean_text($_POST['driving_license_no'] ?? ''),
                'education_qualification' => clean_text($_POST['education_qualification'] ?? ''),
                'religion' => clean_text($_POST['religion'] ?? ''),
                'caste' => clean_text($_POST['caste'] ?? ''),
                'occupation_location' => clean_text($_POST['occupation_location'] ?? ''),
                'occupation_business_name' => clean_text($_POST['occupation_business_name'] ?? ''),
                'business_pan_no' => clean_text($_POST['business_pan_no'] ?? ''),
                'business_registration_type' => clean_text($_POST['business_registration_type'] ?? ''),
                'business_registration_no' => clean_text($_POST['business_registration_no'] ?? ''),
                'business_registration_office' => clean_text($_POST['business_registration_office'] ?? ''),
                'business_registration_date_bs' => clean_text($_POST['business_registration_date_bs'] ?? ''),
                'business_nature' => clean_text($_POST['business_nature'] ?? ''),
                'estimated_annual_income' => clean_text($_POST['estimated_annual_income'] ?? ''),
                'politically_exposed' => clean_text($_POST['politically_exposed'] ?? ''),
                'past_crime_declared' => clean_text($_POST['past_crime_declared'] ?? ''),
                'landlord_name' => clean_text($_POST['landlord_name'] ?? ''),
                'landlord_contact' => clean_text($_POST['landlord_contact'] ?? ''),
                'is_rented' => clean_text($_POST['is_rented'] ?? ''),
                'voter_id_card_no' => clean_text($_POST['voter_id_card_no'] ?? ''),
                'polling_station' => clean_text($_POST['polling_station'] ?? ''),
                'member_purpose' => clean_text($_POST['member_purpose'] ?? ''),
                'self_other_coop_member' => clean_text($_POST['self_other_coop_member'] ?? ''),
                'self_other_coop_details' => clean_text($_POST['self_other_coop_details'] ?? ''),
                'family_same_coop_member' => clean_text($_POST['family_same_coop_member'] ?? ''),
                'family_same_coop_details' => clean_text($_POST['family_same_coop_details'] ?? ''),
                'family_same_member_name' => clean_text($_POST['family_same_member_name'] ?? ''),
                'family_same_member_id' => clean_text($_POST['family_same_member_id'] ?? ''),
                'annual_family_income' => clean_text($_POST['annual_family_income'] ?? ''),
                'net_worth_details' => clean_text($_POST['net_worth_details'] ?? ''),
                'annual_debit_credit_estimate' => clean_text($_POST['annual_debit_credit_estimate'] ?? ''),
                'annual_turnover_numbers' => clean_text($_POST['annual_turnover_numbers'] ?? ''),
                'annual_deposit_estimate' => clean_text($_POST['annual_deposit_estimate'] ?? ''),
                'institution_debt_estimate' => clean_text($_POST['institution_debt_estimate'] ?? ''),
                'nearest_person_name' => clean_text($_POST['nearest_person_name'] ?? ''),
                'nearest_person_relation' => clean_text($_POST['nearest_person_relation'] ?? ''),
                'nominee_name' => clean_text($_POST['nominee_name'] ?? ''),
                'nominee_dob' => clean_text($_POST['nominee_dob'] ?? ''),
                'nominee_citizenship_no' => clean_text($_POST['nominee_citizenship_no'] ?? ''),
                'nominee_relation' => clean_text($_POST['nominee_relation'] ?? ''),
                'nominee_issue_district' => clean_text($_POST['nominee_issue_district'] ?? ''),
                'nominee_issue_date' => clean_text($_POST['nominee_issue_date'] ?? ''),
                'nominee_permanent_address' => clean_text($_POST['nominee_permanent_address'] ?? ''),
                'nominee_temporary_address' => clean_text($_POST['nominee_temporary_address'] ?? ''),
                'longitude_latitude' => clean_text($_POST['longitude_latitude'] ?? ''),
                'map_resolved_address' => clean_text($_POST['map_resolved_address'] ?? ''),
                'other_attached_docs' => clean_text($_POST['other_attached_docs'] ?? ''),
            ];
            if (!empty($incomeItems)) $amlExtra['income_items'] = $incomeItems;
            if (!empty($expenseItems)) $amlExtra['expense_items'] = $expenseItems;
            if ($incomeTotal > 0) $amlExtra['income_total'] = round($incomeTotal, 2);
            if ($expenseTotal > 0) $amlExtra['expense_total'] = round($expenseTotal, 2);
            if ($incomeTotal > 0 || $expenseTotal > 0) $amlExtra['net_saving_estimate'] = round($incomeTotal - $expenseTotal, 2);
            $amlExtra = array_filter($amlExtra, static function ($v) {
                if (is_array($v)) return !empty($v);
                return trim((string)$v) !== '';
            });
            $aml_details_json = !empty($amlExtra) ? json_encode($amlExtra, JSON_UNESCAPED_UNICODE) : null;

            // Account Type
            $account_type = clean_text($_POST['account_type'] ?? '');
            $branch = clean_text($_POST['branch'] ?? '');

            /* -------------------------------------------------------
               Server-side validation — KYC application
               नाम, मोबाइल (10 digits), इमेल, नागरिकता, ठेगाना required
            ------------------------------------------------------- */
            if (empty($full_name)) {
                $error = isEnglish() ? 'Please enter your full name.' : 'कृपया पूरा नाम भर्नुहोस्।';
            } elseif (empty($member_id)) {
                $error = isEnglish() ? 'Member ID is required.' : 'सदस्यता नम्बर (Member ID) अनिवार्य छ।';
            } elseif (empty($mobile)) {
                $error = isEnglish() ? 'Mobile number is required.' : 'मोबाइल नम्बर अनिवार्य छ।';
            } elseif (!preg_match('/^[0-9]{10}$/', $mobile)) {
                $error = isEnglish() ? 'Please enter a valid 10-digit mobile number.' : 'कृपया १० अंकको मोबाइल नम्बर राख्नुहोस्।';
            } elseif (empty($email)) {
                $error = isEnglish() ? 'Email address is required.' : 'इमेल ठेगाना अनिवार्य छ।';
            } elseif (!isValidEmail($email)) {
                $error = isEnglish() ? 'Please enter a valid email address.' : 'कृपया सही इमेल ठेगाना राख्नुहोस्।';
            } elseif (empty($citizenship_no)) {
                $error = isEnglish() ? 'Citizenship number is required.' : 'नागरिकता नम्बर अनिवार्य छ।';
            } elseif (empty($_POST['risk_category'])) {
                $error = isEnglish() ? 'Please select KYC risk category.' : 'कृपया KYC risk category छान्नुहोस्।';
            } elseif (trim((string)$nationality) === 'नेपाली' && $national_id_number !== '' && strlen($nidDigits) !== 14) {
                $error = isEnglish()
                    ? 'For Nepal, National ID number should be 14 digits.'
                    : 'नेपालको लागि National ID नम्बर १४ अङ्कको हुनुपर्छ।';
            } elseif (trim((string)$nationality) === 'नेपाली' && $national_id_number !== '' && $citizenshipDigits !== '' && $nidDigits === $citizenshipDigits) {
                $error = isEnglish()
                    ? 'Citizenship number and National ID number cannot be exactly the same.'
                    : 'नागरिकता नम्बर र National ID नम्बर ठ्याक्कै एउटै हुन मिल्दैन।';
            } elseif (empty($permanent_address)) {
                $error = isEnglish() ? 'Permanent address is required.' : 'स्थायी ठेगाना अनिवार्य छ।';
            } else {
                // v10.4 — Capture-aware: base64 (camera/canvas) OR native upload, both supported
                $photo             = captureOrUpload('photo',             'kyc', true);
                $citizenship_front = captureOrUpload('citizenship_front', 'kyc', true);
                $citizenship_back  = captureOrUpload('citizenship_back',  'kyc', true);
                $national_id_card  = captureOrUpload('national_id_card',  'kyc', true);
                $signature         = captureOrUpload('signature',         'kyc', false);
                $left_thumb        = captureOrUpload('left_thumb',        'kyc', true);
                $right_thumb       = captureOrUpload('right_thumb',       'kyc', true);

                // cPanel/MySQL compatibility — ensure all v10.4 columns exist
                $newCols = [
                    'tracking_id'          => "VARCHAR(60) UNIQUE NULL",
                    'want_id_card'         => "TINYINT DEFAULT 0",
                    'member_id'            => "VARCHAR(50) NULL",
                    'national_id_number'   => "VARCHAR(50) NULL",
                    'national_id_card'     => "VARCHAR(255) NULL",
                    'photo_quality_score'  => "TINYINT UNSIGNED NULL",
                    'risk_category'        => "ENUM('low','medium','high') DEFAULT 'medium'",
                    'kyc_verified_at'      => "DATETIME NULL",
                    'risk_review_due_at'   => "DATE NULL",
                    'risk_review_status'   => "ENUM('normal','due_review') DEFAULT 'normal'",
                    'grandfather_name'     => "VARCHAR(100) NULL",
                    'spouse_name'          => "VARCHAR(100) NULL",
                    'family_details_json'  => "TEXT NULL",
                    'left_thumb'           => "VARCHAR(255) NULL",
                    'right_thumb'          => "VARCHAR(255) NULL",
                    'permanent_province'   => "VARCHAR(60) NULL",
                    'permanent_district'   => "VARCHAR(60) NULL",
                    'permanent_municipality'=> "VARCHAR(120) NULL",
                    'permanent_ward'       => "VARCHAR(10) NULL",
                    'permanent_tole'       => "VARCHAR(120) NULL",
                    'temporary_province'   => "VARCHAR(60) NULL",
                    'temporary_district'   => "VARCHAR(60) NULL",
                    'temporary_municipality'=> "VARCHAR(120) NULL",
                    'temporary_ward'       => "VARCHAR(10) NULL",
                    'temporary_tole'       => "VARCHAR(120) NULL",
                    'aml_details_json'     => "LONGTEXT NULL",
                ];
                foreach ($newCols as $col => $def) {
                    if (function_exists('safeAddColumn')) {
                        safeAddColumn($db, 'kyc_applications', $col, $def);
                    } else {
                        try {
                            $c = $db->query("SHOW COLUMNS FROM kyc_applications LIKE " . $db->quote($col));
                            if (!$c || $c->fetch() === false) {
                                $db->exec("ALTER TABLE kyc_applications ADD COLUMN $col $def");
                            }
                        } catch (Throwable $ignored) {}
                    }
                }

                // एउटै member ID को active KYC: नयाँ insert होइन, existing record update गर्ने
                $existingKyc = null;
                try {
                    $linkedKycId = (int)($loggedMember['kyc_application_id'] ?? 0);
                    if ($linkedKycId > 0) {
                        $dup = $db->prepare("SELECT id FROM kyc_applications WHERE id=? LIMIT 1");
                        $dup->execute([$linkedKycId]);
                        $existingKyc = $dup->fetch(PDO::FETCH_ASSOC) ?: null;
                    }
                    if (!$existingKyc) {
                        $dup = $db->prepare("SELECT id FROM kyc_applications
                                             WHERE member_id = ?
                                               AND status IN ('pending','approved','incomplete','partial')
                                             ORDER BY id DESC LIMIT 1");
                        $dup->execute([$member_id]);
                        $existingKyc = $dup->fetch(PDO::FETCH_ASSOC) ?: null;
                    }
                } catch (Throwable $ignored) {
                    // fallback silent
                }

                $photoHasInput = (!empty($_POST['photo']) && is_string($_POST['photo']) && strpos($_POST['photo'], 'data:image') === 0)
                    || (isset($_FILES['photo']['error']) && (int)$_FILES['photo']['error'] === UPLOAD_ERR_OK);
                if ($photoHasInput && !$passport_face_confirm) {
                    $error = isEnglish()
                        ? 'Please confirm passport photo guidelines (eyes and both ears visible).'
                        : 'पासपोर्ट फोटो guideline पुष्टि गर्नुहोस् (दुवै आँखा र दुवै कान स्पष्ट देखिनुपर्छ)।';
                }

                // Server-side: if JS quality score sent and is critically low, flag for admin review
                if (!$error && $photo_quality_score > 0 && $photo_quality_score < 30) {
                    $error = isEnglish()
                        ? 'Photo quality is too low (score: ' . $photo_quality_score . '/100). Please retake with better lighting and a clear face.'
                        : 'फोटो गुणस्तर धेरै कम छ (Score: ' . $photo_quality_score . '/100)। राम्रो उज्यालोमा स्पष्ट अनुहार देखिने फोटो फेरि खिच्नुहोस्।';
                }

                if (!$error) {
                    $kycTrackingId = $existingKyc['tracking_id'] ?? ('KYC-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6)));
                    $want_id_card  = isset($_POST['want_id_card']) ? 1 : 0;

                    // v10.4 — extended INSERT (signature, fingerprints, structured address)
                    $stmt = $db->prepare("INSERT INTO kyc_applications (
                    tracking_id, want_id_card, member_id,
                    full_name, full_name_en, dob_bs, dob_ad, gender, marital_status, nationality,
                    mobile, email, permanent_address, temporary_address,
                    citizenship_no, citizenship_issued_date, citizenship_issued_place, national_id_number, national_id_card,
                    photo_quality_score, risk_category, father_name, mother_name, grandfather_name, spouse_name, family_details_json,
                    occupation, organization_name, monthly_income,
                    account_type, branch, photo, citizenship_front, citizenship_back, signature,
                    left_thumb, right_thumb,
                    permanent_province, permanent_district, permanent_municipality, permanent_ward, permanent_tole,
                    temporary_province, temporary_district, temporary_municipality, temporary_ward, temporary_tole
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                    $tProv = $sameAddress ? clean_text($_POST['permanent_province'] ?? '')      : clean_text($_POST['temporary_province'] ?? '');
                    $tDist = $sameAddress ? clean_text($_POST['permanent_district'] ?? '')      : clean_text($_POST['temporary_district'] ?? '');
                    $tMuni = $sameAddress ? clean_text($_POST['permanent_municipality'] ?? '')  : clean_text($_POST['temporary_municipality'] ?? '');
                    $tWard = $sameAddress ? clean_text($_POST['permanent_ward'] ?? '')          : clean_text($_POST['temporary_ward'] ?? '');
                    $tTole = $sameAddress ? clean_text($_POST['permanent_tole'] ?? '')          : clean_text($_POST['temporary_tole'] ?? '');

                    if ($existingKyc) {
                        $kycWasUpdate = true;
                        $update = $db->prepare("UPDATE kyc_applications SET
                            tracking_id=?, want_id_card=?, member_id=?,
                            full_name=?, full_name_en=?, dob_bs=?, dob_ad=?, gender=?, marital_status=?, nationality=?,
                            mobile=?, email=?, permanent_address=?, temporary_address=?,
                            citizenship_no=?, citizenship_issued_date=?, citizenship_issued_place=?, national_id_number=?,
                            photo_quality_score=?, risk_category=?, father_name=?, mother_name=?, grandfather_name=?, spouse_name=?, family_details_json=?,
                            occupation=?, organization_name=?, monthly_income=?, account_type=?, branch=?,
                            permanent_province=?, permanent_district=?, permanent_municipality=?, permanent_ward=?, permanent_tole=?,
                            temporary_province=?, temporary_district=?, temporary_municipality=?, temporary_ward=?, temporary_tole=?,
                            aml_details_json=?, updated_at=NOW(),
                            photo = COALESCE(NULLIF(?, ''), photo),
                            citizenship_front = COALESCE(NULLIF(?, ''), citizenship_front),
                            citizenship_back = COALESCE(NULLIF(?, ''), citizenship_back),
                            national_id_card = COALESCE(NULLIF(?, ''), national_id_card),
                            signature = COALESCE(NULLIF(?, ''), signature),
                            left_thumb = COALESCE(NULLIF(?, ''), left_thumb),
                            right_thumb = COALESCE(NULLIF(?, ''), right_thumb)
                            WHERE id=?");
                        $update->execute([
                            $kycTrackingId, $want_id_card, $member_id,
                            $full_name, $full_name_en, $dob_bs, $dob_ad, $gender, $marital_status, $nationality,
                            $mobile, $email, $permanent_address, $temporary_address,
                            $citizenship_no, $citizenship_issued_date, $citizenship_issued_place, $national_id_number,
                            $photo_quality_score ?: null, $risk_category, $father_name, $mother_name, $grandfather_name, $spouse_name, $family_details_json,
                            $occupation, $organization_name, $monthly_income, $account_type, $branch,
                            clean_text($_POST['permanent_province'] ?? ''),
                            clean_text($_POST['permanent_district'] ?? ''),
                            clean_text($_POST['permanent_municipality'] ?? ''),
                            clean_text($_POST['permanent_ward'] ?? ''),
                            clean_text($_POST['permanent_tole'] ?? ''),
                            $tProv, $tDist, $tMuni, $tWard, $tTole,
                            $aml_details_json,
                            $photo, $citizenship_front, $citizenship_back, $national_id_card, $signature, $left_thumb, $right_thumb,
                            (int)$existingKyc['id']
                        ]);
                        if (empty($loggedMember['kyc_application_id']) && !empty($loggedMember['id'])) {
                            try {
                                $db->prepare("UPDATE members SET kyc_application_id=? WHERE id=?")
                                   ->execute([(int)$existingKyc['id'], (int)$loggedMember['id']]);
                            } catch (Throwable $ignored) {}
                        }
                    } else {
                        $stmt->execute([
                        $kycTrackingId, $want_id_card, $member_id,
                        $full_name, $full_name_en, $dob_bs, $dob_ad, $gender, $marital_status, $nationality,
                        $mobile, $email, $permanent_address, $temporary_address,
                        $citizenship_no, $citizenship_issued_date, $citizenship_issued_place, $national_id_number, $national_id_card,
                        $photo_quality_score ?: null,
                        $risk_category,
                        $father_name, $mother_name, $grandfather_name, $spouse_name, $family_details_json,
                        $occupation, $organization_name, $monthly_income,
                        $account_type, $branch, $photo, $citizenship_front, $citizenship_back, $signature,
                        $left_thumb, $right_thumb,
                        clean_text($_POST['permanent_province'] ?? ''),
                        clean_text($_POST['permanent_district'] ?? ''),
                        clean_text($_POST['permanent_municipality'] ?? ''),
                        clean_text($_POST['permanent_ward'] ?? ''),
                        clean_text($_POST['permanent_tole'] ?? ''),
                        $tProv, $tDist, $tMuni, $tWard, $tTole
                        ]);
                        if ($aml_details_json !== null) {
                            try {
                                $lastId = (int)$db->lastInsertId();
                                if ($lastId > 0) {
                                    $upAml = $db->prepare("UPDATE kyc_applications SET aml_details_json=? WHERE id=?");
                                    $upAml->execute([$aml_details_json, $lastId]);
                                }
                            } catch (Throwable $ignored) {}
                        }
                        if (!empty($loggedMember['id'])) {
                            try {
                                $lastId = (int)$db->lastInsertId();
                                if ($lastId > 0) {
                                    $db->prepare("UPDATE members SET kyc_application_id=? WHERE id=?")
                                       ->execute([$lastId, (int)$loggedMember['id']]);
                                }
                            } catch (Throwable $ignored) {}
                        }
                    }

                    $success = true;
                    logSecurityEvent('kyc_application', 'KYC submitted/updated by: ' . $full_name . ' (Tracking: ' . $kycTrackingId . ')');

                    require_once 'includes/notifications.php';

                    // Member confirmation SMS
                    if (!empty($mobile)) {
                        try {
                            $smsToken = getSetting('notify_sms_token', '');
                            $smsSender = getSetting('notify_sms_sender_id', 'COOP');
                            if (getSetting('notify_sms_enabled', '0') === '1' && $smsToken) {
                                $smsTxt = 'आकाश सहकारी: तपाईंको KYC आवेदन दर्ता भयो। Tracking ID: ' . $kycTrackingId . '. application-tracker.php मा track गर्नुहोस्।';
                                $ph = preg_replace('/[^0-9]/', '', $mobile);
                                if (strlen($ph) >= 10) {
                                    $ch = curl_init('http://api.sparrowsms.com/v2/sms/');
                                    curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['token'=>$smsToken,'from'=>$smsSender,'to'=>$ph,'text'=>mb_substr($smsTxt,0,160)]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true]);
                                    curl_exec($ch); curl_close($ch);
                                }
                            }
                        } catch (Exception $ignored) {}
                    }

                    sendAdminNotification('kyc_application', [
                        'Member ID'   => $member_id,
                        'नाम'         => $full_name,
                        'नाम (EN)'    => $full_name_en ?: 'N/A',
                        'फोन'         => $mobile,
                        'इमेल'        => $email ?: 'N/A',
                        'खाता प्रकार' => $account_type ?: 'N/A',
                        'Tracking ID' => $kycTrackingId,
                        'शाखा'        => $branch ?: 'N/A',
                        'मिति'        => date('Y-m-d H:i'),
                    ], $kycTrackingId);
                }
            }
            }
        } catch (Throwable $e) {
            error_log('online-kyc submit error: ' . $e->getMessage());
            $error = isEnglish() ? 'An error occurred. Please try again.' : 'त्रुटि भयो। कृपया पुन: प्रयास गर्नुहोस्।';
        }
    }
}

// Get branches for dropdown
try {
    $db = getDB();
    require_once __DIR__ . '/includes/service-centers-helpers.php';
    $branches = fetchActiveServiceCenters($db, 20);
} catch (Exception $e) {
    $branches = [];
}

// Prefill: logged-in member ko latest editable KYC लाई form मा देखाउने
try {
    $db = $db ?? getDB();
    $row = null;
    $memberRefCandidates = array_filter(array_map('trim', [
        (string)($loggedMember['sadasyata_number'] ?? ''),
        (string)($loggedMember['member_card_no'] ?? ''),
        (string)($loggedMember['member_id'] ?? '')
    ]), static fn($v) => $v !== '');
    $memberEmail = strtolower(trim((string)($loggedMember['email'] ?? '')));
    $memberPhoneDigits = preg_replace('/[^0-9]/', '', (string)($loggedMember['phone'] ?? ''));

    // 0) explicit kyc_id from profile edit link (with ownership safety check)
    $forcedKycId = (int)($_GET['kyc_id'] ?? 0);
    if ($forcedKycId > 0) {
        $pf = $db->prepare("SELECT * FROM kyc_applications WHERE id=? LIMIT 1");
        $pf->execute([$forcedKycId]);
        $forcedRow = $pf->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($forcedRow) {
            $ownerOk = false;
            $trustedFromProfile = ((int)($_SESSION['member_last_profile_kyc_id'] ?? 0) === $forcedKycId);
            if ($trustedFromProfile) $ownerOk = true;
            $rowMemberId = trim((string)($forcedRow['member_id'] ?? ''));
            if ($rowMemberId !== '' && in_array($rowMemberId, $memberRefCandidates, true)) $ownerOk = true;
            if (!$ownerOk && $memberEmail !== '' && strtolower(trim((string)($forcedRow['email'] ?? ''))) === $memberEmail) $ownerOk = true;
            if (!$ownerOk && $memberPhoneDigits !== '') {
                $rowPhoneDigits = preg_replace('/[^0-9]/', '', (string)($forcedRow['mobile'] ?? ''));
                if ($rowPhoneDigits !== '' && $rowPhoneDigits === $memberPhoneDigits) $ownerOk = true;
            }
            if ($ownerOk) $row = $forcedRow;
        }
    }

    // 1) direct linked KYC (most reliable)
    $linkedKycId = (int)($loggedMember['kyc_application_id'] ?? 0);
    if (!$row && $linkedKycId > 0) {
        $pf = $db->prepare("SELECT * FROM kyc_applications WHERE id=? LIMIT 1");
        $pf->execute([$linkedKycId]);
        $row = $pf->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // 2) fallback by member_id references
    if (!$row) {
        foreach ($memberRefCandidates as $memberRef) {
            $pf = $db->prepare("SELECT * FROM kyc_applications
                                WHERE member_id=?
                                ORDER BY id DESC
                                LIMIT 1");
            $pf->execute([$memberRef]);
            $row = $pf->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) break;
        }
    }

    // 3) fallback by email/phone (same as member/profile logic)
    if (!$row) {
        $where = [];
        $params = [];
        if ($memberEmail !== '') { $where[] = 'LOWER(email)=?'; $params[] = $memberEmail; }
        if ($memberPhoneDigits !== '') {
            $where[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(mobile,'-',''),' ',''),'+',''),'(',''),')','') = ?";
            $params[] = $memberPhoneDigits;
        }
        if (!empty($where)) {
            $pf = $db->prepare("SELECT * FROM kyc_applications
                                WHERE (" . implode(' OR ', $where) . ")
                                ORDER BY id DESC
                                LIMIT 1");
            $pf->execute($params);
            $row = $pf->fetch(PDO::FETCH_ASSOC) ?: null;
            // auto-link to member for future faster prefill
            if ($row && empty($loggedMember['kyc_application_id']) && !empty($loggedMember['id'])) {
                try {
                    $lk = $db->prepare("UPDATE members SET kyc_application_id=? WHERE id=?");
                    $lk->execute([(int)$row['id'], (int)$loggedMember['id']]);
                } catch (Throwable $ignored) {}
            }
        }
    }

    if ($row) {
            $map = [
                'full_name','full_name_en','member_id','dob_bs','dob_ad','gender','marital_status','mobile','email',
                'citizenship_no','citizenship_issued_date','citizenship_issued_place','national_id_number','risk_category',
                'occupation','organization_name','monthly_income','account_type','branch',
                'permanent_province','permanent_district','permanent_municipality','permanent_ward','permanent_tole',
                'temporary_province','temporary_district','temporary_municipality','temporary_ward','temporary_tole',
                'photo','citizenship_front','citizenship_back','national_id_card','signature','left_thumb','right_thumb'
            ];
            foreach ($map as $k) {
                if (isset($row[$k]) && trim((string)$row[$k]) !== '') $prefillInput[$k] = (string)$row[$k];
            }
            if (!empty($row['want_id_card'])) $prefillInput['want_id_card'] = '1';

            // Old records may only have full address text; fallback into visible fields
            $hasPermStructured = false;
            foreach (['permanent_province','permanent_district','permanent_municipality','permanent_ward','permanent_tole'] as $k) {
                if (!empty($prefillInput[$k])) { $hasPermStructured = true; break; }
            }
            if (!$hasPermStructured && !empty($row['permanent_address'])) {
                $prefillInput['permanent_tole'] = (string)$row['permanent_address'];
            }
            $hasTempStructured = false;
            foreach (['temporary_province','temporary_district','temporary_municipality','temporary_ward','temporary_tole'] as $k) {
                if (!empty($prefillInput[$k])) { $hasTempStructured = true; break; }
            }
            if (!$hasTempStructured && !empty($row['temporary_address'])) {
                $prefillInput['temporary_tole'] = (string)$row['temporary_address'];
            }
            if (!empty($row['permanent_address']) && !empty($row['temporary_address']) && trim((string)$row['permanent_address']) === trim((string)$row['temporary_address'])) {
                $prefillInput['same_as_permanent'] = '1';
            }

            $famRaw = trim((string)($row['family_details_json'] ?? ''));
            if ($famRaw !== '') {
                $fam = json_decode($famRaw, true);
                if (is_array($fam)) {
                    $prefillInput['family_relation'] = [];
                    $prefillInput['family_member_name'] = [];
                    $prefillInput['family_member_phone'] = [];
                    foreach ($fam as $fr) {
                        if (!is_array($fr)) continue;
                        $prefillInput['family_relation'][] = (string)($fr['relation'] ?? '');
                        $prefillInput['family_member_name'][] = (string)($fr['name'] ?? '');
                        $prefillInput['family_member_phone'][] = (string)($fr['phone'] ?? '');
                    }
                }
            }

            $amlRaw = trim((string)($row['aml_details_json'] ?? ''));
            if ($amlRaw !== '') {
                $aml = json_decode($amlRaw, true);
                if (is_array($aml)) {
                    foreach ($aml as $k => $v) {
                        if (is_array($v)) continue;
                        if ($v !== '' && $v !== null) $prefillInput[$k] = (string)$v;
                    }
                    if (!empty($aml['income_items']) && is_array($aml['income_items'])) {
                        $prefillInput['income_source_name'] = [];
                        $prefillInput['income_source_amount'] = [];
                        foreach ($aml['income_items'] as $it) {
                            if (!is_array($it)) continue;
                            $prefillInput['income_source_name'][] = (string)($it['name'] ?? '');
                            $prefillInput['income_source_amount'][] = (string)($it['amount'] ?? '');
                        }
                    }
                    if (!empty($aml['expense_items']) && is_array($aml['expense_items'])) {
                        $prefillInput['expense_source_name'] = [];
                        $prefillInput['expense_source_amount'] = [];
                        foreach ($aml['expense_items'] as $it) {
                            if (!is_array($it)) continue;
                            $prefillInput['expense_source_name'][] = (string)($it['name'] ?? '');
                            $prefillInput['expense_source_amount'][] = (string)($it['amount'] ?? '');
                        }
                    }
                }
            }
    } else {
        // At least basic member data prefill to avoid fully blank form
        $prefillInput['full_name'] = (string)($loggedMember['name'] ?? '');
        $prefillInput['member_id'] = (string)($loggedMember['sadasyata_number'] ?? $loggedMember['member_card_no'] ?? $loggedMember['member_id'] ?? '');
        $prefillInput['mobile'] = (string)($loggedMember['phone'] ?? '');
        $prefillInput['email'] = (string)($loggedMember['email'] ?? '');
    }
} catch (Throwable $ignored) {}
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo isEnglish() ? 'Online KYC Form' : 'अनलाइन केवाइसी फारम'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo isEnglish() ? 'Online KYC' : 'अनलाइन केवाइसी'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- v10.4: KYC capture assets (camera/crop/signature/fingerprint) -->
<link rel="stylesheet" href="<?php echo SITE_URL; ?>assets/css/kyc-capture.css?v=10.6">
<?php printNepalAddressJs(); ?>
<script defer src="<?php echo SITE_URL; ?>assets/js/kyc-capture.js?v=10.9"></script>

<!-- KYC Form Section -->
<section class="kyc-form-section section-padding">
    <div class="container">
        <?php if ($success): ?>
        <div class="row justify-content-center mb-4">
          <div class="col-lg-7">
            <div class="form-success-card text-center py-5 px-4 rounded-4 shadow-sm" style="border:2px solid #c8e6c9;">
              <div class="form-success-icon"><i class="fas fa-id-card-alt"></i></div>
              <h3 class="mt-3 fw-bold text-success"><?php
                if (!empty($kycWasUpdate)) {
                    echo isEnglish() ? 'KYC Updated Successfully!' : 'KYC सफलतापूर्वक अपडेट भयो!';
                } else {
                    echo isEnglish() ? 'KYC Application Submitted!' : 'KYC आवेदन सफलतापूर्वक पेश भयो!';
                }
              ?></h3>
              <p class="text-muted mb-3"><?php echo isEnglish() ? 'Our team will verify your KYC and notify you soon.' : 'हाम्रो टोलीले तपाईंको KYC प्रमाणित गरी सूचना दिनेछ।'; ?></p>
              <?php if ($kycTrackingId): ?>
              <div class="form-tracking-box">
                <div class="text-muted small mb-2"><?php echo isEnglish() ? 'Your Tracking ID — save this!' : 'तपाईंको Tracking ID — सुरक्षित राख्नुहोस्!'; ?></div>
                <div class="d-flex align-items-center gap-2 mb-2">
                  <div class="form-tracking-id" id="kycTrkId"><?php echo e($kycTrackingId); ?></div>
                  <button type="button" onclick="copyTrk('kycTrkId',this)" class="btn btn-sm btn-outline-success py-0 px-2" title="Copy" style="font-size:11px;line-height:1.8;"><i class="lucide-icon" aria-hidden="true" data-lucide="copy"></i></button>
                </div>
                <div class="form-tracking-help"><a href="<?php echo e($trackerUrl); ?>" class="text-success text-decoration-none fw-semibold"><?php echo isEnglish() ? 'Open Tracker' : 'ट्र्याकर खोल्नुहोस्'; ?></a> — <?php echo isEnglish() ? 'check status anytime.' : 'स्थिति जुनसुकै बेला हेर्नुहोस्।'; ?></div>
              </div>
              <?php endif; ?>
              <div class="mt-3">
                <a href="<?php echo e($trackerUrl); ?>" class="btn btn-success px-4 me-2"><i class="fas fa-search me-1"></i><?php echo isEnglish() ? 'Track Application' : 'आवेदन ट्र्याक'; ?></a>
                <a href="<?php echo e($kycFollowUpUrl); ?>" class="btn btn-outline-secondary px-4"><i class="fas <?php echo e($kycFollowUpIcon); ?> me-1"></i><?php echo e($kycFollowUpLabel); ?></a>
              </div>
            </div>
          </div>
        </div>
        <?php else: ?>

        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-1"></i><?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <script>document.addEventListener('DOMContentLoaded',function(){var e=document.querySelector('.alert-danger');if(e)e.scrollIntoView({behavior:'smooth',block:'center'});});</script>
        <?php endif; ?>

        <?php if (!$isMemberLoggedIn): ?>
        <div class="row justify-content-center mb-3">
            <div class="col-lg-10">
                <div class="alert alert-info d-flex flex-wrap align-items-center gap-2" style="font-size:.86rem;">
                    <i class="fas fa-circle-info"></i>
                    <span><?php echo isEnglish() ? 'New applicant? Fill the full KYC form below. Already a member? Use Quick KYC and complete remaining details from Member Portal.' : 'नयाँ आवेदक हुनुहुन्छ? तलको पूर्ण KYC भर्नुहोस्। पहिले नै सदस्य हुनुहुन्छ भने Quick KYC प्रयोग गर्नुहोस् र बाँकी Member Portal बाट पूरा गर्नुहोस्।'; ?></span>
                    <button type="button" id="toggleQuickKycBtn" class="btn btn-sm btn-outline-primary ms-auto">
                        <i class="fas fa-bolt me-1"></i><?php echo isEnglish() ? 'Quick KYC (Existing Member)' : 'Quick KYC (पहिलेको सदस्य)'; ?>
                    </button>
                    <a href="<?php echo SITE_URL; ?>member/login.php" class="btn btn-sm btn-outline-success">
                        <i class="fas fa-right-to-bracket me-1"></i><?php echo isEnglish() ? 'Member Login' : 'Member Login'; ?>
                    </a>
                </div>
                <div id="publicQuickKycPanel" class="kyc-form-box mb-3" style="display:<?php echo isset($_POST['public_quick_submit']) ? 'block' : 'none'; ?>;">
                    <form method="POST" class="kyc-form needs-validation" novalidate>
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="public_quick_submit" value="1">
                        <div class="form-section">
                            <h5><i class="fas fa-bolt me-1"></i><?php echo isEnglish() ? 'Quick KYC (Existing Member)' : 'छोटो KYC (पहिलेको सदस्य)'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3"><label class="form-label"><?php echo isEnglish() ? 'Full Name' : 'पूरा नाम'; ?> <span class="text-danger">*</span></label><input type="text" name="full_name" class="form-control" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"></div>
                                <div class="col-md-6 mb-3"><label class="form-label"><?php echo isEnglish() ? 'Member ID' : 'सदस्यता नम्बर'; ?> <span class="text-danger">*</span></label><input type="text" name="member_id" class="form-control" required value="<?php echo htmlspecialchars($_POST['member_id'] ?? ''); ?>"></div>
                                <div class="col-md-6 mb-3"><label class="form-label"><?php echo isEnglish() ? 'Mobile Number' : 'मोबाइल नम्बर'; ?> <span class="text-danger">*</span></label><input type="tel" name="mobile" class="form-control" required placeholder="98XXXXXXXX" value="<?php echo htmlspecialchars($_POST['mobile'] ?? ''); ?>"></div>
                                <div class="col-md-6 mb-3"><label class="form-label"><?php echo isEnglish() ? 'Email' : 'इमेल'; ?> <span class="text-danger">*</span></label><input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"></div>
                                <div class="col-md-6 mb-3"><label class="form-label"><?php echo isEnglish() ? 'National ID Number' : 'National ID नम्बर'; ?> <span class="text-danger">*</span></label><input type="text" name="national_id_number" class="form-control" required value="<?php echo htmlspecialchars($_POST['national_id_number'] ?? ''); ?>"></div>
                            </div>
                        </div>
                        <div class="text-center"><button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save me-1"></i><?php echo isEnglish() ? 'Save Basic KYC' : 'Basic KYC सेभ गर्नुहोस्'; ?></button></div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="row justify-content-center" id="fullKycRow" style="<?php echo (!$isMemberLoggedIn && isset($_POST['public_quick_submit'])) ? 'display:none;' : ''; ?>">
            <div class="col-lg-10">
                <div class="kyc-form-box" data-aos="fade-up">
                    <div class="form-header text-center mb-4">
                        <div class="form-icon"><i class="fas fa-user-check"></i></div>
                        <h3><?php echo isEnglish() ? 'Know Your Customer (KYC) Form' : 'ग्राहक पहिचान (केवाइसी) फारम'; ?></h3>
                        <p><?php echo isEnglish() ? 'Fill out the form below to complete your KYC verification' : 'केवाइसी प्रमाणीकरण पूरा गर्न तलको फारम भर्नुहोस्'; ?></p>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="kyc-form needs-validation" id="fullKymForm" novalidate>
                        <?php echo csrfField(); ?>
                        <div id="kymProgressStrip" class="kym-progress-strip mb-2">
                            <span id="kymStepCounter" class="kym-step-counter"></span>
                            <div class="kym-progress-track"><div id="kymProgressFill" class="kym-progress-fill"></div></div>
                        </div>
                        <div id="kymWizardNav" class="kym-wizard-nav mb-2"></div>
                        <div class="kym-wizard-hint mb-3"><?php echo isEnglish() ? 'Use Save & Next to keep progress while moving between sections.' : 'सेक्सन अनुसार भर्नुहोस्, "Save & Next" ले हालसम्मको data सुरक्षित राख्छ।'; ?></div>

                        <!-- Personal Information -->
                        <div class="form-section" id="kymExtendedSection">
                            <h5><i class="fas fa-user"></i> <?php echo isEnglish() ? 'Personal Information' : 'व्यक्तिगत जानकारी'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Full Name (Nepali)' : 'पूरा नाम (नेपालीमा)'; ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="full_name" class="form-control" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Full Name (English)' : 'पूरा नाम (अंग्रेजीमा)'; ?></label>
                                    <input type="text" name="full_name_en" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Member ID / Membership Number' : 'सदस्यता नम्बर (Member ID)'; ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="member_id" class="form-control" required placeholder="<?php echo isEnglish() ? 'Example: 1234' : 'उदाहरण: १२३४'; ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Date of Birth (BS)' : 'जन्म मिति (वि.सं.)'; ?></label>
                                    <div class="input-group nepali-datepicker-wrapper">
                                        <input type="text" name="dob_bs" class="form-control nepali-datepicker" placeholder="YYYY-MM-DD">
                                        <span class="input-group-text cursor-pointer" onclick="$(this).siblings('.nepali-datepicker').focus();"><i class="fas fa-calendar-alt"></i></span>
                                    </div>
                                </div>
                                <!-- AD date field hidden — BS date मात्र user ले हाल्छन्, AD auto-fill गर्न JS ले गर्छ -->
                                <input type="hidden" name="dob_ad" id="dob_ad_picker" value="">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Gender' : 'लिङ्ग'; ?></label>
                                    <select name="gender" class="form-select">
                                        <option value=""><?php echo isEnglish() ? 'Select' : 'छान्नुहोस्'; ?></option>
                                        <option value="male"><?php echo isEnglish() ? 'Male' : 'पुरुष'; ?></option>
                                        <option value="female"><?php echo isEnglish() ? 'Female' : 'महिला'; ?></option>
                                        <option value="other"><?php echo isEnglish() ? 'Other' : 'अन्य'; ?></option>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Marital Status' : 'वैवाहिक स्थिति'; ?></label>
                                    <select name="marital_status" class="form-select">
                                        <option value=""><?php echo isEnglish() ? 'Select' : 'छान्नुहोस्'; ?></option>
                                        <option value="single"><?php echo isEnglish() ? 'Single' : 'अविवाहित'; ?></option>
                                        <option value="married"><?php echo isEnglish() ? 'Married' : 'विवाहित'; ?></option>
                                        <option value="divorced"><?php echo isEnglish() ? 'Divorced' : 'सम्बन्धविच्छेद'; ?></option>
                                        <option value="widow"><?php echo isEnglish() ? 'Widow/Widower' : 'विधवा/विधुर'; ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div class="form-section">
                            <h5><i class="fas fa-phone"></i> <?php echo isEnglish() ? 'Contact Information' : 'सम्पर्क जानकारी'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Mobile Number' : 'मोबाइल नम्बर'; ?> <span class="text-danger">*</span></label>
                                    <input type="tel" name="mobile" class="form-control" required placeholder="98XXXXXXXX">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">
                                        <?php echo isEnglish() ? 'Email Address' : 'इमेल ठेगाना'; ?>
                                        <span class="text-danger">*</span>
                                    </label>
                                    <input type="email" name="email" class="form-control" required
                                           placeholder="akashpame@gmail.com"
                                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                </div>
                                <!-- v10.4: Structured Permanent Address (Province → District → Municipality → Ward → Tole) -->
                                <div class="col-12 mb-3">
                                    <label class="form-label fw-semibold"><i class="fas fa-map-marker-alt text-success me-1"></i><?php echo isEnglish() ? 'Permanent Address' : 'स्थायी ठेगाना'; ?> <span class="text-danger">*</span></label>
                                    <div class="kyc-addr-grid" data-kyc-address="permanent">
                                        <div class="kyc-addr-cell"><label>प्रदेश</label>
                                            <select name="permanent_province" class="form-select form-select-sm" required data-testid="kyc-permanent-province-select">
                                                <option value="">— छान्नुहोस् —</option>
                                            </select>
                                        </div>
                                        <div class="kyc-addr-cell"><label>जिल्ला</label>
                                            <select name="permanent_district" class="form-select form-select-sm" required data-testid="kyc-permanent-district-select">
                                                <option value="">— छान्नुहोस् —</option>
                                            </select>
                                        </div>
                                        <div class="kyc-addr-cell"><label>नगर/गाउँपालिका</label>
                                            <select name="permanent_municipality" class="form-select form-select-sm" required data-testid="kyc-permanent-municipality-select">
                                                <option value="">— छान्नुहोस् —</option>
                                            </select>
                                        </div>
                                        <div class="kyc-addr-cell"><label>वडा नं.</label>
                                            <select name="permanent_ward" class="form-select form-select-sm" required data-testid="kyc-permanent-ward-select">
                                                <option value="">— वडा —</option>
                                            </select>
                                        </div>
                                        <div class="kyc-addr-cell" style="grid-column:1/-1;">
                                            <label>टोल / गाउँ (Tole)</label>
                                            <input type="text" name="permanent_tole" class="form-control form-control-sm" placeholder="जस्तै: नयाँ बानेश्वर" data-testid="kyc-permanent-tole-input">
                                        </div>
                                    </div>
                                </div>

                                <!-- "Same as permanent" toggle -->
                                <div class="col-12">
                                    <label class="kyc-same-toggle">
                                        <input type="checkbox" name="same_as_permanent" id="kycSameAddress" value="1" data-testid="kyc-same-as-permanent-checkbox">
                                        <span><i class="lucide-icon text-success me-1" aria-hidden="true" data-lucide="copy"></i><strong>अस्थायी ठेगाना स्थायी ठेगाना जस्तै हो</strong> (Same as Permanent)</span>
                                    </label>
                                </div>

                                <!-- v10.4: Structured Temporary Address -->
                                <div class="col-12 mb-3" id="kycTemporaryAddressWrap">
                                    <label class="form-label fw-semibold"><i class="fas fa-location-dot text-primary me-1"></i><?php echo isEnglish() ? 'Temporary Address' : 'अस्थायी ठेगाना'; ?></label>
                                    <div class="small text-muted mb-2"><?php echo isEnglish() ? 'Fill this only if temporary address is different.' : 'अस्थायी ठेगाना फरक भएमा मात्र भर्नुहोस्।'; ?></div>
                                    <div class="kyc-addr-grid" data-kyc-address="temporary">
                                        <div class="kyc-addr-cell"><label>प्रदेश</label>
                                            <select name="temporary_province" class="form-select form-select-sm" data-testid="kyc-temporary-province-select">
                                                <option value="">— छान्नुहोस् —</option>
                                            </select>
                                        </div>
                                        <div class="kyc-addr-cell"><label>जिल्ला</label>
                                            <select name="temporary_district" class="form-select form-select-sm" data-testid="kyc-temporary-district-select">
                                                <option value="">— छान्नुहोस् —</option>
                                            </select>
                                        </div>
                                        <div class="kyc-addr-cell"><label>नगर/गाउँपालिका</label>
                                            <select name="temporary_municipality" class="form-select form-select-sm" data-testid="kyc-temporary-municipality-select">
                                                <option value="">— छान्नुहोस् —</option>
                                            </select>
                                        </div>
                                        <div class="kyc-addr-cell"><label>वडा नं.</label>
                                            <select name="temporary_ward" class="form-select form-select-sm" data-testid="kyc-temporary-ward-select">
                                                <option value="">— वडा —</option>
                                            </select>
                                        </div>
                                        <div class="kyc-addr-cell" style="grid-column:1/-1;">
                                            <label>टोल / गाउँ (Tole)</label>
                                            <input type="text" name="temporary_tole" class="form-control form-control-sm">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Identity Information -->
                        <div class="form-section">
                            <h5><i class="fas fa-id-card"></i> <?php echo isEnglish() ? 'Identity Information' : 'परिचय पत्र जानकारी'; ?></h5>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Citizenship Number' : 'नागरिकता नम्बर'; ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="citizenship_no" class="form-control" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Issued Date (BS)' : 'जारी मिति (वि.सं.)'; ?></label>
                                    <div class="input-group nepali-datepicker-wrapper">
                                        <input type="text" name="citizenship_issued_date" class="form-control nepali-datepicker" placeholder="YYYY-MM-DD">
                                        <span class="input-group-text cursor-pointer" onclick="$(this).siblings('.nepali-datepicker').focus();"><i class="fas fa-calendar-alt"></i></span>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Issue District' : 'जारी जिल्ला'; ?></label>
                                    <select name="citizenship_issued_place" class="form-select">
                                        <option value=""><?php echo isEnglish() ? 'Select district' : 'जिल्ला छान्नुहोस्'; ?></option>
                                        <?php foreach ($nepalDistricts as $district): ?>
                                            <option value="<?php echo htmlspecialchars($district); ?>"><?php echo htmlspecialchars($district); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'National ID Number' : 'National ID नम्बर'; ?></label>
                                    <input type="text" name="national_id_number" class="form-control" placeholder="<?php echo isEnglish() ? 'Enter NID number' : 'NID नम्बर लेख्नुहोस्'; ?>">
                                    <small class="text-muted"><?php echo isEnglish() ? 'Nepal: enter 14-digit NID. It should not be exactly same as citizenship number.' : 'नेपाल: १४ अङ्कको NID लेख्नुहोस्। नागरिकता नम्बरसँग ठ्याक्कै उस्तै हुनु हुँदैन।'; ?></small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'KYC Risk Category' : 'KYC जोखिम श्रेणी'; ?> <span class="text-danger">*</span></label>
                                    <select name="risk_category" class="form-select" required>
                                        <option value=""><?php echo isEnglish() ? 'Select risk category' : 'जोखिम श्रेणी छान्नुहोस्'; ?></option>
                                        <option value="low"><?php echo isEnglish() ? 'Low Risk (Auto review after 3 years)' : 'Low Risk (३ वर्षपछि auto review)'; ?></option>
                                        <option value="medium"><?php echo isEnglish() ? 'Medium Risk (Auto review after 2 years)' : 'Medium Risk (२ वर्षपछि auto review)'; ?></option>
                                        <option value="high"><?php echo isEnglish() ? 'High Risk (Auto review after 1 year)' : 'High Risk (१ वर्षपछि auto review)'; ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Family Information -->
                        <div class="form-section">
                            <h5><i class="fas fa-users"></i> <?php echo isEnglish() ? 'Family Information' : 'पारिवारिक जानकारी'; ?></h5>
                            <div class="small text-muted mb-2"><?php echo isEnglish() ? 'Choose relation, add family member, and keep list in table.' : 'सम्बन्ध छानेर सदस्य थप्नुहोस्, सूची तालिकामा राख्नुहोस्।'; ?></div>
                            <div class="row g-2 align-items-end" id="familyRowBuilder">
                                <div class="col-md-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Relation' : 'सम्बन्ध'; ?></label>
                                    <select id="familyRelation" class="form-select form-select-sm">
                                        <option value=""><?php echo isEnglish() ? 'Select' : 'छान्नुहोस्'; ?></option>
                                        <option value="father"><?php echo isEnglish() ? 'Father' : 'बुबा'; ?></option>
                                        <option value="mother"><?php echo isEnglish() ? 'Mother' : 'आमा'; ?></option>
                                        <option value="grandfather"><?php echo isEnglish() ? 'Grandfather' : 'हजुरबुबा'; ?></option>
                                        <option value="spouse"><?php echo isEnglish() ? 'Spouse' : 'पति/पत्नी'; ?></option>
                                        <option value="son"><?php echo isEnglish() ? 'Son' : 'छोरा'; ?></option>
                                        <option value="daughter"><?php echo isEnglish() ? 'Daughter' : 'छोरी'; ?></option>
                                        <option value="guardian"><?php echo isEnglish() ? 'Guardian' : 'अभिभावक'; ?></option>
                                        <option value="other"><?php echo isEnglish() ? 'Other' : 'अन्य'; ?></option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo isEnglish() ? 'Name' : 'नाम'; ?></label>
                                    <input type="text" id="familyMemberName" class="form-control form-control-sm" placeholder="<?php echo isEnglish() ? 'Member name' : 'सदस्यको नाम'; ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Phone (optional)' : 'फोन (ऐच्छिक)'; ?></label>
                                    <input type="text" id="familyMemberPhone" class="form-control form-control-sm" placeholder="98XXXXXXXX">
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-sm btn-coop w-100" id="addFamilyMemberBtn">
                                        <i class="fas fa-plus me-1"></i><?php echo isEnglish() ? 'Add' : 'थप्नुहोस्'; ?>
                                    </button>
                                </div>
                            </div>
                            <div class="table-responsive mt-3">
                                <table class="table table-sm table-bordered align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:28%;"><?php echo isEnglish() ? 'Relation' : 'सम्बन्ध'; ?></th>
                                            <th><?php echo isEnglish() ? 'Name' : 'नाम'; ?></th>
                                            <th style="width:24%;"><?php echo isEnglish() ? 'Phone' : 'फोन'; ?></th>
                                            <th style="width:80px;"><?php echo isEnglish() ? 'Action' : 'कार्य'; ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="familyMembersTableBody">
                                        <tr id="familyMembersEmptyRow">
                                            <td colspan="4" class="text-center text-muted"><?php echo isEnglish() ? 'No family member added yet.' : 'अहिलेसम्म परिवार सदस्य थपिएको छैन।'; ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div id="familyMembersHiddenInputs"></div>
                            <input type="hidden" name="father_name" id="legacyFatherName">
                            <input type="hidden" name="mother_name" id="legacyMotherName">
                            <input type="hidden" name="grandfather_name" id="legacyGrandfatherName">
                            <input type="hidden" name="spouse_name" id="legacySpouseName">
                        </div>

                        <!-- Occupation Information -->
                        <div class="form-section">
                            <h5><i class="fas fa-briefcase"></i> <?php echo isEnglish() ? 'Occupation Information' : 'पेशागत जानकारी'; ?></h5>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Occupation' : 'पेशा'; ?></label>
                                    <select name="occupation" class="form-select">
                                        <option value=""><?php echo isEnglish() ? 'Select' : 'छान्नुहोस्'; ?></option>
                                        <option value="government"><?php echo isEnglish() ? 'Government Job' : 'सरकारी नोकरी'; ?></option>
                                        <option value="private"><?php echo isEnglish() ? 'Private Job' : 'निजी नोकरी'; ?></option>
                                        <option value="business"><?php echo isEnglish() ? 'Business' : 'व्यापार/व्यवसाय'; ?></option>
                                        <option value="agriculture"><?php echo isEnglish() ? 'Agriculture' : 'कृषि'; ?></option>
                                        <option value="student"><?php echo isEnglish() ? 'Student' : 'विद्यार्थी'; ?></option>
                                        <option value="housewife"><?php echo isEnglish() ? 'Housewife' : 'गृहिणी'; ?></option>
                                        <option value="retired"><?php echo isEnglish() ? 'Retired' : 'सेवानिवृत्त'; ?></option>
                                        <option value="foreign"><?php echo isEnglish() ? 'Foreign Employment' : 'वैदेशिक रोजगार'; ?></option>
                                        <option value="other"><?php echo isEnglish() ? 'Other' : 'अन्य'; ?></option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Organization Name' : 'संस्थाको नाम'; ?></label>
                                    <input type="text" name="organization_name" class="form-control">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Monthly Income' : 'मासिक आय'; ?></label>
                                    <select name="monthly_income" class="form-select">
                                        <option value=""><?php echo isEnglish() ? 'Select' : 'छान्नुहोस्'; ?></option>
                                        <option value="below_20000"><?php echo isEnglish() ? 'Below Rs. 20,000' : 'रु. २०,००० भन्दा कम'; ?></option>
                                        <option value="20000_50000"><?php echo isEnglish() ? 'Rs. 20,000 - 50,000' : 'रु. २०,००० - ५०,०००'; ?></option>
                                        <option value="50000_100000"><?php echo isEnglish() ? 'Rs. 50,000 - 1,00,000' : 'रु. ५०,००० - १,००,०००'; ?></option>
                                        <option value="above_100000"><?php echo isEnglish() ? 'Above Rs. 1,00,000' : 'रु. १,००,००० भन्दा माथि'; ?></option>
                                    </select>
                                </div>
                                <div class="col-12 business-only-field"><div class="kym-subsec-title">Business Details</div></div>
                                <div class="col-md-4 mb-3 business-only-field"><label class="form-label">पेशा/व्यवसाय स्थान</label><input type="text" name="occupation_location" class="form-control"></div>
                                <div class="col-md-4 mb-3 business-only-field"><label class="form-label">पेशा/व्यवसायको नाम</label><input type="text" name="occupation_business_name" class="form-control"></div>
                                <div class="col-md-4 mb-3 business-only-field"><label class="form-label">Business PAN नं.</label><input type="text" name="business_pan_no" class="form-control"></div>
                                <div class="col-md-4 mb-3 business-only-field"><label class="form-label">Business दर्ता प्रकार</label>
                                    <select name="business_registration_type" class="form-select">
                                        <option value="">छान्नुहोस्</option>
                                        <option value="sole_proprietor">एकल स्वामित्व</option>
                                        <option value="partnership">साझेदारी</option>
                                        <option value="private_limited">Private Limited</option>
                                        <option value="cooperative">सहकारी</option>
                                        <option value="industry_firm">उद्योग/फर्म</option>
                                        <option value="other">अन्य</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3 business-only-field"><label class="form-label">Business दर्ता नं.</label><input type="text" name="business_registration_no" class="form-control"></div>
                                <div class="col-md-4 mb-3 business-only-field"><label class="form-label">दर्ता निकाय</label>
                                    <select name="business_registration_office" class="form-select">
                                        <option value="">छान्नुहोस्</option><option value="घरेलु">घरेलु</option><option value="company_registrar">Company Registrar</option><option value="rajaswo_karyalaya">राजस्व कार्यालय</option><option value="nagarpalika">नगरपालिका</option><option value="gaupalika">गाउँपालिका</option><option value="other">अन्य</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3 business-only-field"><label class="form-label">Business दर्ता मिति (BS)</label><input type="text" name="business_registration_date_bs" class="form-control nepali-datepicker" placeholder="YYYY-MM-DD"></div>
                                <div class="col-md-4 mb-3 business-only-field"><label class="form-label">व्यवसायको प्रकृति</label><input type="text" name="business_nature" class="form-control"></div>
                                <div class="col-md-4 mb-3 business-only-field"><label class="form-label">अनुमानित वार्षिक आय</label><input type="text" name="estimated_annual_income" class="form-control"></div>
                            </div>
                        </div>

                        <!-- AML / CFT Extended Information -->
                        <div class="form-section">
                            <h5><i class="fas fa-shield-halved"></i> <?php echo isEnglish() ? 'AML/CFT Additional Details' : 'AML/CFT थप विवरण'; ?></h5>
                            <div class="small text-muted mb-2"><?php echo isEnglish() ? 'Fill additional KYM details if available (as per cooperative format).' : 'सहकारीको KYM ढाँचाअनुसार उपलब्ध थप विवरण भर्नुहोस्।'; ?></div>
                            <div class="row">
                                <div class="col-12"><div class="kym-part-box"><div class="kym-subsec-title">क. विस्तारित व्यक्तिगत विवरण</div></div></div>
                                <div class="col-md-4 mb-3"><label class="form-label">Passport नं.</label><input type="text" name="passport_no" class="form-control"></div>
                                <div class="col-md-4 mb-3"><label class="form-label">PAN नं.</label><input type="text" name="pan_no" class="form-control"></div>
                                <div class="col-md-4 mb-3"><label class="form-label">Driving License नं.</label><input type="text" name="driving_license_no" class="form-control"></div>
                                <div class="col-md-4 mb-3"><label class="form-label">शैक्षिक योग्यता</label>
                                    <select name="education_qualification" class="form-select">
                                        <option value="">छान्नुहोस्</option><option>निरक्षर</option><option>सामान्य लेखपढ</option><option>SEE</option><option>+2</option><option>स्नातक</option><option>स्नातकोत्तर</option><option>MPhil/PhD</option><option value="other">अन्य</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3"><label class="form-label">धर्म</label>
                                    <select name="religion" class="form-select">
                                        <option value="">छान्नुहोस्</option><option>हिन्दु</option><option>बौद्ध</option><option>किराँत</option><option>इस्लाम</option><option>क्रिश्चियन</option><option>अन्य</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3"><label class="form-label">जात</label>
                                    <select name="caste" class="form-select">
                                        <option value="">छान्नुहोस्</option><option>ब्राह्मण</option><option>क्षेत्री</option><option>नेवार</option><option>जनजाति</option><option>दलित</option><option>मधेसी</option><option>थारु</option><option>मुस्लिम</option><option>अन्य</option>
                                    </select>
                                </div>

                                <div class="col-12"><div class="kym-subsec-divider"></div></div>
                                <div class="col-12"><div class="kym-subsec-title">ख. बसोबास/सदस्यता सम्बन्धी विवरण</div></div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">राजनीतिक रूपमा आबद्ध (PEP)?</label>
                                    <select name="politically_exposed" class="form-select">
                                        <option value="">छान्नुहोस्</option>
                                        <option value="yes">हो</option>
                                        <option value="no">होइन</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">विगतमा अपराधमा दोषी ठहर?</label>
                                    <select name="past_crime_declared" class="form-select">
                                        <option value="">छान्नुहोस्</option>
                                        <option value="yes">हो</option>
                                        <option value="no">होइन</option>
                                    </select>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">भाडामा बस्ने हो?</label>
                                    <select name="is_rented" id="isRentedSelect" class="form-select">
                                        <option value="">छान्नुहोस्</option><option value="yes">हो</option><option value="no">होइन</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3 rented-only-field"><label class="form-label">घरधनीको नाम</label><input type="text" name="landlord_name" class="form-control"></div>
                                <div class="col-md-4 mb-3 rented-only-field"><label class="form-label">घरधनी सम्पर्क नं.</label><input type="text" name="landlord_contact" class="form-control"></div>
                                <div class="col-md-4 mb-3"><label class="form-label">मतदाता परिचयपत्र नं.</label><input type="text" name="voter_id_card_no" class="form-control"></div>
                                <div class="col-md-6 mb-3"><label class="form-label">मतदान स्थल</label><input type="text" name="polling_station" class="form-control"></div>
                                <div class="col-md-6 mb-3"><label class="form-label">सदस्यता लिने मुख्य उद्देश्य</label><input type="text" name="member_purpose" class="form-control"></div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">आफू अन्य सहकारीको सदस्य?</label>
                                    <select name="self_other_coop_member" id="selfOtherCoopSelect" class="form-select">
                                        <option value="">छान्नुहोस्</option>
                                        <option value="yes">हो</option>
                                        <option value="no">होइन</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3 self-coop-only-field"><label class="form-label">अन्य सहकारी विवरण</label><input type="text" name="self_other_coop_details" class="form-control" placeholder="संस्था/सदस्यता नं./भूमिका"></div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">परिवारका सदस्य यसै संस्थामा?</label>
                                    <select name="family_same_coop_member" id="familySameCoopSelect" class="form-select">
                                        <option value="">छान्नुहोस्</option>
                                        <option value="yes">हो</option>
                                        <option value="no">होइन</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3 family-coop-only-field">
                                    <label class="form-label">परिवार सदस्य (सूचीबाट)</label>
                                    <select id="familySameMemberSelect" name="family_same_member_name" class="form-select">
                                        <option value="">परिवार सूचीबाट छान्नुहोस्</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3 family-coop-only-field"><label class="form-label">Member ID</label><input type="text" name="family_same_member_id" class="form-control" placeholder="जस्तै: 001001002"></div>
                                <div class="col-md-4 mb-3 family-coop-only-field"><label class="form-label">परिवार सदस्य विवरण</label><input type="text" name="family_same_coop_details" class="form-control" placeholder="नाम/नाता/सदस्यता नं."></div>

                                <div class="col-12"><div class="kym-subsec-divider"></div></div>
                                <div class="col-12"><div class="kym-subsec-title">ग. वित्तीय कारोबार</div></div>
                                <div class="col-md-3 mb-3"><label class="form-label">वार्षिक डेबिट/क्रेडिट अनुमान</label><input type="text" name="annual_debit_credit_estimate" class="form-control"></div>
                                <div class="col-md-3 mb-3"><label class="form-label">वार्षिक कारोबार संख्या</label><input type="text" name="annual_turnover_numbers" class="form-control"></div>
                                <div class="col-md-3 mb-3"><label class="form-label">वार्षिक जम्मा अनुमान</label><input type="text" name="annual_deposit_estimate" class="form-control"></div>
                                <div class="col-md-3 mb-3"><label class="form-label">संस्थासँग ऋणधन अनुमान</label><input type="text" name="institution_debt_estimate" class="form-control"></div>
                                <div class="col-md-6 mb-3"><label class="form-label">वार्षिक पारिवारिक आम्दानी</label><input type="text" name="annual_family_income" class="form-control"></div>
                                <div class="col-md-6 mb-3"><label class="form-label">सम्पत्ति/Net Worth विवरण</label><input type="text" name="net_worth_details" class="form-control"></div>

                                <div class="col-12"><div class="kym-subsec-divider"></div></div>
                                <div class="col-12"><div class="kym-subsec-title">घ. नजिकको व्यक्ति / हकवाला विवरण</div></div>
                                <div class="col-md-6 mb-3"><label class="form-label">नजिकको व्यक्तिको नाम</label><input type="text" name="nearest_person_name" class="form-control"></div>
                                <div class="col-md-6 mb-3"><label class="form-label">नाता</label><input type="text" name="nearest_person_relation" class="form-control"></div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">हकवालाको नाम</label>
                                    <select id="nomineeFromFamily" class="form-select mb-1">
                                        <option value="">परिवार सूचीबाट छान्नुहोस्</option>
                                    </select>
                                    <input type="text" name="nominee_name" id="nomineeNameInput" class="form-control" placeholder="manual नाम पनि लेख्न सकिन्छ">
                                </div>
                                <div class="col-md-4 mb-3"><label class="form-label">हकवाला जन्म मिति</label><input type="text" name="nominee_dob" class="form-control nepali-datepicker" placeholder="YYYY-MM-DD"></div>
                                <div class="col-md-4 mb-3"><label class="form-label">हकवाला नागरिकता नं.</label><input type="text" name="nominee_citizenship_no" class="form-control"></div>
                                <div class="col-md-4 mb-3"><label class="form-label">हकवालासँग नाता</label>
                                    <select name="nominee_relation" class="form-select">
                                        <option value="">छान्नुहोस्</option><option>पति/पत्नी</option><option>छोरा</option><option>छोरी</option><option>बुबा</option><option>आमा</option><option>दाजु/भाइ</option><option>दिदी/बहिनी</option><option>अभिभावक</option><option>अन्य</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3"><label class="form-label">हकवाला जारी जिल्ला</label>
                                    <select name="nominee_issue_district" class="form-select">
                                        <option value="">जिल्ला छान्नुहोस्</option>
                                        <?php foreach ($nepalDistricts as $district): ?>
                                            <option value="<?php echo htmlspecialchars($district); ?>"><?php echo htmlspecialchars($district); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3"><label class="form-label">हकवाला जारी मिति</label><input type="text" name="nominee_issue_date" class="form-control nepali-datepicker" placeholder="YYYY-MM-DD"></div>
                                <div class="col-md-6 mb-3"><label class="form-label">हकवाला स्थायी ठेगाना</label><input type="text" name="nominee_permanent_address" class="form-control"></div>
                                <div class="col-md-6 mb-3"><label class="form-label">हकवाला अस्थायी ठेगाना</label><input type="text" name="nominee_temporary_address" class="form-control"></div>

                                <div class="col-12"><div class="kym-subsec-divider"></div></div>
                                <div class="col-12"><div class="kym-subsec-title">ङ. नक्सा र आय/खर्च विवरण</div></div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">घर/ठाउँको नक्सा/देशान्तर-अक्षांश</label>
                                    <input type="text" name="longitude_latitude" id="longitudeLatitudeInput" class="form-control" placeholder="Map बाट location छान्नुहोस्" readonly>
                                    <small class="text-muted d-block mt-1">नक्सामा click गरेर location छान्नुहोस्।</small>
                                </div>
                                <div class="col-md-6 mb-3"><label class="form-label">अन्य संलग्न कागजात</label><input type="text" name="other_attached_docs" class="form-control" placeholder="उदा: विवाह दर्ता, बसाइँसराइ, आदि"></div>
                                <div class="col-12 mb-2">
                                    <div class="d-flex gap-2 flex-wrap align-items-center">
                                        <button type="button" class="btn btn-sm btn-outline-success" id="btnUseCurrentLocation">
                                            <i class="fas fa-location-crosshairs me-1"></i>हालको Location प्रयोग गर्नुहोस्
                                        </button>
                                        <span class="small text-muted" id="kycMapHint">नक्सामा marker राख्न click गर्नुहोस्।</span>
                                    </div>
                                    <div id="kycMapPicker" style="height:280px;border:1px solid #d1d5db;border-radius:10px;margin-top:8px;background:#f8fafc;"></div>
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Map बाट प्राप्त ठेगाना</label>
                                    <input type="text" name="map_resolved_address" id="mapResolvedAddressInput" class="form-control" placeholder="Location छानेपछि यहाँ ठेगाना आउँछ" readonly>
                                </div>

                                <div class="col-12"><hr></div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">मासिक आय स्रोतहरू (Multiple + Total)</label>
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-6"><input type="text" id="incomeSourceName" class="form-control form-control-sm" placeholder="उदा: तलब (मासिक)"></div>
                                        <div class="col-md-4"><input type="number" min="0" step="0.01" id="incomeSourceAmount" class="form-control form-control-sm" placeholder="मासिक रकम"></div>
                                        <div class="col-md-2"><button type="button" class="btn btn-sm btn-coop w-100" id="addIncomeSourceBtn"><i class="fas fa-plus me-1"></i>थप</button></div>
                                    </div>
                                    <div class="table-responsive mt-2">
                                        <table class="table table-sm table-bordered mb-0">
                                            <thead class="table-light"><tr><th>मासिक आय स्रोत</th><th style="width:28%;">मासिक रकम</th><th style="width:80px;">कार्य</th></tr></thead>
                                            <tbody id="incomeSourcesTableBody"><tr id="incomeSourcesEmptyRow"><td colspan="3" class="text-center text-muted">आय स्रोत थपिएको छैन।</td></tr></tbody>
                                        </table>
                                    </div>
                                    <div id="incomeSourcesHiddenInputs"></div>
                                </div>

                                <div class="col-12 mt-2">
                                    <label class="form-label fw-semibold">मासिक खर्च स्रोतहरू (Multiple + Total)</label>
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-6"><input type="text" id="expenseSourceName" class="form-control form-control-sm" placeholder="उदा: घर खर्च (मासिक)"></div>
                                        <div class="col-md-4"><input type="number" min="0" step="0.01" id="expenseSourceAmount" class="form-control form-control-sm" placeholder="मासिक रकम"></div>
                                        <div class="col-md-2"><button type="button" class="btn btn-sm btn-coop w-100" id="addExpenseSourceBtn"><i class="fas fa-plus me-1"></i>थप</button></div>
                                    </div>
                                    <div class="table-responsive mt-2">
                                        <table class="table table-sm table-bordered mb-0">
                                            <thead class="table-light"><tr><th>मासिक खर्च स्रोत</th><th style="width:28%;">मासिक रकम</th><th style="width:80px;">कार्य</th></tr></thead>
                                            <tbody id="expenseSourcesTableBody"><tr id="expenseSourcesEmptyRow"><td colspan="3" class="text-center text-muted">खर्च स्रोत थपिएको छैन।</td></tr></tbody>
                                        </table>
                                    </div>
                                    <div id="expenseSourcesHiddenInputs"></div>
                                </div>

                                <div class="col-md-4 mt-3">
                                    <label class="form-label fw-bold">मासिक जम्मा आय</label>
                                    <input type="text" class="form-control form-control-sm bg-light" id="incomeTotalDisplay" readonly value="0.00">
                                </div>
                                <div class="col-md-4 mt-3">
                                    <label class="form-label fw-bold">मासिक जम्मा खर्च</label>
                                    <input type="text" class="form-control form-control-sm bg-light" id="expenseTotalDisplay" readonly value="0.00">
                                </div>
                                <div class="col-md-4 mt-3">
                                    <label class="form-label fw-bold">अन्तर (मासिक आय - मासिक खर्च)</label>
                                    <input type="text" class="form-control form-control-sm bg-light" id="netSavingDisplay" readonly value="0.00">
                                </div>
                            </div>
                        </div>

                        <!-- Account Information -->
                        <div class="form-section">
                            <h5><i class="fas fa-university"></i> <?php echo isEnglish() ? 'Account Information' : 'खाता जानकारी'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Account Type' : 'खाताको प्रकार'; ?></label>
                                    <select name="account_type" class="form-select">
                                        <option value=""><?php echo isEnglish() ? 'Select' : 'छान्नुहोस्'; ?></option>
                                        <option value="saving"><?php echo isEnglish() ? 'Saving Account' : 'बचत खाता'; ?></option>
                                        <option value="current"><?php echo isEnglish() ? 'Current Account' : 'चल्ती खाता'; ?></option>
                                        <option value="fixed"><?php echo isEnglish() ? 'Fixed Deposit' : 'मुद्दती खाता'; ?></option>
                                        <option value="recurring"><?php echo isEnglish() ? 'Recurring Deposit' : 'आवधिक बचत'; ?></option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Preferred Branch' : 'मनपर्ने शाखा'; ?></label>
                                    <select name="branch" class="form-select">
                                        <option value=""><?php echo isEnglish() ? 'Select' : 'छान्नुहोस्'; ?></option>
                                        <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo $branch['name']; ?>"><?php echo $branch['name']; ?></option>
                                        <?php endforeach; ?>
                                        <option value="main"><?php echo isEnglish() ? 'Main Office' : 'प्रधान कार्यालय'; ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- v10.4: Document Capture (Camera + Crop + Signature + Fingerprint) -->
                        <div class="form-section">
                            <h5><i class="fas fa-camera"></i> <?php echo isEnglish() ? 'Documents & Biometrics' : 'कागजात र बायोमेट्रिक'; ?></h5>
                            <div class="alert alert-info py-2" style="font-size:.82rem;">
                                <i class="fas fa-info-circle me-1"></i>
                                मोबाइलमा क्यामेरा खुल्छ — फोटो खिचेर <strong>Zoom / Crop / Rotate</strong> गरेर मात्र अपलोड हुन्छ।
                                हस्ताक्षर तल औंला वा कलमले गर्न सकिन्छ। औंठा छाप स्पष्ट देखिने गरी खिच्नुहोस्।
                            </div>
                            <div class="kyc-doc-compact">
                                <div class="kyc-doc-head">
                                    <span><i class="fas fa-layer-group me-1"></i><?php echo isEnglish() ? 'Compact Document Mode' : 'कम्प्याक्ट डकुमेन्ट मोड'; ?></span>
                                    <small><?php echo isEnglish() ? 'Open one item at a time' : 'एकपटकमा एकवटा मात्र खोल्नुहोस्'; ?></small>
                                </div>

                                <details class="kyc-doc-item" open>
                                    <summary><i class="fas fa-image me-1"></i><?php echo isEnglish() ? 'Passport Photo' : 'पासपोर्ट साइज फोटो'; ?> <span class="req">*</span></summary>
                                    <div class="kyc-doc-body">
                                        <div class="kyc-cap-field" data-kyc-cap="passport" data-required>
                                            <span class="kyc-cap-label"><?php echo isEnglish() ? 'Passport Photo' : 'पासपोर्ट साइज फोटो'; ?> <span class="req">*</span></span>
                                            <div class="small mb-2" style="color:#475569;">
                                                <?php echo isEnglish()
                                                    ? 'Face straight, clear light, no sunglasses. Both eyes and both ears should be visible.'
                                                    : 'अनुहार सिधा, राम्रो उज्यालो, काला चस्मा नलगाउनुहोस्। दुवै आँखा र दुवै कान स्पष्ट देखिनुपर्छ।'; ?>
                                            </div>
                                            <label class="form-check" style="margin-bottom:8px;">
                                                <input class="form-check-input" type="checkbox" name="passport_face_confirm" value="1">
                                                <span class="form-check-label" style="font-size:.82rem;">
                                                    <?php echo isEnglish()
                                                        ? 'I confirm that both eyes and both ears are clearly visible.'
                                                        : 'म पुष्टि गर्छु कि दुवै आँखा र दुवै कान स्पष्ट देखिएका छन्।'; ?>
                                                </span>
                                            </label>
                                            <input type="hidden" name="photo_quality_score" value="">
                                            <div class="small text-muted mb-1">
                                                <?php echo isEnglish()
                                                    ? 'Photo quality score is advisory only. 70+ is recommended.'
                                                    : 'फोटो गुणस्तर स्कोर सल्लाहमूलक मात्र हो। ७०+ सिफारिस गरिन्छ।'; ?>
                                            </div>
                                            <input type="hidden" name="photo">
                                        </div>
                                    </div>
                                </details>

                                <details class="kyc-doc-item">
                                    <summary><i class="fas fa-id-card me-1"></i><?php echo isEnglish() ? 'Citizenship — Front' : 'नागरिकता अगाडि'; ?> <span class="req">*</span></summary>
                                    <div class="kyc-doc-body">
                                        <div class="kyc-cap-field" data-kyc-cap="citizen_front" data-required>
                                            <span class="kyc-cap-label"><?php echo isEnglish() ? 'Citizenship — Front' : 'नागरिकता अगाडि'; ?> <span class="req">*</span></span>
                                            <input type="hidden" name="citizenship_front">
                                        </div>
                                    </div>
                                </details>

                                <details class="kyc-doc-item">
                                    <summary><i class="fas fa-id-card-clip me-1"></i><?php echo isEnglish() ? 'Citizenship — Back' : 'नागरिकता पछाडि'; ?> <span class="req">*</span></summary>
                                    <div class="kyc-doc-body">
                                        <div class="kyc-cap-field" data-kyc-cap="citizen_back" data-required>
                                            <span class="kyc-cap-label"><?php echo isEnglish() ? 'Citizenship — Back' : 'नागरिकता पछाडि'; ?> <span class="req">*</span></span>
                                            <input type="hidden" name="citizenship_back">
                                        </div>
                                    </div>
                                </details>

                                <details class="kyc-doc-item">
                                    <summary><i class="fas fa-address-card me-1"></i><?php echo isEnglish() ? 'National ID Card' : 'National ID कार्ड'; ?></summary>
                                    <div class="kyc-doc-body">
                                        <div class="kyc-cap-field" data-kyc-cap="national_id">
                                            <span class="kyc-cap-label"><?php echo isEnglish() ? 'National ID Card (JPG only — camera or gallery)' : 'National ID कार्ड (JPG मात्र — क्यामेरा वा Gallery)'; ?></span>
                                            <input type="hidden" name="national_id_card">
                                        </div>
                                    </div>
                                </details>

                                <details class="kyc-doc-item">
                                    <summary><i class="fas fa-signature me-1"></i><?php echo isEnglish() ? 'Signature' : 'हस्ताक्षर'; ?> <span class="req">*</span></summary>
                                    <div class="kyc-doc-body">
                                        <span class="kyc-cap-label"><?php echo isEnglish() ? 'Signature (draw below)' : 'हस्ताक्षर (तल गर्नुहोस्)'; ?> <span class="req">*</span></span>
                                        <div class="kyc-sig-wrap" data-kyc-signature data-required>
                                            <input type="hidden" name="signature">
                                        </div>
                                    </div>
                                </details>

                                <details class="kyc-doc-item">
                                    <summary><i class="fas fa-fingerprint me-1"></i><?php echo isEnglish() ? 'Left Thumb Print' : 'बायाँ औंठा छाप'; ?></summary>
                                    <div class="kyc-doc-body">
                                        <div class="kyc-cap-field" data-kyc-cap="thumb">
                                            <span class="kyc-cap-label"><i class="fas fa-fingerprint text-success me-1"></i><?php echo isEnglish() ? 'Left Thumb Print' : 'बायाँ औंठा छाप'; ?></span>
                                            <input type="hidden" name="left_thumb">
                                        </div>
                                    </div>
                                </details>

                                <details class="kyc-doc-item">
                                    <summary><i class="fas fa-fingerprint me-1"></i><?php echo isEnglish() ? 'Right Thumb Print' : 'दायाँ औंठा छाप'; ?></summary>
                                    <div class="kyc-doc-body">
                                        <div class="kyc-cap-field" data-kyc-cap="thumb">
                                            <span class="kyc-cap-label"><i class="fas fa-fingerprint text-success me-1"></i><?php echo isEnglish() ? 'Right Thumb Print' : 'दायाँ औंठा छाप'; ?></span>
                                            <input type="hidden" name="right_thumb">
                                        </div>
                                    </div>
                                </details>
                            </div>
                        </div>

                        <!-- Digital ID Card Request -->
                        <div class="form-section" style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;padding:18px 20px;margin-bottom:16px;">
                            <h5 class="visually-hidden"><i class="fas fa-id-card me-1"></i><?php echo isEnglish() ? 'Digital ID Card' : 'डिजिटल ID कार्ड'; ?></h5>
                            <div class="d-flex align-items-flex-start gap-3">
                                <div style="background:linear-gradient(135deg,var(--primary-color),var(--primary-light));border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fas fa-id-card" style="color:#fff;font-size:1.1rem;"></i>
                                </div>
                                <div style="flex:1;">
                                    <div style="font-weight:700;color:var(--primary-color);font-size:.95rem;margin-bottom:4px;">
                                        <?php echo isEnglish() ? 'Digital Member ID Card' : 'डिजिटल सदस्य परिचय पत्र'; ?>
                                    </div>
                                    <div style="font-size:.83rem;color:#4b7a53;margin-bottom:10px;">
                                        <?php echo isEnglish()
                                            ? 'After your KYC is approved, the cooperative will generate a digital ID card for you.'
                                            : 'तपाईंको KYC स्वीकृत भएपछि, सहकारीले तपाईंको लागि डिजिटल परिचय पत्र तयार पार्नेछ।'; ?>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="want_id_card"
                                               id="want_id_card" value="1">
                                        <label class="form-check-label fw-semibold" for="want_id_card"
                                               style="color:var(--primary-color);cursor:pointer;">
                                            <?php echo isEnglish()
                                                ? 'Yes, I want a Digital ID Card (auto-generated after KYC approval)'
                                                : 'हो, मलाई डिजिटल ID कार्ड चाहिन्छ (KYC स्वीकृत भएपछि स्वतः तयार हुनेछ)'; ?>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Declaration -->
                        <div class="form-section">
                            <h5 class="visually-hidden"><i class="fas fa-file-signature me-1"></i><?php echo isEnglish() ? 'Declaration' : 'घोषणापत्र'; ?></h5>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="declaration" name="declaration" value="1">
                                <label class="form-check-label" for="declaration">
                                    <?php echo isEnglish() ? 'I hereby declare that all the information provided above is true and correct to the best of my knowledge.' : 'मैले माथि दिएको सबै जानकारी मेरो जानकारी अनुसार सत्य र सही छ भनी घोषणा गर्दछु।'; ?>
                                </label>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2 mb-3" id="kymWizardControls">
                            <button type="button" class="btn btn-outline-secondary" id="kymPrevBtn">
                                <i class="fas fa-arrow-left me-1"></i><?php echo isEnglish() ? 'Previous' : 'अघिल्लो'; ?>
                            </button>
                            <button type="button" class="btn btn-coop" id="kymNextBtn">
                                <?php echo isEnglish() ? 'Save & Next' : 'सेभ गरेर अगाडि'; ?> <i class="fas fa-arrow-right ms-1"></i>
                            </button>
                        </div>

                        <div class="text-center" id="kymSubmitWrap">
                            <button type="submit" class="btn btn-primary btn-lg" id="kymSubmitBtn">
                    <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
                                <i class="fas fa-paper-plane"></i> <?php echo isEnglish() ? 'Submit KYC Application' : 'केवाइसी आवेदन पेश गर्नुहोस्'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var t = document.getElementById('toggleQuickKycBtn');
    var p = document.getElementById('publicQuickKycPanel');
    var full = document.getElementById('fullKycRow');
    if (!t || !p || !full) return;

    function syncPanels(showQuick) {
        p.style.display = showQuick ? 'block' : 'none';
        full.style.display = showQuick ? 'none' : '';
        t.innerHTML = showQuick
            ? '<i class="fas fa-layer-group me-1"></i><?php echo isEnglish() ? 'Show Full KYC Form' : 'पूर्ण KYC फारम देखाउनुहोस्'; ?>'
            : '<i class="fas fa-bolt me-1"></i><?php echo isEnglish() ? 'Quick KYC (Existing Member)' : 'Quick KYC (पहिलेको सदस्य)'; ?>';
    }

    syncPanels(p.style.display !== 'none');
    t.addEventListener('click', function () {
        var showQuick = (p.style.display === 'none');
        syncPanels(showQuick);
        if (showQuick) p.scrollIntoView({ behavior: 'smooth', block: 'start' });
        else full.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});

// Capture/signature widgets: hidden step बाट visible हुँदा पनि stable render
document.addEventListener('DOMContentLoaded', function () {
    function forceCaptureRefresh() {
        if (window.KYCCapture) {
            try { window.KYCCapture.initAllKYCCapture ? window.KYCCapture.initAllKYCCapture() : window.KYCCapture.setupCaptureFields(); } catch (e) {}
        }
        try { window.dispatchEvent(new Event('resize')); } catch (e) {}
    }

    // Initial deferred safety refresh
    setTimeout(forceCaptureRefresh, 320);

    // Step बदल्दा capture fields re-init + signature canvas ठीक
    ['kymNextBtn', 'kymPrevBtn'].forEach(function (id) {
        var b = document.getElementById(id);
        if (!b) return;
        b.addEventListener('click', function () {
            setTimeout(function () {
                forceCaptureRefresh();
                try { window.dispatchEvent(new Event('resize')); } catch (e) {}
            }, 180);
        });
    });

    // Document accordion खुल्दा पनि capture re-init + redraw
    document.querySelectorAll('.kyc-doc-item > summary').forEach(function (s) {
        s.addEventListener('click', function () {
            setTimeout(function () {
                forceCaptureRefresh();
                try { window.dispatchEvent(new Event('resize')); } catch (e) {}
            }, 140);
        });
    });
});

/* old input > prefill fallback restore (file input बाहेक) */
(function () {
    var oldInputData = <?php echo json_encode($oldInput, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var prefillData = <?php echo json_encode($prefillInput, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var old = (oldInputData && Object.keys(oldInputData).length) ? oldInputData : prefillData;
    if (!old || typeof old !== 'object') return;

    Object.keys(old).forEach(function (key) {
        var val = old[key];
        var nodes = document.querySelectorAll('[name="' + key.replace(/"/g, '\\"') + '"]');
        if (!nodes.length) return;

        nodes.forEach(function (el) {
            var tag = (el.tagName || '').toLowerCase();
            var type = (el.type || '').toLowerCase();

            if (type === 'file') return;
            if (type === 'checkbox') {
                el.checked = Array.isArray(val)
                    ? val.indexOf(el.value) !== -1
                    : (String(val) === '1' || String(val).toLowerCase() === 'on' || String(val).toLowerCase() === 'yes');
                return;
            }
            if (type === 'radio') {
                el.checked = String(el.value) === String(val);
                return;
            }
            if (tag === 'select') {
                el.value = String(val);
                return;
            }
            el.value = String(val ?? '');
        });
    });
})();
</script>

<script>
(function () {
    var sameBox = document.getElementById('kycSameAddress');
    var tempWrap = document.getElementById('kycTemporaryAddressWrap');
    function toggleTemporaryAddress() {
        if (!sameBox || !tempWrap) return;
        var hide = !!sameBox.checked;
        tempWrap.style.display = hide ? 'none' : '';
        tempWrap.querySelectorAll('select,input,textarea').forEach(function (el) {
            el.disabled = hide;
            if (hide && (el.tagName || '').toLowerCase() !== 'select') {
                el.value = '';
            }
            if (hide && (el.tagName || '').toLowerCase() === 'select') {
                el.selectedIndex = 0;
            }
        });
    }
    if (sameBox) {
        sameBox.addEventListener('change', toggleTemporaryAddress);
        setTimeout(toggleTemporaryAddress, 0);
    }

    var relationEl = document.getElementById('familyRelation');
    var nameEl = document.getElementById('familyMemberName');
    var phoneEl = document.getElementById('familyMemberPhone');
    var addBtn = document.getElementById('addFamilyMemberBtn');
    var tbody = document.getElementById('familyMembersTableBody');
    var emptyRow = document.getElementById('familyMembersEmptyRow');
    var hiddenWrap = document.getElementById('familyMembersHiddenInputs');

    var legacyFather = document.getElementById('legacyFatherName');
    var legacyMother = document.getElementById('legacyMotherName');
    var legacyGrandfather = document.getElementById('legacyGrandfatherName');
    var legacySpouse = document.getElementById('legacySpouseName');

    var relationMap = {
        father: '<?php echo isEnglish() ? 'Father' : 'बुबा'; ?>',
        mother: '<?php echo isEnglish() ? 'Mother' : 'आमा'; ?>',
        grandfather: '<?php echo isEnglish() ? 'Grandfather' : 'हजुरबुबा'; ?>',
        spouse: '<?php echo isEnglish() ? 'Spouse' : 'पति/पत्नी'; ?>',
        son: '<?php echo isEnglish() ? 'Son' : 'छोरा'; ?>',
        daughter: '<?php echo isEnglish() ? 'Daughter' : 'छोरी'; ?>',
        guardian: '<?php echo isEnglish() ? 'Guardian' : 'अभिभावक'; ?>',
        other: '<?php echo isEnglish() ? 'Other' : 'अन्य'; ?>'
    };
    var familyRows = [];
    var incomeRows = [];
    var expenseRows = [];

    function syncLegacyFields() {
        if (legacyFather) legacyFather.value = '';
        if (legacyMother) legacyMother.value = '';
        if (legacyGrandfather) legacyGrandfather.value = '';
        if (legacySpouse) legacySpouse.value = '';
        familyRows.forEach(function (row) {
            if (!row || !row.relation || !row.name) return;
            if (row.relation === 'father' && legacyFather && !legacyFather.value) legacyFather.value = row.name;
            if (row.relation === 'mother' && legacyMother && !legacyMother.value) legacyMother.value = row.name;
            if (row.relation === 'grandfather' && legacyGrandfather && !legacyGrandfather.value) legacyGrandfather.value = row.name;
            if (row.relation === 'spouse' && legacySpouse && !legacySpouse.value) legacySpouse.value = row.name;
        });
    }

    function buildHiddenInputs() {
        if (!hiddenWrap) return;
        hiddenWrap.innerHTML = '';
        familyRows.forEach(function (row) {
            var rel = document.createElement('input');
            rel.type = 'hidden';
            rel.name = 'family_relation[]';
            rel.value = row.relation || '';
            hiddenWrap.appendChild(rel);

            var nm = document.createElement('input');
            nm.type = 'hidden';
            nm.name = 'family_member_name[]';
            nm.value = row.name || '';
            hiddenWrap.appendChild(nm);

            var ph = document.createElement('input');
            ph.type = 'hidden';
            ph.name = 'family_member_phone[]';
            ph.value = row.phone || '';
            hiddenWrap.appendChild(ph);
        });
    }

    function renderFamilyTable() {
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!familyRows.length) {
            if (emptyRow) tbody.appendChild(emptyRow);
            buildHiddenInputs();
            syncLegacyFields();
            return;
        }
        familyRows.forEach(function (row, idx) {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td>' + (relationMap[row.relation] || row.relation || '-') + '</td>' +
                '<td>' + (row.name || '-') + '</td>' +
                '<td>' + (row.phone || '-') + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-danger" data-remove-family="' + idx + '" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button></td>';
            tbody.appendChild(tr);
        });
        buildHiddenInputs();
        syncLegacyFields();
    }

    function addFamilyRow(relation, name, phone) {
        var rel = String(relation || '').trim().toLowerCase();
        var nm = String(name || '').trim();
        var ph = String(phone || '').trim();
        if (!rel || !nm) {
            alert('<?php echo isEnglish() ? 'Please select relation and enter name.' : 'सम्बन्ध छान्नुहोस् र नाम लेख्नुहोस्।'; ?>');
            return false;
        }
        familyRows.push({ relation: rel, name: nm, phone: ph });
        renderFamilyTable();
        syncNomineeFamilyOptions();
        syncFamilySameMemberOptions();
        return true;
    }

    function syncNomineeFamilyOptions() {
        var sel = document.getElementById('nomineeFromFamily');
        if (!sel) return;
        var prev = sel.value;
        sel.innerHTML = '<option value="">परिवार सूचीबाट छान्नुहोस्</option>';
        familyRows.forEach(function (r) {
            if (!r || !r.name) return;
            var o = document.createElement('option');
            o.value = r.name;
            o.textContent = (relationMap[r.relation] || r.relation || 'सम्बन्ध') + ' - ' + r.name;
            sel.appendChild(o);
        });
        if (prev) sel.value = prev;
    }

    function syncFamilySameMemberOptions() {
        var sel = document.getElementById('familySameMemberSelect');
        if (!sel) return;
        var prev = sel.value;
        sel.innerHTML = '<option value="">परिवार सूचीबाट छान्नुहोस्</option>';
        familyRows.forEach(function (r) {
            if (!r || !r.name) return;
            var o = document.createElement('option');
            o.value = r.name;
            o.textContent = (relationMap[r.relation] || r.relation || 'सम्बन्ध') + ' - ' + r.name;
            sel.appendChild(o);
        });
        if (prev) sel.value = prev;
    }

    function parseAmount(val) {
        var n = parseFloat(String(val || '').replace(/[^0-9.]/g, ''));
        return Number.isFinite(n) ? n : 0;
    }

    function renderSourceTable(opts) {
        var rows = opts.rows;
        var tbodyEl = opts.tbody;
        var emptyEl = opts.emptyRow;
        var hiddenEl = opts.hiddenWrap;
        var nameKey = opts.nameKey;
        var amountKey = opts.amountKey;
        if (!tbodyEl || !hiddenEl) return 0;
        tbodyEl.innerHTML = '';
        if (!rows.length) {
            if (emptyEl) tbodyEl.appendChild(emptyEl);
            hiddenEl.innerHTML = '';
            return 0;
        }
        var total = 0;
        hiddenEl.innerHTML = '';
        rows.forEach(function (row, idx) {
            var amt = parseAmount(row.amount);
            total += amt;
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td>' + (row.name || '-') + '</td>' +
                '<td>' + amt.toFixed(2) + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-danger" data-remove-source="' + opts.type + ':' + idx + '" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button></td>';
            tbodyEl.appendChild(tr);

            var n = document.createElement('input');
            n.type = 'hidden';
            n.name = nameKey + '[]';
            n.value = row.name || '';
            hiddenEl.appendChild(n);

            var a = document.createElement('input');
            a.type = 'hidden';
            a.name = amountKey + '[]';
            a.value = amt.toFixed(2);
            hiddenEl.appendChild(a);
        });
        return total;
    }

    function refreshIncomeExpenseSummary() {
        var incomeTotal = renderSourceTable({
            rows: incomeRows,
            tbody: document.getElementById('incomeSourcesTableBody'),
            emptyRow: document.getElementById('incomeSourcesEmptyRow'),
            hiddenWrap: document.getElementById('incomeSourcesHiddenInputs'),
            nameKey: 'income_source_name',
            amountKey: 'income_source_amount',
            type: 'income'
        });
        var expenseTotal = renderSourceTable({
            rows: expenseRows,
            tbody: document.getElementById('expenseSourcesTableBody'),
            emptyRow: document.getElementById('expenseSourcesEmptyRow'),
            hiddenWrap: document.getElementById('expenseSourcesHiddenInputs'),
            nameKey: 'expense_source_name',
            amountKey: 'expense_source_amount',
            type: 'expense'
        });
        var iDisp = document.getElementById('incomeTotalDisplay');
        var eDisp = document.getElementById('expenseTotalDisplay');
        var nDisp = document.getElementById('netSavingDisplay');
        if (iDisp) iDisp.value = incomeTotal.toFixed(2);
        if (eDisp) eDisp.value = expenseTotal.toFixed(2);
        if (nDisp) nDisp.value = (incomeTotal - expenseTotal).toFixed(2);
    }

    function addSourceRow(type, name, amount) {
        var nm = String(name || '').trim();
        var amt = parseAmount(amount);
        if (!nm || amt <= 0) {
            alert('<?php echo isEnglish() ? 'Please enter source and amount.' : 'स्रोत र रकम लेख्नुहोस्।'; ?>');
            return false;
        }
        var row = { name: nm, amount: amt };
        if (type === 'income') incomeRows.push(row);
        else expenseRows.push(row);
        refreshIncomeExpenseSummary();
        return true;
    }

    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var ok = addFamilyRow(relationEl && relationEl.value, nameEl && nameEl.value, phoneEl && phoneEl.value);
            if (!ok) return;
            if (relationEl) relationEl.value = '';
            if (nameEl) nameEl.value = '';
            if (phoneEl) phoneEl.value = '';
            if (relationEl) relationEl.focus();
        });
    }

    if (tbody) {
        tbody.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-remove-family]');
            if (!btn) return;
            var idx = parseInt(btn.getAttribute('data-remove-family'), 10);
            if (Number.isNaN(idx)) return;
            familyRows.splice(idx, 1);
            renderFamilyTable();
            syncNomineeFamilyOptions();
            syncFamilySameMemberOptions();
        });
    }

    var nomineeSel = document.getElementById('nomineeFromFamily');
    var nomineeInput = document.getElementById('nomineeNameInput');
    if (nomineeSel && nomineeInput) {
        nomineeSel.addEventListener('change', function () {
            if (this.value) nomineeInput.value = this.value;
        });
    }
    var familySameSel = document.getElementById('familySameMemberSelect');
    var familySameDetails = document.querySelector('input[name="family_same_coop_details"]');
    if (familySameSel && familySameDetails) {
        familySameSel.addEventListener('change', function () {
            if (this.value && !familySameDetails.value) familySameDetails.value = this.value;
        });
    }

    var addIncomeBtn = document.getElementById('addIncomeSourceBtn');
    var addExpenseBtn = document.getElementById('addExpenseSourceBtn');
    var incomeNameEl = document.getElementById('incomeSourceName');
    var incomeAmtEl = document.getElementById('incomeSourceAmount');
    var expenseNameEl = document.getElementById('expenseSourceName');
    var expenseAmtEl = document.getElementById('expenseSourceAmount');

    if (addIncomeBtn) {
        addIncomeBtn.addEventListener('click', function () {
            var ok = addSourceRow('income', incomeNameEl && incomeNameEl.value, incomeAmtEl && incomeAmtEl.value);
            if (!ok) return;
            if (incomeNameEl) incomeNameEl.value = '';
            if (incomeAmtEl) incomeAmtEl.value = '';
            if (incomeNameEl) incomeNameEl.focus();
        });
    }
    if (addExpenseBtn) {
        addExpenseBtn.addEventListener('click', function () {
            var ok = addSourceRow('expense', expenseNameEl && expenseNameEl.value, expenseAmtEl && expenseAmtEl.value);
            if (!ok) return;
            if (expenseNameEl) expenseNameEl.value = '';
            if (expenseAmtEl) expenseAmtEl.value = '';
            if (expenseNameEl) expenseNameEl.focus();
        });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-remove-source]');
        if (!btn) return;
        var parts = String(btn.getAttribute('data-remove-source') || '').split(':');
        if (parts.length !== 2) return;
        var idx = parseInt(parts[1], 10);
        if (Number.isNaN(idx)) return;
        if (parts[0] === 'income') incomeRows.splice(idx, 1);
        if (parts[0] === 'expense') expenseRows.splice(idx, 1);
        refreshIncomeExpenseSummary();
    });

    // old input restore support
    var oldInputData = <?php echo json_encode($oldInput, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var prefillData = <?php echo json_encode($prefillInput, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var old = (oldInputData && Object.keys(oldInputData).length) ? oldInputData : prefillData;
    if (old && typeof old === 'object') {
        var relOld = Array.isArray(old.family_relation) ? old.family_relation : [];
        var nameOld = Array.isArray(old.family_member_name) ? old.family_member_name : [];
        var phoneOld = Array.isArray(old.family_member_phone) ? old.family_member_phone : [];
        var maxLen = Math.max(relOld.length, nameOld.length, phoneOld.length);
        for (var i = 0; i < maxLen; i++) {
            var rel = String(relOld[i] || '').trim().toLowerCase();
            var nm = String(nameOld[i] || '').trim();
            var ph = String(phoneOld[i] || '').trim();
            if (rel && nm) familyRows.push({ relation: rel, name: nm, phone: ph });
        }

        var incomeOldNames = Array.isArray(old.income_source_name) ? old.income_source_name : [];
        var incomeOldAmts = Array.isArray(old.income_source_amount) ? old.income_source_amount : [];
        for (var ii = 0; ii < Math.max(incomeOldNames.length, incomeOldAmts.length); ii++) {
            var inName = String(incomeOldNames[ii] || '').trim();
            var inAmt = parseAmount(incomeOldAmts[ii] || '');
            if (inName && inAmt > 0) incomeRows.push({ name: inName, amount: inAmt });
        }

        var expenseOldNames = Array.isArray(old.expense_source_name) ? old.expense_source_name : [];
        var expenseOldAmts = Array.isArray(old.expense_source_amount) ? old.expense_source_amount : [];
        for (var ei = 0; ei < Math.max(expenseOldNames.length, expenseOldAmts.length); ei++) {
            var exName = String(expenseOldNames[ei] || '').trim();
            var exAmt = parseAmount(expenseOldAmts[ei] || '');
            if (exName && exAmt > 0) expenseRows.push({ name: exName, amount: exAmt });
        }
    }
    renderFamilyTable();
    syncNomineeFamilyOptions();
    syncFamilySameMemberOptions();
    refreshIncomeExpenseSummary();
})();

(function () {
    var section = document.getElementById('kymExtendedSection');
    var row = section ? section.querySelector('.row') : null;
    if (!row) return;

    var titleCols = Array.prototype.slice.call(row.querySelectorAll('.col-12 .kym-subsec-title'))
        .map(function (el) { return el.closest('.col-12'); })
        .filter(Boolean);
    if (titleCols.length < 2) return;

    var sections = [];
    for (var i = 0; i < titleCols.length; i++) {
        var startCol = titleCols[i];
        var endCol = titleCols[i + 1] || null;
        var members = [];
        var cur = startCol.nextElementSibling;
        while (cur && cur !== endCol) {
            members.push(cur);
            cur = cur.nextElementSibling;
        }
        sections.push({ titleCol: startCol, members: members });
    }

    function getSectionFields(sec) {
        var all = [];
        sec.members.forEach(function (n) {
            var nodes = n.querySelectorAll('input, select, textarea');
            nodes.forEach(function (el) { all.push(el); });
        });
        return all;
    }

    function isSectionFilled(sec) {
        var fields = getSectionFields(sec);
        for (var i = 0; i < fields.length; i++) {
            var el = fields[i];
            var name = (el.getAttribute('name') || '').trim();
            if (!name) continue;
            var type = (el.getAttribute('type') || '').toLowerCase();
            if (type === 'hidden' || type === 'button' || type === 'submit') continue;
            if (type === 'checkbox' || type === 'radio') {
                if (el.checked) return true;
                continue;
            }
            if (String(el.value || '').trim() !== '') return true;
        }
        return false;
    }

    function updateSectionBadges() {
        sections.forEach(function (sec) {
            var title = sec.titleCol.querySelector('.kym-subsec-title');
            if (!title) return;
            var filled = isSectionFilled(sec);
            var badge = title.querySelector('.kym-acc-badge');
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'kym-acc-badge';
                title.appendChild(badge);
            }
            badge.textContent = filled ? 'भरिएको' : 'अपूर्ण';
            badge.classList.toggle('ok', filled);
            badge.classList.toggle('pending', !filled);
        });
    }

    function setOpen(idx) {
        sections.forEach(function (sec, sidx) {
            var open = sidx === idx;
            sec.members.forEach(function (n) {
                n.style.display = open ? '' : 'none';
            });
            var title = sec.titleCol.querySelector('.kym-subsec-title');
            if (!title) return;
            title.classList.toggle('kym-subsec-open', open);
            var icon = title.querySelector('.kym-acc-icon');
            if (icon) icon.textContent = open ? '−' : '+';
        });
    }

    sections.forEach(function (sec, idx) {
        var title = sec.titleCol.querySelector('.kym-subsec-title');
        if (!title) return;
        title.style.cursor = 'pointer';
        title.setAttribute('role', 'button');
        title.setAttribute('tabindex', '0');
        if (!title.querySelector('.kym-acc-icon')) {
            var icon = document.createElement('span');
            icon.className = 'kym-acc-icon';
            icon.textContent = '+';
            title.appendChild(icon);
        }
        function onToggle() { setOpen(idx); }
        title.addEventListener('click', onToggle);
        title.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                onToggle();
            }
        });

        sec.members.forEach(function (n) {
            n.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.addEventListener('input', updateSectionBadges);
                el.addEventListener('change', updateSectionBadges);
            });
        });
    });

    setOpen(0);
    updateSectionBadges();
})();

(function () {
    var occ = document.querySelector('select[name="occupation"]');
    var rented = document.getElementById('isRentedSelect');
    var businessFields = document.querySelectorAll('.business-only-field');
    var rentedFields = document.querySelectorAll('.rented-only-field');
    function toggleBusiness() {
        if (!occ) return;
        var show = occ.value === 'business';
        businessFields.forEach(function (el) {
            el.style.display = show ? '' : 'none';
            el.querySelectorAll('input,select,textarea').forEach(function (f) { if (!show) f.value = ''; });
        });
    }
    function toggleRented() {
        if (!rented) return;
        var show = rented.value === 'yes';
        rentedFields.forEach(function (el) {
            el.style.display = show ? '' : 'none';
            el.querySelectorAll('input,select,textarea').forEach(function (f) { if (!show) f.value = ''; });
        });
    }
    if (occ) { occ.addEventListener('change', toggleBusiness); setTimeout(toggleBusiness, 0); }
    if (rented) { rented.addEventListener('change', toggleRented); setTimeout(toggleRented, 0); }
})();

(function () {
    var selfSel = document.getElementById('selfOtherCoopSelect');
    var familySel = document.getElementById('familySameCoopSelect');
    var selfFields = document.querySelectorAll('.self-coop-only-field');
    var familyFields = document.querySelectorAll('.family-coop-only-field');
    function toggleSelfCoopDetails() {
        if (!selfSel) return;
        var show = selfSel.value === 'yes';
        selfFields.forEach(function (el) {
            el.style.display = show ? '' : 'none';
            if (!show) el.querySelectorAll('input,select,textarea').forEach(function (f) { f.value = ''; });
        });
    }
    function toggleFamilyCoopDetails() {
        if (!familySel) return;
        var show = familySel.value === 'yes';
        familyFields.forEach(function (el) {
            el.style.display = show ? '' : 'none';
            if (!show) el.querySelectorAll('input,select,textarea').forEach(function (f) { f.value = ''; });
        });
    }
    if (selfSel) { selfSel.addEventListener('change', toggleSelfCoopDetails); setTimeout(toggleSelfCoopDetails, 0); }
    if (familySel) { familySel.addEventListener('change', toggleFamilyCoopDetails); setTimeout(toggleFamilyCoopDetails, 0); }
})();

(function () {
    var form = document.getElementById('fullKymForm');
    if (!form) return;
    /* Mobile soft-keyboard Enter should not submit the whole wizard early */
    form.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        var t = e.target;
        if (!t) return;
        var tag = (t.tagName || '').toLowerCase();
        if (tag === 'textarea') return;
        if (tag === 'button' || (tag === 'input' && (t.type === 'submit' || t.type === 'button'))) return;
        e.preventDefault();
    });
    var sections    = Array.prototype.slice.call(form.querySelectorAll('.form-section'));
    if (sections.length < 2) return;
    var nav         = document.getElementById('kymWizardNav');
    var prevBtn     = document.getElementById('kymPrevBtn');
    var nextBtn     = document.getElementById('kymNextBtn');
    var submitWrap  = document.getElementById('kymSubmitWrap');
    var submitBtn   = document.getElementById('kymSubmitBtn');
    var stepCounter = document.getElementById('kymStepCounter');
    var progressFill= document.getElementById('kymProgressFill');
    var stepKey     = 'kym_form_step_v2';
    var draftKey    = 'kym_form_draft_v2';
    var visitedKey  = 'kym_visited_v2';
    var currentStep = 0;
    var hasServerData = <?php echo !empty($prefillInput) ? 'true' : 'false'; ?>;
    var hasOldInput   = <?php echo !empty($oldInput)     ? 'true' : 'false'; ?>;
    var visitedSteps  = {}; /* { "stepIndex": true } — persisted in localStorage */
    var isEn = <?php echo json_encode(isEnglish()); ?>;

    /* ── section title helpers ─────────────────────── */
    function getSectionTitle(sec, idx) {
        var h = sec.querySelector('h5');
        var t = h ? String(h.textContent || '').trim() : '';
        if (!t) t = (isEn ? 'Section' : 'भाग') + ' ' + (idx + 1);
        return t.replace(/\s+/g, ' ');
    }
    function getSectionShortTitle(sec, idx) {
        var full = getSectionTitle(sec, idx);
        var maxChars = isEn ? 18 : 14;
        if (full.length > maxChars) return full.substring(0, maxChars - 1) + '…';
        return full;
    }

    /* ── draft persistence ────────────────────────── */
    function saveDraft() {
        var payload = {};
        var fields = form.querySelectorAll('input[name], select[name], textarea[name]');
        fields.forEach(function (el) {
            var name = String(el.name || '').trim();
            if (!name) return;
            var type = String(el.type || '').toLowerCase();
            if (type === 'file') return;
            if (name.slice(-2) === '[]') return; // dynamic hidden arrays managed separately
            if (type === 'checkbox') {
                payload[name] = el.checked ? (el.value || '1') : '';
                return;
            }
            if (type === 'radio') {
                if (el.checked) payload[name] = el.value;
                return;
            }
            payload[name] = el.value;
        });
        try { localStorage.setItem(draftKey,   JSON.stringify(payload)); } catch (e) {}
        try { localStorage.setItem(stepKey,    String(currentStep)); } catch (e) {}
        try { localStorage.setItem(visitedKey, JSON.stringify(visitedSteps)); } catch (e) {}
    }
    function loadDraftIfNeeded() {
        if (hasOldInput || hasServerData) return;
        var raw = null;
        try { raw = localStorage.getItem(draftKey); } catch (e) {}
        if (!raw) return;
        var data = null;
        try { data = JSON.parse(raw); } catch (e) { data = null; }
        if (!data || typeof data !== 'object') return;
        Object.keys(data).forEach(function (name) {
            var nodes = form.querySelectorAll('[name="' + name.replace(/"/g, '\\"') + '"]');
            if (!nodes.length) return;
            nodes.forEach(function (el) {
                var type = String(el.type || '').toLowerCase();
                if (type === 'checkbox') {
                    el.checked = String(data[name] || '') !== '';
                } else if (type === 'radio') {
                    el.checked = String(el.value) === String(data[name] || '');
                } else if (type !== 'file') {
                    el.value = String(data[name] ?? '');
                }
            });
        });
    }

    /* ── visited-step tracking ────────────────────── */
    function markVisited(idx) { visitedSteps[String(idx)] = true; }

    /* ── progress strip update ────────────────────── */
    function updateProgress() {
        var total = sections.length;
        var pct   = total > 1 ? Math.round((currentStep / (total - 1)) * 100) : 100;
        if (stepCounter) {
            stepCounter.textContent = isEn
                ? 'Step ' + (currentStep + 1) + ' of ' + total
                : 'भाग '  + (currentStep + 1) + ' / '  + total;
        }
        if (progressFill) progressFill.style.width = pct + '%';
    }

    /* ── nav rendering with checkmarks ───────────── */
    function renderNav() {
        if (!nav) return;
        nav.innerHTML = '';
        sections.forEach(function (sec, idx) {
            var isDone = !!visitedSteps[String(idx)] && idx !== currentStep;
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'kym-step-btn'
                + (idx === currentStep ? ' active' : '')
                + (isDone             ? ' done'   : '');
            b.title = getSectionTitle(sec, idx);

            var numSpan = document.createElement('span');
            numSpan.className = 'kym-step-num';
            if (isDone) {
                numSpan.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i>';
            } else {
                numSpan.textContent = String(idx + 1);
            }

            var labelSpan = document.createElement('span');
            labelSpan.className = 'kym-step-label';
            labelSpan.textContent = getSectionShortTitle(sec, idx);

            b.appendChild(numSpan);
            b.appendChild(labelSpan);
            b.addEventListener('click', function () {
                if (idx !== currentStep) markVisited(currentStep);
                saveDraft();
                setStep(idx);
            });
            nav.appendChild(b);
        });
    }

    /* ── navigation ───────────────────────────────── */
    function setStep(idx) {
        currentStep = Math.max(0, Math.min(idx, sections.length - 1));
        sections.forEach(function (sec, sidx) {
            sec.style.display = (sidx === currentStep) ? '' : 'none';
        });
        if (prevBtn)    prevBtn.disabled         = currentStep === 0;
        if (nextBtn)    nextBtn.style.display    = currentStep === sections.length - 1 ? 'none' : '';
        if (submitWrap) submitWrap.style.display = currentStep === sections.length - 1 ? '' : 'none';
        renderNav();
        updateProgress();
        window.scrollTo({ top: form.getBoundingClientRect().top + window.scrollY - 120, behavior: 'smooth' });
    }

    if (nextBtn) nextBtn.addEventListener('click', function () {
        markVisited(currentStep); /* mark section done when advancing */
        saveDraft();
        setStep(currentStep + 1);
    });
    if (prevBtn) prevBtn.addEventListener('click', function () {
        saveDraft();
        setStep(currentStep - 1);
    });

    form.addEventListener('input',  saveDraft, true);
    form.addEventListener('change', saveDraft, true);
    form.addEventListener('submit', function (e) {
        var decl = document.getElementById('declaration');
        if (decl && !decl.checked) {
            e.preventDefault();
            var wrap = decl.closest('.form-section') || decl.parentElement;
            if (wrap) { wrap.style.outline = '2px solid #dc3545'; wrap.style.borderRadius = '6px'; }
            decl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        try {
            localStorage.removeItem(draftKey);
            localStorage.removeItem(stepKey);
            localStorage.removeItem(visitedKey);
        } catch (e) {}
        if (submitBtn) {
            submitBtn.disabled = true;
            var spin = submitBtn.querySelector('.spinner-border');
            if (spin) spin.classList.remove('d-none');
        }
    });

    loadDraftIfNeeded();
    /* Restore visited steps from previous session */
    try {
        var rawV = localStorage.getItem(visitedKey);
        if (rawV) visitedSteps = JSON.parse(rawV) || {};
    } catch (e) {}
    var fromStorage = 0;
    try { fromStorage = parseInt(localStorage.getItem(stepKey) || '0', 10) || 0; } catch (e) {}
    /* Pre-mark all steps the user has already navigated past */
    for (var i = 0; i < fromStorage; i++) { markVisited(i); }
    setStep(fromStorage);
})();
</script>

<link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
    crossorigin=""
>
<script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
    crossorigin=""
></script>
<script>
(function () {
    var input = document.getElementById('longitudeLatitudeInput');
    var resolvedAddressInput = document.getElementById('mapResolvedAddressInput');
    var mapEl = document.getElementById('kycMapPicker');
    var hintEl = document.getElementById('kycMapHint');
    var btnCurrent = document.getElementById('btnUseCurrentLocation');
    if (!input || !mapEl) return;
    if (typeof L === 'undefined') {
        if (hintEl) hintEl.textContent = 'Map load हुन सकेन। कृपया refresh गर्नुहोस्।';
        input.removeAttribute('readonly');
        input.placeholder = 'जस्तै: 28.2096, 83.9856';
        return;
    }

    var defaultLat = 28.2096;
    var defaultLng = 83.9856;
    var zoom = 8;
    var marker = null;
    var geoReqSeq = 0;

    function parseInputValue() {
        var raw = String(input.value || '').trim();
        if (!raw) return null;
        var parts = raw.split(',');
        if (parts.length < 2) return null;
        var lat = parseFloat(parts[0]);
        var lng = parseFloat(parts[1]);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
        return { lat: lat, lng: lng };
    }

    function setValue(lat, lng) {
        input.value = lat.toFixed(6) + ', ' + lng.toFixed(6);
        if (hintEl) hintEl.textContent = 'Selected: ' + input.value;
    }

    function setResolvedAddress(text) {
        if (!resolvedAddressInput) return;
        resolvedAddressInput.value = text || '';
    }

    function reverseGeocode(lat, lng) {
        geoReqSeq += 1;
        var reqId = geoReqSeq;
        if (hintEl) hintEl.textContent = 'ठेगाना खोजिँदैछ...';
        var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat='
            + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng);
        fetch(url, {
            headers: { 'Accept': 'application/json' }
        }).then(function (r) {
            return r.ok ? r.json() : null;
        }).then(function (data) {
            if (reqId !== geoReqSeq) return;
            var addr = data && (data.display_name || '');
            setResolvedAddress(addr || '');
            if (hintEl) {
                hintEl.textContent = addr ? ('Selected: ' + input.value) : 'ठेगाना फेला परेन, coordinate मात्र save हुन्छ।';
            }
        }).catch(function () {
            if (reqId !== geoReqSeq) return;
            if (hintEl) hintEl.textContent = 'ठेगाना खोज्न सकिएन, coordinate मात्र save हुन्छ।';
        });
    }

    var initial = parseInputValue();
    if (initial) {
        defaultLat = initial.lat;
        defaultLng = initial.lng;
        zoom = 14;
    }

    var map = L.map(mapEl).setView([defaultLat, defaultLng], zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    function placeMarker(lat, lng, recenter) {
        if (!marker) {
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            marker.on('dragend', function (e) {
                var p = e.target.getLatLng();
                setValue(p.lat, p.lng);
            });
        } else {
            marker.setLatLng([lat, lng]);
        }
        if (recenter) map.setView([lat, lng], 16);
        setValue(lat, lng);
        reverseGeocode(lat, lng);
    }

    if (initial) placeMarker(initial.lat, initial.lng, false);

    map.on('click', function (e) {
        placeMarker(e.latlng.lat, e.latlng.lng, false);
    });

    if (btnCurrent && navigator.geolocation) {
        btnCurrent.addEventListener('click', function () {
            navigator.geolocation.getCurrentPosition(function (pos) {
                placeMarker(pos.coords.latitude, pos.coords.longitude, true);
            }, function () {
                if (hintEl) hintEl.textContent = 'हालको location प्राप्त गर्न सकिएन। नक्सामा click गरेर छान्नुहोस्।';
            }, { enableHighAccuracy: true, timeout: 10000 });
        });
    } else if (btnCurrent) {
        btnCurrent.disabled = true;
    }

    setTimeout(function () { map.invalidateSize(); }, 200);
})();
</script>


<?php require_once 'includes/footer.php'; ?>
