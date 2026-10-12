-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 12-10-2026 a las 19:20:33
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `emdesi_contable`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `asientos_contables`
--

CREATE TABLE `asientos_contables` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `empresa_id` bigint(20) UNSIGNED NOT NULL,
  `periodo_id` bigint(20) UNSIGNED NOT NULL,
  `numero` bigint(20) UNSIGNED NOT NULL,
  `fecha` date NOT NULL,
  `glosa` varchar(500) NOT NULL,
  `estado` enum('BORRADOR','CONTABILIZADO','ANULADO') NOT NULL DEFAULT 'BORRADOR',
  `creado_por` bigint(20) UNSIGNED NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `asientos_contables`
--

INSERT INTO `asientos_contables` (`id`, `empresa_id`, `periodo_id`, `numero`, `fecha`, `glosa`, `estado`, `creado_por`, `creado_en`, `actualizado_en`) VALUES
(1, 1, 1, 1, '2026-01-02', 'Aporte inicial', 'BORRADOR', 1, '2026-10-12 16:54:10', '2026-10-12 16:54:10'),
(2, 1, 1, 2, '2026-01-06', 'BANCO', 'BORRADOR', 1, '2026-10-12 16:55:12', '2026-10-12 16:55:12'),
(3, 1, 1, 3, '2026-01-08', 'fdfdf', 'CONTABILIZADO', 1, '2026-10-12 16:58:40', '2026-10-12 16:58:40');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `auditoria`
--

CREATE TABLE `auditoria` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `usuario_id` bigint(20) UNSIGNED DEFAULT NULL,
  `empresa_id` bigint(20) UNSIGNED DEFAULT NULL,
  `accion` varchar(80) NOT NULL,
  `modulo` varchar(80) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `detalle_asientos`
--

CREATE TABLE `detalle_asientos` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `asiento_id` bigint(20) UNSIGNED NOT NULL,
  `cuenta_id` bigint(20) UNSIGNED NOT NULL,
  `glosa` varchar(300) DEFAULT NULL,
  `debe` decimal(15,2) NOT NULL DEFAULT 0.00,
  `haber` decimal(15,2) NOT NULL DEFAULT 0.00,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Volcado de datos para la tabla `detalle_asientos`
--

INSERT INTO `detalle_asientos` (`id`, `asiento_id`, `cuenta_id`, `glosa`, `debe`, `haber`, `creado_en`) VALUES
(1, 1, 2, 'Dinero de socios', 1000.00, 0.00, '2026-10-12 16:54:10'),
(2, 1, 2, 'Pago', 0.00, 1000.00, '2026-10-12 16:54:10'),
(3, 2, 2, 'Ingreso a caja chica', 200.00, 0.00, '2026-10-12 16:55:12'),
(4, 2, 2, 'Deposito', 0.00, 200.00, '2026-10-12 16:55:12'),
(5, 3, 2, NULL, 100.00, 0.00, '2026-10-12 16:58:40'),
(6, 3, 2, NULL, 0.00, 100.00, '2026-10-12 16:58:40');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `empresas`
--

CREATE TABLE `empresas` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `ruc` char(11) NOT NULL,
  `razon_social` varchar(200) NOT NULL,
  `nombre_comercial` varchar(200) DEFAULT NULL,
  `regimen_tributario` varchar(50) DEFAULT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `departamento` varchar(100) DEFAULT NULL,
  `provincia` varchar(100) DEFAULT NULL,
  `distrito` varchar(100) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `correo` varchar(190) DEFAULT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `empresas`
--

INSERT INTO `empresas` (`id`, `ruc`, `razon_social`, `nombre_comercial`, `regimen_tributario`, `direccion`, `departamento`, `provincia`, `distrito`, `telefono`, `correo`, `estado`, `creado_en`) VALUES
(1, '10703959704', 'EMDESI TECH SAC', 'EMDESI TECH', 'RER', 'C.P. Verdún S/N', 'Ica', 'Chincha', 'Chincha Baja', '936206715', 'danielcamachosalhua@gmail.com', 1, '2026-10-12 16:05:41');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `periodos_contables`
--

