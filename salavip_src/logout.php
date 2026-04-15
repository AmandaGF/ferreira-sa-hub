<?php
/**
 * Central VIP F&S — Logout
 */
require_once __DIR__ . '/config.php';

session_unset();
session_destroy();

header('Location: ' . SALAVIP_BASE_URL . '/index.php');
exit;
