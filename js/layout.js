/**
 * Сквозные шапка и подвал psytalk.pro.
 * Подключение (в <head>): <script src="/js/layout.js"></script>
 * Вставка на странице (синхронно, без мерцания):
 *   шапка:  <script>psyWriteHeader();</script>
 *   подвал: <script>psyWriteFooter();</script>
 * Меняешь этот файл один раз — меняется на всех страницах.
 */
// Если /js/netguard.js по какой-то причине не загрузился, страница всё равно должна
// работать: подставляем безобидные заглушки вместо его функций.
window.psyServerBusy = window.psyServerBusy || function () { return false; };
window.psyGuardPoll = window.psyGuardPoll || function (fn) { return fn; };

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
    { href: '/blog.html', text: 'Блог' }
  ];

  var personSvg =
    '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
    '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>';

  // Лого в начале шапки — та же буква «p», что в иконке приложения и на вкладке.
  // Раньше слева было только слово; фирменный знак читается быстрее и держит строку
  // компактнее (просьба админа: «лого в начало и сделать компактнее»).
  var brandHtml =
    '<a href="/" class="nav-brand" aria-label="psytalk.pro — на главную">' +
      '<img class="nav-logo" src="/assets/favicon.svg" alt="" width="30" height="30">' +
      '<span class="nav-brand-text">' +
        '<span style="color:#1A1A1A;">psy</span><span style="color:#047857;font-style:italic;">talk.pro</span>' +
      '</span>' +
    '</a>';

  // Инлайн-сброс, чтобы любые правила страниц для li/a не ломали шапку
  var liReset = 'list-style:none;margin:0;padding:0;border:0;display:flex;align-items:center;';

  var loginInner =
    '<a id="psyLoginCircle" href="/login.html" aria-label="Личный кабинет" title="Личный кабинет" ' +
      'style="width:40px;height:40px;min-width:40px;border-radius:50%;background:#34C759;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:0;transition:transform .2s;" ' +
      'onmouseover="this.style.transform=\'scale(1.05)\'" onmouseout="this.style.transform=\'scale(1)\'">' +
      personSvg +
    '</a>';

  // Иконка чата со счётчиком непрочитанных. Показываем только тем, кто вошёл, —
  // проверяем это тем же запросом, что считает сообщения (лишнего обращения нет).
  // Три кружка-действия (чат, тема, вход) раньше были тремя отдельными <li> с разными
  // left-отступами — в вертикальном мобильном меню они выстраивались вкривь. Теперь это
  // один общий блок-строка: на ПК тот же ряд справа, на телефоне — аккуратный ряд внизу меню.
  var chatIconInner =
    '<span id="psyChatWrap" style="display:none;align-items:center;">' +
      '<a id="psyChatLink" href="/chat.html" aria-label="Сообщения" title="Сообщения" ' +
      'style="position:relative;width:38px;height:38px;min-width:38px;border-radius:50%;border:1.5px solid #E5E7EB;background:#fff;' +
      'display:inline-flex;align-items:center;justify-content:center;font-size:1.05rem;text-decoration:none;transition:transform .2s;" ' +
      'onmouseover="this.style.transform=\'scale(1.05)\'" onmouseout="this.style.transform=\'scale(1)\'">' +
        '<span class="psy-act-ico">💬</span><span class="psy-act-label">Сообщения</span>' +
        '<span id="psyChatBadge" style="display:none;position:absolute;top:-4px;right:-4px;min-width:18px;height:18px;padding:0 5px;' +
        'border-radius:9px;background:#EF4444;color:#fff;font-size:0.68rem;font-weight:700;line-height:18px;text-align:center;' +
        'box-shadow:0 0 0 2px #fff;"></span>' +
      '</a>' +
    '</span>';

  var themeToggleInner =
    '<span id="psyThemeToggleWrap" style="display:inline-flex;align-items:center;">' +
      '<button id="psyThemeToggle" aria-label="Светлая/тёмная тема" title="Светлая/тёмная тема" ' +
      'style="width:38px;height:38px;min-width:38px;border-radius:50%;border:1.5px solid #E5E7EB;background:#fff;cursor:pointer;font-size:1.05rem;display:inline-flex;align-items:center;justify-content:center;transition:transform .2s;" ' +
      'onmouseover="this.style.transform=\'scale(1.05)\'" onmouseout="this.style.transform=\'scale(1)\'">' +
        '<span class="psy-act-ico" id="psyThemeIco">🌙</span><span class="psy-act-label" id="psyThemeLabel">Тёмная тема</span>' +
      '</button>' +
    '</span>';

  var actionsItem =
    '<li id="psyNavActions" class="psy-nav-actions" style="' + liReset + 'margin-left:0.9rem;gap:0.55rem;flex-shrink:0;">' +
      chatIconInner + themeToggleInner + loginInner +
    '</li>';

  // ── Вход в кабинет строкой, а не одним кружком ──────────────────────────────
  // На телефоне в шторке ЛК был только зелёный кружок с человечком — по нему
  // не понять, что это вход в личный кабинет. Добавляем подписанную строку
  // (аватар + «Личный кабинет» + пояснение). На ПК она скрыта: там остаётся
  // привычный кружок справа в шапке.
  var accountItem =
    // liReset здесь не подходит: в нём display:flex, и инлайн-стиль перебивал
    // display:none — строка вылезала в шапку на ПК. Сбрасываем без display.
    '<li id="psyNavAccount" class="psy-nav-account" style="list-style:none;margin:0;padding:0;border:0;">' +
      '<a id="psyAccountLink" class="psy-account-link" href="/login.html">' +
        '<span id="psyAccountAv" class="psy-account-av">' + personSvg + '</span>' +
        '<span class="psy-account-txt">' +
          '<span class="psy-account-title" id="psyAccountTitle">Личный кабинет</span>' +
          '<span class="psy-account-sub" id="psyAccountSub">Войти или зарегистрироваться</span>' +
        '</span>' +
        '<span class="psy-account-go" aria-hidden="true">›</span>' +
      '</a>' +
    '</li>';

  var menu = accountItem + links.map(function (l) {
    var st = active(l.href) ? 'color:#34C759;font-weight:600;' : '';
    return '<li style="' + liReset + '"><a href="' + l.href + '" class="nav-link" style="' + st + '">' + l.text + '</a></li>';
  }).join('') + actionsItem;

  // ВАЖНО: не используем класс .container внутри шапки — многие страницы
  // переопределяют .container (свой max-width/padding), из-за чего меню «плясало».
  // Здесь фиксированная обёртка с инлайн-стилями — одинаково на всех страницах.
  var headerHtml =
    '<nav class="nav" style="background:#fff;border-bottom:1px solid #F0F0F0;">' +
      '<div style="max-width:1200px;margin:0 auto;padding:0 1.5rem;width:100%;"><div class="nav-container">' +
        brandHtml +
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
        '<a href="/install.html">Приложение</a>' +
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
      // Кружки-действия (чат/тема/вход) в мобильном меню — ровным рядом внизу, с разделителем,
      // а не вкривь по одному. Раньше у каждого был свой left-отступ и они «плясали».
      // Ряд кружков — сразу под пунктами, отделённый воздухом и тонкой чертой.
      // К самому низу шторки прижимать нельзя: внизу висит плашка про cookie, она
      // выше по слоям и просто накрывала бы кружки.
      '#navMenu #psyNavActions{width:100%;margin:1rem 0 0 0!important;padding-top:0.9rem!important;' +
        'border-top:1px solid #EFF7F3!important;border-bottom:none!important;justify-content:flex-start;gap:0.6rem}' +
      'body.dark #navMenu #psyNavActions{border-top-color:#34313D!important}' +
    '}' +
    'body.dark .nav-link:hover{color:#34D399!important}' +
    // Панель шторки чуть светлее страницы, иначе в тёмной теме её границы не видно
    // и меню выглядит «наплывом» без формы. Значение то же, что в css/styles.css;
    // держим и здесь, потому что layout.js подключается позже и перебивает.
    'body.dark .nav-menu{background:#242229!important}' +
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
    'body.dark #psyThemeToggle{background:#2A2A2A!important;border-color:#444!important;color:#E6E6E6!important}' +
    // Тонкая шапка кабинета: меню всегда в строку, бургера нет вовсе.
    // Мобильные правила .nav-menu делают из меню выезжающую панель: рамка, тень и высота
    // на весь экран. В тонкой шапке кабинета это давало светлую полосу сверху справа —
    // гасим всё, что относится к панели, а не к строке кнопок.
    '.nav-menu-slim{display:flex!important;position:static!important;flex-direction:row!important;' +
      'background:none!important;box-shadow:none!important;padding:0!important;gap:0;height:auto!important;' +
      'width:auto!important;transform:none!important;border:none!important;border-left:none!important;' +
      'overflow:visible!important;align-items:center!important;top:auto!important;right:auto!important;}' +
    // Разделитель над кружками нужен только в выезжающем меню сайта. В тонкой шапке
    // кабинета он рисовался светлой чертой над иконками — гасим целиком, вместе с
    // отступами, которые в панели раздвигали ряд по вертикали.
    // Селектор с двумя id — иначе правило мобильного меню (#navMenu #psyNavActions)
    // весит больше и разделитель остаётся: у обоих !important, решает специфичность.
    '#navMenu.nav-menu-slim #psyNavActions,#navMenu.nav-menu-slim li{border:none!important;' +
      'padding:0!important;margin:0!important;width:auto!important}' +
    '#navMenu.nav-menu-slim #psyNavActions{gap:0.55rem!important;flex-direction:row!important;align-items:center!important}' +
    // В тонкой шапке кабинета шторки нет: подписанная строка ЛК и подписи у
    // кружков там не нужны — иначе строка «Сообщения» растянет всю шапку.
    '#navMenu.nav-menu-slim .psy-nav-account{display:none!important}' +
    '#navMenu.nav-menu-slim .psy-act-label{display:none!important}' +
    '#navMenu.nav-menu-slim #psyNavActions #psyLoginCircle{display:inline-flex!important;width:40px!important;height:40px!important;flex:0 0 40px;border-radius:50%;overflow:hidden}' +
    '#navMenu.nav-menu-slim #psyNavActions>*{width:auto!important}' +
    '#navMenu.nav-menu-slim #psyNavActions #psyChatLink,#navMenu.nav-menu-slim #psyNavActions #psyThemeToggle{' +
      'width:38px!important;height:38px!important;min-height:0!important;flex:0 0 38px;border-radius:50%!important;' +
      'border:1.5px solid #E5E7EB!important;background:#fff!important;justify-content:center!important;padding:0!important}' +
    '#navMenu.nav-menu-slim #psyNavActions #psyChatBadge{position:absolute!important;top:-4px;right:-4px;margin:0!important;box-shadow:0 0 0 2px #fff!important}' +
    '.nav-menu-slim li{margin-left:0!important}' +
    'body.dark #psyChatLink{background:#2A2A2A!important;border-color:#444!important}' +
    'body.dark #psyChatBadge{box-shadow:0 0 0 2px #1E1E1E!important}';
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
    if (!btn) return;
    // Раньше здесь стоял btn.textContent — он стирал подпись «Тёмная тема»,
    // которая нужна в мобильной шторке. Меняем только сам значок.
    var ico = document.getElementById('psyThemeIco');
    var lbl = document.getElementById('psyThemeLabel');
    if (ico) ico.textContent = isDarkNow() ? '☀️' : '🌙';
    else btn.textContent = isDarkNow() ? '☀️' : '🌙';
    if (lbl) lbl.textContent = isDarkNow() ? 'Светлая тема' : 'Тёмная тема';
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

  // ── Страницы кабинета: шапка без своего меню ──────────────────────────────────
  // Там есть боковое меню (dash-sidebar), и вторая навигация в шапке давала на
  // телефоне два бургера рядом — человек не понимал, в какое меню жать. Разделы
  // сайта переехали в боковое меню (группа «Сайт»), а здесь шапка остаётся
  // тонкой полосой: логотип, чат со счётчиком и вход в кабинет.
  var DASH_PAGES = [
    '/client-dashboard.html', '/psychologist-dashboard.html', '/dashboard-admin.html',
    '/psychologist-notes.html', '/psychologist-blog.html', '/schedule.html',
    '/supervision.html', '/notify-settings.html', '/edit-profile.html'
  ];
  function isDashPage() {
    var p = path.replace(/\/index\.html$/, '/');
    return DASH_PAGES.indexOf(p) !== -1;
  }

  var slimMenu = actionsItem;
  var slimHeaderHtml =
    '<nav class="nav" style="background:#fff;border-bottom:1px solid #F0F0F0;">' +
      '<div style="max-width:1200px;margin:0 auto;padding:0 1.5rem;width:100%;"><div class="nav-container">' +
        brandHtml +
        '<ul class="nav-menu nav-menu-slim" id="navMenu">' + slimMenu + '</ul>' +
      '</div></div>' +
    '</nav>';

  // Синхронная вставка во время разбора HTML — без мерцания.
  // Приложение (манифест, сервис-воркер, установка) — в js/app.js. Он сам ставит
  // нужные теги, поэтому здесь достаточно его подключить.
  (function loadAppJs() {
    if (document.querySelector('script[src="/js/app.js"]')) return;
    var sc = document.createElement('script');
    sc.src = '/js/app.js';
    sc.defer = true;
    (document.head || document.documentElement).appendChild(sc);
  })();

  window.psyWriteHeader = function () {
    applyStoredTheme();
    document.write(isDashPage() ? slimHeaderHtml : headerHtml);
  };
  window.psyWriteFooter = function () { document.write(footerHtml); };

  // ── Страховка читаемости текста в тёмной теме ──────────────────────────────────
  // В тёмной теме часть правил задаёт светлый цвет текста в паре с тёмным фоном
  // (например .badge-secondary: color #6EE7B7 + background #0B3B2E). Но если фон этого
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
    'body.dark .psy-fg-brand-dark.psy-fg-brand-dark{color:#064E3B!important}' +
    'body.dark .psy-fg-brand-light.psy-fg-brand-light{color:#6EE7B7!important}' +
    'body.dark .psy-fg-mgrey.psy-fg-mgrey{color:#565656!important}' +
    'body.dark .psy-fg-lgrey.psy-fg-lgrey{color:#A8A8A8!important}';
  document.head.appendChild(fixCss);
  var FG_CLASSES = {
    '#1A1A1A': 'psy-fg-dark', '#E6E6E6': 'psy-fg-light',
    '#064E3B': 'psy-fg-brand-dark', '#6EE7B7': 'psy-fg-brand-light',
    '#565656': 'psy-fg-mgrey', '#A8A8A8': 'psy-fg-lgrey'
  };
  var FG_ALL = ['psy-fg-dark', 'psy-fg-light', 'psy-fg-brand-dark', 'psy-fg-brand-light', 'psy-fg-mgrey', 'psy-fg-lgrey'];

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
        // фирменный оттенок сохраняем, просто берём подходящую по светлоте версию.
        // Бренд стал зелёным, поэтому доминирует зелёный канал (раньше искали синий).
        var brand = fg.g > fg.r && fg.g > fg.b && fg.g > 60;
        var onLight = lum(bg) > 0.4;
        // приглушённый серый не выводим в максимальный контраст — иначе теряется иерархия:
        // вторичный текст должен оставаться вторичным, просто читаемым
        var muted = Math.max(fg.r, fg.g, fg.b) - Math.min(fg.r, fg.g, fg.b) < 24;
        var target = onLight
          ? (brand ? '#064E3B' : (muted ? '#565656' : '#1A1A1A'))
          : (brand ? '#6EE7B7' : (muted ? '#A8A8A8' : '#E6E6E6'));
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
      // Шторку кабинета отступом под шапку больше не сдвигаем: на телефоне она накрывает
      // шапку сайта целиком (z-index 1000 против 999), поэтому сверху просто пустовало
      // ~75px, из-за которых нижние пункты меню («Выйти») уезжали за видимую часть.
      var sb = document.querySelector('.dash-sidebar');
      if (sb && sb.style.paddingTop) sb.style.paddingTop = '';
    } catch (e) {}
  }
  // Кнопка «☰» боковой менюшки кабинета висела на фиксированных top:92px/left:12px и после
  // подгонки отступа шапки наезжала на заголовок карточки («Личный кабинет»). Ставим её в
  // свободный правый верхний угол контента и считаем top от реальной высоты шапки.
  // Просмотр фото во весь экран должен работать на любой странице, а не только там,
  // где скрипт подключён руками: иначе картинки в виджете поддержки открывались новой вкладкой.
  try {
    if (!document.querySelector('script[src*="/js/lightbox.js"]')) {
      var lb = document.createElement('script');
      lb.src = '/js/lightbox.js?v=2';
      lb.async = true;
      document.head.appendChild(lb);
    }
  } catch (e) {}

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

    // Затемнение под шторкой. Отдельным элементом, а не тенью меню: по нему
    // удобно закрывать меню нажатием, и видно, что страница под ним неактивна.
    var scrim = document.getElementById('psyNavScrim');
    if (!scrim) {
      scrim = document.createElement('div');
      scrim.id = 'psyNavScrim';
      scrim.className = 'nav-scrim';
      document.body.appendChild(scrim);
    }

    function setOpen(open) {
      navMenu.classList.toggle('active', open);
      burger.classList.toggle('active', open);
      scrim.classList.toggle('active', open);
      document.body.style.overflow = open ? 'hidden' : '';
    }

    burger.addEventListener('click', function (e) {
      e.stopPropagation();
      setOpen(!navMenu.classList.contains('active'));
    });
    scrim.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('click', function (e) {
      if (navMenu.classList.contains('active') && !navMenu.contains(e.target) && !burger.contains(e.target)) {
        setOpen(false);
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && navMenu.classList.contains('active')) setOpen(false);
    });
    navMenu.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () { setOpen(false); });
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
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(18,42,34,0.55);z-index:2000;display:flex;align-items:center;justify-content:center;padding:1rem;';
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

  // Строка ЛК в мобильной шторке: имя вместо «Войти», фото вместо человечка.
  function applyAccountRow(user) {
    var link = document.getElementById('psyAccountLink');
    if (!link) return;
    var av = document.getElementById('psyAccountAv');
    var title = document.getElementById('psyAccountTitle');
    var sub = document.getElementById('psyAccountSub');
    if (!user) {
      link.href = '/login.html';
      if (title) title.textContent = 'Личный кабинет';
      if (sub) sub.textContent = 'Войти или зарегистрироваться';
      return;
    }
    link.href = dashUrlFor(user);
    var name = [user.first_name, user.last_name].filter(Boolean).join(' ').trim();
    if (title) title.textContent = name || 'Личный кабинет';
    if (sub) sub.textContent = name ? 'Личный кабинет' : 'Перейти в кабинет';
    var avatar = user.avatar || user.avatar_url || user.photo || '';
    if (av) {
      if (avatar) {
        av.innerHTML = '<img src="' + avatar + '" alt="" onerror="this.remove()">';
      } else {
        var initial = (user.first_name || user.email || '?').charAt(0).toUpperCase();
        av.innerHTML = '<span class="psy-account-ini">' + initial + '</span>';
      }
    }
  }

  function applyLoggedInCircle(user) {
    applyAccountRow(user);
    var circle = document.getElementById('psyLoginCircle');
    if (!circle || !user) return;
    circle.href = dashUrlFor(user);
    circle.title = (user.first_name || 'Кабинет');
    circle.style.background = 'linear-gradient(135deg,#047857,#059669)';
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

  // ── Счётчик непрочитанных в шапке ─────────────────────────────────────────────
  // Раньше о новом сообщении можно было узнать, только зайдя в чат. Теперь на любой
  // странице сайта видно иконку с числом. Считаем личные переписки и группы; в самом
  // чате иконку не показываем — там счётчики и так на виду.
  function paintChatBadge(total) {
    var wrap = document.getElementById('psyChatWrap');
    var badge = document.getElementById('psyChatBadge');
    if (!wrap || !badge) return;
    wrap.style.display = 'flex';
    if (total > 0) {
      badge.textContent = total > 99 ? '99+' : String(total);
      badge.style.display = 'block';
      var a = document.getElementById('psyChatLink');
      if (a) a.title = total + (total === 1 ? ' новое сообщение' : ' новых сообщений');
    } else {
      badge.style.display = 'none';
    }
  }

  async function refreshChatBadge() {
    if (location.pathname.indexOf('/chat.html') === 0) return;   // в самом чате незачем
    var total = 0, seen = false;
    try {
      var r = await fetch('/api/messages.php?action=conversations', { credentials: 'include' });
      var d = await r.json();
      if (Array.isArray(d)) {
        seen = true;
        d.forEach(function (c) { total += parseInt(c.unread_count, 10) || 0; });
      }
    } catch (e) {}
    if (!seen) return;                    // не вошёл или сервер не ответил — иконку не показываем
    try {
      var g = await (await fetch('/api/group_chat.php?action=list', { credentials: 'include' })).json();
      ((g && g.data) || []).forEach(function (x) { total += parseInt(x.unread_count, 10) || 0; });
    } catch (e) {}
    paintChatBadge(total);
  }

  function wireChatBadge() {
    refreshChatBadge();
    setInterval(psyGuardPoll(refreshChatBadge), 30000);
    // Вернулись на вкладку — обновляем сразу, а не через полминуты.
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) refreshChatBadge();
    });
  }

  // Согласие с политикой конфиденциальности (один раз на устройство)
  function showConsentBanner() {
    try { if (localStorage.getItem('psy_privacy_consent') === '1') return; } catch (e) {}
    var bar = document.createElement('div');
    bar.id = 'psyConsent';
    bar.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:1900;background:#122A22;color:#fff;padding:0.9rem 1.25rem;display:flex;align-items:center;justify-content:center;gap:1rem;flex-wrap:wrap;box-shadow:0 -4px 20px rgba(0,0,0,0.25);font-size:0.875rem;';
    bar.innerHTML =
      '<span style="max-width:760px;line-height:1.5;">🍪 Мы используем файлы cookie и обрабатываем данные для работы сайта. Продолжая пользоваться сайтом, вы соглашаетесь с ' +
      '<a href="/privacy.html" style="color:#059669;font-weight:600;">политикой конфиденциальности</a> и ' +
      '<a href="/consent.html" style="color:#059669;font-weight:600;">обработкой ПД</a>.</span>' +
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

  // Виджет поддержки открыт и тем, кто не вошёл. Потолок здесь ниже, чем в чате,
  // намеренно: гигабайтные файлы от анонимных посетителей — это забитый диск
  // хостинга и ничья ответственность.
  var SUP_MAX_MB = 50;

  /**
   * Убрать пузырь поддержки, если человек уже вошёл: у него поддержка есть прямо
   * в чатах, отдельным диалогом, и второй чатик на том же экране только путает.
   */
  function dropSupportWidgetIfLoggedIn() {
    try {
      if (!window.Auth || !window.Auth.getCurrentUser) return;
      Promise.resolve(window.Auth.getCurrentUser()).then(function (u) {
        var el = document.getElementById('psySupport');
        if (u && el) el.remove();
      }).catch(function () {});
    } catch (e) {}
  }

  function buildSupportWidget() {
    if (document.getElementById('psySupport')) return;
    var user = getCachedUserSafe();
    // Виджет — только для тех, кто НЕ вошёл. Вошедшим поддержка доступна в чатах.
    // Кэша может ещё не быть, поэтому ниже, после сборки, проверяем и у сервера.
    if (user) return;
    dropSupportWidgetIfLoggedIn();

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
      '<button id="psySupBubble" aria-label="Чат поддержки" style="position:fixed;right:20px;bottom:20px;z-index:1700;width:60px;height:60px;border-radius:50%;border:none;cursor:pointer;background:linear-gradient(135deg,#047857,#059669);color:#fff;font-size:1.6rem;box-shadow:0 8px 24px rgba(4,120,87,0.4);display:flex;align-items:center;justify-content:center;transition:transform .2s;">💬<span id="psySupBadge" style="display:none;position:absolute;top:-2px;right:-2px;background:#DC2626;color:#fff;font-size:0.65rem;font-weight:700;min-width:18px;height:18px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 4px;border:2px solid #fff;"></span></button>' +
      '<div id="psySupToast" style="display:none;position:fixed;right:20px;bottom:88px;z-index:1699;background:' + '#fff' + ';border-radius:0.8rem;padding:0.65rem 1rem;box-shadow:0 8px 30px rgba(0,0,0,0.18);max-width:280px;font-size:0.85rem;cursor:pointer;border-left:3px solid #047857;animation:psyToastIn 0.3s ease;"></div>' +
      '<div id="psySupPanel" style="display:none;position:fixed;right:20px;bottom:90px;z-index:1701;width:350px;max-width:calc(100vw - 32px);height:480px;max-height:calc(100vh - 120px);background:#fff;border-radius:1.1rem;box-shadow:0 20px 60px rgba(0,0,0,0.28);overflow:hidden;flex-direction:column;">' +
        '<div style="background:linear-gradient(135deg,#047857,#059669);color:#fff;padding:0.9rem 1.1rem;display:flex;align-items:center;justify-content:space-between;">' +
          '<div><div style="font-weight:700;">Чат поддержки</div><div style="font-size:0.75rem;opacity:0.9;">Обычно отвечаем в течение дня</div></div>' +
          '<button id="psySupClose" aria-label="Закрыть" style="background:rgba(255,255,255,0.2);border:none;color:#fff;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:1.05rem;">✕</button>' +
        '</div>' +
        '<div id="psySupForm" style="padding:1rem 1.1rem;overflow-y:auto;flex:1;">' +
          '<p style="color:#6B7280;font-size:0.85rem;line-height:1.5;margin-bottom:0.8rem;">Здравствуйте! Представьтесь, чтобы мы могли ответить.</p>' +
          '<input id="psySupName" placeholder="Ваше имя" style="width:100%;padding:0.6rem 0.75rem;border:1.5px solid #E5E7EB;border-radius:0.6rem;font-family:inherit;font-size:0.9rem;outline:none;margin-bottom:0.5rem;">' +
          '<input id="psySupEmail" type="email" placeholder="Email для ответа" style="width:100%;padding:0.6rem 0.75rem;border:1.5px solid #E5E7EB;border-radius:0.6rem;font-family:inherit;font-size:0.9rem;outline:none;margin-bottom:0.5rem;">' +
          '<select id="psySupRole" style="width:100%;padding:0.6rem 0.75rem;border:1.5px solid #E5E7EB;border-radius:0.6rem;font-family:inherit;font-size:0.9rem;outline:none;margin-bottom:0.5rem;background:#fff;">' + roleOpts + '</select>' +
          '<textarea id="psySupFirstMsg" placeholder="Ваш вопрос..." style="width:100%;height:72px;padding:0.6rem 0.75rem;border:1.5px solid #E5E7EB;border-radius:0.6rem;font-family:inherit;font-size:0.9rem;outline:none;resize:vertical;margin-bottom:0.5rem;"></textarea>' +
          '<div id="psySupFormAttachPreview" style="display:none;padding:0 0 0.5rem;font-size:0.78rem;color:#047857;word-break:break-word;"></div>' +
          '<div id="psySupFormErr" style="display:none;color:#DC2626;font-size:0.8rem;margin-bottom:0.5rem;"></div>' +
          '<div style="display:flex;gap:0.5rem;align-items:center;">' +
            '<button type="button" id="psySupFormAttachBtn" title="Прикрепить файл" style="width:38px;height:38px;flex-shrink:0;border-radius:50%;border:1.5px solid #E5E7EB;background:none;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;color:#6B7280;">📎</button>' +
            '<input type="file" id="psySupFormFileInput" accept="image/*,.pdf,.txt,.doc,.docx" style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;padding:0;margin:-1px;">' +
            '<button id="psySupStart" style="flex:1;padding:0.7rem;background:linear-gradient(135deg,#047857,#059669);color:#fff;border:none;border-radius:0.6rem;font-weight:700;cursor:pointer;font-family:inherit;">Начать чат</button>' +
          '</div>' +
        '</div>' +
        '<div id="psySupChat" style="display:none;flex:1;flex-direction:column;min-height:0;">' +
          '<div id="psySupMsgs" style="flex:1;overflow-y:auto;padding:0.85rem;display:flex;flex-direction:column;gap:0.5rem;background:#F7F8FA;"></div>' +
          '<div id="psySupAttachPreview" style="display:none;padding:0.3rem 0.6rem;border-top:1px solid #eee;font-size:0.78rem;color:#047857;word-break:break-word;"></div>' +
          '<div style="display:flex;gap:0.5rem;padding:0.6rem;border-top:1px solid #eee;align-items:center;">' +
            '<button id="psySupAttachBtn" title="Прикрепить файл" style="width:36px;height:36px;border-radius:50%;border:1px solid #E5E7EB;background:none;cursor:pointer;font-size:1rem;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#6B7280;">📎</button>' +
            '<input type="file" id="psySupFileInput" accept="image/*,.pdf,.txt,.doc,.docx" style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;padding:0;margin:-1px;">' +
            '<button id="psySupVoiceBtn" title="Голосовое сообщение" style="width:36px;height:36px;border-radius:50%;border:1px solid #E5E7EB;background:none;cursor:pointer;font-size:1rem;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#6B7280;">🎤</button>' +
            '<input id="psySupInput" placeholder="Сообщение..." style="flex:1;min-width:0;padding:0.6rem 0.8rem;border:1.5px solid #E5E7EB;border-radius:1.2rem;font-family:inherit;font-size:0.9rem;outline:none;">' +
            '<button id="psySupSend" style="width:42px;height:42px;border-radius:50%;border:none;background:linear-gradient(135deg,#047857,#059669);color:#fff;cursor:pointer;font-size:1.05rem;flex-shrink:0;">➤</button>' +
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
      toast.innerHTML = '<div style="font-weight:600;font-size:0.78rem;color:#047857;margin-bottom:0.15rem;">Поддержка</div>' + escSup(text.length > 80 ? text.slice(0,80) + '...' : text);
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
      var isAudio = (m.attachment_type || '').indexOf('audio') === 0;
      if (isImg) {
        // просмотр во весь экран делает lightbox.js; window.open открывал вторую вкладку поверх него
        return '<div style="margin-top:0.3rem;"><img src="' + escSup(m.attachment_url) + '" style="max-width:200px;max-height:150px;border-radius:0.5rem;cursor:pointer;" data-zoom></div>';
      }
      if (isAudio) {
        return '<div style="margin-top:0.3rem;"><audio controls src="' + escSup(m.attachment_url) + '" style="max-width:220px;height:34px;"></audio></div>';
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
        return '<div style="align-self:' + (mine ? 'flex-end' : 'flex-start') + ';max-width:80%;background:' + (mine ? 'linear-gradient(135deg,#047857,#059669)' : (isDark() ? '#2A2A2A' : '#fff')) + ';color:' + (mine ? '#fff' : (isDark() ? '#E6E6E6' : '#1A1A1A')) + ';border:' + (mine ? 'none' : ('1px solid ' + (isDark() ? '#444' : '#ECECEC'))) + ';border-radius:0.9rem;padding:0.5rem 0.75rem;font-size:0.875rem;line-height:1.4;">' +
          (mine ? '' : '<div style="font-size:0.7rem;color:#047857;font-weight:700;margin-bottom:0.15rem;">Поддержка</div>') +
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
    function startPolling() { if (pollTimer) clearInterval(pollTimer); poll(); pollTimer = setInterval(psyGuardPoll(poll), 5000); }

    function checkUnread() {
      if (!token || panelOpen) return;
      fetch('/api/support.php?action=client-unread&token=' + encodeURIComponent(token), { credentials: 'include' })
        .then(function(r) { return r.json(); }).then(function(d) {
          if (d && d.ok) updateBadge(d.unread || 0);
        }).catch(function(){});
    }
    if (token) { checkUnread(); bgPollTimer = setInterval(psyGuardPoll(checkUnread), 20000); }

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
          if (!bgPollTimer) bgPollTimer = setInterval(psyGuardPoll(checkUnread), 20000);
          return true;
        }
        throw new Error((d && d.error) || 'Ошибка');
      });
    }

    // Вложение к самому первому сообщению (форма) хранится в той же переменной, что и в чате:
    // формы и чат никогда не видны одновременно — сначала форма, потом (после старта) чат.
    document.getElementById('psySupStart').addEventListener('click', function () {
      var err = document.getElementById('psySupFormErr');
      var msg = document.getElementById('psySupFirstMsg').value.trim();
      var name = user ? '' : document.getElementById('psySupName').value.trim();
      var email = user ? '' : document.getElementById('psySupEmail').value.trim();
      var role = user ? '' : document.getElementById('psySupRole').value;
      if (!user && !name) { err.textContent = 'Укажите имя'; err.style.display = 'block'; return; }
      if (!msg && !pendingFile) { err.textContent = 'Введите вопрос или прикрепите файл'; err.style.display = 'block'; return; }
      err.style.display = 'none';
      var att = pendingFile; pendingFile = null;
      var preview = document.getElementById('psySupFormAttachPreview'); if (preview) preview.style.display = 'none';
      doStart(msg, name, email, role, att).catch(function () {
        pendingFile = att;
        err.textContent = 'Не удалось отправить. Попробуйте ещё раз.'; err.style.display = 'block';
      });
    });

    function uploadFile(file, callback) {
      var fd = new FormData(); fd.append('file', file);
      // /api/upload.php требует обычную сессию Auth — у анонимных гостей виджета её нет,
      // поэтому файлы/голосовые от них отправляем через свой загрузчик в support.php.
      fetch('/api/support.php?action=upload', { method: 'POST', credentials: 'include', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (d.error) { alert(d.error); return; }
          callback({ url: d.url, type: file.type, name: file.name });
        })
        .catch(function() { alert('Ошибка загрузки файла'); });
    }

    function showAttachPreview(name, targetId) {
      var el = document.getElementById(targetId || 'psySupAttachPreview');
      if (!el) return;
      el.innerHTML = '📎 ' + escSup(name) + ' <span class="psySupAttX" style="cursor:pointer;margin-left:0.3rem;">✕</span>';
      el.style.display = 'block';
      // Крестик раньше только прятал строку, а сам файл оставался в pendingFile и всё равно
      // уходил со следующим сообщением — отменить прикрепление было невозможно.
      var x = el.querySelector('.psySupAttX');
      if (x) x.onclick = function () {
        pendingFile = null;
        el.style.display = 'none';
        el.innerHTML = '';
      };
    }

    document.getElementById('psySupAttachBtn').addEventListener('click', function() {
      document.getElementById('psySupFileInput').click();
    });
    document.getElementById('psySupFileInput').addEventListener('change', function() {
      var file = this.files[0];
      if (!file) return;
      if (file.size > SUP_MAX_MB * 1024 * 1024) { alert('Файл больше ' + SUP_MAX_MB + ' МБ — выберите поменьше.'); return; }
      uploadFile(file, function(att) { pendingFile = att; showAttachPreview(att.name, 'psySupAttachPreview'); });
      this.value = '';
    });

    var formAttachBtn = document.getElementById('psySupFormAttachBtn');
    var formFileInput = document.getElementById('psySupFormFileInput');
    if (formAttachBtn && formFileInput) {
      formAttachBtn.addEventListener('click', function() { formFileInput.click(); });
      formFileInput.addEventListener('change', function() {
        var file = this.files[0];
        if (!file) return;
        if (file.size > SUP_MAX_MB * 1024 * 1024) { alert('Файл больше ' + SUP_MAX_MB + ' МБ — выберите поменьше.'); return; }
        uploadFile(file, function(att) { pendingFile = att; showAttachPreview(att.name, 'psySupFormAttachPreview'); });
        this.value = '';
      });
    }

    // Голосовое сообщение: тап — начать запись, повторный тап — остановить и отправить сразу,
    // как в основном чате. Доступно только когда переписка уже открыта (после первого сообщения).
    var voiceBtn = document.getElementById('psySupVoiceBtn');
    if (voiceBtn) {
      var voiceRecorder = null, voiceChunks = [], voiceStream = null;
      voiceBtn.addEventListener('click', function () {
        if (voiceRecorder && voiceRecorder.state === 'recording') { voiceRecorder.stop(); return; }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
          alert('Голосовые сообщения не поддерживаются этим браузером'); return;
        }
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
          voiceStream = stream;
          voiceChunks = [];
          try { voiceRecorder = new MediaRecorder(stream); } catch (e) { alert('Не удалось начать запись'); return; }
          voiceRecorder.ondataavailable = function (e) { if (e.data && e.data.size) voiceChunks.push(e.data); };
          voiceRecorder.onstop = function () {
            voiceStream.getTracks().forEach(function (t) { t.stop(); });
            voiceBtn.style.background = 'none'; voiceBtn.style.color = '#6B7280';
            var blob = new Blob(voiceChunks, { type: 'audio/webm' });
            if (!blob.size) return;
            var file = new File([blob], 'voice-message.webm', { type: 'audio/webm' });
            uploadFile(file, function (att) {
              if (!token) { doStart('', '', '', '', att).catch(function () { alert('Не удалось отправить голосовое'); }); return; }
              fetch('/api/support.php?action=send', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include',
                body: JSON.stringify({ token: token, message: '', attachment_url: att.url, attachment_type: att.type, attachment_name: att.name })
              }).then(function () { poll(); }).catch(function () { alert('Не удалось отправить голосовое'); });
            });
          };
          voiceRecorder.start();
          voiceBtn.style.background = '#DC2626'; voiceBtn.style.color = '#fff';
        }).catch(function () { alert('Нет доступа к микрофону'); });
      });
    }

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
    wireChatBadge();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', onReady);
  } else {
    onReady();
  }
})();
