# Módulo Partes de Trabajo — Dolibarr

Módulo para Dolibarr que muestra a cada usuario técnico únicamente los albaranes
asignados a él, excluyendo borradores y finalizados, presentados como fichas visuales.

---

## ✅ Requisitos

- Dolibarr **13.0** o superior
- PHP **7.0** o superior
- Módulos Dolibarr activados: **Terceros** y **Albaranes (Expeditions)**

---

## 📦 Instalación

### 1. Copiar el módulo

Copia la carpeta `partes_trabajo` completa dentro del directorio de módulos personalizados de tu Dolibarr:

```
/var/www/dolibarr/htdocs/custom/partes_trabajo/
```

La estructura final debe quedar así:

```
custom/
└── partes_trabajo/
    ├── index.php                          ← Página principal (listado de partes)
    ├── admin/
    │   └── setup.php                      ← Configuración del módulo
    ├── core/
    │   └── modules/
    │       └── modPartesTrabajo.class.php ← Descriptor del módulo
    ├── css/
    │   └── partes.css                     ← Estilos de las fichas
    ├── langs/
    │   └── es_ES/
    │       └── partes_trabajo.lang        ← Traducciones español
    └── sql/
        └── create_extrafields.sql         ← Script SQL (opcional)
```

### 2. Activar el módulo

1. Ir a **Configuración → Módulos/Aplicaciones**
2. Buscar **"Partes de Trabajo"** en la categoría *Técnico*
3. Hacer clic en **Activar**

### 3. Crear los campos extra (extrafields)

#### Opción A — Desde la interfaz Dolibarr *(recomendado)*

Ve a **Configuración → Campos extra → Albaranes** y crea dos campos:

| Nombre interno      | Etiqueta          | Tipo          | Obligatorio |
|---------------------|-------------------|---------------|-------------|
| `tecnico_asignado`  | Técnico asignado  | Enlace (User) | No          |
| `tipo_parte`        | Tipo de parte     | Lista valores | Sí          |

Para el campo `tipo_parte`, define estos valores en la lista:

| Clave              | Etiqueta           |
|--------------------|--------------------|
| `Parte de trabajo` | Parte de trabajo   |
| `Puesta en marcha` | Puesta en marcha   |
| `Averia`           | Avería             |
| `Mantenimiento`    | Mantenimiento      |

#### Opción B — Script SQL

Ejecuta el script incluido directamente en tu base de datos:

```bash
mysql -u root -p nombre_bd < custom/partes_trabajo/sql/create_extrafields.sql
```

> ⚠️ Ajusta el prefijo `llx_` si tu instalación usa uno diferente.

### 4. Configurar el módulo

Ve a **Configuración → Módulos → Partes de Trabajo → Configurar** y verifica:

- **Extrafield técnico asignado**: nombre del campo extra creado (por defecto `tecnico_asignado`)
- **Extrafield tipo de parte**: nombre del campo extra de tipo (por defecto vacío → usa `ref_ext`)
- **Usar autor del albarán como fallback**: actívalo si también quieres mostrar los albaranes creados por el propio usuario

---

## 🎨 Tipos de parte y colores

| Tipo               | Color      |
|--------------------|------------|
| Parte de trabajo   | 🔵 Azul    |
| Puesta en marcha   | 🟢 Verde   |
| Avería             | 🔴 Rojo    |
| Mantenimiento      | 🟡 Ámbar   |

---

## 🔑 Permisos

El módulo añade el permiso `partes_trabajo → leer`. Asígnalo a los roles/usuarios técnicos desde **Configuración → Usuarios y grupos**.

Los administradores (`$user->admin`) siempre tienen acceso.

---

## ⚙️ Personalización avanzada

### Cambiar los estados visibles

Edita en `index.php` la línea:

```php
$sql .= "   AND e.statut NOT IN (0, 99)";
```

Estados de albaranes en Dolibarr:
- `0` = Borrador
- `1` = Validado
- `2` = Enviado / En tránsito
- `99` = Cerrado / Finalizado

### Añadir más tipos de parte

En `index.php`, extiende el array `$tipos`:

