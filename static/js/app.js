/**
 * CS2 Case Simulator - Full Frontend Client Controller
 * Manages State, Roulette Physics Animation, Audio FX, Inventory, Trade-Up, and Monitoring
 */

const API_BASE = window.location.origin;

(function installCsrfFetchGuard() {
    const nativeFetch = window.fetch.bind(window);

    window.fetch = async function(input, init = {}) {
        const options = { ...init };
        const method = String(options.method || 'GET').toUpperCase();

        if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
            const match = document.cookie.match(/(?:^|; )csrf_token=([^;]+)/);
            if (match) {
                const headers = new Headers(options.headers || {});
                headers.set('X-CSRF-Token', decodeURIComponent(match[1]));
                options.headers = headers;
            }
            options.credentials = options.credentials || 'same-origin';
        }

        return nativeFetch(input, options);
    };
})();

function getFallbackCrateSvg(name) {
    const safeName = (name || 'CS2 Case').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return `data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="300" height="200" viewBox="0 0 300 200"><rect width="100%" height="100%" fill="%23131924" rx="12" stroke="%232b364c" stroke-width="2"/><path d="M70,60 L150,22 L230,60 L150,98 Z" fill="%23e58e26"/><path d="M70,60 L150,98 L150,178 L70,140 Z" fill="%23b86e18"/><path d="M230,60 L150,98 L150,178 L230,140 Z" fill="%2394540d"/><rect x="135" y="100" width="30" height="35" rx="4" fill="%23222b3d" stroke="%23ffd700" stroke-width="2"/><circle cx="150" cy="114" r="4" fill="%23ffd700"/><text x="150" y="166" font-family="sans-serif" font-size="12" font-weight="bold" fill="%23ffffff" text-anchor="middle">${encodeURIComponent(safeName)}</text></svg>`;
}

function getFallbackWeaponSvg(weapon, skinName, rarityTier = 1) {
    const colors = { 0: '#b0c3d9', 1: '#4b69ff', 2: '#8847ff', 3: '#d32ce6', 4: '#eb4b4b', 5: '#ffd700' };
    const col = colors[rarityTier] || '#b0c3d9';
    const safeW = (weapon || 'CS2 Weapon').replace(/&/g, '&amp;');
    const safeS = (skinName || 'Skin').replace(/&/g, '&amp;');
    return `data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="300" height="180" viewBox="0 0 300 180"><rect width="100%" height="100%" fill="%23101520" rx="8" stroke="${encodeURIComponent(col)}" stroke-width="2"/><circle cx="150" cy="80" r="45" fill="${encodeURIComponent(col)}" opacity="0.15"/><path d="M60,82 L210,68 L240,92 L185,92 L145,115 L125,95 L60,86 Z" fill="${encodeURIComponent(col)}" opacity="0.85"/><text x="150" y="138" font-family="sans-serif" font-size="12" font-weight="bold" fill="%23ffffff" text-anchor="middle">${encodeURIComponent(safeW)}</text><text x="150" y="156" font-family="sans-serif" font-size="11" fill="${encodeURIComponent(col)}" text-anchor="middle">${encodeURIComponent(safeS)}</text></svg>`;
}

class CS2App {
    constructor() {
        this.token = null;
        this.currentUser = null;
        this.cases = [];
        this.currentCase = null;
        this.inventory = [];
        this.tradeUpSelected = [];
        this.openCount = 1;
        this.isSpinning = false;
        this.revealedItem = null;
        this.currentMultiDrops = [];
        this.soundMuted = false;
        this.authMode = 'login';
        this.currentView = 'cases';
        
        // Roulette constants
        this.CARD_WIDTH = 160; // default px for x1
        this.REEL_ITEMS_COUNT = 75;
        this.WINNING_INDEX = 65;
    }

    getCardWidth() {
        if (this.openCount === 1) return 160;
        if (this.openCount === 5) return 115;
        return 135; // for x2 and x3 (compact)
    }

    async init() {
        this.setupAudioTrigger();
        await this.checkAuthSession();
        await this.loadCases();
        this.updateNavBadges();
    }

    // Audio unlock on first user gesture
    setupAudioTrigger() {
        const unlock = () => {
            if (window.soundEngine) window.soundEngine.init();
            document.removeEventListener('click', unlock);
        };
        document.addEventListener('click', unlock);
    }

    toggleSound() {
        this.soundMuted = !this.soundMuted;
        if (window.soundEngine) window.soundEngine.setMuted(this.soundMuted);
        const icon = document.getElementById('sound-icon');
        if (icon) {
            icon.className = this.soundMuted ? 'fa-solid fa-volume-xmark text-red-400' : 'fa-solid fa-volume-high text-slate-300';
        }
    }

    // ==========================================
    // AUTHENTICATION & SESSION MANAGEMENT
    // ==========================================

    async checkAuthSession() {
        try {
            const res = await fetch(API_BASE + '/api/auth/me', {
                credentials: 'same-origin'
            });
            if (res.ok) {
                const data = await res.json();
                this.currentUser = data.user;
            } else {
                this.currentUser = null;
            }
        } catch (err) {
            console.error("Auth check failed:", err);
            this.currentUser = null;
        }
        this.updateUserUI();
        this.checkDailyBadge();
    }

