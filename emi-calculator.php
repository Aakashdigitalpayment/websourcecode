<?php
/**
 * EMI Loan Calculator — ईएमआई ऋण क्याल्कुलेटर
 * File: emi-calculator.php
 *
 * Member ले आफ्नो ऋणको:
 *   - मासिक EMI हेर्न सक्छ
 *   - कुल ब्याज र भुक्तानी हेर्न सक्छ
 *   - पूरो महिनावार भुक्तानी तालिका हेर्न सक्छ
 *   - Table print गर्न सक्छ
 *
 * Calculator JavaScript मा real-time calculate हुन्छ
 * EMI Formula: P × r × (1+r)^n / ((1+r)^n - 1)
 */
require_once 'includes/config.php';
$pageTitle = isEnglish() ? 'EMI Loan Calculator' : 'EMI ऋण क्याल्कुलेटर';
require_once 'includes/header.php';

/* Cooperative ले दिने ऋण प्रकार र उनीहरूको default ब्याज दर */
$loanTypes = [
    'home'      => ['np' => 'घर कर्जा',        'en' => 'Home Loan',        'rate' => 11],
    'business'  => ['np' => 'व्यापार कर्जा',    'en' => 'Business Loan',    'rate' => 14],
    'personal'  => ['np' => 'व्यक्तिगत कर्जा',  'en' => 'Personal Loan',    'rate' => 16],
    'education' => ['np' => 'शिक्षा कर्जा',     'en' => 'Education Loan',   'rate' => 10],
    'vehicle'   => ['np' => 'सवारी कर्जा',      'en' => 'Vehicle Loan',     'rate' => 13],
    'agri'      => ['np' => 'कृषि कर्जा',       'en' => 'Agriculture Loan', 'rate' => 9],
    'custom'    => ['np' => 'आफ्नै दर राख्ने',  'en' => 'Custom Rate',      'rate' => 12],
];
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><i class="fas fa-calculator me-2"></i><?php echo isEnglish() ? 'EMI Loan Calculator' : 'EMI ऋण क्याल्कुलेटर'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo isEnglish() ? 'EMI Calculator' : 'EMI क्याल्कुलेटर'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<section class="section-padding">
<div class="container">

    <!-- Top explanation row -->
    <div class="row mb-4">
        <div class="col-12 text-center">
            <p class="text-muted mb-0">
                <?php echo isEnglish()
                    ? 'Enter your loan details below to instantly calculate your EMI and see the full repayment schedule.'
                    : 'तल ऋणको विवरण भर्नुहोस् — मासिक EMI र पूरो भुक्तानी तालिका तुरुन्तै देखिन्छ।'; ?>
            </p>
        </div>
    </div>

    <div class="row g-4">

        <!-- LEFT: Input Card -->
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-sliders-h me-2"></i>
                        <?php echo isEnglish() ? 'Loan Details' : 'ऋण विवरण'; ?>
                    </h5>
                </div>
                <div class="card-body p-4">

                    <!-- Loan Type Quick Select -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <?php echo isEnglish() ? 'Loan Type' : 'ऋण प्रकार'; ?>
                        </label>
                        <div class="d-flex flex-wrap gap-2" id="loanTypeButtons">
                            <?php foreach ($loanTypes as $key => $lt): ?>
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary loan-type-btn <?php echo $key === 'custom' ? '' : ''; ?>"
                                    data-rate="<?php echo $lt['rate']; ?>"
                                    data-key="<?php echo $key; ?>">
                                <?php echo isEnglish() ? $lt['en'] : $lt['np']; ?>
                                <span class="badge bg-primary ms-1"><?php echo $lt['rate']; ?>%</span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted mt-1 d-block">
                            <?php echo isEnglish()
                                ? 'Click a loan type to auto-fill the interest rate.'
                                : 'ऋण प्रकारमा click गर्नुहोस् — ब्याज दर automatic भरिन्छ।'; ?>
                        </small>
                    </div>

                    <hr>

                    <!-- Loan Amount -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold d-flex justify-content-between">
                            <span><?php echo isEnglish() ? 'Loan Amount (रु.)' : 'ऋण रकम (रु.)'; ?></span>
                            <span class="text-primary fw-bold" id="amountDisplay">रु. 5,00,000</span>
                        </label>
                        <input type="range" class="form-range emi-range" id="amountSlider"
                               min="10000" max="10000000" step="10000" value="500000">
                        <div class="d-flex justify-content-between small text-muted mt-1">
                            <span>रु. 10,000</span>
                            <span>रु. 1,00,00,000</span>
                        </div>
                        <div class="input-group mt-2">
                            <span class="input-group-text">रु.</span>
                            <input type="number" class="form-control" id="amountInput"
                                   value="500000" min="10000" max="10000000" step="1000"
                                   placeholder="ऋण रकम">
                        </div>
                    </div>

                    <!-- Interest Rate -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold d-flex justify-content-between">
                            <span><?php echo isEnglish() ? 'Annual Interest Rate (%)' : 'वार्षिक ब्याज दर (%)'; ?></span>
                            <span class="text-primary fw-bold" id="rateDisplay">12.00%</span>
                        </label>
                        <input type="range" class="form-range emi-range" id="rateSlider"
                               min="1" max="30" step="0.25" value="12">
                        <div class="d-flex justify-content-between small text-muted mt-1">
                            <span>1%</span>
                            <span>30%</span>
                        </div>
                        <div class="input-group mt-2">
                            <input type="number" class="form-control" id="rateInput"
                                   value="12" min="1" max="30" step="0.25"
                                   placeholder="ब्याज दर">
                            <span class="input-group-text">% / वर्ष</span>
                        </div>
                    </div>

                    <!-- Tenure -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold d-flex justify-content-between">
                            <span><?php echo isEnglish() ? 'Loan Tenure' : 'ऋण अवधि'; ?></span>
                            <span class="text-primary fw-bold" id="tenureDisplay">5 वर्ष (60 महिना)</span>
                        </label>
                        <!-- Year / Month toggle -->
                        <div class="btn-group btn-group-sm w-100 mb-2" role="group">
                            <input type="radio" class="btn-check" name="tenureUnit" id="tenureYears" value="years" checked>
                            <label class="btn btn-outline-primary" for="tenureYears">
                                <?php echo isEnglish() ? 'Years' : 'वर्ष'; ?>
                            </label>
                            <input type="radio" class="btn-check" name="tenureUnit" id="tenureMonths" value="months">
                            <label class="btn btn-outline-primary" for="tenureMonths">
                                <?php echo isEnglish() ? 'Months' : 'महिना'; ?>
                            </label>
                        </div>
                        <input type="range" class="form-range emi-range" id="tenureSlider"
                               min="1" max="30" step="1" value="5">
                        <div class="d-flex justify-content-between small text-muted mt-1">
                            <span id="tenureMin">1 वर्ष</span>
                            <span id="tenureMax">30 वर्ष</span>
                        </div>
                        <div class="input-group mt-2">
                            <input type="number" class="form-control" id="tenureInput"
                                   value="5" min="1" max="30" step="1"
                                   placeholder="अवधि">
                            <span class="input-group-text" id="tenureUnitLabel">वर्ष</span>
                        </div>
                    </div>

                    <!-- Calculate Button -->
                    <button type="button" class="btn btn-primary btn-lg w-100" id="calcBtn" onclick="calculateEMI()">
                        <i class="fas fa-calculator me-2"></i>
                        <?php echo isEnglish() ? 'Calculate EMI' : 'EMI गणना गर्नुहोस्'; ?>
                    </button>

                    <!-- Apply for Loan CTA -->
                    <div class="mt-3 text-center">
                        <a href="<?php echo SITE_URL; ?>loan-apply.php" class="btn btn-outline-success btn-sm">
                            <i class="fas fa-file-alt me-1"></i>
                            <?php echo isEnglish() ? 'Apply for Loan →' : 'ऋणको लागि आवेदन दिनुहोस् →'; ?>
                        </a>
                    </div>

                </div>
            </div>
        </div><!-- /col-lg-5 -->

        <!-- RIGHT: Result Cards + Chart + Table -->
        <div class="col-lg-7">

            <!-- Summary Cards -->
            <div class="row g-3 mb-4" id="resultSummary" style="display:none;">
                <div class="col-sm-4">
                    <div class="card border-0 shadow-sm text-center h-100" style="border-left:4px solid var(--primary-color) !important; border-left-width:4px !important;">
                        <div class="card-body py-3">
                            <div class="text-muted small mb-1">
                                <?php echo isEnglish() ? 'Monthly EMI' : 'मासिक EMI'; ?>
                            </div>
                            <div class="fw-bold text-primary" style="font-size:1.4rem;" id="resEmi">—</div>
                            <div class="small text-muted"><?php echo isEnglish() ? 'per month' : 'प्रति महिना'; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="card border-0 shadow-sm text-center h-100">
                        <div class="card-body py-3">
                            <div class="text-muted small mb-1">
                                <?php echo isEnglish() ? 'Total Interest' : 'कुल ब्याज'; ?>
                            </div>
                            <div class="fw-bold text-warning" style="font-size:1.4rem;" id="resInterest">—</div>
                            <div class="small text-muted" id="resInterestPct"></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="card border-0 shadow-sm text-center h-100">
                        <div class="card-body py-3">
                            <div class="text-muted small mb-1">
                                <?php echo isEnglish() ? 'Total Payment' : 'कुल भुक्तानी'; ?>
                            </div>
                            <div class="fw-bold text-success" style="font-size:1.4rem;" id="resTotal">—</div>
                            <div class="small text-muted"><?php echo isEnglish() ? 'Principal + Interest' : 'मूलधन + ब्याज'; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Breakdown Bar -->
            <div class="card shadow-sm mb-4" id="breakdownCard" style="display:none;">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3">
                        <i class="fas fa-chart-bar me-2 text-primary"></i>
                        <?php echo isEnglish() ? 'Payment Breakdown' : 'भुक्तानी विवरण'; ?>
                    </h6>
                    <div class="progress mb-2" style="height:28px;border-radius:8px;overflow:hidden;">
                        <div id="principalBar" class="progress-bar fw-semibold"
                             role="progressbar"
                             style="background:linear-gradient(135deg,var(--primary-color),var(--primary-light));">
                            <span id="principalBarLabel"></span>
                        </div>
                        <div id="interestBar" class="progress-bar fw-semibold"
                             role="progressbar"
                             style="background:linear-gradient(135deg,var(--secondary-color),#ffca2c);color:#333;">
                            <span id="interestBarLabel"></span>
                        </div>
                    </div>
                    <div class="d-flex justify-content-center gap-4 flex-wrap mt-2 small">
                        <span>
                            <span class="badge" style="background:var(--primary-color);">&nbsp;&nbsp;</span>
                            <?php echo isEnglish() ? 'Principal' : 'मूलधन'; ?> — <strong id="principalPct"></strong>
                        </span>
                        <span>
                            <span class="badge bg-warning">&nbsp;&nbsp;</span>
                            <?php echo isEnglish() ? 'Interest' : 'ब्याज'; ?> — <strong id="interestPct"></strong>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Repayment Schedule Table -->
            <div class="card shadow-sm" id="scheduleCard" style="display:none;">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-table me-2 text-primary"></i>
                        <?php echo isEnglish() ? 'Monthly Repayment Schedule' : 'मासिक भुक्तानी तालिका'; ?>
                    </h6>
                    <div class="d-flex gap-2 flex-wrap">
                        <!-- Yearly summary toggle -->
                        <div class="form-check form-switch mb-0 d-flex align-items-center gap-2">
                            <input class="form-check-input" type="checkbox" id="yearlyToggle"
                                   onchange="toggleYearly(this.checked)" style="width:38px;height:20px;">
                            <label class="form-check-label small" for="yearlyToggle">
                                <?php echo isEnglish() ? 'Yearly Summary' : 'वार्षिक सारांश'; ?>
                            </label>
                        </div>
                        <!-- Print button -->
                        <button class="btn btn-sm btn-outline-secondary" onclick="printSchedule()">
                            <i class="fas fa-print me-1"></i><?php echo isEnglish() ? 'Print' : 'प्रिन्ट'; ?>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height:420px;overflow-y:auto;">
                        <table class="table table-sm table-hover table-bordered mb-0" id="emiTable">
                            <thead class="table-dark sticky-top">
                                <tr>
                                    <th class="text-center">#</th>
                                    <th><?php echo isEnglish() ? 'Month' : 'महिना'; ?></th>
                                    <th class="text-end"><?php echo isEnglish() ? 'EMI' : 'EMI'; ?></th>
                                    <th class="text-end"><?php echo isEnglish() ? 'Principal' : 'मूलधन'; ?></th>
                                    <th class="text-end"><?php echo isEnglish() ? 'Interest' : 'ब्याज'; ?></th>
                                    <th class="text-end"><?php echo isEnglish() ? 'Balance' : 'बाँकी'; ?></th>
                                </tr>
                            </thead>
                            <tbody id="scheduleBody"></tbody>
                            <tfoot id="scheduleFoot" class="table-light fw-bold"></tfoot>
                        </table>
                    </div>
                </div>
                <div class="card-footer small text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    <?php echo isEnglish()
                        ? 'This is an approximate schedule. Actual amounts may vary slightly based on processing dates.'
                        : 'यो अनुमानित तालिका हो। वास्तविक रकम processing मितिको आधारमा केही फरक पर्न सक्छ।'; ?>
                </div>
            </div>

        </div><!-- /col-lg-7 -->
    </div><!-- /row -->

    <!-- Info Cards at bottom -->
    <div class="row g-3 mt-4">
        <div class="col-md-4">
            <div class="card border-0 bg-light">
                <div class="card-body text-center p-3">
                    <i class="fas fa-formula text-primary fa-2x mb-2"></i>
                    <h6 class="fw-semibold"><?php echo isEnglish() ? 'EMI Formula' : 'EMI सूत्र'; ?></h6>
                    <p class="small text-muted mb-0">
                        <strong>EMI = P × r × (1+r)ⁿ / ((1+r)ⁿ − 1)</strong><br>
                        P = ऋण रकम, r = मासिक ब्याज, n = महिना संख्या
                    </p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 bg-light">
                <div class="card-body text-center p-3">
                    <i class="fas fa-lightbulb text-warning fa-2x mb-2"></i>
                    <h6 class="fw-semibold"><?php echo isEnglish() ? 'EMI Tips' : 'EMI सुझाव'; ?></h6>
                    <p class="small text-muted mb-0">
                        <?php echo isEnglish()
                            ? 'Longer tenure = Lower EMI but more interest. Shorter tenure = Higher EMI but less interest.'
                            : 'लामो अवधि = कम EMI तर धेरै ब्याज। छोटो अवधि = बढी EMI तर कम ब्याज।'; ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 bg-light">
                <div class="card-body text-center p-3">
                    <i class="fas fa-phone-alt text-success fa-2x mb-2"></i>
                    <h6 class="fw-semibold"><?php echo isEnglish() ? 'Need Help?' : 'सहयोग चाहिन्छ?'; ?></h6>
                    <p class="small text-muted mb-0">
                        <?php echo isEnglish()
                            ? 'Contact our loan officers for personalized guidance.'
                            : 'ऋण सम्बन्धी जानकारीको लागि हाम्रो कार्यालयमा सम्पर्क गर्नुहोस्।'; ?>
                    </p>
                    <a href="<?php echo SITE_URL; ?>contact.php" class="btn btn-sm btn-success mt-2">
                        <?php echo isEnglish() ? 'Contact Us' : 'सम्पर्क'; ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

