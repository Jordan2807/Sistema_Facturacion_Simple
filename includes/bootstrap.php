<?php

/**
 * Inicializacion general del sistema.
 * Sistema de facturacion sencilla y control de inventario.
 */

// ==========================================
// 1. CARGAR CONFIGURACION GENERAL
// ==========================================

$appConfig = require __DIR__ . '/../config/app.php';

// Configurar zona horaria.
date_default_timezone_set($appConfig['zona_horaria']);


// ==========================================
// 2. CONFIGURAR SESIONES
// ==========================================

// Evitar configuraciones inseguras de sesiones.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

// Detectar si la conexion utiliza HTTPS.
$conexionSegura = (
    isset($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] !== 'off'
);

// Configurar la cookie de sesion.
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $conexionSegura,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Iniciar sesion solamente si no existe una activa.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// ==========================================
// 3. CONFIGURAR ENCABEZADOS DE SEGURIDAD
// ==========================================

header('X-Content-Type-Options: nosniff');

header('X-Frame-Options: SAMEORIGIN');

header('Referrer-Policy: strict-origin-when-cross-origin');


// ==========================================
// 4. ESTABLECER CONEXION CON LA BASE DE DATOS
// ==========================================

$pdo = require __DIR__ . '/../config/database.php';