    updateUserUI() {
        const profileWidget = document.getElementById('user-profile-widget');
        const balanceDisplay = document.getElementById('user-balance-display');
        const adminNavBtn = document.getElementById('nav-btn-admin');

        if (this.currentUser) {
            if (balanceDisplay) {
                balanceDisplay.textContent = `$${this.currentUser.balance.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            }

            const isAdmin = this.currentUser.role === 'admin';
            if (adminNavBtn) {
                adminNavBtn.style.display = isAdmin ? 'flex' : 'none';
            }

            if (profileWidget) {
                profileWidget.innerHTML = `
                    <div class="flex items-center space-x-2 bg-[#131822] border border-[#212a3d] px-2.5 py-1 rounded-lg">
                        <div class="w-6 h-6 rounded-full ${isAdmin ? 'bg-purple-600' : 'bg-orange-500'} flex items-center justify-center text-[10px] font-bold text-black font-tactical">
                            ${this.currentUser.username.substring(0, 2).toUpperCase()}
                        </div>
                        <div class="text-xs font-tactical text-slate-200">
                            ${this.currentUser.username}
                            ${isAdmin ? '<span class="text-[9px] px-1 rounded bg-purple-500/30 text-purple-300 border border-purple-500/40 ml-1">ADMIN</span>' : ''}
                        </div>
                        <button onclick="app.logout()" class="text-slate-400 hover:text-red-400 ml-1 text-xs" title="Đăng xuất">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i>
                        </button>
                    </div>
                `;
            }
        } else {
            if (balanceDisplay) balanceDisplay.textContent = '$0.00';
            if (adminNavBtn) adminNavBtn.style.display = 'none';
            if (profileWidget) {
                profileWidget.innerHTML = `
                    <button onclick="app.openAuthModal('login')" class="px-3 py-1.5 text-xs font-tactical font-bold bg-csOrange text-black rounded hover:bg-amber-400 transition flex items-center gap-1.5 shadow-md">
                        <i class="fa-solid fa-right-to-bracket"></i> ĐĂNG NHẬP
                    </button>
                `;
            }
        }
    }

    openAuthModal(mode = 'login') {
        this.authMode = mode;
        this.switchAuthTab(mode);
        document.getElementById('auth-modal').classList.remove('hidden');
    }

    closeAuthModal() {
        document.getElementById('auth-modal').classList.add('hidden');
    }

    switchAuthTab(mode) {
        this.authMode = mode;
        const tabsRow = document.getElementById('auth-tabs-row');
        const forgotHeader = document.getElementById('auth-forgot-header');
        const forgotBack = document.getElementById('auth-forgot-back');

        const tabLogin = document.getElementById('auth-tab-login');
        const tabReg = document.getElementById('auth-tab-register');

        const emailField = document.getElementById('auth-email-field');
        const passwordField = document.getElementById('auth-password-field');
        const confirmPasswordField = document.getElementById('auth-confirm-password-field');
        const newPasswordField = document.getElementById('auth-new-password-field');
        const confirmNewPasswordField = document.getElementById('auth-confirm-new-password-field');
        const linksRow = document.getElementById('auth-links-row');
        const submitBtn = document.getElementById('auth-submit-btn');
        const usernameInput = document.getElementById('auth-username');

        const activeClass = "flex-1 py-2 font-tactical font-bold text-xs sm:text-sm text-csOrange border-b-2 border-csOrange";
        const inactiveClass = "flex-1 py-2 font-tactical font-bold text-xs sm:text-sm text-slate-400 hover:text-white";

        if (mode === 'login') {
            if (tabsRow) tabsRow.classList.remove('hidden');
            if (forgotHeader) forgotHeader.classList.add('hidden');
            if (forgotBack) forgotBack.classList.add('hidden');
            if (tabLogin) tabLogin.className = activeClass;
            if (tabReg) tabReg.className = inactiveClass;

            if (emailField) emailField.classList.add('hidden');
            if (passwordField) passwordField.classList.remove('hidden');
            if (confirmPasswordField) confirmPasswordField.classList.add('hidden');
            if (newPasswordField) newPasswordField.classList.add('hidden');
            if (confirmNewPasswordField) confirmNewPasswordField.classList.add('hidden');
            if (linksRow) linksRow.classList.remove('hidden');
            if (submitBtn) submitBtn.textContent = "ĐĂNG NHẬP";
            if (usernameInput) usernameInput.placeholder = "Nhập tên đăng nhập hoặc email...";
        } else if (mode === 'register') {
            if (tabsRow) tabsRow.classList.remove('hidden');
            if (forgotHeader) forgotHeader.classList.add('hidden');
            if (forgotBack) forgotBack.classList.add('hidden');
            if (tabReg) tabReg.className = activeClass;
            if (tabLogin) tabLogin.className = inactiveClass;

            if (emailField) emailField.classList.remove('hidden');
            if (passwordField) passwordField.classList.remove('hidden');
            if (confirmPasswordField) confirmPasswordField.classList.remove('hidden');
            if (newPasswordField) newPasswordField.classList.add('hidden');
            if (confirmNewPasswordField) confirmNewPasswordField.classList.add('hidden');
            if (linksRow) linksRow.classList.add('hidden');
            if (submitBtn) submitBtn.textContent = "ĐĂNG KÝ (SỐ DƯ KHỞI TẠO $0.00)";
            if (usernameInput) usernameInput.placeholder = "Tên đăng nhập mong muốn...";
        } else if (mode === 'forgot') {
            if (tabsRow) tabsRow.classList.add('hidden');
            if (forgotHeader) forgotHeader.classList.remove('hidden');
            if (forgotBack) forgotBack.classList.remove('hidden');

            if (emailField) emailField.classList.remove('hidden');
            if (passwordField) passwordField.classList.add('hidden');
            if (confirmPasswordField) confirmPasswordField.classList.add('hidden');
            if (newPasswordField) newPasswordField.classList.remove('hidden');
            if (confirmNewPasswordField) confirmNewPasswordField.classList.remove('hidden');
            if (linksRow) linksRow.classList.add('hidden');
            if (submitBtn) submitBtn.textContent = "ĐẶT LẠI MẬT KHẨU";
            if (usernameInput) usernameInput.placeholder = "Tên đăng nhập của tài khoản...";
        }
    }

    async handleAuthSubmit(e) {
        e.preventDefault();
        const username = (document.getElementById('auth-username').value || '').trim();
        const email = (document.getElementById('auth-email').value || '').trim();
        const password = document.getElementById('auth-password').value;
        const confirmPassword = document.getElementById('auth-confirm-password').value;
        const newPassword = document.getElementById('auth-new-password').value;
        const confirmNewPassword = document.getElementById('auth-confirm-new-password').value;

        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        if (this.authMode === 'login') {
            if (!username || !password) {
                this.notify("Vui lòng nhập tên đăng nhập và mật khẩu!", "error");
                return;
            }
            try {
                const res = await fetch(`${API_BASE}/api/auth/login`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username_or_email: username, password })
                });
                const data = await res.json();
                if (res.ok) {
                    this.token = null;
                    this.currentUser = data.user;
                    this.updateUserUI();
                    this.closeAuthModal();
                    this.checkDailyBadge();
                    this.notify(`Đăng nhập thành công! Chào mừng ${this.currentUser.username}.`, "success");
                    if (this.currentUser.role === 'admin') {
                        this.notify("Tài khoản Quản Trị Viên (Admin) đã được kích hoạt!", "info");
                    }
                } else {
                    this.notify(data.detail || "Đăng nhập thất bại. Kiểm tra lại thông tin.", "error");
                }
            } catch (err) {
                this.notify("Lỗi kết nối máy chủ", "error");
            }

        } else if (this.authMode === 'register') {
            if (!username) {
                this.notify("Vui lòng nhập tên đăng nhập!", "error");
                return;
            }
            if (!email || !emailRegex.test(email)) {
                this.notify("Email không hợp lệ (cú pháp yêu cầu: abc@def.com)!", "error");
                return;
            }
            if (!password || password.length < 6) {
                this.notify("Mật khẩu phải có độ dài từ 6 ký tự trở lên!", "error");
                return;
            }
            if (password !== confirmPassword) {
                this.notify("Mật khẩu xác nhận không trùng khớp!", "error");
                return;
            }

            try {
                const res = await fetch(`${API_BASE}/api/auth/register`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, email, password })
                });
                const data = await res.json();
                if (res.ok) {
                    this.token = null;
                    this.currentUser = data.user;
                    this.updateUserUI();
                    this.closeAuthModal();
                    this.checkDailyBadge();
                    this.notify("Đăng ký thành công! Số dư mặc định: $0.00. Hãy điểm danh nhận quà để nhận tiền mở rương!", "success");
                    setTimeout(() => this.openDailyModal(), 500);
                } else {
                    this.notify(data.detail || "Đăng ký thất bại", "error");
                }
            } catch (err) {
                this.notify("Lỗi kết nối máy chủ", "error");
            }

        } else if (this.authMode === 'forgot') {
            if (!username) {
                this.notify("Vui lòng nhập tên đăng nhập!", "error");
                return;
            }
            if (!email || !emailRegex.test(email)) {
                this.notify("Vui lòng nhập email hợp lệ (abc@def.com)!", "error");
                return;
            }
            if (!newPassword || newPassword.length < 6) {
                this.notify("Mật khẩu mới phải có ít nhất 6 ký tự!", "error");
                return;
            }
            if (newPassword !== confirmNewPassword) {
                this.notify("Mật khẩu mới xác nhận không khớp!", "error");
                return;
            }

            try {
                const res = await fetch(`${API_BASE}/api/auth/forgot-password`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, email, new_password: newPassword })
                });
                const data = await res.json();
                if (res.ok) {
                    this.notify(data.message || "Đặt lại mật khẩu thành công! Vui lòng đăng nhập với mật khẩu mới.", "success");
                    this.switchAuthTab('login');
                    const pwInput = document.getElementById('auth-password');
                    if (pwInput) pwInput.value = newPassword;
                } else {
                    this.notify(data.detail || "Không thể đặt lại mật khẩu", "error");
                }
            } catch (err) {
                this.notify("Lỗi kết nối máy chủ", "error");
            }
        }
    }

    async logout() {
        try {
            await fetch(API_BASE + '/api/auth/logout', {
                method: 'POST',
                credentials: 'same-origin'
            });
        } catch (e) {
            console.warn("Logout request failed:", e);
        }
        this.token = null;
        this.currentUser = null;
        this.updateUserUI();
        this.checkDailyBadge();
        this.notify("Đã đăng xuất", "info");
        this.navigate('cases');
    }

    // ==========================================
    // 7-DAY DAILY LOGIN REWARD SYSTEM
    // ==========================================

    async checkDailyBadge() {
        const dot = document.getElementById('daily-ready-dot');
        if (!this.currentUser) {
            if (dot) dot.classList.add('hidden');
            return;
        }

        try {
            const res = await fetch(`${API_BASE}/api/daily/status`, {
                headers: { }
            });
            if (res.ok) {
                const data = await res.json();
                if (dot) {
                    if (data.can_claim) {
                        dot.classList.remove('hidden');
                    } else {
                        dot.classList.add('hidden');
                    }
                }
                if (this.currentUser) {
                    this.currentUser.daily_streak = data.current_streak;
                }
            }
        } catch (e) {
            console.error("Check daily badge error:", e);
        }
    }

    async openDailyModal() {
        if (!this.currentUser) {
            this.notify("Vui lòng đăng nhập để nhận quà hàng ngày!", "info");
            this.openAuthModal('login');
            return;
        }

        const modal = document.getElementById('daily-modal');
        if (!modal) return;

        try {
            const res = await fetch(`${API_BASE}/api/daily/status`, {
                headers: { }
            });
            if (!res.ok) {
                this.notify("Không thể tải thông tin quà đăng nhập", "error");
                return;
            }
            const data = await res.json();

            // Update streak counter
            const streakEl = document.getElementById('daily-streak-count');
            if (streakEl) {
                streakEl.textContent = `${data.current_streak} / 7 Ngày`;
            }

            // Grid rendering
            const grid = document.getElementById('daily-schedule-grid');
            if (grid) {
                grid.innerHTML = data.schedule.map(item => {
                    let cardClasses = "";
                    let statusBadge = "";
                    let iconColor = "text-csOrange";

                    if (item.claimed) {
                        cardClasses = "border-green-500/50 bg-green-500/10 shadow-lg shadow-green-500/10";
                        statusBadge = `<span class="text-[9px] font-tactical font-bold text-green-400 bg-green-500/20 px-1.5 py-0.5 rounded border border-green-500/30 flex items-center gap-1 justify-center"><i class="fa-solid fa-check"></i> ĐÃ NHẬN</span>`;
                        iconColor = "text-green-400";
                    } else if (item.is_today) {
                        cardClasses = "border-csOrange bg-gradient-to-b from-orange-500/25 to-amber-500/10 ring-2 ring-orange-500/80 shadow-lg shadow-orange-500/30 animate-pulse";
                        statusBadge = `<span class="text-[9px] font-tactical font-black text-black bg-csOrange px-1.5 py-0.5 rounded flex items-center gap-1 justify-center"><i class="fa-solid fa-bolt"></i> HÔM NAY</span>`;
                        iconColor = "text-amber-300";
                    } else {
                        cardClasses = "border-slate-800 bg-[#0c1017] opacity-60";
                        statusBadge = `<span class="text-[9px] font-tactical text-slate-500 flex items-center gap-1 justify-center"><i class="fa-solid fa-lock text-[8px]"></i> CHỜ MỞ</span>`;
                        iconColor = "text-slate-500";
                    }

                    const isDay7 = item.day === 7;

                    return `
                        <div class="relative p-2.5 rounded-xl border flex flex-col items-center justify-between text-center transition ${cardClasses} ${isDay7 ? 'sm:col-span-2 md:col-span-1 border-amber-400/60' : ''}">
                            <div class="text-[10px] font-tactical font-bold text-slate-400">NGÀY ${item.day}</div>
                            <div class="my-1.5 w-8 h-8 rounded-full bg-black/40 flex items-center justify-center">
                                <i class="fa-solid ${item.icon} ${iconColor} text-sm"></i>
                            </div>
                            <div class="font-tactical font-extrabold text-sm ${item.claimed ? 'text-green-400' : (item.is_today ? 'text-csGold' : 'text-slate-300')}">
                                +$${item.reward.toFixed(0)}
                            </div>
                            <div class="text-[9px] text-slate-400 truncate w-full mb-1">${item.title}</div>
                            <div class="w-full mt-auto">${statusBadge}</div>
                        </div>
                    `;
                }).join('');
            }

            // Timer / status text
            const timerText = document.getElementById('daily-timer-text');
            if (timerText) {
                if (data.can_claim) {
                    timerText.innerHTML = `<i class="fa-solid fa-gift text-green-400 mr-1 animate-bounce"></i> <span class="text-green-400 font-bold">Quà Ngày ${data.next_day} đã sẵn sàng nhận ngay!</span>`;
                } else {
                    timerText.innerHTML = `<i class="fa-solid fa-clock text-amber-400 mr-1"></i> Đã nhận hôm nay. Quà Ngày ${data.next_day} mở sau khoảng <span class="text-amber-300 font-bold">${data.hours_left} giờ</span> nữa.`;
                }
            }

            // Claim button
            const claimBtn = document.getElementById('btn-claim-daily');
            const claimBtnText = document.getElementById('btn-claim-daily-text');
            if (claimBtn && claimBtnText) {
                if (data.can_claim) {
                    claimBtn.disabled = false;
                    claimBtn.className = "w-full sm:w-auto px-8 py-2.5 bg-gradient-to-r from-orange-500 to-amber-500 hover:from-orange-400 hover:to-amber-500 text-black font-tactical font-extrabold text-sm rounded-lg shadow-lg shadow-orange-500/30 transition transform hover:scale-105 flex items-center justify-center gap-2 cursor-pointer";
                    claimBtnText.textContent = `NHẬN QUÀ NGAY (+$${data.next_reward.toFixed(2)})`;
                } else {
                    claimBtn.disabled = true;
                    claimBtn.className = "w-full sm:w-auto px-8 py-2.5 bg-slate-800 text-slate-500 font-tactical font-bold text-sm rounded-lg cursor-not-allowed flex items-center justify-center gap-2";
                    claimBtnText.textContent = "ĐÃ ĐIỂM DANH HÔM NAY";
                }
            }

            modal.classList.remove('hidden');
        } catch (e) {
            console.error("Open daily modal error:", e);
            this.notify("Lỗi khi mở giao diện điểm danh", "error");
        }
    }

    closeDailyModal() {
        const modal = document.getElementById('daily-modal');
        if (modal) modal.classList.add('hidden');
    }

    async claimDailyReward() {
        if (!this.currentUser) {
            this.openAuthModal('login');
            return;
        }

        const claimBtn = document.getElementById('btn-claim-daily');
        if (claimBtn) claimBtn.disabled = true;

        try {
            const res = await fetch(`${API_BASE}/api/daily/claim`, {
                method: 'POST',
                headers: { }
            });
            const data = await res.json();
            if (res.ok) {
                if (this.currentUser) {
                    this.currentUser.balance = data.new_balance;
                    this.currentUser.daily_streak = data.streak;
                }
                this.updateUserUI();
                this.notify(data.message, "success");
                if (window.soundEngine && typeof window.soundEngine.playTradeUpSuccess === 'function') {
                    window.soundEngine.playTradeUpSuccess();
                }
                await this.openDailyModal();
                await this.checkDailyBadge();
            } else {
                this.notify(data.detail || "Không thể nhận quà hôm nay", "error");
                if (claimBtn) claimBtn.disabled = false;
            }
        } catch (e) {
            console.error("Claim daily reward error:", e);
            this.notify("Lỗi kết nối khi nhận quà", "error");
            if (claimBtn) claimBtn.disabled = false;
        }
    }

    // ==========================================
    // NAVIGATION & VIEW SWITCHING
    // ==========================================

    navigate(viewName) {
        this.currentView = viewName;
        const views = ['cases', 'open-case', 'inventory', 'tradeup', 'admin'];
        views.forEach(v => {
            const el = document.getElementById(`view-${v}`);
            if (el) el.classList.add('hidden');
            const navBtn = document.getElementById(`nav-btn-${v}`);
            if (navBtn) {
                navBtn.className = "px-3 py-1.5 rounded transition text-slate-300 hover:text-white hover:bg-slate-800/60 flex items-center gap-2";
            }
        });

        const activeEl = document.getElementById(`view-${viewName}`);
        if (activeEl) activeEl.classList.remove('hidden');

        const activeNavBtn = document.getElementById(`nav-btn-${viewName}`);
        if (activeNavBtn) {
            activeNavBtn.className = "px-3 py-1.5 rounded transition text-csOrange bg-orange-500/10 font-bold flex items-center gap-2";
        }

        if (viewName === 'inventory') this.loadInventory();
        if (viewName === 'tradeup') this.setupTradeUpView();
        if (viewName === 'admin') this.loadAdminOverview();
    }

    // ==========================================
    // CASES CATALOG
    // ==========================================

    async loadCases() {
        try {
            const res = await fetch(`${API_BASE}/api/cases`);
            if (res.ok) {
                const data = await res.json();
                this.cases = data.cases;
                const catAllBtn = document.getElementById('case-cat-all');
                if (catAllBtn) catAllBtn.textContent = `Tất cả rương (${this.cases.length})`;
                this.renderCasesGrid(this.cases);
            }
        } catch (err) {
            console.error("Failed to load cases:", err);
        }
    }

    renderCasesGrid(cases) {
        const grid = document.getElementById('cases-grid');
        if (!grid) return;

        if (cases.length === 0) {
            grid.innerHTML = `<div class="col-span-full py-12 text-center text-slate-400">Không tìm thấy rương phù hợp.</div>`;
            return;
        }

        grid.innerHTML = cases.map(c => `
            <div class="case-card bg-csCard border border-csBorder rounded-xl p-5 flex flex-col items-center justify-between cursor-pointer" onclick="app.selectCase('${c.id}')">
                <div class="w-full flex items-center justify-end text-xs font-mono text-slate-400 mb-2">
                    <span class="text-slate-300 bg-slate-800/70 px-2.5 py-0.5 rounded-full border border-slate-700/60 flex items-center gap-1.5 text-[11px]">
                        <i class="fa-solid fa-layer-group text-[10px] text-csOrange"></i>
                        <span>${c.total_items} Skins</span>
                    </span>
                </div>
                <div class="py-4 w-full flex items-center justify-center relative min-h-[150px]">
                    <img src="${c.image}" alt="${c.name}" class="h-36 object-contain drop-shadow-xl transition-transform duration-300 hover:scale-105" onerror="this.onerror=null; this.src=getFallbackCrateSvg('${c.name.replace(/'/g, "\\'")}');">
                </div>
                <div class="w-full text-center mt-2">
                    <h3 class="font-tactical font-bold text-lg text-white group-hover:text-csOrange transition">${c.name}</h3>
                    <p class="text-xs text-slate-400 line-clamp-1 mt-0.5">${c.description}</p>
                </div>
                <div class="w-full mt-4 pt-3 border-t border-csBorder/70 flex items-center justify-between">
                    <div>
                        <div class="text-[10px] text-slate-500 font-mono">TỔNG GIÁ</div>
                        <div class="font-tactical font-bold text-base text-csOrange">$${(c.price + c.key_price).toFixed(2)}</div>
                    </div>
                    <button class="px-3.5 py-1.5 bg-csOrange/20 hover:bg-csOrange hover:text-black border border-csOrange/40 text-csOrange font-tactical font-bold text-xs rounded transition flex items-center gap-1.5">
                        <i class="fa-solid fa-lock-open"></i> MỞ NGAY
                    </button>
                </div>
            </div>
        `).join('');
    }

    filterCases(query) {
        const q = query.toLowerCase().trim();
        const filtered = this.cases.filter(c => c.name.toLowerCase().includes(q) || c.description.toLowerCase().includes(q));
        this.renderCasesGrid(filtered);
    }

    filterCaseCategory(cat) {
        ['all', 'cs2', 'knives', 'gloves', 'grail'].forEach(c => {
            const btn = document.getElementById(`case-cat-${c}`);
            if (btn) {
                if (c === cat) {
                    btn.className = "px-3 py-1.5 rounded bg-csOrange text-black font-bold transition";
                } else {
                    btn.className = "px-3 py-1.5 rounded bg-[#131822] border border-csBorder text-slate-300 hover:text-white hover:border-csOrange transition";
                }
            }
        });

        if (cat === 'all') {
            this.renderCasesGrid(this.cases);
        } else if (cat === 'cs2') {
            this.renderCasesGrid(this.cases.filter(c => 
                c.name.includes('Gallery') || c.name.includes('Kilowatt') || c.name.includes('Revolution') || 
                c.name.includes('Recoil') || c.name.includes('Dreams') || c.name.includes('Snakebite') || 
                c.name.includes('Fracture') || c.name.includes('Prisma 2')
            ));
        } else if (cat === 'knives') {
            this.renderCasesGrid(this.cases.filter(c => 
                !c.name.includes('Glove Case') && !c.name.includes('Broken Fang') && !c.name.includes('Clutch Case')
            ));
        } else if (cat === 'gloves') {
            this.renderCasesGrid(this.cases.filter(c => 
                c.name.includes('Clutch') || c.name.includes('Glove Case') || c.name.includes('Snakebite') || 
                c.name.includes('Recoil') || c.name.includes('Revolution') || c.name.includes('Broken Fang') || 
                c.name.includes('Hydra')
            ));
        } else if (cat === 'grail') {
            this.renderCasesGrid(this.cases.filter(c => 
                c.name.includes('Weapon Case') || c.name.includes('Bravo') || c.name.includes('Huntsman') || 
                c.name.includes('Breakout') || c.name.includes('eSports') || c.name.includes('Winter Offensive') ||
                c.name.includes('Phoenix') || c.name.includes('Vanguard') || c.name.includes('Hydra')
            ));
        }
    }


    filterInventoryByWeaponType(type) {
        this.currentWeaponType = type;
        this.renderInventoryGrid(this.inventory);
    }

    // ==========================================
    // CASE OPENING & ROULETTE REEL ANIMATION
    // ==========================================

    async selectCase(caseId) {
        try {
            const res = await fetch(`${API_BASE}/api/cases/${caseId}`);
            if (!res.ok) throw new Error("Could not load case detail");
            
            const data = await res.json();
            this.currentCase = data;
            this.navigate('open-case');

            // Set UI details
            document.getElementById('open-case-name').textContent = data.case.name;
            document.getElementById('open-case-desc').textContent = data.case.description;
            document.getElementById('open-case-unit-price').textContent = `$${data.total_price.toFixed(2)}`;
            
            // Set default count = 1 and build reels
            this.setOpenCount(1);

            // Populate items grid in case view
            this.renderCaseSkins(data.skins);

            // Hide previous multi-drop section
            const multiDropCont = document.getElementById('multi-drop-container');
            if (multiDropCont) multiDropCont.classList.add('hidden');

        } catch (err) {
            this.notify("Không thể tải thông tin rương", "error");
        }
    }

    renderCaseSkins(skins) {
        const grid = document.getElementById('case-items-grid');
        if (!grid) return;

        grid.innerHTML = skins.map(s => `
            <div class="skin-card p-3 flex flex-col items-center justify-between text-center rarity-${s.rarity_tier} group cursor-pointer" onclick="app.inspectCaseSkin('${s.id}')">
                <div class="w-full flex items-center justify-between text-[10px] font-mono">
                    <span class="text-xs text-slate-500 group-hover:text-cyan-400 transition" title="Bấm để Soi 3D"><i class="fa-solid fa-cube"></i></span>
                    <span class="${s.rarity_tier === 5 ? 'text-csGold font-bold' : 'text-slate-400'}">$${s.base_price.toFixed(2)}</span>
                </div>
                <img src="${s.image}" alt="${s.name}" class="h-20 object-contain my-2 drop-shadow group-hover:scale-105 transition" onerror="this.onerror=null; this.src=getFallbackWeaponSvg('${s.weapon.replace(/'/g, "\\'")}', '${s.skin_name.replace(/'/g, "\\'")}', ${s.rarity_tier});">
                <div class="w-full">
                    <div class="text-[11px] text-slate-400 font-tactical truncate">${s.weapon}</div>
                    <div class="text-xs font-tactical font-bold truncate text-white">${s.skin_name}</div>
                    <div class="text-[9px] text-cyan-400 font-tactical mt-1 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-1">
                        <i class="fa-solid fa-cube text-[8px]"></i> BẤM ĐỂ SOI 3D
                    </div>
                </div>
                <div class="skin-card-bottom-bar bg-rarity-${s.rarity_tier} mt-2"></div>
            </div>
        `).join('');
    }

    renderMultiRouletteReels(count) {
        const container = document.getElementById('roulette-multi-container');
        if (!container) return;

        let sizeClass = '';
        if (count === 2 || count === 3) {
            sizeClass = 'compact';
        } else if (count === 5) {
            sizeClass = 'mini';
        }

        let html = '';
        for (let i = 0; i < count; i++) {
            html += `
                <div class="roulette-wrapper ${sizeClass} relative" id="roulette-wrapper-${i}">
                    <div class="roulette-mask-left"></div>
                    <div class="roulette-mask-right"></div>
                    <div class="roulette-needle"></div>
                    <div class="roulette-viewport" id="roulette-viewport-${i}">
                        <div class="roulette-track" id="roulette-track-${i}"></div>
                    </div>
                </div>
            `;
        }
        container.innerHTML = html;

        // Populate track preview for each reel
        if (this.currentCase && this.currentCase.skins) {
            for (let i = 0; i < count; i++) {
                this.prepareRouletteTrack(this.currentCase.skins, null, i);
            }
        }
    }

    prepareRouletteTrack(skins, winningItem = null, trackIndex = 0) {
        const track = document.getElementById(`roulette-track-${trackIndex}`);
        if (!track || !skins || !skins.length) return;

        // Reset track position
        track.style.transition = 'none';
        track.style.transform = 'translateX(0px)';

        let html = '';
        for (let i = 0; i < this.REEL_ITEMS_COUNT; i++) {
            let item;
            if (i === this.WINNING_INDEX && winningItem) {
                item = winningItem;
            } else {
                // Bias random roulette skins mostly toward blues/purples with occasional reds/knives
                const rand = Math.random();
                let filtered = skins;
                if (rand < 0.70) filtered = skins.filter(s => s.rarity_tier === 1);
                else if (rand < 0.88) filtered = skins.filter(s => s.rarity_tier === 2);
                else if (rand < 0.96) filtered = skins.filter(s => s.rarity_tier === 3);
                else if (rand < 0.99) filtered = skins.filter(s => s.rarity_tier === 4);
                else filtered = skins.filter(s => s.rarity_tier === 5);

                if (!filtered.length) filtered = skins;
                item = filtered[Math.floor(Math.random() * filtered.length)];
            }

            const safeWeapon = (item.weapon || '').replace(/'/g, "\\'");
            const safeSkin = (item.skin_name || item.name || '').replace(/'/g, "\\'");
            const price = item.base_price ? item.base_price.toFixed(2) : (item.value ? item.value.toFixed(2) : '1.00');

            html += `
                <div class="roulette-item rarity-${item.rarity_tier}" data-index="${i}">
                    <div class="text-[10px] font-mono text-slate-400 self-end">$${price}</div>
                    <img src="${item.image}" alt="${item.name || safeSkin}" class="h-20 object-contain my-1 drop-shadow" onerror="this.onerror=null; this.src=getFallbackWeaponSvg('${safeWeapon}', '${safeSkin}', ${item.rarity_tier});">
                    <div class="w-full text-center">
                        <div class="text-[10px] text-slate-400 font-tactical truncate">${item.weapon || ''}</div>
                        <div class="text-xs font-tactical font-bold truncate text-white">${item.skin_name || item.name || ''}</div>
                    </div>
                    <div class="roulette-item-bottom bg-rarity-${item.rarity_tier}"></div>
                </div>
            `;
        }
        track.innerHTML = html;
    }

    setOpenCount(count) {
        if (this.isSpinning) return;
        this.openCount = count;
        [1, 2, 3, 5].forEach(c => {
            const btn = document.getElementById(`btn-count-${c}`);
            if (btn) {
                if (c === count) {
                    btn.className = "px-3 py-1.5 text-xs font-tactical font-bold border border-csBorder bg-orange-500 text-black";
                } else {
                    btn.className = "px-3 py-1.5 text-xs font-tactical font-bold border-t border-b border-csBorder bg-[#1a2230] text-slate-300 hover:text-white";
                }
            }
        });

        if (this.currentCase) {
            const total = (this.currentCase.total_price * count).toFixed(2);
            const costEl = document.getElementById('open-cost-total');
            if (costEl) costEl.textContent = `$${total}`;
            this.renderMultiRouletteReels(count);
        }
    }

    async executeOpenCase() {
        if (!this.currentCase || this.isSpinning) return;
        if (!this.currentUser) {
            this.openAuthModal('login');
            return;
        }

        const cost = this.currentCase.total_price * this.openCount;
        if (this.currentUser.balance < cost) {
            this.notify(`Số dư không đủ ($${this.currentUser.balance.toFixed(2)}). Cần $${cost.toFixed(2)}`, "error");
            this.openDepositModal();
            return;
        }

        this.isSpinning = true;
        const btn = document.getElementById('btn-open-case');
        btn.disabled = true;
        btn.classList.add('opacity-50');

        const fastOpen = document.getElementById('fast-open-toggle').checked;

        try {
            const res = await fetch(`${API_BASE}/api/cases/${this.currentCase.case.id}/open`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ count: this.openCount })
            });

            const data = await res.json();
            if (!res.ok) {
                throw new Error(data.detail || "Opening failed");
            }

            // Update user balance
            this.currentUser.balance = data.remaining_balance;
            this.updateUserUI();

            const allDrops = data.drops;

            if (fastOpen) {
                // Instant open without delay
                const maxTier = Math.max(...allDrops.map(d => d.rarity_tier || 1));
                if (window.soundEngine) window.soundEngine.playItemReveal(maxTier);
                this.isSpinning = false;
                btn.disabled = false;
                btn.classList.remove('opacity-50');
                this.displayUnboxResult(allDrops);
            } else {
                // Smooth CS2 physics roulette reel for all opened cases
                this.spinRoulette(allDrops);
            }

        } catch (err) {
            this.notify(err.message, "error");
            this.isSpinning = false;
            btn.disabled = false;
            btn.classList.remove('opacity-50');
        }
    }

    spinRoulette(allDrops) {
        const count = allDrops.length;
        const cardW = this.getCardWidth();

        // Sound whoosh
        if (window.soundEngine) window.soundEngine.playSpinStart();

        // Add needle glow while spinning
        document.querySelectorAll('.roulette-needle').forEach(n => n.classList.add('spinning'));

        let maxDuration = 5.5;

        for (let i = 0; i < count; i++) {
            const winningItem = allDrops[i];
            const track = document.getElementById(`roulette-track-${i}`);
            const viewport = document.getElementById(`roulette-viewport-${i}`);
            if (!track || !viewport) continue;

            // Re-generate track with winning item at target index
            this.prepareRouletteTrack(this.currentCase.skins, winningItem, i);

            // Calculate target offset: center of winning card at needle
            const viewportWidth = viewport.offsetWidth || 800;
            // Smaller jitter for cleaner stop (±10% of card width)
            const jitter = (Math.random() * (cardW * 0.2)) - (cardW * 0.1);
            const targetCardCenter = (this.WINNING_INDEX * cardW) + (cardW / 2) + jitter;
            const targetTranslateX = targetCardCenter - (viewportWidth / 2);

            // Stagger each reel slightly for multi-open
            const duration = 5.5 + (i * 0.18);
            if (duration > maxDuration) maxDuration = duration;

            // Force reflow to reset position cleanly
            void track.offsetWidth;

            // Smooth deceleration: fast start, very gentle glide to stop
            // cubic-bezier(0.08, 0.82, 0.17, 1) — CS2-authentic feel
            track.style.transition = `transform ${duration}s cubic-bezier(0.08, 0.82, 0.17, 1)`;
            track.style.transform = `translate3d(-${targetTranslateX}px, 0, 0)`;

            // Procedural tick audio on first track
            if (i === 0) {
                this.startRouletteTickAudio(duration, targetTranslateX, cardW);
            }
        }

        // On spin finish
        setTimeout(() => {
            this.isSpinning = false;

            // Remove needle glow animation
            document.querySelectorAll('.roulette-needle').forEach(n => n.classList.remove('spinning'));

            const btn = document.getElementById('btn-open-case');
            if (btn) {
                btn.disabled = false;
                btn.classList.remove('opacity-50');
            }

            // Reveal highest rarity sound
            const maxTier = Math.max(...allDrops.map(d => d.rarity_tier || 1));
            if (window.soundEngine) window.soundEngine.playItemReveal(maxTier);

            // Confetti for covert or gold
            if (maxTier >= 4 && window.confetti) {
                window.confetti({
                    particleCount: maxTier === 5 ? 180 : 90,
                    spread: 80,
                    origin: { y: 0.6 }
                });
            }

            this.displayUnboxResult(allDrops);

        }, maxDuration * 1000 + 120);
    }

    startRouletteTickAudio(totalDuration, totalDistance, cardWidth = null) {
        if (!window.soundEngine || this.soundMuted) return;
        const cardW = cardWidth || this.getCardWidth();

        let startTime = performance.now();
        let lastCardPassed = 0;

        const checkTick = (currentTime) => {
            if (!this.isSpinning) return;
            const elapsed = (currentTime - startTime) / 1000;
            if (elapsed >= totalDuration) return;

            // Approximate cubic bezier progress
            const t = elapsed / totalDuration;
            const progress = 1 - Math.pow(1 - t, 3);
            const currentX = progress * totalDistance;
            const cardPassed = Math.floor(currentX / cardW);

            if (cardPassed > lastCardPassed) {
                lastCardPassed = cardPassed;
                const pitch = Math.max(0.6, 1.2 - (progress * 0.5));
                window.soundEngine.playTick(pitch);
            }

            requestAnimationFrame(checkTick);
        };

        requestAnimationFrame(checkTick);
    }

    createDropCardHtml(item, includeSellButton = false) {
        const safeWeapon = (item.weapon || '').replace(/'/g, "\\'");
        const safeSkin = (item.skin_name || '').replace(/'/g, "\\'");
        const safeItemJson = JSON.stringify(item).replace(/"/g, '&quot;');
        const floatPct = Math.min(100, Math.max(0, (item.float_value || 0) * 100));

        return `
            <div class="skin-card p-3 flex flex-col items-center justify-between text-center rarity-${item.rarity_tier} animate-reveal bg-[#101520] relative group rounded-xl border border-csBorder">
                <div class="w-full flex items-center justify-between text-[10px] font-mono">
                    ${item.is_stattrak ? '<span class="badge-stattrak text-[9px]">ST™</span>' : '<span></span>'}
                    <span class="text-green-400 font-bold">$${(item.value || 0).toFixed(2)}</span>
                </div>
                
