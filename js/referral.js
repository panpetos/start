/**
 * referral.js — приглашения по личной ссылке.
 *
 * Ссылка выглядит коротко: psytalk.pro/i/КОД. Отдельной страницы по такому
 * адресу на сервере нет — он отдаёт главную, поэтому код ловим здесь и уводим
 * человека на нормальную страницу-приглашение.
 *
 * Почему код запоминается в браузере. Регистрация живёт в серверном auth.php,
 * которого нет в репозитории и трогать его нельзя. Поэтому: перешли по
 * ссылке → запомнили код → после входа отправили на сервер. Повторная отправка
 * безвредна: то же приглашение второй раз не засчитывается.
 */
(function (global) {
    if (global.psyReferral) return;

    var KEY = 'psy_ref_code';
    var TTL_DAYS = 30;

    function clean(c) {
        return String(c || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 16);
    }

    /** Код из адреса: путь /i/КОД или параметры ?r= / ?ref= / ?invite=. */
    function codeFromUrl() {
        try {
            var m = /^\/i\/([A-Za-z0-9]{4,16})\/?$/.exec(location.pathname);
            if (m) return clean(m[1]);
            var q = new URLSearchParams(location.search);
            return clean(q.get('r') || q.get('ref') || q.get('invite') || '');
        } catch (e) { return ''; }
    }

    function saveCode(code) {
        try {
            localStorage.setItem(KEY, JSON.stringify({ code: code, at: Date.now() }));
        } catch (e) {}
    }

    /** Сохранённый код, если он не протух: месяц — разумный срок «подумать». */
    function storedCode() {
        try {
            var raw = localStorage.getItem(KEY);
            if (!raw) return '';
            var d = JSON.parse(raw);
            if (!d || !d.code) return '';
            if (Date.now() - (d.at || 0) > TTL_DAYS * 864e5) { localStorage.removeItem(KEY); return ''; }
            return clean(d.code);
        } catch (e) { return ''; }
    }

    function forgetCode() { try { localStorage.removeItem(KEY); } catch (e) {} }

    function post(action, body) {
        return fetch('/api/referrals.php?action=' + action, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            credentials: 'include', body: JSON.stringify(body || {})
        }).then(function (r) { return r.json(); }).catch(function () { return null; });
    }

    /**
     * Засчитать приглашение вошедшему.
     * Зовём после входа со всех страниц: человек мог зарегистрироваться и через
     * час, и с другой страницы — код всё это время лежит в браузере.
     */
    function claim() {
        var code = storedCode();
        if (!code) return Promise.resolve(null);
        return post('claim', { code: code }).then(function (d) {
            // Ответ пришёл — код своё отработал в любом случае: засчитан,
            // не найден или уже был. Держать его дальше незачем.
            if (d && d.ok) forgetCode();
            return d;
        });
    }

    function init() {
        var code = codeFromUrl();
        if (code) {
            saveCode(code);
            post('touch', { code: code });
            // Короткий адрес отдаёт главную страницу. Уводим на приглашение —
            // там объяснено, куда человек попал и что делать дальше.
            if (/^\/i\//.test(location.pathname)) {
                location.replace('/invite.html?r=' + encodeURIComponent(code));
                return;
            }
        }
    }

    global.psyReferral = {
        claim: claim,
        stored: storedCode,
        forget: forgetCode,
        fromUrl: codeFromUrl
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})(window);