</div>
</section>

<!-- Print-only styles -->

<script>
/* -------------------------------------------------------
   EMI Calculator — real-time calculation
   Formula: EMI = P × r × (1+r)^n / ((1+r)^n - 1)
------------------------------------------------------- */

const isEn = <?php echo isEnglish() ? 'true' : 'false'; ?>;

/* Nepali month names — तालिकामा देखाउन */
const npMonths = ['बैशाख','जेठ','असार','श्रावण','भाद्र','आश्विन','कार्तिक','मंसिर','पुष','माघ','फाल्गुण','चैत्र'];
const enMonths = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

/* Format number with commas — नेपाली number format */
function fmtRs(n) {
    return 'रु. ' + Math.round(n).toLocaleString('en-IN');
}
function fmtRsDec(n) {
    return 'रु. ' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/* ---- Slider ↔ Input sync ---- */
const amountSlider = document.getElementById('amountSlider');
const amountInput  = document.getElementById('amountInput');
const rateSlider   = document.getElementById('rateSlider');
const rateInput    = document.getElementById('rateInput');
const tenureSlider = document.getElementById('tenureSlider');
const tenureInput  = document.getElementById('tenureInput');

function syncDisplay() {
    const amt = parseFloat(amountInput.value) || 500000;
    document.getElementById('amountDisplay').textContent = fmtRs(amt);

    const rate = parseFloat(rateInput.value) || 12;
    document.getElementById('rateDisplay').textContent = rate.toFixed(2) + '%';

    const ten = parseFloat(tenureInput.value) || 5;
    const isYears = document.querySelector('[name="tenureUnit"]:checked').value === 'years';
    const months = isYears ? ten * 12 : ten;
    if (isYears) {
        document.getElementById('tenureDisplay').textContent = ten + ' वर्ष (' + months + ' महिना)';
    } else {
        document.getElementById('tenureDisplay').textContent = ten + ' महिना';
    }
}

/* Amount sync */
amountSlider.addEventListener('input', () => { amountInput.value = amountSlider.value; syncDisplay(); autoCalc(); });
amountInput.addEventListener('input', () => { amountSlider.value = amountInput.value; syncDisplay(); autoCalc(); });

/* Rate sync */
rateSlider.addEventListener('input', () => { rateInput.value = rateSlider.value; syncDisplay(); autoCalc(); });
rateInput.addEventListener('input', () => { rateSlider.value = rateInput.value; syncDisplay(); autoCalc(); });

/* Tenure sync + unit toggle */
tenureSlider.addEventListener('input', () => { tenureInput.value = tenureSlider.value; syncDisplay(); autoCalc(); });
tenureInput.addEventListener('input', () => { tenureSlider.value = tenureInput.value; syncDisplay(); autoCalc(); });

document.querySelectorAll('[name="tenureUnit"]').forEach(radio => {
    radio.addEventListener('change', () => {
        const isYears = radio.value === 'years';
        if (isYears) {
            tenureSlider.min = 1; tenureSlider.max = 30; tenureSlider.step = 1;
            tenureInput.min  = 1; tenureInput.max  = 30;
            document.getElementById('tenureMin').textContent = '1 वर्ष';
            document.getElementById('tenureMax').textContent = '30 वर्ष';
            document.getElementById('tenureUnitLabel').textContent = 'वर्ष';
            if (parseFloat(tenureInput.value) > 30) tenureInput.value = 30;
        } else {
            tenureSlider.min = 1; tenureSlider.max = 360; tenureSlider.step = 1;
            tenureInput.min  = 1; tenureInput.max  = 360;
            document.getElementById('tenureMin').textContent = '1 महिना';
            document.getElementById('tenureMax').textContent = '360 महिना';
            document.getElementById('tenureUnitLabel').textContent = 'महिना';
        }
        syncDisplay();
        autoCalc();
    });
});

/* Loan type quick select — ब्याज दर auto-fill */
document.querySelectorAll('.loan-type-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.loan-type-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const rate = parseFloat(btn.dataset.rate);
        rateInput.value  = rate;
        rateSlider.value = rate;
        syncDisplay();
        autoCalc();
    });
});

