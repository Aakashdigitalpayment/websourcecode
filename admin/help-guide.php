<?php
/**
 * ════════════════════════════════════════════════════════════════════
 * ADMIN QUICK START GUIDE — v6.0
 * ════════════════════════════════════════════════════════════════════
 * Non-developer admin staff का लागि सम्पूर्ण admin panel manual।
 * हरेक feature को step-by-step Nepali निर्देशन।
 * ════════════════════════════════════════════════════════════════════
 */
define('IS_ADMIN_PAGE', true);
require_once '../includes/config.php';
requireAdminLogin();

require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
echo adminPageHeader('Quick Start Guide','fa-book-open',
    'Admin panel का हरेक feature कसरी प्रयोग गर्ने — सम्पूर्ण Nepali निर्देशन यहाँ छ।');
?>
<div id="hg-read-progress"></div>

<button class="btn btn-outline-success btn-sm hg-mobile-toggle" onclick="document.querySelector('.hg-sidebar').classList.toggle('mobile-hidden');">
  <i class="fas fa-list me-1"></i> विषयसूची देखाउनुहोस् / लुकाउनुहोस्
</button>

<div class="hg-wrap">

  <!-- ══════════ SIDEBAR ══════════ -->
  <aside class="hg-sidebar">
    <div class="hg-search-wrap">
      <i class="fas fa-search"></i>
      <input type="text" id="hgSearch" placeholder="खोज्नुहोस्...">
    </div>

    <div class="grp">सुरुवात</div>
    <a href="#sec-intro"><i class="fas fa-rocket"></i>परिचय</a>
    <a href="#sec-login"><i class="fas fa-key"></i>Login / Logout</a>
    <a href="#sec-dashboard"><i class="fas fa-tachometer-alt"></i>Dashboard</a>

    <div class="grp">आवेदन व्यवस्थापन</div>
    <a href="#sec-kyc"><i class="fas fa-id-card"></i>KYC आवेदन</a>
    <a href="#sec-loan"><i class="fas fa-hand-holding-usd"></i>ऋण आवेदन</a>
    <a href="#sec-account"><i class="fas fa-piggy-bank"></i>खाता आवेदन</a>
    <a href="#sec-digital"><i class="fas fa-mobile-screen"></i>डिजिटल सेवा अनुरोध</a>
    <a href="#sec-welfare"><i class="fas fa-heart"></i>कल्याण दाबी</a>
    <a href="#sec-grievance"><i class="fas fa-comment-dots"></i>गुनासो व्यवस्थापन</a>
    <a href="#sec-appointment"><i class="fas fa-calendar-check"></i>अपोइन्टमेन्ट</a>

    <div class="grp">सदस्य व्यवस्थापन</div>
    <a href="#sec-members"><i class="fas fa-users"></i>सदस्यहरू (Members)</a>
    <a href="#sec-member-portal"><i class="fas fa-user-shield"></i>Member Portal Settings</a>

    <div class="grp">Website Content</div>
    <a href="#sec-notices"><i class="fas fa-bullhorn"></i>सूचनाहरू / Notices</a>
    <a href="#sec-news"><i class="fas fa-newspaper"></i>समाचार / News</a>
    <a href="#sec-gallery"><i class="fas fa-images"></i>ग्यालरी</a>
    <a href="#sec-sliders"><i class="fas fa-sliders"></i>Slider (Banner)</a>
    <a href="#sec-services"><i class="fas fa-hand-holding-heart"></i>सेवाहरू</a>
    <a href="#sec-interest"><i class="fas fa-percent"></i>ब्याज दर</a>
    <a href="#sec-pages"><i class="fas fa-file-lines"></i>Pages / पृष्ठहरू</a>
    <a href="#sec-downloads"><i class="fas fa-file-arrow-down"></i>डाउनलोड सामग्री</a>
    <a href="#sec-faqs"><i class="fas fa-circle-question"></i>FAQs / प्रश्नोत्तर</a>
    <a href="#sec-team"><i class="fas fa-people-group"></i>टोली / समिति</a>
    <a href="#sec-careers"><i class="fas fa-briefcase"></i>रोजगारी</a>

    <div class="grp">कार्यक्रम र संचार</div>
    <a href="#sec-programs"><i class="fas fa-calendar-days"></i>कार्यक्रमहरू</a>
    <a href="#sec-attendance"><i class="fas fa-clipboard-check"></i>उपस्थिति</a>
    <a href="#sec-messages"><i class="fas fa-envelope"></i>सन्देशहरू</a>
    <a href="#sec-feedback"><i class="fas fa-star"></i>प्रतिक्रिया</a>

    <div class="grp">लिलामी</div>
    <a href="#sec-auction"><i class="fas fa-gavel"></i>लिलामी / Auctions</a>
    <a href="#sec-vendor"><i class="fas fa-store"></i>Vendor Enlistment</a>

    <div class="grp">Analytics</div>
    <a href="#sec-analytics"><i class="fas fa-chart-line"></i>Analytics Dashboard</a>
    <a href="#sec-reports"><i class="fas fa-chart-column"></i>Reports</a>

    <div class="grp">सेटिङ्स र प्रशासन</div>
    <a href="#sec-notifications"><i class="fas fa-bell"></i>Notifications</a>
    <a href="#sec-settings"><i class="fas fa-gear"></i>General Settings</a>
    <a href="#sec-admins"><i class="fas fa-user-shield"></i>Admin User Management</a>
    <a href="#sec-backup"><i class="fas fa-database"></i>Backup & Restore</a>
    <a href="#sec-sitehealth"><i class="fas fa-heart-pulse"></i>Site Health</a>
    <a href="#sec-errorlog"><i class="fas fa-bug"></i>Error Log</a>

    <div class="grp">Reference</div>
    <a href="#sec-troubleshoot"><i class="fas fa-tools"></i>Troubleshooting</a>
    <a href="#sec-faq"><i class="fas fa-question-circle"></i>FAQ</a>
    <a href="#sec-contact"><i class="fas fa-headset"></i>Support सम्पर्क</a>
  </aside>

  <!-- ══════════ MAIN CONTENT ══════════ -->
  <main class="hg-content" id="hgMain">

    <!-- ══ 1. INTRO ══ -->
    <section id="sec-intro" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-rocket"></i></span> परिचय — यो Quick Start Guide के हो?</h3>
      <p>नमस्कार! यो guide <b>गैर-प्राविधिक (non-developer) admin staff</b> का लागि बनाइएको छ। तपाईंले code नजानी पनि admin panel का <b>सबै feature</b> आफैं manage गर्न सक्नुहुन्छ।</p>
      <div class="hg-info">
        <b>📌 कसरी use गर्ने:</b> बायाँ sidebar बाट विषय छान्नुहोस् — वा माथिको खोज box मा Nepali/English मा type गर्नुहोस्।
      </div>
      <h5>यो website का तीन हिस्सा:</h5>
      <table class="hg-table">
        <tr><th>हिस्सा</th><th>URL</th><th>को लागि?</th></tr>
        <tr><td>🌐 <b>Public Website</b></td><td><code>/</code></td><td>सबै visitor ले देख्ने (about, services, news, apply forms)</td></tr>
        <tr><td>👤 <b>Member Portal</b></td><td><code>/member/</code></td><td>Login गरेका सदस्यले आफ्नो data हेर्ने ठाउँ</td></tr>
        <tr><td>🛠️ <b>Admin Panel</b></td><td><code>/admin/</code></td><td>तपाईं अहिले यहाँ हुनुहुन्छ — सबै manage गर्ने</td></tr>
      </table>
    </section>

    <!-- ══ 2. LOGIN ══ -->
    <section id="sec-login" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-key"></i></span> Login र Logout</h3>
      <ol class="hg-steps-list">
        <li>Browser मा <code>तपाईंको-website.com/admin/</code> टाइप गर्नुहोस्।</li>
        <li>Username र Password हाल्नुहोस् → <span class="kbd">Login</span> click गर्नुहोस्।</li>
        <li>Logout गर्न — माथि दायाँ कुनामा <b>आफ्नो नाम / Avatar</b> → <span class="kbd">Logout</span>।</li>
      </ol>
      <div class="hg-warn">⚠️ <b>Password बिर्सियो भने:</b> Login page मा "Forgot Password" link छैन — Super Admin ले Admin Users page बाट reset गर्नुहोस्, वा <a href="change-password.php">Change Password</a> page use गर्नुहोस्।</div>
      <div class="hg-danger">🔐 <b>सुरक्षा नियम:</b> Password कसैलाई नसुनाउनुहोस्। Public computer मा काम सकेपछि तुरुन्तै logout गर्नुहोस्।</div>
    </section>

    <!-- ══ 3. DASHBOARD ══ -->
    <section id="sec-dashboard" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-tachometer-alt"></i></span> Dashboard — मुख्य पाना</h3>
      <p>Login पछि देखिने पहिलो screen Dashboard हो। यहाँ एकै नजरमा देखिन्छ:</p>
      <table class="hg-table">
        <tr><th>Element</th><th>के देखिन्छ?</th></tr>
        <tr><td>📊 <b>KPI Cards</b></td><td>Total members, KYC pending, ऋण pending, सूचनाहरू count</td></tr>
        <tr><td>🔴 <b>Alert Badges</b></td><td>माथिको bell icon मा — अपठित submissions को count</td></tr>
        <tr><td>📋 <b>Recent Activity</b></td><td>पछिल्ला admin actions को log</td></tr>
        <tr><td>🎯 <b>Quick Links</b></td><td>बढी use हुने pages का shortcut buttons</td></tr>
        <tr><td>🔑 <b>Smart Credentials</b></td><td>Office का website login passwords (encrypted)</td></tr>
      </table>
      <div class="hg-info">💡 Dashboard मा देखिने <b>red number badges</b> (जस्तै "3 KYC Pending") — ती pages मा जाएर काम गर्नुहोस्।</div>
    </section>

    <!-- ══ 4. KYC ══ -->
    <section id="sec-kyc" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-id-card"></i></span> KYC आवेदन व्यवस्थापन</h3>
      <p>सदस्यले online-kyc.php बाट पेश गरेका KYC आवेदन यहाँ आउँछन्।</p>

      <h5>📋 KYC List हेर्ने र Review गर्ने:</h5>
      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>KYC आवेदन</b> click गर्नुहोस् (वा <a href="kyc-applications.php">यहाँ click गर्नुहोस्</a>)।</li>
        <li>Filter गर्न सक्नुहुन्छ: <code>Pending</code>, <code>Under Review</code>, <code>Approved</code>, <code>Rejected</code>।</li>
        <li>कुनै आवेदनको <span class="kbd">View</span> button click गर्नुहोस् — पूरो details खुल्छ।</li>
        <li>सबै documents (citizenship, photo, signature) हेर्नुहोस्।</li>
        <li>"Update Status" section मा गएर status छान्नुहोस् र remarks लेख्नुहोस्।</li>
        <li><span class="kbd">Update</span> click गर्नुहोस् — member लाई auto email/SMS जान्छ।</li>
      </ol>

      <h5>⚠️ KYC Risk Review:</h5>
      <div class="hg-step">
        <a href="kyc-risk-reviews.php">KYC Risk Reviews</a> page मा approved भएका तर periodic review due भएका KYC देखिन्छन्। यहाँबाट "Risk Category" (Low/Medium/High) update गर्न सकिन्छ।
      </div>

      <h5>📥 Bulk Import:</h5>
      <div class="hg-step">
        <a href="kyc-import-sample.php">KYC Import Sample</a> page बाट CSV template download गरेर bulk member KYC import गर्न सकिन्छ। पहिले template fill गर्नुहोस् → फेरि import गर्नुहोस्।
      </div>

      <div class="hg-warn">
        ⚠️ KYC <b>Approve</b> गर्नु अघि: नागरिकता नम्बर, फोटो, र हस्ताक्षर ध्यानपूर्वक जाँच गर्नुहोस्।
      </div>

      <table class="hg-table">
        <tr><th>Status</th><th>अर्थ</th><th>के गर्ने?</th></tr>
        <tr><td><span class="hg-badge hg-badge-yellow">Pending</span></td><td>नयाँ आएको, review नभएको</td><td>Documents जाँच गर्नुहोस्</td></tr>
        <tr><td><span class="hg-badge hg-badge-blue">Under Review</span></td><td>जाँच गरिरहेको</td><td>थप कागजात माग्न सकिन्छ</td></tr>
        <tr><td><span class="hg-badge hg-badge-green">Approved</span></td><td>स्वीकृत</td><td>Member को KYC complete</td></tr>
        <tr><td><span class="hg-badge hg-badge-red">Rejected</span></td><td>अस्वीकृत</td><td>कारण remarks मा स्पष्ट लेख्नुहोस्</td></tr>
      </table>
    </section>

    <!-- ══ 5. LOAN ══ -->
    <section id="sec-loan" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-hand-holding-usd"></i></span> ऋण आवेदन व्यवस्थापन</h3>
      <p>Public website को loan-apply.php बाट आएका ऋण आवेदनहरू यहाँ देखिन्छन्।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>ऋण आवेदन</b> click (<a href="loan-applications.php">वा यहाँ</a>)।</li>
        <li>List मा आवेदन देख्नुहुन्छ: नाम, ऋण प्रकार, रकम, status।</li>
        <li>कुनै आवेदन click गर्नुहोस् → विवरण + documents हेर्नुहोस्।</li>
        <li>Status update गर्नुहोस्: <span class="hg-badge hg-badge-yellow">Pending</span> → <span class="hg-badge hg-badge-blue">Under Review</span> → <span class="hg-badge hg-badge-green">Approved</span> वा <span class="hg-badge hg-badge-red">Rejected</span>।</li>
        <li>Internal notes लेख्न "Admin Remarks" field use गर्नुहोस् (member लाई देखिँदैन)।</li>
        <li>Member कै लागि message: "Reply to Member" field मा लेख्नुहोस् — email/SMS मा जान्छ।</li>
      </ol>

      <div class="hg-info">
        ✨ <b>EMI Calculator:</b> <a href="../emi-calculator.php" target="_blank">Public EMI Calculator</a> बाट ऋण सम्बन्धी हिसाब गर्न सकिन्छ।
      </div>
      <div class="hg-warn">⚠️ ऋण approve गर्नु अगाडि KYC approved छ कि check गर्नुहोस्।</div>
    </section>

    <!-- ══ 6. ACCOUNT ══ -->
    <section id="sec-account" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-piggy-bank"></i></span> खाता आवेदन</h3>
      <p>नयाँ खाता (बचत/FD/RD) खोल्न परेको आवेदनहरू यहाँ आउँछन्।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>खाता आवेदन</b> (<a href="account-applications.php">वा यहाँ</a>)।</li>
        <li>आवेदन View गर्नुहोस् → खाता प्रकार, रकम, सदस्य विवरण जाँच्नुहोस्।</li>
        <li>Status update गर्नुहोस् र member लाई message पठाउनुहोस्।</li>
      </ol>
      <div class="hg-success">✅ Approve गरेपछि सम्बन्धित branch staff लाई physical process गर्न जानकारी दिनुहोस्।</div>
    </section>

    <!-- ══ 7. DIGITAL SERVICE ══ -->
    <section id="sec-digital" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-mobile-screen"></i></span> डिजिटल सेवा अनुरोध</h3>
      <p>Mobile banking, internet banking, debit card जस्ता digital services को request यहाँ आउँछन्।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>डिजिटल सेवा</b> (<a href="digital-service-requests.php">वा यहाँ</a>)।</li>
        <li>Service type (mobile banking, debit card, etc.) अनुसार filter गर्नुहोस्।</li>
        <li>Request View → Status update → Member लाई reply।</li>
      </ol>

      <div class="hg-info">💡 Member Portal को <a href="../member/service-request.php" target="_blank">Service Request</a> page बाट पनि member ले request पठाउन सक्छ।</div>
    </section>

    <!-- ══ 8. WELFARE ══ -->
    <section id="sec-welfare" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-heart"></i></span> कल्याण दाबी (Welfare Claims)</h3>
      <p>सदस्यले कल्याण कोषबाट सहायता माग गरेका दाबीहरू यहाँ आउँछन् (बिरामी, मृत्यु, दुर्घटना सहायता, आदि)।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>कल्याण दाबी</b> (<a href="welfare-claims.php">वा यहाँ</a>)।</li>
        <li>दाबी View गर्नुहोस् → प्रकार, रकम, documents जाँच्नुहोस्।</li>
        <li>Status: <span class="hg-badge hg-badge-yellow">Pending</span> → <span class="hg-badge hg-badge-green">Approved</span> वा <span class="hg-badge hg-badge-red">Rejected</span>।</li>
        <li>Approved भएपछि payment process को लागि finance team लाई जानकारी दिनुहोस्।</li>
      </ol>
    </section>

    <!-- ══ 9. GRIEVANCE ══ -->
    <section id="sec-grievance" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-comment-dots"></i></span> गुनासो व्यवस्थापन</h3>
      <p>सदस्य वा जनसाधारणले पेश गरेका complaint/grievance यहाँ देखिन्छन्।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>गुनासो</b> (<a href="grievances.php">वा यहाँ</a>)।</li>
        <li>गुनासो View गर्नुहोस् — विवरण र प्रकार हेर्नुहोस्।</li>
        <li>Status update गर्नुहोस्: <span class="hg-badge hg-badge-blue">Under Review</span> → <span class="hg-badge hg-badge-green">Resolved</span>।</li>
        <li>"Resolution Note" मा समाधान के भयो त्यो लेख्नुहोस्।</li>
      </ol>

      <h5>📌 Grievance Officer:</h5>
      <div class="hg-step">
        <a href="grievance-officer.php">Grievance Officer</a> page मा जाएर — गुनासो हेर्ने जिम्मेवार व्यक्तिको नाम, फोन, email set गर्नुहोस्। यो public website मा देखिन्छ।
      </div>
    </section>

    <!-- ══ 10. APPOINTMENT ══ -->
    <section id="sec-appointment" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-calendar-check"></i></span> अपोइन्टमेन्ट</h3>
      <p>सदस्यले office भेट्नको लागि बुक गरेका appointments यहाँ आउँछन्।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>अपोइन्टमेन्ट</b> (<a href="appointments.php">वा यहाँ</a>)।</li>
        <li>मिति अनुसार filter गर्नुहोस्।</li>
        <li>Status update: <span class="hg-badge hg-badge-green">Confirmed</span> वा <span class="hg-badge hg-badge-red">Cancelled</span>।</li>
        <li>Confirmed भएपछि member लाई auto-email जान्छ।</li>
      </ol>
    </section>

    <!-- ══ 11. MEMBERS ══ -->
    <section id="sec-members" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-users"></i></span> सदस्य व्यवस्थापन</h3>

      <h5>🔍 Member खोज्ने / List हेर्ने:</h5>
      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>सदस्यहरू</b> (<a href="members.php">वा यहाँ</a>)।</li>
        <li>Search box मा नाम, Member ID, वा फोन नम्बर type गर्नुहोस्।</li>
        <li>Filter: <code>Active</code>, <code>Inactive</code>, <code>Pending Approval</code>।</li>
      </ol>

      <h5>➕ नयाँ Member थप्ने (Admin बाट):</h5>
      <ol class="hg-steps-list">
        <li><a href="members.php">Members</a> page → <span class="kbd">Add New Member</span> button।</li>
        <li>नाम, email, phone, Member ID भर्नुहोस्।</li>
        <li>Password set गर्नुहोस् (member पछि आफैं बदल्न सक्छ)।</li>
        <li><span class="kbd">Save</span> click गर्नुहोस्।</li>
      </ol>

      <h5>✏️ Member Edit / Activate / Deactivate:</h5>
      <ol class="hg-steps-list">
        <li>Member को नामको छेउमा रहेको <span class="kbd">Edit</span> button।</li>
        <li>जे edit गर्ने त्यो बदल्नुहोस् → <span class="kbd">Save</span>।</li>
        <li>Active/Inactive toggle: member को row मा रहेको switch button।</li>
      </ol>

      <h5>🔑 Member को Password Reset:</h5>
      <ol class="hg-steps-list">
        <li>Member को <span class="kbd">View</span> page खोल्नुहोस्।</li>
        <li>"Reset Password" section मा नयाँ password type गर्नुहोस्।</li>
        <li><span class="kbd">Reset</span> click — member लाई email जान्छ।</li>
      </ol>

      <h5>📋 Pending Registration Requests:</h5>
      <div class="hg-step">
        <a href="member-online-portal.php">Member Online Portal</a> → "Pending Registrations" tab मा नयाँ signup requests आउँछन् — Approve वा Reject गर्नुहोस्।
      </div>

      <div class="hg-warn">⚠️ Member delete गर्दा <b>soft delete</b> हुन्छ — data permanently हट्दैन। Recover गर्न developer लाई भन्नुहोस्।</div>
    </section>

    <!-- ══ 12. MEMBER PORTAL ══ -->
    <section id="sec-member-portal" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-user-shield"></i></span> Member Portal Settings</h3>

      <h5>Password Reset Requests:</h5>
      <ol class="hg-steps-list">
        <li><a href="member-online-portal.php?tab=resets">Member Portal → Password Resets tab</a>।</li>
        <li>Member ले "Forgot Password" click गरेपछि यहाँ request आउँछ।</li>
        <li><span class="kbd">Approve Reset</span> → member को email मा link जान्छ।</li>
      </ol>

      <h5>Member of the Year:</h5>
      <div class="hg-step">
        <a href="member-of-year.php">Member of Year</a> page मा जाएर — "वर्षको उत्कृष्ट सदस्य" को नाम, फोटो, र विवरण थप्नुहोस्। यो public website मा देखिन्छ।
      </div>
    </section>

    <!-- ══ 13. NOTICES ══ -->
    <section id="sec-notices" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-bullhorn"></i></span> सूचनाहरू (Notices)</h3>
      <p>AGM, board meeting, छुट्टी, ब्याज बदल जस्ता official notices यहाँबाट publish हुन्छन्।</p>

      <h5>✅ नयाँ सूचना थप्ने:</h5>
      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>सूचनाहरू</b> (<a href="notices.php">वा यहाँ</a>) → <span class="kbd">Add New</span>।</li>
        <li><b>शीर्षक</b> (Nepali मा लेख्नुहोस्) र <b>विवरण</b> भर्नुहोस्।</li>
        <li><b>Category</b> छान्नुहोस् (General, AGM, Holiday, Interest Rate, आदि)।</li>
        <li>PDF/image attachment upload गर्न सकिन्छ।</li>
        <li><b>Published</b> toggle ON राख्नुहोस् — website मा देखिन्छ।</li>
        <li><span class="kbd">Save Notice</span> click गर्नुहोस्।</li>
      </ol>

      <div class="hg-info">💡 <b>Publish Date</b> future मा राख्न सकिन्छ — त्यो दिनसम्म website मा देखिँदैन।</div>
    </section>

    <!-- ══ 14. NEWS ══ -->
    <section id="sec-news" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-newspaper"></i></span> समाचार / News</h3>
      <p>सहकारीको गतिविधि, कार्यक्रम, पुरस्कार सम्बन्धी news publish गर्ने ठाउँ।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>समाचार</b> (<a href="news.php">वा यहाँ</a>) → <span class="kbd">Add News</span>।</li>
        <li><b>Title, Slug</b> (URL name), <b>Content</b> भर्नुहोस्।</li>
        <li><b>Featured Image</b> upload गर्नुहोस् (JPG/PNG, 1MB भन्दा कम)।</li>
        <li>Category र Tags छान्नुहोस्।</li>
        <li><b>Published</b> toggle ON → <span class="kbd">Save</span>।</li>
      </ol>

      <div class="hg-warn">⚠️ Image size 800KB भन्दा कम राख्नुहोस् — ठूलो image ले page slow हुन्छ।</div>
    </section>

    <!-- ══ 15. GALLERY ══ -->
    <section id="sec-gallery" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-images"></i></span> ग्यालरी</h3>
      <p>सहकारीका कार्यक्रम, office, staff को photos यहाँ थप्ने।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>ग्यालरी</b> (<a href="gallery.php">वा यहाँ</a>)।</li>
        <li>Album छान्नुहोस् (वा नयाँ album बनाउनुहोस्)।</li>
        <li><span class="kbd">Upload Images</span> click → एकैपटक धेरै photos select गर्न सकिन्छ।</li>
        <li>Caption र Alt text लेख्नुहोस् (SEO का लागि)।</li>
        <li><span class="kbd">Save</span> click → gallery मा देखिन्छ।</li>
      </ol>

      <div class="hg-info">💡 Photos लाई drag-and-drop गरेर order मिलाउन सकिन्छ।</div>
    </section>

    <!-- ══ 16. SLIDERS ══ -->
    <section id="sec-sliders" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-sliders"></i></span> Homepage Slider / Banner</h3>
      <p>Homepage को सबैभन्दा माथि देखिने sliding banner images यहाँबाट manage हुन्छन्।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>स्लाइडर</b> (<a href="sliders.php">वा यहाँ</a>)।</li>
        <li><span class="kbd">Add Slide</span> → Image upload गर्नुहोस् (Recommended: 1920×700px)।</li>
        <li>Title, Subtitle, Button text र Button link भर्नुहोस्।</li>
        <li>Sort Order number राख्नुहोस् (1 = पहिले देखिन्छ)।</li>
        <li>Active toggle ON → <span class="kbd">Save</span>।</li>
      </ol>
      <div class="hg-warn">⚠️ Slider image को size 300KB भन्दा कम राख्नुहोस् — website load speed का लागि।</div>
    </section>

    <!-- ══ 17. SERVICES ══ -->
    <section id="sec-services" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-hand-holding-heart"></i></span> सेवाहरू</h3>
      <p>Website को "हाम्रा सेवाहरू" section मा देखिने services यहाँबाट manage हुन्छन्।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>सेवाहरू</b> (<a href="services.php">वा यहाँ</a>) → <span class="kbd">Add Service</span>।</li>
        <li>Service नाम, Icon (FontAwesome class जस्तै <code>fa-piggy-bank</code>), र विवरण भर्नुहोस्।</li>
        <li>Sort order र Active status set गर्नुहोस्।</li>
        <li><span class="kbd">Save</span> → Homepage मा देखिन्छ।</li>
      </ol>
    </section>

    <!-- ══ 18. INTEREST RATES ══ -->
    <section id="sec-interest" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-percent"></i></span> ब्याज दर</h3>
      <p>बचत, ऋण, FD को current interest rates यहाँबाट update हुन्छन्।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>ब्याज दर</b> (<a href="interest-rates.php">वा यहाँ</a>)।</li>
        <li>Category (Saving / Loan / FD) छान्नुहोस्।</li>
        <li>Existing rate edit गर्न <span class="kbd">Edit</span> click → नयाँ rate enter गर्नुहोस्।</li>
        <li>Effective date राख्नुहोस् (कहिलेदेखि लागू हुन्छ)।</li>
        <li><span class="kbd">Save</span> → Website मा तुरुन्त update हुन्छ।</li>
      </ol>
      <div class="hg-danger">🚨 ब्याज दर बदल्दा बोर्डको निर्णय अनुसार मात्र गर्नुहोस्।</div>
    </section>

    <!-- ══ 19. PAGES ══ -->
    <section id="sec-pages" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-file-lines"></i></span> Pages / पृष्ठहरू</h3>
      <p>About Us, Privacy Policy, Terms जस्ता static pages यहाँबाट edit हुन्छन्।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>पृष्ठहरू</b> (<a href="pages.php">वा यहाँ</a>)।</li>
        <li>Edit गर्ने page को <span class="kbd">Edit</span> button click गर्नुहोस्।</li>
        <li>Rich text editor मा content लेख्नुहोस् — Bold, Italic, List, Link सबै toolbar मा छन्।</li>
        <li><span class="kbd">Save Page</span> → website मा तुरुन्त update।</li>
      </ol>

      <h5>Footer Menu Links बदल्ने:</h5>
      <div class="hg-step">
        <a href="pages.php?tab=dynamic&f_menu=footer">Pages → Footer Menu tab</a> मा footer मा देखिने links manage गर्नुहोस्।
      </div>
    </section>

    <!-- ══ 20. DOWNLOADS ══ -->
    <section id="sec-downloads" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-file-arrow-down"></i></span> डाउनलोड सामग्री</h3>
      <p>Forms, bye-laws, annual reports जस्ता documents download section मा राख्ने।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>डाउनलोड</b> (<a href="downloads.php">वा यहाँ</a>) → <span class="kbd">Add File</span>।</li>
        <li>Title (Nepali), Category, र File (PDF/DOCX) upload गर्नुहोस्।</li>
        <li>Active toggle ON → <span class="kbd">Save</span>।</li>
      </ol>
      <div class="hg-info">💡 File size 10MB भन्दा कम राख्नुहोस्। PDF format सबैभन्दा राम्रो।</div>
    </section>

    <!-- ══ 21. FAQS ══ -->
    <section id="sec-faqs" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-circle-question"></i></span> FAQs / प्रश्नोत्तर</h3>
      <p>Website को FAQ section मा देखिने प्रश्न-उत्तर यहाँबाट manage हुन्छन्।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>प्रश्नोत्तर</b> (<a href="faqs.php">वा यहाँ</a>) → <span class="kbd">Add FAQ</span>।</li>
        <li>Question र Answer Nepali मा लेख्नुहोस्।</li>
        <li>Category: General, Loan, Saving, KYC जस्ता छान्नुहोस्।</li>
        <li>Sort order र Active status set → <span class="kbd">Save</span>।</li>
      </ol>
    </section>

    <!-- ══ 22. TEAM / COMMITTEES ══ -->
    <section id="sec-team" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-people-group"></i></span> टोली / समिति सदस्य</h3>

      <h5>Staff / Team Members:</h5>
      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>टोली सदस्य</b> (<a href="team.php">वा यहाँ</a>) → <span class="kbd">Add Member</span>।</li>
        <li>नाम, पद (Designation), फोटो, र विभाग भर्नुहोस्।</li>
        <li>Sort order, Social links (Facebook, LinkedIn) र Active status → <span class="kbd">Save</span>।</li>
      </ol>

      <h5>Board / Committees:</h5>
      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>समिति</b> (<a href="committees.php">वा यहाँ</a>) → <span class="kbd">Add Committee</span>।</li>
        <li>Committee नाम (Board of Directors, Supervisory Committee, आदि) र सदस्यहरू थप्नुहोस्।</li>
        <li>हरेक member को नाम, पद, कार्यकाल, र फोटो भर्नुहोस्।</li>
      </ol>
    </section>

    <!-- ══ 23. CAREERS ══ -->
    <section id="sec-careers" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-briefcase"></i></span> रोजगारी (Careers)</h3>

      <h5>नयाँ Job Post थप्ने:</h5>
      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>रोजगारी</b> (<a href="careers.php">वा यहाँ</a>) → <span class="kbd">Add Job</span>।</li>
        <li>पद नाम, विभाग, आवश्यक योग्यता, र आवेदन deadline भर्नुहोस्।</li>
        <li>Active toggle ON → <span class="kbd">Save</span> — Website मा देखिन्छ।</li>
      </ol>

      <h5>Job Applications हेर्ने:</h5>
      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>जागिर आवेदन</b> (<a href="job-applications.php">वा यहाँ</a>)।</li>
        <li>CV download गर्न applicant को नाम click → <span class="kbd">Download CV</span>।</li>
        <li>Status update: <span class="hg-badge hg-badge-yellow">Pending</span> → <span class="hg-badge hg-badge-blue">Shortlisted</span> → <span class="hg-badge hg-badge-green">Selected</span> वा <span class="hg-badge hg-badge-red">Rejected</span>।</li>
      </ol>
    </section>

    <!-- ══ 24. PROGRAMS ══ -->
    <section id="sec-programs" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-calendar-days"></i></span> कार्यक्रमहरू (Programs)</h3>
      <p>Training, seminar, AGM, annual gathering जस्ता events manage गर्ने ठाउँ।</p>

      <h5>नयाँ कार्यक्रम थप्ने:</h5>
      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>कार्यक्रमहरू</b> (<a href="programs.php">वा यहाँ</a>) → <span class="kbd">Add Program</span>।</li>
        <li>कार्यक्रम नाम, मिति, स्थान, र विवरण भर्नुहोस्।</li>
        <li>QR Code Attendance enable गर्न "Enable QR Attendance" toggle ON राख्नुहोस्।</li>
        <li><span class="kbd">Save</span> → Website calendar मा देखिन्छ।</li>
      </ol>
    </section>

    <!-- ══ 25. ATTENDANCE ══ -->
    <section id="sec-attendance" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-clipboard-check"></i></span> उपस्थिति (Attendance)</h3>
      <p>QR code scan गरेर कार्यक्रममा सदस्यको उपस्थिति दर्ता गर्ने system।</p>

      <h5>Attendance कसरी लिने:</h5>
      <ol class="hg-steps-list">
        <li><b>Option 1 (QR Code):</b> <a href="../attend.php" target="_blank">attend.php</a> page खोल्नुहोस् — सदस्यले आफ्नो QR code scan गर्छन्।</li>
        <li><b>Option 2 (Manual):</b> Admin → <a href="program-attendance.php">Program Attendance</a> → Member ID manually enter गर्नुहोस्।</li>
        <li>Attendance report हेर्न: कार्यक्रम छान्नुहोस् → <span class="kbd">View Report</span>।</li>
        <li>Excel/CSV export गर्न: <span class="kbd">Export</span> button।</li>
      </ol>
    </section>

    <!-- ══ 26. MESSAGES ══ -->
    <section id="sec-messages" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-envelope"></i></span> सन्देशहरू (Contact Messages)</h3>
      <p>Website को Contact page बाट आएका messages यहाँ देखिन्छन्।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>सन्देशहरू</b> (<a href="messages.php">वा यहाँ</a>)।</li>
        <li>Unread messages bold font मा देखिन्छन्।</li>
        <li>Message click → पढ्नुहोस् → Reply email directly पठाउन सकिन्छ।</li>
        <li>Mark as Read / Replied toggle गर्नुहोस्।</li>
      </ol>
    </section>

    <!-- ══ 27. FEEDBACK ══ -->
    <section id="sec-feedback" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-star"></i></span> प्रतिक्रिया / Feedback</h3>
      <p>सदस्य र जनसाधारणले दिएका feedback, satisfaction ratings यहाँ छन्।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>प्रतिक्रिया</b> (<a href="feedbacks.php">वा यहाँ</a>)।</li>
        <li>Rating (1-5 stars) र comment हेर्नुहोस्।</li>
        <li>Public मा देखाउने feedback — <b>Published</b> toggle ON गर्नुहोस्।</li>
      </ol>

      <div class="hg-step">
        <b>Satisfaction Survey Settings:</b> <a href="satisfaction-settings.php">Satisfaction Settings</a> page मा survey को questions र options customize गर्नुहोस्।
      </div>
    </section>

    <!-- ══ 28. AUCTION ══ -->
    <section id="sec-auction" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-gavel"></i></span> लिलामी / Auctions</h3>
      <p>Loan default वा cooperative assets को लिलामी यहाँबाट manage हुन्छ।</p>

      <h5>नयाँ Auction थप्ने:</h5>
      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>लिलामी</b> (<a href="auctions.php">वा यहाँ</a>) → <span class="kbd">Add Auction</span>।</li>
        <li>Item नाम, विवरण, Starting Bid, Minimum Bid Increment, र End Date/Time भर्नुहोस्।</li>
        <li>Photos upload गर्नुहोस्।</li>
        <li>Status: <span class="hg-badge hg-badge-yellow">Draft</span> → <span class="hg-badge hg-badge-green">Published</span> गर्दा website मा देखिन्छ।</li>
      </ol>

      <h5>Bids हेर्ने:</h5>
      <div class="hg-step">
        <a href="auction-bids.php">Auction Bids</a> page मा कसले कति bid गर्यो हेर्नुहोस्। Highest bidder select → <span class="kbd">Declare Winner</span>।
      </div>
    </section>

    <!-- ══ 29. VENDOR ══ -->
    <section id="sec-vendor" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-store"></i></span> Vendor Enlistment</h3>
      <p>Partner vendors / suppliers जो सदस्यलाई discount दिन्छन् — तिनीहरूको enlistment request यहाँ आउँछ।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>Vendor Enlistment</b> (<a href="vendor-enlistment.php">वा यहाँ</a>)।</li>
        <li>Request हेर्नुहोस् — business details, discount offer।</li>
        <li>Status: <span class="hg-badge hg-badge-green">Approved</span> भएपछि website को Partner Facilities section मा देखिन्छ।</li>
      </ol>

      <div class="hg-step">
        Manual partner थप्न: <a href="partner-facilities.php">Partner Facilities</a> page → <span class="kbd">Add Partner</span>।
      </div>
    </section>

    <!-- ══ 30. ANALYTICS ══ -->
    <section id="sec-analytics" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-chart-line"></i></span> Analytics Dashboard</h3>
      <p>Website traffic, application trends, member growth — सबै एकै ठाउँमा।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>Analytics</b> (<a href="analytics.php">वा यहाँ</a>)।</li>
        <li>Date range filter गर्नुहोस् (यो हप्ता / यो महिना / custom)।</li>
        <li>Charts हेर्नुहोस्: KYC trend, Loan applications, Member signups, Form submissions।</li>
        <li>Export button बाट PDF वा Excel मा निकाल्न सकिन्छ।</li>
      </ol>
    </section>

    <!-- ══ 31. REPORTS ══ -->
    <section id="sec-reports" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-chart-column"></i></span> Reports / प्रतिवेदन</h3>
      <p>Monthly, quarterly, annual reports generate गर्ने ठाउँ।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>प्रतिवेदन</b> (<a href="reports.php">वा यहाँ</a>)।</li>
        <li>Report type छान्नुहोस्: Member Report, KYC Report, Loan Report, आदि।</li>
        <li>Date range set गर्नुहोस्।</li>
        <li><span class="kbd">Generate Report</span> → PDF वा Excel download गर्नुहोस्।</li>
      </ol>
    </section>

    <!-- ══ 32. NOTIFICATIONS ══ -->
    <section id="sec-notifications" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-bell"></i></span> Notification Settings र Templates</h3>

      <h5>📧 Email/SMS Gateway Setup (एक पटक मात्र):</h5>
      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>Notification Settings</b> (<a href="notification-settings.php">वा यहाँ</a>)।</li>
        <li><b>Email Settings:</b> SMTP Host, Port, Username, Password भर्नुहोस् (Hosting provider ले दिन्छ)।</li>
        <li><b>SMS Settings:</b> Sparrow SMS / other provider को API token भर्नुहोस्।</li>
        <li><span class="kbd">Test Send</span> → आफ्नै email/SMS मा test पठाएर confirm गर्नुहोस्।</li>
      </ol>

      <h5>✏️ Message Templates Edit गर्ने:</h5>
      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>Notification Templates</b> (<a href="notification-templates.php">वा यहाँ</a>)।</li>
        <li>Event छान्नुहोस् (KYC Approved, Loan Rejected, आदि)।</li>
        <li>Subject र Body Nepali मा edit गर्नुहोस्।</li>
        <li>
          <b>Available Placeholders (automatic fill हुन्छन्):</b>
          <br><code>{name}</code> — member को नाम
          <br><code>{tracking_id}</code> — tracking ID
          <br><code>{status}</code> — नयाँ status
          <br><code>{remarks}</code> — admin ले लेखेको कारण
          <br><code>{site_name}</code> — website नाम
        </li>
        <li>Channel disable गर्न: Email/SMS toggle OFF।</li>
        <li><span class="kbd">Save Template</span>।</li>
      </ol>
    </section>

    <!-- ══ 33. SETTINGS ══ -->
    <section id="sec-settings" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-gear"></i></span> General Settings</h3>
      <p>Website को मूल जानकारी यहाँबाट बदल्न सकिन्छ।</p>

      <table class="hg-table">
        <tr><th>के बदल्ने?</th><th>Setting Tab</th></tr>
        <tr><td>Website नाम, Logo, Favicon</td><td><a href="settings.php#branding">Settings → Branding</a></td></tr>
        <tr><td>Office ठेगाना, Phone, Email</td><td><a href="settings.php#contact">Settings → Contact</a></td></tr>
        <tr><td>Facebook, YouTube links</td><td><a href="settings.php#social">Settings → Social Media</a></td></tr>
        <tr><td>Google Map embed</td><td><a href="settings.php#map">Settings → Map</a></td></tr>
        <tr><td>Footer text</td><td><a href="settings.php#footer">Settings → Footer</a></td></tr>
        <tr><td>Maintenance Mode ON/OFF</td><td><a href="settings.php#maintenance">Settings → Maintenance</a></td></tr>
        <tr><td>Member registration enable/disable</td><td><a href="settings.php#registration">Settings → Registration</a></td></tr>
      </table>

      <div class="hg-warn">⚠️ <b>Maintenance Mode ON</b> गर्दा public website देखिँदैन — developers को लागि मात्र।</div>

      <h5>🏛️ About / Institutional Profile:</h5>
      <div class="hg-step">
        <a href="about-settings.php">About Settings</a> र <a href="institutional-profile.php">Institutional Profile</a> pages मा संस्थाको इतिहास, mission, vision, registration numbers edit गर्नुहोस्।
      </div>

      <h5>🌐 Info Officer (RTI):</h5>
      <div class="hg-step">
        <a href="info-officer.php">Info Officer</a> page मा सूचना अधिकारीको नाम र सम्पर्क set गर्नुहोस् — Right to Information (RTI) का लागि।
      </div>

      <h5>🏢 Service Centers / Branches:</h5>
      <div class="hg-step">
        <a href="service-centers.php">Service Centers</a> page मा सबै शाखाहरूको नाम, ठेगाना, फोन, नक्शा link थप्नुहोस्।
      </div>
    </section>

    <!-- ══ 34. ADMIN USERS ══ -->
    <section id="sec-admins" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-user-shield"></i></span> Admin User Management</h3>
      <p>Admin panel use गर्ने staff को accounts यहाँबाट manage गर्नुहोस्।</p>

      <h5>नयाँ Admin थप्ने:</h5>
      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>Admin Users</b> (<a href="manage-admins.php">वा यहाँ</a>) → <span class="kbd">Add Admin</span>।</li>
        <li>नाम, email, र username भर्नुहोस्।</li>
        <li>Role छान्नुहोस्:</li>
      </ol>
      <table class="hg-table">
        <tr><th>Role</th><th>के गर्न पाउँछ?</th></tr>
        <tr><td><span class="hg-badge hg-badge-red">Super Admin</span></td><td>सबै कुरा — Settings, User Management, Delete सहित</td></tr>
        <tr><td><span class="hg-badge hg-badge-blue">Admin</span></td><td>Applications, Members, Content — Delete बाहेक</td></tr>
        <tr><td><span class="hg-badge hg-badge-yellow">Editor</span></td><td>Content मात्र (News, Notices, Gallery)</td></tr>
        <tr><td><span class="hg-badge">Staff</span></td><td>View only + basic status updates</td></tr>
      </table>
      <div class="hg-danger">🚨 <b>Super Admin role</b> विश्वसनीय व्यक्तिलाई मात्र दिनुहोस् — सबै data delete गर्ने अधिकार हुन्छ।</div>
    </section>

    <!-- ══ 35. BACKUP ══ -->
    <section id="sec-backup" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-database"></i></span> Backup र Restore</h3>

      <h5>📦 अहिल्यै Backup लिने:</h5>
      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>Backup &amp; Restore</b> (<a href="backup-restore.php">वा यहाँ</a>)।</li>
        <li><span class="kbd">Download Database Backup</span> button click गर्नुहोस्।</li>
        <li>SQL file तपाईंको computer मा download हुन्छ — safe ठाउँमा राख्नुहोस्।</li>
      </ol>

      <h5>⏰ Auto Daily Backup Setup (एक पटक मात्र):</h5>
      <div class="hg-step">
        cPanel → Cron Jobs → <span class="kbd">Add New Cron Job</span>:<br>
        <code>0 2 * * * /usr/bin/php -q /home/USERNAME/public_html/scripts/cron-backup.php</code><br>
        (<code>USERNAME</code> = तपाईंको cPanel username)
      </div>
      <div class="hg-info">✅ Daily राति 2 बजे automatically backup लिन्छ। 7 दिन भन्दा पुरानो auto-delete हुन्छ।</div>

      <h5>🔄 Database Migration:</h5>
      <ol class="hg-steps-list">
        <li>नयाँ version update गरेपछि <a href="run-migration.php">Run Migration</a> page मा जानुहोस्।</li>
        <li>Migration button click गर्नुहोस् — एक पटक मात्र। दोहोर्‍याउँदा safe छ।</li>
      </ol>
      <div class="hg-danger">🚨 Migration अघि <b>backup अनिवार्य</b> गर्नुहोस्!</div>
    </section>

    <!-- ══ 36. SITE HEALTH ══ -->
    <section id="sec-sitehealth" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-heart-pulse"></i></span> Site Health Check</h3>
      <p>Website को technical health — PHP version, disk space, DB connection, upload permissions — एकैछिनमा check गर्ने।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>Site Health</b> (<a href="site-health.php">वा यहाँ</a>)।</li>
        <li>🟢 <b>Green</b> = सबै ठीक छ।</li>
        <li>🟡 <b>Yellow</b> = Warning — ध्यान दिनुहोस्।</li>
        <li>🔴 <b>Red</b> = Problem — तुरुन्त developer लाई जानकारी दिनुहोस्।</li>
      </ol>

      <div class="hg-step">
        <b>System Info हेर्ने:</b> <a href="system-info.php">System Info</a> page मा PHP version, server details, installed extensions हेर्न सकिन्छ।
      </div>
      <div class="hg-step">
        <b>Update Checklist:</b> <a href="update-checklist.php">Update Checklist</a> page मा website update गर्दा गर्नुपर्ने कामहरूको list छ।
      </div>
    </section>

    <!-- ══ 37. ERROR LOG ══ -->
    <section id="sec-errorlog" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-bug"></i></span> Error Log हेर्ने तरिका</h3>
      <p>Website मा कुनै problem भएमा error log मा कारण देखिन्छ।</p>

      <ol class="hg-steps-list">
        <li>बायाँ menu → <b>Error Log</b> (<a href="error-log.php">वा यहाँ</a>)।</li>
        <li>पछिल्लो errors list हेर्नुहोस् — date/time, file, र error message देखिन्छ।</li>
        <li>Error छ भने — screenshot लिनुहोस् र developer लाई पठाउनुहोस्।</li>
      </ol>

      <div class="hg-info">
        💡 <b>cPanel बाट पनि हेर्न सकिन्छ:</b> cPanel → File Manager → <code>public_html/error_log</code> file open गर्नुहोस्।
      </div>
    </section>

    <!-- ══ 38. TROUBLESHOOTING ══ -->
    <section id="sec-troubleshoot" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-tools"></i></span> Troubleshooting — Problem आउँदा के गर्ने?</h3>

      <div class="hg-faq">
        <details>
          <summary>❌ Email / SMS जादैन, member ले notification पाएन</summary>
          <div class="mt-2">
            <ol>
              <li><a href="notification-settings.php">Notification Settings</a> → Email/SMS "Enabled" छ कि check।</li>
              <li><a href="notification-templates.php">Notification Templates</a> → सम्बन्धित template Enabled छ कि।</li>
              <li>Test Send button बाट आफ्नै email मा test पठाउनुहोस्।</li>
              <li>SMTP credentials (host, port, password) सही छ कि recheck गर्नुहोस्।</li>
              <li>Hosting cPanel → Email Deliverability → PHP mail() function enabled छ कि।</li>
            </ol>
          </div>
        </details>

        <details>
          <summary>❌ White screen / Page खुल्दैन</summary>
          <div class="mt-2">
            <ol>
              <li>Browser <code>Ctrl + F5</code> (force refresh) try।</li>
              <li><a href="error-log.php">Error Log</a> page हेर्नुहोस् — error message पढ्नुहोस्।</li>
              <li>अन्तिम change गरेको file undo गर्नुहोस्।</li>
              <li>Developer लाई error_log screenshot पठाउनुहोस्।</li>
            </ol>
          </div>
        </details>

        <details>
          <summary>❌ Member login गर्न सक्दैन</summary>
          <div class="mt-2">
            <ol>
              <li><a href="members.php">Members</a> → Member खोज्नुहोस् → Status <b>Active</b> छ कि।</li>
              <li>Email verified छ कि (green checkmark)।</li>
              <li>"Reset Password" → नयाँ password set → member लाई email मा पठाउनुहोस्।</li>
              <li>Member Portal → <a href="member-online-portal.php?tab=resets">Password Reset Requests</a> tab check गर्नुहोस्।</li>
            </ol>
          </div>
        </details>

        <details>
          <summary>❌ File upload हुँदैन / "File too large" error</summary>
          <div class="mt-2">
            <ul>
              <li>Image: JPG/PNG, <b>1MB भन्दा कम</b> राख्नुहोस् (<a href="https://tinypng.com" target="_blank">TinyPNG.com</a> use गर्नुहोस्)।</li>
              <li>Document: PDF, <b>5MB भन्दा कम</b>।</li>
              <li>ठूलो file नै upload गर्नु छ भने hosting PHP settings (<code>upload_max_filesize</code>) बढाउन developer लाई भन्नुहोस्।</li>
            </ul>
          </div>
        </details>

        <details>
          <summary>❌ KYC submit भएन / form काम गरेन</summary>
          <div class="mt-2">
            <ul>
              <li>Member लाई सबै 8 steps पूरा गर्न भन्नुहोस् (Declaration checkbox अनिवार्य)।</li>
              <li>Mobile मा browser बाट try गर्दा camera permission दिएको हो check गर्नुहोस्।</li>
              <li>Chrome browser use गर्न सुझाउनुहोस्।</li>
            </ul>
          </div>
        </details>

        <details>
          <summary>❌ गल्तिले data delete गरेँ — फिर्ता आउँछ?</summary>
          <div class="mt-2">
            ✅ <b>हो, आउँछ!</b> System "Soft Delete" use गर्छ — data permanently हट्दैन, "deleted" flag मात्र लाग्छ। Developer लाई भन्नुहोस् — database बाट manually restore गर्न सकिन्छ।
          </div>
        </details>

        <details>
          <summary>❌ Website slow भएको</summary>
          <div class="mt-2">
            <ol>
              <li>Gallery / News मा ठूला images छन् भने <a href="https://tinypng.com" target="_blank">TinyPNG</a> बाट compress गरेर re-upload गर्नुहोस्।</li>
              <li><a href="site-health.php">Site Health</a> check गर्नुहोस् — कुनै warning छ कि।</li>
              <li>Hosting plan upgrade बारे hosting provider लाई सोध्नुहोस्।</li>
            </ol>
          </div>
        </details>

        <details>
          <summary>❌ Admin panel login हुँदैन, password बिर्सियो</summary>
          <div class="mt-2">
            <ol>
              <li>अर्को Super Admin ले <a href="manage-admins.php">Admin Users</a> बाट password reset गरिदिन सक्छ।</li>
              <li>सबैले बिर्सिए — developer लाई database access दिएर reset गराउनुहोस्।</li>
            </ol>
          </div>
        </details>
      </div>
    </section>

    <!-- ══ 39. FAQ ══ -->
    <section id="sec-faq" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-question-circle"></i></span> FAQ — बारम्बार सोधिने प्रश्न</h3>

      <div class="hg-faq">
        <details>
          <summary>आफ्नो admin password कसरी बदल्ने?</summary>
          <div class="mt-2">माथि दायाँ Avatar icon → <a href="profile.php">My Profile</a> → "Change Password" section।</div>
        </details>
        <details>
          <summary>एकभन्दा बढी admin staff बनाउन मिल्छ?</summary>
          <div class="mt-2">हो — <a href="manage-admins.php">Admin Users</a> → Add Admin → Role assign गर्नुहोस्।</div>
        </details>
        <details>
          <summary>Member ले आफैं register गर्न पाउने कि नपाउने?</summary>
          <div class="mt-2"><a href="settings.php">Settings</a> → Registration section मा toggle ON/OFF गर्नुहोस्। OFF गर्दा admin मात्रले member थप्न सक्छ।</div>
        </details>
        <details>
          <summary>Website को नाम र logo कसरी बदल्ने?</summary>
          <div class="mt-2"><a href="settings.php">Settings</a> → Branding tab → Logo upload र Site Name बदल्नुहोस्।</div>
        </details>
        <details>
          <summary>नयाँ branch/शाखा कसरी थप्ने?</summary>
          <div class="mt-2"><a href="service-centers.php">Service Centers</a> → Add Service Center → नाम, ठेगाना, फोन, map link भर्नुहोस्।</div>
        </details>
        <details>
          <summary>Mobile बाट admin panel use गर्न मिल्छ?</summary>
          <div class="mt-2">हो, responsive design छ। तर ठूलो data काम गर्दा laptop/desktop नै उपयुक्त।</div>
        </details>
        <details>
          <summary>Form submit गरेको data admin मा कति समयमा देखिन्छ?</summary>
          <div class="mt-2">तत्काल — visitor ले Submit click गर्ने बित्तिकै admin panel मा देखिन्छ र admin लाई email/SMS पनि जान्छ।</div>
        </details>
        <details>
          <summary>Deleted data recover कसरी गर्ने?</summary>
          <div class="mt-2">Soft delete भएको data developer ले database बाट restore गर्न सक्छ। Backup restore पनि option हो — <a href="backup-restore.php">Backup &amp; Restore</a>।</div>
        </details>
        <details>
          <summary>Website update भएपछि के गर्ने?</summary>
          <div class="mt-2">१) Backup लिनुहोस् → २) Files upload गर्नुहोस् → ३) <a href="run-migration.php">Run Migration</a> → ४) <a href="site-health.php">Site Health</a> check गर्नुहोस्।</div>
        </details>
      </div>
    </section>

    <!-- ══ 40. CONTACT ══ -->
    <section id="sec-contact" class="hg-section">
      <h3><span class="hg-icon"><i class="fas fa-headset"></i></span> Developer / Support सम्पर्क</h3>

      <div class="hg-info">
        <b>📧 Technical support चाहिएमा developer लाई पठाउने जानकारी:</b>
      </div>
      <div class="hg-step">
        <ol class="mb-0 mt-1">
          <li>के गर्न खोज्दै हुनुहुन्थ्यो? (step-by-step)</li>
          <li>के error देखियो? <b>(screenshot अनिवार्य)</b></li>
          <li>कुन browser? (Chrome / Edge / Firefox)</li>
          <li><a href="error-log.php">Error Log</a> page को पछिल्लो 10 lines copy गर्नुहोस्।</li>
          <li>Problem कहिलेदेखि भयो?</li>
        </ol>
      </div>

      <div class="hg-success">
        ✅ <b>Quick Help Resources:</b>
        <ul class="mb-0 mt-2">
          <li><a href="site-health.php">Site Health Check</a> — technical problems identify गर्न</li>
          <li><a href="error-log.php">Error Log</a> — exact error message हेर्न</li>
          <li><a href="backup-restore.php">Backup &amp; Restore</a> — data सुरक्षित राख्न</li>
          <li><a href="help-center.php">Help Center</a> — सदस्यको queries handle गर्न</li>
        </ul>
      </div>

      <p class="mt-4 text-center text-muted small">
        <i class="fas fa-heart text-danger"></i> Admin Quick Start Guide v6.0 &nbsp;|&nbsp; Last updated: <?php echo date('Y F j'); ?> &nbsp;|&nbsp; सहकारी Admin Panel
      </p>
    </section>

  </main>
