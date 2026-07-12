  <!-- Member Portal Mobile Footer Navigation (Mobile Only) -->
<nav class="member-mobile-footer" aria-label="Member mobile navigation">
  <a href="<?php echo defined('MEMBER_URL') ? MEMBER_URL : SITE_URL . 'member/'; ?>index.php" class="<?php echo (basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : ''); ?>" title="Dashboard">
    <i class="lucide-icon" aria-hidden="true" data-lucide="gauge"></i>
    <span>Dashboard</span>
  </a>

  <a href="<?php echo defined('MEMBER_URL') ? MEMBER_URL : SITE_URL . 'member/'; ?>profile.php" class="<?php echo (basename($_SERVER['PHP_SELF']) === 'profile.php' ? 'active' : ''); ?>" title="Profile">
    <i class="lucide-icon" aria-hidden="true" data-lucide="circle-user"></i>
    <span>Profile</span>
  </a>

  <a href="<?php echo defined('MEMBER_URL') ? MEMBER_URL : SITE_URL . 'member/'; ?>loans.php" class="<?php echo (basename($_SERVER['PHP_SELF']) === 'loans.php' ? 'active' : ''); ?>" title="Loans">
    <i class="lucide-icon" aria-hidden="true" data-lucide="hand-coins"></i>
    <span>Loans</span>
  </a>

  <a href="<?php echo defined('MEMBER_URL') ? MEMBER_URL : SITE_URL . 'member/'; ?>savings.php" class="<?php echo (basename($_SERVER['PHP_SELF']) === 'savings.php' ? 'active' : ''); ?>" title="Savings">
    <i class="lucide-icon" aria-hidden="true" data-lucide="piggy-bank"></i>
    <span>Savings</span>
  </a>

  <!-- PWA Install — visible when not in standalone (pwa-register.js hides via html.pwa-standalone) -->
  <button type="button" class="pwa-install-btn mem-footer-pwa"
          onclick="if(typeof pwaTriggerInstall==='function')pwaTriggerInstall();"
          title="App Install">
    <i class="lucide-icon" aria-hidden="true" data-lucide="smartphone"></i>
    <span>Install</span>
  </button>

  <a href="<?php echo defined('MEMBER_URL') ? MEMBER_URL : SITE_URL . 'member/'; ?>logout.php" title="Logout">
    <i class="lucide-icon" aria-hidden="true" data-lucide="log-out"></i>
    <span>Logout</span>
  </a>
</nav>

<script>
  // Add body class when mobile footer is visible
  (function() {
    var mobileFooter = document.querySelector('.member-mobile-footer');
    if (mobileFooter && window.innerWidth <= 768) {
      document.body.classList.add('has-member-footer');
    }

    // Handle window resize
    window.addEventListener('resize', function() {
      if (window.innerWidth <= 768 && mobileFooter) {
        document.body.classList.add('has-member-footer');
      } else {
        document.body.classList.remove('has-member-footer');
      }
    });
  })();
</script>

<style>
  body.has-member-footer {
    padding-bottom: 65px;
  }
</style>
