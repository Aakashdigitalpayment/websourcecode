<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * सहकारी पात्रो — Sahakari Patro  v2.1 (project integrated)
 * ══════════════════════════════════════════════════════════════════════════════
 * Live Kathmandu-day panchanga / rashifal / lagna / nakshatra / gunmilan / jyotish.
 * No admin CMS updates — data recalculates from astronomy + BS calendar map.
 * Reference adapted from external tool build; uses includes/nepali-bs-convert.php.
 * ══════════════════════════════════════════════════════════════════════════════
 */
require_once __DIR__ . '/_bootstrap.php';
require_once 'includes/nepali-bs-convert.php';

$pageTitle       = isEnglish() ? 'Sahakari Patro' : 'सहकारी पात्रो';
$pageDescription = isEnglish()
    ? 'Nepali Patro with Calendar, Rashifal, Lagna, Nakshatra, Gunmilan and Jyotish'
    : 'नेपाली पञ्चाङ्ग पात्रो — राशिफल, लग्न, नक्षत्र, गुणमिलन र ज्योतिष';

/* ── Safe getSetting fallback ───────────────────────────────────────────── */
function sp_setting(string $k, string $d = ''): string {
    return function_exists('getSetting') ? (string)getSetting($k, $d) : $d;
}
$spSiteName   = sp_setting('site_name', defined('SITE_NAME') ? (string)SITE_NAME : 'सहकारी');
$spSiteNameEn = sp_setting('site_name_en', $spSiteName);
$spLogo       = sp_setting('site_logo', '');
if ($spLogo === '') {
    $spLogo = sp_setting('logo', 'assets/images/logo.png');
}
if ($spLogo === '') {
    $spLogo = 'assets/images/logo.png';
}
$spLogoUrl = rtrim(defined('SITE_URL') ? (string)SITE_URL : '', '/') . '/' . ltrim($spLogo, '/');

/* ══════════════════════════════════════════════════════════════════════════
   BS MONTH HELPERS — use project converter map (1970–2100), live each day
══════════════════════════════════════════════════════════════════════════ */
$SP_BS_MONTHS_NP = ['बैशाख','जेठ','असार','श्रावण','भाद्र','आश्विन','कार्तिक','मंसिर','पौष','माघ','फाल्गुन','चैत्र'];
$SP_BS_MONTHS_EN = ['Baishakh','Jestha','Ashadh','Shrawan','Bhadra','Ashwin','Kartik','Mangsir','Poush','Magh','Falgun','Chaitra'];
$SP_AD_MONTHS_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$SP_VAARS_NP  = ['आइत','सोम','मंगल','बुध','बिहि','शुक्र','शनि'];
$SP_BS_YEAR_MIN = 1970;
$SP_BS_YEAR_MAX = 2100;

function sp_bs_month_days(int $bY): array {
    if (function_exists('nepali_bs_month_lens')) {
        try {
            $lens = nepali_bs_month_lens($bY);
            if (is_array($lens) && count($lens) === 12) {
                return array_map('intval', array_values($lens));
            }
        } catch (Throwable $e) { /* fall through */ }
    }
    return [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30];
}

/* ══════════════════════════════════════════════════════════════════════════
   ASTRONOMICAL FUNCTIONS — live from sky math (Kathmandu day), not admin CMS
══════════════════════════════════════════════════════════════════════════ */
function sp_jdn(int $y, int $m, int $d): float {
    $a = intdiv(14-$m, 12);
    $yr = $y+4800-$a; $mo = $m+12*$a-3;
    return $d + intdiv(153*$mo+2,5) + 365*$yr + intdiv($yr,4) - intdiv($yr,100) + intdiv($yr,400) - 32045;
}
function sp_sun_moon(float $jd): array {
    $n = $jd - 2451545.0;
    $L = fmod(280.460 + 0.9856474*$n, 360);
    $g = fmod(357.528 + 0.9856003*$n, 360); if($g<0)$g+=360; $gr=deg2rad($g);
    $sun = fmod($L + 1.915*sin($gr) + 0.020*sin(2*$gr), 360); if($sun<0)$sun+=360;
    $mL = fmod(218.316+13.176396*$n,360); $mM=fmod(134.963+13.064993*$n,360); $mF=fmod(93.272+13.229350*$n,360);
    $moon = fmod($mL+6.289*sin(deg2rad($mM))-1.274*sin(deg2rad(2*$mF-$mM))+0.658*sin(deg2rad(2*$mF))-0.214*sin(deg2rad(2*$mM))-0.186*sin($gr)-0.114*sin(deg2rad(2*$mF)),360);
    if($moon<0)$moon+=360;
    return [$sun,$moon];
}

function sp_bs_to_ad(int $bY, int $bM, int $bD): array {
    $bY = max(1970, min(2100, $bY));
    $bM = max(1, min(12, $bM));
    $md = sp_bs_month_days($bY);
    $bD = max(1, min((int)$md[$bM - 1], $bD));
    if (function_exists('nepali_bs_to_ad_string')) {
        try {
            $ad = nepali_bs_to_ad_string(sprintf('%04d-%02d-%02d', $bY, $bM, $bD));
            if (is_string($ad) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ad, $m)) {
                return [(int)$m[1], (int)$m[2], (int)$m[3]];
            }
        } catch (Throwable $e) { /* fall through */ }
    }
    /* Safe last resort — keep page alive rather than crash */
    try {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kathmandu'));
        return [(int)$now->format('Y'), (int)$now->format('n'), (int)$now->format('j')];
    } catch (Throwable $e) {
        return [(int)date('Y'), (int)date('n'), (int)date('j')];
    }
}
function sp_bs_weekday(int $bY, int $bM, int $bD): int {
    [$y,$m,$d]=sp_bs_to_ad($bY,$bM,$bD);
    return (int)((sp_jdn($y,$m,$d)+1)%7);
}
function sp_np(int $n): string {
    return str_replace(['0','1','2','3','4','5','6','7','8','9'],
        ['०','१','२','३','४','५','६','७','८','९'], (string)$n);
}
function sp_fmt_min(int $m): string {
    return sprintf('%s:%s बजे', sp_np(intdiv($m,60)), str_pad(sp_np($m%60),3,'०',STR_PAD_LEFT));
}

/* ══════════════════════════════════════════════════════════════════════════
   FULL PANCHANGA
══════════════════════════════════════════════════════════════════════════ */
function sp_panchanga(int $y, int $m, int $d): array {
    global $SP_BS_MONTHS_NP;
    $tithiNames=['प्रतिपदा','द्वितीया','तृतीया','चतुर्थी','पञ्चमी','षष्ठी','सप्तमी','अष्टमी','नवमी','दशमी','एकादशी','द्वादशी','त्रयोदशी','चतुर्दशी','पूर्णिमा','प्रतिपदा','द्वितीया','तृतीया','चतुर्थी','पञ्चमी','षष्ठी','सप्तमी','अष्टमी','नवमी','दशमी','एकादशी','द्वादशी','त्रयोदशी','चतुर्दशी','औंसी'];
    $tithiShort=['प्र','द्वि','तृ','च','पं','ष','स','अ','न','द','ए','द्वा','त्र','च','पू','प्र','द्वि','तृ','च','पं','ष','स','अ','न','द','ए','द्वा','त्र','च','औ'];
    $nakNp=['अश्विनी','भरणी','कृत्तिका','रोहिणी','मृगशिरा','आर्द्रा','पुनर्वसु','पुष्य','आश्लेषा','मघा','पू.फाल्.','उ.फाल्.','हस्त','चित्रा','स्वाति','विशाखा','अनुराधा','ज्येष्ठा','मूल','पू.षा.','उ.षा.','श्रवण','धनिष्ठा','शतभिषा','पू.भा.','उ.भा.','रेवती'];
    $yogaNp=['विष्कुम्भ','प्रीति','आयुष्मान','सौभाग्य','शोभन','अतिगण्ड','सुकर्म','धृति','शूल','गण्ड','वृद्धि','ध्रुव','व्याघात','हर्षण','वज्र','सिद्धि','व्यतीपात','वरीयान','परिघ','शिव','सिद्ध','साध्य','शुभ','शुक्ल','ब्रह्म','ऐन्द्र','वैधृति'];
    $karNp=['बव','बालव','कौलव','तैतिल','गरज','वणिज','विष्टि','शकुनि','चतुष्पाद','नाग','किंस्तुघ्न'];
    $samv=['प्रभव','विभव','शुक्ल','प्रमोद','प्रजापति','अंगिरा','श्रीमुख','भाव','युव','धाता','ईश्वर','बहुधान्य','प्रमाथी','विक्रम','वृष','चित्रभानु','स्वभानु','तारण','पार्थिव','व्यय','सर्वजित','सर्वधारी','विरोधी','विकृति','खर','नन्दन','विजय','जय','मन्मथ','दुर्मुख','हेविलम्बी','विलम्बी','विकारी','शार्वरी','प्लव','शुभकृत','शोभन','क्रोधी','विश्वावसु','पराभव','प्लवंग','कीलक','सौम्य','साधारण','विरोधकृत','परिधावी','प्रमादीच','आनन्द','राक्षस','नल','पिंगल','काल','सिद्धार्थ','रौद्र','दुर्मति','दुन्दुभी','रुधिरोद्गारी','रक्ताक्षी','क्रोधन','अक्षय'];
    $vaarsNp=['आइतबार','सोमबार','मंगलबार','बुधबार','बिहिबार','शुक्रबार','शनिबार'];
    $bsStr=function_exists('nepali_ad_to_bs_string')?nepali_ad_to_bs_string(sprintf('%04d-%02d-%02d',$y,$m,$d)):'2083-03-29';
    [$bY,$bM,$bD]=array_map('intval',explode('-',$bsStr));
    $jd=sp_jdn($y,$m,$d)+0.5;
    [$sunL,$moonL]=sp_sun_moon($jd);
    $elong=fmod($moonL-$sunL+360,360);
    $ti=(int)floor($elong/12);
    $nkIdx=(int)floor($moonL/(360.0/27));
    $yogaIdx=(int)floor(fmod($sunL+$moonL,360)/(360.0/27));
    $karIdx=(int)floor($elong/6)%11;
    $wd=(int)date('w',mktime(0,0,0,$m,$d,$y));
    $doy=(int)date('z',mktime(0,0,0,$m,$d,$y));
    $srMin=312-(int)(15*sin(deg2rad(($doy-80)*360/365)));
    $ssMin=min(max($srMin+366+2*(int)(15*sin(deg2rad(($doy-80)*360/365))),960),1170);
    $rahuMins=[465,450,435,750,480,630,540];
    $gulikMins=[780,480,1050,570,1110,660,750];
    $rm=$rahuMins[$wd]; $gm=$gulikMins[$wd];
    $mid=(int)(($srMin+$ssMin)/2);
    $rituNp=['वसन्त','वसन्त','ग्रीष्म','ग्रीष्म','वर्षा','वर्षा','शरद','शरद','हेमन्त','हेमन्त','शिशिर','शिशिर'];
    return [
        'bs_year'=>$bY,'bs_month'=>$bM,'bs_day'=>$bD,
        'bs_year_np'=>sp_np($bY),'bs_month_np'=>sp_np($bM),'bs_day_np'=>sp_np($bD),
        'bs_month_name'=>$SP_BS_MONTHS_NP[$bM-1],
        'ad_date'=>date('F j, Y',mktime(0,0,0,$m,$d,$y)),
        'vaar_np'=>$vaarsNp[$wd],'vaar_short'=>$SP_VAARS_NP[$wd]??$vaarsNp[$wd],'wd'=>$wd,
        'tithi'=>$tithiNames[$ti],'tithi_short'=>$tithiShort[$ti],'ti'=>$ti,
        'paksha'=>$ti<15?'शुक्ल पक्ष':'कृष्ण पक्ष',
        'nakshatra'=>$nakNp[$nkIdx],'nk_idx'=>$nkIdx,
        'yoga'=>$yogaNp[$yogaIdx%27],'karana'=>$karNp[$karIdx],
        'samvatsar'=>$samv[($bY-1)%60],
        'ritu'=>$rituNp[$bM-1].' ऋतु',
        'ayana'=>$m>=7?'उत्तरायण':'दक्षिणायन',
        'masa'=>$SP_BS_MONTHS_NP[$bM-1],
        'sunrise'=>sp_fmt_min($srMin),'sunset'=>sp_fmt_min($ssMin),
        'rahu_kaal'=>sp_fmt_min($rm).' – '.sp_fmt_min($rm+90),
        'abhijit'=>sp_fmt_min($mid-24).' – '.sp_fmt_min($mid+24),
        'gulik'=>sp_fmt_min($gm).' – '.sp_fmt_min($gm+90),
        'moon_rashi'=>(int)floor($moonL/30),'sun_rashi'=>(int)floor($sunL/30),
        'moon_long'=>$moonL,'sun_long'=>$sunL,
    ];
}