CREATE TABLE `periodos_contables` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `empresa_id` bigint(20) UNSIGNED NOT NULL,
  `anio` smallint(5) UNSIGNED NOT NULL,
  `mes` tinyint(3) UNSIGNED NOT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date NOT NULL,
  `estado` enum('ABIERTO','CERRADO') NOT NULL DEFAULT 'ABIERTO',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Volcado de datos para la tabla `periodos_contables`
--

INSERT INTO `periodos_contables` (`id`, `empresa_id`, `anio`, `mes`, `fecha_inicio`, `fecha_fin`, `estado`, `creado_en`, `actualizado_en`) VALUES
(1, 1, 2026, 1, '2026-01-01', '2026-01-31', 'ABIERTO', '2026-10-12 16:47:44', '2026-10-12 16:48:44'),
(3, 1, 2026, 2, '2026-02-01', '2026-02-28', 'ABIERTO', '2026-10-12 16:48:16', '2026-10-12 16:48:16');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `plan_cuentas`
--

CREATE TABLE `plan_cuentas` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `empresa_id` bigint(20) UNSIGNED NOT NULL,
  `codigo` varchar(20) NOT NULL,
  `nombre` varchar(200) NOT NULL,
  `cuenta_padre_id` bigint(20) UNSIGNED DEFAULT NULL,
  `nivel` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `tipo_cuenta` enum('ACTIVO','PASIVO','PATRIMONIO','INGRESO','GASTO','COSTO','ORDEN') NOT NULL,
  `acepta_movimientos` tinyint(1) NOT NULL DEFAULT 1,
  `requiere_tercero` tinyint(1) NOT NULL DEFAULT 0,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `plan_cuentas`
--

INSERT INTO `plan_cuentas` (`id`, `empresa_id`, `codigo`, `nombre`, `cuenta_padre_id`, `nivel`, `tipo_cuenta`, `acepta_movimientos`, `requiere_tercero`, `estado`, `creado_en`) VALUES
(1, 1, '10', 'Efectivo y equivalentes de efectivos', NULL, 1, 'ACTIVO', 0, 0, 1, '2026-10-12 16:19:15'),
(2, 1, '101', 'Caja', 1, 2, 'ACTIVO', 1, 0, 1, '2026-10-12 16:20:08');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

CREATE TABLE `roles` (
  `id` int(10) UNSIGNED NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `roles`
--

INSERT INTO `roles` (`id`, `nombre`, `descripcion`, `estado`, `creado_en`) VALUES
(1, 'SUPERADMIN', 'Administrador general del sistema', 1, '2026-10-12 15:47:44'),
(2, 'CONTADOR', 'Gestión contable y tributaria', 1, '2026-10-12 15:47:44'),
(3, 'ASISTENTE', 'Registro y apoyo contable', 1, '2026-10-12 15:47:44'),
(4, 'CONSULTA', 'Acceso de solo consulta', 1, '2026-10-12 15:47:44');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `rol_id` int(10) UNSIGNED NOT NULL,
  `nombres` varchar(120) NOT NULL,
  `apellidos` varchar(120) NOT NULL,
  `correo` varchar(190) NOT NULL,
  `clave_hash` varchar(255) NOT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `ultimo_acceso` datetime DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `rol_id`, `nombres`, `apellidos`, `correo`, `clave_hash`, `estado`, `ultimo_acceso`, `creado_en`, `actualizado_en`) VALUES
(1, 1, 'Daniel Junior', 'Camacho Salhua', 'danielcamachosalhua@gmail.com', '$2y$10$/02pDomhXHXGP7K3qJFY/urNL3JTEkUKtWQHB1ljl3wGF6dItbZWO', 1, '2026-10-12 11:47:12', '2026-10-12 15:58:17', '2026-10-12 16:47:12');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuario_empresa`
--

CREATE TABLE `usuario_empresa` (
  `usuario_id` bigint(20) UNSIGNED NOT NULL,
  `empresa_id` bigint(20) UNSIGNED NOT NULL,
  `es_principal` tinyint(1) NOT NULL DEFAULT 0,
  `asignado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `usuario_empresa`
--

