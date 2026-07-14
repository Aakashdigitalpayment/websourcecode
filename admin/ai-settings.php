<?php
/**
 * Admin: AI Chat settings (Gemini / OpenAI / DeepSeek per सहकारी)
 * Key stored in site_settings; blank password keeps previous value.
 */
define('IS_ADMIN_PAGE', true);
$pageTitle = 'AI Chat सेटिङ्स';
$currentPage = 'ai-settings';

require_once '../includes/config.php';
require_once __DIR__ . '/../includes/ai-chat-helpers.php';
require_once __DIR__ . '/../includes/ai-chat-providers.php';

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
            if (!in_array($provider, ['openai', 'gemini', 'deepseek'], true)) {
                $provider = 'gemini';
            }
            updateSetting('ai_provider', $provider);

            $modelPick = clean_text($_POST['ai_model_pick'] ?? '', 80);
            $modelCustom = clean_text($_POST['ai_model_custom'] ?? '', 80);
            $model = ($modelPick === 'custom') ? $modelCustom : $modelPick;
            if ($model === '') {
                $model = ai_chat_default_model($provider);
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
        } elseif ($action === 'test') {
            $key = ai_chat_get_api_key();
            if ($key === '') {
                throw new Exception('पहिले API key सेभ गर्नुहोस्।');
            }
            $provider = ai_chat_provider();
            $model = ai_chat_model();
            $reply = ai_chat_ask_provider(
                $provider,
                $key,
                $model,
                'You are a connection test. Reply with exactly: OK',
                'ping'
            );
            $preview = mb_substr(trim($reply), 0, 80);
            setFlash('success', 'Connection OK (' . $provider . ' / ' . $model . '): ' . $preview);
        }
    } catch (Throwable $e) {
        $prefix = (($action ?? '') === 'test') ? 'Test असफल: ' : '';
        setFlash('error', $prefix . $e->getMessage());
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

$geminiModels = ['gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-3.5-flash'];
$deepseekModels = ['deepseek-chat', 'deepseek-reasoner'];
$openaiModels = ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini'];
$known = array_merge($geminiModels, $deepseekModels, $openaiModels);
/* Saved value may be legacy ID — picker shows the live remapped model. */
$displayModel = ai_chat_model();
$modelIsCustom = $displayModel !== '' && !in_array($displayModel, $known, true);

$_flash = getFlash();
$publicUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') . '/' : '../';
?>

<?php
echo adminPageHeader(
    'AI Chat सेटिङ्स',
    'fa-robot',
    'हरेक सहकारीले आफ्नै Gemini/OpenAI/DeepSeek key राख्छ। Visitor लाई निःशुल्क च्याट; जवाफ यसै साइटको डाटाबाट।',
    ($enabled && $hasKey
        ? '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25"><i class="fas fa-check-circle me-1"></i>सक्रिय</span>'
        : '<span class="badge admin-stat-badge bg-secondary-subtle text-secondary border"><i class="fas fa-pause-circle me-1"></i>बन्द / key छैन</span>')
    . ' <a class="btn btn-sm btn-outline-success ms-1" href="' . htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">साइट हेर्नुहोस्</a>'
);
if ($_flash) {
    $ft = ($_flash['type'] ?? '') === 'error' ? 'danger' : (string)($_flash['type'] ?? 'info');
    echo adminAlert($ft, (string)($_flash['message'] ?? ''));
}
?>

<div class="alert alert-success border-success border-opacity-25">
    <strong>सिफारिस:</strong> सुरुमा <strong>Google Gemini</strong> + <code>gemini-2.5-flash</code> (free tier)।
    Traffic बढ्यो वा paid mode चाहनु भए <strong>DeepSeek</strong> छान्नुहोस् — सस्तो, quality राम्रो (<a href="https://platform.deepseek.com" target="_blank" rel="noopener">platform.deepseek.com</a> मा top-up)।
    OpenAI पनि paid। Visitor ले कहिल्यै key हाल्दैन — Admin मा मात्र।
</div>

<div class="card admin-table-card mb-3">
    <div class="card-body py-3">
        <div class="fw-semibold mb-2"><i class="fas fa-list-ol me-1 text-success"></i>API key कहाँबाट?</div>
        <ul class="mb-0 small text-muted ps-3">
            <li><strong>Gemini (free):</strong> <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google AI Studio → API key</a></li>
            <li><strong>DeepSeek (paid, सस्तो):</strong> <a href="https://platform.deepseek.com/api_keys" target="_blank" rel="noopener">DeepSeek Platform → API Keys</a> — balance top-up गर्नुपर्छ</li>
            <li><strong>OpenAI (paid):</strong> <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">OpenAI API keys</a></li>
        </ul>
        <ol class="mb-0 mt-2 small text-muted ps-3">
            <li>Provider छान्नुहोस् → key paste → <strong>सेभ</strong></li>
            <li><strong>Test connection</strong> → OK आएपछि Quick Help मा «AI च्याट» देखिन्छ</li>
        </ol>
    </div>
</div>

<div class="card admin-table-card">
    <div class="card-body">
        <form method="post" id="ai-settings-form">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save">

            <div class="form-check form-switch mb-4">
                <input class="form-check-input" type="checkbox" role="switch" id="ai_chat_enabled" name="ai_chat_enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
                <label class="form-check-label fw-semibold" for="ai_chat_enabled">AI Chat सार्वजनिक मेनुमा देखाउने</label>
                <div class="form-text">सक्रिय + API key भए मात्र Quick Help → «सहयोग» → AI च्याट देखिन्छ।</div>
            </div>

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">AI Provider</label>
                    <select name="ai_provider" id="ai_provider" class="form-select">
                        <option value="gemini" <?php echo $provider === 'gemini' ? 'selected' : ''; ?>>Google Gemini (सिफारिस — free tier)</option>
                        <option value="deepseek" <?php echo $provider === 'deepseek' ? 'selected' : ''; ?>>DeepSeek (paid — सस्तो)</option>
                        <option value="openai" <?php echo $provider === 'openai' ? 'selected' : ''; ?>>OpenAI (paid)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Model</label>
                    <select name="ai_model_pick" id="ai_model_pick" class="form-select">
                        <optgroup label="Gemini" id="opt-gemini">
                            <?php foreach ($geminiModels as $m): ?>
                            <option value="<?php echo htmlspecialchars($m); ?>" <?php echo (!$modelIsCustom && $displayModel === $m) ? 'selected' : ''; ?>><?php echo htmlspecialchars($m); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="OpenAI" id="opt-openai">
                            <?php foreach ($openaiModels as $m): ?>
                            <option value="<?php echo htmlspecialchars($m); ?>" <?php echo (!$modelIsCustom && $displayModel === $m) ? 'selected' : ''; ?>><?php echo htmlspecialchars($m); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="DeepSeek" id="opt-deepseek">
                            <?php foreach ($deepseekModels as $m): ?>
                            <option value="<?php echo htmlspecialchars($m); ?>" <?php echo (!$modelIsCustom && $displayModel === $m) ? 'selected' : ''; ?>><?php echo htmlspecialchars($m); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <option value="custom" <?php echo $modelIsCustom ? 'selected' : ''; ?>>अन्य (custom)…</option>
                    </select>
                    <input type="text" name="ai_model_custom" id="ai_model_custom" class="form-control mt-2 <?php echo $modelIsCustom ? '' : 'd-none'; ?>"
                           value="<?php echo $modelIsCustom ? htmlspecialchars($displayModel) : ''; ?>" placeholder="custom model id">
                </div>
                <div class="col-md-4">
                    <label class="form-label">API Key <?php echo $hasKey ? '<span class="badge bg-success">सुरक्षित छ</span>' : '<span class="badge bg-warning text-dark">छैन</span>'; ?></label>
                    <input type="password" name="ai_api_key" class="form-control" autocomplete="new-password"
                           placeholder="<?php echo $hasKey ? '(सुरक्षित छ — बदल्न नयाँ key लेख्नुहोस्)' : 'API key यहाँ राख्नुहोस्'; ?>">
                    <div class="form-text">
                        <?php echo $encryptOn
                            ? 'Key encrypt (CRED_MASTER_KEY)।'
                            : 'SMTP जस्तै सुरक्षित राखिन्छ।'; ?>
                        खाली save = पुरानो key।
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Welcome (नेपाली)</label>
                    <textarea name="ai_welcome_np" class="form-control" rows="2" maxlength="500" placeholder="खाली = डिफल्ट सन्देश"><?php echo htmlspecialchars($welcomeNp); ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Welcome (English)</label>
                    <textarea name="ai_welcome_en" class="form-control" rows="2" maxlength="500"><?php echo htmlspecialchars($welcomeEn); ?></textarea>
                </div>
            </div>

            <div class="alert alert-info mt-4 mb-0">
                <strong>केबाट जवाफ आउँछ?</strong>
                FAQ, सूचना, सेवा, ब्याजदर, टोली/अध्यक्ष, पृष्ठ, समाचार, डाउनलोड, प्रतिवेदन, सहायता, साझेदारी — सार्वजनिक डाटा मात्र।
                पासवर्ड, पिन, OTP, सदस्य खाता ब्यालेन्स कहिल्यै पठाइँदैन।
            </div>

            <div class="mt-4 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>सेभ गर्नुहोस्</button>
            </div>
        </form>

        <div class="mt-3 d-flex flex-wrap gap-2">
            <?php if ($hasKey): ?>
            <form method="post" class="d-inline">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="test">
                <button type="submit" class="btn btn-outline-success btn-sm"><i class="fas fa-plug me-1"></i>Test connection</button>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('API key मेट्ने निश्चित हो?');">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="clear_key">
                <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash me-1"></i>Key हटाउनुहोस्</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
  var provider = document.getElementById('ai_provider');
  var pick = document.getElementById('ai_model_pick');
  var custom = document.getElementById('ai_model_custom');
  var g = document.getElementById('opt-gemini');
  var o = document.getElementById('opt-openai');
  var d = document.getElementById('opt-deepseek');
  if (!provider || !pick || !custom) return;

  function syncCustom() {
    custom.classList.toggle('d-none', pick.value !== 'custom');
  }
  function syncProvider() {
    var p = provider.value;
    var isG = p === 'gemini';
    var isO = p === 'openai';
    var isD = p === 'deepseek';
    if (g) g.disabled = !isG;
    if (o) o.disabled = !isO;
    if (d) d.disabled = !isD;
    if (pick.value !== 'custom') {
      var def = isG ? 'gemini-2.5-flash' : (isD ? 'deepseek-chat' : 'gpt-4o-mini');
      var ok = false;
      Array.prototype.forEach.call(pick.options, function (opt) {
        if (opt.value === pick.value && !opt.disabled && opt.parentElement && !opt.parentElement.disabled) ok = true;
      });
      if (!ok) pick.value = def;
    }
    syncCustom();
  }
  provider.addEventListener('change', syncProvider);
  pick.addEventListener('change', syncCustom);
  syncProvider();
})();
</script>

<?php require_once 'includes/admin-footer.php'; ?>