/* ══════════════════════════════════════════════════════════════════════════
   FESTIVAL DATA  (2082–2083)
══════════════════════════════════════════════════════════════════════════ */
$SP_FESTIVALS = [
    '2082-1-1'  =>[['name'=>'नेपाली नयाँ वर्ष','type'=>'holiday']],
    '2082-1-15' =>[['name'=>'बुद्ध जयन्ती','type'=>'festival']],
    '2082-4-15' =>[['name'=>'जनै पूर्णिमा','type'=>'festival']],
    '2082-5-29' =>[['name'=>'कृष्ण जन्माष्टमी','type'=>'festival']],
    '2082-6-1'  =>[['name'=>'घटस्थापना','type'=>'holiday']],
    '2082-6-7'  =>[['name'=>'फूलपाती','type'=>'festival']],
    '2082-6-8'  =>[['name'=>'महाअष्टमी','type'=>'festival']],
    '2082-6-9'  =>[['name'=>'महानवमी','type'=>'festival']],
    '2082-6-10' =>[['name'=>'विजया दशमी','type'=>'holiday']],
    '2082-7-13' =>[['name'=>'लक्ष्मी पूजा','type'=>'holiday']],
    '2082-7-14' =>[['name'=>'गोवर्धन पूजा','type'=>'holiday']],
    '2082-7-15' =>[['name'=>'भाइटीका','type'=>'holiday']],
    '2082-7-19' =>[['name'=>'छठ पर्व','type'=>'festival']],
    '2082-8-5'  =>[['name'=>'विवाह पञ्चमी','type'=>'festival']],
    '2082-10-1' =>[['name'=>'माघे संक्रान्ति','type'=>'holiday']],
    '2082-11-14'=>[['name'=>'महाशिवरात्रि','type'=>'holiday']],
    '2082-11-27'=>[['name'=>'होली','type'=>'holiday']],
    '2082-12-9' =>[['name'=>'राम नवमी','type'=>'festival']],
    '2083-1-1'  =>[['name'=>'नेपाली नयाँ वर्ष','type'=>'holiday']],
    '2083-1-15' =>[['name'=>'बुद्ध जयन्ती','type'=>'festival']],
    '2083-2-15' =>[['name'=>'जेष्ठपूर्णिमा','type'=>'festival']],
    '2083-3-1'  =>[['name'=>'हरिशयनी एकादशी','type'=>'religious']],
    '2083-3-15' =>[['name'=>'गुरु पूर्णिमा','type'=>'festival']],
    '2083-3-29' =>[['name'=>'नाग पञ्चमी','type'=>'festival']],
    '2083-4-3'  =>[['name'=>'हरितालिका तीज','type'=>'festival']],
    '2083-4-4'  =>[['name'=>'ऋषि पञ्चमी','type'=>'festival']],
    '2083-4-15' =>[['name'=>'जनै पूर्णिमा','type'=>'festival']],
    '2083-4-18' =>[['name'=>'गाईजात्रा','type'=>'festival']],
    '2083-5-1'  =>[['name'=>'इन्द्रजात्रा','type'=>'festival']],
    '2083-5-29' =>[['name'=>'कृष्ण जन्माष्टमी','type'=>'festival']],
    '2083-6-1'  =>[['name'=>'घटस्थापना','type'=>'holiday']],
    '2083-6-7'  =>[['name'=>'फूलपाती','type'=>'festival']],
    '2083-6-8'  =>[['name'=>'महाअष्टमी','type'=>'festival']],
    '2083-6-9'  =>[['name'=>'महानवमी','type'=>'festival']],
    '2083-6-10' =>[['name'=>'विजया दशमी','type'=>'holiday']],
    '2083-6-14' =>[['name'=>'लक्ष्मी पूजा','type'=>'holiday']],
    '2083-6-15' =>[['name'=>'कोजाग्रत पूर्णिमा','type'=>'festival']],
    '2083-7-1'  =>[['name'=>'भाइटीका','type'=>'holiday']],
    '2083-7-3'  =>[['name'=>'छठ पर्व','type'=>'festival']],
    '2083-10-1' =>[['name'=>'माघे संक्रान्ति','type'=>'holiday']],
    '2083-11-15'=>[['name'=>'महाशिवरात्रि','type'=>'holiday']],
    '2083-12-9' =>[['name'=>'राम नवमी','type'=>'festival']],
    '2084-1-1'  =>[['name'=>'नेपाली नयाँ वर्ष','type'=>'holiday']],
];

function sp_get_events(int $bY, int $bM, int $bD, int $ti): array {
    global $SP_FESTIVALS;
    $ev=$SP_FESTIVALS["$bY-$bM-$bD"]??[];
    if($ti===14) $ev[]=['name'=>'पूर्णिमा','type'=>'purnima'];
    if($ti===29) $ev[]=['name'=>'औंसी','type'=>'aunsi'];
    if($ti===10||$ti===25) $ev[]=['name'=>'एकादशी','type'=>'ekadashi'];
    if($ti===7||$ti===22)  $ev[]=['name'=>'अष्टमी','type'=>'ashtami'];
    if($ti===3||$ti===18)  $ev[]=['name'=>'चतुर्थी','type'=>'chaturthi'];
    return $ev;
}
function sp_ev_bg(string $type): string {
    return ['purnima'=>'#eff6ff','aunsi'=>'#f3f4f6','ekadashi'=>'#f5f3ff','ashtami'=>'#ecfeff','chaturthi'=>'#fef3c7','holiday'=>'#fef2f2','festival'=>'#fff7ed','religious'=>'#dcfce7'][$type]??'#dcfce7';
}
function sp_ev_color(string $type): string {
    return ['purnima'=>'#3b82f6','aunsi'=>'#374151','ekadashi'=>'#7c3aed','ashtami'=>'#0891b2','chaturthi'=>'#d97706','holiday'=>'#dc2626','festival'=>'#ea580c','religious'=>'#16a34a'][$type]??'#16a34a';
}

/* Calendar builder */
function sp_calendar_cells(int $bY, int $bM): array {
    global $SP_AD_MONTHS_SHORT;
    $md = sp_bs_month_days($bY);
    $days=$md[$bM-1];
    $firstWd=sp_bs_weekday($bY,$bM,1);
    $cells=array_fill(0,$firstWd,null);
    $shubhTithis=[0,2,4,7,9,10,12,14];
    for($dd=1;$dd<=$days;$dd++){
        [$adY,$adM,$adD]=sp_bs_to_ad($bY,$bM,$dd);
        $jd=sp_jdn($adY,$adM,$adD)+0.5;
        [$sun,$moon]=sp_sun_moon($jd);
        $elong=fmod($moon-$sun+360,360);
        $ti=(int)floor($elong/12);
        $wd=(int)((sp_jdn($adY,$adM,$adD)+1)%7);
        $evs=sp_get_events($bY,$bM,$dd,$ti);
        $cells[]=['d'=>$dd,'ad_d'=>$adD,'ad_ms'=>$SP_AD_MONTHS_SHORT[$adM-1],'ti'=>$ti,'wd'=>$wd,'shubh'=>in_array($ti,$shubhTithis),'evs'=>$evs];
    }
    while(count($cells)%7!==0) $cells[]=null;
    return $cells;
}

/* ══════════════════════════════════════════════════════════════════════════
   REQUEST HANDLING
══════════════════════════════════════════════════════════════════════════ */
$tz  = new DateTimeZone('Asia/Kathmandu');
$now = new DateTimeImmutable('now', $tz);
$todayAD = [(int)$now->format('Y'),(int)$now->format('n'),(int)$now->format('j')];
$pg = sp_panchanga(...$todayAD);

$activeTab = in_array($_GET['tab']??'',['rashifal','lagna','gunmilan','muhurta','jyotish'])?$_GET['tab']:'patro';
$rfPeriod  = in_array($_GET['rf']??'',['monthly','yearly'])?$_GET['rf']:'daily';

/* Calendar month navigation */
$calY = isset($_GET['cal_year'])  ? max($SP_BS_YEAR_MIN, min($SP_BS_YEAR_MAX, (int)$_GET['cal_year']))  : $pg['bs_year'];
$calM = isset($_GET['cal_month']) ? max(1,min(12,(int)$_GET['cal_month']))       : $pg['bs_month'];
$selD = isset($_GET['sel_day'])   ? max(1,(int)$_GET['sel_day'])                 : ($calY===$pg['bs_year']&&$calM===$pg['bs_month']?$pg['bs_day']:1);
$calMd= sp_bs_month_days($calY)[$calM-1];
$selD = min($selD, $calMd);

/* Prev/Next month */
$prevY=$calM===1?max($SP_BS_YEAR_MIN,$calY-1):$calY; $prevM=$calM===1?12:$calM-1;
$nextY=$calM===12?min($SP_BS_YEAR_MAX,$calY+1):$calY; $nextM=$calM===12?1:$calM+1;
$baseQ="tab=patro&rf=$rfPeriod";

/* Selected day panchanga */
$selAD = sp_bs_to_ad($calY,$calM,$selD);
$selPg = sp_panchanga(...$selAD);
$selEvs = sp_get_events($calY,$calM,$selD,$selPg['ti']);

/* Calendar cells */
$calCells = sp_calendar_cells($calY,$calM);

/* AD range label for month header */
$firstAd=sp_bs_to_ad($calY,$calM,1);
$lastAd=sp_bs_to_ad($calY,$calM,$calMd);
$adRangeLabel=$SP_AD_MONTHS_SHORT[$firstAd[1]-1].' '.$firstAd[0].' – '.$SP_AD_MONTHS_SHORT[$lastAd[1]-1].' '.$lastAd[0];

/* Next month mini-calendar for "upcoming" */
$nextCalCells=sp_calendar_cells($nextY,$nextM);
$nextMd=sp_bs_month_days($nextY)[$nextM-1];

