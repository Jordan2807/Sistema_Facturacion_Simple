<?php

/**
 * Control centralizado de permisos.
 * Sistema de facturacion sencilla y control de inventario.
 */

// Cargar autenticacion y funciones generales.
require_once __DIR__ . '/auth.php';


// ==========================================
// 1. DEFINIR PERMISOS SEGUN EL ROL
// ==========================================

function obtenerPermisosPorRol(string $rol): array
{
    static $permisos = [

        // ==================================
        // ADMINISTRADOR
        // ==================================

        'administrador' => [

            'usuarios.ver',
            'usuarios.crear',
            'usuarios.editar',
            'usuarios.desactivar',

            'productos.consultar',
            'productos.registrar',
            'productos.editar',
            'productos.desactivar',

            'inventario.ver',
            'inventario.registrar_entrada',
            'inventario.ajustar_existencias',
            'inventario.ver_movimientos',

            'ventas.registrar',
            'ventas.ver_todas',
            'ventas.ver_propias',
            'ventas.anular',

            'comprobantes.ver'

        ],


        // ==================================
        // CAJERO
        // ==================================

        'cajero' => [

            'productos.consultar',

            'ventas.registrar',
            'ventas.ver_propias',

            'comprobantes.ver'

        ],


        // ==================================
        // EMPLEADO DE ALMACEN
        // ==================================

        'almacen' => [

            'productos.consultar',
            'productos.registrar',
            'productos.editar',

            'inventario.ver',
            'inventario.registrar_entrada',
            'inventario.ajustar_existencias',
            'inventario.ver_movimientos'

        ]

    ];

    return $permisos[$rol] ?? [];
}


// ==========================================
// 2. OBTENER PERMISOS DEL USUARIO ACTUAL
// ==========================================

function obtenerPermisosUsuarioActual(): array
{
    $usuario = obtenerUsuarioActual();

    if ($usuario === null) {
        return [];
    }

    $rol = $usuario['rol'] ?? '';

    return obtenerPermisosPorRol($rol);
}


// ==========================================
// 3. COMPROBAR SI EL USUARIO TIENE UN PERMISO
// ==========================================

function usuarioPuede(string $permiso): bool
{
    $permisosUsuario = obtenerPermisosUsuarioActual();

    return in_array(
        $permiso,
        $permisosUsuario,
        true
    );
}


// ==========================================
// 4. EXIGIR UN PERMISO PARA CONTINUAR
// ==========================================

function requerirPermiso(string $permiso): void
{
    // Comprobar que exista una sesion autenticada.
    requerirAutenticacion();

    // Verificar el permiso solicitado.
    if (!usuarioPuede($permiso)) {

        establecerMensaje(
            'error',
            'No tienes autorizacion para realizar esta operacion.'
        );

        // Regresar a la pagina principal.
        redirigir('index.php');

    }
}
