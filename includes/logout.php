<?php

/**
 * Cierre de sesion de usuarios.
 * Sistema de facturacion sencilla y control de inventario.
 */

// Cargar configuracion y sesion.
require_once __DIR__ . '/bootstrap.php';

// Cargar funciones generales.
require_once __DIR__ . '/functions.php';

// Cargar proteccion CSRF.
require_once __DIR__ . '/csrf.php';


// ==========================================
// 1. COMPROBAR EL METODO DE SOLICITUD
// ==========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    header('Allow: POST');

    exit('Metodo de solicitud no permitido.');

}


// ==========================================
// 2. VERIFICAR EL TOKEN CSRF
// ==========================================

verificarTokenCSRF();


// ==========================================
// 3. ELIMINAR LOS DATOS DE LA SESION
// ==========================================

$_SESSION = [];


// ==========================================
// 4. ELIMINAR LA COOKIE DE SESION
// ==========================================

$parametrosCookie = session_get_cookie_params();

setcookie(
    session_name(),
    '',
    [
        'expires' => time() - 42000,
        'path' => $parametrosCookie['path'],
        'domain' => $parametrosCookie['domain'],
        'secure' => $parametrosCookie['secure'],
        'httponly' => $parametrosCookie['httponly'],
        'samesite' => $parametrosCookie['samesite'] ?? 'Lax'
    ]
);


// ==========================================
// 5. DESTRUIR LA SESION
// ==========================================

session_destroy();


// ==========================================
// 6. REGRESAR AL INICIO
// ==========================================

redirigir('index.php');

