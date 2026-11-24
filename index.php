<?php
/**
 * SMS entry point — redirects to the admin login/dashboard.
 * The public-facing website lives at the domain root; /sms/ is the
 * Endrias Film College School Management System (admin only).
 */
require_once __DIR__ . '/school_config.php';
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: /sms/admin/index.php', true, 302);
exit;
