/**
 * Сквозные шапка и подвал psytalk.pro.
 * Подключение (в <head>): <script src="/js/layout.js"></script>
 * Вставка на странице (синхронно, без мерцания):
 *   шапка:  <script>psyWriteHeader();</script>
 *   подвал: <script>psyWriteFooter();</script>
 * Меняешь этот файл один раз — меняется на всех страницах.
 */
(function () {
  var path = location.pathname;
  function active(href) {
    var h = href.replace(/\/index\.html$/, '/');
    var p = path.replace(/\/index\.html$/, '/');
    if (h === '/') return p === '/' || p === '';
    return p === h || p.indexOf(h) !== -1;
  }

  var links = [
    { href: '/search.html', text: 'Найти психолога' },
    { href: '/offers.html', text: 'Пакеты и цены' },
    { href: '/blog.html', text: 'блог' }
  ];

  var personSvg =
    '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
    '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>';

  // Инлайн-сброс, чтобы любые правила страниц для li/a не ломали шапку
  var liReset = 'list-style:none;margin:0;padding:0;border:0;display:flex;align-items:center;';

  var loginCircle =
    '<li style="' + liReset + 'margin-left:2rem;flex-shrink:0;">' +
      '<a id="psyLoginCircle" href="/login.html" aria-label="Личный кабинет" title="Личный кабинет" ' +
      'style="width:40px;height:40px;min-width:40px;border-radius:50%;background:#34C759;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:0;transition:transform .2s;" ' +
      'onmouseover="this.style.transform=\'scale(1.05)\'" onmouseout="this.style.transform=\'scale(1)\'">' +
      personSvg +
      '</a>' +
    '</li>';

  var menu = links.map(function (l) {
    var st = active(l.href) ? 'color:#34C759;font-weight:600;' : '';
    return '<li style="' + liReset + '"><a href="' + l.href + '" class="nav-link" style="' + st + '">' + l.text + '</a></li>';
  }).join('') + loginCircle;

  // ВАЖНО: не используем класс .container внутри шапки — многие страницы
  // переопределяют .container (свой max-width/padding), из-за чего меню «плясало».
  // Здесь фиксированная обёртка с инлайн-стилями — одинаково на всех страницах.
  var headerHtml =
    '<nav class="nav" style="background:#fff;border-bottom:1px solid #F0F0F0;">' +
      '<div style="max-width:1200px;margin:0 auto;padding:0 1.5rem;width:100%;"><div class="nav-container">' +
        '<a href="/" class="nav-brand" style="font-size:1.25rem;font-weight:600;word-spacing:0;letter-spacing:0;">' +
          '<span style="color:#1A1A1A;">psy</span><span style="color:#7C3AED;font-style:italic;">talk.pro</span>' +
        '</a>' +
        '<button class="burger-menu" id="burgerMenu" aria-label="Меню"><span></span><span></span><span></span></button>' +
        '<ul class="nav-menu" id="navMenu">' + menu + '</ul>' +
      '</div></div>' +
    '</nav>';

  var footerHtml =
    '<footer class="site-footer">' +
      '<p>&copy; ' + new Date().getFullYear() + ' psytalk.pro. Все права защищены.</p>' +
      '<p style="margin-top:4px;font-size:0.8rem;color:#aaa;">ИП Вакульский Петр Валерьевич &nbsp;|&nbsp; ИНН: 230107911410 &nbsp;|&nbsp; ОГРНИП: 326470400081272</p>' +
      '<p style="margin-top:2px;font-size:0.8rem;color:#aaa;">Ленинградская обл., Всеволожский р-н, д. Новосаратовка &nbsp;|&nbsp; <a href="tel:+79626815320" style="color:#aaa;">+7 (962) 681-53-20</a> &nbsp;|&nbsp; <a href="mailto:support@psytalk.pro" style="color:#aaa;">support@psytalk.pro</a></p>' +
      '<p style="margin-top:8px;">' +
        '<a href="/offer.html">Публичная оферта</a>' +
        '<a href="/agent-offer.html">Агентский договор</a>' +
        '<a href="/privacy.html">Конфиденциальность</a>' +
        '<a href="/refund.html">Возврат средств</a>' +
        '<a href="/payment-info.html">Оплата и безопасность</a>' +
        '<a href="/consent.html">Согласие на обработку ПД</a>' +
        '<a href="/consent-health.html">Согласие на данные о здоровье</a>' +
        '<a href="/">Главная</a>' +
      '</p>' +
      '<p style="margin-top:10px;display:flex;gap:8px;justify-content:center;align-items:center;flex-wrap:wrap;">' +
        '<span style="font-size:0.75rem;color:#aaa;">Принимаем к оплате:</span>' +
        '<span style="background:#fff;border:1px solid #e5e5e5;border-radius:4px;padding:2px 8px;font-size:0.72rem;font-weight:700;color:#0066b3;">Мир</span>' +
        '<span style="background:#fff;border:1px solid #e5e5e5;border-radius:4px;padding:2px 8px;font-size:0.72rem;font-weight:700;color:#1a1f71;">VISA</span>' +
        '<span style="background:#fff;border:1px solid #e5e5e5;border-radius:4px;padding:2px 8px;font-size:0.72rem;font-weight:700;color:#eb001b;">Mastercard</span>' +
      '</p>' +
    '</footer>';

  // Синхронная вставка во время разбора HTML — без мерцания.
  window.psyWriteHeader = function () { document.write(headerHtml); };
  window.psyWriteFooter = function () { document.write(footerHtml); };

  function wireBurger() {
    var navMenu = document.getElementById('navMenu');
    var burger = document.getElementById('burgerMenu');
    if (!navMenu || !burger) return;
    burger.addEventListener('click', function (e) {
      e.stopPropagation();
      navMenu.classList.toggle('active');
      burger.classList.toggle('active');
      document.body.style.overflow = navMenu.classList.contains('active') ? 'hidden' : '';
    });
    document.addEventListener('click', function (e) {
      if (navMenu.classList.contains('active') && !navMenu.contains(e.target) && !burger.contains(e.target)) {
        navMenu.classList.remove('active');
        burger.classList.remove('active');
        document.body.style.overflow = '';
      }
    });
    navMenu.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () {
        navMenu.classList.remove('active');
        burger.classList.remove('active');
        document.body.style.overflow = '';
      });
    });
  }

  // Фолбэк: если на странице остались плейсхолдеры-блоки — заполнить их.
  function fillPlaceholders() {
    var h = document.getElementById('site-header');
    if (h) h.outerHTML = headerHtml;
    var f = document.getElementById('site-footer');
    if (f) f.outerHTML = footerHtml;
  }

  function showDevNotice() {
    try { if (sessionStorage.getItem('psy_dev_notice') === '1') return; } catch (e) {}
    var ov = document.createElement('div');
    ov.id = 'psyDevNotice';
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(26,26,46,0.55);z-index:2000;display:flex;align-items:center;justify-content:center;padding:1rem;';
    ov.innerHTML =
      '<div style="background:#fff;border-radius:1rem;max-width:420px;width:100%;padding:2rem;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.25);">' +
        '<div style="font-size:2.5rem;margin-bottom:0.5rem;">🚧</div>' +
        '<h3 style="font-size:1.25rem;font-weight:700;color:#1A1A1A;margin-bottom:0.6rem;">Сайт в разработке</h3>' +
        '<p style="color:#666;font-size:0.95rem;line-height:1.6;margin-bottom:1.5rem;">Мы ещё дорабатываем сервис — возможны временные неполадки, тестовые данные и изменения цен. Спасибо за понимание!</p>' +
        '<button id="psyDevOk" style="background:#34C759;color:#fff;border:none;font-weight:600;padding:0.75rem 2rem;border-radius:0.5rem;cursor:pointer;width:100%;">Понятно, продолжить</button>' +
      '</div>';
    document.body.appendChild(ov);
    document.getElementById('psyDevOk').addEventListener('click', function () {
      try { sessionStorage.setItem('psy_dev_notice', '1'); } catch (e) {}
      ov.remove();
    });
  }

  function dashUrlFor(user) {
    if (!user) return '/login.html';
    if (user.role === 'psychologist') return '/psychologist-dashboard.html';
    if (user.role === 'admin') return '/dashboard-admin.html';
    return '/client-dashboard.html';
  }

  function applyLoggedInCircle(user) {
    var circle = document.getElementById('psyLoginCircle');
    if (!circle || !user) return;
    circle.href = dashUrlFor(user);
    circle.title = (user.first_name || 'Кабинет');
    circle.style.background = 'linear-gradient(135deg,#7C3AED,#9F67FA)';
    circle.style.overflow = 'hidden';
    var avatar = user.avatar || user.avatar_url || user.photo || '';
    if (avatar) {
      // Показываем фото пользователя в кружке ЛК (на всех страницах одинаково)
      circle.innerHTML = '<img src="' + avatar + '" alt="" ' +
        'style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;" ' +
        'onerror="this.remove()">';
    } else {
      // Нет фото — инициал имени в кружке
      var initial = (user.first_name || user.email || '?').charAt(0).toUpperCase();
      circle.innerHTML = '<span style="color:#fff;font-weight:700;font-size:1rem;">' + initial + '</span>';
    }
  }

  function updateLoginCircle() {
    // 1) Мгновенно из кэша — чтобы клик по «ЛК» сразу вёл в кабинет, а не на вход.
    try {
      var c = sessionStorage.getItem('psy_user');
      if (c) applyLoggedInCircle(JSON.parse(c));
    } catch (e) {}
    // 2) Затем валидируем в фоне.
    if (!window.Auth || !window.Auth.getCurrentUser) return;
    try {
      Promise.resolve(window.Auth.getCurrentUser()).then(function(user) {
        if (user) applyLoggedInCircle(user);
      }).catch(function() {});
    } catch (e) {}
  }

  // Согласие с политикой конфиденциальности (один раз на устройство)
  function showConsentBanner() {
    try { if (localStorage.getItem('psy_privacy_consent') === '1') return; } catch (e) {}
    var bar = document.createElement('div');
    bar.id = 'psyConsent';
    bar.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:1900;background:#1A1A2E;color:#fff;padding:0.9rem 1.25rem;display:flex;align-items:center;justify-content:center;gap:1rem;flex-wrap:wrap;box-shadow:0 -4px 20px rgba(0,0,0,0.25);font-size:0.875rem;';
    bar.innerHTML =
      '<span style="max-width:760px;line-height:1.5;">🍪 Мы используем файлы cookie и обрабатываем данные для работы сайта. Продолжая пользоваться сайтом, вы соглашаетесь с ' +
      '<a href="/privacy.html" style="color:#9F67FA;font-weight:600;">политикой конфиденциальности</a> и ' +
      '<a href="/consent.html" style="color:#9F67FA;font-weight:600;">обработкой ПД</a>.</span>' +
      '<button id="psyConsentOk" style="background:#34C759;color:#fff;border:none;font-weight:700;padding:0.6rem 1.5rem;border-radius:0.5rem;cursor:pointer;white-space:nowrap;">Принять</button>';
    document.body.appendChild(bar);
    document.getElementById('psyConsentOk').addEventListener('click', function () {
      try { localStorage.setItem('psy_privacy_consent', '1'); } catch (e) {}
      bar.remove();
    });
  }

  // ── Закреплённый чат поддержки ───────────────────────────────────────────────
  function getCachedUserSafe() {
    try { var c = sessionStorage.getItem('psy_user'); return c ? JSON.parse(c) : null; } catch (e) { return null; }
  }
  var SUPPORT_ROLES = ['Клиент', 'Психолог', 'Хочу стать психологом', 'Компания (бизнес)', 'Другое'];
  function roleFromUser(u) {
    if (!u) return '';
    if (u.role === 'client') return 'Клиент';
    if (u.role === 'psychologist') return 'Психолог';
    return 'Другое';
  }
  function escSup(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }

  function buildSupportWidget() {
    if (document.getElementById('psySupport')) return;
    var user = getCachedUserSafe();
    if (user && user.role === 'admin') return; // админ отвечает в панели, виджет ему не нужен

    var TOKEN_KEY = 'psy_support_token';
    var token = null; try { token = localStorage.getItem(TOKEN_KEY); } catch (e) {}
    var pollTimer = null;

    var roleOpts = SUPPORT_ROLES.map(function (r) { return '<option value="' + r + '">' + r + '</option>'; }).join('');

    var el = document.createElement('div');
    el.id = 'psySupport';
    el.innerHTML =
      '<button id="psySupBubble" aria-label="Чат поддержки" style="position:fixed;right:20px;bottom:20px;z-index:1700;width:60px;height:60px;border-radius:50%;border:none;cursor:pointer;background:linear-gradient(135deg,#7C3AED,#9F67FA);color:#fff;font-size:1.6rem;box-shadow:0 8px 24px rgba(124,58,237,0.4);display:flex;align-items:center;justify-content:center;transition:transform .2s;">💬</button>' +
      '<div id="psySupPanel" style="display:none;position:fixed;right:20px;bottom:90px;z-index:1701;width:350px;max-width:calc(100vw - 32px);height:480px;max-height:calc(100vh - 120px);background:#fff;border-radius:1.1rem;box-shadow:0 20px 60px rgba(0,0,0,0.28);overflow:hidden;flex-direction:column;">' +
        '<div style="background:linear-gradient(135deg,#7C3AED,#9F67FA);color:#fff;padding:0.9rem 1.1rem;display:flex;align-items:center;justify-content:space-between;">' +
          '<div><div style="font-weight:700;">Чат поддержки</div><div style="font-size:0.75rem;opacity:0.9;">Обычно отвечаем в течение дня</div></div>' +
          '<button id="psySupClose" aria-label="Закрыть" style="background:rgba(255,255,255,0.2);border:none;color:#fff;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:1.05rem;">✕</button>' +
        '</div>' +
        '<div id="psySupForm" style="padding:1rem 1.1rem;overflow-y:auto;flex:1;">' +
          '<p style="color:#6B7280;font-size:0.85rem;line-height:1.5;margin-bottom:0.8rem;">Здравствуйте! Представьтесь, чтобы мы могли ответить.</p>' +
          '<input id="psySupName" placeholder="Ваше имя" style="width:100%;padding:0.6rem 0.75rem;border:1.5px solid #E5E7EB;border-radius:0.6rem;font-family:inherit;font-size:0.9rem;outline:none;margin-bottom:0.5rem;">' +
          '<input id="psySupEmail" type="email" placeholder="Email для ответа" style="width:100%;padding:0.6rem 0.75rem;border:1.5px solid #E5E7EB;border-radius:0.6rem;font-family:inherit;font-size:0.9rem;outline:none;margin-bottom:0.5rem;">' +
          '<select id="psySupRole" style="width:100%;padding:0.6rem 0.75rem;border:1.5px solid #E5E7EB;border-radius:0.6rem;font-family:inherit;font-size:0.9rem;outline:none;margin-bottom:0.5rem;background:#fff;">' + roleOpts + '</select>' +
          '<textarea id="psySupFirstMsg" placeholder="Ваш вопрос..." style="width:100%;height:72px;padding:0.6rem 0.75rem;border:1.5px solid #E5E7EB;border-radius:0.6rem;font-family:inherit;font-size:0.9rem;outline:none;resize:vertical;margin-bottom:0.5rem;"></textarea>' +
          '<div id="psySupFormErr" style="display:none;color:#DC2626;font-size:0.8rem;margin-bottom:0.5rem;"></div>' +
          '<button id="psySupStart" style="width:100%;padding:0.7rem;background:linear-gradient(135deg,#7C3AED,#9F67FA);color:#fff;border:none;border-radius:0.6rem;font-weight:700;cursor:pointer;font-family:inherit;">Начать чат</button>' +
        '</div>' +
        '<div id="psySupChat" style="display:none;flex:1;flex-direction:column;min-height:0;">' +
          '<div id="psySupMsgs" style="flex:1;overflow-y:auto;padding:0.85rem;display:flex;flex-direction:column;gap:0.5rem;background:#F7F8FA;"></div>' +
          '<div style="display:flex;gap:0.5rem;padding:0.6rem;border-top:1px solid #eee;">' +
            '<input id="psySupInput" placeholder="Сообщение..." style="flex:1;padding:0.6rem 0.8rem;border:1.5px solid #E5E7EB;border-radius:1.2rem;font-family:inherit;font-size:0.9rem;outline:none;">' +
            '<button id="psySupSend" style="width:42px;height:42px;border-radius:50%;border:none;background:linear-gradient(135deg,#7C3AED,#9F67FA);color:#fff;cursor:pointer;font-size:1.05rem;flex-shrink:0;">➤</button>' +
          '</div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(el);

    var bubble = document.getElementById('psySupBubble');
    var panel = document.getElementById('psySupPanel');
    var formView = document.getElementById('psySupForm');
    var chatView = document.getElementById('psySupChat');

    // Авто-подстановка для залогиненных
    if (user) {
      var nm = ((user.first_name || '') + ' ' + (user.last_name || '')).trim();
      var nameI = document.getElementById('psySupName'); if (nameI) nameI.value = nm;
      var emailI = document.getElementById('psySupEmail'); if (emailI) emailI.value = user.email || '';
      var roleS = document.getElementById('psySupRole'); if (roleS) roleS.value = roleFromUser(user);
      // Прячем поля идентификации — контакты возьмём из аккаунта
      ['psySupName', 'psySupEmail', 'psySupRole'].forEach(function (idd) { var e = document.getElementById(idd); if (e) e.style.display = 'none'; });
      var hint = formView.querySelector('p'); if (hint) hint.textContent = 'Здравствуйте, ' + (user.first_name || '') + '! Напишите ваш вопрос — ответим в чат.';
    }

    function showChat() { formView.style.display = 'none'; chatView.style.display = 'flex'; }
    function renderMsgs(rows) {
      var box = document.getElementById('psySupMsgs');
      if (!box) return;
      if (!rows.length) { box.innerHTML = '<div style="color:#9CA3AF;text-align:center;font-size:0.85rem;padding:1rem;">Напишите сообщение — мы ответим здесь.</div>'; return; }
      box.innerHTML = rows.map(function (m) {
        var mine = m.sender === 'user';
        return '<div style="align-self:' + (mine ? 'flex-end' : 'flex-start') + ';max-width:80%;background:' + (mine ? 'linear-gradient(135deg,#7C3AED,#9F67FA)' : '#fff') + ';color:' + (mine ? '#fff' : '#1A1A1A') + ';border:' + (mine ? 'none' : '1px solid #ECECEC') + ';border-radius:0.9rem;padding:0.5rem 0.75rem;font-size:0.875rem;line-height:1.4;">' +
          (mine ? '' : '<div style="font-size:0.7rem;color:#7C3AED;font-weight:700;margin-bottom:0.15rem;">Поддержка</div>') +
          escSup(m.body) + '</div>';
      }).join('');
      box.scrollTop = box.scrollHeight;
    }
    function poll() {
      if (!token) return;
      fetch('/api/support.php?action=poll&token=' + encodeURIComponent(token), { credentials: 'include' })
        .then(function (r) { return r.json(); }).then(function (d) { if (d && d.ok) renderMsgs(d.data || []); }).catch(function () {});
    }
    function startPolling() { if (pollTimer) clearInterval(pollTimer); poll(); pollTimer = setInterval(poll, 7000); }

    bubble.addEventListener('click', function () {
      var open = panel.style.display === 'flex';
      panel.style.display = open ? 'none' : 'flex';
      if (!open) {
        if (token) { showChat(); startPolling(); }
        else if (user) { showChat(); } // залогинен — сразу чат, первое сообщение создаст тред
        setTimeout(function () { var i = document.getElementById(token || user ? 'psySupInput' : 'psySupFirstMsg'); if (i) i.focus(); }, 80);
      } else if (pollTimer) { clearInterval(pollTimer); }
    });
    document.getElementById('psySupClose').addEventListener('click', function () { panel.style.display = 'none'; if (pollTimer) clearInterval(pollTimer); });

    function doStart(message, name, email, role) {
      return fetch('/api/support.php?action=start', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include',
        body: JSON.stringify({ token: token || '', message: message, name: name, email: email, role: role })
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.ok && d.token) { token = d.token; try { localStorage.setItem(TOKEN_KEY, token); } catch (e) {} showChat(); startPolling(); return true; }
        throw new Error((d && d.error) || 'Ошибка');
      });
    }

    document.getElementById('psySupStart').addEventListener('click', function () {
      var err = document.getElementById('psySupFormErr');
      var msg = document.getElementById('psySupFirstMsg').value.trim();
      var name = user ? '' : document.getElementById('psySupName').value.trim();
      var email = user ? '' : document.getElementById('psySupEmail').value.trim();
      var role = user ? '' : document.getElementById('psySupRole').value;
      if (!user && !name) { err.textContent = 'Укажите имя'; err.style.display = 'block'; return; }
      if (!msg) { err.textContent = 'Введите вопрос'; err.style.display = 'block'; return; }
      err.style.display = 'none';
      doStart(msg, name, email, role).catch(function () { err.textContent = 'Не удалось отправить. Попробуйте ещё раз.'; err.style.display = 'block'; });
    });

    function sendChat() {
      var inp = document.getElementById('psySupInput');
      var msg = inp.value.trim();
      if (!msg) return;
      inp.value = '';
      if (!token) { doStart(msg, '', '', '').catch(function () { inp.value = msg; }); return; }
      fetch('/api/support.php?action=send', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include',
        body: JSON.stringify({ token: token, message: msg })
      }).then(function () { poll(); }).catch(function () { inp.value = msg; });
    }
    document.getElementById('psySupSend').addEventListener('click', sendChat);
    document.getElementById('psySupInput').addEventListener('keypress', function (e) { if (e.key === 'Enter') sendChat(); });
  }

  // Подстановка актуальной цены из настроек (единый источник) в любые элементы
  // с классом .psy-first-price (первая/ознакомительная сессия) и .psy-base-price.
  function fillDynamicPrices() {
    var els = document.querySelectorAll('.psy-first-price, .psy-base-price');
    if (!els.length) return;
    fetch('/api/settings.php?action=public').then(function (r) { return r.json(); }).then(function (s) {
      if (!s) return;
      var rub = function (n) { var v = parseInt(n, 10); return isNaN(v) ? null : v.toLocaleString('ru-RU') + ' ₽'; };
      var enabled = String(s.promo_self_enabled || '0') === '1';
      var raw = (s.promo_self_deadline || '').trim();
      var iso = raw && raw.length <= 16 ? raw + ':00+03:00' : raw;
      var dl = raw ? new Date(iso) : null;
      var valid = dl && !isNaN(dl.getTime()) && dl > new Date();
      var first = (enabled && valid && s.promo_self_price) ? rub(s.promo_self_price) : rub(s.price_self);
      var base = rub(s.price_self);
      if (first) document.querySelectorAll('.psy-first-price').forEach(function (e) { e.textContent = first; });
      if (base) document.querySelectorAll('.psy-base-price').forEach(function (e) { e.textContent = base; });
    }).catch(function () { /* оставляем статическое значение */ });
  }

  function onReady() {
    fillPlaceholders();
    fillDynamicPrices();
    wireBurger();
    // showDevNotice(); — отключено: оверлей «сайт в разработке» мешает проверке эквайринга
    showConsentBanner();
    buildSupportWidget();
    if (window.lucide && lucide.createIcons) lucide.createIcons();
    updateLoginCircle();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', onReady);
  } else {
    onReady();
  }
})();
