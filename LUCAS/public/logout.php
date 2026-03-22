<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

logout_user();
flash_set('success', 'Sesión cerrada correctamente.');
redirect('login.php');
