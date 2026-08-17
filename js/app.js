/**
 * app.js — превращает сайт в устанавливаемое приложение.
 *
 * Подключается на всех страницах через layout.js и делает три вещи:
 *  • регистрирует сервис-воркер (без него нет ни установки, ни уведомлений на iOS);
 *  • показывает подсказку «Установить приложение» — своим текстом на Android
 *    и пошаговой инструкцией на iPhone, где браузер такой кнопки не даёт;
 *  • отдаёт остальному коду единый способ показать уведомление — psyNotify(),
 *    который работает и в обычном браузере, и в установленном приложении на iOS.
 *
 * Почему на iPhone уведомления не приходили раньше: Safari умеет веб-уведомления
 * только когда сайт добавлен на экран «Домой» и показывает их исключительно через
 * сервис-воркер. Ни манифеста, ни воркера у сайта не было — значит, ни установки,
 * ни разрешения на уведомления Safari не предлагал вовсе.
 */
(function (global) {
  'use strict';

  var SW_URL = '/sw.js';
  var swReady = null;

  /**
   * Манифест, иконка и мета-теги для «На экран Домой». Ставим из кода, а не из
   * разметки каждой страницы: chat.html свою шапку рисует сам и layout.js не
   * подключает — теги там просто не появлялись, а это главная страница
   * для уведомлений.
   */
  (function installAppTags() {
    function head() { return document.head || document.documentElement; }
    function addOnce(sel, tag, attrs) {
      if (document.querySelector(sel)) return;
      var el = document.createElement(tag);
      for (var a in attrs) el.setAttribute(a, attrs[a]);
      head().appendChild(el);
    }
    // Через PHP: статический .webmanifest reg.ru отдаёт без Content-Type и с nosniff,
    // и браузер отказывается считать его манифестом — установка не предлагается.
    addOnce('link[rel="manifest"]', 'link', { rel: 'manifest', href: '/api/manifest.php' });
    addOnce('link[rel="apple-touch-icon"]', 'link', { rel: 'apple-touch-icon', href: '/assets/icon-192.png' });
    addOnce('meta[name="theme-color"]', 'meta', { name: 'theme-color', content: '#7C3AED' });
    // Без этих строк приложение с домашнего экрана открывается как обычная вкладка Safari
    addOnce('meta[name="apple-mobile-web-app-capable"]', 'meta', { name: 'apple-mobile-web-app-capable', content: 'yes' });
    addOnce('meta[name="apple-mobile-web-app-status-bar-style"]', 'meta', { name: 'apple-mobile-web-app-status-bar-style', content: 'default' });
    addOnce('meta[name="apple-mobile-web-app-title"]', 'meta', { name: 'apple-mobile-web-app-title', content: 'psytalk' });
  })();

  function isStandalone() {
    return (global.matchMedia && global.matchMedia('(display-mode: standalone)').matches) ||
           global.navigator.standalone === true;
  }
  function isIos() {
    var ua = global.navigator.userAgent || '';
    // iPad с iOS 13+ представляется как Mac — отличаем по наличию касаний
    return /iPhone|iPad|iPod/.test(ua) ||
           (/Macintosh/.test(ua) && global.navigator.maxTouchPoints > 1);
  }

  function registerSw() {
    if (!('serviceWorker' in global.navigator)) return null;
    if (swReady) return swReady;
    swReady = global.navigator.serviceWorker.register(SW_URL)
      .then(function (reg) { return reg; })
      .catch(function () { return null; });
    return swReady;
  }

  /**
   * Показать уведомление. Возвращает true, если получилось.
   * Через сервис-воркер — потому что на iOS это единственный работающий путь,
   * а в остальных браузерах результат тот же.
   */
  async function psyNotify(title, body, url, tag) {
    try {
      if (!('Notification' in global) || Notification.permission !== 'granted') return false;
      var reg = await registerSw();
      if (reg && reg.showNotification) {
        await reg.showNotification(title || 'psytalk.pro', {
          body: body || '', icon: '/assets/icon-192.png', badge: '/assets/icon-192.png',
          tag: tag || 'psy-msg', renotify: true, data: { url: url || '/chat.html' }
        });
        return true;
      }
      new Notification(title || 'psytalk.pro', { body: body || '', icon: '/assets/icon-192.png' });
      return true;
    } catch (e) { return false; }
  }

  // ── Подписка на настоящие push-уведомления ────────────────────────────────
  // Без неё уведомление показывалось только пока открыта страница: она сама
  // опрашивала сервер. Браузер усыпляет такие опросы в фоне (на iPhone почти
  // сразу) — отсюда и «приходят через раз». Подписка отдаёт доставку службе
  // браузера, и сообщение приходит даже при закрытом приложении.

  function urlB64ToU8(base64) {
    var pad = '='.repeat((4 - base64.length % 4) % 4);
    var raw = atob((base64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  /** Подписаться и отдать подписку серверу. Возвращает true, если получилось. */
  async function psySubscribePush() {
    try {
      if (!('serviceWorker' in global.navigator) || !('PushManager' in global)) return false;
      if (!('Notification' in global) || Notification.permission !== 'granted') return false;
      var reg = await registerSw();
      if (!reg || !reg.pushManager) return false;

      var keyRes = await fetch('/api/push.php?action=key', { credentials: 'include' });
      var keyJson = await keyRes.json();
      if (!keyJson || !keyJson.ok || !keyJson.key) return false;

      var sub = await reg.pushManager.getSubscription();
      // Ключ сервера мог смениться — старая подписка тогда бесполезна, пересоздаём.
      if (sub) {
        var same = false;
        try {
          var cur = new Uint8Array(sub.options.applicationServerKey || []);
          var want = urlB64ToU8(keyJson.key);
          same = cur.length === want.length && cur.every(function (v, i) { return v === want[i]; });
        } catch (e) { same = false; }
        if (!same) { try { await sub.unsubscribe(); } catch (e) {} sub = null; }
      }
      if (!sub) {
        sub = await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlB64ToU8(keyJson.key),
        });
      }
      var raw = sub.toJSON ? sub.toJSON() : sub;
      var r = await fetch('/api/push.php?action=subscribe', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include',
        body: JSON.stringify({ endpoint: raw.endpoint, keys: raw.keys || {} }),
      });
      var d = await r.json().catch(function () { return {}; });
      return !!(d && d.ok);
    } catch (e) { return false; }
  }
  global.psySubscribePush = psySubscribePush;

  /**
   * Отключить это устройство от уведомлений.
   * Подписка — и есть согласие: рассылка смотрит на список устройств, а не на
   * отдельную галочку. Поэтому «не присылать» должно снимать именно подписку,
   * иначе уведомления продолжали бы приходить при выключенном переключателе.
   */
  async function psyUnsubscribePush() {
    try {
      if (!('serviceWorker' in global.navigator)) return false;
      var reg = await registerSw();
      var sub = reg && reg.pushManager ? await reg.pushManager.getSubscription() : null;
      var endpoint = sub ? (sub.endpoint || (sub.toJSON ? sub.toJSON().endpoint : '')) : '';
      if (sub) { try { await sub.unsubscribe(); } catch (e) {} }
      if (!endpoint) return false;
      var r = await fetch('/api/push.php?action=unsubscribe', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include',
        body: JSON.stringify({ endpoint: endpoint }),
      });
      var d = await r.json().catch(function () { return {}; });
      return !!(d && d.ok);
    } catch (e) { return false; }
  }
  global.psyUnsubscribePush = psyUnsubscribePush;

  /** Спросить разрешение на уведомления. Возвращает итоговое состояние. */
  async function psyAskNotify() {
    if (!('Notification' in global)) {
      // iOS до 16.4 и не установленное приложение: объекта Notification нет вовсе.
      return isIos() && !isStandalone() ? 'need-install' : 'unsupported';
    }
    if (Notification.permission === 'granted') { registerSw(); psySubscribePush(); return 'granted'; }
    if (Notification.permission === 'denied') return 'denied';
    try {
      var res = await Notification.requestPermission();
      if (res === 'granted') { registerSw(); psySubscribePush(); }
      return res;
    } catch (e) { return 'denied'; }
  }

  // ── Подсказка про установку ───────────────────────────────────────────────
  var HIDE_KEY = 'psy-install-hidden';

  function hidden() { try { return localStorage.getItem(HIDE_KEY) === '1'; } catch (e) { return false; } }
  function hide() { try { localStorage.setItem(HIDE_KEY, '1'); } catch (e) {} var b = document.getElementById('psyInstallBar'); if (b) b.remove(); }

  var deferredPrompt = null;
  global.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();          // покажем свою кнопку, а не браузерную «шторку»
    deferredPrompt = e;
    showBar('android');
    // Раздел «Установить приложение» открывается когда угодно, а это событие
    // приходит один раз при загрузке. Сообщаем ему, что кнопка теперь живая.
    try { global.dispatchEvent(new CustomEvent('psy-installable')); } catch (err) {}
  });

  /** Готов ли браузер поставить приложение своей кнопкой (Android/Chrome). */
  global.psyCanInstall = function () { return !!deferredPrompt; };

  /** Показать браузерное окно установки. 'accepted' | 'dismissed' | 'unavailable'. */
  global.psyInstallNow = async function () {
    if (!deferredPrompt) return 'unavailable';
    var p = deferredPrompt;
    deferredPrompt = null;
    try {
      p.prompt();
      var res = await p.userChoice;
      return (res && res.outcome) || 'dismissed';
    } catch (e) { return 'dismissed'; }
  };

  /**
   * Полный сброс приложения на этом устройстве: снять подписку, убрать воркер и
   * стереть его кэш. Нужен для переустановки: без этого «удалил иконку и добавил
   * заново» ничего не меняет — воркер и кэш живут отдельно от иконки, и человек
   * снова получает тот же старый интерфейс.
   * Вход и переписку не трогаем: это ни cookie, ни localStorage.
   */
  global.psyResetApp = async function () {
    var done = { push: false, worker: 0, caches: 0 };
    try { done.push = await psyUnsubscribePush(); } catch (e) {}
    try {
      if ('serviceWorker' in global.navigator) {
        var regs = await global.navigator.serviceWorker.getRegistrations();
        for (var i = 0; i < regs.length; i++) { try { if (await regs[i].unregister()) done.worker++; } catch (e) {} }
        swReady = null;
      }
    } catch (e) {}
    try {
      if (global.caches && caches.keys) {
        var keys = await caches.keys();
        for (var k = 0; k < keys.length; k++) { try { if (await caches.delete(keys[k])) done.caches++; } catch (e) {} }
      }
    } catch (e) {}
    return done;
  };

  function iosSteps() {
    return '<b>Установите приложение</b><span>Нажмите «Поделиться» ' +
           '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
           'style="vertical-align:-2px"><path d="M12 3v12M12 3l-4 4M12 3l4 4"/><path d="M4 14v5a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/></svg>' +
           ' внизу Safari → «На экран „Домой“». Тогда заработают и уведомления.</span>';
  }

  function showBar(kind) {
    if (hidden() || isStandalone() || document.getElementById('psyInstallBar')) return;
    var bar = document.createElement('div');
    bar.id = 'psyInstallBar';
    bar.style.cssText = 'position:fixed;left:0.75rem;right:0.75rem;bottom:0.75rem;z-index:2200;' +
      'background:#1A1A2E;color:#fff;border-radius:0.9rem;padding:0.8rem 0.9rem;display:flex;gap:0.7rem;' +
      'align-items:center;box-shadow:0 8px 28px rgba(0,0,0,0.32);font-size:0.86rem;line-height:1.45;' +
      'max-width:520px;margin:0 auto;';
    bar.innerHTML =
      '<span style="font-size:1.5rem;flex:0 0 auto">📲</span>' +
      '<span style="flex:1;min-width:0">' +
        (kind === 'ios'
          ? iosSteps().replace('<b>', '<b style="display:block">').replace('<span>', '<span style="display:block;color:#C9C6D6;font-size:0.8rem;margin-top:2px">')
          : '<b style="display:block">Установить приложение</b>' +
            '<span style="display:block;color:#C9C6D6;font-size:0.8rem;margin-top:2px">Чат и записи в одно касание, с экрана телефона.</span>') +
      '</span>' +
      (kind === 'android'
        ? '<button id="psyInstallGo" style="flex:0 0 auto;background:#34C759;color:#fff;border:none;border-radius:0.6rem;' +
          'padding:0.55rem 0.9rem;font:inherit;font-weight:700;cursor:pointer">Установить</button>'
        : '') +
      '<button id="psyInstallNo" aria-label="Скрыть" style="flex:0 0 auto;background:none;border:none;color:#9C9AAB;' +
      'font-size:1.1rem;cursor:pointer;padding:0 0.2rem">✕</button>';
    document.body.appendChild(bar);
    var no = document.getElementById('psyInstallNo');
    if (no) no.addEventListener('click', hide);
    var go = document.getElementById('psyInstallGo');
    if (go) go.addEventListener('click', async function () {
      if (!deferredPrompt) { hide(); return; }
      deferredPrompt.prompt();
      try { await deferredPrompt.userChoice; } catch (e) {}
      deferredPrompt = null;
      hide();
    });
  }

  /** Подсказку показываем не сразу: сначала пусть человек увидит сам сайт. */
  function maybeOfferInstall() {
    if (hidden() || isStandalone()) return;
    // На iPhone события beforeinstallprompt нет — предлагаем сами, но только тем,
    // кто уже вошёл: гостю приложение пока не нужно.
    if (isIos()) {
      var logged = false;
      try { logged = !!sessionStorage.getItem('psy_user'); } catch (e) {}
      if (logged) setTimeout(function () { showBar('ios'); }, 4000);
    }
  }

  global.psyNotify = psyNotify;
  global.psyAskNotify = psyAskNotify;
  global.psyIsStandalone = isStandalone;
  global.psyIsIos = isIos;
  global.psyRegisterSw = registerSw;
  global.psyOfferInstall = function () { showBar(isIos() ? 'ios' : 'android'); };

  /**
   * Воркер сообщил, что обновился, — перезагружаем страницу один раз.
   * Без этого у уже открытого окна остаются файлы прежней версии, и правки
   * интерфейса «не появляются» (именно так в кабинете оставались два меню).
   */
  function watchSwUpdates() {
    if (!('serviceWorker' in global.navigator)) return;
    // Была ли страница под управлением ПРЕЖНЕГО воркера. Если воркер ставится
    // впервые, устаревших файлов взяться негде — перезагружать нечего.
    var hadOldWorker = !!global.navigator.serviceWorker.controller;
    global.navigator.serviceWorker.addEventListener('message', function (e) {
      var d = e.data || {};
      if (d.type !== 'sw-updated') return;
      if (!hadOldWorker) return;
      var key = 'psy-sw-reloaded';
      var seen = null;
      try { seen = sessionStorage.getItem(key); } catch (err) {}
      if (seen === d.version) return;          // одна перезагрузка на версию, не цикл
      try { sessionStorage.setItem(key, d.version); } catch (err) {}
      reloadWhenSafe();
    });
  }

  /**
   * Перезагрузить, но не вырвав работу из рук: если человек что-то печатает или
   * открыт диалог, ждём, пока он уйдёт со страницы. Забрать недописанное
   * сообщение ради обновления интерфейса — плохая цена.
   */
  function reloadWhenSafe() {
    function busy() {
      try {
        var a = document.activeElement;
        if (a && /^(INPUT|TEXTAREA)$/.test(a.tagName) && String(a.value || '').trim() !== '') return true;
        var typed = document.querySelector('textarea');
        if (typed && String(typed.value || '').trim() !== '') return true;
        return !!document.querySelector('dialog[open]');
      } catch (e) { return false; }
    }
    if (!busy()) { location.reload(); return; }
    document.addEventListener('visibilitychange', function onHide() {
      if (document.visibilityState !== 'hidden') return;
      document.removeEventListener('visibilitychange', onHide);
      location.reload();
    });
  }

  function onReady() {
    registerSw();
    watchSwUpdates();
    maybeOfferInstall();
    // Подписка живёт не вечно: браузер её сбрасывает при чистке данных, а служба
    // доставки — сама по себе. Проверяем при каждом запуске, иначе уведомления
    // однажды тихо перестают приходить и никто не понимает почему.
    if ('Notification' in global && Notification.permission === 'granted') psySubscribePush();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', onReady);
  else onReady();
})(window);
