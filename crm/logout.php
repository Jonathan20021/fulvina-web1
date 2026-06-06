<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// Logout must be a deliberate POST with a valid CSRF token (prevents cross-site
// forced logout via <img>/<iframe> pointing at this URL on GET).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(is_logged_in() ? 'crm/index.php' : 'crm/login.php');
}
verify_csrf();

logout_user();
flash('success', 'Sesión cerrada.');
redirect('crm/login.php');
