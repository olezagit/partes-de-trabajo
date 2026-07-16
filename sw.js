/**
 * Service Worker — Partes de Trabajo PWA
 * Estrategia simplificada: sin precarga en install.
 * Los recursos se cachean la primera vez que se usan (cache-on-demand).
 */

const SW_VERSION   = 'pt-v1.5';
const CACHE_STATIC = SW_VERSION + '-static';
const CACHE_API    = SW_VERSION + '-api';

// ── Install: minimal, sin precarga ───────────────────────────────────────────
self.addEventListener('install', event => {
    // Nada que precargar — activar inmediatamente
    event.waitUntil(self.skipWaiting());
});

// ── Activate: limpiar versiones anteriores ────────────────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys
                    .filter(k => k.startsWith('pt-') && k !== CACHE_STATIC && k !== CACHE_API)
                    .map(k => {
                        console.log('[PT SW] Eliminando caché antigua:', k);
                        return caches.delete(k);
                    })
            ))
            .then(() => self.clients.claim())
    );
});

// ── Fetch: estrategia por tipo de recurso ─────────────────────────────────────
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Solo interceptar peticiones del mismo origen
    if (url.origin !== self.location.origin) return;

    // 1. POST → intentar red, si falla encolar
    if (event.request.method === 'POST') {
        event.respondWith(
            fetch(event.request.clone()).catch(() =>
                new Response(
                    JSON.stringify({ offline: true, queued: true }),
                    { headers: { 'Content-Type': 'application/json' } }
                )
            )
        );
        return;
    }

    // 2. Llamadas a nuestra API (ajax.php) → Network First, fallback caché
    if (url.pathname.endsWith('/ajax.php') || (url.pathname.endsWith('/index.php') && (url.searchParams.get('action') === 'partes' || url.searchParams.get('action') === 'detalle'))) {
        event.respondWith(networkFirstAPI(event.request));
        return;
    }

    // 3. CSS y JS del módulo → Cache First (se cachean al primer uso)
    if (url.pathname.match(/\/partes_trabajo\/.+\.(css|js)(\?.*)?$/)) {
        event.respondWith(cacheFirstStatic(event.request));
        return;
    }

    // 4. Página principal del módulo → Network First, fallback caché
    if (url.pathname.endsWith('/partes_trabajo/') || url.pathname.endsWith('/partes_trabajo/index.php')) {
        event.respondWith(networkFirstPage(event.request));
        return;
    }

    // 5. Todo lo demás → red normal, sin interceptar
});

// ════════════════════════════════════════════════════════════════════════════
// Estrategias de caché
// ════════════════════════════════════════════════════════════════════════════

// Cache First: devuelve caché si existe, si no va a red y cachea
async function cacheFirstStatic(request) {
    try {
        const cached = await caches.match(request);
        if (cached) return cached;

        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_STATIC);
            cache.put(request, response.clone());
        }
        return response;
    } catch {
        const cached = await caches.match(request);
        if (cached) return cached;
        return new Response('/* offline */', { headers: { 'Content-Type': 'text/css' } });
    }
}

// Network First API: intenta red, si falla devuelve caché o error JSON
async function networkFirstAPI(request) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_API);
            cache.put(request, response.clone());
            notifyClients({ type: 'DATA_UPDATED' });
        }
        return response;
    } catch {
        const cached = await caches.match(request);
        if (cached) {
            notifyClients({ type: 'USING_CACHE' });
            return cached;
        }
        return new Response(
            JSON.stringify({ error: 'offline', partes: [] }),
            { status: 503, headers: { 'Content-Type': 'application/json' } }
        );
    }
}

// Network First Page: intenta red, si falla devuelve caché
async function networkFirstPage(request) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_STATIC);
            cache.put(request, response.clone());
        }
        return response;
    } catch {
        const cached = await caches.match(request);
        return cached ?? new Response(
            '<h1 style="font-family:sans-serif;padding:40px">Sin conexión</h1>',
            { headers: { 'Content-Type': 'text/html' } }
        );
    }
}

// ════════════════════════════════════════════════════════════════════════════
// Mensajes a clientes
// ════════════════════════════════════════════════════════════════════════════

function notifyClients(message) {
    self.clients.matchAll({ type: 'window' })
        .then(list => list.forEach(client => client.postMessage(message)));
}

// ── Push Notifications ────────────────────────────────────────────────────────
self.addEventListener('push', event => {
    const data = event.data?.json() ?? {};
    event.waitUntil(
        self.registration.showNotification(data.title ?? 'Nuevo parte asignado', {
            body:  data.body ?? 'Tienes un nuevo parte de trabajo pendiente.',
            icon:  self.registration.scope + 'icons/icon-192.png',
            tag:   'pt-nuevo-parte',
            data:  { url: data.url ?? self.registration.scope },
        })
    );
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = event.notification.data?.url ?? self.registration.scope;
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
            for (const client of list) {
                if (client.url.includes('partes_trabajo') && 'focus' in client)
                    return client.focus();
            }
            return clients.openWindow(target);
        })
    );
});
