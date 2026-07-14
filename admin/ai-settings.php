<?php
/**
 * Admin: AI Chat settings (OpenAI / Gemini per सहकारी)
 * Key stored in site_settings; blank password keeps previous value.
 */
define('IS_ADMIN_PAGE', true);
$pageTitle = 'AI Chat सेटिङ्स';
$currentPage = 'ai-settings';

require_once '../includes/config.php';
require_once __DIR__ . '/../includes/ai-chat-helpers.php';

if (!isAdminLoggedIn()) {
    redirect(ADMIN_URL . 'index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            updateSetting('ai_chat_enabled', !empty($_POST['ai_chat_enabled']) ? '1' : '0');
            $provider = strtolower(trim((string)($_POST['ai_provider'] ?? 'gemini')));
            if (!in_array($provider, ['openai', 'gemini'], true)) {
                $provider = 'gemini';
            }
            updateSetting('ai_provider', $provider);

            $model = clean_text($_POST['ai_model'] ?? '', 80);
            if ($model === '') {
                $model = $provider === 'openai' ? 'gpt-4o-mini' : 'gemini-2.0-flash';
            }
            updateSetting('ai_model', $model);

            $keyIn = trim((string)($_POST['ai_api_key'] ?? ''));
            if ($keyIn !== '') {
                ai_chat_store_api_key($keyIn);
            }

            updateSetting('ai_welcome_np', clean_text($_POST['ai_welcome_np'] ?? '', 500));
            updateSetting('ai_welcome_en', clean_text($_POST['ai_welcome_en'] ?? '', 500));

            setFlash('success', 'AI Chat सेटिङ सुरक्षित भयो।');
        } elseif ($action === 'clear_key') {
            updateSetting('ai_api_key', '');
            setFlash('success', 'API key हटाइयो।');
        }
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
    }
    redirect('ai-settings.php');
}

require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$enabled = getSetting('ai_chat_enabled', '0') === '1';
$provider = ai_chat_provider();
$model = (string)getSetting('ai_model', ai_chat_model());
$hasKey = ai_chat_has_api_key();
$welcomeNp = (string)getSetting('ai_welcome_np', '');
$welcomeEn = (string)getSetting('ai_welcome_en', '');
$encryptOn = ai_chat_can_encrypt();

$_flash = getFlash();
?>

<?php
echo adminPageHeader(
    'AI Chat सेटिङ्स',
    'fa-robot',
    'OpenAI वा Gemini को आफ्नै API key राख्नुहोस्। जवाफ यस सहकारीको वेबसाइट डाटाबाट मात्र दिइन्छ। Key बाँड्दैन — हरेक सहकारीको आफ्नै account।',
    ($enabled && $hasKey
        ? '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25"><i class="fas fa-check-circle me-1"></i>सक्रिय</span>'
        : '<span class="badge admin-stat-badge bg-secondary-subtle text-secondary border"><i class="fas fa-pause-circle me-1"></i>बन्द / key छैन</span>')
);
if ($_flash) {
    $ft = ($_flash['type'] ?? '') === 'error' ? 'danger' : (string)($_flash['type'] ?? 'info');
    echo adminAlert($ft, (string)($_flash['message'] ?? ''));
}
?>

<div class="card admin-table-card">
    <div class="card-body">
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save">

            <div class="form-check form-switch mb-4">
                <input class="form-check-input" type="checkbox" role="switch" id="ai_chat_enabled" name="ai_chat_enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
                <label class="form-check-label fw-semibold" for="ai_chat_enabled">AI Chat सार्वजनिक मेनुमा देखाउने</label>
                <div class="form-text">सक्रिय + API key भए मात्र Quick Help → «AI च्याट» देखिन्छ।</div>
            </div>

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">AI Provider</label>
                    <select name="ai_provider" id="ai_provider" class="form-select">
                        <option value="gemini" <?php echo $provider === 'gemini' ? 'selected' : ''; ?>>Google Gemini</option>
                        <option value="openai" <?php echo $provider === 'openai' ? 'selected' : ''; ?>>OpenAI (ChatGPT)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Model</label>
                    <input type="text" name="ai_model" class="form-control" value="<?php echo htmlspecialchars($model); ?>" placeholder="<?php echo $provider === 'openai' ? 'gpt-4o-mini' : 'gemini-2.0-flash'; ?>">
                    <div class="form-text">खाली छोडे डिफल्ट model प्रयोग हुन्छ।</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">API Key <?php echo $hasKey ? '<span class="badge bg-success">सुरक्षित छ</span>' : '<span class="badge bg-warning text-dark">छैन</span>'; ?></label>
                    <input type="password" name="ai_api_key" class="form-control" autocomplete="new-password"
                           placeholder="<?php echo $hasKey ? '(सुरक्षित छ — बदल्न नयाँ key लेख्नुहोस्)' : 'API key यहाँ राख्नुहोस्'; ?>">
                    <div class="form-text">
                        <?php echo $encryptOn
                            ? 'Key encrypt गरेर site_settings मा राखिन्छ (CRED_MASTER_KEY)।'
                            : 'Key site_settings मा सुरक्षित राखिन्छ (SMTP जस्तै)। CRED_MASTER_KEY भए encrypt हुन्छ।'; ?>
                        खाली save = पुरानो key जस्ताको तस्तै।
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Welcome (नेपाली)</label>
                    <textarea name="ai_welcome_np" class="form-control" rows="2" maxlength="500"><?php echo htmlspecialchars($welcomeNp); ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Welcome (English)</label>
                    <textarea name="ai_welcome_en" class="form-control" rows="2" maxlength="500"><?php echo htmlspecialchars($welcomeEn); ?></textarea>
                </div>
            </div>

            <div class="alert alert-info mt-4 mb-0">
                <strong>केबाट जवाफ आउँछ?</strong>
                FAQ, chatbot FAQ, सूचना, सेवा, ब्याजदर, बारेमा/भिजन/मिसन, सेवा केन्द्र — यस साइटको लाइभ डाटा।
                सदस्य खाता/ब्यालेन्स/KYC कहिल्यै पठाइँदैन। जानकारी नभए Live Chat / सम्पर्क सुझाउँछ।
            </div>

            <div class="mt-4 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>सेभ गर्नुहोस्</button>
            </div>
        </form>
        <?php if ($hasKey): ?>
        <form method="post" class="mt-2" onsubmit="return confirm('API key मेट्ने निश्चित हो?');">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="clear_key">
            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash me-1"></i>Key हटाउनुहोस्</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
  var sel = document.getElementById('ai_provider');
  var model = document.querySelector('input[name="ai_model"]');
  if (!sel || !model) return;
  var defaults = { gemini: 'gemini-2.0-flash', openai: 'gpt-4o-mini' };
  sel.addEventListener('change', function () {
    var v = model.value.trim();
    if (v === '' || v === defaults.gemini || v === defaults.openai) {
      model.value = defaults[sel.value] || '';
      model.placeholder = model.value;
    }
  });
})();
</script>

<?php require_once 'includes/admin-footer.php'; ?>
