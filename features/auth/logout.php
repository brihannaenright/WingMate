<?php
require_once __DIR__ . '/../../includes/session.php';

wingmate_start_secure_session();
wingmate_destroy_session();

header('Location: /features/auth/login.php');
exit;
