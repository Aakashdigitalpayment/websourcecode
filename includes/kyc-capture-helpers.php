<?php
/**
 * KYC Capture Helpers (v10.4)
 * Base64 (data URL) → uploaded file converter
 * Used by online-kyc.php and member/full-kyc.php for camera/signature/fingerprint capture.
 */

if (!defined('UPLOAD_PATH')) {
    // Fallback — config.php should already define
    define('UPLOAD_PATH', __DIR__ . '/../assets/uploads/');
}

/**
 * Save a base64 data-URL (image) to disk and return the relative path
 * (relative to project root, e.g. "assets/uploads/kyc/abc.jpg")
 *
 * @param string $dataUrl  e.g. "data:image/jpeg;base64,..."
 * @param string $folder   Subfolder under uploads
 * @param string $prefix   Optional filename prefix
 * @return array  ['success'=>bool, 'path'=>string|null, 'message'=>string|null]
 */
if (!function_exists('saveBase64Image')) {
function saveBase64Image($dataUrl, $folder = 'kyc', $prefix = '') {
    if (!is_string($dataUrl) || strlen($dataUrl) < 30) {
        return ['success' => false, 'message' => 'Empty data'];
    }
    if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,(.+)$#i', $dataUrl, $m)) {
        return ['success' => false, 'message' => 'Invalid data URL'];
    }
    $ext = strtolower($m[1]);
    if ($ext === 'jpeg') $ext = 'jpg';
    $bin = base64_decode($m[2], true);
    if ($bin === false) {
        return ['success' => false, 'message' => 'Invalid base64'];
    }

    // Hard size limit — 5 MB per capture
    if (strlen($bin) > 5 * 1024 * 1024) {
        return ['success' => false, 'message' => 'File too large (max 5MB)'];
    }

    $dir = UPLOAD_PATH . $folder . '/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $name = ($prefix ? $prefix . '_' : '') . uniqid() . '_' . time() . '.' . $ext;
    $abs  = $dir . $name;
    if (file_put_contents($abs, $bin) === false) {
        return ['success' => false, 'message' => 'Failed to write file'];
    }

    // Build path relative to project root
    $relUploads = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'] ?? '', '', UPLOAD_PATH), '/');
    if ($relUploads === '' || $relUploads[0] !== '/') {
        // fallback: assume "assets/uploads/"
        $rel = 'assets/uploads/' . $folder . '/' . $name;
    } else {
        $rel = ltrim($relUploads, '/') . '/' . $folder . '/' . $name;
    }
    return ['success' => true, 'path' => $rel];
}
}

/**
 * True if uploaded file is JPEG by extension and (when available) MIME.
 */
if (!function_exists('kyc_is_jpeg_upload')) {
function kyc_is_jpeg_upload(array $file) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return false;
    }
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg'], true)) {
        return false;
    }
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return false;
    }
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string)finfo_file($fi, $tmp);
            finfo_close($fi);
            if ($mime !== 'image/jpeg') {
                return false;
            }
        }
    }
    return true;
}
}

/**
 * Try multiple sources to obtain a file path for a KYC field:
 *   1. Base64 hidden input (preferred — from camera/signature)
 *   2. Traditional $_FILES upload (fallback)
 *
 * @param string $name        Input name
 * @param string $folder      Storage subfolder
 * @param bool   $jpegOnly    If true, accept only JPEG base64 / .jpg uploads (not PNG signature, etc.)
 * @return string             Saved path or empty string
 */
if (!function_exists('captureOrUpload')) {
function captureOrUpload($name, $folder = 'kyc', $jpegOnly = false) {
    // 1. Base64 capture
    if (!empty($_POST[$name]) && is_string($_POST[$name]) && strpos($_POST[$name], 'data:image') === 0) {
        if ($jpegOnly && !preg_match('#^data:image/(jpeg|jpg);base64,#i', $_POST[$name])) {
            return '';
        }
        $r = saveBase64Image($_POST[$name], $folder, $name);
        if (!empty($r['success'])) return $r['path'];
    }
    // 2. Native file upload
    if (isset($_FILES[$name]) && $_FILES[$name]['error'] === UPLOAD_ERR_OK) {
        if ($jpegOnly && !kyc_is_jpeg_upload($_FILES[$name])) {
            return '';
        }
        if (function_exists('uploadFile')) {
            $r = uploadFile($_FILES[$name], $folder);
            if (!empty($r['success'])) return $r['path'];
        }
    }
    return '';
}
}

/**
 * Compose a full address string from the new structured fields
 * for backward compatibility with the existing TEXT column.
 */
if (!function_exists('composeAddress')) {
function composeAddress($prefix) {
    $parts = [];
    foreach (['tole', 'ward', 'municipality', 'district', 'province'] as $k) {
        $v = trim($_POST[$prefix . '_' . $k] ?? '');
        if ($v === '') continue;
        if ($k === 'ward') $parts[] = 'वडा ' . $v;
        else $parts[] = $v;
    }
    return implode(', ', $parts);
}
}

/**
 * Browser लोड गर्न मिल्ने पूर्ण URL (सापेक्ष upload पथ → SITE_URL)
 * Member portal /member/ मा relative भाँडिएर छवि नबिग्रियोस् भन्नका लागि।
 */
if (!function_exists('publicSiteAssetUrl')) {
function publicSiteAssetUrl(?string $path): string {
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^(https?:)?//#i', $path)) {
        return $path;
    }
    if (!defined('SITE_URL')) {
        return '/' . ltrim($path, '/');
    }

    return rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
}
}

/**
 * KYC कागजात क्षेत्र खाली छ वा सर्भरमा फाइल छैन (वा external URL — मान्य मान्ने)
 */
if (!function_exists('kycDocNeedsUpload')) {
function kycDocNeedsUpload(?string $path): bool {
    $path = trim((string)$path);
    if ($path === '') {
        return true;
    }
    if (preg_match('#^(https?:)?//#i', $path)) {
        return false;
    }
    $root = defined('ROOT_PATH') ? ROOT_PATH : (dirname(__DIR__) . '/');
    $rel  = ltrim(str_replace('\\', '/', $path), '/');
    $full = rtrim(str_replace('\\', '/', $root), '/') . '/' . $rel;
    return !is_file($full);
}
}
