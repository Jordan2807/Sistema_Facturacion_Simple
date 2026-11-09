<?php

/**
 * Funciones generales del sistema.
 * Sistema de facturacion sencilla y control de inventario.
 */


// ==========================================
// 1. ESCAPAR TEXTO PARA MOSTRARLO EN HTML
// ==========================================

function escapar($valor): string
{
    return htmlspecialchars(
        (string) ($valor ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


// ==========================================
// 2. GENERAR URLS DEL SISTEMA
// ==========================================

function urlSistema(string $ruta = ''): string
{
    global $appConfig;

    $urlBase = rtrim($appConfig['url_base'], '/');

    return $urlBase . '/' . ltrim($ruta, '/');
}


// ==========================================
// 3. FORMATEAR PRECIOS EN COLONES
// ==========================================

function formatearMoneda(float|int|string $monto): string
{
    global $appConfig;

    return $appConfig['simbolo_moneda'] .
        number_format((float) $monto, 2, ',', '.');
}


// ==========================================
// 4. REDIRECCIONAR A OTRA PAGINA
// ==========================================

function redirigir(string $ruta): void
{
    header('Location: ' . urlSistema($ruta));
    exit;
}


// ==========================================
// 5. GUARDAR MENSAJES TEMPORALES
// ==========================================

function establecerMensaje(string $tipo, string $mensaje): void
{
    $tiposPermitidos = [
        'success',
        'error',
        'warning',
        'info'
    ];

    if (!in_array($tipo, $tiposPermitidos, true)) {
        $tipo = 'info';
    }

    $_SESSION['mensaje_flash'] = [
        'tipo' => $tipo,
        'mensaje' => $mensaje
    ];
}


// ==========================================
// 6. OBTENER Y ELIMINAR MENSAJES TEMPORALES
// ==========================================

function obtenerMensaje(): ?array
{
    if (!isset($_SESSION['mensaje_flash'])) {
        return null;
    }

    $mensaje = $_SESSION['mensaje_flash'];

    unset($_SESSION['mensaje_flash']);

    return $mensaje;
}


// ==========================================
// 7. OBTENER EL USUARIO AUTENTICADO
// ==========================================

function obtenerUsuarioActual(): ?array
{
    if (
        !isset($_SESSION['usuario']) ||
        !is_array($_SESSION['usuario'])
    ) {
        return null;
    }

    return $_SESSION['usuario'];
}


// ==========================================
// 8. COMPROBAR EL ROL DEL USUARIO
// ==========================================

function tieneRol(string ...$roles): bool
{
    $usuario = obtenerUsuarioActual();

    if ($usuario === null) {
        return false;
    }

    return in_array(
        $usuario['rol'] ?? '',
        $roles,
        true
    );
}