                <div class="cursor-pointer my-2 w-full flex items-center justify-center" onclick="app.inspectSkin(${safeItemJson})">
                    <img src="${item.image}" alt="${item.skin_name}" class="h-20 object-contain drop-shadow transition-transform group-hover:scale-105" onerror="this.onerror=null; this.src=getFallbackWeaponSvg('${safeWeapon}', '${safeSkin}', ${item.rarity_tier});">
                </div>

                <div class="w-full">
                    <div class="text-[11px] text-slate-400 font-tactical truncate">${item.weapon}</div>
                    <div class="text-xs font-tactical font-bold truncate text-white" title="${item.skin_name}">${item.skin_name}</div>
                    <div class="flex items-center justify-between text-[10px] font-mono text-slate-400 mt-1">
                        <span>${item.wear_name || 'FN'}</span>
                        <span>${(item.float_value || 0).toFixed(4)}</span>
                    </div>
                    <div class="float-gauge-track mt-1" style="height: 4px;">
                        <div class="float-gauge-marker" style="left: ${floatPct}%; height: 8px; top: -2px;"></div>
                    </div>
                </div>

                <div class="w-full mt-2.5 pt-2 border-t border-csBorder/60 flex items-center gap-1.5">
                    <button onclick="app.inspectSkin(${safeItemJson})" class="py-1 px-2 text-xs bg-blue-500/20 hover:bg-blue-500 hover:text-black text-blue-300 font-tactical font-bold rounded transition flex items-center justify-center gap-1 border border-blue-500/30" title="Soi 3D vũ khí">
                        <i class="fa-solid fa-cube"></i> Soi 3D
                    </button>
                    ${includeSellButton ? `
                        <button id="multi-item-sell-btn-${item.inventory_id}" onclick="app.sellSingleFromMulti(${item.inventory_id}, ${item.value}, this)" class="flex-1 py-1 text-xs bg-[#1a2230] hover:bg-csOrange hover:text-black text-slate-200 font-tactical font-bold rounded transition flex items-center justify-center gap-1 border border-csBorder">
                            <i class="fa-solid fa-coins text-amber-400"></i> Bán $${(item.value || 0).toFixed(2)}
                        </button>
                    ` : ''}
                </div>

