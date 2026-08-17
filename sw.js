/**
 * sw.js — сервис-воркер psytalk.pro.
 *
 * Нужен для трёх вещей:
 *  1) приложение можно установить на телефон (Android — «Установить», iOS — «На экран Домой»);
 *  2) уведомления в установленном приложении: на iOS веб-уведомления работают ТОЛЬКО из
 *     приложения на домашнем экране и только через сервис-воркер — без него их нет вовсе,
 *     поэтому раньше на айфоне ничего и не приходило;
 *  3) оболочка открывается сразу и не показывает «нет интернета» при плохой связи.
 *
 * Чего он намеренно НЕ делает: не кэширует ответы API и не отдаёт из кэша страницы
 * с перепиской. Сообщения, записи и оплаты должны быть свежими; закэшированная
 * переписка — это ещё и чужие данные на общем устройстве.
 */

const VERSION = 'psy-v1';
const SHELL = VERSION + '-shell';

// Оболочка: то, без чего окно не нарисуется. Страницы сюда не входим намеренно —
// им нужен свежий HTML (мы правим его часто), они берутся из сети.
const SHELL_FILES = [
    '/css/styles.css',
    '/js/layout.js',
    '/js/auth.js',
    '/manifest.webmanifest',
    '/assets/icon-192.png',
    '/assets/icon-512.png',
];

self.addEventListener('install', (e) => {
    e.waitUntil((async () => {
        const cache = await caches.open(SHELL);
        // addAll падает целиком, если хоть один файл не отдался, — кладём по одному
        await Promise.all(SHELL_FILES.map(u => cache.add(u).catch(() => {})));
        self.skipWaiting();
    })());
});

self.addEventListener('activate', (e) => {
    e.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.filter(k => k !== SHELL).map(k => caches.delete(k)));
        await self.clients.claim();
    })());
});

/** Запросы, которые нельзя кэшировать ни при каких условиях. */
function neverCache(url) {
    return url.pathname.startsWith('/api/') ||
           url.pathname.startsWith('/uploads/') ||
           url.pathname.endsWith('.html') ||
           url.pathname === '/';
}

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;   // чужие домены не наше дело

    if (neverCache(url)) {
        // Страницы и API — всегда из сети. Если сети нет, отдаём внятную заглушку,
        // а не браузерную ошибку «страница недоступна».
        e.respondWith(fetch(req).catch(async () => {
            if (req.mode === 'navigate') {
                return new Response(
                    '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
                    '<title>Нет связи — psytalk.pro</title>' +
                    '<div style="font-family:-apple-system,Inter,sans-serif;padding:2.5rem 1.5rem;text-align:center;color:#1A1A1A">' +
                    '<div style="font-size:2.5rem">📡</div>' +
                    '<h1 style="font-size:1.15rem;margin:.6rem 0">Нет связи</h1>' +
                    '<p style="color:#6B7280;line-height:1.6;font-size:.95rem">Проверьте интернет — сообщения отправятся, как только связь вернётся.</p>' +
                    '<button onclick="location.reload()" style="margin-top:1rem;padding:.7rem 1.4rem;border:none;border-radius:.7rem;' +
                    'background:#7C3AED;color:#fff;font:inherit;font-weight:700">Повторить</button></div>',
                    { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 }
                );
            }
            return new Response('', { status: 504 });
        }));
        return;
    }

    // Статика (css/js/иконки): сначала кэш, параллельно обновляем — так оболочка
    // открывается мгновенно, но не залипает на старой версии.
    e.respondWith((async () => {
        const cache = await caches.open(SHELL);
        const hit = await cache.match(req);
        const net = fetch(req).then(res => {
            if (res && res.ok) cache.put(req, res.clone()).catch(() => {});
            return res;
        }).catch(() => null);
        return hit || (await net) || new Response('', { status: 504 });
    })());
});

// ── Уведомления ─────────────────────────────────────────────────────────────
// Показ уведомления из сервис-воркера. На iOS это единственный работающий путь.
self.addEventListener('push', (e) => {
    let d = {};
    try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data ? e.data.text() : '' }; }
    const title = d.title || 'psytalk.pro';
    e.waitUntil(self.registration.showNotification(title, {
        body: d.body || 'Новое сообщение',
        icon: '/assets/icon-192.png',
        badge: '/assets/icon-192.png',
        tag: d.tag || 'psy-msg',
        renotify: true,
        data: { url: d.url || '/chat.html' },
    }));
});

// Клик по уведомлению: переводим в уже открытое окно, а не плодим новые вкладки.
self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    const target = (e.notification.data && e.notification.data.url) || '/chat.html';
    e.waitUntil((async () => {
        const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const c of all) {
            if (c.url.includes(self.location.origin)) {
                await c.focus();
                if ('navigate' in c) { try { await c.navigate(target); } catch (err) {} }
                return;
            }
        }
        await self.clients.openWindow(target);
    })());
});

// Страница просит показать уведомление (наш случай: сообщение пришло, пока окно открыто
// в фоне). Через сервис-воркер это работает и на iOS, где new Notification() недоступен.
self.addEventListener('message', (e) => {
    const d = e.data || {};
    if (d.type !== 'notify') return;
    self.registration.showNotification(d.title || 'psytalk.pro', {
        body: d.body || '',
        icon: '/assets/icon-192.png',
        badge: '/assets/icon-192.png',
        tag: d.tag || 'psy-msg',
        renotify: true,
        data: { url: d.url || '/chat.html' },
    });
});
