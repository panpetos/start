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

  var loginCircle =
    '<li style="margin-left:2rem;">' +
      '<a id="psyLoginCircle" href="/login.html" aria-label="Личный кабинет" title="Личный кабинет" ' +
      'style="width:40px;height:40px;border-radius:50%;background:#34C759;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;transition:transform .2s;" ' +
      'onmouseover="this.style.transform=\'scale(1.05)\'" onmouseout="this.style.transform=\'scale(1)\'">' +
      personSvg +
      '</a>' +
    '</li>';

  var menu = links.map(function (l) {
    var st = active(l.href) ? ' style="color:#34C759;font-weight:600;"' : '';
    return '<li><a href="' + l.href + '" class="nav-link"' + st + '>' + l.text + '</a></li>';
  }).join('') + loginCircle;

  var headerHtml =
    '<nav class="nav" style="background:#fff;border-bottom:1px solid #F0F0F0;">' +
      '<div class="container"><div class="nav-container">' +
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
      '<p style="margin-top:2px;font-size:0.8rem;color:#aaa;">Ленинградская обл., Всеволожский р-н, д. Новосаратовка &nbsp;|&nbsp; <a href="tel:+79119404073" style="color:#aaa;">+7 (911) 940-40-73</a> &nbsp;|&nbsp; <a href="mailto:support@psytalk.pro" style="color:#aaa;">support@psytalk.pro</a></p>' +
      '<p style="margin-top:8px;">' +
        '<a href="/offer.html">Публичная оферта</a>' +
        '<a href="/privacy.html">Конфиденциальность</a>' +
        '<a href="/refund.html">Возврат средств</a>' +
        '<a href="/payment-info.html">Оплата и безопасность</a>' +
        '<a href="/consent.html">Согласие на обработку ПД</a>' +
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

  function updateLoginCircle() {
    if (!window.Auth || !window.Auth.getCurrentUser) return;
    try {
      Promise.resolve(window.Auth.getCurrentUser()).then(function(user) {
        if (!user) return;
        var circle = document.getElementById('psyLoginCircle');
        if (!circle) return;
        var url = '/client-dashboard.html';
        if (user.role === 'psychologist') url = '/psychologist-dashboard.html';
        else if (user.role === 'admin') url = '/dashboard-admin.html';
        circle.href = url;
        circle.title = (user.first_name || 'Кабинет');
        circle.style.background = 'linear-gradient(135deg,#7C3AED,#9F67FA)';
      }).catch(function() {});
    } catch(e) {}
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

  function onReady() {
    fillPlaceholders();
    wireBurger();
    // showDevNotice(); — отключено: оверлей «сайт в разработке» мешает проверке эквайринга
    showConsentBanner();
    if (window.lucide && lucide.createIcons) lucide.createIcons();
    setTimeout(updateLoginCircle, 500);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', onReady);
  } else {
    onReady();
  }
})();