</div>

<script>
/* ── Reading progress bar ── */
window.addEventListener('scroll', function(){
  var doc = document.documentElement;
  var scrollTop = doc.scrollTop || document.body.scrollTop;
  var scrollHeight = doc.scrollHeight - doc.clientHeight;
  var progress = scrollHeight > 0 ? (scrollTop / scrollHeight) * 100 : 0;
  var bar = document.getElementById('hg-read-progress');
  if(bar) bar.style.width = progress + '%';
});

/* ── Active sidebar link on scroll ── */
(function(){
  var sections = document.querySelectorAll('.hg-section');
  var navLinks = document.querySelectorAll('.hg-sidebar a');
  var ticking = false;
  window.addEventListener('scroll', function(){
    if(!ticking){
      requestAnimationFrame(function(){
        var scrollY = window.scrollY + 120;
        var current = '';
        sections.forEach(function(s){ if(scrollY >= s.offsetTop) current = s.id; });
        navLinks.forEach(function(a){
          a.classList.toggle('active', a.getAttribute('href') === '#' + current);
        });
        ticking = false;
      });
      ticking = true;
    }
  });
})();

/* ── Search filter ── */
document.getElementById('hgSearch').addEventListener('input', function(){
  var q = this.value.trim().toLowerCase();
  var links = document.querySelectorAll('.hg-sidebar a');
  if(!q){ links.forEach(function(l){ l.style.display=''; }); return; }
  links.forEach(function(l){
    var txt = l.textContent.toLowerCase();
    l.style.display = txt.includes(q) ? '' : 'none';
  });
});

/* ── Smooth scroll for sidebar links ── */
document.querySelectorAll('.hg-sidebar a[href^="#"]').forEach(function(a){
  a.addEventListener('click', function(e){
    var target = document.querySelector(this.getAttribute('href'));
    if(target){ e.preventDefault(); target.scrollIntoView({behavior:'smooth', block:'start'}); }
  });
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
