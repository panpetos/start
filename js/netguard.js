/* ──────────────────────────────────────────────────────────────────────────
 * ЗАЩИТА ОТ ПЕРЕГРУЗКИ (общая для всех страниц)
 *
 * ЧТО СЛУЧИЛОСЬ. База перегрузилась, запросы стали висеть по 25 секунд. Дальше
 * начиналось самое неприятное: страницы опрашивают сервер по таймеру, и пока
 * прошлый запрос висел, таймер отправлял следующий. У одной вкладки набегали
 * десятки одновременных «висяков», у нескольких вкладок — сотни. Сайт добивал
 * сам себя, и вернуться в норму он уже не мог.
 *
 * ЧТО ДЕЛАЕМ. Оборачиваем fetch к нашему API:
 *   1) у каждого запроса есть срок — 25 секунд, потом он снимается, а не копится;
 *   2) считаем подряд идущие отказы (нет сети, 429, 502/503/504). После второго
 *      включается «отступ»: 3, 6, 12… до минуты. Пока он идёт, фоновые опросы
 *      сами себя пропускают (см. psyServerBusy) — сервер получает передышку и
 *      успевает подняться;
 *   3) первый же удачный ответ всё сбрасывает.
 * Запросы, которые человек сделал руками (отправка сообщения, вход), не
 * блокируются никогда — отступ касается только фоновых опросов.
 * ────────────────────────────────────────────────────────────────────────── */
(function () {
  if (typeof window.fetch !== 'function' || window.__psyFetchGuard) return;
  window.__psyFetchGuard = true;

  var FETCH_TIMEOUT = 25000;
  var fails = 0;
  window.psyBackoffUntil = 0;

  /** Идёт ли сейчас «отступ» — фоновым опросам стоит промолчать. */
  window.psyServerBusy = function () { return Date.now() < window.psyBackoffUntil; };

  function bad() {
    fails++;
    if (fails >= 2) {
      var wait = Math.min(3000 * Math.pow(2, fails - 2), 60000);
      window.psyBackoffUntil = Date.now() + wait;
    }
  }
  function good() { fails = 0; window.psyBackoffUntil = 0; }

  /**
   * Открытая, но забытая вкладка — обычное дело: их держат днями. Пока человек
   * ничего не делает, опрашивать сервер в прежнем темпе незачем: через две минуты
   * бездействия ходим вдвое реже, через десять — вчетверо. О новом сообщении всё
   * равно сообщит push, а любое действие мгновенно возвращает обычную частоту.
   */
  var lastAct = Date.now();
  function touch() { lastAct = Date.now(); }
  ['pointerdown', 'keydown', 'wheel', 'touchstart', 'focus'].forEach(function (e) {
    window.addEventListener(e, touch, { passive: true, capture: true });
  });
  document.addEventListener('visibilitychange', function () { if (!document.hidden) touch(); });

  /** Во сколько раз разредить фоновый опрос прямо сейчас. */
  window.psyIdleFactor = function () {
    var idle = Date.now() - lastAct;
    if (idle > 600000) return 4;
    if (idle > 120000) return 2;
    return 1;
  };

  /**
   * Обёртка для фоновых опросов по таймеру. Пропускает круг, если вкладка свёрнута,
   * если прошлый запрос ещё не вернулся, если сервер попросил передышку или если
   * человек давно ничего не делал.
   * Именно накопление незавершённых опросов и превращало «медленно» в «висит».
   */
  window.psyGuardPoll = function (fn) {
    var busy = false, tick = 0;
    return function () {
      if (document.hidden) return;
      if (busy) return;
      if (window.psyServerBusy()) return;
      if (tick++ % window.psyIdleFactor() !== 0) return;
      busy = true;
      var done = function () { busy = false; };
      var r;
      try { r = fn.apply(this, arguments); } catch (e) { done(); return; }
      if (r && typeof r.then === 'function') r.then(done, done); else done();
      return r;
    };
  };

  var orig = window.fetch.bind(window);
  window.fetch = function (input, init) {
    var url = (typeof input === 'string') ? input : (input && input.url) || '';
    var ours = url.indexOf('/api/') !== -1;
    if (!ours) return orig(input, init);

    init = init || {};
    var ctrl = null, timer = null;
    // свой сигнал у вызывающего — не мешаем ему, только следим за исходом
    if (!init.signal && typeof AbortController === 'function') {
      ctrl = new AbortController();
      init = Object.assign({}, init, { signal: ctrl.signal });
      timer = setTimeout(function () { try { ctrl.abort(); } catch (e) {} }, FETCH_TIMEOUT);
    }
    return orig(input, init).then(function (r) {
      if (timer) clearTimeout(timer);
      if (r.status === 429 || r.status === 502 || r.status === 503 || r.status === 504) bad();
      else good();
      return r;
    }, function (e) {
      if (timer) clearTimeout(timer);
      bad();
      throw e;
    });
  };
})();
