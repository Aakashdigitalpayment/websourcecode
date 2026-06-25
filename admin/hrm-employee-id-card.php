<?php
/**
 * 💳 HRM — कर्मचारी Digital ID Card (Bank-style)
 * Standalone printable page; opens in new tab.
 * Uses HRM employee data + site_settings for branding.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth-roles.php';
require_once __DIR__ . '/includes/hrm-tables.php';
require_role('admin');

$db = getDB();
ensureHrmTables($db);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Employee not found'); }

$stmt = $db->prepare("SELECT e.*, d.name_np AS dept_name, d.name_en AS dept_name_en
                      FROM hrm_employees e
                      LEFT JOIN hrm_departments d ON d.id = e.department_id
                      WHERE e.id = ? LIMIT 1");
$stmt->execute([$id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) { http_response_code(404); exit('Employee not found'); }

/* Branding from site_settings */
$siteName    = function_exists('getSetting') ? getSetting('site_name', 'सहकारी संस्था') : 'सहकारी संस्था';
$siteNameEn  = function_exists('getSetting') ? getSetting('site_name_en', '') : '';
$sitePhone   = function_exists('getSetting') ? getSetting('phone', '') : '';
$siteEmail   = function_exists('getSetting') ? getSetting('email', '') : '';
$siteAddr    = function_exists('getSetting') ? getSetting('address', '') : '';
$siteUrl     = function_exists('getSetting') ? getSetting('site_url', '') : '';
$logoPath    = function_exists('getLocalizedLogoPath') ? getLocalizedLogoPath('assets/images/logo.png') : 'assets/images/logo.png';
$logoSrc     = '../' . ltrim($logoPath, '/');

/* Photo */
$photoSrc = !empty($emp['photo']) ? '../' . ltrim($emp['photo'], '/') : '../assets/images/default-avatar.png';

/* Validity */
$joinAd = $emp['join_date_ad'] ?: date('Y-m-d');
$expiry = date('Y-m-d', strtotime($joinAd . ' +5 years'));

/* Card number */
$cardNo    = strtoupper(str_replace(['-',' '], '', $emp['employee_code']));
$cardNoFmt = wordwrap($cardNo, 4, ' ', true);