INSERT INTO `usuario_empresa` (`usuario_id`, `empresa_id`, `es_principal`, `asignado_en`) VALUES
(1, 1, 1, '2026-10-12 16:05:41');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `asientos_contables`
--
ALTER TABLE `asientos_contables`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_asiento_empresa_numero` (`empresa_id`,`numero`),
  ADD KEY `idx_asiento_empresa_fecha` (`empresa_id`,`fecha`),
  ADD KEY `idx_asiento_periodo` (`periodo_id`,`estado`),
  ADD KEY `fk_asiento_usuario` (`creado_por`);

--
-- Indices de la tabla `auditoria`
--
ALTER TABLE `auditoria`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_auditoria_fecha` (`fecha`),
  ADD KEY `idx_auditoria_usuario` (`usuario_id`),
  ADD KEY `fk_auditoria_empresa` (`empresa_id`);

--
-- Indices de la tabla `detalle_asientos`
--
ALTER TABLE `detalle_asientos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_detalle_asiento` (`asiento_id`),
  ADD KEY `idx_detalle_cuenta` (`cuenta_id`);

--
-- Indices de la tabla `empresas`
--
ALTER TABLE `empresas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ruc` (`ruc`);

--
-- Indices de la tabla `periodos_contables`
--
ALTER TABLE `periodos_contables`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_periodo_empresa` (`empresa_id`,`anio`,`mes`),
  ADD KEY `idx_periodo_empresa_estado` (`empresa_id`,`estado`);

--
-- Indices de la tabla `plan_cuentas`
--
ALTER TABLE `plan_cuentas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cuenta_empresa_codigo` (`empresa_id`,`codigo`),
  ADD KEY `idx_cuentas_empresa` (`empresa_id`),
  ADD KEY `fk_cuenta_padre` (`cuenta_padre_id`);

--
-- Indices de la tabla `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `correo` (`correo`),
  ADD KEY `fk_usuarios_roles` (`rol_id`);

--
-- Indices de la tabla `usuario_empresa`
--
ALTER TABLE `usuario_empresa`
  ADD PRIMARY KEY (`usuario_id`,`empresa_id`),
  ADD KEY `fk_ue_empresa` (`empresa_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `asientos_contables`
--
ALTER TABLE `asientos_contables`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `auditoria`
--
ALTER TABLE `auditoria`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `detalle_asientos`
--
ALTER TABLE `detalle_asientos`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `empresas`
--
ALTER TABLE `empresas`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `periodos_contables`
--
ALTER TABLE `periodos_contables`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `plan_cuentas`
--
ALTER TABLE `plan_cuentas`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `asientos_contables`
--
ALTER TABLE `asientos_contables`
  ADD CONSTRAINT `fk_asiento_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`),
  ADD CONSTRAINT `fk_asiento_periodo` FOREIGN KEY (`periodo_id`) REFERENCES `periodos_contables` (`id`),
  ADD CONSTRAINT `fk_asiento_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`);

--
-- Filtros para la tabla `auditoria`
--
ALTER TABLE `auditoria`
  ADD CONSTRAINT `fk_auditoria_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_auditoria_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `detalle_asientos`
--
ALTER TABLE `detalle_asientos`
  ADD CONSTRAINT `fk_detalle_asiento` FOREIGN KEY (`asiento_id`) REFERENCES `asientos_contables` (`id`),
  ADD CONSTRAINT `fk_detalle_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `plan_cuentas` (`id`);

--
-- Filtros para la tabla `periodos_contables`
--
ALTER TABLE `periodos_contables`
  ADD CONSTRAINT `fk_periodo_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`);

--
-- Filtros para la tabla `plan_cuentas`
--
ALTER TABLE `plan_cuentas`
  ADD CONSTRAINT `fk_cuenta_padre` FOREIGN KEY (`cuenta_padre_id`) REFERENCES `plan_cuentas` (`id`),
  ADD CONSTRAINT `fk_cuentas_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`);

--
-- Filtros para la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD CONSTRAINT `fk_usuarios_roles` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`);

--
-- Filtros para la tabla `usuario_empresa`
--
ALTER TABLE `usuario_empresa`
  ADD CONSTRAINT `fk_ue_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`),
  ADD CONSTRAINT `fk_ue_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
