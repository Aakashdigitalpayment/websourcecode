</main>
<?php
$_mpLang = function_exists('getCurrentLang') ? getCurrentLang() : 'np';
$_mpIsEn = ($_mpLang === 'en');
$_mpT = static function (string $np, string $en) use ($_mpIsEn): string {
  return $_mpIsEn ? $en : $np;
};
?>

<nav class="mp-bottom-nav">
  <a href="/member/index.php"><i class="fas fa-house"></i><span><?php echo $_mpT('गृह', 'Home'); ?></span></a>
  <a href="/member/id-card.php"><i class="lucide-icon" aria-hidden="true" data-lucide="id-card"></i><span><?php echo $_mpT('ID कार्ड', 'ID Card'); ?></span></a>
  <a href="/member/transactions.php"><i class="fas fa-money-bill-transfer"></i><span><?php echo $_mpT('कारोबार', 'Transactions'); ?></span></a>
  <a href="/member/profile.php"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i><span><?php echo $_mpT('प्रोफाइल', 'Profile'); ?></span></a>
  <a href="/member/logout.php"><i class="fas fa-right-from-bracket"></i><span><?php echo $_mpT('लगआउट', 'Logout'); ?></span></a>
</nav>

<style>
  .mp-bottom-nav {
    position: fixed; bottom: 0; left: 0; right: 0;
    background: #fff; border-top: 1px solid #e5e7eb;
    display: flex; justify-content: space-around;
    padding: 8px 0 calc(8px + env(safe-area-inset-bottom, 0px));
    box-shadow: 0 -2px 8px rgba(0,0,0,.06);
    z-index: 40;
  }
  .mp-bottom-nav a {
    flex: 1; text-decoration: none; color: #6b7280;
    display: flex; flex-direction: column; align-items: center; gap: 2px;
    font-size: 11px; font-weight: 600;
    transition: color .15s;
  }
  .mp-bottom-nav a i { font-size: 18px; }
  .mp-bottom-nav a:hover { color: var(--primary-dark); }
  body { padding-bottom: 70px; }
  @media (min-width: 900px) { .mp-bottom-nav { display: none; } body { padding-bottom: 0; } }
</style>
</body></html>
