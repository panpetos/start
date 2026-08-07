// js/auth.js - Клиент авторизации (исправленная версия)

/**
 * Модуль для работы с авторизацией
 * Обертка над API для удобной работы с аутентификацией
 */

window.Auth = {
    /**
     * Вход пользователя
     * @param {string} email - Email пользователя
     * @param {string} password - Пароль пользователя
     * @returns {Promise<Object>} Объект пользователя
     */
    async login(email, password) {
        try {
            const response = await window.API.auth.login({ email, password });

            if (response.ok && response.data && response.data.user) {
                try { sessionStorage.setItem('psy_user', JSON.stringify(response.data.user)); } catch (e) {}
                return response.data.user;
            }

            throw new Error(response.error || 'Ошибка входа');
        } catch (error) {
            console.error('Login error:', error);
            throw error;
        }
    },

    /**
     * Регистрация нового пользователя
     * @param {Object} userData - Данные пользователя
     * @returns {Promise<Object>} Объект пользователя
     */
    async register(userData) {
        try {
            const response = await window.API.auth.register(userData);

            if (response.ok && response.data && response.data.user) {
                return response.data.user;
            }

            throw new Error(response.error || 'Ошибка регистрации');
        } catch (error) {
            console.error('Registration error:', error);
            throw error;
        }
    },

    /**
     * Выход из системы
     * @returns {Promise<void>}
     */
    async logout() {
        try { sessionStorage.removeItem('psy_user'); } catch (e) {}
        try {
            await window.API.auth.logout();
        } catch (error) {
            console.error('Logout error:', error);
        }
        window.location.href = '/';
    },

    /**
     * Получить текущего пользователя
     * @returns {Promise<Object|null>} Объект пользователя или null
     */
    async getCurrentUser() {
        try {
            const response = await window.API.auth.getCurrentUser();

            if (response.ok && response.data && response.data.user) {
                try { sessionStorage.setItem('psy_user', JSON.stringify(response.data.user)); } catch (e) {}
                this._logSessionIp();
                return response.data.user;
            }

            try { sessionStorage.removeItem('psy_user'); } catch (e) {}
            return null;
        } catch (error) {
            console.error('Get current user error:', error);
            // Сетевой сбой — не выкидываем пользователя на вход, отдаём кэш.
            try { const c = sessionStorage.getItem('psy_user'); if (c) return JSON.parse(c); } catch (e) {}
            return null;
        }
    },

    /** Зафиксировать IP/устройство текущей сессии в журнале аудита (для юр. отчётности).
     *  Раз в браузерную вкладку — сервер дополнительно троттлит раз в 30 минут на юзера. */
    _logSessionIp() {
        try {
            if (sessionStorage.getItem('psy_session_logged')) return;
            sessionStorage.setItem('psy_session_logged', '1');
        } catch (e) {}
        try {
            fetch('/api/consent.php?action=log-session', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ page: location.pathname })
            }).catch(() => {});
        } catch (e) {}
    },

    /** Синхронно вернуть кэшированного пользователя (для мгновенного рендера). */
    getCachedUser() {
        try { const c = sessionStorage.getItem('psy_user'); return c ? JSON.parse(c) : null; }
        catch (e) { return null; }
    },

    /**
     * Инициализация (проверка текущего пользователя)
     * @returns {Promise<Object|null>} Объект пользователя или null
     */
    async init() {
        return this.getCurrentUser();
    },

    /**
     * Перенаправление на дашборд в зависимости от роли
     * @param {Object} user - Объект пользователя с полем role
     */
    redirectToDashboard(user) {
        if (!user || !user.role) {
            console.error('Invalid user object for redirect');
            window.location.href = '/';
            return;
        }

        const role = user.role;

        if (role === 'psychologist') {
            window.location.href = '/dashboard-psychologist.html';
        } else if (role === 'admin') {
            window.location.href = '/admin.html';
        } else {
            // client или любая другая роль
            window.location.href = '/dashboard-client.html';
        }
    },

    /**
     * Проверка, авторизован ли пользователь
     * @returns {Promise<boolean>}
     */
    async isAuthenticated() {
        const user = await this.getCurrentUser();
        return user !== null;
    },

    /**
     * Защита страницы - перенаправление на login если не авторизован
     * @param {Array<string>} allowedRoles - Разрешенные роли (опционально)
     * @returns {Promise<Object|null>} Объект пользователя или null
     */
    async requireAuth(allowedRoles = []) {
        const user = await this.getCurrentUser();

        if (!user) {
            window.location.href = '/login.html';
            return null;
        }

        if (allowedRoles.length > 0 && !allowedRoles.includes(user.role)) {
            alert('У вас нет доступа к этой странице');
            this.redirectToDashboard(user);
            return null;
        }

        return user;
    }
};

// Экспорт для совместимости
if (typeof module !== 'undefined' && module.exports) {
    module.exports = window.Auth;
}
