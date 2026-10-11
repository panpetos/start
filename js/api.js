// js/api.js - Главный API клиент

const API_BASE = '/api';

// Вспомогательная функция для запросов
async function apiRequest(endpoint, options = {}) {
    const url = `${API_BASE}/${endpoint}`;

    const defaultOptions = {
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json',
            ...(options.headers || {})
        }
    };

    const config = { ...defaultOptions, ...options };

    try {
        const response = await fetch(url, config);
        const data = await response.json();
        return data;
    } catch (error) {
        console.error('API Request failed:', error);
        throw error;
    }
}

// Auth API
const authAPI = {
    async register(userData) {
        return apiRequest('auth.php?action=register', {
            method: 'POST',
            body: JSON.stringify(userData)
        });
    },

    async login(credentials) {
        return apiRequest('auth.php?action=login', {
            method: 'POST',
            body: JSON.stringify(credentials)
        });
    },

    async logout() {
        return apiRequest('auth.php?action=logout', {
            method: 'POST'
        });
    },

    async getCurrentUser() {
        return apiRequest('auth.php?action=me');
    }
};

// Users API
const usersAPI = {
    async update(id, data) {
        return apiRequest(`users.php?action=update&id=${id}`, {
            method: 'POST',
            body: JSON.stringify(data)
        });
    }
};

// Psychologists API
const psychologistsAPI = {
    async list(filters = {}) {
        const params = new URLSearchParams(filters);
        return apiRequest(`psychologists.php?action=search&${params}`);
    },

    async get(id) {
        return apiRequest(`psychologists.php?action=get&id=${id}`);
    },

    async update(data) {
        return apiRequest('psychologists.php?action=update', {
            method: 'POST',
            body: JSON.stringify(data)
        });
    },

    async create(data) {
        return apiRequest('psychologists.php?action=create', {
            method: 'POST',
            body: JSON.stringify(data)
        });
    }
};

// Appointments API
const appointmentsAPI = {
    async create(appointmentData) {
        return apiRequest('appointments.php?action=create', {
            method: 'POST',
            body: JSON.stringify(appointmentData)
        });
    },

    async list(userId, role) {
        return apiRequest(`appointments.php?action=list&userId=${userId}&role=${role}`);
    },

    async updateStatus(id, status) {
        return apiRequest(`appointments.php?action=update-status&id=${id}`, {
            method: 'POST',
            body: JSON.stringify({ status })
        });
    }
};

// Admin API
const adminAPI = {
    async getStats() {
        return apiRequest('admin.php?action=stats');
    },

    async getAllUsers() {
        return apiRequest('admin.php?action=users');
    },

    async verifyPsychologist(psychologistId) {
        return apiRequest(`admin.php?action=verify-psychologist&id=${psychologistId}`, {
            method: 'POST'
        });
    }
};

// Export для использования в других скриптах
window.API = {
    auth: authAPI,
    users: usersAPI,
    psychologists: psychologistsAPI,
    appointments: appointmentsAPI,
    admin: adminAPI
};
