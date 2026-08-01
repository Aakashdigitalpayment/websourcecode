<?php
/**
 * Cooperative display terminology (UI labels only).
 * Technical IDs stay: kyc_applications, online-kyc.php, tracking KYC-*, branch DB column.
 *
 * सहकारीमा: ग्राहक→सदस्य (KYC→KYM), शाखा कार्यालय→सेवा कार्यालय
 */
if (!function_exists('coop_is_en')) {
    function coop_is_en(): bool
    {
        return function_exists('isEnglish') && isEnglish();
    }
}

/** Short label: केवाइएम / KYM */
if (!function_exists('coop_term_kym')) {
    function coop_term_kym(?bool $en = null): string
    {
        $en = $en ?? coop_is_en();
        return $en ? 'KYM' : 'केवाइएम';
    }
}

/** Full: सदस्य पहिचान (केवाइएम) / Know Your Member (KYM) */
if (!function_exists('coop_term_kym_full')) {
    function coop_term_kym_full(?bool $en = null): string
    {
        $en = $en ?? coop_is_en();
        return $en ? 'Know Your Member (KYM)' : 'सदस्य पहिचान (केवाइएम)';
    }
}

/** Online form page title */
if (!function_exists('coop_term_kym_online')) {
    function coop_term_kym_online(?bool $en = null): string
    {
        $en = $en ?? coop_is_en();
        return $en ? 'Online KYM Form' : 'अनलाइन केवाइएम फारम';
    }
}

/** Applications list / admin nav */
if (!function_exists('coop_term_kym_apps')) {
    function coop_term_kym_apps(?bool $en = null): string
    {
        $en = $en ?? coop_is_en();
        return $en ? 'KYM Applications' : 'केवाइएम आवेदन';
    }
}

/** Single office: सेवा कार्यालय / Service Office */
if (!function_exists('coop_term_office')) {
    function coop_term_office(?bool $en = null): string
    {
        $en = $en ?? coop_is_en();
        return $en ? 'Service Office' : 'सेवा कार्यालय';
    }
}

/** Plural offices */
if (!function_exists('coop_term_offices')) {
    function coop_term_offices(?bool $en = null): string
    {
        $en = $en ?? coop_is_en();
        return $en ? 'Service Offices' : 'सेवा कार्यालयहरू';
    }
}

/** Preferred office field label */
if (!function_exists('coop_term_office_preferred')) {
    function coop_term_office_preferred(?bool $en = null): string
    {
        $en = $en ?? coop_is_en();
        return $en ? 'Preferred Service Office' : 'मनपर्ने सेवा कार्यालय';
    }
}

/** Select placeholder */
if (!function_exists('coop_term_office_select')) {
    function coop_term_office_select(?bool $en = null): string
    {
        $en = $en ?? coop_is_en();
        return $en ? 'Select Service Office' : 'सेवा कार्यालय छान्नुहोस्';
    }
}
