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
    { href: '/feed.html', text: 'Лента' },
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

  var themeToggleItem =
    '<li id="psyThemeToggleWrap" style="' + liReset + 'margin-left:0.6rem;flex-shrink:0;">' +
      '<button id="psyThemeToggle" aria-label="Светлая/тёмная тема" title="Светлая/тёмная тема" ' +
      'style="width:38px;height:38px;min-width:38px;border-radius:50%;border:1.5px solid #E5E7EB;background:#fff;cursor:pointer;font-size:1.05rem;display:inline-flex;align-items:center;justify-content:center;transition:transform .2s;" ' +
      'onmouseover="this.style.transform=\'scale(1.05)\'" onmouseout="this.style.transform=\'scale(1)\'">🌙</button>' +
    '</li>';

  var menu = links.map(function (l) {
    var st = active(l.href) ? 'color:#34C759;font-weight:600;' : '';
    return '<li style="' + liReset + '"><a href="' + l.href + '" class="nav-link" style="' + st + '">' + l.text + '</a></li>';
  }).join('') + themeToggleItem + loginCircle;

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

  var darkCss = document.createElement('style');
  darkCss.textContent =
    'body.dark .nav{background:#1E1E1E!important;border-bottom-color:#333!important}' +
    'body.dark .nav-brand span:first-child{color:#E6E6E6!important}' +
    'body.dark .nav-link{color:#D1D5DB!important}' +
    // iOS Safari зумит страницу при фокусе на поле с font-size < 16px, после чего вёрстка
    // становится шире экрана. Правило есть в css/styles.css, но часть страниц (privacy,
    // refund, offer, consent*) её не подключают, а layout.js есть на всех — дублируем здесь,
    // чтобы поля виджета поддержки и форм не вызывали зум ни на одной странице.
    '@media (max-width:768px){' +
      'input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=color]),' +
      'textarea,select{font-size:16px!important}' +
      '.site-footer a{display:inline-block;margin:3px 6px}' +
    '}' +
    'body.dark .nav-link:hover{color:#A78BFA!important}' +
    'body.dark .nav-menu{background:#1E1E1E!important}' +
    'body.dark .site-footer{background:#1A1A1A!important;color:#9CA3AF!important}' +
    'body.dark .site-footer a{color:#9CA3AF!important}' +
    // Логотипы платёжных систем — тёмные брендовые цвета текста. Перекрашивать их подложку
    // в тёмную нельзя: получался тёмный текст на тёмном фоне (VISA — контраст 1.01).
    'body.dark .site-footer span[style*="background:#fff"]{background:#F3F4F6!important;border-color:#555!important}' +
    'body.dark #psySupPanel{background:#1E1E1E!important}' +
    'body.dark #psySupForm{background:#1E1E1E!important}' +
    'body.dark #psySupForm p{color:#9CA3AF!important}' +
    'body.dark #psySupForm input,body.dark #psySupForm select,body.dark #psySupForm textarea{background:#2A2A2A!important;border-color:#444!important;color:#E6E6E6!important}' +
    'body.dark #psySupMsgs{background:#1A1A1A!important}' +
    'body.dark #psySupChat>div:last-child{border-color:#333!important;background:#1E1E1E!important}' +
    'body.dark #psySupInput{background:#2A2A2A!important;border-color:#444!important;color:#E6E6E6!important}' +
    'body.dark #psyDevNotice>div{background:#1E1E1E!important}' +
    'body.dark #psyDevNotice h3{color:#E6E6E6!important}' +
    'body.dark #psyDevNotice p{color:#9CA3AF!important}' +
    'body.dark #psyThemeToggle{background:#2A2A2A!important;border-color:#444!important;color:#E6E6E6!important}';
  document.head.appendChild(darkCss);

  // ── Единая светлая/тёмная тема (ключ localStorage: psy-theme) ────────────────
  // Личные кабинеты клиента/психолога и админка держат свои переключатели (тот же
  // ключ) — на этих страницах кнопку в шапке скрываем, чтобы не дублировать.
  function isDarkNow() { return document.body.classList.contains('dark'); }
  function applyStoredTheme() {
    try {
      var t = localStorage.getItem('psy-theme');
      var dark = t === 'dark' || (!t && window.matchMedia && window.matchMedia('(prefers-color-scheme:dark)').matches);
      document.body.classList.toggle('dark', !!dark);
    } catch (e) {}
  }
  function updateThemeToggleIcon() {
    var btn = document.getElementById('psyThemeToggle');
    if (btn) btn.textContent = isDarkNow() ? '☀️' : '🌙';
  }
  function toggleSiteTheme() {
    var dark = !isDarkNow();
    document.body.classList.toggle('dark', dark);
    try { localStorage.setItem('psy-theme', dark ? 'dark' : 'light'); } catch (e) {}
    updateThemeToggleIcon();
  }
  window.psyToggleTheme = toggleSiteTheme;
  function wireThemeToggle() {
    var wrap = document.getElementById('psyThemeToggleWrap');
    var btn = document.getElementById('psyThemeToggle');
    if (!btn) return;
    // Страница уже даёт свой переключатель темы (личный кабинет/админка) — не дублируем.
    if (document.getElementById('themeBtn') || document.getElementById('themeIcon')) {
      if (wrap) wrap.style.display = 'none';
      return;
    }
    updateThemeToggleIcon();
    btn.addEventListener('click', toggleSiteTheme);
  }

  // Синхронная вставка во время разбора HTML — без мерцания.
  window.psyWriteHeader = function () { applyStoredTheme(); document.write(headerHtml); };
  window.psyWriteFooter = function () { document.write(footerHtml); };

  // ── Страховка читаемости текста в тёмной теме ──────────────────────────────────
  // В тёмной теме часть правил задаёт светлый цвет текста в паре с тёмным фоном
  // (например .badge-secondary: color #B794F4 + background #2D1F4E). Но если фон этого
  // же элемента задан ИНЛАЙНОВО светлым, инлайн побеждает класс — остаётся светлый текст
  // на светлой подложке, который не читается. Обратный случай тоже встречается.
  // CSS такое не выразит (нужно знать фактический фон), поэтому считаем реальный контраст
  // и правим только там, где он ниже порога. Цвет меняем в ту сторону, которой не хватает.
  //
  // ВАЖНО: цвет ставим КЛАССОМ, а не el.style.setProperty. Тёмная тема ловит инлайновые
  // светлые фоны атрибутными селекторами (body.dark div[style*="background:#fff"]), а любая
  // запись в el.style пересобирает весь атрибут style (background:#fff → background: rgb(255, 255, 255)),
  // селектор перестаёт совпадать, фон возвращается в белый — и текст становится ещё хуже видно.
  var fixCss = document.createElement('style');
  fixCss.textContent =
    'body.dark .psy-fg-dark.psy-fg-dark{color:#1A1A1A!important}' +
    'body.dark .psy-fg-light.psy-fg-light{color:#E6E6E6!important}' +
    'body.dark .psy-fg-plum.psy-fg-plum{color:#4C1D95!important}' +
    'body.dark .psy-fg-lilac.psy-fg-lilac{color:#B794F4!important}' +
    'body.dark .psy-fg-mgrey.psy-fg-mgrey{color:#565656!important}' +
    'body.dark .psy-fg-lgrey.psy-fg-lgrey{color:#A8A8A8!important}';
  document.head.appendChild(fixCss);
  var FG_CLASSES = {
    '#1A1A1A': 'psy-fg-dark', '#E6E6E6': 'psy-fg-light',
    '#4C1D95': 'psy-fg-plum', '#B794F4': 'psy-fg-lilac',
    '#565656': 'psy-fg-mgrey', '#A8A8A8': 'psy-fg-lgrey'
  };
  var FG_ALL = ['psy-fg-dark', 'psy-fg-light', 'psy-fg-plum', 'psy-fg-lilac', 'psy-fg-mgrey', 'psy-fg-lgrey'];

  function psyFixAccentContrast() {
    try {
      if (!document.body.classList.contains('dark')) return;
      var parse = function (c) {
        var m = String(c).match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/);
        return m ? { r: +m[1], g: +m[2], b: +m[3], a: m[4] === undefined ? 1 : +m[4] } : null;
      };
      var lum = function (c) {
        var f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
        return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
      };
      var ratio = function (a, b) {
        var l1 = lum(a), l2 = lum(b);
        return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
      };
      // сначала снимаем свои прошлые правки, иначе второй проход измерит уже исправленный цвет
      document.querySelectorAll('.' + FG_ALL.join(',.')).forEach(function (el) {
        el.classList.remove.apply(el.classList, FG_ALL);
      });
      document.querySelectorAll('body *').forEach(function (el) {
        var hasText = false;
        for (var i = 0; i < el.childNodes.length; i++) {
          var n = el.childNodes[i];
          if (n.nodeType === 3 && n.textContent.trim().length > 1) { hasText = true; break; }
        }
        if (!hasText) return;
        var cs = getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) < 0.4) return;
        var fg = parse(cs.color);
        if (!fg || fg.a < 0.5) return;
        var bg = null;
        for (var q = el; q; q = q.parentElement) {
          var c = parse(getComputedStyle(q).backgroundColor);
          if (c && c.a > 0.5) { bg = c; break; }
        }
        if (!bg) return;
        // порог по WCAG AA: крупному и жирному тексту достаточно 3:1, обычному нужно 4.5:1
        var fs = parseFloat(cs.fontSize) || 16;
        var big = fs >= 24 || (fs >= 18.66 && parseInt(cs.fontWeight, 10) >= 600);
        if (ratio(fg, bg) >= (big ? 3 : 4.5)) return;
        // сиреневый/фиолетовый оттенок сохраняем, просто берём подходящую по светлоте версию
        var purple = fg.b > fg.r && fg.b > fg.g && fg.r > 60;
        var onLight = lum(bg) > 0.4;
        // приглушённый серый не выводим в максимальный контраст — иначе теряется иерархия:
        // вторичный текст должен оставаться вторичным, просто читаемым
        var muted = Math.max(fg.r, fg.g, fg.b) - Math.min(fg.r, fg.g, fg.b) < 24;
        var target = onLight
          ? (purple ? '#4C1D95' : (muted ? '#565656' : '#1A1A1A'))
          : (purple ? '#B794F4' : (muted ? '#A8A8A8' : '#E6E6E6'));
        var tc = parse('rgb(' + [
          parseInt(target.slice(1, 3), 16), parseInt(target.slice(3, 5), 16), parseInt(target.slice(5, 7), 16)
        ].join(', ') + ')');
        if (ratio(fg, bg) < ratio(tc, bg)) el.classList.add(FG_CLASSES[target]);
      });
    } catch (e) {}
  }
  window.psyFixAccentContrast = psyFixAccentContrast;
  window.addEventListener('load', function () { setTimeout(psyFixAccentContrast, 300); });

  // На мобильных фиксированная шапка ниже, чем зашитый в страницы padding-top: 84px —
  // сверху оставалось ~27px пустоты до контента. Подгоняем отступ под реальную высоту шапки.
  // Трогаем только страницы, которые уже используют этот приём (текущий отступ 60–120px),
  // чтобы не сломать вёрстку там, где отступ выставлен осознанно.
  function psySyncHeaderOffset() {
    try {
      var nav = document.querySelector('.nav');
      if (!nav) return;
      if (getComputedStyle(nav).position !== 'fixed') return;
      var cur = parseFloat(getComputedStyle(document.body).paddingTop) || 0;
      if (cur < 60 || cur > 120) return;
      var h = Math.round(nav.getBoundingClientRect().height);
      if (!h) return;
      document.body.style.paddingTop = (h + 2) + 'px';
      document.documentElement.style.setProperty('--psy-nav-h', h + 'px');
      var sb = document.querySelector('.dash-sidebar');
      if (sb && window.matchMedia('(max-width: 768px)').matches) sb.style.paddingTop = (h + 2) + 'px';
    } catch (e) {}
  }
  // Кнопка «☰» боковой менюшки кабинета висела на фиксированных top:92px/left:12px и после
  // подгонки отступа шапки наезжала на заголовок карточки («Личный кабинет»). Ставим её в
  // свободный правый верхний угол контента и считаем top от реальной высоты шапки.
  var sbCss = document.createElement('style');
  sbCss.textContent =
    '@media (max-width: 768px){' +
    '.dash-sidebar-toggle{top:calc(var(--psy-nav-h,74px) + 10px)!important;left:auto!important;right:0.75rem!important}' +
    '}';
  document.head.appendChild(sbCss);
  window.psySyncHeaderOffset = psySyncHeaderOffset;
  window.addEventListener('resize', psySyncHeaderOffset);
  window.addEventListener('orientationchange', psySyncHeaderOffset);

  function wireBurger() {
    psySyncHeaderOffset();
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
    if (user && user.role === 'admin') return;

    var TOKEN_KEY = 'psy_support_token';
    var token = null; try { token = localStorage.getItem(TOKEN_KEY); } catch (e) {}
    var pollTimer = null;
    var bgPollTimer = null;
    var lastMsgCount = 0;
    var panelOpen = false;

    var roleOpts = SUPPORT_ROLES.map(function (r) { return '<option value="' + r + '">' + r + '</option>'; }).join('');
    var isDark = function() { return document.body.classList.contains('dark'); };

    var el = document.createElement('div');
    el.id = 'psySupport';
    el.innerHTML =
      '<button id="psySupBubble" aria-label="Чат поддержки" style="position:fixed;right:20px;bottom:20px;z-index:1700;width:60px;height:60px;border-radius:50%;border:none;cursor:pointer;background:linear-gradient(135deg,#7C3AED,#9F67FA);color:#fff;font-size:1.6rem;box-shadow:0 8px 24px rgba(124,58,237,0.4);display:flex;align-items:center;justify-content:center;transition:transform .2s;">💬<span id="psySupBadge" style="display:none;position:absolute;top:-2px;right:-2px;background:#DC2626;color:#fff;font-size:0.65rem;font-weight:700;min-width:18px;height:18px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 4px;border:2px solid #fff;"></span></button>' +
      '<div id="psySupToast" style="display:none;position:fixed;right:20px;bottom:88px;z-index:1699;background:' + '#fff' + ';border-radius:0.8rem;padding:0.65rem 1rem;box-shadow:0 8px 30px rgba(0,0,0,0.18);max-width:280px;font-size:0.85rem;cursor:pointer;border-left:3px solid #7C3AED;animation:psyToastIn 0.3s ease;"></div>' +
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
          '<div id="psySupAttachPreview" style="display:none;padding:0.3rem 0.6rem;border-top:1px solid #eee;font-size:0.78rem;color:#7C3AED;"></div>' +
          '<div style="display:flex;gap:0.5rem;padding:0.6rem;border-top:1px solid #eee;align-items:center;">' +
            '<button id="psySupAttachBtn" title="Прикрепить файл" style="width:36px;height:36px;border-radius:50%;border:1px solid #E5E7EB;background:none;cursor:pointer;font-size:1rem;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#6B7280;">📎</button>' +
            '<input type="file" id="psySupFileInput" accept="image/*,.pdf,.txt,.doc,.docx" style="display:none;">' +
            '<input id="psySupInput" placeholder="Сообщение..." style="flex:1;padding:0.6rem 0.8rem;border:1.5px solid #E5E7EB;border-radius:1.2rem;font-family:inherit;font-size:0.9rem;outline:none;">' +
            '<button id="psySupSend" style="width:42px;height:42px;border-radius:50%;border:none;background:linear-gradient(135deg,#7C3AED,#9F67FA);color:#fff;cursor:pointer;font-size:1.05rem;flex-shrink:0;">➤</button>' +
          '</div>' +
        '</div>' +
      '</div>';

    var toastStyle = document.createElement('style');
    toastStyle.textContent = '@keyframes psyToastIn{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}';
    document.head.appendChild(toastStyle);
    document.body.appendChild(el);

    var bubble = document.getElementById('psySupBubble');
    var badge = document.getElementById('psySupBadge');
    var panel = document.getElementById('psySupPanel');
    var formView = document.getElementById('psySupForm');
    var chatView = document.getElementById('psySupChat');
    var pendingFile = null;

    if (user) {
      var nm = ((user.first_name || '') + ' ' + (user.last_name || '')).trim();
      var nameI = document.getElementById('psySupName'); if (nameI) nameI.value = nm;
      var emailI = document.getElementById('psySupEmail'); if (emailI) emailI.value = user.email || '';
      var roleS = document.getElementById('psySupRole'); if (roleS) roleS.value = roleFromUser(user);
      ['psySupName', 'psySupEmail', 'psySupRole'].forEach(function (idd) { var e = document.getElementById(idd); if (e) e.style.display = 'none'; });
      var hint = formView.querySelector('p'); if (hint) hint.textContent = 'Здравствуйте, ' + (user.first_name || '') + '! Напишите ваш вопрос — ответим в чат.';
    }

    function playNotifSound() {
      try {
        var ctx = new (window.AudioContext || window.webkitAudioContext)();
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.connect(gain); gain.connect(ctx.destination);
        osc.frequency.setValueAtTime(880, ctx.currentTime);
        osc.frequency.setValueAtTime(1100, ctx.currentTime + 0.1);
        gain.gain.setValueAtTime(0.15, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + 0.4);
      } catch(e) {}
    }

    function showToast(text) {
      var toast = document.getElementById('psySupToast');
      if (!toast) return;
      toast.style.background = isDark() ? '#2A2A2A' : '#fff';
      toast.style.color = isDark() ? '#E6E6E6' : '#1A1A1A';
      toast.innerHTML = '<div style="font-weight:600;font-size:0.78rem;color:#7C3AED;margin-bottom:0.15rem;">Поддержка</div>' + escSup(text.length > 80 ? text.slice(0,80) + '...' : text);
      toast.style.display = 'block';
      toast.onclick = function() { toast.style.display = 'none'; bubble.click(); };
      setTimeout(function() { toast.style.display = 'none'; }, 6000);
    }

    function updateBadge(count) {
      if (count > 0) {
        badge.textContent = count > 9 ? '9+' : count;
        badge.style.display = 'flex';
      } else {
        badge.style.display = 'none';
      }
    }

    function showChat() { formView.style.display = 'none'; chatView.style.display = 'flex'; }

    function renderAttachment(m) {
      if (!m.attachment_url) return '';
      var isImg = (m.attachment_type || '').indexOf('image') === 0;
      if (isImg) {
        return '<div style="margin-top:0.3rem;"><img src="' + escSup(m.attachment_url) + '" style="max-width:200px;max-height:150px;border-radius:0.5rem;cursor:pointer;" onclick="window.open(this.src,\'_blank\')"></div>';
      }
      return '<div style="margin-top:0.3rem;"><a href="' + escSup(m.attachment_url) + '" target="_blank" style="color:inherit;text-decoration:underline;font-size:0.8rem;">📎 ' + escSup(m.attachment_name || 'файл') + '</a></div>';
    }

    function renderMsgs(rows) {
      var box = document.getElementById('psySupMsgs');
      if (!box) return;
      if (!rows.length) { box.innerHTML = '<div style="color:#9CA3AF;text-align:center;font-size:0.85rem;padding:1rem;">Напишите сообщение — мы ответим здесь.</div>'; return; }

      var newAdminCount = rows.filter(function(m) { return m.sender === 'admin'; }).length;
      if (newAdminCount > lastMsgCount && lastMsgCount > 0) {
        var lastAdmin = null;
        for (var i = rows.length - 1; i >= 0; i--) { if (rows[i].sender === 'admin') { lastAdmin = rows[i]; break; } }
        if (lastAdmin && !panelOpen) {
          playNotifSound();
          showToast(lastAdmin.body || 'Новое сообщение от поддержки');
        } else if (lastAdmin && panelOpen) {
          playNotifSound();
        }
      }
      lastMsgCount = newAdminCount;

      box.innerHTML = rows.map(function (m) {
        var mine = m.sender === 'user';
        return '<div style="align-self:' + (mine ? 'flex-end' : 'flex-start') + ';max-width:80%;background:' + (mine ? 'linear-gradient(135deg,#7C3AED,#9F67FA)' : (isDark() ? '#2A2A2A' : '#fff')) + ';color:' + (mine ? '#fff' : (isDark() ? '#E6E6E6' : '#1A1A1A')) + ';border:' + (mine ? 'none' : ('1px solid ' + (isDark() ? '#444' : '#ECECEC'))) + ';border-radius:0.9rem;padding:0.5rem 0.75rem;font-size:0.875rem;line-height:1.4;">' +
          (mine ? '' : '<div style="font-size:0.7rem;color:#7C3AED;font-weight:700;margin-bottom:0.15rem;">Поддержка</div>') +
          escSup(m.body) + renderAttachment(m) + '</div>';
      }).join('');
      box.scrollTop = box.scrollHeight;
    }

    function poll() {
      if (!token) return;
      fetch('/api/support.php?action=poll&token=' + encodeURIComponent(token), { credentials: 'include' })
        .then(function (r) { return r.json(); }).then(function (d) {
          if (d && d.ok) {
            renderMsgs(d.data || []);
            if (panelOpen) updateBadge(0);
          }
        }).catch(function () {});
    }
    function startPolling() { if (pollTimer) clearInterval(pollTimer); poll(); pollTimer = setInterval(poll, 5000); }

    function checkUnread() {
      if (!token || panelOpen) return;
      fetch('/api/support.php?action=client-unread&token=' + encodeURIComponent(token), { credentials: 'include' })
        .then(function(r) { return r.json(); }).then(function(d) {
          if (d && d.ok) updateBadge(d.unread || 0);
        }).catch(function(){});
    }
    if (token) { checkUnread(); bgPollTimer = setInterval(checkUnread, 15000); }

    bubble.addEventListener('click', function () {
      var open = panel.style.display === 'flex';
      panel.style.display = open ? 'none' : 'flex';
      panelOpen = !open;
      document.getElementById('psySupToast').style.display = 'none';
      if (!open) {
        updateBadge(0);
        if (token) { showChat(); startPolling(); }
        else if (user) { showChat(); }
        setTimeout(function () { var i = document.getElementById(token || user ? 'psySupInput' : 'psySupFirstMsg'); if (i) i.focus(); }, 80);
      } else {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
      }
    });
    document.getElementById('psySupClose').addEventListener('click', function () {
      panel.style.display = 'none'; panelOpen = false;
      if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    });

    function doStart(message, name, email, role, attachment) {
      var payload = { token: token || '', message: message, name: name, email: email, role: role };
      if (attachment) { payload.attachment_url = attachment.url; payload.attachment_type = attachment.type; payload.attachment_name = attachment.name; }
      return fetch('/api/support.php?action=start', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include',
        body: JSON.stringify(payload)
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.ok && d.token) {
          token = d.token; try { localStorage.setItem(TOKEN_KEY, token); } catch (e) {}
          showChat(); startPolling();
          if (!bgPollTimer) bgPollTimer = setInterval(checkUnread, 15000);
          return true;
        }
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

    function uploadFile(file, callback) {
      var fd = new FormData(); fd.append('file', file);
      fetch('/api/upload.php', { method: 'POST', credentials: 'include', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (d.error) { alert(d.error); return; }
          callback({ url: d.url, type: file.type, name: file.name });
        })
        .catch(function() { alert('Ошибка загрузки файла'); });
    }

    function showAttachPreview(name) {
      var el = document.getElementById('psySupAttachPreview');
      el.innerHTML = '📎 ' + escSup(name) + ' <span style="cursor:pointer;margin-left:0.3rem;" onclick="this.parentNode.style.display=\'none\'">✕</span>';
      el.style.display = 'block';
    }

    document.getElementById('psySupAttachBtn').addEventListener('click', function() {
      document.getElementById('psySupFileInput').click();
    });
    document.getElementById('psySupFileInput').addEventListener('change', function() {
      var file = this.files[0];
      if (!file) return;
      if (file.size > 5 * 1024 * 1024) { alert('Файл слишком большой (макс. 5 МБ)'); return; }
      uploadFile(file, function(att) { pendingFile = att; showAttachPreview(att.name); });
      this.value = '';
    });

    var supInput = document.getElementById('psySupInput');
    supInput.addEventListener('paste', function(e) {
      var items = (e.clipboardData || e.originalEvent.clipboardData).items;
      for (var i = 0; i < items.length; i++) {
        if (items[i].type.indexOf('image') === 0) {
          e.preventDefault();
          var blob = items[i].getAsFile();
          uploadFile(blob, function(att) { pendingFile = att; showAttachPreview(att.name || 'изображение'); });
          break;
        }
      }
    });

    function sendChat() {
      var inp = document.getElementById('psySupInput');
      var msg = inp.value.trim();
      var att = pendingFile;
      pendingFile = null;
      document.getElementById('psySupAttachPreview').style.display = 'none';
      if (!msg && !att) return;
      inp.value = '';
      if (!token) { doStart(msg, '', '', '', att).catch(function () { inp.value = msg; }); return; }
      var payload = { token: token, message: msg };
      if (att) { payload.attachment_url = att.url; payload.attachment_type = att.type; payload.attachment_name = att.name; }
      fetch('/api/support.php?action=send', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include',
        body: JSON.stringify(payload)
      }).then(function () { poll(); }).catch(function () { inp.value = msg; });
    }
    document.getElementById('psySupSend').addEventListener('click', sendChat);
    supInput.addEventListener('keypress', function (e) { if (e.key === 'Enter') sendChat(); });
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

  function injectFavicon() {
    if (document.querySelector('link[rel*="icon"]')) return;
    var link = document.createElement('link');
    link.rel = 'icon';
    link.type = 'image/svg+xml';
    link.href = '/assets/favicon.svg';
    document.head.appendChild(link);
  }

  // Собственная лёгкая аналитика (задача #39): анонимный просмотр страницы —
  // без сторонних сервисов, без сбора ПД (id устройства в httpOnly-cookie,
  // ставится сервером). Админку не считаем, чтобы не раздувать статистику
  // собственными проверками.
  function trackPageview() {
    try {
      if (location.pathname.indexOf('/dashboard-admin') === 0) return;
      var payload = JSON.stringify({ path: location.pathname, referrer: document.referrer || '' });
      if (navigator.sendBeacon) {
        navigator.sendBeacon('/api/analytics.php?action=track', new Blob([payload], { type: 'application/json' }));
      } else {
        fetch('/api/analytics.php?action=track', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include', body: payload, keepalive: true }).catch(function () {});
      }
    } catch (e) {}
  }

  function onReady() {
    injectFavicon();
    fillPlaceholders();
    fillDynamicPrices();
    wireBurger();
    wireThemeToggle();
    // showDevNotice(); — отключено: оверлей «сайт в разработке» мешает проверке эквайринга
    showConsentBanner();
    buildSupportWidget();
    trackPageview();
    if (window.lucide && lucide.createIcons) lucide.createIcons();
    updateLoginCircle();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', onReady);
  } else {
    onReady();
  }
})();
