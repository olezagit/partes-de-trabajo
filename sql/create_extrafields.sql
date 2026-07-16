-- ═══════════════════════════════════════════════════════════════════════════════
-- Script SQL — Módulo Partes de Trabajo
-- Crea el campo extra tipo_albaran en llx_commande_extrafields
--
-- Ejecutar UNA SOLA VEZ sobre tu base de datos Dolibarr.
-- Ajusta el prefijo llx_ si tu instalación usa uno diferente.
-- ═══════════════════════════════════════════════════════════════════════════════

-- ── 1. Añadir el campo extra tipo_albaran a los pedidos ───────────────────────
-- Tipo: select numérico (valores 1-4 mapeados a etiquetas en el módulo)
-- Si prefieres crearlo desde la interfaz:
--   Configuración → Campos extra → Pedidos → Nuevo campo
--   Nombre: tipo_albaran | Tipo: Entero (int) | Obligatorio: Sí

INSERT IGNORE INTO llx_extrafields
  (name, label, type, size, elementtype, fieldunique, fieldrequired,
   param, pos, alwayseditable, perms, langs, list, printable,
   totalizable, help, computed, entity, enabled, mandatoryforthreshold)
VALUES
  ('tipo_albaran', 'Tipo de parte', 'select', '11', 'commande', 0, 1,
   'a:1:{s:7:"options";a:4:{s:1:"1";s:15:"Parte de trabajo";s:1:"2";s:15:"Puesta en marcha";s:1:"3";s:6:"Avería";s:1:"4";s:13:"Mantenimiento";}}',
   10, 1, '', '', '1', 1, 0,
   'Tipo de parte de trabajo (1=Parte, 2=Puesta en marcha, 3=Avería, 4=Mantenimiento)',
   '', 1, '1', 0);


-- ── 2. Verificar que el campo se creó correctamente ───────────────────────────
SELECT name, label, type, elementtype, fieldrequired
FROM llx_extrafields
WHERE elementtype = 'commande'
  AND name = 'tipo_albaran';


-- ── 3. Referencia de tablas usadas por el módulo (solo lectura, no modificar) ─
-- llx_commande              → rowid, ref, statut, fk_soc, fk_shipping_address, date_commande
-- llx_commande_extrafields  → fk_object (=commande.rowid), tipo_albaran (int 1-4)
-- llx_societe               → rowid, nom, address, zip, town
-- llx_societe_address       → rowid, address, zip, town  (sede del pedido)
-- llx_actioncomm            → id, fk_element (=commande.rowid), elementtype='order'
-- llx_actioncomm_resources  → fk_actioncomm, element_type='user', fk_element (=user.rowid)


-- ── 4. Ejemplo: asignar tipo a un pedido existente ────────────────────────────
-- UPDATE llx_commande_extrafields SET tipo_albaran = 1 WHERE fk_object = 123;
-- (Reemplaza 123 por el rowid del pedido y 1 por el tipo deseado)


-- ── 5. Ejemplo: consulta de verificación ─────────────────────────────────────
-- Muestra pedidos activos con su tipo y el técnico asignado:
SELECT
  c.ref                       AS parte,
  ef.tipo_albaran             AS tipo,
  s.nom                       AS cliente,
  CONCAT(u.firstname,' ',u.lastname) AS tecnico
FROM llx_commande c
JOIN llx_societe              s   ON s.rowid        = c.fk_soc
LEFT JOIN llx_commande_extrafields ef ON ef.fk_object   = c.rowid
JOIN llx_actioncomm           ac  ON ac.fk_element  = c.rowid
                                  AND ac.elementtype = 'order'
JOIN llx_actioncomm_resources acr ON acr.fk_actioncomm = ac.id
                                  AND acr.element_type  = 'user'
JOIN llx_user                 u   ON u.rowid         = acr.fk_element
WHERE c.statut NOT IN (0, -1, 3)
ORDER BY c.date_commande DESC;


-- ── Campos de firma en llx_commande_extrafields ───────────────────────────────
-- Ejecutar tras crear el campo tipo_albaran (ver arriba)

INSERT IGNORE INTO llx_extrafields
  (name, label, type, size, elementtype, fieldunique, fieldrequired, param, pos, alwayseditable, perms, langs, list, printable, totalizable, help, computed, entity, enabled, mandatoryforthreshold)
VALUES
  ('firma_cliente', 'Firma cliente', 'text', '', 'commande', 0, 0, '', 30, 0, '', '', '0', 0, 0, 'Firma del cliente en base64', '', 1, '1', 0),
  ('firma_tecnico', 'Firma técnico', 'text', '', 'commande', 0, 0, '', 40, 0, '', '', '0', 0, 0, 'Firma del técnico en base64', '', 1, '1', 0),
  ('nombre_firmante', 'Nombre firmante', 'varchar', '255', 'commande', 0, 0, '', 50, 0, '', '', '1', 1, 0, 'Nombre del cliente que firma', '', 1, '1', 0),
  ('dni_firmante', 'DNI firmante', 'varchar', '20', 'commande', 0, 0, '', 60, 0, '', '', '1', 1, 0, 'DNI del cliente que firma', '', 1, '1', 0),
  ('fecha_firma', 'Fecha firma', 'varchar', '20', 'commande', 0, 0, '', 70, 0, '', '', '1', 1, 0, 'Fecha y hora de la firma', '', 1, '1', 0);
