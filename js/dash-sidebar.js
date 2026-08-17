/**
 * dash-sidebar.js — общая боковая менюшка кабинета (Notion-style), общая для страниц,
 * которые раньше её не имели (schedule.html, edit-profile.html и т.д.), чтобы не плодить
 * очередную копипасту markup/списка пунктов меню (задача — «сделай сайдбар везде по логике»).
 * Использует те же классы/CSS-переменные (--d-*, .dash-sidebar, .dash-layout), что и
 * psychologist-dashboard.html/client-dashboard.html/psychologist-notes.html — CSS для этих
 * классов уже должен быть подключён на странице (скопирован в её <style>, как и там).
 *
 * Использование:
 *   <div id="dashSidebarMount"></div><div class="container">...</div>  — обернуть в .dash-layout
 *   await mountDashSidebar('psychologist', 'Имя Фамилия');
 */
(function (global) {
    const NAV = {
        psychologist: [
            { group: 'Основное', items: [
                { href: '/psychologist-dashboard.html', icon: '🏠', label: 'Главная' },
                { href: '/schedule.html', icon: '📅', label: 'Расписание' },
                { href: '/supervision.html', icon: '🎓', label: 'Супервизия' },
                { href: '/chat.html', icon: '💬', label: 'Чаты' },
                { href: '/feed.html', icon: '📰', label: 'Лента' },
            ]},
            { group: 'Инструменты', items: [
                { href: '/psychologist-notes.html', icon: '📝', label: 'Записки' },
                { href: '/psychologist-blog.html', icon: '✍️', label: 'Мой блог' },
            ]},
            { group: 'Профиль', items: [
                { href: '/notify-settings.html', icon: '🔔', label: 'Уведомления' },
                { href: '/edit-profile.html', icon: '⚙️', label: 'Настройки' },
            ]},
        ],
        client: [
            { group: 'Основное', items: [
                { href: '/client-dashboard.html', icon: '🏠', label: 'Главная' },
                { href: '/search.html', icon: '🔍', label: 'Найти психолога' },
                { href: '/chat.html', icon: '💬', label: 'Чаты' },
                { href: '/feed.html', icon: '📰', label: 'Лента' },
            ]},
            { group: 'Профиль', items: [
                { href: '/notify-settings.html', icon: '🔔', label: 'Уведомления' },
                { href: '/edit-profile.html', icon: '⚙️', label: 'Настройки' },
            ]},
        ],
    };

    // Разделы сайта, которые раньше висели во ВТОРОМ меню — в шапке. Теперь они здесь:
    // в кабинете остаётся одно меню, и на телефоне не два бургера рядом.
    const SITE_LINKS = [
        { href: '/', icon: '🏡', label: 'На сайт' },
        { href: '/search.html', icon: '🔍', label: 'Найти психолога' },
        { href: '/offers.html', icon: '💳', label: 'Пакеты и цены' },
        { href: '/feed.html', icon: '📰', label: 'Лента' },
        { href: '/blog.html', icon: '📖', label: 'Блог' },
    ];
    const TITLES = { psychologist: 'Кабинет психолога', client: 'Личный кабинет' };

    function esc(s) { const d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }

    function renderDashSidebarHtml(role, name) {
        const base = NAV[role] || NAV.client;
        const path = location.pathname;
        // Не повторяем ссылку дважды: «Найти психолога» и «Лента» у клиента уже есть выше.
        const already = new Set(base.flatMap(g => g.items.map(i => i.href)));
        const site = SITE_LINKS.filter(l => !already.has(l.href));
        const groups = site.length ? base.concat([{ group: 'Сайт', items: site }]) : base;
        const groupsHtml = groups.map(g => `
            <div class="dash-sidebar-group">
                <div class="dash-sidebar-group-label">${esc(g.group)}</div>
                ${g.items.map(it => `<a class="dash-sidebar-item${path === it.href ? ' active' : ''}" href="${it.href}"><span class="dash-sidebar-icon">${it.icon}</span> ${esc(it.label)}</a>`).join('')}
            </div>`).join('');
        return `
            <button class="dash-sidebar-toggle" onclick="document.querySelector('.dash-sidebar').classList.toggle('open');document.querySelector('.dash-sidebar-overlay').classList.toggle('open')">☰</button>
            <div class="dash-sidebar-overlay" onclick="document.querySelector('.dash-sidebar').classList.remove('open');this.classList.remove('open')"></div>
            <aside class="dash-sidebar">
                <div class="dash-sidebar-header">
                    <h2>${esc(TITLES[role] || 'Кабинет')}</h2>
                    <p id="sidebarName">${esc(name || '')}</p>
                </div>
                <nav class="dash-sidebar-nav">${groupsHtml}</nav>
                <div class="dash-sidebar-footer">
                    <!-- Переключатель темы убран из бокового меню кабинета: он теперь в
                         настройках (чаты → «Настройки») и в общем меню сайта. Здесь он
                         только занимал строку в каждом разделе. -->
                    <button class="dash-sidebar-item" onclick="psyLogout()">
                        <span class="dash-sidebar-icon">🚪</span> Выйти
                    </button>
                </div>
            </aside>`;
    }

    /** Вставить сайдбар вместо #dashSidebarMount (должен быть первым ребёнком внутри .dash-layout). */
    function mountDashSidebar(role, name) {
        const mount = document.getElementById('dashSidebarMount');
        if (!mount) return;
        mount.outerHTML = renderDashSidebarHtml(role, name);
        updateThemeBtn();
    }

    function toggleDashTheme() {
        document.body.classList.toggle('dark');
        const isDark = document.body.classList.contains('dark');
        try { localStorage.setItem('psy-theme', isDark ? 'dark' : 'light'); } catch (e) {}
        updateThemeBtn();
    }
    function updateThemeBtn() {
        const isDark = document.body.classList.contains('dark');
        const icon = document.querySelector('#themeBtn .dash-sidebar-icon');
        const label = document.getElementById('themeBtnLabel');
        if (icon) icon.textContent = isDark ? '☀️' : '🌙';
        if (label) label.textContent = isDark ? 'Светлая тема' : 'Тёмная тема';
    }
    async function psyLogout() {
        try { sessionStorage.removeItem('psy_user'); } catch (e) {}
        try {
            if (global.Auth && global.Auth.logout) { await global.Auth.logout(); return; }
            await fetch('/api/auth.php?action=logout', { method: 'POST', credentials: 'include' });
        } catch (e) {}
        window.location.href = '/';
    }

    global.mountDashSidebar = mountDashSidebar;
    global.renderDashSidebarHtml = renderDashSidebarHtml;
    if (!global.toggleDashTheme) global.toggleDashTheme = toggleDashTheme;
    if (!global.updateThemeBtn) global.updateThemeBtn = updateThemeBtn;
    if (!global.psyLogout) global.psyLogout = psyLogout;
})(window);
