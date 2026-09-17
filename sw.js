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

const VERSION = 'psy-v11';  // быстрые ответы: свои фразы пользователя из payload
const SHELL = VERSION + '-shell';

// Оболочка: то, без чего окно не нарисуется. Страницы сюда не входят намеренно —
// им нужен свежий HTML (мы правим его часто), они берутся из сети. Манифест тоже
// не здесь: он отдаётся через /api/manifest.php, а /api/* мы не кэшируем вовсе.
const SHELL_FILES = [
    '/css/styles.css',
    '/js/layout.js',
    '/js/auth.js',
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
        // Открытым окнам говорим, что версия сменилась: у тех, кто уже успел
        // получить старые файлы, интерфейс иначе останется прежним до ручной
        // перезагрузки — именно так и «остались два меню».
        const all = await self.clients.matchAll({ type: 'window' });
        all.forEach(c => { try { c.postMessage({ type: 'sw-updated', version: VERSION }); } catch (err) {} });
    })());
});

/** Запросы, которые нельзя кэшировать ни при каких условиях. */
function neverCache(url) {
    return url.pathname.startsWith('/api/') ||
           url.pathname.startsWith('/uploads/') ||
           url.pathname.endsWith('.html') ||
           url.pathname === '/';
}

/**
 * ПРИЁМ «ПОДЕЛИТЬСЯ» ИЗ ДРУГИХ ПРИЛОЖЕНИЙ.
 *
 * Система отдаёт то, чем поделились, обычной формой POST — а страницу по POST не
 * откроешь. Поэтому забираем содержимое здесь, складываем во временное хранилище
 * и переводим человека на обычный адрес чата, который уже покажет, куда отправить.
 * Так работают все приложения, принимающие «поделиться» в вебе.
 */
const SHARE_CACHE = 'psy-share';

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method === 'POST' && new URL(req.url).pathname === '/share-target') {
        e.respondWith((async () => {
            try {
                const form = await req.formData();
                const id = 's' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
                const cache = await caches.open(SHARE_CACHE);
                const files = (form.getAll('files') || []).filter(f => f && typeof f.size === 'number');
                const meta = {
                    title: String(form.get('title') || ''),
                    text: String(form.get('text') || ''),
                    url: String(form.get('url') || ''),
                    files: [],
                };
                for (let i = 0; i < files.length && i < 10; i++) {
                    const f = files[i];
                    const type = f.type || 'application/octet-stream';
                    meta.files.push({ name: f.name || ('файл-' + (i + 1)), type: type, size: f.size });
                    await cache.put('/__share/' + id + '/' + i,
                        new Response(f, { headers: { 'Content-Type': type } }));
                }
                await cache.put('/__share/' + id,
                    new Response(JSON.stringify(meta), { headers: { 'Content-Type': 'application/json' } }));
                return Response.redirect('/chat.html?share=' + id, 303);
            } catch (err) {
                // Что-то пошло не так — всё равно открываем чат, а не пустую ошибку
                return Response.redirect('/chat.html', 303);
            }
        })());
        return;
    }
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;   // чужие домены не наше дело

    // API и загруженные файлы вообще не пропускаем через воркер: кэшировать их
    // нельзя, а лишний посредник только добавляет задержку к каждому обращению.
    // Раньше они шли через respondWith(fetch(...)) — то же самое, но через нас.
    if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/uploads/')) return;

    if (neverCache(url)) {
        // Страницы — всегда из сети. Если сети нет, отдаём внятную заглушку,
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
                    'background:#047857;color:#fff;font:inherit;font-weight:700">Повторить</button></div>',
                    { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 }
                );
            }
            return new Response('', { status: 504 });
        }));
        return;
    }

    // Статика (css/js/иконки): СНАЧАЛА СЕТЬ, кэш — только если сети нет.
    //
    // Сначала было наоборот («сначала кэш, потом тихо обновим»), и это дало
    // настоящую беду: телефон продолжал открывать вчерашний layout.js, поэтому
    // правки интерфейса не появлялись — в кабинете так и висели два меню, хотя
    // на сервере лежал уже исправленный файл. Наш css/js меняется каждый день,
    // и мгновенная отрисовка не стоит показа устаревшего интерфейса.
    e.respondWith((async () => {
        const cache = await caches.open(SHELL);
        try {
            const res = await fetch(req);
            if (res && res.ok) cache.put(req, res.clone()).catch(() => {});
            return res;
        } catch (err) {
            const hit = await cache.match(req);
            return hit || new Response('', { status: 504 });
        }
    })());
});