```php
$tipos = array(
    'Instalacion' => array('label' => 'Instalación', 'color' => '#7C3AED', 'bg' => '#EDE9FE', 'icon' => '🔌'),
    // ... resto de tipos
);
```

Recuerda añadir el mismo valor en el campo extra `tipo_parte` desde la interfaz.

---

## 🐛 Solución de problemas

**No aparece ningún parte:**
- Comprueba que los albaranes tienen el extrafield `tecnico_asignado` relleno con el ID del usuario.
- Verifica que los albaranes no están en estado borrador (0) ni cerrado (99).
- Activa la opción "Usar autor del albarán como fallback" en la configuración.

**El tipo aparece como `—`:**
- El campo `tipo_parte` (o `ref_ext`) del albarán está vacío o tiene un valor que no coincide exactamente con las claves del array `$tipos`.
- Los valores son **sensibles a mayúsculas/minúsculas**. Usa exactamente: `Parte de trabajo`, `Puesta en marcha`, `Averia`, `Mantenimiento`.

**Error de acceso:**
- Asegúrate de que el usuario tiene el permiso `partes_trabajo → leer`.

---

## 📱 Funcionalidad PWA (Progressive Web App)

### ¿Qué significa funcionar offline?

El módulo implementa una estrategia completa de **Progressive Web App**:

| Componente | Archivo | Función |
|---|---|---|
| Service Worker | `sw.js` | Intercepta peticiones, gestiona caché, sincronización en fondo |
| Base de datos local | `js/db.js` | IndexedDB: almacena partes y cola de acciones offline |
| Lógica de app | `js/app.js` | Red vs caché, toasts, banner de red, prompt de instalación |
| API JSON | `api/partes.php` | Endpoint que devuelve los partes en JSON para el SW |
| Página offline | `offline.html` | Pantalla de fallback si no hay caché disponible |
| Manifiesto | `manifest.json` | Permite instalar la app en el móvil |

### Estrategia de caché por tipo de recurso

- **CSS / JS / iconos** → *Cache First*: se sirven siempre desde caché, se actualizan en segundo plano
- **API de partes** → *Network First*: intenta red, si falla usa caché IndexedDB
- **Páginas HTML** → *Network First*: intenta red, si falla usa caché de páginas
- **Peticiones POST** → se guardan en cola y se envían al recuperar conexión (*Background Sync*)

### Flujo offline completo

```
Usuario abre la app sin internet
  → SW detecta fallo de red
  → Sirve la última página cacheada
  → app.js carga datos de IndexedDB
  → Banner naranja "Sin conexión"
  → Usuario ve sus partes del último sync

Usuario recupera internet
  → Evento 'online' dispara loadFromServer()
  → Se descargan datos frescos y se guardan en IDB
  → Se vacía la cola de acciones pendientes (flushPendingActions)
  → Banner verde "Conexión restaurada" → desaparece a los 3.5s
```

### Instalar en el móvil

1. Abrir la URL del módulo en Chrome (Android) o Safari (iOS)
2. Aparece automáticamente el banner de instalación en la parte inferior
3. Pulsar **Instalar** → la app se añade a la pantalla de inicio
4. A partir de ese momento funciona como una app nativa, con icono propio y sin barra del navegador

### Iconos necesarios

Crea los iconos en `icons/` con estas medidas (puedes generarlos en [realfavicongenerator.net](https://realfavicongenerator.net)):

```
icons/
├── icon-72.png
├── icon-96.png
├── icon-128.png
├── icon-192.png   ← principal
├── icon-512.png   ← para splash screen
└── badge-72.png   ← para notificaciones push
```

### Acciones offline

Cualquier acción que realice el técnico mientras no tiene red (p.ej. marcar un parte como completado) debe llamar a:

```javascript
await window.ptSaveAccionOffline(
    'completar',           // tipo de acción
    parteId,               // ID del albarán
    { nota: '...' },       // datos a enviar
    '/custom/partes_trabajo/api/accion.php'  // URL del endpoint
);
```

La acción se guarda en IndexedDB y se envía automáticamente cuando haya conexión.