/* Rashifal data */
$rashiNames=['मेष','वृष','मिथुन','कर्कट','सिंह','कन्या','तुला','वृश्चिक','धनु','मकर','कुम्भ','मीन'];
$rashiSym=['♈','♉','♊','♋','♌','♍','♎','♏','♐','♑','♒','♓'];
$rashiLords=['मंगल','शुक्र','बुध','चन्द्र','सूर्य','बुध','शुक्र','मंगल','बृहस्पति','शनि','शनि','बृहस्पति'];
$rashiEl=['अग्नि','पृथ्वी','वायु','जल','अग्नि','पृथ्वी','वायु','जल','अग्नि','पृथ्वी','वायु','जल'];
$rashiColors=['#ef4444','#84cc16','#f59e0b','#06b6d4','#f97316','#10b981','#8b5cf6','#dc2626','#7c3aed','#374151','#0284c7','#0d9488'];
$dailyPreds=["व्यापार-व्यवसायमा सफलता। आर्थिक अवसरहरू बढ्नेछन्। परिवारसंगको सम्बन्ध सुमधुर। दिउँसोपछि विशेष शुभ।","सामाजिक कार्यमा यश मिल्नेछ। मित्रहरूको सहयोग प्राप्त। नयाँ सम्बन्ध बन्नेछ। आर्थिक स्थिति सुधार।","स्वास्थ्यमा सतर्कता आवश्यक। बौद्धिक कार्यमा प्रगति। अध्ययन-अनुसन्धानमा सफलता। बिहान विशेष शुभ।","आय-आर्जनमा वृद्धि। रोकिएका काम बन्नेछन्। प्रेम सम्बन्धमा मिठास। नयाँ मित्रता सम्भव।","सरकारी कार्यमा अनुकूल। प्रशासनिक विषयमा सफलता। यात्रा योजना राम्रो। नेतृत्व क्षमता प्रकट।","धार्मिक-आध्यात्मिक कार्यमा मन लाग्नेछ। दान-पुण्यका लागि शुभ दिन। परिवारमा खुशी।","कलात्मक कार्यमा सृजनशीलता बढ्नेछ। मनोरञ्जन र विश्रामको अवसर। प्रेम जीवनमा मिठास।","व्यापारिक वार्तामा सफलता। सम्झौता र करारका लागि अनुकूल। साझेदारी लाभदायक।","परिश्रमको फल मिल्नेछ। दीर्घकालीन परियोजनामा प्रगति। बचत बढ्नेछ। स्वास्थ्य राम्रो।","नेतृत्व क्षमता प्रकट हुनेछ। अधीनस्थहरूको विश्वास जित्नेछ। करियरमा उन्नति।","नयाँ सीप सिक्ने अवसर। शैक्षिक कार्यमा उपलब्धि। ज्ञान-विज्ञानमा रुचि। संचार राम्रो।","पारिवारिक सुख-शान्ति। घर-जग्गाका विषयमा अनुकूल निर्णय। सम्पत्तिमा लाभ।"];
$monthlyPreds=["यो महिना व्यापारिक विस्तारका लागि उत्तम। बैंकिङ तथा वित्तीय कार्यमा सफलता। मध्यमतिरको भाग अझ राम्रो।","पारिवारिक जीवनमा स्थिरता। नयाँ सम्पत्ति आर्जन सम्भव। वाणिज्यिक क्षेत्रमा नयाँ साझेदारी।","संचार र यात्राका कार्य बढ्नेछन्। छोटो यात्राहरू फलदायी। मीडिया तथा प्रकाशनमा लाभ।","घर-गृहस्थीमा सुधार। आमा-परिवारसंगको सम्बन्ध मजबुत। अचल सम्पत्तिमा लगानी अनुकूल।","करियर र मनोरञ्जनमा उन्नति। प्रेम-सम्बन्धमा नयाँ अध्याय। सृजनात्मक कार्यमा पुरस्कार।","स्वास्थ्यमा विशेष ध्यान दिनुहोस्। सेवा र परोपकारका कार्य फलदायी। सहकर्मीसंगको सम्बन्ध राम्रो।","विवाह-साझेदारीका विषयमा अनुकूल महिना। कानूनी विवादमा समझौता सम्भव। व्यावसायिक सम्बन्ध मजबुत।","गहन अनुसन्धान र परिवर्तनको महिना। लुकेका सम्पत्ति प्रकट हुनेछन्। आध्यात्मिक अनुभव गहिरो।","उच्च शिक्षा र विदेश योजनाका लागि अनुकूल। धर्म-दर्शनमा रुचि। दीर्घ यात्रामा सफलता।","करियर र सामाजिक प्रतिष्ठामा उन्नति। सरकारी/प्रशासनिक सहयोग प्राप्त। व्यावसायिक लक्ष्य हासिल।","मित्र-समूहसंगको सम्बन्ध मजबुत। सामाजिक संजालमा विस्तार। आय-स्रोतमा विविधता।","एकान्त र ध्यानको महिना। आध्यात्मिक उन्नति। गुप्त शत्रुबाट सावधान। स्वास्थ्यमा सतर्कता।"];
$yearlyPreds=["मेष: २०८३ व्यापार र आर्थिक उन्नतिको दृष्टिले उत्कृष्ट। बृहस्पतिको दृष्टिले करियरमा ठूलो अवसर। माघदेखि असार विशेष शुभ।","वृष: शनिको प्रभावले मेहनत र धैर्यको परीक्षण। तर अन्त्यमा सकारात्मक। सम्पत्ति र बचतमा वृद्धि।","मिथुन: बुधको अनुकूल स्थितिले संचार, व्यापार र शिक्षामा उत्कृष्ट वर्ष। छोटो यात्राहरू लाभदायक।","कर्कट: चन्द्रको प्रभावले भावनात्मक उतारचढाव। माघदेखि सुधार। घर-परिवारमा आनन्द।","सिंह: सूर्यको बलिया स्थितिले नेतृत्व र प्रतिष्ठामा उन्नति। सरकारी कार्यमा सफलता। स्वास्थ्य राम्रो।","कन्या: बुधको दोहोरो प्रभावले विश्लेषण र सेवा क्षेत्रमा उत्कृष्ट। पदोन्नतिको सम्भावना।","तुला: शुक्रको अनुकूल स्थितिले प्रेम, कला र सौन्दर्यमा वर्ष राम्रो। विवाह वा साझेदारीका लागि शुभ।","वृश्चिक: मंगलको दोहोरो प्रभावले साहस र ऊर्जामा वृद्धि। गहन परिवर्तनको वर्ष।","धनु: बृहस्पतिको सकारात्मक दृष्टिले उच्च शिक्षा र विदेश अवसरमा उत्कृष्ट।","मकर: शनिको स्वराशिमा बसाइले दृढ मेहनतको फल। करियर र व्यावसायिक क्षेत्रमा ठूलो उपलब्धि।","कुम्भ: राहुको प्रभावले अप्रत्याशित परिवर्तन। टेक्नोलोजी र नवीन क्षेत्रमा अवसर।","मीन: बृहस्पतिको आफ्नै राशिमा बसाइले आध्यात्मिक र भावनात्मक उन्नतिको वर्ष।"];
$doy_np=(int)$now->format('z');

/* Nakshatra full list */
$nakshatraAll=[
    ['name'=>'अश्विनी','lord'=>'केतु','rashi'=>'मेष','char'=>'चु,चे,चो,ला','qual'=>'शीघ्र, चतुर, चिकित्सा प्रेमी'],
    ['name'=>'भरणी','lord'=>'शुक्र','rashi'=>'मेष','char'=>'ली,लू,ले,लो','qual'=>'सत्यवादी, कर्मठ, सुन्दर'],
    ['name'=>'कृत्तिका','lord'=>'सूर्य','rashi'=>'वृष','char'=>'अ,इ,उ,ए','qual'=>'दृढनिश्चयी, नेतृत्वकारी'],
    ['name'=>'रोहिणी','lord'=>'चन्द्र','rashi'=>'वृष','char'=>'ओ,वा,वी,वू','qual'=>'सौम्य, कलाप्रिय, समृद्ध'],
    ['name'=>'मृगशिरा','lord'=>'मंगल','rashi'=>'मिथुन','char'=>'वे,वो,का,की','qual'=>'जिज्ञासु, यात्री, चतुर'],
    ['name'=>'आर्द्रा','lord'=>'राहु','rashi'=>'मिथुन','char'=>'कु,घ,ङ,छ','qual'=>'संवेदनशील, खोजी, तीव्र'],
    ['name'=>'पुनर्वसु','lord'=>'बृहस्पति','rashi'=>'कर्कट','char'=>'के,को,ह,ही','qual'=>'धार्मिक, उदार, भाग्यशाली'],
    ['name'=>'पुष्य','lord'=>'शनि','rashi'=>'कर्कट','char'=>'हु,हे,हो,ड','qual'=>'पोषणकारी, सहयोगी, शुभ'],
    ['name'=>'आश्लेषा','lord'=>'बुध','rashi'=>'कर्कट','char'=>'डी,डू,डे,डो','qual'=>'चतुर, रहस्यमय, महत्वाकांक्षी'],
    ['name'=>'मघा','lord'=>'केतु','rashi'=>'सिंह','char'=>'म,मि,मू,मे','qual'=>'राजसी, परम्परावादी'],
    ['name'=>'पू.फाल्गुनी','lord'=>'शुक्र','rashi'=>'सिंह','char'=>'मो,ट,टी,टू','qual'=>'प्रेमी, कलाकार, विलासी'],
    ['name'=>'उ.फाल्गुनी','lord'=>'सूर्य','rashi'=>'कन्या','char'=>'टे,टो,प,पि','qual'=>'मित्रवत्, परिश्रमी'],
    ['name'=>'हस्त','lord'=>'चन्द्र','rashi'=>'कन्या','char'=>'पू,ष,ण,ठ','qual'=>'कुशल, हाथकाम प्रेमी'],
    ['name'=>'चित्रा','lord'=>'मंगल','rashi'=>'तुला','char'=>'पे,पो,र,री','qual'=>'सृजनशील, आकर्षक'],
    ['name'=>'स्वाति','lord'=>'राहु','rashi'=>'तुला','char'=>'रू,रे,रो,त','qual'=>'स्वतन्त्र, न्यायप्रिय'],
    ['name'=>'विशाखा','lord'=>'बृहस्पति','rashi'=>'वृश्चिक','char'=>'ती,तू,ते,तो','qual'=>'दृढनिश्चयी, महत्वाकांक्षी'],
    ['name'=>'अनुराधा','lord'=>'शनि','rashi'=>'वृश्चिक','char'=>'न,नि,नू,ने','qual'=>'भक्त, मित्रवत्, अनुशासित'],
    ['name'=>'ज्येष्ठा','lord'=>'बुध','rashi'=>'वृश्चिक','char'=>'नो,य,यि,यू','qual'=>'वरिष्ठ, बहादुर'],
    ['name'=>'मूल','lord'=>'केतु','rashi'=>'धनु','char'=>'ये,यो,भ,भि','qual'=>'खोजी, तीक्ष्ण, परिवर्तनशील'],
    ['name'=>'पू.षाढा','lord'=>'शुक्र','rashi'=>'धनु','char'=>'भू,ध,फ,ढ','qual'=>'प्रेरक, दृढ, लोकप्रिय'],
    ['name'=>'उ.षाढा','lord'=>'सूर्य','rashi'=>'मकर','char'=>'भे,भो,ज,जि','qual'=>'विजयी, नैतिक'],
    ['name'=>'श्रवण','lord'=>'चन्द्र','rashi'=>'मकर','char'=>'खि,खू,खे,खो','qual'=>'ज्ञानी, धार्मिक'],
    ['name'=>'धनिष्ठा','lord'=>'मंगल','rashi'=>'कुम्भ','char'=>'ग,गि,गू,गे','qual'=>'सम्पन्न, साहसी'],
    ['name'=>'शतभिषा','lord'=>'राहु','rashi'=>'कुम्भ','char'=>'गो,स,सि,सू','qual'=>'उपचारक, रहस्यमय'],
    ['name'=>'पू.भाद्रपद','lord'=>'बृहस्पति','rashi'=>'मीन','char'=>'से,सो,द,दि','qual'=>'आदर्शवादी, परिवर्तनशील'],
    ['name'=>'उ.भाद्रपद','lord'=>'शनि','rashi'=>'मीन','char'=>'दू,थ,झ,ञ','qual'=>'ज्ञानी, आध्यात्मिक'],
    ['name'=>'रेवती','lord'=>'बुध','rashi'=>'मीन','char'=>'दे,दो,च,चि','qual'=>'पोषणकारी, कलाकार'],
];

/* Guna Milan (POST) */
$gunaResult=null;
if($_SERVER['REQUEST_METHOD']==='POST'
    && isset($_POST['n1'],$_POST['n2'],$_POST['r1'],$_POST['r2'])
    && $_POST['n1']!=='' && $_POST['n2']!=='' && $_POST['r1']!=='' && $_POST['r2']!==''){
    $n1=max(0,min(26,(int)$_POST['n1'])); $n2=max(0,min(26,(int)$_POST['n2']));
    $r1=max(0,min(11,(int)$_POST['r1'])); $r2=max(0,min(11,(int)$_POST['r2']));
    $diff=abs($n1-$n2); $diff=min($diff,27-$diff);
    $ganaMap=[0,0,0,0,1,1,0,0,2,2,1,1,0,2,2,0,0,2,2,1,1,0,2,2,0,0,0];
    $nadiMap=[0,2,1,0,2,1,0,2,1,0,2,1,0,2,1,0,2,1,0,2,1,0,2,1,0,2,1];
    $lords1=['केतु','शुक्र','सूर्य','चन्द्र','मंगल','राहु','बृहस्पति','शनि','बुध','केतु','शुक्र','सूर्य','चन्द्र','मंगल','राहु','बृहस्पति','शनि','बुध','केतु','शुक्र','सूर्य','चन्द्र','मंगल','राहु','बृहस्पति','शनि','बुध'];
    $rasAvoid=[[5,7],[4,8],[11,1],[7,5],[8,4],[1,11]];
    $varna=($n1%3===$n2%3)?1:0;
    $vashya=($r1===$r2)?2:(abs($r1-$r2)<=2?1:0);
    $tn=(($n2-$n1+27)%27); $taraCyc=$tn%9; $tara=in_array($taraCyc,[1,3,5,7])?3:($taraCyc===0?2:1);
    $yoni=($diff<=2)?4:($diff<=5?3:($diff<=9?2:1));
    $grahaM=($lords1[$n1]===$lords1[$n2])?5:(abs($n1-$n2)<=4?4:3);
    $gana=($ganaMap[$n1]===$ganaMap[$n2])?6:(abs($ganaMap[$n1]-$ganaMap[$n2])===1?4:0);
    $bhakuta=7; foreach($rasAvoid as[$a,$b]){if(($r1%12===$a&&$r2%12===$b)||($r1%12===$b&&$r2%12===$a)){$bhakuta=0;break;}}
    $nadi=($nadiMap[$n1]===$nadiMap[$n2])?0:8;
    $total=$varna+$vashya+$tara+$yoni+$grahaM+$gana+$bhakuta+$nadi;
    $verdict=$total>=28?'उत्तम मिलान — विवाहका लागि अत्यन्त शुभ':($total>=18?'मध्यम मिलान — विवाहका लागि स्वीकार्य':'साधारण — ज्योतिषीसँग परामर्श लिनुहोस्');
    $gunaResult=compact('total','varna','vashya','tara','yoni','grahaM','gana','bhakuta','nadi','verdict');
}