// ── Уведомления ─────────────────────────────────────────────────────────────
// Показ уведомления из сервис-воркера. На iOS это единственный работающий путь.
// Пуш приходит БЕЗ содержимого: текст переписки не должен идти через чужую службу
// доставки, да и шифрование содержимого — отдельная гора кода. Поэтому воркер сам
// спрашивает сервер, что показать. Если спросить не удалось, показываем нейтральное
// уведомление: правило браузера — на каждый пуш обязано быть видимое уведомление,
// иначе подписку у нас отберут.
/**
 * Отметка о полученном пуше — чтобы можно было отличить две разные беды:
 * «браузер пуш не получил» (тогда дело в устройстве или сети) и «получил, но не
 * показал» (тогда дело в нас). Без такой отметки оставалось только гадать.
 * Пишем в Cache Storage: он доступен и воркеру, и странице.
 */
async function logPush(info) {
    try {
        const c = await caches.open('psy-push-log');
        await c.put('/__push-log', new Response(JSON.stringify(info), {
            headers: { 'Content-Type': 'application/json' },
        }));
    } catch (err) {}
}

self.addEventListener('push', (e) => {
    e.waitUntil((async () => {
        let d = null;
        // На всякий случай понимаем и пуш с содержимым — если когда-нибудь начнём его слать
        try { if (e.data) d = e.data.json(); } catch (err) { d = null; }
        if (!d) {
            try {
                const r = await fetch('/api/push.php?action=pending', { credentials: 'include', cache: 'no-store' });
                if (r.ok) d = await r.json();
            } catch (err) { d = null; }
        }
        const title = (d && d.title) || 'psytalk.pro';
        const body = (d && d.body) || 'Новое сообщение в чатах';
        const url = (d && d.url) || '/chat.html';
        const count = d && typeof d.count === 'number' ? d.count : 0;
        const isCall = !!(d && d.call);
        const canReply = !!(d && d.can_reply);
        // ВАЖНО про «ответить прямо в уведомлении». Веб-уведомления (PWA) НЕ умеют
        // поле ввода в шторке — это возможно только в нативном приложении. Поэтому
        // отдельной кнопки «Ответить» больше нет (она вводила в заблуждение — работала
        // как «Открыть»). Вместо этого сам тап по уведомлению открывает нужный чат
        // сразу с курсором в поле ввода (reply=1) — это лучшее, что доступно в вебе.
        const openUrl = canReply ? (d.reply_url || (url + (url.indexOf('?') >= 0 ? '&' : '?') + 'reply=1')) : url;
        // Быстрые ответы прямо из уведомления: свободный текст в вебе печатать нельзя,
        // но готовую фразу можно отправить по кнопке БЕЗ открытия приложения — сервис-
        // воркер сам сделает запрос с кукой сессии. Показываем только для одиночного
        // диалога (когда точно известно, кому слать).
        const quick = !!(d && d.quick && d.peer);
        // Кнопки быстрого ответа: берём фразы пользователя (из payload), иначе — запасные.
        const qrList = (quick && Array.isArray(d.quick_replies) && d.quick_replies.length)
            ? d.quick_replies.slice(0, 2)
            : (quick ? [{ id: 'qr0', title: '👍 Ок', text: '👍 Ок' }, { id: 'qr1', title: 'Позже отвечу', text: 'Отвечу чуть позже 🙏' }] : []);
        const qrMap = {}; qrList.forEach(q => { qrMap[q.id] = q.text; });
        // Звонок ведёт себя иначе, чем сообщение: не гаснет сам, вибрирует «очередью»
        // и не сворачивается в общую ленту уведомлений — иначе вызов легко пропустить
        // при выключенном экране.
        await self.registration.showNotification(title, {
            body,
            icon: '/assets/icon-192.png',
            badge: '/assets/icon-192.png',
            tag: isCall ? 'psy-call' : 'psy-msg',   // одно уведомление, а не лента одинаковых
            renotify: true,
            requireInteraction: isCall,
            // Вибрация звонка длиннее и настойчивее обычного уведомления: телефон в
            // кармане должен дать понять, что это вызов, а не сообщение.
            vibrate: isCall ? [500, 250, 500, 250, 500, 250, 500] : undefined,
            silent: false,
            // Звонок → «Ответить»; одиночное сообщение → две кнопки быстрого ответа,
            // которые отправляют готовую фразу без открытия приложения.
            actions: isCall ? [{ action: 'answer', title: 'Ответить' }]
                   : (qrList.length ? qrList.map(q => ({ action: q.id, title: (q.title || '').slice(0, 24) || 'Ответ' })) : undefined),
            data: { url: openUrl, peer: (d && d.peer) || '', kind: (d && d.kind) || 'msg', qr: qrMap },
        });
        // Число на иконке приложения, где это поддерживается
        try {
            if (count > 0 && self.navigator && self.navigator.setAppBadge) await self.navigator.setAppBadge(count);
        } catch (err) {}
        await logPush({ at: Date.now(), title, body, asked: !!d, version: VERSION });
    })());
});

