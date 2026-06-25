<?php
require_once '../includes/config.php';
require_once '../includes/member-auth.php';
memberLogout();
header('Location: ' . SITE_URL . 'member/login.php?msg=loggedout');
exit;
