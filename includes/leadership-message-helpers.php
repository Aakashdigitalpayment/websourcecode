<?php
/**
 * Shared chairman / CEO message loader (settings + team_members fallback).
 */
if (!function_exists('coop_load_leadership_messages')) {
    /**
     * @return array{
     *   chairman_name:string,chairman_photo:string,chairman_message:string,
     *   ceo_name:string,ceo_photo:string,ceo_message:string,
     *   ceo_designation_np:string,ceo_designation_en:string
     * }
     */
    function coop_load_leadership_messages(?PDO $db = null): array
    {
        $en = function_exists('isEnglish') && isEnglish();

        $chairmanName = trim((string) getSetting('chairman_name', ''));
        $chairmanPhoto = trim((string) getSetting('chairman_photo', ''));
        $chairmanMessage = trim((string) getSetting(
            $en ? 'chairman_message_en' : 'chairman_message_np',
            getSetting('chairman_message_np', '')
        ));
        if ($chairmanMessage === '' && $en) {
            $chairmanMessage = trim((string) getSetting('chairman_message_np', ''));
        }

        $ceoName = trim((string) getSetting('ceo_name', ''));
        $ceoPhoto = trim((string) getSetting('ceo_photo', ''));
        $ceoMessage = trim((string) getSetting(
            $en ? 'ceo_message_en' : 'ceo_message_np',
            getSetting('ceo_message_np', '')
        ));
        if ($ceoMessage === '' && $en) {
            $ceoMessage = trim((string) getSetting('ceo_message_np', ''));
        }

        $ceoDesignationNp = trim((string) getSetting('ceo_designation_np', 'प्रमुख कार्यकारी अधिकृत'));
        $ceoDesignationEn = trim((string) getSetting('ceo_designation_en', 'Chief Executive Officer'));
        $chairmanDesignationNp = trim((string) getSetting('chairman_designation_np', 'अध्यक्ष'));
        $chairmanDesignationEn = trim((string) getSetting('chairman_designation_en', 'Chairman'));

        try {
            if (!$db && function_exists('getDB')) {
                $db = getDB();
            }
            $needChair = $chairmanName === '' || $chairmanMessage === '';
            $needCeo = $ceoName === '' || $ceoMessage === '';
            if (($needChair || $needCeo) && $db instanceof PDO) {
                $leaders = $db->query(
                    "SELECT * FROM team_members WHERE is_active = 1 AND (is_chairman = 1 OR is_ceo = 1) LIMIT 4"
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $chairFromTeam = null;
                $ceoFromTeam = null;
                foreach ($leaders as $lm) {
                    if ($chairFromTeam === null && !empty($lm['is_chairman'])) {
                        $chairFromTeam = $lm;
                    }
                    if ($ceoFromTeam === null && !empty($lm['is_ceo'])) {
                        $ceoFromTeam = $lm;
                    }
                }
                if ($needChair && $chairFromTeam) {
                    if ($chairmanName === '') {
                        $chairmanName = $en
                            ? (string) (($chairFromTeam['name_en'] ?? '') ?: ($chairFromTeam['name'] ?? '') ?: ($chairFromTeam['name_np'] ?? ''))
                            : (string) (($chairFromTeam['name_np'] ?? '') ?: ($chairFromTeam['name'] ?? '') ?: ($chairFromTeam['name_en'] ?? ''));
                    }
                    if ($chairmanPhoto === '') {
                        $chairmanPhoto = (string) ($chairFromTeam['photo'] ?? '');
                    }
                    if ($chairmanMessage === '') {
                        $chairmanMessage = $en
                            ? (string) (($chairFromTeam['position_en'] ?? '') ?: ($chairFromTeam['position'] ?? '') ?: ($chairFromTeam['position_np'] ?? ''))
                            : (string) (($chairFromTeam['position_np'] ?? '') ?: ($chairFromTeam['position'] ?? '') ?: ($chairFromTeam['position_en'] ?? ''));
                    }
                }
                if ($needCeo && $ceoFromTeam) {
                    if ($ceoName === '') {
                        $ceoName = $en
                            ? (string) (($ceoFromTeam['name_en'] ?? '') ?: ($ceoFromTeam['name'] ?? '') ?: ($ceoFromTeam['name_np'] ?? ''))
                            : (string) (($ceoFromTeam['name_np'] ?? '') ?: ($ceoFromTeam['name'] ?? '') ?: ($ceoFromTeam['name_en'] ?? ''));
                    }
                    if ($ceoPhoto === '') {
                        $ceoPhoto = (string) ($ceoFromTeam['photo'] ?? '');
                    }
                    if ($ceoMessage === '') {
                        $ceoMessage = $en
                            ? (string) (($ceoFromTeam['position_en'] ?? '') ?: ($ceoFromTeam['position'] ?? '') ?: ($ceoFromTeam['position_np'] ?? ''))
                            : (string) (($ceoFromTeam['position_np'] ?? '') ?: ($ceoFromTeam['position'] ?? '') ?: ($ceoFromTeam['position_en'] ?? ''));
                    }
                }
            }
        } catch (Throwable $e) {
            /* silent */
        }

        return [
            'chairman_name' => $chairmanName !== '' ? $chairmanName : ($en ? $chairmanDesignationEn : $chairmanDesignationNp),
            'chairman_photo' => $chairmanPhoto,
            'chairman_message' => $chairmanMessage,
            'chairman_designation_np' => $chairmanDesignationNp !== '' ? $chairmanDesignationNp : 'अध्यक्ष',
            'chairman_designation_en' => $chairmanDesignationEn !== '' ? $chairmanDesignationEn : 'Chairman',
            'ceo_name' => $ceoName !== '' ? $ceoName : ($en ? $ceoDesignationEn : $ceoDesignationNp),
            'ceo_photo' => $ceoPhoto,
            'ceo_message' => $ceoMessage,
            'ceo_designation_np' => $ceoDesignationNp !== '' ? $ceoDesignationNp : 'प्रमुख कार्यकारी अधिकृत',
            'ceo_designation_en' => $ceoDesignationEn !== '' ? $ceoDesignationEn : 'Chief Executive Officer',
        ];
    }
}
