<?php
/**
 * Ferreira & Sá Hub — Logout
 */

require_once __DIR__ . '/../core/auth.php';

logout_user();
redirect(url('auth/login.php'));
