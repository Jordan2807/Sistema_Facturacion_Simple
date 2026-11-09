<?php

/**
 * Proteccion CSRF para los formularios del sistema.
 * Sistema de facturacion sencilla y control de inventario.
 */

// Garantizar que exista una sesion activa.
require_once __DIR__ . '/bootstrap.php';


// ==========================================
// 1. GENERAR Y OBTENER TOKEN CSRF
// ==========================================

function obtenerTokenCSRF(): string
{
    // Generar un token si todavia no existe.
    if (
        !isset($_SESSION['token_csrf']) ||
        !is_string($_SESSION['token_csrf'])
    ) {

        $_SESSION['token_csrf'] = bin2hex(
            random_bytes(32)
        );

    }

    return $_SESSION['token_csrf'];
}


// ==========================================
// 2. GENERAR CAMPO OCULTO PARA LOS FORMULARIOS
// ==========================================

function campoTokenCSRF(): string
{
    $token = htmlspecialchars(
        obtenerTokenCSRF(),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    return '<input type="hidden" name="csrf_token" value="' .
        $token .
        '">';
}


// ==========================================
// 3. VERIFICAR TOKEN RECIBIDO
// ==========================================

function verificarTokenCSRF(): void
{
    // Obtener token enviado por el formulario.
    $tokenFormulario = $_POST['csrf_token'] ?? '';

    // Obtener token almacenado en la sesion.
    $tokenSesion = $_SESSION['token_csrf'] ?? '';

    // Comprobar que ambos valores sean cadenas.
    if (
        !is_string($tokenFormulario) ||
        !is_string($tokenSesion) ||
        $tokenFormulario === '' ||
        $tokenSesion === ''
    ) {

        http_response_code(403);

        exit('Solicitud no autorizada. Token CSRF invalido.');

    }

    // Comparacion segura de ambos tokens.
    if (!hash_equals($tokenSesion, $tokenFormulario)) {

        http_response_code(403);

        exit('Solicitud no autorizada. Token CSRF incorrecto.');

    }
}
