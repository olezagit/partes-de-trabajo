/**
 * db.js — Capa de acceso a IndexedDB para Partes de Trabajo
 * Almacena localmente los partes, acciones pendientes y estado de sincronización.
 */

const PT_DB_NAME    = 'partes-trabajo-db';
const PT_DB_VERSION = 1;

// Stores:
//  'partes'         → datos de los albaranes (snapshot del servidor)
//  'acciones'       → acciones offline pendientes de sincronizar
//  'meta'           → metadatos (última sincronización, etc.)

const PtDB = (() => {
    let _db = null;

    function open() {
        if (_db) return Promise.resolve(_db);
        return new Promise((resolve, reject) => {
            const req = indexedDB.open(PT_DB_NAME, PT_DB_VERSION);

            req.onupgradeneeded = e => {
                const db = e.target.result;

                // Tabla principal de partes
                if (!db.objectStoreNames.contains('partes')) {
                    const store = db.createObjectStore('partes', { keyPath: 'rowid' });
                    store.createIndex('tipo',   'tipo_parte', { unique: false });
                    store.createIndex('statut', 'statut',     { unique: false });
                }

                // Cola de acciones offline
                if (!db.objectStoreNames.contains('acciones')) {
                    const aStore = db.createObjectStore('acciones', { keyPath: 'id', autoIncrement: true });
                    aStore.createIndex('parte_id', 'parte_id', { unique: false });
                    aStore.createIndex('estado',   'estado',   { unique: false });
                }

                // Metadatos
                if (!db.objectStoreNames.contains('meta')) {
                    db.createObjectStore('meta', { keyPath: 'key' });
                }
            };

            req.onsuccess = e => { _db = e.target.result; resolve(_db); };
            req.onerror   = e => reject(e.target.error);
        });
    }

    // ── Partes ──────────────────────────────────────────────────────────────────

    async function savePartes(partesList) {
        const db = await open();
        return new Promise((resolve, reject) => {
            const tx    = db.transaction(['partes', 'meta'], 'readwrite');
            const store = tx.objectStore('partes');

            // Limpiar datos anteriores y guardar los nuevos
            store.clear();
            partesList.forEach(p => store.put(p));

            // Actualizar timestamp de última sincronización
            tx.objectStore('meta').put({ key: 'lastSync', value: Date.now() });

            tx.oncomplete = resolve;
            tx.onerror    = () => reject(tx.error);
        });
    }

    async function getPartes() {
        const db = await open();
        return new Promise((resolve, reject) => {
            const tx  = db.transaction('partes', 'readonly');
            const req = tx.objectStore('partes').getAll();
            req.onsuccess = () => resolve(req.result);
            req.onerror   = () => reject(req.error);
        });
    }

    async function getParte(rowid) {
        const db = await open();
        return new Promise((resolve, reject) => {
            const tx  = db.transaction('partes', 'readonly');
            const req = tx.objectStore('partes').get(Number(rowid));
            req.onsuccess = () => resolve(req.result ?? null);
            req.onerror   = () => reject(req.error);
        });
    }

    // ── Acciones offline ────────────────────────────────────────────────────────

    /**
     * Guardar una acción pendiente de sincronizar.
     * @param {object} accion - { parte_id, tipo, payload, url, method }
     */
    async function saveAccion(accion) {
        const db = await open();
        return new Promise((resolve, reject) => {
            const tx    = db.transaction('acciones', 'readwrite');
            const store = tx.objectStore('acciones');
            const req   = store.add({
                ...accion,
                estado:    'pendiente',   // pendiente | enviando | ok | error
                timestamp: Date.now(),
                intentos:  0
            });
            req.onsuccess = () => resolve(req.result);   // devuelve el id generado
            tx.onerror    = () => reject(tx.error);
        });
    }

    async function getAccionesPendientes() {
        const db = await open();
        return new Promise((resolve, reject) => {
            const tx    = db.transaction('acciones', 'readonly');
            const idx   = tx.objectStore('acciones').index('estado');
            const req   = idx.getAll('pendiente');
            req.onsuccess = () => resolve(req.result);
            req.onerror   = () => reject(req.error);
        });
    }

    async function updateAccionEstado(id, estado) {
        const db = await open();
        return new Promise((resolve, reject) => {
            const tx    = db.transaction('acciones', 'readwrite');
            const store = tx.objectStore('acciones');
            const getReq = store.get(id);
            getReq.onsuccess = () => {
                const record = getReq.result;
                if (!record) return resolve();
                record.estado   = estado;
                record.intentos = (record.intentos || 0) + 1;
                if (estado === 'ok') record.syncedAt = Date.now();
                store.put(record);
                tx.oncomplete = resolve;
            };
            tx.onerror = () => reject(tx.error);
        });
    }

    async function countAccionesPendientes() {
        const db = await open();
        return new Promise((resolve, reject) => {
            const tx  = db.transaction('acciones', 'readonly');
            const idx = tx.objectStore('acciones').index('estado');
            const req = idx.count('pendiente');
            req.onsuccess = () => resolve(req.result);
            req.onerror   = () => reject(req.error);
        });
    }

    async function deleteAccion(id) {
        const db = await open();
        return new Promise((resolve, reject) => {
            const tx = db.transaction('acciones', 'readwrite');
            tx.objectStore('acciones').delete(id);
            tx.oncomplete = resolve;
            tx.onerror    = () => reject(tx.error);
        });
    }

    // ── Meta ────────────────────────────────────────────────────────────────────

    async function getMeta(key) {
        const db = await open();
        return new Promise((resolve, reject) => {
            const tx  = db.transaction('meta', 'readonly');
            const req = tx.objectStore('meta').get(key);
            req.onsuccess = () => resolve(req.result?.value ?? null);
            req.onerror   = () => reject(req.error);
        });
    }

    async function setMeta(key, value) {
        const db = await open();
        return new Promise((resolve, reject) => {
            const tx = db.transaction('meta', 'readwrite');
            tx.objectStore('meta').put({ key, value });
            tx.oncomplete = resolve;
            tx.onerror    = () => reject(tx.error);
        });
    }

    // API pública
    return {
        open,
        savePartes,
        getPartes,
        getParte,
        saveAccion,
        getAccionesPendientes,
        updateAccionEstado,
        countAccionesPendientes,
        deleteAccion,
        getMeta,
        setMeta
    };
})();

// Exportar para uso como módulo o global
if (typeof module !== 'undefined') module.exports = PtDB;
