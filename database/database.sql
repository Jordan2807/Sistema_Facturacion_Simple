
-- =====================================================
-- SISTEMA DE FACTURACION SENCILLA Y CONTROL DE INVENTARIO
-- Base de datos: pulperia_elena
-- Motor: MySQL / MariaDB
-- =====================================================

CREATE DATABASE IF NOT EXISTS pulperia_elena
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE pulperia_elena;


-- =====================================================
-- TABLA: USUARIOS
-- Administradores, cajeros y empleados de almacen
-- =====================================================

CREATE TABLE IF NOT EXISTS usuarios (

    id_usuario INT AUTO_INCREMENT PRIMARY KEY,

    nombre VARCHAR(100) NOT NULL,

    correo VARCHAR(150) NOT NULL UNIQUE,

    contrasena_hash VARCHAR(255) NOT NULL,

    rol ENUM(
        'administrador',
        'cajero',
        'almacen'
    ) NOT NULL,

    estado TINYINT(1) NOT NULL DEFAULT 1,

    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP

) ENGINE=InnoDB;


-- =====================================================
-- TABLA: PRODUCTOS
-- Informacion y existencias de los productos
-- =====================================================

CREATE TABLE IF NOT EXISTS productos (

    id_producto INT AUTO_INCREMENT PRIMARY KEY,

    codigo_barras VARCHAR(50) NOT NULL UNIQUE,

    nombre VARCHAR(150) NOT NULL,

    categoria VARCHAR(100) DEFAULT NULL,

    costo DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    precio_venta DECIMAL(10,2) NOT NULL,

    stock_actual INT NOT NULL DEFAULT 0,

    stock_minimo INT NOT NULL DEFAULT 0,

    estado TINYINT(1) NOT NULL DEFAULT 1,

    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP

) ENGINE=InnoDB;


-- =====================================================
-- TABLA: VENTAS
-- Registro de ventas y comprobantes internos
-- =====================================================

CREATE TABLE IF NOT EXISTS ventas (

    id_venta INT AUTO_INCREMENT PRIMARY KEY,

    id_usuario INT NOT NULL,

    fecha_venta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    subtotal DECIMAL(10,2) NOT NULL,

    descuento DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    total DECIMAL(10,2) NOT NULL,

    metodo_pago ENUM(
        'efectivo',
        'tarjeta',
        'sinpe',
        'otro'
    ) NOT NULL DEFAULT 'efectivo',

    monto_recibido DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    cambio DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    estado ENUM(
        'completada',
        'anulada'
    ) NOT NULL DEFAULT 'completada',

    observaciones VARCHAR(255) DEFAULT NULL,

    CONSTRAINT fk_ventas_usuarios
        FOREIGN KEY (id_usuario)
        REFERENCES usuarios(id_usuario)

        ON UPDATE CASCADE
        ON DELETE RESTRICT

) ENGINE=InnoDB;


-- =====================================================
-- TABLA: DETALLE_VENTA
-- Productos incluidos en cada venta
-- =====================================================

CREATE TABLE IF NOT EXISTS detalle_venta (

    id_detalle INT AUTO_INCREMENT PRIMARY KEY,

    id_venta INT NOT NULL,

    id_producto INT NOT NULL,

    cantidad INT NOT NULL,

    precio_unitario DECIMAL(10,2) NOT NULL,

    subtotal DECIMAL(10,2) NOT NULL,

    CONSTRAINT fk_detalle_venta
        FOREIGN KEY (id_venta)
        REFERENCES ventas(id_venta)

        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_detalle_producto
        FOREIGN KEY (id_producto)
        REFERENCES productos(id_producto)

        ON UPDATE CASCADE
        ON DELETE RESTRICT

) ENGINE=InnoDB;


-- =====================================================
-- TABLA: MOVIMIENTOS_INVENTARIO
-- Registro de entradas, salidas y ajustes de stock
-- =====================================================

CREATE TABLE IF NOT EXISTS movimientos_inventario (

    id_movimiento INT AUTO_INCREMENT PRIMARY KEY,

    id_producto INT NOT NULL,

    id_usuario INT NOT NULL,

    id_venta INT DEFAULT NULL,

    tipo_movimiento ENUM(
        'entrada',
        'salida',
        'ajuste_positivo',
        'ajuste_negativo'
    ) NOT NULL,

    cantidad INT NOT NULL,

    fecha_movimiento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    observaciones VARCHAR(255) DEFAULT NULL,

    CONSTRAINT fk_movimientos_producto
        FOREIGN KEY (id_producto)
        REFERENCES productos(id_producto)

        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_movimientos_usuario
        FOREIGN KEY (id_usuario)
        REFERENCES usuarios(id_usuario)

        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_movimientos_venta
        FOREIGN KEY (id_venta)
        REFERENCES ventas(id_venta)

        ON UPDATE CASCADE
        ON DELETE SET NULL

) ENGINE=InnoDB;


-- =====================================================
-- FIN DE LA CREACION DE LA BASE DE DATOS
-- =====================================================