// Готовые фразы для кнопок быстрого ответа.
const QUICK_REPLIES = { qr_ok: '👍 Ок', qr_later: 'Отвечу чуть позже 🙏' };

/** Отправить готовую фразу нужному собеседнику — прямо из воркера, без открытия окна. */
async function swSendQuickReply(data, text) {
    const peer = data.peer || '';
    if (!peer) return false;
    const opt = (b) => ({ method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) });
    try {
        if (data.kind === 'group' || peer.indexOf('group:') === 0) {
            const gid = peer.indexOf('group:') === 0 ? peer.slice(6) : peer;
            const r = await fetch('/api/group_chat.php?action=send', opt({ group_id: gid, content: text }));
            return r.ok;
        }
        const r = await fetch('/api/messages.php?action=send', opt({ receiver_id: peer, content: text }));
        // Разбудить получателя пушем (best-effort).
        try { await fetch('/api/push.php?action=poke', opt({ to: peer })); } catch (e) {}
        return r.ok;
    } catch (e) { return false; }
}

// Клик по уведомлению: кнопки быстрого ответа отправляют фразу без открытия окна;
// остальное — переводим в уже открытое окно, а не плодим новые вкладки.
self.addEventListener('notificationclick', (e) => {
    const data = e.notification.data || {};
    const action = e.action || '';
    e.notification.close();
    try { if (self.navigator && self.navigator.clearAppBadge) self.navigator.clearAppBadge(); } catch (err) {}

    // Быстрый ответ готовой фразой — приложение НЕ открываем.
    if (action.indexOf('qr') === 0) {
        const text = (data.qr && data.qr[action]) || QUICK_REPLIES[action] || '👍 Ок';
        e.waitUntil((async () => {
            const ok = await swSendQuickReply(data, text);
            if (ok) {
                // Короткое подтверждение, что ответ ушёл, и авто-закрытие.
                try {
                    await self.registration.showNotification('Ответ отправлен ✓', {
                        body: text, icon: '/assets/icon-192.png', badge: '/assets/icon-192.png',
                        tag: 'psy-reply', silent: true,
                    });
                    await new Promise(res => setTimeout(res, 3000));
                    const ns = await self.registration.getNotifications({ tag: 'psy-reply' });
                    ns.forEach(n => n.close());
                } catch (err) {}
            } else {
                // Не отправилось — открываем чат, чтобы человек ответил вручную.
                const t = data.url || '/chat.html';
                const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
                for (const c of all) { if (c.url.includes(self.location.origin)) { await c.focus(); if ('navigate' in c) { try { await c.navigate(t); } catch (err) {} } return; } }
                await self.clients.openWindow(t);
            }
        })());
        return;
    }

    const target = data.url || '/chat.html';
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
