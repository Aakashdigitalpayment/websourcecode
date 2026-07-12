</div><!-- /.admin-page -->

<style>
.admin-bottom-nav{
  position:fixed; bottom:0; left:0; right:0;
  background:#fff; border-top:1px solid #eef2f7;
  display:flex; align-items:stretch; justify-content:space-around;
  padding:8px 4px calc(8px + env(safe-area-inset-bottom,0px));
  z-index:40; box-shadow:0 -10px 24px rgba(15,23,42,.10);
  border-radius:16px 16px 0 0; gap:2px;
}
.admin-bottom-nav .admin-nav-item{
  flex:1 1 0; min-width:0;
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  gap:4px; padding:6px 2px;
  text-decoration:none; color:#6b7280;
  font-size:.68rem; font-weight:600; text-align:center; line-height:1.1;
  border-radius:10px; transition:background .15s, color .15s;
}
.admin-bottom-nav .admin-nav-item i{
  font-size:1.15rem; line-height:1; height:20px;
  display:inline-flex; align-items:center; justify-content:center;
}
.admin-bottom-nav .admin-nav-item span{
  display:block; width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.admin-bottom-nav .admin-nav-item.active,
.admin-bottom-nav .admin-nav-item:hover{
  color:var(--primary-color,#1a5f2a);
  background:rgba(26,95,42,.08);
}
@media (min-width:900px){ .admin-bottom-nav{ display:none; } }
@media (max-width:899px){ body.admin-shell{ padding-bottom:78px; } }
</style>

<nav class="admin-bottom-nav">
  <a href="/admin/" class="admin-nav-item"><i class="lucide-icon" aria-hidden="true" data-lucide="gauge"></i><span>ड्यासबोर्ड</span></a>
  <a href="/admin/members/" class="admin-nav-item"><i class="lucide-icon" aria-hidden="true" data-lucide="users"></i><span>सदस्य</span></a>
  <a href="/admin/hrm-employees.php" class="admin-nav-item"><i class="fas fa-id-badge"></i><span>HRM</span></a>
  <a href="/admin/notices.php" class="admin-nav-item"><i class="fas fa-bullhorn"></i><span>सूचना</span></a>
  <a href="/admin/settings.php" class="admin-nav-item"><i class="fas fa-cog"></i><span>सेटिङ</span></a>
</nav>

</body></html>