                <div class="skin-card-bottom-bar bg-rarity-${item.rarity_tier} mt-2"></div>
            </div>
        `;
    }

    displayUnboxResult(allDrops) {
        // Populate inline multi-drop section if it exists
        const container = document.getElementById('multi-drop-container');
        const grid = document.getElementById('multi-drop-grid');
        if (container && grid) {
            if (allDrops.length > 1) {
                container.classList.remove('hidden');
                grid.innerHTML = allDrops.map(item => this.createDropCardHtml(item, false)).join('');
            } else {
                container.classList.add('hidden');
            }
        }

        if (allDrops.length === 1) {
            // Single modal reveal
            this.openRevealModal(allDrops[0]);
        } else {
            // Multi-drop modal reveal showing ALL items side by side
            this.openMultiRevealModal(allDrops);
        }
    }

    // ==========================================
    // REVEAL MODAL & ITEM INSPECTION
    // ==========================================

    openRevealModal(item) {
        this.revealedItem = item;
        const modal = document.getElementById('reveal-modal');
        const card = document.getElementById('reveal-modal-card');

        // Populate fields
        document.getElementById('reveal-weapon').textContent = item.weapon;
        document.getElementById('reveal-skin-name').textContent = item.skin_name;
        const revImg = document.getElementById('reveal-image');
        revImg.src = item.image;
        revImg.onerror = () => {
            revImg.onerror = null;
            revImg.src = getFallbackWeaponSvg(item.weapon, item.skin_name, item.rarity_tier);
        };
        document.getElementById('reveal-wear-name').textContent = item.wear_name;
        document.getElementById('reveal-float-val').textContent = (item.float_value || 0).toFixed(6);
        document.getElementById('reveal-value').textContent = `$${(item.value || 0).toFixed(2)}`;
        document.getElementById('reveal-sell-val').textContent = `$${(item.value || 0).toFixed(2)}`;

        // StatTrak badge
        const stBadge = document.getElementById('reveal-stattrak-badge');
        if (item.is_stattrak) {
            stBadge.classList.remove('hidden');
        } else {
            stBadge.classList.add('hidden');
        }

        // Rarity badge
        const rarityBadge = document.getElementById('reveal-rarity-badge');
        rarityBadge.textContent = item.rarity_name || 'Rarity';
        rarityBadge.className = `font-tactical text-xs font-bold px-2.5 py-0.5 rounded uppercase bg-rarity-${item.rarity_tier} text-black`;

        // Card border & glow
        card.className = `relative w-full max-w-lg bg-[#131822] border-2 rounded-2xl p-6 shadow-2xl animate-reveal rarity-${item.rarity_tier}`;
        const glow = document.getElementById('reveal-image-glow');
        glow.className = `absolute w-44 h-44 rounded-full filter blur-2xl opacity-40 bg-rarity-${item.rarity_tier}`;

        // Float marker position (0.0 to 1.0 mapped to 0% to 100%)
        const floatPct = Math.min(100, Math.max(0, (item.float_value || 0) * 100));
        document.getElementById('reveal-float-marker').style.left = `${floatPct}%`;

        modal.classList.remove('hidden');
    }

    closeRevealModal() {
        document.getElementById('reveal-modal').classList.add('hidden');
        this.revealedItem = null;
    }

    async sellRevealedItem() {
        if (!this.revealedItem) return;
        const id = this.revealedItem.inventory_id;
        try {
            const res = await fetch(`${API_BASE}/api/inventory/${id}/sell`, {
                method: 'POST',
                headers: { }
            });
            const data = await res.json();
            if (res.ok) {
                if (window.soundEngine) window.soundEngine.playSell();
                this.currentUser.balance = data.new_balance;
                this.updateUserUI();
                this.notify(`Đã bán vật phẩm thu về $${data.amount.toFixed(2)}`, "success");
                this.closeRevealModal();
            } else {
                this.notify(data.detail || "Không thể bán vật phẩm", "error");
            }
        } catch (e) {
            this.notify("Lỗi kết nối", "error");
        }
    }

    // ==========================================
    // MULTI-REVEAL MODAL (x2, x3, x5)
    // ==========================================

    openMultiRevealModal(allDrops) {
        this.currentMultiDrops = [...allDrops];
        const modal = document.getElementById('multi-reveal-modal');
        if (!modal) return;

        // Set counts
        const countEl = document.getElementById('multi-reveal-count');
        if (countEl) countEl.textContent = allDrops.length;
        const bCountEl = document.getElementById('multi-reveal-bottom-count');
        if (bCountEl) bCountEl.textContent = allDrops.length;

        // Financial calculations
        const unitPrice = this.currentCase ? this.currentCase.total_price : 0;
        const totalSpent = unitPrice * allDrops.length;
        const totalWon = allDrops.reduce((acc, it) => acc + (it.value || 0), 0);
        const profit = totalWon - totalSpent;

        const spentEl = document.getElementById('multi-reveal-spent');
        if (spentEl) spentEl.textContent = `$${totalSpent.toFixed(2)}`;

        const valEl = document.getElementById('multi-reveal-value');
        if (valEl) valEl.textContent = `$${totalWon.toFixed(2)}`;

        const profitEl = document.getElementById('multi-reveal-profit');
        if (profitEl) {
            if (profit >= 0) {
                profitEl.textContent = `+$${profit.toFixed(2)} (LÃI)`;
                profitEl.className = "text-green-400 font-bold";
            } else {
                profitEl.textContent = `-$${Math.abs(profit).toFixed(2)} (LỖ)`;
                profitEl.className = "text-red-400 font-bold";
            }
        }

        const sellValEl = document.getElementById('multi-reveal-sell-val');
        if (sellValEl) sellValEl.textContent = `$${totalWon.toFixed(2)}`;

        // Populate items in modal grid
        const grid = document.getElementById('multi-reveal-grid');
        if (grid) {
            if (allDrops.length === 2) {
                grid.className = "flex-1 overflow-y-auto grid grid-cols-1 sm:grid-cols-2 max-w-2xl mx-auto w-full gap-4 p-2";
            } else if (allDrops.length === 3) {
                grid.className = "flex-1 overflow-y-auto grid grid-cols-1 sm:grid-cols-3 max-w-3xl mx-auto w-full gap-3 p-2";
            } else {
                grid.className = "flex-1 overflow-y-auto grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 w-full gap-3 p-1";
            }
            grid.innerHTML = allDrops.map(item => this.createDropCardHtml(item, true)).join('');
        }


        // Enable Sell All button
        const sellAllBtn = document.getElementById('multi-reveal-sell-all-btn');
        if (sellAllBtn) {
            sellAllBtn.disabled = false;
            sellAllBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            sellAllBtn.innerHTML = `<i class="fa-solid fa-coins"></i> BÁN TẤT CẢ LẤY <span id="multi-reveal-sell-val">$${totalWon.toFixed(2)}</span>`;
        }

        modal.classList.remove('hidden');
    }

    closeMultiRevealModal() {
        const modal = document.getElementById('multi-reveal-modal');
        if (modal) modal.classList.add('hidden');
        this.currentMultiDrops = [];
    }

    async sellSingleFromMulti(inventoryId, value, btnEl) {
        if (!this.currentUser) return;
        try {
            btnEl.disabled = true;
            btnEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

            const res = await fetch(`${API_BASE}/api/inventory/${inventoryId}/sell`, {
                method: 'POST',
                headers: { }
            });
            const data = await res.json();
            if (res.ok) {
                if (window.soundEngine) window.soundEngine.playSell();
                this.currentUser.balance = data.new_balance;
                this.updateUserUI();
                this.notify(`Đã bán vật phẩm thu về $${data.amount.toFixed(2)}`, "success");

                btnEl.className = "w-full py-1 text-xs bg-green-900/40 text-green-400 font-tactical font-bold rounded cursor-default border border-green-500/30";
                btnEl.innerHTML = '<i class="fa-solid fa-check"></i> ĐÃ BÁN';

                this.currentMultiDrops = this.currentMultiDrops.filter(it => it.inventory_id !== inventoryId);

                const remainingWon = this.currentMultiDrops.reduce((acc, it) => acc + (it.value || 0), 0);
                const sellValEl = document.getElementById('multi-reveal-sell-val');
                if (sellValEl) sellValEl.textContent = `$${remainingWon.toFixed(2)}`;

                const sellAllBtn = document.getElementById('multi-reveal-sell-all-btn');
                if (sellAllBtn) {
                    if (this.currentMultiDrops.length === 0) {
                        sellAllBtn.disabled = true;
                        sellAllBtn.classList.add('opacity-50', 'cursor-not-allowed');
                        sellAllBtn.innerHTML = '<i class="fa-solid fa-check"></i> ĐÃ BÁN HẾT';
                    } else {
                        sellAllBtn.innerHTML = `<i class="fa-solid fa-coins"></i> BÁN TẤT CẢ LẤY <span id="multi-reveal-sell-val">$${remainingWon.toFixed(2)}</span>`;
                    }
                }
            } else {
                btnEl.disabled = false;
                btnEl.innerHTML = `<i class="fa-solid fa-coins text-amber-400"></i> Bán $${value.toFixed(2)}`;
                this.notify(data.detail || "Không thể bán vật phẩm", "error");
            }
        } catch (e) {
            btnEl.disabled = false;
            btnEl.innerHTML = `<i class="fa-solid fa-coins text-amber-400"></i> Bán $${value.toFixed(2)}`;
            this.notify("Lỗi kết nối", "error");
        }
    }

    async sellMultiRevealedBatch() {
        if (!this.currentMultiDrops || !this.currentMultiDrops.length) {
            this.notify("Không còn vật phẩm nào để bán trong đợt này", "info");
            return;
        }

        const sellAllBtn = document.getElementById('multi-reveal-sell-all-btn');
        if (sellAllBtn) {
            sellAllBtn.disabled = true;
            sellAllBtn.classList.add('opacity-50');
            sellAllBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Đang bán tất cả...';
        }

        let totalSold = 0;
        let countSold = 0;
        const itemsToSell = [...this.currentMultiDrops];

        for (const item of itemsToSell) {
            try {
                const res = await fetch(`${API_BASE}/api/inventory/${item.inventory_id}/sell`, {
                    method: 'POST',
                    headers: { }
                });
                if (res.ok) {
                    const data = await res.json();
                    totalSold += data.amount;
                    countSold++;
                    this.currentUser.balance = data.new_balance;

                    const itemBtn = document.getElementById(`multi-item-sell-btn-${item.inventory_id}`);
                    if (itemBtn) {
                        itemBtn.disabled = true;
                        itemBtn.className = "w-full py-1 text-xs bg-green-900/40 text-green-400 font-tactical font-bold rounded cursor-default border border-green-500/30";
                        itemBtn.innerHTML = '<i class="fa-solid fa-check"></i> ĐÃ BÁN';
                    }
                }
            } catch (e) {
                console.error("Sell error for item:", item.inventory_id, e);
            }
        }

        this.currentMultiDrops = [];
        this.updateUserUI();

        if (countSold > 0) {
            if (window.soundEngine) window.soundEngine.playSell();
            this.notify(`Đã bán toàn bộ ${countSold} vật phẩm thu về $${totalSold.toFixed(2)}!`, "success");
        }

        if (sellAllBtn) {
            sellAllBtn.disabled = true;
            sellAllBtn.classList.add('opacity-50', 'cursor-not-allowed');
            sellAllBtn.innerHTML = '<i class="fa-solid fa-check"></i> ĐÃ BÁN HẾT';
        }

        setTimeout(() => {
            this.closeMultiRevealModal();
        }, 1200);
    }


    // ==========================================
    // INVENTORY MANAGEMENT
    // ==========================================

    async loadInventory(rarityFilter = null, sortBy = 'date_desc') {
        if (!this.currentUser) return;
        try {
            let url = `${API_BASE}/api/inventory?sort_by=${sortBy}`;
            if (rarityFilter && rarityFilter !== 'all') {
                url += `&rarity_tier=${rarityFilter}`;
            }

            const res = await fetch(url, {
                headers: { }
            });
            if (res.ok) {
                const data = await res.json();
                this.inventory = data.items;
                document.getElementById('inv-total-value').textContent = `$${data.total_value.toFixed(2)}`;
                this.renderInventoryGrid(this.inventory);
                this.updateNavBadges();
            }
        } catch (err) {
            console.error("Failed to load inventory:", err);
        }
    }