/* QR */
$qrText = "EMPLOYEE ID CARD\nOrg: $siteName\nCode: {$emp['employee_code']}\nName: {$emp['full_name_np']}\nDesignation: ".($emp['designation']??'')."\nJoined: $joinAd\nValid Till: $expiry";
$qrSrc  = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($qrText);
?>
<!DOCTYPE html>
<html lang="ne">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ID Card — <?= htmlspecialchars($emp['full_name_np']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap">

<style>
/* ── Variables ── */
:root {
  --card-w: 540px;
  --card-h: 340px;

  /* Card palette — deep forest green */
  --c1: #021a0b;   /* darkest — bottom-left */
  --c2: #063d1a;   /* dark mid */
  --c3: #0e6630;   /* main green */
  --c4: #1b9148;   /* bright green — top-right glow */

  /* Gold accents */
  --gold:      #e2b84a;
  --gold-dark: #b8892a;
  --gold-lt:   #f5dfa0;

  /* Back */
  --back-bg:   #f4f8f5;
  --back-text: #1a2e1d;
  --back-muted:#4b6858;

  color-scheme: light;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Noto Sans Devanagari','Inter',system-ui,sans-serif;
  background: linear-gradient(150deg, #d4e8da 0%, #b8d4c0 50%, #c2ddc8 100%);
  min-height: 100vh;
  padding: 32px 16px 48px;
  -webkit-font-smoothing: antialiased;
}

/* ── Toolbar ── */
.toolbar {
  max-width: var(--card-w); margin: 0 auto 20px;
  display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;
}
.toolbar button, .toolbar a {
  background: #fff; border: 1px solid #c8d8cc; color: #1a3320;
  padding: 9px 18px; border-radius: 9px; font-weight: 600;
  cursor: pointer; text-decoration: none; font-size: 13.5px;
  font-family: inherit; transition: background .15s;
}
.toolbar button:hover { background: #f0f8f3; }
.toolbar .btn-print { background: #0e6630; color: #fff; border-color: #0e6630; }
.toolbar .btn-print:hover { background: #0a5026; }

/* ── Card stack ── */
.id-stack { display: flex; flex-direction: column; align-items: center; gap: 28px; }

/* ══════════════════════════════════════════
   FRONT CARD
══════════════════════════════════════════ */
.id-card {
  width: var(--card-w);
  height: var(--card-h);
  border-radius: 20px;
  overflow: hidden;
  position: relative;
  box-shadow:
    0 2px 0 rgba(255,255,255,.12) inset,
    0 28px 56px rgba(2,20,8,.38),
    0 8px 20px rgba(2,20,8,.22);
}

/* Main card gradient — all one cohesive flow */
.id-card.front {
  background:
    radial-gradient(ellipse at 78% 5%,  rgba(27,145,72,.55) 0%, transparent 55%),
    radial-gradient(ellipse at 10% 88%, rgba(6,61,26,.8)    0%, transparent 50%),
    linear-gradient(145deg, var(--c1) 0%, var(--c2) 30%, var(--c3) 65%, var(--c4) 100%);
  color: #fff;
}

/* Subtle diagonal lines texture */
.id-card.front::before {
  content: '';
  position: absolute; inset: 0;
  background:
    repeating-linear-gradient(
      -52deg,
      rgba(255,255,255,.03) 0 10px,
      transparent 10px 20px
    );
  pointer-events: none;
}

/* Shimmer highlight on top-left edge */
.id-card.front::after {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(to right, rgba(255,255,255,.0), rgba(255,255,255,.35), rgba(255,255,255,.0));
  pointer-events: none;
}

/* ── Header strip — NO dark overlay, flows with card ── */
.card-top {
  display: flex; align-items: center; gap: 12px;
  padding: 13px 18px 11px;
  /* Subtle lighter tint so header reads as separate but stays in the same color family */
  background: rgba(255,255,255,0.07);
  border-bottom: 1.5px solid rgba(226,184,74,.6);  /* gold line separator */
  position: relative; z-index: 2;
  backdrop-filter: brightness(1.08);
}

.card-top .logo-wrap {
  width: 40px; height: 40px;
  border-radius: 8px;
  background: #fff;
  padding: 3px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 2px 8px rgba(0,0,0,.25);
  flex-shrink: 0;
}
.card-top .logo-wrap img {
  max-width: 100%; max-height: 100%;
  object-fit: contain;
}
.card-top .logo-fallback { font-size: 22px; color: var(--c3); }

.card-top .org-block { flex: 1; min-width: 0; }
.card-top .org-name {
  font-size: 14.5px; font-weight: 800;
  line-height: 1.2; letter-spacing: .15px;
  text-shadow: 0 1px 4px rgba(0,0,0,.3);
}
.card-top .org-sub { font-size: 10px; opacity: .8; margin-top: 2px; }

.badge-emp {
  font-size: 9.5px; font-weight: 800;
  background: linear-gradient(135deg, var(--gold) 0%, var(--gold-dark) 100%);
  color: #1a0f00;
  padding: 5px 12px; border-radius: 999px;
  letter-spacing: 1.2px; text-transform: uppercase;
  box-shadow: 0 2px 8px rgba(0,0,0,.25), 0 1px 0 rgba(255,255,255,.3) inset;
  flex-shrink: 0;
}

/* ── Card body ── */
.card-body {
  display: flex; gap: 18px;
  padding: 14px 18px; position: relative; z-index: 2;
}

/* Photo */
.photo-frame {
  width: 108px; height: 134px;
  border-radius: 10px; overflow: hidden;
  border: 2.5px solid var(--gold);
  background: rgba(255,255,255,.15);
  flex-shrink: 0;
  box-shadow: 0 6px 18px rgba(0,0,0,.35), 0 1px 0 rgba(255,255,255,.2) inset;
}
.photo-frame img { width: 100%; height: 100%; object-fit: cover; display: block; }

/* Info */
.info { flex: 1; min-width: 0; }
.info .emp-name {
  font-size: 18px; font-weight: 800;
  line-height: 1.2; margin-bottom: 1px;
  text-shadow: 0 1px 4px rgba(0,0,0,.3);
  word-break: break-word;
}
.info .emp-name-en {
  font-size: 11px; opacity: .78; margin-bottom: 10px; font-weight: 400;
}

.info-grid {
  display: grid; grid-template-columns: 94px 1fr;
  gap: 2px 10px; font-size: 11.5px; line-height: 1.6;
}
.ig-lbl { opacity: .7; font-weight: 400; }
.ig-val { font-weight: 700; word-break: break-word; }

/* Chip */
.chip {
  position: absolute; top: 76px; right: 22px; z-index: 3;
  width: 44px; height: 32px;
  background: linear-gradient(145deg, #f5dfa0 0%, #d4a822 40%, #e2b84a 65%, #b8892a 100%);
  border-radius: 5px;
  box-shadow: 0 2px 6px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.35);
}
.chip::before, .chip::after {
  content: ''; position: absolute; left: 5px; right: 5px; height: 1px;
  background: rgba(100,60,0,.3);
}
.chip::before { top: 10px; }
.chip::after  { top: 20px; }

/* Card footer */
.card-foot {
  position: absolute; left: 0; right: 0; bottom: 0;
  padding: 10px 18px;
  display: flex; justify-content: space-between; align-items: flex-end;
  background: linear-gradient(to top, rgba(0,0,0,.38) 0%, transparent 100%);
  z-index: 2;
}
.card-no {
  font-family: 'Courier New',monospace; font-weight: 700;
  font-size: 15px; letter-spacing: 3px;
  text-shadow: 0 1px 3px rgba(0,0,0,.5);
  opacity: .92;
}
.validity { font-size: 10.5px; text-align: right; }
.validity .vt-label { opacity: .75; font-size: 9.5px; text-transform: uppercase; letter-spacing: .5px; }
.validity .vt-date  { font-size: 13px; font-weight: 700; margin-top: 1px; }


/* ══════════════════════════════════════════
   BACK CARD
══════════════════════════════════════════ */
.id-card.back {
  background: var(--back-bg);
  color: var(--back-text);
  box-shadow:
    0 28px 56px rgba(2,20,8,.28),
    0 8px 20px rgba(2,20,8,.16);
}

/* Top green bar on back — matches front gradient */
.back-bar {
  height: 44px;
  background: linear-gradient(135deg, var(--c1) 0%, var(--c3) 60%, var(--c4) 100%);
  position: relative;
}
.back-bar::after {
  content: '';
  position: absolute; bottom: 0; left: 0; right: 0; height: 2px;
  background: linear-gradient(to right, var(--gold-dark), var(--gold), var(--gold-dark));
}

/* Back content */
.back-grid {
  padding: 14px 20px 10px;
  display: grid;
  grid-template-columns: 1fr 148px;
  gap: 16px; align-items: start;
}
.back-info { font-size: 11.5px; line-height: 1.7; color: var(--back-text); }
.back-info .bi-row { display: flex; gap: 6px; }
.back-info .bi-label { color: var(--c3); font-weight: 700; min-width: 100px; flex-shrink: 0; }
.back-info .bi-val   { color: var(--back-text); word-break: break-word; }

.back-terms {
  margin-top: 10px;
  font-size: 9.5px; color: var(--back-muted); line-height: 1.6;
  padding-top: 8px; border-top: 1px dashed #b8cfc0;
}

.back-sig {
  display: flex; gap: 16px; margin-top: 12px;
}
.sig-box { flex: 1; text-align: center; }
.sig-line { border-top: 1px solid #8aac96; padding-top: 18px; }
.sig-label { font-size: 10px; color: var(--back-muted); margin-top: 3px; }

/* QR */
.qr-box {
  background: #fff;
  border-radius: 10px; padding: 8px;
  box-shadow: 0 2px 10px rgba(0,0,0,.1);
  display: flex; flex-direction: column; align-items: center; gap: 4px;
}
.qr-box img { display: block; width: 128px; height: 128px; }
.qr-label { font-size: 9px; color: var(--back-muted); text-align: center; }

/* Back footer */
.back-foot {
  text-align: center;
  font-size: 10px; color: var(--back-muted);
  padding: 8px 16px;
  border-top: 1px solid #d4e4da;
}


/* ══════════════════════════════════════════
   PRINT & RESPONSIVE
══════════════════════════════════════════ */
@media print {
  body { background: #fff; padding: 0; }
  .toolbar { display: none; }
  .id-stack { gap: 18px; }
  .id-card { box-shadow: none !important; border: 1px solid #ccc; }
  @page { size: auto; margin: 10mm; }
  * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
}

@media (max-width: 580px) {
  :root { --card-w: 93vw; --card-h: calc(93vw * 0.63); }
  .info .emp-name { font-size: 15px; }
  .photo-frame { width: 88px; height: 112px; }
  .chip { display: none; }
  .back-grid { grid-template-columns: 1fr; }
  .qr-box { display: none; }
}
</style>
</head>
<body>

<div class="toolbar">
  <button class="btn-print" onclick="window.print()">🖨&nbsp; Print / PDF</button>
  <a href="hrm-employee-view.php?id=<?= (int)$emp['id'] ?>">← View Profile</a>
</div>

<div class="id-stack">

  <!-- ══════ FRONT ══════ -->
  <div class="id-card front">

    <!-- Header — light-tinted, gold separator, flows with card -->
    <div class="card-top">
      <div class="logo-wrap">
        <img src="<?= htmlspecialchars($logoSrc) ?>" alt="logo"
             onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'fas fa-landmark logo-fallback\'></i>'">
      </div>
      <div class="org-block">
        <div class="org-name"><?= htmlspecialchars($siteName) ?></div>
        <?php if ($siteAddr): ?>
        <div class="org-sub"><?= htmlspecialchars($siteAddr) ?></div>
        <?php endif; ?>
      </div>
      <span class="badge-emp">Employee</span>
    </div>

    <!-- EMV Chip -->
    <div class="chip"></div>

    <!-- Body -->
    <div class="card-body">
      <div class="photo-frame">
        <img src="<?= htmlspecialchars($photoSrc) ?>" alt="photo"
             onerror="this.src='../assets/images/default-avatar.png'">
      </div>
      <div class="info">
        <div class="emp-name"><?= htmlspecialchars($emp['full_name_np']) ?></div>
        <?php if (!empty($emp['full_name_en'])): ?>
        <div class="emp-name-en"><?= htmlspecialchars($emp['full_name_en']) ?></div>
        <?php endif; ?>
        <div class="info-grid">
          <div class="ig-lbl">पद / Designation</div>
          <div class="ig-val"><?= htmlspecialchars($emp['designation'] ?? '—') ?></div>

          <?php if (!empty($emp['dept_name'])): ?>
          <div class="ig-lbl">विभाग</div>
          <div class="ig-val"><?= htmlspecialchars($emp['dept_name']) ?></div>
          <?php endif; ?>

          <?php if (!empty($emp['blood_group'])): ?>
          <div class="ig-lbl">रक्त समूह</div>
          <div class="ig-val"><?= htmlspecialchars($emp['blood_group']) ?></div>
          <?php endif; ?>

          <?php if (!empty($emp['mobile'])): ?>
          <div class="ig-lbl">सम्पर्क</div>
          <div class="ig-val"><?= htmlspecialchars($emp['mobile']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <div class="card-foot">
      <div class="card-no"><?= htmlspecialchars($cardNoFmt) ?></div>
      <div class="validity">
        <div class="vt-label">Valid Thru</div>
        <div class="vt-date"><?= htmlspecialchars(date('m/Y', strtotime($expiry))) ?></div>
      </div>
    </div>

  </div><!-- /.id-card.front -->

  <!-- ══════ BACK ══════ -->
  <div class="id-card back">

    <!-- Green bar matching front -->
    <div class="back-bar"></div>

    <div class="back-grid">
      <div class="back-info">
        <div class="bi-row"><span class="bi-label">Employee Code:</span><span class="bi-val"><?= htmlspecialchars($emp['employee_code']) ?></span></div>
        <?php if (!empty($emp['email'])): ?>
        <div class="bi-row"><span class="bi-label">Email:</span><span class="bi-val"><?= htmlspecialchars($emp['email']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($emp['citizenship_no'])): ?>
        <div class="bi-row"><span class="bi-label">Citizenship No:</span><span class="bi-val"><?= htmlspecialchars($emp['citizenship_no']) ?></span></div>
        <?php endif; ?>
        <div class="bi-row"><span class="bi-label">Joined:</span><span class="bi-val"><?= htmlspecialchars($joinAd) ?></span></div>
        <div class="bi-row"><span class="bi-label">Valid Till:</span><span class="bi-val"><?= htmlspecialchars($expiry) ?></span></div>

        <div class="back-terms">
          यो परिचय पत्र <strong><?= htmlspecialchars($siteName) ?></strong> को सम्पत्ति हो।
          भेटिएमा कृपया <?= htmlspecialchars($sitePhone ?: 'कार्यालय') ?> मा फिर्ता गराइदिनुहोस्।
          सेवा अवधि समाप्त भएपछि यो कार्ड अमान्य हुनेछ।
        </div>

        <div class="back-sig">
          <div class="sig-box">
            <div class="sig-line"></div>
            <div class="sig-label">कर्मचारी हस्ताक्षर</div>
          </div>
          <div class="sig-box">
            <div class="sig-line"></div>
            <div class="sig-label">अधिकृत हस्ताक्षर</div>
          </div>
        </div>
      </div>

      <div class="qr-box">
        <img src="<?= htmlspecialchars($qrSrc) ?>" alt="QR">
        <div class="qr-label">Scan to Verify</div>
      </div>
    </div>

    <div class="back-foot">
      <?php if ($sitePhone): ?>📞 <?= htmlspecialchars($sitePhone) ?><?php if ($siteUrl): ?> &nbsp;·&nbsp; <?php endif; ?><?php endif; ?>
      <?php if ($siteUrl): ?>🌐 <?= htmlspecialchars(preg_replace('~^https?://~','', $siteUrl)) ?><?php endif; ?>
    </div>

  </div><!-- /.id-card.back -->

</div><!-- /.id-stack -->

</body>
</html>