/* Lagna (GET) */
$lagnaResult=null;
if(!empty($_GET['birth_date'])&&!empty($_GET['birth_time'])){
    $bdate=$_GET['birth_date']; $btime=$_GET['birth_time'];
    if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$bdate)&&preg_match('/^\d{2}:\d{2}$/',$btime)){
        [$by,$bm,$bd]=array_map('intval',explode('-',$bdate));
        [$bh,$bmin]=array_map('intval',explode(':',$btime));
        $bjdn=sp_jdn($by,$bm,$bd)+($bh+$bmin/60)/24-5.75/24;
        [$bSun,$bMoon]=sp_sun_moon($bjdn);
        $lIdx=((int)(($bh*60+$bmin)/120)+(int)floor($bSun/30))%12;
        $bNkIdx=(int)floor($bMoon/(360.0/27));
        $bBsStr=function_exists('nepali_ad_to_bs_string')?nepali_ad_to_bs_string($bdate):'—';
        $bBsNp=$bBsStr?function_exists('nepali_latin_digits_to_devanagari')?nepali_latin_digits_to_devanagari(str_replace('-','/',$bBsStr)):$bBsStr:'—';
        $lagnaResult=['lagna'=>$rashiNames[$lIdx],'nakshatra'=>$nakshatraAll[$bNkIdx]['name'],'nk_lord'=>$nakshatraAll[$bNkIdx]['lord'],'chandra_rashi'=>$rashiNames[(int)floor($bMoon/30)],'bs_date'=>$bBsNp];
    }
}

/* Navagraha */
$jdnNow=sp_jdn(...$todayAD)+0.5;
[$sL,$mL]=sp_sun_moon($jdnNow);
$n0=sp_jdn(...$todayAD)-2451545;
$grahas=[
    ['name'=>'सूर्य ☀','long'=>$sL,'fal'=>'सरकारी कार्यमा अनुकूल, नेतृत्वमा शक्ति'],
    ['name'=>'चन्द्र ☽','long'=>$mL,'fal'=>'मन र भावनामा सकारात्मकता, परिवारमा सुख'],
    ['name'=>'मंगल ♂','long'=>fmod(355+0.524*$n0,360),'fal'=>'ऊर्जा र साहसमा वृद्धि'],
    ['name'=>'बुध ☿','long'=>fmod($sL+sin(deg2rad(fmod(20.9+$n0*0.85,360)))*23,360),'fal'=>'बुद्धि र व्यापारमा अनुकूल'],
    ['name'=>'बृहस्पति ♃','long'=>fmod(34.4+0.0831*$n0,360),'fal'=>'धर्म र ज्ञानमा शुभ'],
    ['name'=>'शुक्र ♀','long'=>fmod($sL+sin(deg2rad(fmod(215+$n0*1.6,360)))*47,360),'fal'=>'प्रेम र कलामा अनुकूल'],
    ['name'=>'शनि ♄','long'=>fmod(50+0.0335*$n0,360),'fal'=>'मेहनत र अनुशासनबाट फल'],
    ['name'=>'राहु ☊','long'=>fmod(125-0.0529539*$n0+360,360),'fal'=>'आध्यात्मिकतामा वृद्धि'],
    ['name'=>'केतु ☋','long'=>fmod(305-0.0529539*$n0+360,360),'fal'=>'ध्यान र साधनामा अनुकूल'],
];
foreach($grahas as &$gr){
    $gr['long']=fmod($gr['long']+360,360);
    $gr['rashi']=$rashiNames[(int)floor($gr['long']/30)];
    $deg=fmod($gr['long'],30);
    $gr['avastha']=$deg>25?'उच्च':($deg<5?'नेच':'सामान्य');
    $gr['deg_np']=sp_np((int)$gr['long']).'°';
} unset($gr);

require_once 'includes/header.php';
?>

<!-- ══════════════════════════════════════════ PAGE HEADER -->
<section class="page-banner">
<div class="container">
    <h1><i class="lucide-icon" aria-hidden="true" data-lucide="calendar-days"></i>
        <?php echo isEnglish()?'Sahakari Patro':'सहकारी पात्रो'; ?>
    </h1>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo isEnglish()?'Home':'गृहपृष्ठ'; ?></a></li>
            <li class="breadcrumb-item active"><?php echo isEnglish()?'Sahakari Patro':'सहकारी पात्रो'; ?></li>
        </ol>
    </nav>
</div>
</section>