    renderInventoryGrid(items) {
        const grid = document.getElementById('inventory-grid');
        if (!grid) return;

        let displayItems = items;
        if (this.currentWeaponType && this.currentWeaponType !== 'all') {
            const wt = this.currentWeaponType;
            displayItems = items.filter(it => {
                const w = it.weapon.toLowerCase();
                if (wt === 'rifle') return w.includes('ak') || w.includes('m4') || w.includes('awp') || w.includes('galil') || w.includes('famas') || w.includes('sg') || w.includes('aug') || w.includes('ssg') || w.includes('scar') || w.includes('g3');
                if (wt === 'pistol') return w.includes('glock') || w.includes('usp') || w.includes('deagle') || w.includes('desert') || w.includes('p250') || w.includes('five') || w.includes('tec') || w.includes('berettas') || w.includes('r8') || w.includes('cz') || w.includes('p2000');
                if (wt === 'smg') return w.includes('mac') || w.includes('mp9') || w.includes('mp7') || w.includes('mp5') || w.includes('p90') || w.includes('ump') || w.includes('bizon');
                if (wt === 'heavy') return w.includes('nova') || w.includes('xm') || w.includes('mag') || w.includes('sawed') || w.includes('m249') || w.includes('negev');
                if (wt === 'knife') return w.includes('knife') || w.includes('bayonet') || w.includes('daggers') || w.includes('karambit');
                if (wt === 'glove') return w.includes('gloves') || w.includes('wraps');
                return true;
            });
        }

        if (displayItems.length === 0) {
            grid.innerHTML = `
                <div class="col-span-full py-16 text-center text-slate-500">
                    <i class="fa-solid fa-box-open text-4xl mb-3"></i>
                    <div>Không có vật phẩm nào phù hợp với bộ lọc hiện tại.</div>
                </div>
            `;
            return;
        }

        grid.innerHTML = displayItems.map(item => `
            <div class="skin-card p-3 flex flex-col items-center justify-between text-center rarity-${item.rarity_tier}">
                <div class="w-full flex items-center justify-between text-[10px] font-mono">
                    ${item.is_stattrak ? '<span class="badge-stattrak text-[8px]">ST™</span>' : '<span></span>'}
                    <span class="text-green-400 font-bold">$${item.value.toFixed(2)}</span>
                </div>
                <img src="${item.image}" alt="${item.name}" class="h-20 object-contain my-2 drop-shadow cursor-pointer hover:scale-105 transition" onclick="app.inspectInventoryItem(${item.id})" onerror="this.onerror=null; this.src=getFallbackWeaponSvg('${item.weapon.replace(/'/g, "\\'")}', '${item.skin_name.replace(/'/g, "\\'")}', ${item.rarity_tier});">
                <div class="w-full">
                    <div class="text-[11px] text-slate-400 font-tactical truncate">${item.weapon}</div>
                    <div class="text-xs font-tactical font-bold truncate text-white">${item.skin_name}</div>
                    <div class="text-[10px] text-slate-400 font-mono mt-0.5">${item.wear_name} (${item.float_value.toFixed(4)})</div>
                </div>
                <div class="w-full mt-2.5 pt-2 border-t border-csBorder flex items-center justify-between gap-1">
                    <button onclick="app.inspectInventoryItem(${item.id})" class="px-2 py-0.5 bg-blue-500/10 hover:bg-blue-500 hover:text-white text-blue-400 text-[10px] font-tactical font-bold rounded border border-blue-500/30 transition flex items-center gap-1" title="Soi 3D vũ khí">
                        <i class="fa-solid fa-cube"></i> SOI 3D
                    </button>
                    <button onclick="app.sellInventoryItem(${item.id})" class="px-2 py-0.5 bg-green-500/10 hover:bg-green-500 hover:text-black text-green-400 text-[10px] font-tactical font-bold rounded border border-green-500/30 transition">
                        BÁN $${item.value.toFixed(2)}
                    </button>
                </div>
                <div class="skin-card-bottom-bar bg-rarity-${item.rarity_tier} mt-2"></div>
            </div>
        `).join('');
    }

    filterInventory(tier) {
        ['all', 1, 2, 3, 4, 5].forEach(t => {
            const btn = document.getElementById(`inv-filter-${t}`);
            if (btn) {
                if (t === tier) {
                    btn.className = "px-2.5 py-1 rounded bg-csOrange text-black font-bold";
                } else {
                    btn.className = "px-2.5 py-1 rounded bg-[#1a2230] text-slate-300 hover:bg-slate-700";
                }
            }
        });
        const sortVal = document.getElementById('inv-sort-select').value;
        this.loadInventory(tier, sortVal);
    }

    sortInventory(sortBy) {
        this.loadInventory(null, sortBy);
    }

    async sellInventoryItem(id) {
        try {
            const res = await fetch(`${API_BASE}/api/inventory/${id}/sell`, {
                method: 'POST',
                headers: { }
            });
            const data = await res.json();
            if (res.ok) {
                if (window.soundEngine) window.soundEngine.playSell();
                this.currentUser.balance = data.new_balance;
                this.updateUserUI();
                this.notify(`Đã bán với giá $${data.amount.toFixed(2)}`, "success");
                this.loadInventory();
            }
        } catch (e) {
            this.notify("Không thể bán", "error");
        }
    }

    async executeSellAll() {
        if (!confirm("Bạn có chắc chắn muốn bán tất cả skin trong kho đồ?")) return;
        try {
            const res = await fetch(`${API_BASE}/api/inventory/sell-all`, {
                method: 'POST',
                headers: { }
            });
            const data = await res.json();
            if (res.ok) {
                if (window.soundEngine) window.soundEngine.playSell();
                this.currentUser.balance = data.new_balance;
                this.updateUserUI();
                this.notify(`Đã bán ${data.sold_count} vật phẩm, nhận $${data.amount.toFixed(2)}!`, "success");
                this.loadInventory();
            }
        } catch (e) {
            this.notify("Lỗi khi bán tất cả", "error");
        }
    }

    inspectInventoryItem(id) {
        const item = this.inventory.find(i => i.id === id);
        if (item) {
            this.inspectSkin(item);
        }
    }

    updateNavBadges() {
        const badge = document.getElementById('nav-inventory-badge');
        if (badge) {
            if (this.inventory.length > 0) {
                badge.textContent = this.inventory.length;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }
    }

    // ==========================================
    // TRADE-UP CONTRACT (HỢP ĐỒNG NÂNG CẤP)
    // ==========================================

    async setupTradeUpView() {
        await this.loadInventory();
        this.tradeUpSelected = [];
        this.renderTradeUpSlots();
        this.renderTradeUpInventory();
    }

    renderTradeUpSlots() {
        const slotsGrid = document.getElementById('tradeup-slots');
        if (!slotsGrid) return;

        let html = '';
        for (let i = 0; i < 10; i++) {
            const item = this.tradeUpSelected[i];
            if (item) {
                html += `
                    <div class="h-28 bg-[#1a2230] border border-csOrange rounded-lg p-2 flex flex-col items-center justify-between text-center relative cursor-pointer" onclick="app.removeTradeUpItem(${item.id})">
                        <span class="text-[9px] text-slate-400 font-mono">${item.weapon}</span>
                        <img src="${item.image}" alt="${item.skin_name}" class="h-12 object-contain" onerror="this.onerror=null; this.src=getFallbackWeaponSvg('${item.weapon.replace(/'/g, "\\'")}', '${item.skin_name.replace(/'/g, "\\'")}', ${item.rarity_tier});">
                        <div class="text-[10px] font-tactical font-bold text-white truncate w-full">${item.skin_name}</div>
                        <button class="absolute -top-1.5 -right-1.5 w-4 h-4 rounded-full bg-red-500 text-black text-[9px] flex items-center justify-center font-bold">×</button>
                    </div>
                `;
            } else {
                html += `
                    <div class="h-28 bg-[#0b0e14] border-2 border-dashed border-csBorder rounded-lg flex flex-col items-center justify-center text-slate-600 text-xs">
                        <i class="fa-solid fa-plus text-sm mb-1"></i>
                        <span>Slot ${i + 1}</span>
                    </div>
                `;
            }
        }
        slotsGrid.innerHTML = html;

        // Update Counter & Avg Float
        document.getElementById('tradeup-selected-count').textContent = this.tradeUpSelected.length;
        const avg = this.tradeUpSelected.length 
            ? (this.tradeUpSelected.reduce((sum, it) => sum + it.float_value, 0) / this.tradeUpSelected.length).toFixed(6)
            : '0.000000';
        document.getElementById('tradeup-avg-float').textContent = avg;

        // Button state
        const btn = document.getElementById('btn-execute-tradeup');
        if (btn) {
            if (this.tradeUpSelected.length === 10) {
                btn.disabled = false;
                btn.className = "px-6 py-2.5 bg-gradient-to-r from-orange-500 to-amber-600 hover:from-orange-400 hover:to-amber-500 text-black font-tactical font-extrabold text-sm rounded-lg shadow-lg shadow-orange-500/30 transition";
            } else {
                btn.disabled = true;
                btn.className = "px-6 py-2.5 bg-slate-700 text-slate-400 font-tactical font-bold text-sm rounded-lg transition";
            }
        }
    }

    renderTradeUpInventory() {
        const list = document.getElementById('tradeup-inventory-list');
        if (!list) return;

        // Filter eligible items (cannot trade up tier 5 knives/gloves)
        let eligible = this.inventory.filter(i => i.rarity_tier < 5 && !this.tradeUpSelected.some(sel => sel.id === i.id));

        // If at least one item is selected, restrict others to same tier!
        if (this.tradeUpSelected.length > 0) {
            const requiredTier = this.tradeUpSelected[0].rarity_tier;
            eligible = eligible.filter(i => i.rarity_tier === requiredTier);
        }

        if (eligible.length === 0) {
            list.innerHTML = `<div class="py-12 text-center text-slate-500 text-xs">Không có skin hợp lệ cùng bậc để thêm vào hợp đồng.</div>`;
            return;
        }

        list.innerHTML = eligible.map(item => `
            <div class="p-2 bg-[#0b0e14] hover:bg-[#1a2230] border border-csBorder rounded-lg flex items-center justify-between cursor-pointer transition" onclick="app.addTradeUpItem(${item.id})">
                <div class="flex items-center space-x-2">
                    <img src="${item.image}" alt="${item.name}" class="w-10 h-8 object-contain" onerror="this.onerror=null; this.src=getFallbackWeaponSvg('${item.weapon.replace(/'/g, "\\'")}', '${item.skin_name.replace(/'/g, "\\'")}', ${item.rarity_tier});">
                    <div>
                        <div class="text-xs font-tactical font-bold text-white truncate max-w-[130px]">${item.skin_name}</div>
                        <div class="text-[10px] text-slate-400 font-mono">${item.weapon} | Float: ${item.float_value.toFixed(4)}</div>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-xs font-mono text-green-400 font-bold">$${item.value.toFixed(2)}</div>
                    <span class="text-[9px] px-1.5 py-0.2 rounded uppercase bg-rarity-${item.rarity_tier} text-black font-bold">Tier ${item.rarity_tier}</span>
                </div>
            </div>
        `).join('');
    }

    addTradeUpItem(id) {
        if (this.tradeUpSelected.length >= 10) return;
        const item = this.inventory.find(i => i.id === id);
        if (!item) return;

        if (this.tradeUpSelected.length > 0 && this.tradeUpSelected[0].rarity_tier !== item.rarity_tier) {
            this.notify("Tất cả 10 skin phải cùng một phẩm cấp!", "error");
            return;
        }

        this.tradeUpSelected.push(item);
        this.renderTradeUpSlots();
        this.renderTradeUpInventory();
    }

    removeTradeUpItem(id) {
        this.tradeUpSelected = this.tradeUpSelected.filter(i => i.id !== id);
        this.renderTradeUpSlots();
        this.renderTradeUpInventory();
    }

    clearTradeUp() {
        this.tradeUpSelected = [];
        this.renderTradeUpSlots();
        this.renderTradeUpInventory();
    }

    async executeTradeUp() {
        if (this.tradeUpSelected.length !== 10) return;

        try {
            const res = await fetch(`${API_BASE}/api/tradeup`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ inventory_ids: this.tradeUpSelected.map(i => i.id) })
            });

