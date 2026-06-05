<?php
require_once __DIR__ . '/../includes/bootstrap.php';

logout_user();
flash('success', 'Sesion cerrada.');
redirect('crm/login.php');