<!-- ══════════════════════════════════════════ PAGE-SCOPED CSS -->
<style>
:root{--sp-primary:#1a5f2a;--sp-primary-dark:#144a21;--sp-primary-light:#2e8b4a;--sp-secondary:#c0392b;--sp-bg:#f8faf9;--sp-card:#fff;--sp-soft:#f5faf6;--sp-muted:#e8f5e9;--sp-border:#e5e7eb;--sp-border-soft:#f0f0f0;--sp-text:#1f2937;--sp-text-muted:#4b5563;}

/* Page shell — inherit site typography, readable defaults */
.sp-page{color:var(--sp-text);font-size:15px;line-height:1.55;}
.sp-page h3,.sp-page h4,.sp-page h6{color:var(--sp-primary-dark);}

/* ── Layout ── */
.sp-row{display:flex;gap:16px;align-items:start;}
.sp-col-main{flex:1;min-width:0;}
.sp-col-side{width:320px;flex-shrink:0;display:flex;flex-direction:column;gap:14px;}
@media(max-width:900px){.sp-row{flex-direction:column;}.sp-col-side{width:100%;}}

/* ── Tab nav ── */
.sp-tabs{border-bottom:2px solid var(--sp-border);gap:2px;}
.sp-tabs .nav-link{color:var(--sp-text-muted);border:none;border-bottom:3px solid transparent;border-radius:8px 8px 0 0;padding:11px 16px;font-size:14px;font-weight:600;display:flex;align-items:center;gap:7px;white-space:nowrap;transition:all .15s;text-decoration:none;}
.sp-tabs .nav-link.active{color:var(--sp-primary);border-bottom-color:var(--sp-primary);background:var(--sp-soft);font-weight:700;}
.sp-tabs .nav-link:hover{color:var(--sp-primary);background:rgba(26,95,42,.04);}
.sp-tabs .nav-link .lucide-icon,.sp-tabs .nav-link svg{width:15px!important;height:15px!important;flex-shrink:0;}

/* ── Calendar ── */
.sp-cal-card{background:#fff;border-radius:14px;box-shadow:0 4px 16px rgba(26,95,42,.07);border:1px solid rgba(26,95,42,.08);overflow:hidden;}
.sp-cal-nav{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--sp-border);gap:8px;flex-wrap:wrap;}
.sp-cal-title{text-align:center;flex:1;min-width:140px;}
.sp-cal-title-main{font-weight:800;font-size:1.25rem;color:var(--sp-primary-dark);line-height:1.25;}
.sp-cal-title-sub{color:var(--sp-text-muted);font-size:13px;margin-top:2px;}
.sp-cal-nav-btn{border:1px solid var(--sp-border);background:#fff;border-radius:8px;padding:7px 12px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-size:13px;font-weight:600;color:var(--sp-text);text-decoration:none;font-family:inherit;}
.sp-cal-nav-btn:hover{border-color:var(--sp-primary);color:var(--sp-primary);}
.sp-cal-today-btn{border:1px solid var(--sp-primary);background:var(--sp-muted);border-radius:8px;padding:7px 12px;font-size:13px;font-weight:700;color:var(--sp-primary);text-decoration:none;}
.sp-cal-body{padding:12px;}
.sp-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px;}
.sp-cal-weekhdr{text-align:center;padding:8px 2px;font-size:12px;font-weight:700;background:var(--sp-soft);border-radius:6px;color:var(--sp-text);}
.sp-cal-weekhdr.sat{color:var(--sp-secondary);}
.sp-cal-weekhdr.sun{color:#b45309;}
.sp-cal-cell{border-radius:8px;min-height:68px;cursor:pointer;border:1px solid var(--sp-border-soft);background:#fff;padding:5px 3px;display:flex;flex-direction:column;align-items:center;gap:2px;position:relative;transition:box-shadow .12s;}
.sp-cal-cell:hover{box-shadow:0 2px 10px rgba(26,95,42,.12);z-index:2;}
.sp-cal-cell.today{background:var(--sp-primary)!important;border-color:var(--sp-primary-dark)!important;}
.sp-cal-cell.selected{background:var(--sp-muted);border-color:var(--sp-primary)!important;border-width:2px!important;}
.sp-cal-cell.sat .sp-cal-daynum{color:var(--sp-secondary);}
.sp-cal-cell.today .sp-cal-daynum,.sp-cal-cell.today .sp-cal-tithi,.sp-cal-cell.today .sp-cal-evbadge{color:#fff!important;}
.sp-cal-cell.today .sp-cal-evbadge{background:rgba(255,255,255,.22)!important;}
.sp-cal-daynum{font-size:17px;font-weight:700;line-height:1.15;color:var(--sp-text);}
.sp-cal-tithi{font-size:11px;color:var(--sp-text-muted);line-height:1.2;font-weight:500;}
.sp-cal-evbadge{font-size:10px;font-weight:600;border-radius:4px;padding:2px 4px;line-height:1.3;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:center;}
.sp-cal-shubhdot{width:5px;height:5px;border-radius:50%;background:#16a34a;position:absolute;bottom:4px;right:4px;}
.sp-cal-more{font-size:10px;color:var(--sp-text-muted);font-weight:600;}

/* Empty cell — site logo watermark (subtle but visible) */
.sp-cal-empty{background:linear-gradient(145deg,#f7fbf8,#eef7f0);display:flex;align-items:center;justify-content:center;cursor:default!important;border-color:#e4efe6!important;min-height:68px;}
.sp-cal-empty:hover{box-shadow:none!important;}
.sp-cal-logo-wrap{opacity:.42;width:86%;height:86%;display:flex;align-items:center;justify-content:center;pointer-events:none;}
.sp-cal-logo-wrap img{max-width:100%;max-height:40px;width:auto;height:auto;object-fit:contain;filter:none;}

/* Calendar legend */
.sp-cal-legend{padding:10px 14px 12px;border-top:1px solid var(--sp-border);background:var(--sp-soft);display:flex;flex-wrap:wrap;gap:10px;}
.sp-cal-leg-item{display:flex;align-items:center;gap:4px;font-size:12px;color:var(--sp-text-muted);font-weight:500;}
.sp-cal-leg-swatch{width:12px;height:12px;border-radius:3px;flex-shrink:0;}

/* Selected day panel */
.sp-selday-card{background:#fff;border-radius:14px;box-shadow:0 4px 16px rgba(26,95,42,.07);border:1px solid rgba(26,95,42,.08);overflow:hidden;}
.sp-selday-hero{background:linear-gradient(135deg,var(--sp-primary),var(--sp-primary-light));color:#fff;padding:16px;}
.sp-selday-daynum{font-size:2.1rem;font-weight:900;line-height:1;}
.sp-selday-month{font-weight:700;font-size:1rem;margin-top:4px;}
.sp-selday-ad{opacity:.9;font-size:13px;margin-top:3px;}
.sp-selday-body{padding:14px 15px;}
.sp-selday-row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--sp-border-soft);font-size:13.5px;}
.sp-selday-row:last-child{border:none;}
.sp-selday-label{color:var(--sp-text-muted);font-weight:500;}
.sp-selday-val{font-weight:700;text-align:right;max-width:58%;color:var(--sp-text);}
.sp-timegrid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px;}
.sp-time-box{background:var(--sp-soft);border-radius:8px;padding:8px 9px;}
.sp-time-box-label{font-size:11px;color:var(--sp-text-muted);display:flex;align-items:center;gap:4px;margin-bottom:3px;font-weight:500;}
.sp-time-box-val{font-size:12.5px;font-weight:700;}
.sp-ev-chip{display:flex;align-items:center;gap:6px;border-radius:6px;padding:6px 9px;font-size:12.5px;font-weight:600;margin-bottom:5px;}

/* Month events list */
.sp-evlist-card{background:#fff;border-radius:14px;box-shadow:0 4px 16px rgba(26,95,42,.07);border:1px solid rgba(26,95,42,.08);overflow:hidden;}
.sp-evlist-hdr{padding:12px 14px;border-bottom:1px solid var(--sp-border);font-weight:700;font-size:14px;color:var(--sp-primary-dark);display:flex;align-items:center;gap:6px;}
.sp-evlist-body{max-height:280px;overflow-y:auto;}
.sp-evlist-row{display:flex;gap:10px;align-items:flex-start;padding:9px 12px;border-bottom:1px solid var(--sp-border-soft);cursor:pointer;}
.sp-evlist-row:hover,.sp-evlist-row.active{background:var(--sp-muted);}
.sp-evlist-daynum{min-width:28px;height:28px;border-radius:7px;background:var(--sp-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex-shrink:0;}
.sp-evlist-evname{font-size:13px;font-weight:600;line-height:1.45;}
.sp-evlist-vaar{font-size:11px;color:var(--sp-text-muted);margin-top:2px;}

/* Mini calendar (upcoming) */
.sp-minical-card{background:#fff;border-radius:14px;box-shadow:0 4px 16px rgba(26,95,42,.07);border:1px solid rgba(26,95,42,.08);overflow:hidden;}
.sp-minical-hdr{background:var(--sp-soft);padding:11px 14px;border-bottom:1px solid var(--sp-border);font-weight:700;font-size:13.5px;color:var(--sp-primary-dark);display:flex;align-items:center;justify-content:space-between;gap:8px;}
.sp-minical-body{padding:10px;}
.sp-minical-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;}
.sp-minical-wh{text-align:center;font-size:11px;color:var(--sp-text-muted);padding:4px 0;font-weight:600;}
.sp-minical-cell{text-align:center;font-size:12px;padding:4px 1px;border-radius:5px;color:var(--sp-text);font-weight:600;}
.sp-minical-cell.empty{opacity:0;}
.sp-minical-cell.has-ev{font-weight:700;}
.sp-minical-cell.sat{color:var(--sp-secondary);}
.sp-minical-cell.holiday{background:#fef2f2;color:#dc2626;}
.sp-minical-cell.festival{background:#fff7ed;color:#ea580c;}
.sp-minical-cell.purnima{background:#eff6ff;color:#3b82f6;}
.sp-minical-cell.ekadashi{background:#f5f3ff;color:#7c3aed;}

/* Cards / sections */
.sp-card{background:#fff;border-radius:14px;box-shadow:0 4px 16px rgba(26,95,42,.07);border:1px solid rgba(26,95,42,.08);overflow:hidden;}
.sp-section-title{padding:14px 16px;border-bottom:1px solid var(--sp-border);font-weight:700;font-size:15px;color:var(--sp-primary-dark);display:flex;align-items:center;gap:8px;}
.sp-pancha-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;padding:14px 16px 16px;}
.sp-pancha-item{background:var(--sp-soft);border-radius:10px;padding:11px 12px;border:1px solid rgba(26,95,42,.06);}
.sp-pi-label{font-size:12px;color:var(--sp-text-muted);display:flex;align-items:center;gap:5px;margin-bottom:4px;font-weight:600;}
.sp-pi-val{font-size:14px;font-weight:700;color:var(--sp-text);line-height:1.35;}

/* Rashifal */
.sp-subnav{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;}
.sp-subnav a{display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;border:1px solid var(--sp-border);background:#fff;color:var(--sp-text-muted);font-size:13px;font-weight:600;text-decoration:none;}
.sp-subnav a.active,.sp-subnav a:hover{background:var(--sp-primary);color:#fff;border-color:var(--sp-primary);}
.sp-rashi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;}
.sp-rashi-card{background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.05);}
.sp-rashi-card.active{box-shadow:0 6px 18px rgba(26,95,42,.14);}
.sp-rashi-hdr{display:flex;align-items:center;gap:10px;padding:12px 14px;}
.sp-rashi-sym{font-size:1.5rem;line-height:1;}
.sp-rashi-name{font-size:1.05rem;font-weight:800;}
.sp-rashi-meta{font-size:12px;color:var(--sp-text-muted);font-weight:500;margin-top:2px;}
.sp-rashi-badges{margin-left:auto;display:flex;flex-direction:column;gap:4px;align-items:flex-end;}
.sp-rashi-body{padding:12px 14px 14px;border-top:1px solid var(--sp-border-soft);}
.sp-rashi-pred{margin:0;font-size:14px;line-height:1.6;color:var(--sp-text);}

/* Tools / forms */
.sp-tool-center{text-align:center;padding:22px 18px 8px;}
.sp-tool-icon{width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,var(--sp-primary),var(--sp-primary-light));color:#fff;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;box-shadow:0 8px 18px rgba(26,95,42,.22);}
.sp-tool-icon .lucide-icon,.sp-tool-icon svg{color:#fff!important;stroke:#fff!important;}
.sp-tool-center h3{font-size:1.15rem!important;font-weight:800!important;color:var(--sp-primary-dark)!important;margin:0 0 6px!important;}
.sp-tool-center p{font-size:14px!important;color:var(--sp-text-muted)!important;line-height:1.5;}
.sp-form-label{display:block;font-size:13px;font-weight:700;color:var(--sp-text);margin-bottom:6px;}
.sp-form-control{width:100%;border:1px solid #d1d5db;border-radius:9px;padding:10px 12px;font-size:14px;font-family:inherit;color:var(--sp-text);background:#fff;line-height:1.4;}
.sp-form-control:focus{outline:none;border-color:var(--sp-primary);box-shadow:0 0 0 3px rgba(26,95,42,.12);}
.sp-badge{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:5px 11px;font-size:12.5px;font-weight:700;line-height:1.2;}
.sp-btn-primary{width:100%;background:var(--sp-primary);color:#fff;border:none;border-radius:10px;padding:12px 16px;font-size:15px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;font-family:inherit;text-decoration:none;}
.sp-btn-primary:hover{background:var(--sp-primary-dark);color:#fff;}
.sp-result-box{margin-top:16px;background:var(--sp-muted);border-radius:12px;padding:16px;border:1px solid rgba(26,95,42,.14);}
.sp-table{width:100%;border-collapse:collapse;font-size:13.5px;}
.sp-table th,.sp-table td{padding:10px 12px;border-bottom:1px solid var(--sp-border);text-align:left;vertical-align:middle;}
.sp-table th{background:var(--sp-soft);color:var(--sp-primary-dark);font-weight:700;font-size:13px;}
.sp-table td{color:var(--sp-text);}
.sp-muhurta-card{display:flex;gap:10px;padding:12px 13px;border-radius:10px;background:#f0fdf4;border:1px solid #bbf7d0;height:100%;}
.sp-muhurta-card.bad{background:#fff7ed;border-color:#fed7aa;}
.sp-muhurta-card-title{font-weight:700;font-size:14px;color:var(--sp-text);margin-bottom:3px;}
.sp-muhurta-card-desc{font-size:13px;color:var(--sp-text-muted);line-height:1.45;}
.sp-muhurta-card-time{font-size:12.5px;font-weight:700;color:var(--sp-primary);margin-top:6px;display:flex;align-items:center;gap:4px;}

/* Date bar — date chips only (page banner already brands the page) */
.sp-datebar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;background:#fff;border-radius:12px;padding:12px 16px;box-shadow:0 1px 6px rgba(26,95,42,.06);border:1px solid rgba(26,95,42,.08);}
.sp-datebar-meta{font-size:14px;color:var(--sp-text-muted);font-weight:500;}

@media(max-width:576px){
  .sp-pancha-grid{grid-template-columns:repeat(2,1fr);}
  .sp-cal-daynum{font-size:14px;}
  .sp-cal-tithi{font-size:10px;}
  .sp-cal-cell,.sp-cal-empty{min-height:54px;}
  .sp-cal-evbadge{display:none;}
  .sp-rashi-grid{grid-template-columns:1fr;}
  .sp-tabs .nav-link{padding:10px 12px;font-size:13px;}
  .sp-cal-logo-wrap img{max-height:28px;}
}
</style>

<!-- ══════════════════════════════════════════ MAIN -->
<section class="section-padding sp-page">
<div class="container">

<!-- Date bar — today chips only (site branding stays in header + page banner) -->
<div class="sp-datebar">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <span class="sp-badge" style="background:var(--sp-primary);color:#fff;">
            <i class="lucide-icon" style="width:12px;height:12px;" data-lucide="calendar-days"></i>
            <?php echo $pg['bs_day_np'].' '.$pg['bs_month_name'].' '.$pg['bs_year_np']; ?>
        </span>
        <span class="sp-datebar-meta"><?php echo $pg['ad_date'].' · '.$pg['vaar_np']; ?></span>
        <span class="sp-badge" style="background:var(--sp-muted);color:var(--sp-primary);"><?php echo htmlspecialchars($pg['tithi']); ?></span>
    </div>
    <span class="sp-badge" style="background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;">
        <i class="lucide-icon" style="width:12px;height:12px;" data-lucide="refresh-cw"></i>
        Live auto-sync
    </span>
</div>

<!-- Tab nav -->
<ul class="nav sp-tabs flex-nowrap overflow-auto mb-0" id="sp-main-tabs">
    <?php $tabs=[
        'patro'    =>['calendar-days','पात्रो / पञ्चाङ्ग','Patro / Panchanga'],
        'rashifal' =>['star','राशिफल','Rashifal'],
        'lagna'    =>['compass','लग्न / नक्षत्र','Lagna / Nakshatra'],
        'gunmilan' =>['heart-handshake','गुणमिलन','Guna Milan'],
        'muhurta'  =>['clock-4','शुभ मुहुर्त','Subha Muhurta'],
        'jyotish'  =>['telescope','ज्योतिष','Jyotish'],
    ];
    foreach($tabs as $tid=>[$ico,$np,$en]):
        $isAct=$activeTab===$tid; ?>
    <li class="nav-item flex-shrink-0">
        <a href="?tab=<?php echo $tid; ?>&rf=<?php echo $rfPeriod; ?>&cal_year=<?php echo $calY; ?>&cal_month=<?php echo $calM; ?>&sel_day=<?php echo $selD; ?>"
           class="nav-link <?php echo $isAct?'active':''; ?>">
            <i class="lucide-icon" style="width:13px;height:13px;" data-lucide="<?php echo $ico; ?>"></i>
            <span><?php echo isEnglish()?$en:$np; ?></span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<div style="padding-top:18px;">

<?php /* ════════════ TAB: पात्रो / पञ्चाङ्ग ═══════════════════════════════ */
if($activeTab==='patro'): ?>

<div class="sp-row">

  <!-- CALENDAR (main) -->
  <div class="sp-col-main">
    <div class="sp-cal-card">
      <!-- Month nav -->
      <div class="sp-cal-nav">
        <a href="?tab=patro&cal_year=<?php echo $prevY; ?>&cal_month=<?php echo $prevM; ?>&sel_day=1&rf=<?php echo $rfPeriod; ?>" class="sp-cal-nav-btn">
          &#8249; <?php echo isEnglish()?'Prev':'अघि'; ?>
        </a>
        <div class="sp-cal-title">
          <div class="sp-cal-title-main"><?php echo $SP_BS_MONTHS_NP[$calM-1].' '.sp_np($calY); ?></div>
          <div class="sp-cal-title-sub"><?php echo $adRangeLabel; ?></div>
        </div>
        <div style="display:flex;gap:6px;">
          <a href="?tab=patro&cal_year=<?php echo $pg['bs_year']; ?>&cal_month=<?php echo $pg['bs_month']; ?>&sel_day=<?php echo $pg['bs_day']; ?>&rf=<?php echo $rfPeriod; ?>" class="sp-cal-today-btn">आज</a>
          <a href="?tab=patro&cal_year=<?php echo $nextY; ?>&cal_month=<?php echo $nextM; ?>&sel_day=1&rf=<?php echo $rfPeriod; ?>" class="sp-cal-nav-btn">
            <?php echo isEnglish()?'Next':'अर्को'; ?> &#8250;
          </a>
        </div>
      </div>

      <!-- Calendar grid -->
      <div class="sp-cal-body">
        <div class="sp-cal-grid">
          <!-- Weekday headers -->
          <?php foreach(['आइत','सोम','मंगल','बुध','बिहि','शुक्र','शनि'] as $wi=>$wn): ?>
          <div class="sp-cal-weekhdr <?php echo $wi===6?'sat':($wi===0?'sun':''); ?>"><?php echo $wn; ?></div>
          <?php endforeach; ?>

          <!-- Day cells -->
          <?php foreach($calCells as $cell):
            if($cell===null): ?>
            <!-- Empty cell: Sahakari logo -->
            <div class="sp-cal-cell sp-cal-empty" aria-hidden="true">
              <div class="sp-cal-logo-wrap">
                <img src="<?php echo htmlspecialchars($spLogoUrl, ENT_QUOTES, 'UTF-8'); ?>"
                     alt=""
                     loading="lazy"
                     onerror="this.onerror=null;this.src='<?php echo htmlspecialchars(rtrim((string)SITE_URL,'/').'/assets/images/logo.png', ENT_QUOTES, 'UTF-8'); ?>';">
              </div>
            </div>
            <?php else:
              $d=$cell['d'];
              $isToday=$calY===$pg['bs_year']&&$calM===$pg['bs_month']&&$d===$pg['bs_day'];
              $isSel=$d===$selD;
              $mainEv=$cell['evs'][0]??null;
              $bg='#fff'; $brd='1px solid #f0f0f0';
              if(!$isToday&&$mainEv) $bg=sp_ev_bg($mainEv['type']);
              elseif(!$isToday&&$cell['shubh']) $bg='#dcfce7';
              $classes='sp-cal-cell'.($isToday?' today':'').($isSel&&!$isToday?' selected':'').($cell['wd']===6?' sat':'');
              $href="?tab=patro&cal_year=$calY&cal_month=$calM&sel_day=$d&rf=$rfPeriod";
            ?>
            <a href="<?php echo $href; ?>" class="<?php echo $classes; ?>" style="background:<?php echo $bg; ?>;border:<?php echo $isSel&&!$isToday?'2px solid var(--sp-primary)':$brd; ?>;text-decoration:none;">
              <div class="sp-cal-daynum"><?php echo sp_np($d); ?></div>
              <div class="sp-cal-tithi"><?php echo ['प्र','द्वि','तृ','च','पं','ष','स','अ','न','द','ए','द्वा','त्र','च','पू','प्र','द्वि','तृ','च','पं','ष','स','अ','न','द','ए','द्वा','त्र','च','औ'][$cell['ti']]; ?></div>
              <?php if($mainEv): $ec=sp_ev_color($mainEv['type']); $eb=sp_ev_bg($mainEv['type']); ?>
              <div class="sp-cal-evbadge" style="background:<?php echo $isToday?'rgba(255,255,255,.2)':$eb; ?>;color:<?php echo $isToday?'#fff':$ec; ?>;"><?php echo mb_strlen($mainEv['name'])>8?mb_substr($mainEv['name'],0,7).'…':$mainEv['name']; ?></div>
              <?php endif; ?>
              <?php if($cell['shubh']&&!$mainEv&&!$isToday): ?><div class="sp-cal-shubhdot"></div><?php endif; ?>
              <?php if(count($cell['evs'])>1): ?><div class="sp-cal-more">+<?php echo sp_np(count($cell['evs'])-1); ?></div><?php endif; ?>
            </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div><!-- /sp-cal-body -->

      <!-- Legend -->
      <div class="sp-cal-legend">
        <?php foreach([['पूर्णिमा','#eff6ff','#3b82f6'],['औंसी','#f3f4f6','#374151'],['एकादशी','#f5f3ff','#7c3aed'],['अष्टमी','#ecfeff','#0891b2'],['पर्व','#fff7ed','#ea580c'],['बिदा','#fef2f2','#dc2626'],['शुभ दिन','#dcfce7','#16a34a']] as [$l,$bg,$tc]): ?>
        <div class="sp-cal-leg-item">
          <span class="sp-cal-leg-swatch" style="background:<?php echo $bg; ?>;border:1px solid <?php echo $tc; ?>;"></span>
          <?php echo $l; ?>
        </div>
        <?php endforeach; ?>
        <div class="sp-cal-leg-item"><span style="width:7px;height:7px;border-radius:50%;background:#16a34a;flex-shrink:0;display:inline-block;"></span>शुभ संकेत</div>
      </div>
    </div><!-- /sp-cal-card -->

    <!-- ── Today full panchanga ── -->
    <div class="sp-card" style="margin-top:16px;">
      <div class="sp-section-title">
        <i class="lucide-icon" style="width:15px;height:15px;" data-lucide="sparkles"></i>
        आजको पूर्ण पञ्चाङ्ग — <?php echo $pg['bs_day_np'].' '.$pg['bs_month_name'].' '.$pg['bs_year_np']; ?>
      </div>
      <div class="sp-pancha-grid">
        <?php foreach([
          ['calendar-days','तिथि',$pg['tithi'].' ('.$pg['paksha'].')'],
          ['star','नक्षत्र',$pg['nakshatra']],['sparkles','योग',$pg['yoga']],
          ['rotate-cw','करण',$pg['karana']],['calendar-days','वार',$pg['vaar_np']],
          ['moon','पक्ष',$pg['paksha']],['leaf','ऋतु',$pg['ritu']],
          ['scroll-text','संवत्सर',$pg['samvatsar']],
          ['sun','सूर्योदय',$pg['sunrise']],['sunset','सूर्यास्त',$pg['sunset']],
          ['alarm-clock','राहुकाल',$pg['rahu_kaal']],['zap','अभिजित',$pg['abhijit']],
        ] as [$ico,$l,$v]): ?>
        <div class="sp-pancha-item">
          <div class="sp-pi-label"><i class="lucide-icon" style="width:13px;height:13px;color:var(--sp-primary);" data-lucide="<?php echo $ico; ?>"></i><?php echo $l; ?></div>
          <div class="sp-pi-val"><?php echo htmlspecialchars($v); ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ── Upcoming month mini calendar ── -->
    <div class="sp-minical-card" style="margin-top:16px;">
      <div class="sp-minical-hdr">
        <span><i class="lucide-icon" style="width:13px;height:13px;" data-lucide="calendar"></i>
        आगामी महिना — <?php echo $SP_BS_MONTHS_NP[$nextM-1].' '.sp_np($nextY); ?></span>
        <a href="?tab=patro&cal_year=<?php echo $nextY; ?>&cal_month=<?php echo $nextM; ?>&sel_day=1" style="font-size:11px;color:var(--sp-primary);text-decoration:none;">पूरा हेर्नुहोस् →</a>
      </div>
      <div class="sp-minical-body">
        <div class="sp-minical-grid">
          <?php foreach(['आ','सो','मं','बु','बि','शु','श'] as $wn): ?>
          <div class="sp-minical-wh"><?php echo $wn; ?></div>
          <?php endforeach; ?>
          <?php foreach($nextCalCells as $mc):
            if($mc===null): ?>
            <div class="sp-minical-cell empty">·</div>
            <?php else:
              $me=$mc['evs'][0]??null;
              $mcls='sp-minical-cell'.($mc['wd']===6?' sat':'');
              if($me) $mcls.=' '.($me['type']==='holiday'?'holiday':($me['type']==='festival'?'festival':($me['type']==='purnima'?'purnima':($me['type']==='ekadashi'?'ekadashi':'has-ev')))); ?>
            <a href="?tab=patro&cal_year=<?php echo $nextY; ?>&cal_month=<?php echo $nextM; ?>&sel_day=<?php echo $mc['d']; ?>" class="<?php echo $mcls; ?>" title="<?php echo $me?htmlspecialchars($me['name']):''; ?>" style="text-decoration:none;">
              <?php echo sp_np($mc['d']); ?>
            </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <!-- Next month events -->
        <?php $nextEvDays=array_filter($nextCalCells,fn($c)=>$c!==null&&!empty($c['evs']));
        if(!empty($nextEvDays)): ?>
        <div style="margin-top:8px;border-top:1px solid var(--sp-border-soft);padding-top:7px;">
          <?php $shown=0; foreach($nextEvDays as $nc) { foreach($nc['evs'] as $ne) { if($shown>=5) break 2; $ec=sp_ev_color($ne['type']); ?>
          <div style="font-size:10px;display:flex;gap:5px;margin-bottom:3px;">
            <span style="font-weight:700;color:var(--sp-primary);min-width:18px;"><?php echo sp_np($nc['d']); ?></span>
            <span style="color:<?php echo $ec; ?>;font-weight:600;"><?php echo htmlspecialchars($ne['name']); ?></span>
          </div>
          <?php $shown++; }} ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div><!-- /col-main -->

  <!-- SIDEBAR -->
  <div class="sp-col-side">

    <!-- Selected day card -->
    <div class="sp-selday-card">
      <div class="sp-selday-hero">
        <div class="sp-selday-daynum"><?php echo sp_np($selD); ?></div>
        <div class="sp-selday-month"><?php echo $SP_BS_MONTHS_NP[$calM-1].', '.sp_np($calY); ?></div>
        <div class="sp-selday-ad"><?php echo $selPg['vaar_np'].' — '.$selPg['ad_date']; ?></div>
      </div>
      <div class="sp-selday-body">
        <?php foreach([['तिथि',$selPg['tithi'].' ('.$selPg['paksha'].')'],['नक्षत्र',$selPg['nakshatra']],['योग',$selPg['yoga']],['करण',$selPg['karana']],['ऋतु',$selPg['ritu']],['अयन',$selPg['ayana']]] as [$l,$v]): ?>
        <div class="sp-selday-row"><span class="sp-selday-label"><?php echo $l; ?></span><span class="sp-selday-val"><?php echo htmlspecialchars($v); ?></span></div>
        <?php endforeach; ?>
        <div class="sp-timegrid">
          <?php foreach([['sun','सूर्योदय',$selPg['sunrise'],'#f59e0b'],['sunset','सूर्यास्त',$selPg['sunset'],'#c0392b'],['alarm-clock','राहुकाल',$selPg['rahu_kaal'],'#374151'],['zap','अभिजित',$selPg['abhijit'],'var(--sp-primary)']] as [$ico,$l,$v,$c]): ?>
          <div class="sp-time-box">
            <div class="sp-time-box-label"><i class="lucide-icon" style="width:11px;height:11px;color:<?php echo $c; ?>;" data-lucide="<?php echo $ico; ?>"></i><span style="font-size:9.5px;color:var(--sp-text-muted);"><?php echo $l; ?></span></div>
            <div class="sp-time-box-val" style="color:<?php echo $c; ?>;"><?php echo $v; ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if(!empty($selEvs)): ?>
        <div style="margin-top:10px;">
          <?php foreach($selEvs as $ev): $ec=sp_ev_color($ev['type']); $eb=sp_ev_bg($ev['type']); ?>
          <div class="sp-ev-chip" style="background:<?php echo $eb; ?>;color:<?php echo $ec; ?>;">
            <i class="lucide-icon" style="width:10px;height:10px;" data-lucide="bell"></i>
            <?php echo htmlspecialchars($ev['name']); ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div><!-- /selday -->

    <!-- Month events list -->
    <div class="sp-evlist-card">
      <div class="sp-evlist-hdr">
        <i class="lucide-icon" style="width:13px;height:13px;" data-lucide="bell"></i>
        <?php echo $SP_BS_MONTHS_NP[$calM-1].' '.sp_np($calY); ?> का पर्वहरू
      </div>
      <div class="sp-evlist-body">
        <?php $hasSomeEv=false;
        foreach($calCells as $ec):
          if($ec===null||empty($ec['evs'])) continue; $hasSomeEv=true; ?>
        <a href="?tab=patro&cal_year=<?php echo $calY; ?>&cal_month=<?php echo $calM; ?>&sel_day=<?php echo $ec['d']; ?>&rf=<?php echo $rfPeriod; ?>"
           class="sp-evlist-row <?php echo $ec['d']===$selD?'active':''; ?>" style="text-decoration:none;">
          <div class="sp-evlist-daynum"><?php echo sp_np($ec['d']); ?></div>
          <div>
            <?php foreach($ec['evs'] as $ev): $ec2=sp_ev_color($ev['type']); ?>
            <div class="sp-evlist-evname" style="color:<?php echo $ec2; ?>;"><?php echo htmlspecialchars($ev['name']); ?></div>
            <?php endforeach; ?>
            <div class="sp-evlist-vaar"><?php echo ['आइत','सोम','मंगल','बुध','बिहि','शुक्र','शनि'][$ec['wd']]; ?></div>
          </div>
        </a>
        <?php endforeach;
        if(!$hasSomeEv): ?>
        <div style="padding:16px;text-align:center;color:var(--sp-text-muted);font-size:12px;">यस महिना कुनै विशेष पर्व छैन</div>
        <?php endif; ?>
      </div>
    </div><!-- /evlist -->

  </div><!-- /col-side -->
</div><!-- /row -->

<?php /* ════════════ TAB: राशिफल ══════════════════════════════════════════ */
elseif($activeTab==='rashifal'): ?>

<div class="sp-subnav">
  <?php foreach(['daily'=>'दैनिक राशिफल','monthly'=>'मासिक राशिफल','yearly'=>'वार्षिक राशिफल'] as $rk=>$rl): ?>
  <a href="?tab=rashifal&rf=<?php echo $rk; ?>" class="<?php echo $rfPeriod===$rk?'active':''; ?>"><?php echo $rl; ?></a>
  <?php endforeach; ?>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
  <h4 style="font-weight:700;color:var(--sp-primary);margin:0;display:flex;align-items:center;gap:8px;font-size:15px;">
    <i class="lucide-icon" style="width:16px;height:16px;" data-lucide="star"></i>
    <?php echo $rfPeriod==='daily'?'दैनिक — '.$pg['bs_day_np'].' '.$pg['bs_month_name'].' '.$pg['bs_year_np']:($rfPeriod==='monthly'?$pg['bs_month_name'].' '.$pg['bs_year_np'].' मासिक':$pg['bs_year_np'].' बि.सं. वार्षिक'); ?>
  </h4>
  <span style="font-size:12px;color:var(--sp-text-muted);">चन्द्र नक्षत्र: <strong style="color:var(--sp-primary);"><?php echo $pg['nakshatra']; ?></strong></span>
</div>

<div class="sp-rashi-grid">
  <?php foreach($rashiNames as $ri=>$rn):
    $preds=$rfPeriod==='daily'?$dailyPreds:($rfPeriod==='monthly'?$monthlyPreds:$yearlyPreds);
    $pred=$rfPeriod==='daily'?$preds[($ri+$pg['moon_rashi']+$doy_np)%12]:$preds[$ri];
    $rc=$rashiColors[$ri]; $isMoon=$ri===$pg['moon_rashi']; $isSun=$ri===$pg['sun_rashi']; ?>
  <div class="sp-rashi-card <?php echo $isMoon?'active':''; ?>" style="border:<?php echo $isMoon?'2px solid '.$rc:'1px solid var(--sp-border)'; ?>">
    <div class="sp-rashi-hdr" style="background:<?php echo $rc; ?>1a;">
      <span class="sp-rashi-sym"><?php echo $rashiSym[$ri]; ?></span>
      <div>
        <div class="sp-rashi-name" style="color:<?php echo $rc; ?>;"><?php echo $rn; ?></div>
        <div class="sp-rashi-meta"><?php echo $rashiEl[$ri]; ?> • <?php echo $rashiLords[$ri]; ?></div>
      </div>
      <div class="sp-rashi-badges">
        <?php if($isMoon): ?><span class="sp-badge" style="background:#3b82f6;color:#fff;font-size:10px;"><i class="lucide-icon" style="width:9px;height:9px;" data-lucide="moon"></i>चन्द्र</span><?php endif; ?>
        <?php if($isSun): ?><span class="sp-badge" style="background:#f59e0b;color:#fff;font-size:10px;"><i class="lucide-icon" style="width:9px;height:9px;" data-lucide="sun"></i>सूर्य</span><?php endif; ?>
      </div>
    </div>
    <div class="sp-rashi-body">
      <p class="sp-rashi-pred"><?php echo htmlspecialchars($pred); ?></p>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php /* ════════════ TAB: लग्न / नक्षत्र ══════════════════════════════════ */
elseif($activeTab==='lagna'): ?>

<div class="row g-4">
  <!-- Lagna calculator -->
  <div class="col-md-6">
    <div class="sp-card">
      <div class="sp-tool-center">
        <div class="sp-tool-icon"><i class="lucide-icon" style="width:24px;height:24px;" data-lucide="compass"></i></div>
        <h3 style="margin:0 0 5px;font-size:17px;font-weight:700;">लग्न / कुण्डली विवरण</h3>
        <p style="margin:0;color:var(--sp-text-muted);font-size:12px;">जन्म मिति र समयबाट लग्न, नक्षत्र र राशि पत्ता लगाउनुहोस्</p>
      </div>
      <div style="padding:18px 20px 22px;">
        <form method="get" action="">
          <input type="hidden" name="tab" value="lagna">
          <div style="margin-bottom:12px;">
            <label class="sp-form-label">जन्म मिति (ई.सं.)</label>
            <input type="date" name="birth_date" value="<?php echo htmlspecialchars($_GET['birth_date']??'1990-01-15'); ?>" class="sp-form-control">
          </div>
          <div style="margin-bottom:14px;">
            <label class="sp-form-label">जन्म समय (स्थानीय)</label>
            <input type="time" name="birth_time" value="<?php echo htmlspecialchars($_GET['birth_time']??'06:30'); ?>" class="sp-form-control">
          </div>
          <button type="submit" class="sp-btn-primary">
            <i class="lucide-icon" style="width:15px;height:15px;" data-lucide="search"></i> लग्न निकाल्नुहोस्
          </button>
        </form>
        <?php if($lagnaResult): ?>
        <div class="sp-result-box">
          <div style="font-weight:700;color:var(--sp-primary);margin-bottom:10px;font-size:12px;display:flex;align-items:center;gap:5px;">
            <i class="lucide-icon" style="width:13px;height:13px;" data-lucide="award"></i> तपाईंको ज्योतिष विवरण
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
            <?php foreach([['लग्न',$lagnaResult['lagna']],['जन्म नक्षत्र',$lagnaResult['nakshatra']],['नक्षत्र स्वामी',$lagnaResult['nk_lord']],['चन्द्र राशि',$lagnaResult['chandra_rashi']],['जन्म बि.सं.',$lagnaResult['bs_date']??'—']] as [$l,$v]): ?>
            <div style="background:#fff;border-radius:6px;padding:7px 9px;">
              <div style="font-size:9.5px;color:var(--sp-text-muted);"><?php echo $l; ?></div>
              <div style="font-weight:700;color:var(--sp-primary);font-size:12px;margin-top:1px;"><?php echo htmlspecialchars($v); ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <p style="font-size:9.5px;color:var(--sp-text-muted);margin:10px 0 0;">* अनुमानित। सटीक कुण्डलीका लागि अनुभवी ज्योतिषीसँग परामर्श लिनुहोस्।</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <!-- Nakshatra directory -->
  <div class="col-md-6">
    <div class="sp-card">
      <div class="sp-tool-center">
        <div class="sp-tool-icon"><i class="lucide-icon" style="width:24px;height:24px;" data-lucide="star"></i></div>
        <h3 style="margin:0 0 5px;font-size:17px;font-weight:700;">२७ नक्षत्र विवरण</h3>
        <p style="margin:0;color:var(--sp-text-muted);font-size:12px;">आजको नक्षत्र: <strong style="color:var(--sp-primary);"><?php echo $pg['nakshatra']; ?></strong></p>
      </div>
      <div style="padding:14px 16px 18px;overflow-y:auto;max-height:500px;">
        <?php foreach($nakshatraAll as $ni=>$nk):
          $isToday=$ni===$pg['nk_idx']; ?>
        <div style="border:<?php echo $isToday?'2px solid var(--sp-primary)':'1px solid var(--sp-border)'; ?>;border-radius:9px;padding:10px 12px;margin-bottom:8px;background:<?php echo $isToday?'var(--sp-muted)':'var(--sp-soft)'; ?>;">
          <div style="display:flex;align-items:center;gap:7px;margin-bottom:7px;">
            <span class="sp-badge" style="background:var(--sp-primary);color:#fff;"><?php echo sp_np($ni+1); ?></span>
            <span style="font-weight:700;font-size:14px;color:var(--sp-primary);"><?php echo $nk['name']; ?></span>
            <?php if($isToday): ?><span class="sp-badge" style="background:#f59e0b;color:#fff;font-size:10px;margin-left:auto;">आजको</span><?php endif; ?>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px;font-size:11px;">
            <?php foreach([['स्वामी',$nk['lord']],['राशि',$nk['rashi']],['नामाक्षर',$nk['char']],['विशेषता',$nk['qual']]] as [$l,$v]): ?>
            <div><span style="color:var(--sp-text-muted);"><?php echo $l; ?>:</span> <strong><?php echo $v; ?></strong></div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php /* ════════════ TAB: गुणमिलन ══════════════════════════════════════════ */
elseif($activeTab==='gunmilan'): ?>

<div class="sp-card">
  <div class="sp-tool-center">
    <div class="sp-tool-icon"><i class="lucide-icon" style="width:24px;height:24px;" data-lucide="heart-handshake"></i></div>
    <h3 style="margin:0 0 5px;font-size:17px;font-weight:700;">गुणमिलन / अष्टकूट मिलान</h3>
    <p style="margin:0;color:var(--sp-text-muted);font-size:12px;">३६ गुण अष्टकूट पद्धति — विवाहका लागि कुण्डली मिलान</p>
  </div>
  <div style="padding:18px 20px 22px;">
    <form method="post" action="?tab=gunmilan&amp;cal_year=<?php echo (int)$calY; ?>&amp;cal_month=<?php echo (int)$calM; ?>&amp;sel_day=<?php echo (int)$selD; ?>">
      <div class="row g-4 mb-4">
        <?php foreach([[0,'वर / व्यक्ति १','#1a5f2a'],[1,'वधू / व्यक्ति २','#c0392b']] as [$pi,$plabel,$pc]):
          $nKey = $pi === 0 ? 'n1' : 'n2';
          $rKey = $pi === 0 ? 'r1' : 'r2';
          $nPost = isset($_POST[$nKey]) ? (string)$_POST[$nKey] : '';
          $rPost = isset($_POST[$rKey]) ? (string)$_POST[$rKey] : '';
        ?>
        <div class="col-md-6">
          <div style="border:2px solid <?php echo $pc; ?>;border-radius:10px;overflow:hidden;">
            <div style="background:<?php echo $pc; ?>;color:#fff;padding:10px 14px;font-weight:700;font-size:14px;"><?php echo $plabel; ?></div>
            <div style="padding:14px;">
              <label class="sp-form-label">जन्म नक्षत्र</label>
              <select name="<?php echo $nKey; ?>" class="sp-form-control" style="margin-bottom:12px;" required>
                <option value="">नक्षत्र छान्नुहोस्...</option>
                <?php foreach($nakshatraAll as $ni=>$nk):
                  $sel = ($nPost !== '' && (int)$nPost === (int)$ni);
                ?>
                <option value="<?php echo (int)$ni; ?>"<?php echo $sel ? ' selected' : ''; ?>><?php echo sp_np($ni+1).'. '.$nk['name']; ?></option>
                <?php endforeach; ?>
              </select>
              <label class="sp-form-label">चन्द्र राशि</label>
              <select name="<?php echo $rKey; ?>" class="sp-form-control" required>
                <option value="">राशि छान्नुहोस्...</option>
                <?php foreach($rashiNames as $ri=>$rn):
                  $sel = ($rPost !== '' && (int)$rPost === (int)$ri);
                ?>
                <option value="<?php echo (int)$ri; ?>"<?php echo $sel ? ' selected' : ''; ?>><?php echo $rashiSym[$ri].' '.$rn; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="text-align:center;margin-bottom:18px;">
        <button type="submit" class="sp-btn-primary" style="max-width:280px;margin:0 auto;">
          <i class="lucide-icon" style="width:16px;height:16px;" data-lucide="heart-handshake"></i> गुणमिलन गणना गर्नुहोस्
        </button>
      </div>
    </form>
    <?php if($gunaResult): $tot=$gunaResult['total']; $col=$tot>=28?'var(--sp-primary)':($tot>=18?'#d97706':'var(--sp-secondary)'); ?>
    <div style="text-align:center;margin-bottom:18px;">
      <div style="font-size:72px;font-weight:900;line-height:1;color:<?php echo $col; ?>"><?php echo sp_np($tot); ?><span style="font-size:32px;opacity:.4;">/३६</span></div>
      <div style="height:12px;background:var(--sp-border);border-radius:8px;overflow:hidden;max-width:320px;margin:10px auto;">
        <div style="height:100%;width:<?php echo round($tot/36*100); ?>%;background:<?php echo $col; ?>;border-radius:8px;"></div>
      </div>
      <div style="font-size:17px;font-weight:700;color:<?php echo $col; ?>;margin-top:6px;"><?php echo htmlspecialchars($gunaResult['verdict']); ?></div>
    </div>
    <div style="overflow-x:auto;">
      <table class="sp-table">
        <thead><tr><th>कूट</th><th>विषय</th><th>प्राप्त अंक</th><th>पूर्णाङ्क</th></tr></thead>
        <tbody>
          <?php foreach([['वर्ण','सामाजिक स्तर',$gunaResult['varna'],1],['वश्य','प्रभुत्व',$gunaResult['vashya'],2],['तारा','स्वास्थ्य र भाग्य',$gunaResult['tara'],3],['योनि','शारीरिक सम्बन्ध',$gunaResult['yoni'],4],['ग्रह मैत्री','मानसिक समानता',$gunaResult['grahaM'],5],['गण','स्वभाव मिलान',$gunaResult['gana'],6],['भकुट','स्वास्थ्य र आयु',$gunaResult['bhakuta'],7],['नाडी','सन्तान र स्वास्थ्य',$gunaResult['nadi'],8]] as [$k,$sub,$sc,$mx]):
            $pc=$sc/$mx; $rc=$pc>=.7?'var(--sp-primary)':($pc>=.4?'#d97706':'var(--sp-secondary)'); ?>
          <tr><td style="font-weight:600;"><?php echo $k; ?></td><td style="color:var(--sp-text-muted);font-size:11px;"><?php echo $sub; ?></td>
              <td style="font-weight:700;color:<?php echo $rc; ?>"><?php echo sp_np($sc); ?></td>
              <td style="color:var(--sp-text-muted);"><?php echo sp_np($mx); ?></td></tr>
          <?php endforeach; ?>
          <tr style="background:var(--sp-primary)!important;color:#fff;"><td colspan="2" style="font-weight:700;color:#fff;">जम्मा</td><td style="font-weight:800;font-size:15px;color:#fff;"><?php echo sp_np($tot); ?></td><td style="color:#fff;">३६</td></tr>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php /* ════════════ TAB: शुभ मुहुर्त ═══════════════════════════════════════ */
elseif($activeTab==='muhurta'): ?>

<div class="sp-card">
  <div class="sp-tool-center">
    <div class="sp-tool-icon"><i class="lucide-icon" style="width:24px;height:24px;" data-lucide="clock-4"></i></div>
    <h3 style="margin:0 0 5px;font-size:17px;font-weight:700;">आजका शुभ मुहुर्त</h3>
    <p style="margin:0;color:var(--sp-text-muted);font-size:12px;"><?php echo $pg['bs_day_np'].' '.$pg['bs_month_name'].' '.$pg['bs_year_np'].' | '.$pg['vaar_np']; ?></p>
  </div>
  <div style="padding:16px 18px 20px;">
    <div class="row g-3 mb-4">
      <?php foreach([
        [true,'sun','ब्रह्म मुहुर्त','बिहान ३:४५ – ५:१५','ध्यान, पूजा र अध्ययनका लागि सर्वोत्तम'],
        [true,'star','रुद्राभिषेक / पूजा',$pg['sunrise'].' – ७:३०','धार्मिक कार्यका लागि उत्तम'],
        [false,'alarm-clock','राहुकाल',$pg['rahu_kaal'],'यो समयमा शुभ काम सुरु नगर्नुहोस्'],
        [true,'zap','अभिजित मुहुर्त',$pg['abhijit'],'सर्वोत्तम — जुनसुकै शुभ काम सुरु गर्नुहोस्'],
        [false,'alert-circle','गुलिक काल',$pg['gulik'],'महत्वपूर्ण निर्णय नलिनुहोस्'],
        [true,'moon','प्रदोष काल','साँझ ७:०० – ७:३०','शिव पूजाका लागि उत्तम'],
        [true,'moon-star','निशीथ काल','रात १२:०० – १:३०','तान्त्रिक साधनाका लागि'],
        [false,'alert-triangle','यमघण्ट काल','दिउँसो ३:०० – ४:३०','नयाँ काम सुरु नगर्नुहोस्'],
      ] as [$good,$ico,$name,$time,$desc]): ?>
      <div class="col-md-6">
        <div class="sp-muhurta-card <?php echo $good?'':'bad'; ?>">
          <i class="lucide-icon" style="width:16px;height:16px;flex-shrink:0;margin-top:2px;" data-lucide="<?php echo $ico; ?>"></i>
          <div>
            <div class="sp-muhurta-card-title"><?php echo $good?'✅ ':'❌ '; ?><?php echo $name; ?></div>
            <div class="sp-muhurta-card-desc"><?php echo $desc; ?></div>
            <div class="sp-muhurta-card-time"><i class="lucide-icon" style="width:10px;height:10px;" data-lucide="clock"></i><?php echo $time; ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <h6 style="font-weight:700;color:var(--sp-primary);margin-bottom:12px;font-size:13px;display:flex;align-items:center;gap:6px;">
      <i class="lucide-icon" style="width:14px;height:14px;" data-lucide="check-circle"></i> शुभ कार्यहरू र सर्वोत्तम समय
    </h6>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:9px;">
      <?php foreach([['calendar-days','विवाह','अभिजित मुहुर्त'],['home','गृहप्रवेश','बिहान ७:३०+'],['briefcase','व्यापार सुरु','बिहान ९:००+'],['trending-up','लगानी / बचत','अभिजित मुहुर्त'],['plane','यात्रा','राहुकाल पछि'],['landmark','बैंकिङ','बैंक समयमा'],['book-open','विद्यारम्भ','ब्रह्म मुहुर्त'],['gift','दान','अभिजित मुहुर्त'],['users','सभा','दिउँसो २:००+'],['map-pin','नयाँ घर','पूर्णिमा नजिक']] as [$ico,$work,$time]): ?>
      <div style="background:var(--sp-muted);border-radius:9px;padding:11px 8px;text-align:center;">
        <i class="lucide-icon" style="width:20px;height:20px;color:var(--sp-primary);display:block;margin:0 auto 5px;" data-lucide="<?php echo $ico; ?>"></i>
        <div style="font-weight:600;font-size:11px;color:var(--sp-primary);"><?php echo $work; ?></div>
        <div style="font-size:9.5px;color:var(--sp-text-muted);margin-top:2px;"><?php echo $time; ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php /* ════════════ TAB: ज्योतिष ════════════════════════════════════════════ */
elseif($activeTab==='jyotish'): ?>

<div class="sp-card">
  <div class="sp-tool-center">
    <div class="sp-tool-icon"><i class="lucide-icon" style="width:24px;height:24px;" data-lucide="telescope"></i></div>
    <h3 style="margin:0 0 5px;font-size:17px;font-weight:700;">नवग्रह स्थिति र ज्योतिष फलादेश</h3>
    <p style="margin:0;color:var(--sp-text-muted);font-size:12px;">ग्रहको वर्तमान चाल र आजका फलादेश</p>
  </div>
  <div style="padding:16px 18px 20px;">
    <div style="overflow-x:auto;margin-bottom:20px;">
      <table class="sp-table">
        <thead><tr><th>ग्रह</th><th>वर्तमान राशि</th><th>अंश</th><th>अवस्था</th><th>प्रभाव</th></tr></thead>
        <tbody>
          <?php foreach($grahas as $gr):
            $avc=$gr['avastha']==='उच्च'?'var(--sp-primary)':($gr['avastha']==='नेच'?'var(--sp-secondary)':'#6b7280'); ?>
          <tr>
            <td style="font-weight:600;"><?php echo $gr['name']; ?></td>
            <td><span class="sp-badge" style="background:var(--sp-muted);color:var(--sp-primary);"><?php echo $gr['rashi']; ?></span></td>
            <td style="color:var(--sp-text-muted);"><?php echo $gr['deg_np']; ?></td>
            <td><span class="sp-badge" style="background:<?php echo $avc; ?>;color:#fff;"><?php echo $gr['avastha']; ?></span></td>
            <td style="font-size:11px;color:#4a5a4f;"><?php echo $gr['fal']; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="row g-4">
      <div class="col-md-6">
        <h6 style="font-weight:700;color:var(--sp-primary);margin-bottom:10px;font-size:13px;display:flex;align-items:center;gap:5px;">
          <i class="lucide-icon" style="width:13px;height:13px;" data-lucide="trending-up"></i> आजका फलादेश
        </h6>
        <?php foreach([[true,'briefcase','व्यापार / करियर','बुध उच्चको स्थितिले व्यापार र संचारमा विशेष लाभ।'],[true,'heart','स्वास्थ्य','चन्द्र उच्चको प्रभावले शारीरिक तथा मानसिक स्वास्थ्य राम्रो।'],[true,'users','परिवार र समाज','शुक्रको अनुकूल स्थितिले परिवारमा प्रेम र समाजमा सम्मान।'],[false,'alert-triangle','सावधानी','मंगलको नेच स्थितिले क्रोध र हतारोबाट बच्नुहोस्।']] as [$g,$ico,$t,$p]): ?>
        <div style="display:flex;gap:9px;margin-bottom:9px;padding:9px 11px;border-radius:8px;background:<?php echo $g?'#f0fdf4':'#fffbeb'; ?>;border:1px solid <?php echo $g?'#86efac':'#fde68a'; ?>;">
          <i class="lucide-icon" style="width:14px;height:14px;flex-shrink:0;margin-top:2px;color:<?php echo $g?'var(--sp-primary)':'#d97706'; ?>;" data-lucide="<?php echo $ico; ?>"></i>
          <div>
            <div style="font-weight:600;font-size:12px;color:<?php echo $g?'var(--sp-primary)':'#92400e'; ?>"><?php echo $t; ?></div>
            <div style="font-size:11px;color:var(--sp-text-muted);margin-top:2px;"><?php echo $p; ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="col-md-6">
        <h6 style="font-weight:700;color:var(--sp-primary);margin-bottom:10px;font-size:13px;display:flex;align-items:center;gap:5px;">
          <i class="lucide-icon" style="width:13px;height:13px;" data-lucide="sparkles"></i> आजका उपाय र सुझाव
        </h6>
        <div style="background:var(--sp-muted);border-radius:10px;padding:13px;">
          <?php foreach([['book-open','आजको मन्त्र','ॐ श्री गणेशाय नमः'],['gem','शुभ रत्न','मोती (Pearl) — चन्द्र बलिया'],['gift','दान सुझाव','सेता वस्तु, दूध, चाँदी'],['star','शुभ रंग','सेतो, हल्का नीलो'],['clock-4','शुभ समय',$pg['abhijit']],['map-pin','शुभ दिशा','उत्तर']] as [$ico,$l,$v]): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--sp-border);">
            <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--sp-text-muted);">
              <i class="lucide-icon" style="width:11px;height:11px;color:var(--sp-primary);" data-lucide="<?php echo $ico; ?>"></i><?php echo $l; ?>
            </div>
            <div style="font-weight:600;font-size:11px;color:var(--sp-primary);"><?php echo htmlspecialchars($v); ?></div>
          </div>
          <?php endforeach; ?>
          <p style="font-size:9.5px;color:var(--sp-text-muted);margin:8px 0 0;">व्यक्तिगत कुण्डलीका लागि अनुभवी ज्योतिषीसँग परामर्श लिनुहोस्।</p>
        </div>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>
</div><!-- /tab content -->
</div><!-- /container -->
</section>

<?php require_once 'includes/footer.php'; ?>
<script>if(typeof lucide!=='undefined')lucide.createIcons();</script>