            const data = await res.json();
            if (res.ok) {
                if (window.soundEngine) window.soundEngine.playTradeup();
                if (window.confetti) window.confetti({ particleCount: 120, spread: 70 });
                this.notify("Hợp đồng nâng cấp thành công!", "success");
                this.tradeUpSelected = [];
                this.setupTradeUpView();
                this.openRevealModal(data.reward);
            } else {
                this.notify(data.detail || "Thất bại khi ký hợp đồng", "error");
            }
        } catch (e) {
            this.notify("Lỗi kết nối", "error");
        }
    }

    // ==========================================
    // PROVABLY FAIR VERIFIER
    // ==========================================

    async executeVerifyRoll() {
        const serverSeed = document.getElementById('verify-server-seed').value.trim();
        const clientSeed = document.getElementById('verify-client-seed').value.trim();
        const nonce = parseInt(document.getElementById('verify-nonce').value) || 1;

        if (!serverSeed || !clientSeed) {
            this.notify("Vui lòng nhập đầy đủ Server Seed và Client Seed", "error");
            return;
        }

        try {
            const res = await fetch(`${API_BASE}/api/cases/verify-roll`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ server_seed: serverSeed, client_seed: clientSeed, nonce })
            });
            const data = await res.json();
            if (res.ok) {
                const box = document.getElementById('verify-result-box');
                box.classList.remove('hidden');
                document.getElementById('res-roll-number').textContent = data.roll_number;
                document.getElementById('res-rarity').textContent = data.rarity_desc;
                document.getElementById('res-stattrak').textContent = data.is_stattrak ? "Có (True 10%)" : "Không (False 90%)";
                document.getElementById('res-float').textContent = data.raw_float;
                document.getElementById('res-wear').textContent = data.wear_condition;
            }
        } catch (e) {
            this.notify("Lỗi khi kiểm tra mã hóa", "error");
        }
    }

    // ==========================================
    // ADMIN DASHBOARD & MONITORING
    // ==========================================
    // 3D WEAPON SPEC / INSPECT SYSTEM
    // ==========================================

    inspectCaseSkin(skinId) {
        let skin = null;
        if (this.currentCase && this.currentCase.skins) {
            skin = this.currentCase.skins.find(s => s.id === skinId);
        }
        if (!skin) {
            this.notify("Không tìm thấy thông tin skin để soi 3D", "error");
            return;
        }

        // Prepare inspect payload with standard preview float
        const inspectPayload = {
            id: skin.id,
            weapon: skin.weapon,
            skin_name: skin.skin_name,
            rarity_name: skin.rarity_name,
            rarity_tier: skin.rarity_tier,
            image: skin.image,
            float_value: 0.03412859,
            wear_name: "Factory New",
            is_stattrak: false,
            value: skin.base_price || 0,
            base_price: skin.base_price || 0
        };
        this.inspectSkin(inspectPayload);
    }

    inspectRevealedSkin() {
        if (!this.revealedItem) return;
        this.inspectSkin(this.revealedItem);
    }

    inspectSkin(skin) {
        if (!skin) return;
        this.currentInspectedSkin = skin;

        const modal = document.getElementById('inspect-modal');
        if (!modal) return;

        // Populate skin details
        const weaponEl = document.getElementById('inspect-weapon-name');
        const skinEl = document.getElementById('inspect-skin-name');
        const priceTag = document.getElementById('inspect-price-tag');
        const marketVal = document.getElementById('inspect-market-value');
        const wearEl = document.getElementById('inspect-wear-text');
        const floatEl = document.getElementById('inspect-float-value');
        const seedEl = document.getElementById('inspect-paint-seed');
        const finishEl = document.getElementById('inspect-finish-style');
        const stBadge = document.getElementById('inspect-stattrak-badge');
        const stCount = document.getElementById('inspect-stattrak-count');
        const rarityBadge = document.getElementById('inspect-rarity-badge');
        const needle = document.getElementById('inspect-float-needle');

        if (weaponEl) weaponEl.textContent = skin.weapon || 'WEAPON';
        if (skinEl) skinEl.textContent = skin.skin_name || 'SKIN';

        const val = (skin.value || skin.base_price || 0).toFixed(2);
        if (priceTag) priceTag.textContent = `$${val}`;
        if (marketVal) marketVal.textContent = `$${val}`;

        const wear = skin.wear_name || 'Factory New';
        if (wearEl) wearEl.textContent = wear;

        const floatVal = skin.float_value !== undefined ? Number(skin.float_value) : 0.03412859;
        if (floatEl) floatEl.textContent = floatVal.toFixed(8);

        // Pattern seed
        const seed = skin.paint_seed || Math.abs(Math.floor((floatVal * 987654) % 1000)) || 421;
        if (seedEl) seedEl.textContent = seed;

        // Finish style
        let finishStyle = "Custom Paint Job";
        if (skin.rarity_tier === 5) finishStyle = "Anodized & Patina";
        else if (skin.rarity_tier === 4) finishStyle = "Gunsmith / Pearlescent";
        else if (skin.rarity_tier === 3) finishStyle = "Patina & Hydrographic";
        else finishStyle = "Solid Color Spray";
        if (finishEl) finishEl.textContent = finishStyle;

        // StatTrak
        const isSt = skin.is_stattrak === true || skin.is_stattrak === 1;
        if (stBadge) {
            if (isSt) stBadge.classList.remove('hidden');
            else stBadge.classList.add('hidden');
        }
        if (stCount) {
            if (isSt) {
                const kills = Math.floor(100 + (floatVal * 3200));
                stCount.textContent = `${kills.toLocaleString()} Kills`;
            } else {
                stCount.textContent = "None";
            }
        }

        // Rarity badge
        if (rarityBadge) {
            rarityBadge.textContent = skin.rarity_name || 'COVERT';
            rarityBadge.className = `font-tactical text-xs font-bold px-2 py-0.5 rounded uppercase bg-rarity-${skin.rarity_tier} text-black`;
        }

        // Float needle position
        if (needle) {
            const pct = Math.min(100, Math.max(0, floatVal * 100));
            needle.style.left = `${pct}%`;
        }

        // Show modal
        modal.classList.remove('hidden');

        // Initialize 3D Engine
        setTimeout(() => {
            if (window.weaponInspector) {
                window.weaponInspector.init('inspect-3d-canvas-container');
                window.weaponInspector.loadSkin(skin);
                this.updateInspectAutoRotateUI(true);
            }
        }, 50);

        // Bind keyboard shortcuts ('F' to inspect flip, 'Escape' to close)
        if (this.inspectKeyHandler) {
            window.removeEventListener('keydown', this.inspectKeyHandler);
        }
        this.inspectKeyHandler = (e) => {
            if (e.key === 'f' || e.key === 'F') {
                this.triggerInspectFlip();
            } else if (e.key === 'Escape') {
                this.closeInspectModal();
            }
        };
        window.addEventListener('keydown', this.inspectKeyHandler);
    }

    closeInspectModal() {
        const modal = document.getElementById('inspect-modal');
        if (modal) modal.classList.add('hidden');

        if (this.inspectKeyHandler) {
            window.removeEventListener('keydown', this.inspectKeyHandler);
            this.inspectKeyHandler = null;
        }

        if (window.weaponInspector) {
            window.weaponInspector.destroy();
        }
        this.currentInspectedSkin = null;
    }

    toggleInspectAutoRotate() {
        if (!window.weaponInspector) return;
        const isRotating = window.weaponInspector.toggleAutoRotate();
        this.updateInspectAutoRotateUI(isRotating);
    }

    updateInspectAutoRotateUI(isRotating) {
        const statusEl = document.getElementById('inspect-autorotate-status');
        if (statusEl) {
            if (isRotating) {
                statusEl.textContent = "BẬT";
                statusEl.className = "text-[9px] text-green-400 font-mono";
            } else {
                statusEl.textContent = "TẮT";
                statusEl.className = "text-[9px] text-slate-500 font-mono";
            }
        }
    }

    triggerInspectFlip() {
        if (!window.weaponInspector) return;
        window.weaponInspector.triggerFlip();
        if (window.soundEngine && typeof window.soundEngine.playTick === 'function') {
            window.soundEngine.playTick(1.3);
        }
    }

    resetInspectView() {
        if (!window.weaponInspector) return;
        window.weaponInspector.resetView();
        this.updateInspectAutoRotateUI(true);
    }

    changeInspectLight(mode) {
        if (!window.weaponInspector) return;
        window.weaponInspector.setLightMode(mode);

        ['studio', 'sunset', 'cyberpunk'].forEach(m => {
            const btn = document.getElementById(`inspect-light-${m}`);
            if (btn) {
                if (m === mode) {
                    btn.className = "px-2 py-1 bg-csOrange text-black text-[10px] font-tactical font-bold rounded transition";
                } else {
                    btn.className = "px-2 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 text-[10px] font-tactical font-bold rounded transition";
                }
            }
        });
    }

    // ==========================================
    // ADMIN DASHBOARD & MONITORING CENTER
    // ==========================================

    switchAdminTab(tabName) {
        this.currentAdminTab = tabName;
        const tabs = ['overview', 'users', 'giftcode', 'cases', 'logs'];

        tabs.forEach(t => {
            const btn = document.getElementById(`adm-nav-btn-${t}`);
            const content = document.getElementById(`adm-subtab-${t}`);

            if (btn) {
                if (t === tabName) {
                    btn.className = "px-3.5 py-2 rounded-lg font-tactical text-xs font-bold transition flex items-center gap-2 bg-csOrange text-black";
                } else {
                    btn.className = "px-3.5 py-2 rounded-lg font-tactical text-xs font-bold transition flex items-center gap-2 bg-[#131822] border border-csBorder text-slate-300 hover:text-white hover:border-csOrange";
                }
            }
            if (content) {
                if (t === tabName) content.classList.remove('hidden');
                else content.classList.add('hidden');
            }
        });

        // Load data corresponding to selected tab
        if (tabName === 'overview') this.loadAdminOverview();
        else if (tabName === 'users') this.loadAdminUsers();
        else if (tabName === 'giftcode') this.loadAdminGiftcode();
        else if (tabName === 'cases') this.loadAdminCases();
        else if (tabName === 'logs') this.fetchAdminLogs();
    }

    async loadAdminOverview() {
        if (!this.currentUser) return;
        try {
            const res = await fetch(`${API_BASE}/api/admin/overview`, {
                headers: { }
            });
            if (res.ok) {
                const data = await res.json();

                // 1. System Health Cards
                const hStatus = document.getElementById('adm-health-status');
                const uptime = document.getElementById('adm-uptime');
                const mem = document.getElementById('adm-memory');
                const lat = document.getElementById('adm-latency');
                const casesOpened = document.getElementById('adm-cases-opened');
                const reqCount = document.getElementById('adm-requests-count');
                const profit = document.getElementById('adm-profit');
                const totalSpent = document.getElementById('adm-total-spent');

                if (hStatus) hStatus.textContent = "HOẠT ĐỘNG TỐT";
                if (uptime) uptime.textContent = `Uptime: ${data.system_health.uptime_formatted}`;
                if (mem) mem.textContent = `${data.system_health.memory_usage_mb} MB`;
                if (lat) lat.textContent = `Độ trễ TB: ${data.system_health.avg_response_time_ms}ms`;
                if (casesOpened) casesOpened.textContent = (data.platform.total_cases_opened || 0).toLocaleString();
                if (reqCount) reqCount.textContent = `Total HTTP: ${data.system_health.total_requests || 0}`;
                if (profit) {
                    const p = data.platform.house_profit_usd || 0;
                    profit.textContent = `$${p.toFixed(2)}`;
                    profit.className = `text-xl font-tactical font-bold mt-1 ${p >= 0 ? 'text-green-400' : 'text-red-400'}`;
                }
                if (totalSpent) totalSpent.textContent = `Doanh thu: $${(data.platform.total_revenue_usd || 0).toFixed(2)}`;

                // 2. Economic Telemetry Cards
                const totalUsers = document.getElementById('adm-total-users');
                const circBal = document.getElementById('adm-circulating-bal');
                const rtp = document.getElementById('adm-rtp-rate');
                const totalPayout = document.getElementById('adm-total-payout');
                const invSkins = document.getElementById('adm-inv-skins-count');
                const soldSkins = document.getElementById('adm-sold-skins-count');
                const tradeups = document.getElementById('adm-tradeup-count');
                const codeRedeems = document.getElementById('adm-code-redeems-count');
                const giftDist = document.getElementById('adm-gift-distributed');

                if (totalUsers) totalUsers.textContent = (data.platform.total_registered_users || 0).toLocaleString();
                if (circBal) circBal.textContent = `$${(data.platform.total_circulating_balance || 0).toFixed(2)}`;
                if (rtp) rtp.textContent = `${data.platform.rtp_percentage}%`;
                if (totalPayout) totalPayout.textContent = `$${(data.platform.total_payout_usd || 0).toFixed(2)}`;
                if (invSkins) invSkins.textContent = (data.platform.active_items_in_inventories || 0).toLocaleString();
                if (soldSkins) soldSkins.textContent = (data.platform.total_skins_sold || 0).toLocaleString();
                if (tradeups) tradeups.textContent = (data.platform.tradeups_completed || 0).toLocaleString();
                if (codeRedeems) codeRedeems.textContent = `${data.platform.total_code_redemptions || 0} lượt`;
                if (giftDist) giftDist.textContent = `$${(data.platform.total_gift_distributed || 0).toFixed(2)}`;

                // 3. Live Hourly Code Status
                const ovCode = document.getElementById('adm-overview-live-code');
                const ovTimer = document.getElementById('adm-overview-code-timer');
                if (ovCode) ovCode.textContent = data.platform.active_hourly_code || '------';
                if (ovTimer) {
                    const secs = data.platform.code_expires_in_secs || 0;
                    const m = Math.floor(secs / 60);
                    const s = secs % 60;
                    ovTimer.textContent = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
                }

            } else {
                this.notify("Bạn cần quyền Quản trị viên (Admin) để xem trang này", "error");
            }
        } catch (e) {
            console.error("Failed to load admin stats:", e);
        }
    }

    // --- USER MANAGEMENT ---

    debounceUserSearch() {
        if (this.userSearchTimeout) clearTimeout(this.userSearchTimeout);
        this.userSearchTimeout = setTimeout(() => {
            this.loadAdminUsers();
        }, 300);
    }

    async loadAdminUsers() {
        if (!this.currentUser) return;
        const tbody = document.getElementById('adm-users-table-body');
        if (!tbody) return;

        const q = (document.getElementById('adm-user-search-input')?.value || '').trim();
        const role = document.getElementById('adm-user-role-select')?.value || '';

        try {
            let url = `${API_BASE}/api/admin/users?limit=100`;
            if (q) url += `&q=${encodeURIComponent(q)}`;
            if (role) url += `&role=${encodeURIComponent(role)}`;

            const res = await fetch(url, {
                headers: { }
            });
            if (res.ok) {
                const data = await res.json();
                const users = data.users || [];

                if (users.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="9" class="py-8 text-center text-slate-500 font-tactical">
                                Không tìm thấy người dùng nào phù hợp.
                            </td>
                        </tr>
                    `;
                    return;
                }

                tbody.innerHTML = users.map(u => {
                    const isAdmin = u.role === 'admin';
                    const roleBadge = isAdmin
                        ? `<span class="px-2 py-0.5 rounded bg-red-500/20 text-red-400 font-mono text-[10px] font-bold border border-red-500/30">ADMIN</span>`
                        : `<span class="px-2 py-0.5 rounded bg-slate-800 text-slate-300 font-mono text-[10px] font-bold border border-slate-700">USER</span>`;

                    const dateStr = u.created_at ? u.created_at.slice(0, 16) : 'N/A';
                    const safeUsername = this.escapeHtml(u.username);
                    const safeEmail = this.escapeHtml(u.email || 'N/A');

                    return `
                        <tr class="hover:bg-slate-800/40 transition">
                            <td class="py-3 px-4 font-mono text-slate-400">#${u.id}</td>
                            <td class="py-3 px-4 font-bold text-white flex items-center gap-2">
                                <i class="fa-solid fa-user-circle text-slate-500 text-sm"></i>
                                <span>${safeUsername}</span>
                            </td>
                            <td class="py-3 px-4 text-slate-400 font-mono text-[11px]">${safeEmail}</td>
                            <td class="py-3 px-4">${roleBadge}</td>
                            <td class="py-3 px-4 text-right font-mono font-bold text-green-400">$${(u.balance || 0).toFixed(2)}</td>
                            <td class="py-3 px-4 text-center font-mono text-csOrange font-bold">${u.cases_opened || 0}</td>
                            <td class="py-3 px-4 text-center font-mono text-purple-400 font-bold">${u.inventory_count || 0}</td>
                            <td class="py-3 px-4 font-mono text-slate-500 text-[11px]">${dateStr}</td>
                            <td class="py-3 px-4 text-center">
                                <div class="flex items-center justify-center gap-1.5">
                                    <button onclick="app.openAdminBalanceModal(${u.id}, '${safeUsername}', ${u.balance || 0})" class="px-2.5 py-1 bg-green-500/10 hover:bg-green-500 hover:text-black border border-green-500/30 text-green-400 font-tactical text-xs font-bold rounded transition" title="Chỉnh số dư">
                                        <i class="fa-solid fa-coins"></i> Tiền
                                    </button>
                                    <button onclick="app.toggleAdminUserRole(${u.id}, '${u.role}')" class="px-2.5 py-1 bg-purple-500/10 hover:bg-purple-500 hover:text-white border border-purple-500/30 text-purple-300 font-tactical text-xs font-bold rounded transition" title="Đổi quyền Admin / User">
                                        <i class="fa-solid fa-shield"></i> Quyền
                                    </button>
                                    <button onclick="app.openAdminUserInventory(${u.id}, '${safeUsername}')" class="px-2.5 py-1 bg-blue-500/10 hover:bg-blue-500 hover:text-white border border-blue-500/30 text-blue-300 font-tactical text-xs font-bold rounded transition" title="Xem kho đồ người dùng">
                                        <i class="fa-solid fa-box-open"></i> Kho
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                }).join('');
            }
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="9" class="py-8 text-center text-red-400">Lỗi khi tải danh sách user</td></tr>`;
        }
    }

    // --- BALANCE ADJUSTMENT MODAL ---

    openAdminBalanceModal(userId, username, currentBalance) {
        this.adminBalanceUserId = userId;
        this.adminBalanceMode = 'set';

        const modal = document.getElementById('admin-user-balance-modal');
        const userEl = document.getElementById('adm-bal-modal-username');
        const curEl = document.getElementById('adm-bal-modal-current');
        const input = document.getElementById('adm-bal-modal-amount');
        const hiddenId = document.getElementById('adm-bal-modal-userid');

        if (userEl) userEl.textContent = username;
        if (curEl) curEl.textContent = `$${Number(currentBalance || 0).toFixed(2)}`;
        if (input) input.value = Number(currentBalance || 0).toFixed(2);
        if (hiddenId) hiddenId.value = userId;

        this.setBalanceAdjustMode('set');
        if (modal) modal.classList.remove('hidden');
    }

    closeAdminBalanceModal() {
        const modal = document.getElementById('admin-user-balance-modal');
        if (modal) modal.classList.add('hidden');
        this.adminBalanceUserId = null;
    }

    setBalanceAdjustMode(mode) {
        this.adminBalanceMode = mode;
        const btnSet = document.getElementById('adm-bal-mode-set');
        const btnAdd = document.getElementById('adm-bal-mode-add');
        const lbl = document.getElementById('adm-bal-modal-amount-label');

        if (mode === 'set') {
            if (btnSet) btnSet.className = "py-2 rounded-lg font-tactical text-xs font-bold bg-csOrange text-black";
            if (btnAdd) btnAdd.className = "py-2 rounded-lg font-tactical text-xs font-bold bg-[#0b0e14] border border-csBorder text-slate-300";
            if (lbl) lbl.textContent = "SỐ TIỀN MỚI THIẾT LẬP ($):";
        } else {
            if (btnSet) btnSet.className = "py-2 rounded-lg font-tactical text-xs font-bold bg-[#0b0e14] border border-csBorder text-slate-300";
            if (btnAdd) btnAdd.className = "py-2 rounded-lg font-tactical text-xs font-bold bg-green-500 text-black";
            if (lbl) lbl.textContent = "SỐ TIỀN CỘNG THÊM VÀO VÍ ($):";
        }
    }

    async submitUserBalanceAdjustment(e) {
        e.preventDefault();
        if (!this.adminBalanceUserId) return;

        const amountInput = document.getElementById('adm-bal-modal-amount');
        const amount = parseFloat(amountInput?.value || 0);

        if (isNaN(amount) || amount < 0) {
            this.notify("Số tiền không hợp lệ!", "error");
            return;
        }

        try {
            const res = await fetch(`${API_BASE}/api/admin/users/${this.adminBalanceUserId}/balance`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    amount: amount,
                    mode: this.adminBalanceMode || 'set'
                })
            });
            const data = await res.json();
            if (res.ok) {
                this.notify(data.message, "success");
                this.closeAdminBalanceModal();

                // If updating own balance, update local user UI
                if (this.currentUser && this.currentUser.id === this.adminBalanceUserId) {
                    this.currentUser.balance = data.new_balance;
                    this.updateUserUI();
                }

                this.loadAdminUsers();
                this.loadAdminOverview();
            } else {
                this.notify(data.detail || "Không thể cập nhật số dư", "error");
            }
        } catch (err) {
            this.notify("Lỗi kết nối máy chủ", "error");
        }
    }

    async toggleAdminUserRole(userId, currentRole) {
        const newRole = currentRole === 'admin' ? 'user' : 'admin';
        const roleLabel = newRole === 'admin' ? 'QUẢN TRỊ VIÊN (ADMIN)' : 'NGƯỜI DÙNG (USER)';

        if (!confirm(`Bạn có chắc chắn muốn thay đổi quyền của tài khoản này thành ${roleLabel}?`)) {
            return;
        }

        try {
            const res = await fetch(`${API_BASE}/api/admin/users/${userId}/role`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ role: newRole })
            });
            const data = await res.json();
            if (res.ok) {
                this.notify(data.message, "success");
                this.loadAdminUsers();
            } else {
                this.notify(data.detail || "Không thể thay đổi quyền", "error");
            }
        } catch (err) {
            this.notify("Lỗi kết nối máy chủ", "error");
        }
    }

    // --- USER INVENTORY INSPECT MODAL ---

    async openAdminUserInventory(userId, username) {
        const modal = document.getElementById('admin-user-inventory-modal');
        const userTitle = document.getElementById('adm-inv-modal-username');
        const countTitle = document.getElementById('adm-inv-modal-count');
        const grid = document.getElementById('adm-inv-modal-grid');

        if (userTitle) userTitle.textContent = username;
        if (countTitle) countTitle.textContent = "0";
        if (grid) grid.innerHTML = `<div class="col-span-full py-8 text-center text-slate-500">Đang tải kho đồ...</div>`;
        if (modal) modal.classList.remove('hidden');

        try {
            const res = await fetch(`${API_BASE}/api/admin/users/${userId}/inventory`, {
                headers: { }
            });
            if (res.ok) {
                const data = await res.json();
                const items = data.items || [];
                if (countTitle) countTitle.textContent = items.length;

                if (items.length === 0) {
                    if (grid) grid.innerHTML = `<div class="col-span-full py-12 text-center text-slate-500">Người dùng này chưa có vật phẩm nào trong kho.</div>`;
                    return;
                }

                if (grid) {
                    grid.innerHTML = items.map(item => {
                        const safeItemJson = JSON.stringify(item).replace(/"/g, '&quot;');
                        const safeWeapon = (item.weapon || '').replace(/'/g, "\\'");
                        const safeSkin = (item.skin_name || '').replace(/'/g, "\\'");

                        return `
                            <div class="skin-card p-2.5 flex flex-col items-center justify-between text-center rarity-${item.rarity_tier} rounded-lg bg-[#0b0e14] border border-csBorder">
                                <div class="w-full flex items-center justify-between text-[10px] font-mono">
                                    ${item.is_stattrak ? '<span class="badge-stattrak text-[8px]">ST™</span>' : '<span></span>'}
                                    <span class="text-green-400 font-bold">$${(item.value || 0).toFixed(2)}</span>
                                </div>
                                <img src="${item.image}" alt="${item.name}" class="h-16 object-contain my-1 drop-shadow" onerror="this.onerror=null; this.src=getFallbackWeaponSvg('${safeWeapon}', '${safeSkin}', ${item.rarity_tier});">
                                <div class="w-full">
                                    <div class="text-[10px] text-slate-400 font-tactical truncate">${item.weapon}</div>
                                    <div class="text-[11px] font-tactical font-bold truncate text-white">${item.skin_name}</div>
                                    <div class="text-[9px] text-slate-400 font-mono mt-0.5">${item.wear_name} (${(item.float_value || 0).toFixed(4)})</div>
                                </div>
                                <div class="w-full mt-2 pt-1 border-t border-csBorder/50">
                                    <button onclick="app.inspectSkin(${safeItemJson})" class="w-full py-0.5 bg-blue-500/20 hover:bg-blue-500 hover:text-white text-blue-300 font-tactical text-[10px] font-bold rounded transition flex items-center justify-center gap-1 border border-blue-500/30">
                                        <i class="fa-solid fa-cube text-[9px]"></i> Soi 3D
                                    </button>
                                </div>
                                <div class="skin-card-bottom-bar bg-rarity-${item.rarity_tier} mt-1.5"></div>
                            </div>
                        `;
                    }).join('');
                }
            }
        } catch (e) {
            if (grid) grid.innerHTML = `<div class="col-span-full py-8 text-center text-red-400">Lỗi khi tải kho đồ người dùng</div>`;
        }
    }

    closeAdminUserInventoryModal() {
        const modal = document.getElementById('admin-user-inventory-modal');
        if (modal) modal.classList.add('hidden');
    }

    // --- GIFTCODE MANAGER ---

    async loadAdminGiftcode() {
        if (!this.currentUser) return;
        try {
            const res = await fetch(`${API_BASE}/api/admin/giftcode`, {
                headers: { }
            });
            if (res.ok) {
                const data = await res.json();
                const codeEl = document.getElementById('adm-gift-live-code');
                const timerEl = document.getElementById('adm-gift-timer');
                const redeemsEl = document.getElementById('adm-gift-current-redeems');
                const totalPaidEl = document.getElementById('adm-gift-current-total-paid');
                const tbody = document.getElementById('adm-gift-redemptions-tbody');

                if (codeEl) codeEl.textContent = data.active_code.code || '------';
                if (timerEl) {
                    const secs = data.active_code.seconds_left || 0;
                    const m = Math.floor(secs / 60);
                    const s = secs % 60;
                    timerEl.textContent = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
                }
                if (redeemsEl) redeemsEl.textContent = `${data.current_code_redemptions || 0} lượt`;
                if (totalPaidEl) totalPaidEl.textContent = `$${(data.current_code_total_paid || 0).toFixed(2)}`;

                const inputReward = document.getElementById('adm-gift-reward-input');
                if (inputReward && data.active_code.reward_amount) {
                    inputReward.value = data.active_code.reward_amount;
                }

                // Render recent redemptions table
                if (tbody) {
                    const recents = data.recent_redemptions || [];
                    if (recents.length === 0) {
                        tbody.innerHTML = `<tr><td colspan="5" class="py-6 text-center text-slate-500 font-tactical">Chưa có lượt nạp nào được ghi nhận.</td></tr>`;
                    } else {
                        tbody.innerHTML = recents.map(r => `
                            <tr class="hover:bg-slate-800/40 transition">
                                <td class="py-2.5 px-3 font-mono font-bold text-csGold tracking-wider">${r.code}</td>
                                <td class="py-2.5 px-3 font-bold text-white">${this.escapeHtml(r.username)}</td>
                                <td class="py-2.5 px-3 font-mono text-slate-400 text-[11px]">${this.escapeHtml(r.email || 'N/A')}</td>
                                <td class="py-2.5 px-3 text-right font-mono font-bold text-green-400">+$${Number(r.amount || 100).toFixed(2)}</td>
                                <td class="py-2.5 px-3 text-right font-mono text-slate-500 text-[11px]">${r.redeemed_at ? r.redeemed_at.slice(0, 16) : 'N/A'}</td>
                            </tr>
                        `).join('');
                    }
                }
            }
        } catch (e) {
            console.error("Failed to load admin giftcode:", e);
        }
    }

    async adminForceNewCode() {
        if (!confirm("Bạn có chắc chắn muốn hủy mã hiện tại và tạo mã Giftcode 6 chữ số mới ngay bây giờ?")) {
            return;
        }

        try {
            const res = await fetch(`${API_BASE}/api/admin/giftcode/generate`, {
                method: 'POST',
                headers: { }
            });
            const data = await res.json();
            if (res.ok) {
                this.notify(data.message, "success");
                this.loadAdminGiftcode();
                this.loadAdminOverview();
                // If user is currently looking at deposit modal, update it too
                this.fetchActiveHourlyCode();
            } else {
                this.notify(data.detail || "Không thể tạo mã mới", "error");
            }
        } catch (err) {
            this.notify("Lỗi kết nối máy chủ", "error");
        }
    }

    async submitGiftRewardUpdate(e) {
        e.preventDefault();
        const input = document.getElementById('adm-gift-reward-input');
        const amount = parseFloat(input?.value || 100);

        if (isNaN(amount) || amount <= 0) {
            this.notify("Vui lòng nhập giá trị hợp lệ (> $0)", "error");
            return;
        }

        try {
            const res = await fetch(`${API_BASE}/api/admin/giftcode/reward`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ reward_amount: amount })
            });
            const data = await res.json();
            if (res.ok) {
                this.notify(data.message, "success");
                this.loadAdminGiftcode();
            } else {
                this.notify(data.detail || "Không thể đổi phần thưởng", "error");
            }
        } catch (err) {
            this.notify("Lỗi kết nối máy chủ", "error");
        }
    }

    // --- CASE & ECONOMY MANAGER ---

    async loadAdminCases() {
        if (!this.currentUser) return;
        const tbody = document.getElementById('adm-cases-table-body');
        if (!tbody) return;

        try {
            const res = await fetch(`${API_BASE}/api/admin/cases`, {
                headers: { }
            });
            if (res.ok) {
                const data = await res.json();
                this.adminCasesList = data.cases || [];
                this.renderAdminCasesRows(this.adminCasesList);
            }
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="7" class="py-8 text-center text-red-400">Lỗi khi tải danh sách rương</td></tr>`;
        }
    }

    filterAdminCases(query) {
        if (!this.adminCasesList) return;
        const q = (query || '').toLowerCase().trim();
        const filtered = this.adminCasesList.filter(c => c.name.toLowerCase().includes(q) || c.id.toLowerCase().includes(q));
        this.renderAdminCasesRows(filtered);
    }

    renderAdminCasesRows(cases) {
        const tbody = document.getElementById('adm-cases-table-body');
        if (!tbody) return;

        if (cases.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="py-8 text-center text-slate-500 font-tactical">Không tìm thấy rương nào.</td></tr>`;
            return;
        }

        tbody.innerHTML = cases.map(c => {
            const isActive = c.is_active === 1 || c.is_active === true;
            const safeName = this.escapeHtml(c.name);

            return `
                <tr class="hover:bg-slate-800/40 transition">
                    <td class="py-3 px-4">
                        <img src="${c.image}" alt="${safeName}" class="h-10 w-10 object-contain drop-shadow" onerror="this.onerror=null; this.src=getFallbackCrateSvg('${c.name.replace(/'/g, "\\'")}');">
                    </td>
                    <td class="py-3 px-4 font-bold text-white">
                        <div>${safeName}</div>
                        <div class="text-[10px] text-slate-500 font-mono">${c.id}</div>
                    </td>
                    <td class="py-3 px-4 text-center font-mono text-purple-400 font-bold">${c.total_items || 0}</td>
                    <td class="py-3 px-4 text-center font-mono text-csOrange font-bold">${(c.total_opened || 0).toLocaleString()}</td>
                    <td class="py-3 px-4 text-center">
                        <div class="flex items-center justify-center gap-1">
                            <span class="text-xs font-mono text-slate-400">$</span>
                            <input type="number" id="adm-case-price-${c.id}" step="0.10" min="1.0" max="100.0" value="${(c.price || 5.0).toFixed(2)}" class="w-20 bg-[#0b0e14] border border-csBorder px-2 py-1 rounded text-center font-mono text-white text-xs focus:outline-none focus:border-csOrange">
                        </div>
                    </td>
                    <td class="py-3 px-4 text-center">
                        <label class="inline-flex items-center cursor-pointer">
                            <input type="checkbox" id="adm-case-active-${c.id}" ${isActive ? 'checked' : ''} class="sr-only peer">
                            <div class="w-8 h-4 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-green-500"></div>
                            <span class="ml-2 text-[10px] font-mono ${isActive ? 'text-green-400' : 'text-slate-500'}">${isActive ? 'BẬT' : 'ẨN'}</span>
                        </label>
                    </td>
                    <td class="py-3 px-4 text-center">
                        <button onclick="app.updateAdminCasePrice('${c.id}')" class="px-3 py-1 bg-csOrange hover:bg-amber-400 text-black font-tactical font-black text-xs rounded transition flex items-center justify-center gap-1 mx-auto">
                            <i class="fa-solid fa-floppy-disk text-[10px]"></i> Lưu
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    async updateAdminCasePrice(caseId) {
        const priceInput = document.getElementById(`adm-case-price-${caseId}`);
        const activeCheck = document.getElementById(`adm-case-active-${caseId}`);
        const price = parseFloat(priceInput?.value || 0);
        const isActive = activeCheck?.checked ? 1 : 0;

        if (isNaN(price) || price < 1.0 || price > 100.0) {
            this.notify("Giá rương phải nằm trong khoảng từ $1.00 đến $100.00!", "error");
            return;
        }

        try {
            const res = await fetch(`${API_BASE}/api/admin/cases/${caseId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    price: price,
                    is_active: isActive
                })
            });
            const data = await res.json();
            if (res.ok) {
                this.notify(data.message, "success");
                this.loadCases(); // reload client cases catalog
            } else {
                this.notify(data.detail || "Không thể cập nhật rương", "error");
            }
        } catch (err) {
            this.notify("Lỗi kết nối máy chủ", "error");
        }
    }

    // --- SYSTEM LOGS & AUDIT TRAIL ---

    async fetchAdminLogs() {
        const logTypeSelect = document.getElementById('adm-log-type-select');
        const linesSelect = document.getElementById('adm-log-lines-select');
        const logType = logTypeSelect ? logTypeSelect.value : 'app';
        const lines = linesSelect ? parseInt(linesSelect.value) : 100;

        const terminal = document.getElementById('admin-log-terminal');
        const auditTableCont = document.getElementById('admin-audit-db-container');
        const auditTbody = document.getElementById('adm-audit-db-tbody');

        if (logType === 'audit_db') {
            if (terminal) terminal.classList.add('hidden');
            if (auditTableCont) auditTableCont.classList.remove('hidden');

            try {
                const res = await fetch(`${API_BASE}/api/admin/audit?limit=${lines}`, {
                    headers: { }
                });
                if (res.ok) {
                    const data = await res.json();
                    const records = data.audit_trail || [];
                    if (auditTbody) {
                        if (records.length === 0) {
                            auditTbody.innerHTML = `<tr><td colspan="6" class="py-6 text-center text-slate-500 font-tactical">Không có dữ liệu audit</td></tr>`;
                        } else {
                            auditTbody.innerHTML = records.map(r => `
                                <tr class="hover:bg-slate-800/40 transition">
                                    <td class="py-2.5 px-3 font-mono text-slate-400">#${r.id}</td>
                                    <td class="py-2.5 px-3 font-bold text-white">${this.escapeHtml(r.username || 'System')}</td>
                                    <td class="py-2.5 px-3 font-tactical text-amber-400 font-bold">${this.escapeHtml(r.action)}</td>
                                    <td class="py-2.5 px-3 text-slate-300 font-mono text-[11px]">${this.escapeHtml(r.details || '')}</td>
                                    <td class="py-2.5 px-3 font-mono text-slate-400 text-[11px]">${this.escapeHtml(r.ip_address || '127.0.0.1')}</td>
                                    <td class="py-2.5 px-3 text-right font-mono text-slate-500 text-[11px]">${r.created_at ? r.created_at.slice(0, 19) : ''}</td>
                                </tr>
                            `).join('');
                        }
                    }
                }
            } catch (e) {
                if (auditTbody) auditTbody.innerHTML = `<tr><td colspan="6" class="py-6 text-center text-red-400">Lỗi khi tải Audit Trail</td></tr>`;
            }

        } else {
            // Text log files (app.log or audit.log)
            if (auditTableCont) auditTableCont.classList.add('hidden');
            if (terminal) {
                terminal.classList.remove('hidden');

                try {
                    const res = await fetch(`${API_BASE}/api/admin/logs?log_type=${logType}&lines=${lines}`, {
                        headers: { }
                    });
                    if (res.ok) {
                        const data = await res.json();
                        terminal.innerHTML = (data.logs || []).map(line => {
                            let cls = "text-slate-400";
                            if (line.includes("[ERROR]")) cls = "log-line-error";
                            else if (line.includes("[WARNING]")) cls = "log-line-warning";
                            else if (line.includes("[AUDIT]")) cls = "log-line-audit";
                            else if (line.includes("[INFO]")) cls = "log-line-info";
                            return `<div class="${cls}">${this.escapeHtml(line)}</div>`;
                        }).join('');
                        terminal.scrollTop = terminal.scrollHeight;
                    }
                } catch (e) {
                    terminal.innerHTML = `<div class="text-red-400">Không thể tải nhật ký máy chủ.</div>`;
                }
            }
        }
    }

    // ==========================================
    // DEPOSIT & HOURLY CODE REDEMPTION SYSTEM
    // ==========================================

    openDepositModal() {
        document.getElementById('deposit-modal').classList.remove('hidden');
        this.fetchActiveHourlyCode();
        if (this.hourlyCodeInterval) clearInterval(this.hourlyCodeInterval);
        this.hourlyCodeInterval = setInterval(() => this.tickHourlyCodeTimer(), 1000);
    }

    closeDepositModal() {
        document.getElementById('deposit-modal').classList.add('hidden');
        if (this.hourlyCodeInterval) {
            clearInterval(this.hourlyCodeInterval);
            this.hourlyCodeInterval = null;
        }
    }

    async fetchActiveHourlyCode() {
        try {
            const headers = {};
            if (this.currentUser) {
                headers['Authorization'] = `Bearer ${this.token}`;
            }
            const res = await fetch(`${API_BASE}/api/wallet/active-code`, { headers });
            if (res.ok) {
                const data = await res.json();
                this.activeCodeData = data;
                this.codeSecondsLeft = data.seconds_left;

                const codeEl = document.getElementById('hourly-active-code');
                const timerEl = document.getElementById('hourly-code-timer');
                const statusEl = document.getElementById('hourly-code-status');
                const btnRedeem = document.getElementById('btn-redeem-code');

                if (codeEl) codeEl.textContent = data.code;
                if (timerEl) this.renderCodeTimer(this.codeSecondsLeft);

                if (statusEl && btnRedeem) {
                    if (data.has_redeemed) {
                        statusEl.className = "text-red-400 font-tactical text-[11px] font-bold";
                        statusEl.innerHTML = "<i class='fa-solid fa-circle-check'></i> BẠN ĐÃ NẠP MÃ NÀY";
                        btnRedeem.disabled = true;
                        btnRedeem.className = "w-full py-3 bg-slate-800 text-slate-500 font-tactical font-bold text-sm rounded-lg cursor-not-allowed flex items-center justify-center gap-2";
                        btnRedeem.innerHTML = "<i class='fa-solid fa-lock'></i> ĐÃ NẠP MÃ GIỜ NÀY - HÃY ĐỢI GIỜ SAU";
                    } else {
                        statusEl.className = "text-green-400 font-tactical text-[11px] font-bold";
                        statusEl.innerHTML = "<i class='fa-solid fa-bolt text-amber-400'></i> SẴN SÀNG NẠP (+100.00$)";
                        btnRedeem.disabled = false;
                        btnRedeem.className = "w-full py-3 bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-400 hover:to-emerald-500 text-black font-tactical font-black text-sm rounded-lg transition shadow-lg shadow-green-500/20 flex items-center justify-center gap-2 cursor-pointer";
                        btnRedeem.innerHTML = "<i class='fa-solid fa-check'></i> XÁC NHẬN NẠP $100.00";
                    }
                }
            }
        } catch (e) {
            console.error("Fetch active code error:", e);
        }
    }

    tickHourlyCodeTimer() {
        if (typeof this.codeSecondsLeft === 'number' && this.codeSecondsLeft > 0) {
            this.codeSecondsLeft--;
            this.renderCodeTimer(this.codeSecondsLeft);
            if (this.codeSecondsLeft <= 0) {
                this.fetchActiveHourlyCode();
            }
        }
    }

    renderCodeTimer(secs) {
        const timerEl = document.getElementById('hourly-code-timer');
        if (!timerEl) return;
        const m = Math.floor(secs / 60);
        const s = secs % 60;
        timerEl.textContent = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }

    copyHourlyCode() {
        if (!this.activeCodeData || !this.activeCodeData.code) return;
        const input = document.getElementById('deposit-code-input');
        if (input) {
            input.value = this.activeCodeData.code;
            this.notify(`Đã tự động điền mã ${this.activeCodeData.code}!`, "info");
        }
    }

    async handleRedeemCode(e) {
        e.preventDefault();
        if (!this.currentUser) {
            this.notify("Vui lòng đăng nhập trước khi nạp mã!", "info");
            this.openAuthModal('login');
            return;
        }

        const input = document.getElementById('deposit-code-input');
        const code = (input.value || '').trim();

        if (code.length !== 6 || !/^\d+$/.test(code)) {
            this.notify("Mã code phải gồm đúng 6 chữ số!", "error");
            return;
        }

        try {
            const res = await fetch(`${API_BASE}/api/wallet/redeem-code`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ code })
            });
            const data = await res.json();
            if (res.ok) {
                if (window.soundEngine && typeof window.soundEngine.playTradeUpSuccess === 'function') {
                    window.soundEngine.playTradeUpSuccess();
                }
                if (typeof confetti === 'function') {
                    confetti({ particleCount: 70, spread: 60, origin: { y: 0.6 } });
                }
                this.currentUser.balance = data.new_balance;
                this.updateUserUI();
                this.notify(data.message, "success");
                input.value = "";
                await this.fetchActiveHourlyCode();
            } else {
                this.notify(data.detail || "Không thể nạp mã code", "error");
            }
        } catch (err) {
            this.notify("Lỗi kết nối khi nạp mã code", "error");
        }
    }

    // Toast notification utility
    notify(msg, type = "info") {
        const toast = document.createElement('div');
        const color = type === 'success' ? 'bg-green-600 text-white' : (type === 'error' ? 'bg-red-600 text-white' : 'bg-slate-800 text-white border border-slate-700');
        toast.className = `fixed bottom-5 right-5 z-50 px-4 py-2.5 rounded-lg shadow-2xl font-tactical text-xs font-bold transition-all duration-300 transform translate-y-5 opacity-0 ${color}`;
        toast.textContent = msg;
        document.body.appendChild(toast);

        requestAnimationFrame(() => {
            toast.classList.remove('translate-y-5', 'opacity-0');
        });

        setTimeout(() => {
            toast.classList.add('translate-y-5', 'opacity-0');
            setTimeout(() => toast.remove(), 300);
        }, 3200);
    }

    escapeHtml(str) {
        return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    }
}

// Global instance
window.app = new CS2App();
document.addEventListener('DOMContentLoaded', () => {
    window.app.init();
});
