<?php
require_once 'includes/config.php';
$pageTitle = isEnglish() ? 'Date Converter' : 'मिति रूपान्तरण';
require_once 'includes/header.php';
$L = getLangStrings();
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo $L['date_converter']; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo $L['date_converter']; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- Date Converter Section -->
<section class="section-padding">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="tool-card">
                    <div class="tool-header">
                        <div class="tool-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <h3><?php echo $L['date_converter']; ?></h3>
                        <p><?php echo isEnglish() ? 'Convert between Bikram Sambat (BS) and Anno Domini (AD)' : 'विक्रम संवत (बि.सं.) र ईसवी सन् (ई.सं.) बीच रूपान्तरण गर्नुहोस्'; ?></p>
                    </div>

                    <div class="date-converter-tabs">
                        <ul class="nav nav-tabs nav-fill" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="bs-ad-tab" data-bs-toggle="tab" data-bs-target="#bs-ad" type="button" role="tab">
                                    <i class="fas fa-arrow-right"></i> <?php echo $L['bs_to_ad']; ?>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="ad-bs-tab" data-bs-toggle="tab" data-bs-target="#ad-bs" type="button" role="tab">
                                    <i class="fas fa-arrow-left"></i> <?php echo $L['ad_to_bs']; ?>
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content">
                            <!-- BS to AD -->
                            <div class="tab-pane fade show active" id="bs-ad" role="tabpanel">
                                <form id="bsToAdForm" class="converter-form">
                                    <h5><i class="fas fa-calendar-alt text-primary me-2"></i><?php echo isEnglish() ? 'Enter Bikram Sambat Date' : 'विक्रम संवत मिति प्रविष्ट गर्नुहोस्'; ?></h5>
                                    <div class="date-select-group">
                                        <div class="date-select-wrapper">
                                            <select class="form-select" id="bsYear" required>
                                                <option value=""><?php echo isEnglish() ? 'Year' : 'वर्ष'; ?></option>
                                                <?php for ($i = 2070; $i <= 2110; $i++): ?>
                                                <option value="<?php echo $i; ?>" <?php echo ($i == 2082) ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div class="date-select-wrapper">
                                            <select class="form-select" id="bsMonth" required>
                                                <option value=""><?php echo isEnglish() ? 'Month' : 'महिना'; ?></option>
                                                <option value="1"><?php echo isEnglish() ? 'Baishakh (1)' : 'बैशाख (१)'; ?></option>
                                                <option value="2"><?php echo isEnglish() ? 'Jestha (2)' : 'जेठ (२)'; ?></option>
                                                <option value="3"><?php echo isEnglish() ? 'Ashadh (3)' : 'असार (३)'; ?></option>
                                                <option value="4"><?php echo isEnglish() ? 'Shrawan (4)' : 'साउन (४)'; ?></option>
                                                <option value="5"><?php echo isEnglish() ? 'Bhadra (5)' : 'भदौ (५)'; ?></option>
                                                <option value="6"><?php echo isEnglish() ? 'Ashwin (6)' : 'असोज (६)'; ?></option>
                                                <option value="7"><?php echo isEnglish() ? 'Kartik (7)' : 'कार्तिक (७)'; ?></option>
                                                <option value="8"><?php echo isEnglish() ? 'Mangsir (8)' : 'मंसिर (८)'; ?></option>
                                                <option value="9"><?php echo isEnglish() ? 'Poush (9)' : 'पुष (९)'; ?></option>
                                                <option value="10"><?php echo isEnglish() ? 'Magh (10)' : 'माघ (१०)'; ?></option>
                                                <option value="11"><?php echo isEnglish() ? 'Falgun (11)' : 'फागुन (११)'; ?></option>
                                                <option value="12"><?php echo isEnglish() ? 'Chaitra (12)' : 'चैत्र (१२)'; ?></option>
                                            </select>
                                        </div>
                                        <div class="date-select-wrapper">
                                            <select class="form-select" id="bsDay" required>
                                                <option value=""><?php echo isEnglish() ? 'Day' : 'दिन'; ?></option>
                                                <?php for ($i = 1; $i <= 32; $i++): ?>
                                                <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-primary mt-4">
                                        <i class="fas fa-sync-alt me-2"></i> <?php echo $L['convert']; ?>
                                    </button>
                                </form>
                                <div id="bsToAdResult" class="converter-result" style="display: none;">
                                    <h5><?php echo isEnglish() ? 'Converted Date (AD)' : 'रूपान्तरित मिति (ई.सं.)'; ?></h5>
                                    <div class="result-date" id="adResult"></div>
                                </div>
                            </div>

                            <!-- AD to BS -->
                            <div class="tab-pane fade" id="ad-bs" role="tabpanel">
                                <form id="adToBsForm" class="converter-form">
                                    <h5><?php echo isEnglish() ? 'Enter English Date' : 'अंग्रेजी मिति प्रविष्ट गर्नुहोस्'; ?></h5>
                                    <div class="row">
                                        <div class="col-12">
                                            <input type="date" class="form-control" id="adDate" required>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-primary mt-3">
                                        <i class="fas fa-sync-alt"></i> <?php echo $L['convert']; ?>
                                    </button>
                                </form>
                                <div id="adToBsResult" class="converter-result" style="display: none;">
                                    <h5><?php echo isEnglish() ? 'Converted Date (BS)' : 'रूपान्तरित मिति (बि.सं.)'; ?></h5>
                                    <div class="result-date" id="bsResult"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Today's Date Info -->
                <div class="today-date-card mt-4">
                    <div class="row text-center">
                        <div class="col-md-6">
                            <div class="date-box">
                                <h6><?php echo isEnglish() ? 'Today (BS)' : 'आज (बि.सं.)'; ?></h6>
                                <span class="date-display" id="todayBs">२०८२ चैत्र २८</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="date-box">
                                <h6><?php echo isEnglish() ? 'Today (AD)' : 'आज (ई.सं.)'; ?></h6>
                                <span class="date-display"><?php echo date('F j, Y'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
// Simplified BS-AD conversion (basic approximation)
// For accurate conversion, you would need a proper Nepali date library

const nepaliMonths = ['बैशाख', 'जेठ', 'असार', 'साउन', 'भदौ', 'असोज', 'कार्तिक', 'मंसिर', 'पुष', 'माघ', 'फागुन', 'चैत्र'];
const englishMonths = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

// BS to AD conversion (approximate)
document.getElementById('bsToAdForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const bsYear = parseInt(document.getElementById('bsYear').value);
    const bsMonth = parseInt(document.getElementById('bsMonth').value);
    const bsDay = parseInt(document.getElementById('bsDay').value);

    // Approximate conversion (BS year - 56.7 = AD year approximately)
    let adYear = bsYear - 57;
    let adMonth = bsMonth;
    let adDay = bsDay;

    // Adjust for month offset (BS months start ~mid-April)
    if (bsMonth <= 9) {
        adMonth = bsMonth + 3;
        if (adMonth > 12) {
            adMonth -= 12;
            adYear += 1;
        }
    } else {
        adMonth = bsMonth - 9;
        adYear += 1;
    }

    // Adjust day (approximate)
    adDay = Math.min(bsDay, 28);
    if (bsDay > 15) adDay = bsDay - 13;
    else adDay = bsDay + 13;
    if (adDay > 31) adDay = adDay - 30;
    if (adDay < 1) adDay = 1;

    const resultDate = new Date(adYear, adMonth - 1, Math.min(adDay, 28));
    const formattedDate = englishMonths[resultDate.getMonth()] + ' ' + resultDate.getDate() + ', ' + resultDate.getFullYear();

    document.getElementById('adResult').textContent = formattedDate;
    document.getElementById('bsToAdResult').style.display = 'block';
});

// AD to BS conversion (approximate)
document.getElementById('adToBsForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const adDate = new Date(document.getElementById('adDate').value);
    const adYear = adDate.getFullYear();
    const adMonth = adDate.getMonth() + 1;
    const adDay = adDate.getDate();

    // Approximate conversion
    let bsYear = adYear + 57;
    let bsMonth = adMonth;
    let bsDay = adDay;

    // Adjust for month offset
    if (adMonth >= 4) {
        bsMonth = adMonth - 3;
    } else {
        bsMonth = adMonth + 9;
        bsYear -= 1;
    }

    // Adjust day (approximate)
    if (adDay > 13) bsDay = adDay - 13;
    else bsDay = adDay + 17;
    if (bsDay > 32) bsDay = 32;

    const resultDate = bsYear + ' ' + nepaliMonths[bsMonth - 1] + ' ' + bsDay;

    document.getElementById('bsResult').textContent = resultDate;
    document.getElementById('adToBsResult').style.display = 'block';
});
</script>

<?php require_once 'includes/footer.php'; ?>
