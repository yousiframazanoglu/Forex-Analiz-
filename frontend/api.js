// ============================================================
// ForexAnaliz — Frontend API Katmanı (PHP Backend)
// ============================================================

const API_BASE = 'http://localhost/ForexAnaliz_PHP/api';

// ── Token yönetimi ────────────────────────────────────────────
const Auth = {
    getAccess()  { return localStorage.getItem('fa_access'); },
    getRefresh() { return localStorage.getItem('fa_refresh'); },
    getUser()    { const u = localStorage.getItem('fa_user'); return u ? JSON.parse(u) : null; },
    setTokens(access, refresh, user) {
        localStorage.setItem('fa_access',  access);
        localStorage.setItem('fa_refresh', refresh);
        localStorage.setItem('fa_user',    JSON.stringify(user));
    },
    clear() {
        localStorage.removeItem('fa_access');
        localStorage.removeItem('fa_refresh');
        localStorage.removeItem('fa_user');
    },
    isLoggedIn() { return !!this.getAccess(); },
};

// ── HTTP yardımcısı ───────────────────────────────────────────
async function apiFetch(url, options = {}) {
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    const token   = Auth.getAccess();
    if (token) headers['Authorization'] = `Bearer ${token}`;

    let res = await fetch(url, { ...options, headers });

    // Token süresi dolduysa refresh dene
    if (res.status === 401 && Auth.getRefresh()) {
        const r = await fetch(`${API_BASE}/auth.php?action=refresh`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ refresh_token: Auth.getRefresh() }),
        });
        if (r.ok) {
            const data = await r.json();
            localStorage.setItem('fa_access', data.access_token);
            headers['Authorization'] = `Bearer ${data.access_token}`;
            res = await fetch(url, { ...options, headers });
        } else {
            Auth.clear();
            window.location.href = 'giris.html';
            return null;
        }
    }

    if (!res.ok) {
        const err = await res.json().catch(() => ({ error: 'Bilinmeyen hata' }));
        throw new Error(err.error || err.detail || `HTTP ${res.status}`);
    }
    return res.json();
}

// ── Auth API ──────────────────────────────────────────────────
const AuthAPI = {
    async register(email, username, password, fullName) {
        return apiFetch(`${API_BASE}/auth.php?action=register`, {
            method: 'POST',
            body: JSON.stringify({ email, username, password, full_name: fullName }),
        });
    },
    async login(email, password) {
        const data = await apiFetch(`${API_BASE}/auth.php?action=login`, {
            method: 'POST',
            body: JSON.stringify({ email, password }),
        });
        if (data) Auth.setTokens(data.access_token, data.refresh_token, data.user);
        return data;
    },
    async logout() {
        await apiFetch(`${API_BASE}/auth.php?action=logout`, {
            method: 'POST',
            body: JSON.stringify({ refresh_token: Auth.getRefresh() }),
        }).catch(() => {});
        Auth.clear();
    },
    async getMe()         { return apiFetch(`${API_BASE}/auth.php?action=me`); },
    async updateMe(data)  {
        return apiFetch(`${API_BASE}/auth.php?action=update_me`, {
            method: 'PUT', body: JSON.stringify(data),
        });
    },
    async changePassword(old_, new_) {
        return apiFetch(`${API_BASE}/auth.php?action=change_password`, {
            method: 'POST',
            body: JSON.stringify({ old_password: old_, new_password: new_ }),
        });
    },
    async forgotPassword(email) {
        return apiFetch(`${API_BASE}/auth.php?action=forgot_password`, {
            method: 'POST', body: JSON.stringify({ email }),
        });
    },
    async resetPassword(token, newPassword) {
        return apiFetch(`${API_BASE}/auth.php?action=reset_password`, {
            method: 'POST',
            body: JSON.stringify({ token, new_password: newPassword }),
        });
    },
};

// ── Forex API ─────────────────────────────────────────────────
const ForexAPI = {
    async getPrices() {
        return apiFetch(`${API_BASE}/forex.php?action=prices`);
    },
    async getPrice(symbol) {
        return apiFetch(`${API_BASE}/forex.php?action=price&symbol=${encodeURIComponent(symbol)}`);
    },
    async getCandles(symbol, tf = '1D') {
        const sym = symbol.replace('/', '-');
        return apiFetch(`${API_BASE}/forex.php?action=candles&symbol=${sym}&tf=${tf}`);
    },
    async getTakvim() {
        return apiFetch(`${API_BASE}/forex.php?action=takvim`);
    },
    async contact(data) {
        return apiFetch(`${API_BASE}/forex.php?action=contact`, {
            method: 'POST', body: JSON.stringify(data),
        });
    },
    async getFavorites() {
        return apiFetch(`${API_BASE}/forex.php?action=favorites`);
    },
    async addFavorite(symbol) {
        return apiFetch(`${API_BASE}/forex.php?action=favorites`, {
            method: 'POST', body: JSON.stringify({ symbol }),
        });
    },
    async removeFavorite(symbol) {
        return apiFetch(`${API_BASE}/forex.php?action=favorites`, {
            method: 'DELETE', body: JSON.stringify({ symbol }),
        });
    },
    async getAlerts() {
        return apiFetch(`${API_BASE}/forex.php?action=alerts`);
    },
    async createAlert(data) {
        return apiFetch(`${API_BASE}/forex.php?action=alerts`, {
            method: 'POST', body: JSON.stringify(data),
        });
    },
    async deleteAlert(id) {
        return apiFetch(`${API_BASE}/forex.php?action=alerts&id=${id}`, {
            method: 'DELETE',
        });
    },
    async getNotes() {
        return apiFetch(`${API_BASE}/forex.php?action=notes`);
    },
    async createNote(data) {
        return apiFetch(`${API_BASE}/forex.php?action=notes`, {
            method: 'POST', body: JSON.stringify(data),
        });
    },
    async deleteNote(id) {
        return apiFetch(`${API_BASE}/forex.php?action=notes&id=${id}`, {
            method: 'DELETE',
        });
    },
};

// ── Polling (PHP'de WebSocket yok, 30sn polling) ─────────────
class PriceStream {
    constructor(onPrices) {
        this.onPrices = onPrices;
        this.running  = true;
        this._poll();
    }

    async _poll() {
        while (this.running) {
            try {
                const res = await ForexAPI.getPrices();
                if (res?.data) this.onPrices(res.data);
            } catch(e) {
                console.warn('Polling hatasi:', e.message);
            }
            await new Promise(r => setTimeout(r, 30000));
        }
    }

    close() { this.running = false; }
}

// ── Navbar kullanıcı durumu ───────────────────────────────────
function updateNavAuth() {
    const user  = Auth.getUser();
    const navEl = document.getElementById('nav-auth');
    if (!navEl) return;

    if (user) {
        navEl.innerHTML = `
            <a href="profil.html" class="btn btn-ghost" style="font-size:.82rem;padding:6px 12px;">
                👤 ${user.username}
            </a>
            <button class="btn btn-outline" style="font-size:.82rem;padding:6px 12px;"
                    onclick="handleLogout()">Cikis</button>`;
    } else {
        navEl.innerHTML = `
            <a href="giris.html"  class="btn btn-ghost"   style="font-size:.82rem;padding:6px 12px;">Giris Yap</a>
            <a href="kayit.html"  class="btn btn-primary" style="font-size:.82rem;padding:6px 12px;">Uye Ol</a>`;
    }
}

async function handleLogout() {
    await AuthAPI.logout();
    window.location.href = 'index.html';
}

document.addEventListener('DOMContentLoaded', updateNavAuth);