/* ---- Main EMI Calculation ---- */
let scheduleData = []; /* full monthly schedule data — yearly toggle को लागि */

function calculateEMI() {
    const P = parseFloat(amountInput.value) || 0;
    const annualRate = parseFloat(rateInput.value) || 0;
    const tenureVal = parseFloat(tenureInput.value) || 0;
    const isYears = document.querySelector('[name="tenureUnit"]:checked').value === 'years';
    const n = isYears ? tenureVal * 12 : tenureVal; /* total months */

    if (P <= 0 || annualRate <= 0 || n <= 0) return;

    const r = annualRate / 100 / 12; /* मासिक ब्याज दर */

    /* EMI formula */
    const emi = P * r * Math.pow(1 + r, n) / (Math.pow(1 + r, n) - 1);
    const totalPayment = emi * n;
    const totalInterest = totalPayment - P;
    const principalPct = (P / totalPayment) * 100;
    const interestPct  = 100 - principalPct;

    /* Summary cards */
    document.getElementById('resEmi').textContent       = fmtRs(emi);
    document.getElementById('resInterest').textContent  = fmtRs(totalInterest);
    document.getElementById('resInterestPct').textContent = '(' + interestPct.toFixed(1) + '% of total)';
    document.getElementById('resTotal').textContent     = fmtRs(totalPayment);

    /* Progress bar */
    document.getElementById('principalBar').style.width = principalPct.toFixed(1) + '%';
    document.getElementById('principalBar').title = 'मूलधन ' + principalPct.toFixed(1) + '%';
    document.getElementById('interestBar').style.width  = interestPct.toFixed(1) + '%';
    document.getElementById('interestBar').title = 'ब्याज ' + interestPct.toFixed(1) + '%';

    const pLabel = principalPct > 15 ? principalPct.toFixed(0) + '% मूलधन' : '';
    const iLabel = interestPct  > 10 ? interestPct.toFixed(0)  + '% ब्याज'  : '';
    document.getElementById('principalBarLabel').textContent = pLabel;
    document.getElementById('interestBarLabel').textContent  = iLabel;
    document.getElementById('principalPct').textContent = principalPct.toFixed(1) + '%';
    document.getElementById('interestPct').textContent  = interestPct.toFixed(1) + '%';

    /* Build monthly schedule */
    scheduleData = [];
    let balance = P;
    const today = new Date();

    for (let m = 1; m <= n; m++) {
        const intComponent  = balance * r;
        const prinComponent = emi - intComponent;
        balance -= prinComponent;
        if (balance < 0.01) balance = 0;

        const date = new Date(today.getFullYear(), today.getMonth() + m - 1, 1);
        const monthLabel = isEn
            ? enMonths[date.getMonth()] + ' ' + date.getFullYear()
            : npMonths[date.getMonth()] + ' ' + date.getFullYear();

        scheduleData.push({
            month: m,
            label: monthLabel,
            emi: emi,
            principal: prinComponent,
            interest: intComponent,
            balance: balance,
            year: date.getFullYear()
        });
    }

    renderTable(false); /* महिनावार table render गर्ने */

    /* Show result sections */
    document.getElementById('resultSummary').style.display = '';
    document.getElementById('breakdownCard').style.display = '';
    document.getElementById('scheduleCard').style.display  = '';
    document.getElementById('scheduleCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/* ---- Render monthly OR yearly table ---- */
function renderTable(yearly) {
    const tbody = document.getElementById('scheduleBody');
    const tfoot = document.getElementById('scheduleFoot');
    tbody.innerHTML = '';
    tfoot.innerHTML = '';

    let grandEmi = 0, grandPrincipal = 0, grandInterest = 0;

    if (!yearly) {
        /* Monthly table — हरेक महिनाको row */
        scheduleData.forEach(row => {
            grandEmi       += row.emi;
            grandPrincipal += row.principal;
            grandInterest  += row.interest;

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="text-center text-muted small">${row.month}</td>
                <td class="small">${row.label}</td>
                <td class="text-end">${fmtRsDec(row.emi)}</td>
                <td class="text-end text-success">${fmtRsDec(row.principal)}</td>
                <td class="text-end text-warning">${fmtRsDec(row.interest)}</td>
                <td class="text-end ${row.balance < 1 ? 'text-success fw-bold' : ''}">${fmtRsDec(row.balance)}</td>
            `;
            tbody.appendChild(tr);
        });
    } else {
        /* Yearly summary — प्रत्येक वर्षको total */
        const years = {};
        scheduleData.forEach(row => {
            if (!years[row.year]) years[row.year] = { year: row.year, emi: 0, principal: 0, interest: 0, balance: 0, count: 0 };
            years[row.year].emi       += row.emi;
            years[row.year].principal += row.principal;
            years[row.year].interest  += row.interest;
            years[row.year].balance    = row.balance;
            years[row.year].count++;
        });

        let idx = 1;
        Object.values(years).forEach(yr => {
            grandEmi       += yr.emi;
            grandPrincipal += yr.principal;
            grandInterest  += yr.interest;

            const tr = document.createElement('tr');
            tr.className = 'year-row';
            tr.innerHTML = `
                <td class="text-center">${idx++}</td>
                <td>${isEn ? 'Year ' + yr.year : 'वर्ष ' + yr.year} <small class="text-muted">(${yr.count} ${isEn ? 'months' : 'महिना'})</small></td>
                <td class="text-end">${fmtRsDec(yr.emi)}</td>
                <td class="text-end text-success">${fmtRsDec(yr.principal)}</td>
                <td class="text-end text-warning">${fmtRsDec(yr.interest)}</td>
                <td class="text-end ${yr.balance < 1 ? 'text-success fw-bold' : ''}">${fmtRsDec(yr.balance)}</td>
            `;
            tbody.appendChild(tr);
        });
    }

    /* Footer total row */
    const tftr = document.createElement('tr');
    tftr.innerHTML = `
        <td colspan="2" class="text-end fw-bold">${isEn ? 'TOTAL' : 'जम्मा'}</td>
        <td class="text-end fw-bold">${fmtRsDec(grandEmi)}</td>
        <td class="text-end fw-bold text-success">${fmtRsDec(grandPrincipal)}</td>
        <td class="text-end fw-bold text-warning">${fmtRsDec(grandInterest)}</td>
        <td class="text-end">—</td>
    `;
    tfoot.appendChild(tftr);
}

/* Yearly toggle */
function toggleYearly(checked) {
    if (scheduleData.length > 0) renderTable(checked);
}

/* Auto-calculate on any input change — instant feedback */
let autoCalcTimer;
function autoCalc() {
    clearTimeout(autoCalcTimer);
    autoCalcTimer = setTimeout(calculateEMI, 300);
}

/* Print function */
function printSchedule() {
    const title = document.querySelector('.print-title');
    const amt   = document.getElementById('amountInput').value;
    const rate  = document.getElementById('rateInput').value;
    const ten   = document.getElementById('tenureInput').value;
    const unit  = document.querySelector('[name="tenureUnit"]:checked').value;
    title.textContent = `EMI Schedule — रु. ${parseInt(amt).toLocaleString('en-IN')} @ ${rate}% / ${ten} ${unit}`;
    window.print();
}

/* ---- Initialize on load ---- */
syncDisplay();
/* First loan type button active by default — home loan */
document.querySelector('.loan-type-btn').click();
/* Initial auto-calculate */
setTimeout(calculateEMI, 200);
</script>

<!-- Print title (hidden on screen, shown in print) -->
<div class="print-title"></div>

<?php require_once 'includes/footer.php'; ?>
