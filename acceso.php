<?php

/**
 * Control de acceso inicial al sistema.
 * Sistema de Facturacion Sencilla y Control de Inventario.
 *
 * Comprueba si existe un administrador antes de
 * decidir a que pantalla debe ingresar el visitante.
 */

// ==========================================
// 1. CARGAR CONFIGURACION Y CONEXION
// ==========================================

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/functions.php';


// ==========================================
// 2. COMPROBAR SI EXISTE UN ADMINISTRADOR
// ==========================================

try {

    $consulta = $pdo->query(
        "SELECT COUNT(*)
         FROM usuarios
         WHERE rol = 'administrador'"
    );

    $cantidadAdministradores = (int) $consulta->fetchColumn();


    // ==========================================
    // 3. REDIRIGIR SEGUN EL RESULTADO
    // ==========================================

    if ($cantidadAdministradores > 0) {

        // Ya existe un administrador.
        // Enviar al formulario de inicio de sesion.

        redirigir('login.php');

    } else {

        // No existe ningun administrador.
        // Enviar a la configuracion inicial.

        redirigir('setup_admin.php');

    }

} catch (Throwable $e) {

    // Registrar el error sin mostrar informacion sensible.

    error_log(
        'Error al verificar el acceso inicial: ' .
        $e->getMessage()
    );

    http_response_code(503);

    exit(
        'No fue posible verificar el estado del sistema. Intenta nuevamente.'
    );

}
