<?php
/**
 * DEPRECATED — do not include.
 * Public mobile nav is `.mob-bottomnav` in includes/footer.php.
 * Re-enabling this creates a duplicate bottom bar.
 */
?>
<!-- Public Website Mobile Footer Navigation (Mobile Only) — DISABLED -->
<nav class="public-mobile-footer" aria-label="Mobile navigation" hidden>
  <a href="<?php echo SITE_URL; ?>index.php" class="<?php echo (basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : ''); ?>" title="Home">
    <i class="fas fa-home"></i>
    <span>Home</span>
  </a>

  <a href="<?php echo SITE_URL; ?>about.php" class="<?php echo (basename($_SERVER['PHP_SELF']) === 'about.php' ? 'active' : ''); ?>" title="About">
    <i class="fas fa-info-circle"></i>
    <span>About</span>
  </a>

  <a href="<?php echo SITE_URL; ?>services.php" class="<?php echo (basename($_SERVER['PHP_SELF']) === 'services.php' ? 'active' : ''); ?>" title="Services">
    <i class="lucide-icon" aria-hidden="true" data-lucide="briefcase"></i>
    <span>Services</span>
  </a>

  <a href="<?php echo SITE_URL; ?>news.php" class="<?php echo (basename($_SERVER['PHP_SELF']) === 'news.php' ? 'active' : ''); ?>" title="News">
    <i class="lucide-icon" aria-hidden="true" data-lucide="newspaper"></i>
    <span>News</span>
  </a>

  <a href="<?php echo SITE_URL; ?>contact.php" class="<?php echo (basename($_SERVER['PHP_SELF']) === 'contact.php' ? 'active' : ''); ?>" title="Contact">
    <i class="fas fa-envelope"></i>
    <span>Contact</span>
  </a>

  <!-- PWA Install — visible when not in standalone (pwa-register.js hides via html.pwa-standalone) -->
  <button type="button" class="pwa-install-btn pub-footer-pwa"
          onclick="if(typeof pwaTriggerInstall==='function')pwaTriggerInstall();"
          title="Install App">
    <i class="fas fa-mobile-screen-button"></i>
    <span>Install</span>
  </button>
</nav>

<script>
  // Add body class when mobile footer is visible
  (function() {
    var mobileFooter = document.querySelector('.public-mobile-footer');
    if (mobileFooter && window.innerWidth <= 768) {
      document.body.classList.add('has-mobile-footer');
    }

    // Handle window resize
    window.addEventListener('resize', function() {
      if (window.innerWidth <= 768 && mobileFooter) {
        document.body.classList.add('has-mobile-footer');
      } else {
        document.body.classList.remove('has-mobile-footer');
      }
    });
  })();
</script>

<style>
  body.has-mobile-footer {
    padding-bottom: 65px;
  }
</style>
