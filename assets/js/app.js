/**
 * Frontend operational ACW - Falticeni • Statie ITP (100% conectat la PHP 8 + MariaDB, zero date hardcodate).
 */
(() => {
  const api = window.ItpApi;

  const state = {
    todayDate: '',
    nowTime: '',
    selectedDate: '',
    user: null,
    permissions: [],
    stationConfig: {
      station_name: 'ACW - Fălticeni • Stație ITP',
      station_signature: '',
      lines: ['Linia 1', 'Linia 2', 'Linia 3'],
      slot_minutes: 30,
      open_time: '08:00',
      close_time: '17:30',
      work_days: [1, 2, 3, 4, 5, 6],
      reinspection_days: 30,
    },
    dailySlots: [],
    modules: [],
    loginUsers: [],
    selectedLoginUserId: null,
    isMasterRecoveryMode: false,

    currentView: 'azi',
    search: '',
    searchAllDays: false,
    mobileSearchOpen: false,
    lineFilter: 'Toate',
    statusFilter: 'Toate',

    appointments: [],
    futureAppointments: [],
    occupancyByLine: {},
    openReveniriCount: 0,
    reasonsRevenire: [],
    reasonsStergere: [],

    historyFilter: 'Toate',
    historyUserFilter: 'TOȚI',
    historyDatePreset: 'today',
    historyDateFrom: '',
    historyDateTo: '',
    historyPage: 1,
    historyPerPage: 10,
    historyTotal: 0,
    historyTotalPages: 1,
    historyEntries: [],
    historyActors: [],
    historyTruncated: false,
    historyLimit: 500,
    editingReasonId: null,

    adminUsers: [],
    adminBackups: [],

    editingAppt: null,
    reprogramParent: null,
    rejectTarget: null,
    rejectSelectedReasons: [],
    deleteSelectedReason: '',
    confirmAction: null,
    editingUser: null,
  };

  let plateLookupTimer = null;
  let searchReloadTimer = null;
  let modalSlotsCache = null;
  let lookupOpenRevenire = null;
  let modalLastDate = '';
  let viewRequestSeq = 0;
  let agendaAbortCtrl = null;
  let lastAgendaSignature = '';
  const busyLocks = new Set();
  const HISTORY_POLL_MS = 10000;

  async function withBusy(lockKey, btnEl, fn) {
    if (busyLocks.has(lockKey)) return;
    busyLocks.add(lockKey);
    const prevDisabled = btnEl ? btnEl.disabled : false;
    if (btnEl) btnEl.disabled = true;
    try {
      return await fn();
    } finally {
      busyLocks.delete(lockKey);
      if (btnEl) btnEl.disabled = prevDisabled;
    }
  }

  function esc(val) {
    return String(val ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function formatDateRo(iso) {
    if (!iso || !String(iso).includes('-')) return iso || '';
    const [y, m, d] = String(iso).split('-');
    return `${d}.${m}.${y}`;
  }

  function formatDateSlashRo(iso) {
    if (!iso || !String(iso).includes('-')) return iso || '';
    const [y, m, d] = String(iso).split('-');
    return `${d}/${m}/${y}`;
  }

  function parseDateSlashRoToIso(raw) {
    const clean = String(raw || '').trim().replace(/[.\-]/g, '/');
    const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(clean);
    if (!m) return null;
    const day = Number(m[1]);
    const mon = Number(m[2]);
    const yr = Number(m[3]);
    const dt = new Date(yr, mon - 1, day);
    if (dt.getFullYear() !== yr || dt.getMonth() !== mon - 1 || dt.getDate() !== day) return null;
    return `${String(yr).padStart(4, '0')}-${String(mon).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
  }

  function setRoDatePair(textId, isoId, iso) {
    const isoEl = document.getElementById(isoId);
    const txtEl = document.getElementById(textId);
    if (isoEl) isoEl.value = iso || '';
    if (txtEl) txtEl.value = iso ? formatDateSlashRo(iso) : '';
  }

  window.onRoDateTextChange = (textId, isoId, onValidIso) => {
    const txtEl = document.getElementById(textId);
    const isoEl = document.getElementById(isoId);
    if (!txtEl || !isoEl) return;
    const parsed = parseDateSlashRoToIso(txtEl.value);
    if (!parsed) {
      txtEl.value = isoEl.value ? formatDateSlashRo(isoEl.value) : '';
      return;
    }
    isoEl.value = parsed;
    txtEl.value = formatDateSlashRo(parsed);
    if (typeof onValidIso === 'function') onValidIso(parsed);
  };

  window.onRoDatePickerChange = (isoId, textId, onValidIso) => {
    const isoEl = document.getElementById(isoId);
    const txtEl = document.getElementById(textId);
    if (!isoEl) return;
    if (txtEl) txtEl.value = isoEl.value ? formatDateSlashRo(isoEl.value) : '';
    if (isoEl.value && typeof onValidIso === 'function') onValidIso(isoEl.value);
  };

  function addDaysIso(iso, delta) {
    const [y, m, d] = String(iso).split('-').map(Number);
    const dt = new Date(y, (m || 1) - 1, (d || 1) + delta);
    const yy = dt.getFullYear();
    const mm = String(dt.getMonth() + 1).padStart(2, '0');
    const dd = String(dt.getDate()).padStart(2, '0');
    return `${yy}-${mm}-${dd}`;
  }

  function isClosedDayIso(iso) {
    if (!iso || !iso.includes('-')) return false;
    const [y, m, d] = iso.split('-').map(Number);
    const dt = new Date(y, (m || 1) - 1, d || 1);
    const jsDow = dt.getDay(); // 0=Duminica
    const isoDow = jsDow === 0 ? 7 : jsDow;
    const workDays = Array.isArray(state.stationConfig.work_days) ? state.stationConfig.work_days : [1, 2, 3, 4, 5, 6];
    return !workDays.includes(isoDow);
  }

  function addOpenDaysIso(iso, openDays) {
    let cur = iso;
    let remaining = Math.max(0, Number(openDays) || 0);
    for (let guard = 0; guard < 30 && remaining > 0; guard++) {
      cur = addDaysIso(cur, 1);
      if (!isClosedDayIso(cur)) remaining--;
    }
    return cur;
  }

  function getLastOpenDayOnOrBefore(iso) {
    if (!iso || !iso.includes('-')) return iso || '';
    let cur = iso;
    for (let i = 0; i < 14; i++) {
      if (!isClosedDayIso(cur)) return cur;
      cur = addDaysIso(cur, -1);
    }
    return iso;
  }

  function diffDaysIso(fromIso, toIso) {
    if (!fromIso || !toIso || !fromIso.includes('-') || !toIso.includes('-')) return null;
    const [y1, m1, d1] = fromIso.split('-').map(Number);
    const [y2, m2, d2] = toIso.split('-').map(Number);
    return Math.round((Date.UTC(y2, (m2 || 1) - 1, d2 || 1) - Date.UTC(y1, (m1 || 1) - 1, d1 || 1)) / 86400000);
  }

  function formatDateHeading(iso) {
    const dt = new Date(`${iso}T12:00:00`);
    const days = ['Duminică', 'Luni', 'Marți', 'Miercuri', 'Joi', 'Vineri', 'Sâmbătă'];
    const months = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
    if (Number.isNaN(dt.getTime())) return iso;
    return `${days[dt.getDay()]}, ${dt.getDate()} ${months[dt.getMonth()]} ${dt.getFullYear()}`;
  }

  function cleanPhoneWa(phone) {
    const digits = String(phone || '').replace(/\D/g, '');
    if (digits.startsWith('00')) return digits.slice(2);
    if (digits.startsWith('0')) return '4' + digits;
    return digits;
  }

  function cleanPhoneTel(phone) {
    return String(phone || '').replace(/[^0-9+]/g, '');
  }

  function isLandlinePhoneRo(phone) {
    let digits = String(phone || '').replace(/\D/g, '');
    if (digits.startsWith('0040')) digits = '0' + digits.slice(4);
    else if (digits.startsWith('40') && digits.length >= 11) digits = '0' + digits.slice(2);
    return /^0[23]\d{7,8}$/.test(digits);
  }

  function stripRoDiacritics(str) {
    return String(str || '')
      .replace(/[ăâ]/g, 'a').replace(/[ĂÂ]/g, 'A')
      .replace(/î/g, 'i').replace(/Î/g, 'I')
      .replace(/[șş]/g, 's').replace(/[ȘŞ]/g, 'S')
      .replace(/[țţ]/g, 't').replace(/[ȚŢ]/g, 'T')
      .replace(/[–—]/g, '-');
  }

  function buildAppointmentMsgText(a, rejectSummary, deadline, effectiveDeadline, isExpired) {
    const sig = state.stationConfig.station_signature || state.stationConfig.station_name;
    const reinspDays = Number(state.stationConfig.reinspection_days || 30);
    if (a.status === 'Revenire') {
      // R10: Mesaje distincte pentru parinte deja reprogramat, expirat, sau in termen
      if (a.reinspection_date) {
        return `Bună ziua! Revenirea ITP pentru ${a.plate} este programată în data de ${formatDateRo(a.reinspection_date)} la ora ${a.reinspection_time || ''} (${a.reinspection_line || ''}).${rejectSummary ? ` De verificat: ${rejectSummary}.` : ''} ${sig}`;
      }
      if (isExpired) {
        return `Bună ziua! Termenul de revenire ITP pentru ${a.plate} (limită ${formatDateRo(deadline)}) a expirat; pentru verificare este necesară o programare pentru ITP complet. ${sig}`;
      }
      const isChainChild = a.visit_type === 'REVENIRE';
      const termDays = (deadline ? diffDaysIso(a.date, deadline) : null) ?? reinspDays;
      const closedNote = effectiveDeadline && effectiveDeadline !== deadline
        ? ` (ultima zi lucrătoare: ${formatDateRo(effectiveDeadline)})`
        : '';
      const termPhrase = isChainChild
        ? `până la termenul inițial ${formatDateRo(deadline)}${closedNote}`
        : `în ${termDays} zile, până la ${formatDateRo(deadline)}${closedNote}`;
      return `Bună ziua! Vehiculul ${a.plate} are status ITP Revenire (${formatDateRo(a.date)}).${rejectSummary ? ` Motive: ${rejectSummary}.` : ''} Revenire posibilă ${termPhrase}. ${sig}`;
    }
    if (a.status === 'Finalizat') {
      return `Bună ziua! Vă mulțumim pentru vizita ITP cu vehiculul ${a.plate} (status: Admis). ${sig}`;
    }
    if (a.visit_type === 'REVENIRE') {
      return `Bună ziua! Vă confirmăm programarea de REVENIRE ITP pentru ${a.plate} în data de ${formatDateRo(a.date)} la ora ${a.time} (${a.line}) - ${sig}.`;
    }
    return `Bună ziua! Vă confirmăm programarea ITP pentru ${a.plate} în data de ${formatDateRo(a.date)} la ora ${a.time} (${a.line}) - ${sig}.`;
  }

  function buildSmsHref(phone, encodedMsg) {
    const waDigits = cleanPhoneWa(phone);
    const telNum = waDigits.startsWith('40') ? '+' + waDigits : cleanPhoneTel(phone);
    const isIos = /iPad|iPhone|iPod/.test(navigator.userAgent || '');
    const sep = isIos ? '&' : '?';
    return `sms:${telNum}${encodedMsg ? `${sep}body=${encodedMsg}` : ''}`;
  }

  function userCan(perm) {
    if (!state.user) return false;
    if (state.user.role === 'admin') return true;
    return state.permissions.includes(perm);
  }

  function isAdmin() {
    return !!(state.user && state.user.role === 'admin');
  }

  function showToast(msg, action = null) {
    const c = document.getElementById('toastContainer');
    if (!c) return;
    const el = document.createElement('div');
    el.className = 'itp-toast';
    const textSpan = document.createElement('span');
    textSpan.textContent = msg;
    el.appendChild(textSpan);

    let dismissed = false;
    const dismiss = () => {
      if (dismissed) return;
      dismissed = true;
      el.remove();
    };
    if (action && typeof action.onClick === 'function' && action.label) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'itp-toast-btn';
      btn.textContent = action.label;
      btn.addEventListener('click', () => {
        dismiss();
        action.onClick();
      });
      el.appendChild(btn);
    }
    c.appendChild(el);
    setTimeout(dismiss, action && action.durationMs ? action.durationMs : 2600);
  }

  function buildJumpToDateAction(targetIso) {
    if (!targetIso || (targetIso === state.selectedDate && state.currentView === 'azi')) {
      return null;
    }
    return {
      label: 'Vezi ziua',
      durationMs: 5000,
      onClick: () => {
        state.selectedDate = targetIso;
        state.currentView = 'azi';
        loadAndRenderCurrentView();
      },
    };
  }

  function getBsApi() {
    return window.bootstrap || (window.tabler && window.tabler.bootstrap) || window.tabler || null;
  }

  function setModalVisible(id, visible) {
    const el = document.getElementById(id);
    const bs = getBsApi();
    if (!el || !bs || !bs.Modal) return;
    const inst = bs.Modal.getInstance(el) || new bs.Modal(el);
    if (visible) inst.show();
    else inst.hide();
  }

  function hideAllOpenModals() {
    state.confirmAction = null;
    const bs = getBsApi();
    document.querySelectorAll('.modal.show').forEach(el => {
      if (bs && bs.Modal) {
        const inst = bs.Modal.getInstance(el);
        if (inst) inst.hide();
      }
    });
  }

  function closeMobileSidebar(immediate = false) {
    const navEl = document.getElementById('sidebar-nav');
    if (!navEl) return;
    const bs = getBsApi();
    if (immediate) {
      navEl.classList.remove('show', 'collapsing');
      navEl.style.height = '';
      const tog = document.querySelector('.navbar-toggler[data-bs-target="#sidebar-nav"]');
      if (tog) tog.setAttribute('aria-expanded', 'false');
      return;
    }
    if (navEl.classList.contains('show') && bs && bs.Collapse) {
      const c = bs.Collapse.getInstance(navEl) || new bs.Collapse(navEl, { toggle: false });
      c.hide();
    } else {
      navEl.classList.remove('show', 'collapsing');
    }
  }

  function showScreen(screenId) {
    ['setupScreen', 'loginScreen', 'appShell'].forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      if (id === screenId) el.classList.remove('d-none');
      else el.classList.add('d-none');
    });
  }

  window.onItpUnauthorized = () => {
    state.user = null;
    api.setCsrfToken('');
    showLoginScreen();
  };

  async function boot() {
    try {
      const setup = await api.get('api/auth/setup-status');
      state.todayDate = setup.today_date || '';
      state.selectedDate = state.todayDate;
      if (setup.station_name) {
        const t = document.getElementById('loginStationTitle');
        if (t) t.textContent = setup.station_name;
      }

      if (setup.needs_setup) {
        showSetupScreen(setup.setup_token_required);
        return;
      }

      try {
        await loadSessionAndEnterApp();
      } catch (err) {
        await showLoginScreen();
      }
    } catch (err) {
      await showLoginScreen(err.message);
    }
  }

  function showSetupScreen(tokenRequired) {
    showScreen('setupScreen');
    const tokWrap = document.getElementById('setupTokenWrap');
    if (tokWrap) tokWrap.classList.toggle('d-none', !tokenRequired);
  }

  window.addSetupUserRow = () => {
    const box = document.getElementById('setupExtraUsersList');
    if (!box) return;
    const row = document.createElement('div');
    row.className = 'row g-1 align-items-center setup-user-row';
    row.innerHTML = `
      <div class="col-4"><input type="text" class="form-control form-control-sm su-name" placeholder="Nume (ex. Vlad)" required></div>
      <div class="col-3"><input type="text" class="form-control form-control-sm su-user" placeholder="Username" required></div>
      <div class="col-3"><input type="password" class="form-control form-control-sm su-pin" inputmode="numeric" maxlength="6" placeholder="PIN 4-6" required></div>
      <div class="col-2 text-end"><button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="this.closest('.setup-user-row').remove()">×</button></div>
    `;
    box.appendChild(row);
  };

  document.getElementById('formFirstStart').addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = e.currentTarget.querySelector('button[type="submit"]');
    await withBusy('formFirstStart', submitBtn, async () => {
      const errEl = document.getElementById('setupErr');
      errEl.classList.add('d-none');

      const extraRows = Array.from(document.querySelectorAll('#setupExtraUsersList .setup-user-row')).map(r => ({
        display_name: r.querySelector('.su-name').value.trim(),
        username: r.querySelector('.su-user').value.trim(),
        pin: r.querySelector('.su-pin').value.trim(),
        permissions: ['programari.edit', 'programari.revenire'],
      }));

      try {
        const res = await api.post('api/setup/first-start', {
          station_name: document.getElementById('setupStationName').value.trim(),
          display_name: document.getElementById('setupAdminName').value.trim(),
          username: document.getElementById('setupAdminUser').value.trim(),
          pin: document.getElementById('setupAdminPin').value.trim(),
          master_pin: document.getElementById('setupMasterPin').value.trim(),
          setup_token: document.getElementById('setupTokenInput').value.trim(),
          additional_users: extraRows,
        });
        if (res.csrf_token) api.setCsrfToken(res.csrf_token);
        await loadSessionAndEnterApp();
        showToast('Sistem inițializat cu succes.');
      } catch (err) {
        errEl.textContent = err.message || 'Eroare la inițializare.';
        errEl.classList.remove('d-none');
      }
    });
  });

  async function showLoginScreen(initialError = '') {
    hideAllOpenModals();
    closeMobileSidebar(true);
    lastAgendaSignature = '';
    showScreen('loginScreen');
    state.isMasterRecoveryMode = false;
    toggleMasterRecoveryMode(false);
    const errEl = document.getElementById('loginErr');
    if (initialError) {
      errEl.textContent = initialError;
      errEl.classList.remove('d-none');
    } else {
      errEl.classList.add('d-none');
    }

    try {
      const data = await api.get('api/auth/users');
      state.loginUsers = data.users || [];
      if (!state.selectedLoginUserId || !state.loginUsers.some(u => u.id === state.selectedLoginUserId)) {
        const firstActive = state.loginUsers.find(u => u.is_active) || state.loginUsers[0];
        state.selectedLoginUserId = firstActive ? firstActive.id : null;
      }
      renderPinLoginUsers();
    } catch (err) {
      errEl.textContent = err.message || 'Nu s-a putut încărca lista de utilizatori.';
      errEl.classList.remove('d-none');
    }
  }

  function renderPinLoginUsers() {
    const grid = document.getElementById('pinUserGrid');
    const nameEl = document.getElementById('selectedPinUserName');
    const masterBtn = document.getElementById('btnShowMasterRecovery');
    if (!grid) return;

    grid.innerHTML = state.loginUsers.map(u => {
      const selected = u.id === state.selectedLoginUserId;
      return `
        <div class="col-4">
          <button type="button" class="pin-user-card ${selected ? 'active' : ''} ${u.is_active ? '' : 'blocked'}"
            onclick="selectLoginUser(${u.id})">
            <div class="fw-bold small text-dark text-truncate">${esc(u.display_name)}</div>
            <div class="text-muted" style="font-size: 0.7rem;">${esc(u.role === 'admin' ? 'Admin' : 'User')}${u.is_active ? '' : ' • Blocat'}</div>
          </button>
        </div>
      `;
    }).join('');

    const cur = state.loginUsers.find(u => u.id === state.selectedLoginUserId);
    if (nameEl) nameEl.textContent = cur ? cur.display_name : '-';
    if (masterBtn) masterBtn.classList.toggle('d-none', !(cur && cur.role === 'admin' && cur.has_master_pin));
  }

  window.selectLoginUser = (uid) => {
    state.selectedLoginUserId = uid;
    clearPinInput();
    renderPinLoginUsers();
  };

  window.toggleLoginPassword = () => {
    const inp = document.getElementById('loginPin');
    const btn = document.getElementById('btnTogglePwd');
    if (!inp || !btn) return;
    const nextType = inp.type === 'password' ? 'text' : 'password';
    inp.type = nextType;
    btn.textContent = nextType === 'text' ? 'Ascunde' : 'Arată';
  };

  window.pressPinKey = (digit) => {
    const pinEl = document.getElementById('loginPin');
    if (!pinEl || pinEl.value.length >= 6) return;
    pinEl.value += String(digit);
  };

  window.backspacePinInput = () => {
    const pinEl = document.getElementById('loginPin');
    if (!pinEl) return;
    pinEl.value = pinEl.value.slice(0, -1);
    document.getElementById('loginErr').classList.add('d-none');
  };

  window.clearPinInput = () => {
    const pinEl = document.getElementById('loginPin');
    if (pinEl) pinEl.value = '';
    document.getElementById('loginErr').classList.add('d-none');
  };

  window.toggleMasterRecoveryMode = (enabled) => {
    state.isMasterRecoveryMode = !!enabled;
    document.getElementById('normalPinBox').classList.toggle('d-none', state.isMasterRecoveryMode);
    document.getElementById('masterRecoveryBox').classList.toggle('d-none', !state.isMasterRecoveryMode);
    document.getElementById('loginErr').classList.add('d-none');
  };

  document.getElementById('formLogin').addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = state.isMasterRecoveryMode
      ? document.querySelector('#masterRecoveryBox button[type="submit"]')
      : document.querySelector('#normalPinBox button[type="submit"]');
    await withBusy('formLogin', submitBtn, async () => {
      const errEl = document.getElementById('loginErr');
      errEl.classList.add('d-none');

      try {
        if (state.isMasterRecoveryMode) {
          const res = await api.post('api/auth/master-recovery', {
            master_pin: document.getElementById('loginMasterPin').value.trim(),
            new_pin: document.getElementById('newAdminPin').value.trim(),
          });
          api.setCsrfToken(res.csrf_token || '');
          document.getElementById('loginMasterPin').value = '';
          document.getElementById('newAdminPin').value = '';
          await loadSessionAndEnterApp();
          showToast(res.message || 'Comandă Admin preluată.');
        } else {
          const res = await api.post('api/auth/login', {
            user_id: state.selectedLoginUserId,
            pin: document.getElementById('loginPin').value.trim(),
          });
          api.setCsrfToken(res.csrf_token || '');
          document.getElementById('loginPin').value = '';
          await loadSessionAndEnterApp();
        }
      } catch (err) {
        errEl.textContent = err.message || 'Autentificare eșuată.';
        errEl.classList.remove('d-none');
      }
    });
  });

  async function loadSessionAndEnterApp() {
    const me = await api.get('api/auth/me');
    state.user = me.user;
    state.permissions = me.permissions || [];
    state.todayDate = me.today_date;
    state.nowTime = me.now_time || state.nowTime;
    if (!state.selectedDate) state.selectedDate = state.todayDate;
    state.stationConfig = me.station_config || state.stationConfig;
    state.dailySlots = me.daily_slots || [];
    state.modules = me.modules || [];
    api.setCsrfToken(me.csrf_token || '');

    const reasonsRes = await api.get('api/m/programari/reasons');
    const allReasons = reasonsRes.reasons || [];
    state.reasonsRevenire = allReasons.filter(r => r.kind === 'revenire');
    state.reasonsStergere = allReasons.filter(r => r.kind === 'stergere');

    const titleEl = document.getElementById('navStationShort');
    if (titleEl && state.stationConfig.station_name) {
      titleEl.textContent = state.stationConfig.station_name.split('•')[0].trim();
    }
    const mobUserEl = document.getElementById('mobDrawerUser');
    if (mobUserEl) mobUserEl.textContent = state.user.actor_label;
    const mobHeaderBadge = document.getElementById('mobHeaderUserBadge');
    if (mobHeaderBadge) {
      mobHeaderBadge.textContent = `${state.user.display_name} • Schimbă`;
      mobHeaderBadge.title = `Logat: ${state.user.actor_label} — apasă pentru schimbare coleg`;
    }
    syncOfflineBanner();

    const lineSel = document.getElementById('inpLine');
    if (lineSel) {
      lineSel.innerHTML = (state.stationConfig.lines || []).map(l => `<option value="${esc(l)}">${esc(l)}</option>`).join('');
    }

    closeMobileSidebar(true);
    lastAgendaSignature = '';
    showScreen('appShell');
    renderNav();
    await loadAndRenderCurrentView();
  }

  window.requestLogout = () => {
    closeMobileSidebar(true);
    openConfirmModal({
      title: 'Deconectare',
      message: `Sigur dorești să te deconectezi din contul ${state.user ? state.user.display_name : ''}?`,
      variant: 'danger',
      confirmText: 'Deconectare',
      onConfirm: async () => {
        try {
          await api.post('api/auth/logout', {});
        } catch (_) {
        }
        state.user = null;
        api.setCsrfToken('');
        await showLoginScreen();
      },
    });
  };

  window.switchView = (view) => {
    state.currentView = view;
    closeMobileSidebar();
    renderNav();
    loadAndRenderCurrentView();
  };

  function renderNav() {
    const opLinks = [
      { id: 'azi', label: 'Programări' },
      { id: 'istoric', label: 'Istoric Imutabil' },
    ];
    const adminLinks = [
      { id: 'users', label: 'Utilizatori & Drepturi' },
      { id: 'motive', label: 'Motive' },
      { id: 'module', label: 'Module' },
      { id: 'setari', label: 'Setări Stație' },
      { id: 'backup', label: 'Backup Bază Date' },
    ];

    document.getElementById('sidebar-module-links').innerHTML = opLinks.map(l => `
      <li class="nav-item">
        <a class="nav-link ${state.currentView === l.id ? 'active' : ''}" href="#" onclick="switchView('${l.id}'); return false;">
          <span class="nav-link-title">${esc(l.label)}</span>
        </a>
      </li>
    `).join('');

    const adminGrp = document.getElementById('sidebar-admin-group');
    adminGrp.classList.toggle('d-none', !isAdmin());
    if (isAdmin()) {
      document.getElementById('sidebar-admin-links').innerHTML = adminLinks.map(l => `
        <li class="nav-item">
          <a class="nav-link ${state.currentView === l.id ? 'active' : ''}" href="#" onclick="switchView('${l.id}'); return false;">
            <span class="nav-link-title">${esc(l.label)}</span>
          </a>
        </li>
      `).join('') + `
        <li class="nav-item">
          <a class="nav-link" href="#" onclick="openSecurityLogModal(); return false;">
            <span class="nav-link-title">Jurnal Securitate</span>
          </a>
        </li>
      `;
    }

    const mobBtns = [
      { id: 'azi', label: 'Programări' },
      { id: 'istoric', label: 'Istoric' },
    ];
    if (isAdmin()) mobBtns.push({ id: 'users', label: 'Admin' });

    document.getElementById('mobileBottomNav').innerHTML = mobBtns.map(m => `
      <button type="button" class="mobile-nav-btn ${state.currentView === m.id ? 'active' : ''}" onclick="switchView('${m.id}')">
        <span>${esc(m.label)}</span>
      </button>
    `).join('');

    const canAdd = userCan('programari.edit');
    document.getElementById('btnNewAppt').classList.toggle('d-none', !canAdd);
    document.getElementById('btnNewApptMob').classList.toggle('d-none', !canAdd);

    const topbar = document.getElementById('itpTopbar');
    if (topbar) topbar.classList.toggle('itp-mob-search-open', !!state.mobileSearchOpen);
    syncMobAllDaysButton();
  }

  function syncMobAllDaysButton() {
    const btn = document.getElementById('btnMobAllDays');
    if (!btn) return;
    btn.classList.toggle('btn-primary', !!state.searchAllDays);
    btn.classList.toggle('btn-outline-secondary', !state.searchAllDays);
    btn.setAttribute('aria-pressed', state.searchAllDays ? 'true' : 'false');
  }

  window.toggleMobileSearch = () => {
    state.mobileSearchOpen = !state.mobileSearchOpen;
    if (!state.mobileSearchOpen && state.search) {
      state.search = '';
      state.historyPage = 1;
      document.getElementById('globalSearch').value = '';
      renderNav();
      loadAndRenderCurrentView();
    } else {
      renderNav();
      if (state.mobileSearchOpen) document.getElementById('globalSearch').focus();
    }
  };

  window.toggleSearchAllDays = () => {
    state.searchAllDays = !state.searchAllDays;
    syncMobAllDaysButton();
    loadAndRenderCurrentView();
  };

  window.selectDate = (iso) => {
    if (!iso) return;
    if (state.statusFilter === 'Reveniri deschise') state.statusFilter = 'Toate';
    state.selectedDate = iso;
    loadAndRenderCurrentView();
  };

  window.shiftSelectedDate = (delta) => {
    if (state.statusFilter === 'Reveniri deschise') state.statusFilter = 'Toate';
    state.selectedDate = addDaysIso(state.selectedDate || state.todayDate, delta);
    loadAndRenderCurrentView();
  };

  document.getElementById('globalSearch').addEventListener('input', (e) => {
    state.search = e.target.value || '';
    state.historyPage = 1;
    clearTimeout(searchReloadTimer);
    searchReloadTimer = setTimeout(() => loadAndRenderCurrentView(), 180);
  });

  async function loadAndRenderCurrentView(opts = {}) {
    const isPoll = !!(opts && opts.isPoll);
    const reqSeq = ++viewRequestSeq;
    const view = state.currentView;
    const root = document.getElementById('viewRoot');
    const titleEl = document.getElementById('viewTitle');
    const subEl = document.getElementById('viewSubtitle');

    try {
      if (view === 'azi') {
        if (agendaAbortCtrl) agendaAbortCtrl.abort();
        agendaAbortCtrl = new AbortController();
        const params = new URLSearchParams({
          date: state.selectedDate || state.todayDate,
          search_all: state.searchAllDays ? '1' : '0',
          include_future: '1',
          q: state.search,
          line: state.lineFilter,
          status: state.statusFilter,
        });
        if (isPoll) params.set('poll', '1');
        const data = await api.get(`api/m/programari/appointments?${params.toString()}`, { signal: agendaAbortCtrl.signal });
        if (reqSeq !== viewRequestSeq || state.currentView !== 'azi') return;

        const sig = JSON.stringify({
          td: data.today_date,
          sd: data.selected_date,
          a: data.appointments || [],
          f: data.future_appointments || [],
          o: data.occupancy_by_line || {},
          or: Number(data.open_reveniri_count || 0),
          q: state.search,
          sa: state.searchAllDays,
          l: state.lineFilter,
          s: state.statusFilter,
        });
        if (isPoll && sig === lastAgendaSignature) {
          return;
        }
        lastAgendaSignature = sig;

        // C03: Trecere automata pe noua zi dupa miezul noptii cand utilizatorul ramasese pe Azi
        const prevToday = state.todayDate;
        const serverToday = data.today_date;
        if (prevToday && serverToday && serverToday > prevToday && state.selectedDate === prevToday) {
          state.todayDate = serverToday;
          state.nowTime = data.now_time || state.nowTime;
          state.selectedDate = serverToday;
          lastAgendaSignature = '';
          return await loadAndRenderCurrentView({ isPoll: false });
        }

        state.todayDate = data.today_date;
        state.nowTime = data.now_time || state.nowTime;
        state.selectedDate = data.selected_date;
        state.appointments = data.appointments || [];
        state.futureAppointments = data.future_appointments || [];
        state.occupancyByLine = data.occupancy_by_line || {};
        state.openReveniriCount = Number(data.open_reveniri_count || 0);
        state.dailySlots = data.daily_slots || state.dailySlots;
        state.stationConfig = data.station_config || state.stationConfig;

        if (state.statusFilter === 'Reveniri deschise') {
          titleEl.textContent = `Reveniri deschise (${state.appointments.length})`;
        } else if (state.searchAllDays && state.search.trim()) {
          titleEl.textContent = 'Căutare în toate zilele';
        } else {
          titleEl.textContent = state.selectedDate === state.todayDate ? 'Programări Azi' : `Programări • ${formatDateRo(state.selectedDate)}`;
        }
        subEl.textContent = `${formatDateHeading(state.selectedDate)} • ${state.user.actor_label}`;
        root.innerHTML = renderAziView();
        return;
      }

      lastAgendaSignature = '';

      if (view === 'istoric') {
        const range = getHistoryDateRange();
        const params = new URLSearchParams({
          q: state.search,
          event_type: state.historyFilter,
          actor: state.historyUserFilter,
          date_from: range.from,
          date_to: range.to,
          page: String(state.historyPage),
          per_page: String(state.historyPerPage),
        });
        const data = await api.get(`api/m/programari/history?${params.toString()}`);
        if (reqSeq !== viewRequestSeq || state.currentView !== 'istoric') return;
        const entries = Array.isArray(data.entries) ? data.entries : [];
        state.historyEntries = entries;
        state.historyActors = Array.isArray(data.all_actors) ? data.all_actors : [];
        state.historyTruncated = Boolean(data.truncated);
        state.historyLimit = Number(data.limit || 500);
        state.historyTotal = typeof data.total === 'number' ? data.total : entries.length;
        state.historyPage = Math.max(1, Number(data.page || state.historyPage || 1));
        state.historyTotalPages = Math.max(1, Number(data.total_pages || Math.ceil(state.historyTotal / state.historyPerPage) || 1));
        titleEl.textContent = 'Istoric Imutabil';
        subEl.textContent = `Audit complet pe programări (Dată + Oră + Utilizator) • ${state.user.actor_label}`;
        root.innerHTML = renderIstoricView();
        return;
      }

      if (view === 'users' && isAdmin()) {
        const data = await api.get('api/admin/users');
        if (reqSeq !== viewRequestSeq) return;
        state.adminUsers = data.users || [];
        titleEl.textContent = 'Utilizatori & Drepturi';
        subEl.textContent = 'Administrare conturi PIN și permisiuni granulare';
        root.innerHTML = renderUsersView();
        return;
      }

      if (view === 'motive' && isAdmin()) {
        const data = await api.get('api/m/programari/reasons');
        if (reqSeq !== viewRequestSeq) return;
        const all = data.reasons || [];
        state.reasonsRevenire = all.filter(r => r.kind === 'revenire');
        state.reasonsStergere = all.filter(r => r.kind === 'stergere');
        titleEl.textContent = 'Motive Rapide (1-Tap)';
        subEl.textContent = 'Configurare liste de motive pentru Revenire și Ștergere';
        root.innerHTML = renderReasonsView();
        return;
      }

      if (view === 'module' && isAdmin()) {
        const data = await api.get('api/admin/modules');
        if (reqSeq !== viewRequestSeq) return;
        state.modules = data.modules || [];
        titleEl.textContent = 'Module Sistem';
        subEl.textContent = 'Activare și dezactivare module';
        root.innerHTML = renderModulesView();
        return;
      }

      if (view === 'setari' && isAdmin()) {
        const data = await api.get('api/admin/settings');
        if (reqSeq !== viewRequestSeq) return;
        state.stationConfig = data.station_config || state.stationConfig;
        titleEl.textContent = 'Setări Stație ITP';
        subEl.textContent = 'Linii ITP, program de lucru și durată slot';
        root.innerHTML = renderSettingsView();
        return;
      }

      if (view === 'backup' && isAdmin()) {
        const data = await api.get('api/admin/backups');
        if (reqSeq !== viewRequestSeq) return;
        state.adminBackups = data.backups || [];
        titleEl.textContent = 'Backup Bază Date';
        subEl.textContent = 'Export și arhivă de siguranță a datelor';
        root.innerHTML = renderBackupView();
        return;
      }

      state.currentView = 'azi';
      loadAndRenderCurrentView();
    } catch (err) {
      if (err && err.name === 'AbortError') return;
      if (isPoll) return;
      root.innerHTML = `<div class="alert alert-danger">${esc(err.message || 'Eroare la încărcare.')}</div>`;
    }
  }

  function renderAziView() {
    const isMob = window.innerWidth < 992;
    const isHistory = state.selectedDate < state.todayDate;
    const isFuture = state.selectedDate > state.todayDate;
    const isClosed = isClosedDayIso(state.selectedDate);
    const lines = state.stationConfig.lines || ['Linia 1', 'Linia 2', 'Linia 3'];
    const totalDay = Object.values(state.occupancyByLine).reduce((s, n) => s + Number(n || 0), 0);
    const totalCap = Math.max(1, state.dailySlots.length * lines.length);
    const freeDay = Math.max(0, totalCap - totalDay);

    const isTodaySelected = !isHistory && !isFuture;
    const dayBadge = isHistory
      ? `<span class="badge bg-secondary-lt flex-shrink-0">Istoric</span>`
      : (isFuture ? `<span class="badge bg-green-lt text-green flex-shrink-0">Zi viitoare</span>` : '');

    const lineOccPills = lines.map(ln => {
      const short = ln.replace(/^Linia\s+/i, 'L');
      const used = Number(state.occupancyByLine[ln] || 0);
      return `<button type="button" class="badge border-0 ${state.lineFilter === ln ? 'bg-primary text-white' : 'bg-light text-dark border'}"
        style="cursor:pointer; font-size: 0.71rem;"
        data-itp-action="toggle-line" data-itp-val="${esc(ln)}">${esc(short)}: ${used}/${state.dailySlots.length}</button>`;
    }).join('');

    const isOpenRevFilter = state.statusFilter === 'Reveniri deschise';
    const statusFilters = ['Toate', 'Programat', 'Finalizat', 'Revenire', 'Reveniri deschise'];
    const reinspDays = Number(state.stationConfig.reinspection_days || 30);

    const buildRowMeta = (a) => {
      const isRev = a.status === 'Revenire';
      const isFinal = a.status === 'Finalizat';
      const reasons = Array.isArray(a.reject_reasons) ? a.reject_reasons : [];
      const rejectSummary = [reasons.join(', '), a.reject_note || ''].filter(Boolean).join(' — ');
      // R02 + R15: Preferam reinspection_deadline salvat in DB (pastrat si pe lant A -> B -> C)
      const deadline = isRev ? (a.reinspection_deadline || addDaysIso(a.date, reinspDays)) : '';
      const effectiveDeadline = deadline && isClosedDayIso(deadline) ? getLastOpenDayOnOrBefore(deadline) : deadline;
      const deadlineClosedNote = deadline && effectiveDeadline && effectiveDeadline !== deadline
        ? `(zi închisă — ultima zi utilă: ${formatDateRo(effectiveDeadline)})`
        : '';
      const termDays = deadline ? (diffDaysIso(a.date, deadline) ?? reinspDays) : reinspDays;
      const isChainChild = a.visit_type === 'REVENIRE';
      const isExpired = Boolean(isRev && !a.resolved_by_reinspection_id && deadline && state.todayDate && state.todayDate > deadline);
      const rawMsg = buildAppointmentMsgText(a, rejectSummary, deadline, effectiveDeadline, isExpired);
      const waMsg = encodeURIComponent(rawMsg);
      const smsHref = buildSmsHref(a.phone, encodeURIComponent(stripRoDiacritics(rawMsg)));
      const telHref = cleanPhoneTel(a.phone);
      const isLandline = isLandlinePhoneRo(a.phone);
      const hasRevPerm = userCan('programari.revenire') || isAdmin();
      const canEdit = (a.date >= state.todayDate && userCan('programari.edit')) || isAdmin();
      const canRev = (a.date >= state.todayDate && userCan('programari.revenire')) || isAdmin();
      const canFinal = ((a.date === state.todayDate && (userCan('programari.edit') || userCan('programari.revenire'))) || isAdmin());
      const canDel = (a.date >= state.todayDate && userCan('programari.delete')) || isAdmin();
      // R01: User cu programari.revenire poate reprograma inclusiv respingerile din zilele trecute
      const canReprog = isRev && !a.resolved_by_reinspection_id && hasRevPerm;
      // R10 + T02: Badge distinct pentru stari (Programat / Finalizat Admis / Revenire / Revenire prog.)
      let statusLabel = a.status;
      let badgeCls = 'bg-blue-lt text-blue';
      if (isFinal) {
        statusLabel = 'Finalizat (Admis)';
        badgeCls = 'bg-green-lt text-green';
      } else if (isRev) {
        badgeCls = 'bg-purple-lt text-purple';
      } else if (isChainChild) {
        statusLabel = 'Revenire prog.';
        badgeCls = 'bg-indigo-lt text-indigo';
      }
      return {
        isRev, isFinal, rejectSummary, deadline, effectiveDeadline, deadlineClosedNote, termDays, isChainChild, isExpired,
        reinspDays, waMsg, smsHref, telHref, isLandline, canEdit, canRev, canFinal, canDel, canReprog, statusLabel, badgeCls,
      };
    };

    const actionIcons = {
      wa: '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.26-1.38c1.45.79 3.08 1.21 4.78 1.21 5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2zm5.79 14.08c-.24.68-1.42 1.3-1.95 1.38-.52.08-1.18.11-1.91-.12-.44-.14-1.01-.33-1.73-.64-3.05-1.32-5.04-4.38-5.19-4.58-.15-.2-1.24-1.65-1.24-3.15s.79-2.24 1.07-2.54c.28-.3.61-.38.81-.38.2 0 .41 0 .59.01.19.01.44-.07.69.52.26.62.88 2.14.95 2.29.08.15.13.33.03.53-.1.2-.15.33-.3.51-.15.18-.32.4-.45.54-.15.15-.31.31-.13.61.18.3.78 1.29 1.68 2.09 1.15 1.03 2.13 1.35 2.43 1.5.3.15.48.13.66-.08.18-.2.76-.88.96-1.18.2-.3.4-.25.68-.15.28.1 1.77.83 2.07.98.3.15.5.23.58.36.08.13.08.75-.16 1.43z"/></svg>',
      tel: '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6.62 10.79a15.05 15.05 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.01-.24c1.12.37 2.33.57 3.58.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C10.61 21 3 13.39 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.46.57 3.58a1 1 0 0 1-.25 1.01l-2.2 2.2z"/></svg>',
      sms: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="8" y1="9" x2="16" y2="9"/><line x1="8" y1="13" x2="13" y2="13"/></svg>',
      admis: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
      undoAdmis: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14L4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v0a5.5 5.5 0 0 1-5.5 5.5H11"/></svg>',
      revenire: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
      reprog: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="12" y1="14" x2="12" y2="18"/><line x1="10" y1="16" x2="14" y2="16"/></svg>',
      edit: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
      del: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>',
    };

    const buildDesktopRow = (a, showDateCol, isFutureRow) => {
      const m = buildRowMeta(a);
      const reprogBadge = m.isRev && a.reinspection_date
        ? `<div><button type="button" class="btn btn-link p-0 text-start text-indigo small mt-1 text-decoration-none fw-semibold" onclick="selectDate('${esc(a.reinspection_date)}')">Reprogramată: ${formatDateRo(a.reinspection_date)} ${esc(a.reinspection_time || '')} (${esc(a.reinspection_line || '')})</button></div>`
        : '';
      const expiredBadge = m.isExpired ? `<span class="badge bg-red-lt text-red ms-1">Termen expirat</span>` : '';
      return `
        <tr class="${isFutureRow ? 'itp-future-row' : ''}">
          <td class="fw-bold text-dark text-nowrap">
            ${esc(a.time)}
            <div class="text-muted small fw-normal">${showDateCol ? `${formatDateRo(a.date)} • ` : ''}${esc(a.line)}</div>
          </td>
          <td><span class="plate-badge">${esc(a.plate)}</span></td>
          <td><div class="fw-medium">${esc(a.vehicle || '-')}</div></td>
          <td>
            <div class="fw-medium">${esc(a.client)}</div>
            <div class="text-muted small">${esc(a.phone)}</div>
          </td>
          <td class="itp-status-cell">
            <span class="badge ${m.badgeCls}">${esc(m.statusLabel)}</span>${expiredBadge}
            ${isFutureRow ? `<div class="itp-future-date-note">${esc(formatDateRo(a.date))}</div>` : ''}
            ${reprogBadge}
            ${a.visit_type === 'REVENIRE' && a.verify_notes ? `<div class="text-purple small mt-1"><strong>De verificat:</strong> ${esc(a.verify_notes)}</div>` : ''}
            ${m.rejectSummary ? `<div>${m.canRev ? `<button type="button" class="btn btn-link p-0 text-start text-purple small mt-1 text-decoration-none text-wrap" onclick="openRejectModal(${a.id})">${esc(m.rejectSummary)}</button>` : `<div class="text-purple small mt-1">${esc(m.rejectSummary)}</div>`}</div>` : ''}
            ${m.isRev && !a.resolved_by_reinspection_id ? `<div class="${m.isExpired ? 'text-danger fw-medium' : 'text-muted'} small mt-1">${m.isChainChild ? 'Termen inițial:' : `Termen ${m.termDays} zile:`} ${formatDateRo(m.deadline)}${m.deadlineClosedNote && !m.isExpired ? `<div class="text-orange small fw-medium">${esc(m.deadlineClosedNote)}</div>` : ''}</div>` : ''}
          </td>
          <td class="text-end itp-actions-cell">
            <div class="d-inline-flex flex-nowrap justify-content-end align-items-center gap-1">
              <a class="btn btn-sm itp-desk-act itp-desk-act-primary itp-btn-wa" target="_blank" rel="noopener" href="https://wa.me/${cleanPhoneWa(a.phone)}?text=${m.waMsg}" aria-label="Trimite mesaj WhatsApp" title="WhatsApp">${actionIcons.wa}</a>
              <a class="btn btn-sm itp-desk-act itp-desk-act-primary itp-btn-tel" href="tel:${esc(m.telHref)}" aria-label="Sună clientul" title="Telefon">${actionIcons.tel}</a>
              ${m.canFinal && !m.isRev && !m.isFinal ? `<button class="btn btn-sm btn-outline-success itp-desk-act" onclick="toggleFinalizat(${a.id}, true)" aria-label="Marchează Admis" title="Admis (Finalizat)">${actionIcons.admis}</button>` : ''}
              ${m.canFinal && m.isFinal ? `<button class="btn btn-sm btn-outline-secondary itp-desk-act" onclick="toggleFinalizat(${a.id}, false)" aria-label="Revocă Admis" title="Revocă Finalizat">${actionIcons.undoAdmis}</button>` : ''}
              ${m.canReprog ? `<button class="btn btn-sm btn-purple itp-desk-act" onclick="openReprogramModal(${a.id})" aria-label="Reprogramează" title="Reprogramează revenirea">${actionIcons.reprog}</button>` : ''}
              ${m.canRev && !m.isRev && !m.isFinal ? `<button class="btn btn-sm btn-outline-purple itp-desk-act" onclick="openRejectModal(${a.id})" aria-label="Marchează Revenire" title="Revenire">${actionIcons.revenire}</button>` : ''}
              ${m.canEdit ? `<button class="btn btn-sm btn-outline-secondary itp-desk-act" onclick="openEditAppointmentModal(${a.id})" aria-label="Editează programarea" title="Editează">${actionIcons.edit}</button>` : `<span class="badge bg-secondary-lt">Doar citire</span>`}
              ${m.canDel ? `<button class="btn btn-sm btn-outline-danger itp-desk-act" onclick="openDeleteModal(${a.id})" aria-label="Șterge programarea" title="Șterge">${actionIcons.del}</button>` : ''}
            </div>
          </td>
        </tr>
      `;
    };

    const buildMobileCard = (a, showDateInline, isFutureCard) => {
      const m = buildRowMeta(a);
      const shortLine = String(a.line || '').replace(/^Linia\s+/i, 'L');
      const reprogNoteMob = m.isRev && a.reinspection_date
        ? `<button type="button" class="btn bg-indigo-lt text-indigo rounded px-2 py-1 mb-1 w-100 text-start border-0 fw-semibold" style="font-size: 0.73rem;" onclick="selectDate('${esc(a.reinspection_date)}')">Reprogramată: ${formatDateRo(a.reinspection_date)} ${esc(a.reinspection_time || '')} (${esc(a.reinspection_line || '')})</button>`
        : '';
      return `
        <div class="card itp-mob-card${isFutureCard ? ' itp-future-mob-card' : ''}">
          <div class="d-flex justify-content-between align-items-center gap-1 mb-1">
            <div class="d-flex align-items-center gap-1 flex-nowrap min-w-0">
              <span class="plate-badge itp-mob-plate">${esc(a.plate)}</span>
              <span class="fw-bold text-nowrap" style="font-size: 0.8rem;">${showDateInline ? `${formatDateRo(a.date).slice(0, 5)} ` : ''}${esc(a.time)}</span>
              <span class="text-muted text-nowrap" style="font-size: 0.75rem;">• ${esc(shortLine)}</span>
            </div>
            <div class="text-end flex-shrink-0 d-flex flex-column align-items-end gap-1">
              <div class="d-flex align-items-center gap-1">
                <span class="badge ${m.badgeCls}" style="font-size: 0.7rem;">${esc(m.statusLabel)}</span>
                ${m.isExpired ? `<span class="badge bg-red-lt text-red" style="font-size: 0.66rem;">Expirat</span>` : ''}
              </div>
              ${isFutureCard ? `<span class="itp-future-date-note">${esc(formatDateRo(a.date))}</span>` : ''}
            </div>
          </div>
          <div class="text-muted text-truncate mb-1" style="font-size: 0.77rem;">${esc([a.vehicle, a.client, a.phone].filter(Boolean).join(' • '))}</div>
          ${reprogNoteMob}
          ${a.visit_type === 'REVENIRE' && a.verify_notes ? `<div class="bg-purple-lt text-purple rounded px-2 py-1 mb-1" style="font-size: 0.74rem;"><strong>De verificat:</strong> ${esc(a.verify_notes)}</div>` : ''}
          ${m.rejectSummary ? (m.canRev
            ? `<button type="button" class="btn d-block bg-purple-lt text-purple rounded px-2 py-1 mb-1 w-100 text-start border-0 text-wrap fw-normal" style="font-size: 0.74rem; line-height: 1.3;" onclick="openRejectModal(${a.id})" aria-label="Editează sau revocă motivul revenirii"><strong>Motiv:</strong> ${esc(m.rejectSummary)} <span class="ms-1 text-decoration-underline fw-semibold">(editează)</span></button>`
            : `<div class="bg-purple-lt text-purple rounded px-2 py-1 mb-1" style="font-size: 0.74rem;"><strong>Motiv:</strong> ${esc(m.rejectSummary)}</div>`) : ''}
          ${m.isRev && !a.resolved_by_reinspection_id ? `<div class="${m.isExpired ? 'text-danger fw-medium' : 'text-muted'} mb-1" style="font-size: 0.72rem;">${m.isChainChild ? 'Termen inițial:' : `Termen ${m.termDays} zile:`} <strong>${formatDateRo(m.deadline)}</strong>${m.deadlineClosedNote && !m.isExpired ? ` <span class="text-orange fw-semibold">${esc(m.deadlineClosedNote)}</span>` : ''}</div>` : ''}
          <div class="itp-mob-acts-line">
            <a class="btn itp-mob-act itp-mob-act-primary itp-btn-wa" target="_blank" rel="noopener" href="https://wa.me/${cleanPhoneWa(a.phone)}?text=${m.waMsg}" aria-label="Trimite mesaj WhatsApp" title="WhatsApp">${actionIcons.wa}</a>
            <a class="btn itp-mob-act itp-mob-act-primary itp-btn-tel" href="tel:${esc(m.telHref)}" aria-label="Sună clientul" title="Telefon">${actionIcons.tel}</a>
            ${!m.isLandline ? `<a class="btn btn-outline-azure itp-mob-act" href="${esc(m.smsHref)}" aria-label="Trimite SMS" title="SMS">${actionIcons.sms}</a>` : ''}
            ${m.canFinal && !m.isRev && !m.isFinal ? `<button class="btn btn-outline-success itp-mob-act" onclick="toggleFinalizat(${a.id}, true)" aria-label="Marchează Admis" title="Admis (Finalizat)">${actionIcons.admis}</button>` : ''}
            ${m.canFinal && m.isFinal ? `<button class="btn btn-outline-secondary itp-mob-act" onclick="toggleFinalizat(${a.id}, false)" aria-label="Revocă Admis" title="Revocă Finalizat">${actionIcons.undoAdmis}</button>` : ''}
            ${m.canReprog ? `<button class="btn btn-purple itp-mob-act" onclick="openReprogramModal(${a.id})" aria-label="Reprogramează" title="Reprogramează revenirea">${actionIcons.reprog}</button>` : ''}
            ${m.canRev && !m.isRev && !m.isFinal ? `<button class="btn btn-outline-purple itp-mob-act" onclick="openRejectModal(${a.id})" aria-label="Marchează Revenire" title="Revenire">${actionIcons.revenire}</button>` : ''}
            ${m.canRev && m.isRev && !m.rejectSummary ? `<button class="btn btn-outline-purple itp-mob-act" onclick="openRejectModal(${a.id})" aria-label="Motiv / Revocă revenire" title="Motiv / Revocă">${actionIcons.revenire}</button>` : ''}
            ${m.canEdit ? `<button class="btn btn-outline-secondary itp-mob-act" onclick="openEditAppointmentModal(${a.id})" aria-label="Editează programarea" title="Editează">${actionIcons.edit}</button>` : ''}
            ${m.canDel ? `<button class="btn btn-outline-danger itp-mob-act itp-mob-icon-btn" onclick="openDeleteModal(${a.id})" aria-label="Șterge programarea" title="Șterge">${actionIcons.del}</button>` : ''}
          </div>
        </div>
      `;
    };

    const showAllDatesCol = isOpenRevFilter || (state.searchAllDays && state.search.trim() !== '');
    const desktopRows = state.appointments.map(a => buildDesktopRow(a, showAllDatesCol, false)).join('');
    const futureDesktopRows = state.futureAppointments.map(a => buildDesktopRow(a, false, true)).join('');
    const mobCards = state.appointments.map(a => buildMobileCard(a, showAllDatesCol, false)).join('');
    const futureMobCards = state.futureAppointments.map(a => buildMobileCard(a, false, true)).join('');

    const futureDeskSection = state.futureAppointments.length ? `
      <tr class="itp-future-divider">
        <td colspan="6">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-1">
            <span>Programări în viitor (${state.futureAppointments.length})</span>
            <span class="text-muted fw-normal" style="font-size: 0.74rem;">Urmează în zilele următoare • nu afectează locurile de azi</span>
          </div>
        </td>
      </tr>
      ${futureDesktopRows}
    ` : '';

    const futureMobSection = state.futureAppointments.length ? `
      <div class="itp-mob-future-sep">
        <span>Programări în viitor (${state.futureAppointments.length})</span>
        <span class="text-muted fw-normal">în zilele următoare</span>
      </div>
      ${futureMobCards}
    ` : '';

    return `
      ${isClosed ? `<div class="alert alert-warning py-1 px-2 mb-2 small"><strong>Zi închisă:</strong> în afara programului stației. Doar Administratorul poate confirma excepții.</div>` : ''}
      <div class="card p-2 mb-2 itp-filter-card">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div class="d-flex align-items-center gap-1 itp-filter-date-group">
            <div class="d-flex align-items-center gap-1 flex-grow-1 flex-lg-grow-0 min-w-0">
              <button type="button" class="itp-date-btn" onclick="shiftSelectedDate(-1)" aria-label="Ziua anterioară" title="Ziua anterioară">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
              </button>
              <div class="itp-ro-date-wrap flex-grow-1 flex-lg-grow-0" style="min-width: 108px;">
                <input type="text" class="form-control form-control-sm fw-medium itp-ro-date-text" id="agendaDateText"
                  style="min-height: 32px;" inputmode="numeric" maxlength="10" placeholder="zz/ll/aaaa"
                  value="${esc(formatDateSlashRo(state.selectedDate))}"
                  onchange="onRoDateTextChange('agendaDateText', 'agendaDateNative', selectDate)">
                <span class="itp-ro-date-cal-icon" aria-hidden="true">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </span>
                <input type="date" class="itp-ro-date-native" id="agendaDateNative" aria-label="Selectează data"
                  value="${esc(state.selectedDate)}"
                  onchange="onRoDatePickerChange('agendaDateNative', 'agendaDateText', selectDate)">
              </div>
              <button type="button" class="itp-date-btn" onclick="shiftSelectedDate(1)" aria-label="Ziua următoare" title="Ziua următoare">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
              </button>
              <button type="button" class="btn btn-sm itp-azi-btn ${isTodaySelected ? 'btn-primary' : 'btn-outline-primary'}" onclick="selectDate('${esc(state.todayDate)}')">Azi</button>
            </div>
            ${dayBadge}
            ${isClosed ? `<span class="badge bg-orange-lt text-orange flex-shrink-0">Închis</span>` : `<span class="badge bg-blue-lt text-blue d-lg-none flex-shrink-0" id="mobFreeDayBadge">${freeDay} lib.</span>`}
            <label class="d-none d-lg-flex align-items-center gap-2 ms-2 m-0" style="cursor:pointer;">
              <input class="form-check-input m-0" type="checkbox" ${state.searchAllDays ? 'checked' : ''} onchange="toggleSearchAllDays()">
              <span class="small text-muted">Caută în toate zilele</span>
            </label>
          </div>
          <div class="d-flex align-items-center gap-1 itp-filter-group justify-content-between justify-content-lg-end">
            <select class="form-select form-select-sm" style="width:auto; min-height:28px;" onchange="setLineFilter(this.value)">
              <option value="Toate" ${state.lineFilter === 'Toate' ? 'selected' : ''}>${isMob ? 'Liniile' : 'Toate liniile'}</option>
              ${lines.map(ln => `<option value="${esc(ln)}" ${state.lineFilter === ln ? 'selected' : ''}>${esc(ln)}</option>`).join('')}
            </select>
            <div class="filter-strip mb-0">
              ${statusFilters.map(st => {
                const lbl = (st === 'Reveniri deschise' && state.openReveniriCount > 0)
                  ? `Reveniri deschise (${state.openReveniriCount})`
                  : st;
                return `<button type="button" class="filter-pill ${state.statusFilter === st ? 'active' : ''}" onclick="setStatusFilter('${st}')">${esc(lbl)}</button>`;
              }).join('')}
            </div>
          </div>
        </div>
        <div class="d-flex align-items-center gap-1 mt-1 pt-1 border-top flex-wrap">
          <span class="text-muted" style="font-size: 0.72rem;">Ocupare ${esc(formatDateRo(state.selectedDate))}:</span>
          ${lineOccPills}
        </div>
      </div>

      <div class="card desktop-table">
        <div class="table-responsive">
          <table class="table table-vcenter card-table itp-compact-table">
            <thead>
              <tr><th>Ora / Linie</th><th>Nr. Înmatriculare</th><th>Mașină</th><th>Client & Telefon</th><th>Status</th><th class="text-end">Acțiuni rapide</th></tr>
            </thead>
            <tbody>
              ${desktopRows || `<tr><td colspan="6" class="text-center text-muted py-4">${isOpenRevFilter ? 'Nicio revenire deschisă (nereprogramată) în ultimele 60 de zile.' : 'Nicio programare pentru ziua selectată.'}</td></tr>`}
              ${futureDeskSection}
            </tbody>
          </table>
        </div>
      </div>
      <div class="mobile-cards">
        ${mobCards || `<div class="card p-3 text-center text-muted">${isOpenRevFilter ? 'Nicio revenire deschisă (nereprogramată) în ultimele 60 de zile.' : 'Nicio programare pentru ziua selectată.'}</div>`}
        ${futureMobSection}
      </div>
    `;
  }

  window.toggleLineFilter = (ln) => {
    state.lineFilter = state.lineFilter === ln ? 'Toate' : ln;
    loadAndRenderCurrentView();
  };
  window.setLineFilter = (ln) => {
    state.lineFilter = ln;
    loadAndRenderCurrentView();
  };
  window.setStatusFilter = (st) => {
    state.statusFilter = st;
    loadAndRenderCurrentView();
  };

  function findAppointmentById(id) {
    return state.appointments.find(a => a.id === id) || state.futureAppointments.find(a => a.id === id) || null;
  }

  function updateLineOptionsLabels() {
    const lineEl = document.getElementById('inpLine');
    const hourEl = document.getElementById('inpHour');
    if (!lineEl || !hourEl) return;
    const selectedHour = hourEl.value || '';
    const takenBySlot = (modalSlotsCache && modalSlotsCache.taken_by_slot) || {};
    const freeCountByLine = (modalSlotsCache && modalSlotsCache.free_count_by_line) || {};
    const takenAtHour = new Set(Array.isArray(takenBySlot[selectedHour]) ? takenBySlot[selectedHour] : []);

    Array.from(lineEl.options).forEach(opt => {
      const ln = opt.value;
      const freeCnt = freeCountByLine[ln] !== undefined ? Number(freeCountByLine[ln]) : null;
      if (selectedHour && takenAtHour.has(ln)) {
        opt.textContent = `${ln} (ocupat ${selectedHour})`;
      } else if (freeCnt !== null) {
        opt.textContent = `${ln} (${freeCnt} lib.)`;
      } else {
        opt.textContent = ln;
      }
    });
  }

  window.onHourSelectChange = () => {
    updateLineOptionsLabels();
  };

  async function refreshHourOptions(forceTime = null, excludeId = null, opts = {}) {
    const dateEl = document.getElementById('inpDate');
    const lineEl = document.getElementById('inpLine');
    const hourEl = document.getElementById('inpHour');
    const availEl = document.getElementById('slotAvailabilityAlert');
    const submitBtn = document.getElementById('modalProgramareSubmitBtn');
    if (!dateEl || !lineEl || !hourEl) return;

    const date = dateEl.value || state.todayDate;
    const line = lineEl.value || (state.stationConfig.lines[0] || 'Linia 1');
    const autoSwitchLine = Boolean(opts && opts.autoSwitchLine);
    const switchedFrom = (opts && opts.switchedFrom) || '';
    const triedLines = Array.isArray(opts && opts.triedLines) ? opts.triedLines : [line];

    try {
      const qs = new URLSearchParams({ date, line, exclude_id: String(excludeId || 0) });
      const data = await api.get(`api/m/programari/slots?${qs.toString()}`);
      modalSlotsCache = data;
      if (data.now_time) state.nowTime = data.now_time;
      const nowTime = data.now_time || state.nowTime || '';
      const isToday = Boolean(state.todayDate && date === state.todayDate);

      const free = new Set(data.free_slots || []);
      const all = data.all_slots || state.dailySlots;
      const takenBySlot = data.taken_by_slot || {};
      const freeCountByLine = data.free_count_by_line || {};
      const curVal = forceTime || hourEl.value;

      // R07: In aceeasi zi cu vizita parinte, blocam orele <= ora parintelui
      let parentRef = state.reprogramParent || null;
      if (!parentRef && state.editingAppt && state.editingAppt.parent_id) {
        parentRef = findAppointmentById(state.editingAppt.parent_id);
      }
      const minTimeSameDay = (parentRef && parentRef.date === date) ? (parentRef.time || '') : '';

      const selectableSlots = [];
      const upcomingSlots = [];
      const optionHtml = all.map(s => {
        const isFree = free.has(s);
        const beforeParent = Boolean(minTimeSameDay && s <= minTimeSameDay);
        const isPastToday = Boolean(isToday && nowTime && s < nowTime);
        const canSelect = isFree && !beforeParent;
        if (canSelect) {
          selectableSlots.push(s);
          if (!isPastToday) upcomingSlots.push(s);
        }

        const busyLines = Array.isArray(takenBySlot[s]) ? takenBySlot[s] : [];
        const tags = [];
        if (beforeParent) {
          tags.push(`înainte de ${minTimeSameDay}`);
        } else if (!isFree) {
          tags.push('ocupat');
        } else {
          if (isPastToday) tags.push('trecut');
          if (busyLines.length > 0) {
            const busyShort = busyLines.map(l => String(l).replace(/^Linia\s+/i, 'L')).join(',');
            tags.push(`${busyShort} ocup.`);
          }
        }
        const label = tags.length ? `${s} (${tags.join(' • ')})` : s;
        return `<option value="${esc(s)}" ${canSelect ? '' : 'disabled'}>${esc(label)}</option>`;
      }).join('');

      // P02: Daca linia selectata nu are locuri libere la deschidere/schimbare data, comuta automat pe prima linie libera
      if (selectableSlots.length === 0 && autoSwitchLine && !forceTime && !state.editingAppt) {
        const lines = Array.isArray(state.stationConfig.lines) ? state.stationConfig.lines : [];
        const altLine = lines.find(ln => !triedLines.includes(ln) && Number(freeCountByLine[ln] || 0) > 0);
        if (altLine) {
          lineEl.value = altLine;
          return await refreshHourOptions(null, excludeId, {
            autoSwitchLine: true,
            switchedFrom: switchedFrom || line,
            triedLines: [...triedLines, altLine],
          });
        }
      }

      if (selectableSlots.length === 0) {
        hourEl.innerHTML = `<option value="" disabled selected>Fără locuri</option>${optionHtml}`;
        hourEl.value = '';
      } else {
        hourEl.innerHTML = optionHtml;
        const sameDateAsLast = modalLastDate === date;
        if (forceTime && selectableSlots.includes(forceTime)) {
          hourEl.value = forceTime;
        } else if (curVal && selectableSlots.includes(curVal) && (!isToday || !nowTime || curVal >= nowTime || sameDateAsLast)) {
          hourEl.value = curVal;
        } else if (upcomingSlots.length) {
          hourEl.value = upcomingSlots[0];
        } else {
          hourEl.value = selectableSlots[0];
        }
      }
      modalLastDate = date;

      if (submitBtn) submitBtn.disabled = selectableSlots.length === 0;

      if (availEl) {
        const lines = Array.isArray(state.stationConfig.lines) ? state.stationConfig.lines : [];
        const anyLineHasFree = lines.some(ln => Number(freeCountByLine[ln] || 0) > 0);
        availEl.classList.remove('alert-info', 'alert-warning', 'alert-danger', 'd-none');
        if (selectableSlots.length === 0) {
          availEl.classList.add('alert-danger');
          if (!anyLineHasFree) {
            availEl.innerHTML = `<strong>Zi complet ocupată</strong> pe toate liniile pentru ${formatDateRo(date)} — alege altă dată.`;
          } else if (minTimeSameDay) {
            availEl.innerHTML = `<strong>Fără locuri disponibile</strong> după ora ${esc(minTimeSameDay)} pe ${esc(line)} — încearcă altă linie sau altă dată.`;
          } else {
            availEl.innerHTML = `<strong>${esc(line)} este complet ocupată</strong> pe ${formatDateRo(date)} — selectează o altă linie sau altă dată.`;
          }
        } else if (switchedFrom) {
          availEl.classList.add('alert-info');
          availEl.innerHTML = `<strong>${esc(switchedFrom)} este complet ocupată</strong> — am selectat automat <strong>${esc(line)}</strong> (${selectableSlots.length} locuri libere).`;
        } else if (isToday && nowTime && upcomingSlots.length === 0) {
          availEl.classList.add('alert-warning');
          availEl.innerHTML = `Toate orele de astăzi după <strong>${esc(nowTime)}</strong> sunt ocupate pe ${esc(line)}; ora preselectată (${esc(hourEl.value)}) este anterioară orei curente.`;
        } else {
          availEl.classList.add('d-none');
          availEl.textContent = '';
        }
      }

      updateLineOptionsLabels();
    } catch (_) {
    }
  }
  window.refreshHourOptions = () => refreshHourOptions(null, state.editingAppt ? state.editingAppt.id : null, { autoSwitchLine: false });

  async function runPlateLookupAndAlerts({ autofill = true } = {}) {
    const val = document.getElementById('inpPlate').value.trim();
    const date = (document.getElementById('inpDate') && document.getElementById('inpDate').value) || state.todayDate;
    const hint = document.getElementById('plateAutofillHint');
    const revAlert = document.getElementById('plateOpenRevAlert');
    const dupAlert = document.getElementById('plateDupAlert');
    if (val.replace(/[^A-Za-z0-9]/g, '').length < 4) {
      if (hint) hint.classList.add('d-none');
      if (revAlert) revAlert.classList.add('d-none');
      if (dupAlert) dupAlert.classList.add('d-none');
      lookupOpenRevenire = null;
      return;
    }

    try {
      const qs = new URLSearchParams({ plate: val });
      if (date) qs.set('date', date);
      if (state.editingAppt && state.editingAppt.id) qs.set('exclude_id', String(state.editingAppt.id));
      if (state.reprogramParent && state.reprogramParent.id) qs.set('exclude_parent_id', String(state.reprogramParent.id));
      const res = await api.get(`api/m/programari/lookup?${qs.toString()}`);
      if (document.getElementById('inpPlate').value.trim() !== val) return;

      if (res.match) {
        if (autofill && !state.editingAppt && !state.reprogramParent) {
          const phoneEl = document.getElementById('inpPhone');
          const clientEl = document.getElementById('inpClient');
          const vehicleEl = document.getElementById('inpVehicle');
          if (!phoneEl.value.trim()) phoneEl.value = res.match.phone || '';
          if (!clientEl.value.trim()) clientEl.value = res.match.client || '';
          if (vehicleEl && !vehicleEl.value.trim()) vehicleEl.value = res.match.vehicle || '';
          if (hint) {
            hint.textContent = `Găsit în baza de date (${res.match.plate} • ultima vizită ${formatDateRo(res.match.appt_date)}).`;
            hint.classList.remove('d-none');
          }

          // R09: Semnalizare Revenire deschisa + buton 1-click spre reprogramare
          const openRev = res.match.open_revenire || null;
          lookupOpenRevenire = openRev;
          if (revAlert && openRev && (userCan('programari.revenire') || isAdmin())) {
            const reasonsTxt = [...(openRev.reject_reasons || []), openRev.reject_note || ''].filter(Boolean).join(' — ');
            const dl = openRev.reinspection_deadline || '';
            const expired = Boolean(dl && state.todayDate && state.todayDate > dl);
            revAlert.innerHTML = `
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                  <strong>Atenție — Revenire deschisă:</strong> ${esc(openRev.plate)} din ${formatDateRo(openRev.date)}${reasonsTxt ? ` (${esc(reasonsTxt)})` : ''}${dl ? ` • Limită: ${formatDateRo(dl)}` : ''}${expired ? ' <span class="badge bg-red-lt text-red">Expirat</span>' : ''}
                </div>
                <button type="button" class="btn btn-sm btn-purple" onclick="switchNewModalToLookupReprogram()">Leagă ca Revenire</button>
              </div>
            `;
            revAlert.classList.remove('d-none');
          } else if (revAlert) {
            revAlert.classList.add('d-none');
          }
        }

        // P06: Alerta live pentru duplicat in aceeasi zi
        const conflict = res.match.same_day_conflict || null;
        if (dupAlert && conflict) {
          dupAlert.innerHTML = `<strong>Atenție — duplicat în aceeași zi:</strong> ${esc(conflict.plate)} are deja programare pe ${formatDateRo(conflict.appt_date)} la <strong>${esc(conflict.appt_time)}</strong> (${esc(conflict.line)}).`;
          dupAlert.classList.remove('d-none');
        } else if (dupAlert) {
          dupAlert.classList.add('d-none');
        }
      } else {
        if (hint) hint.classList.add('d-none');
        if (revAlert) revAlert.classList.add('d-none');
        if (dupAlert) dupAlert.classList.add('d-none');
        lookupOpenRevenire = null;
      }
    } catch (_) {
    }
  }

  window.onModalDateChange = () => {
    refreshHourOptions(null, state.editingAppt ? state.editingAppt.id : null, { autoSwitchLine: true });
    runPlateLookupAndAlerts({ autofill: !state.editingAppt && !state.reprogramParent });
  };

  window.onPlateInput = () => {
    clearTimeout(plateLookupTimer);
    const val = document.getElementById('inpPlate').value.trim();
    const hint = document.getElementById('plateAutofillHint');
    const revAlert = document.getElementById('plateOpenRevAlert');
    const dupAlert = document.getElementById('plateDupAlert');
    if (val.replace(/[^A-Za-z0-9]/g, '').length < 4 || state.editingAppt || state.reprogramParent) {
      if (hint) hint.classList.add('d-none');
      if (revAlert) revAlert.classList.add('d-none');
      if (dupAlert) dupAlert.classList.add('d-none');
      lookupOpenRevenire = null;
      return;
    }
    plateLookupTimer = setTimeout(() => {
      runPlateLookupAndAlerts({ autofill: true });
    }, 220);
  };

  window.switchNewModalToLookupReprogram = async () => {
    const p = lookupOpenRevenire;
    if (!p) return;
    const keepDate = document.getElementById('inpDate').value;
    const keepTime = document.getElementById('inpHour').value;
    const keepLine = document.getElementById('inpLine').value;
    await openReprogramModalWithParent(p, { date: keepDate, time: keepTime, line: keepLine });
  };

  function resetProgramareModalAlerts() {
    ['modalErr', 'plateAutofillHint', 'plateOpenRevAlert', 'plateDupAlert', 'slotAvailabilityAlert', 'reinspectionBanner']
      .forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.add('d-none');
      });
    const submitBtn = document.getElementById('modalProgramareSubmitBtn');
    if (submitBtn) submitBtn.disabled = false;
  }

  window.openNewAppointmentModal = async () => {
    closeMobileSidebar();
    state.editingAppt = null;
    state.reprogramParent = null;
    lookupOpenRevenire = null;
    modalLastDate = '';
    document.getElementById('formProgramare').reset();
    resetProgramareModalAlerts();
    document.getElementById('modalProgramareTitle').textContent = 'Programare nouă ITP';
    document.getElementById('modalProgramareSubmitBtn').textContent = 'Salvează programarea';

    const dateEl = document.getElementById('inpDate');
    dateEl.min = isAdmin() ? '' : state.todayDate;
    const defDate = state.selectedDate >= state.todayDate || isAdmin() ? state.selectedDate : state.todayDate;
    setRoDatePair('inpDateText', 'inpDate', defDate);
    if (state.lineFilter && state.lineFilter !== 'Toate' && (state.stationConfig.lines || []).includes(state.lineFilter)) {
      document.getElementById('inpLine').value = state.lineFilter;
    }
    document.getElementById('inpPlate').disabled = false;
    await refreshHourOptions(null, null, { autoSwitchLine: true });
    setModalVisible('modalProgramare', true);
  };

  window.openEditAppointmentModal = async (id) => {
    const a = findAppointmentById(id);
    if (!a) return;
    state.editingAppt = a;
    state.reprogramParent = null;
    lookupOpenRevenire = null;
    modalLastDate = a.date || '';
    resetProgramareModalAlerts();
    document.getElementById('modalProgramareTitle').textContent = `Editează • ${a.plate}`;
    document.getElementById('modalProgramareSubmitBtn').textContent = 'Salvează modificările';

    document.getElementById('inpDate').min = isAdmin() ? '' : state.todayDate;
    setRoDatePair('inpDateText', 'inpDate', a.date);
    const lineSel = document.getElementById('inpLine');
    if (lineSel) {
      const hasLine = Array.from(lineSel.options).some(o => o.value === a.line);
      if (!hasLine && a.line) {
        const opt = document.createElement('option');
        opt.value = a.line;
        opt.textContent = a.line;
        lineSel.appendChild(opt);
      }
      lineSel.value = a.line;
    }
    document.getElementById('inpPlate').value = a.plate;
    document.getElementById('inpPlate').disabled = false;
    document.getElementById('inpPhone').value = a.phone;
    document.getElementById('inpClient').value = a.client || '';
    document.getElementById('inpVehicle').value = a.vehicle || '';
    await refreshHourOptions(a.time, a.id, { autoSwitchLine: false });
    setModalVisible('modalProgramare', true);
  };

  async function openReprogramModalWithParent(p, keepSlot = null) {
    state.editingAppt = null;
    state.reprogramParent = p;
    lookupOpenRevenire = null;
    modalLastDate = '';
    document.getElementById('formProgramare').reset();
    resetProgramareModalAlerts();
    const reinspDays = Number(state.stationConfig.reinspection_days || 30);
    const deadline = p.reinspection_deadline || addDaysIso(p.date, reinspDays);
    const effectiveDeadline = deadline && isClosedDayIso(deadline) ? getLastOpenDayOnOrBefore(deadline) : deadline;
    const isChainChild = p.visit_type === 'REVENIRE';
    const termDays = (deadline ? diffDaysIso(p.date, deadline) : null) ?? reinspDays;
    const closedInfo = effectiveDeadline && effectiveDeadline !== deadline
      ? ` (ultima zi utilă: ${formatDateRo(effectiveDeadline)})`
      : '';
    const banner = document.getElementById('reinspectionBanner');
    banner.textContent = `Reprogramare revenire ${isChainChild ? '(termen inițial)' : `${termDays} zile`} din ${formatDateRo(p.date)} (${p.plate}) • Limită: ${formatDateRo(deadline)}${closedInfo}`;
    banner.classList.remove('d-none');

    document.getElementById('modalProgramareTitle').textContent = `Reprogramează revenire • ${p.plate}`;
    document.getElementById('modalProgramareSubmitBtn').textContent = 'Programează revenirea';

    const minReprogDate = isAdmin() ? p.date : (p.date > state.todayDate ? p.date : state.todayDate);
    document.getElementById('inpDate').min = minReprogDate;
    const defaultDate = (keepSlot && keepSlot.date && keepSlot.date >= minReprogDate)
      ? keepSlot.date
      : (p.date >= state.todayDate
        ? addOpenDaysIso(p.date, 1)
        : (isClosedDayIso(state.todayDate) ? addOpenDaysIso(state.todayDate, 1) : state.todayDate));
    setRoDatePair('inpDateText', 'inpDate', defaultDate);
    const targetLine = (keepSlot && keepSlot.line) ? keepSlot.line : p.line;
    if ((state.stationConfig.lines || []).includes(targetLine)) {
      document.getElementById('inpLine').value = targetLine;
    }
    document.getElementById('inpPlate').value = p.plate;
    document.getElementById('inpPlate').disabled = true;
    document.getElementById('inpPhone').value = p.phone;
    document.getElementById('inpClient').value = p.client || '';
    document.getElementById('inpVehicle').value = p.vehicle || '';
    await refreshHourOptions(keepSlot && keepSlot.time ? keepSlot.time : null, null, { autoSwitchLine: !(keepSlot && keepSlot.line) });
    await runPlateLookupAndAlerts({ autofill: false });
    setModalVisible('modalProgramare', true);
  }

  window.openReprogramModal = async (parentId) => {
    const p = findAppointmentById(parentId);
    if (!p) return;
    await openReprogramModalWithParent(p, null);
  };

  async function submitProgramareWithFlags(extraFlags = {}) {
    const submitBtn = document.getElementById('modalProgramareSubmitBtn');
    return withBusy('formProgramare', submitBtn, async () => {
      const dateTxtEl = document.getElementById('inpDateText');
      const typedIso = dateTxtEl ? parseDateSlashRoToIso(dateTxtEl.value) : null;
      if (typedIso) document.getElementById('inpDate').value = typedIso;
      const date = document.getElementById('inpDate').value;
      const time = document.getElementById('inpHour').value;
      const line = document.getElementById('inpLine').value;
      const plate = document.getElementById('inpPlate').value.trim();
      const phone = document.getElementById('inpPhone').value.trim();
      const client = document.getElementById('inpClient').value.trim();
      const vehicle = document.getElementById('inpVehicle').value.trim();
      const hourEl = document.getElementById('inpHour');
      const selectedOpt = hourEl && hourEl.selectedOptions ? hourEl.selectedOptions[0] : null;
      if (!time || (selectedOpt && selectedOpt.disabled)) {
        const errEl = document.getElementById('modalErr');
        errEl.textContent = 'Nu există niciun slot orar liber selectat.';
        errEl.classList.remove('d-none');
        return;
      }

      try {
        if (state.reprogramParent) {
          await api.post(`api/m/programari/appointments/${state.reprogramParent.id}/reprogram`, {
            date, time, line, phone, client, vehicle, parent_version: state.reprogramParent.version, ...extraFlags,
          });
          setModalVisible('modalProgramare', false);
          showToast(`Revenire programată: ${state.reprogramParent.plate} (${formatDateRo(date)} ${time})`, buildJumpToDateAction(date));
        } else if (state.editingAppt) {
          await api.put(`api/m/programari/appointments/${state.editingAppt.id}`, {
            date, time, line, plate, phone, client, vehicle, version: state.editingAppt.version, ...extraFlags,
          });
          setModalVisible('modalProgramare', false);
          showToast(`Actualizat: ${plate.toUpperCase()} (${formatDateRo(date)} ${time})`, buildJumpToDateAction(date));
        } else {
          await api.post('api/m/programari/appointments', {
            date, time, line, plate, phone, client, vehicle, ...extraFlags,
          });
          setModalVisible('modalProgramare', false);
          showToast(`Programare salvată: ${plate.toUpperCase()} (${formatDateRo(date)} ${time})`, buildJumpToDateAction(date));
        }
        lastAgendaSignature = '';
        await loadAndRenderCurrentView();
      } catch (err) {
        if (err.status === 409 && ['DUPLICATE_PLATE', 'SUNDAY_CONFIRM_REQUIRED', 'OVER_DEADLINE'].includes(err.code)) {
          const flagName = err.code === 'DUPLICATE_PLATE'
            ? 'confirm_duplicate'
            : (err.code === 'SUNDAY_CONFIRM_REQUIRED' ? 'confirm_sunday' : 'confirm_over_deadline');
          setModalVisible('modalProgramare', false);
          openConfirmModal({
            title: 'Confirmare necesară',
            message: err.message,
            variant: 'warning',
            confirmText: 'Salvează oricum',
            onCancel: () => setModalVisible('modalProgramare', true),
            onConfirm: () => submitProgramareWithFlags({ ...extraFlags, [flagName]: true }),
          });
          return;
        }
        const errEl = document.getElementById('modalErr');
        errEl.textContent = err.message || 'Eroare la salvare.';
        errEl.classList.remove('d-none');
        // Daca modalul a fost ascuns temporar pentru o confirmare 409, il redeschidem ca eroarea sa fie vizibila
        if (Object.keys(extraFlags).length > 0) {
          setModalVisible('modalProgramare', true);
          showToast(err.message || 'Eroare la salvare.');
        }
        if (err.code === 'STALE_VERSION' || err.code === 'SLOT_TAKEN') {
          setTimeout(() => {
            refreshHourOptions(null, state.editingAppt ? state.editingAppt.id : null, { autoSwitchLine: err.code === 'SLOT_TAKEN' });
          }, 0);
          if (err.code === 'STALE_VERSION') loadAndRenderCurrentView();
        }
      }
    });
  }

  document.getElementById('formProgramare').addEventListener('submit', (e) => {
    e.preventDefault();
    document.getElementById('modalErr').classList.add('d-none');
    submitProgramareWithFlags({});
  });

  window.openRejectModal = (id) => {
    const a = findAppointmentById(id);
    if (!a) return;
    state.rejectTarget = a;
    state.rejectSelectedReasons = Array.isArray(a.reject_reasons) ? [...a.reject_reasons] : [];
    document.getElementById('rejectSubtitle').textContent = `${a.plate} • ${a.client} (${formatDateRo(a.date)} ${a.time})`;
    document.getElementById('rejectNote').value = a.reject_note || '';
    document.getElementById('rejectErr').classList.add('d-none');
    document.getElementById('btnRevokeReject').classList.toggle('d-none', a.status !== 'Revenire');
    renderRejectReasonButtons();
    setModalVisible('modalRespingere', true);
  };

  function renderRejectReasonButtons() {
    const grid = document.getElementById('rejectReasonsGrid');
    const activeLabels = new Set(state.reasonsRevenire.map(r => r.label));
    // D02: Afisam si eventuale motive arhivate (soft-deleted) inca bifate pe programare ca sa poata fi debifate
    const archivedSelected = state.rejectSelectedReasons
      .filter(lbl => !activeLabels.has(lbl))
      .map(lbl => ({ label: lbl, archived: true }));
    const buttons = [
      ...state.reasonsRevenire.map(r => ({ label: r.label, archived: false })),
      ...archivedSelected,
    ];
    grid.innerHTML = buttons.map(b => {
      const active = state.rejectSelectedReasons.includes(b.label);
      const disp = b.archived ? `${b.label} (arhivat)` : b.label;
      return `<div class="col-6"><button type="button" class="reason-check-btn ${active ? 'active' : ''}" data-itp-action="toggle-reject-reason" data-itp-val="${esc(b.label)}"><span>${esc(disp)}</span><span>${active ? '✓' : ''}</span></button></div>`;
    }).join('');
  }

  window.toggleRejectReason = (label) => {
    if (state.rejectSelectedReasons.includes(label)) {
      state.rejectSelectedReasons = state.rejectSelectedReasons.filter(x => x !== label);
    } else {
      state.rejectSelectedReasons.push(label);
    }
    renderRejectReasonButtons();
  };

  document.getElementById('formRespingere').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!state.rejectTarget) return;
    const submitBtn = e.currentTarget.querySelector('button[type="submit"]');
    await withBusy('formRespingere', submitBtn, async () => {
      const errEl = document.getElementById('rejectErr');
      errEl.classList.add('d-none');
      const reinspDays = Number(state.stationConfig.reinspection_days || 30);
      try {
        const res = await api.post(`api/m/programari/appointments/${state.rejectTarget.id}/revenire`, {
          version: state.rejectTarget.version,
          reasons: state.rejectSelectedReasons,
          note: document.getElementById('rejectNote').value.trim(),
        });
        setModalVisible('modalRespingere', false);
        const savedDeadline = res && res.appointment && res.appointment.reinspection_deadline
          ? res.appointment.reinspection_deadline
          : addDaysIso(state.rejectTarget.date, reinspDays);
        showToast(`${state.rejectTarget.plate} marcat Revenire (termen ${formatDateRo(savedDeadline)}).`);
        lastAgendaSignature = '';
        await loadAndRenderCurrentView();
      } catch (err) {
        errEl.textContent = err.message;
        errEl.classList.remove('d-none');
      }
    });
  });

  window.revokeRevenireFromModal = () => {
    if (!state.rejectTarget) return;
    const target = state.rejectTarget;
    setModalVisible('modalRespingere', false);
    openConfirmModal({
      title: 'Revocă starea Revenire',
      message: `Readuci ${target.plate} din „Revenire” înapoi la „Programat”? Acțiunea se înregistrează în Istoric Imutabil.`,
      variant: 'warning',
      confirmText: 'Revocă → Programat',
      onCancel: () => setModalVisible('modalRespingere', true),
      onConfirm: async () => {
        try {
          await api.post(`api/m/programari/appointments/${target.id}/revoke`, {
            version: target.version,
          });
          showToast(`${target.plate} readus la Programat (REVOCAT consemnat).`);
          lastAgendaSignature = '';
          await loadAndRenderCurrentView();
        } catch (err) {
          const errEl = document.getElementById('rejectErr');
          errEl.textContent = err.message;
          errEl.classList.remove('d-none');
          setModalVisible('modalRespingere', true);
          showToast(err.message);
        }
      },
    });
  };

  window.toggleFinalizat = async (id, makeFinalizat) => {
    const a = findAppointmentById(id);
    if (!a) return;
    const runToggle = async () => {
      try {
        await api.post(`api/m/programari/appointments/${a.id}/finalizat`, {
          version: a.version,
          finalizat: Boolean(makeFinalizat),
        });
        showToast(makeFinalizat ? `${a.plate} finalizat (Admis).` : `${a.plate} readus la Programat.`);
        lastAgendaSignature = '';
        await loadAndRenderCurrentView();
      } catch (err) {
        showToast(err.message);
        if (err.code === 'STALE_VERSION') loadAndRenderCurrentView();
      }
    };
    if (makeFinalizat) {
      await withBusy(`finalizat_${a.id}`, null, runToggle);
    } else {
      openConfirmModal({
        title: 'Revocare status Finalizat',
        message: `Readuci ${a.plate} din „Finalizat (Admis)” înapoi la „Programat”?`,
        variant: 'warning',
        confirmText: 'Readu la Programat',
        onConfirm: runToggle,
      });
    }
  };

  window.openDeleteModal = (id) => {
    const a = findAppointmentById(id);
    if (!a) return;
    state.deleteSelectedReason = state.reasonsStergere[0] ? state.reasonsStergere[0].label : 'Anulat de client';

    openConfirmModal({
      title: 'Ștergere programare',
      message: `${a.plate} • ${formatDateRo(a.date)} ${a.time} (${a.line})`,
      variant: 'danger',
      confirmText: 'Șterge programarea',
      showDeleteReasons: true,
      onConfirm: async () => {
        const note = document.getElementById('confirmReasonNote').value.trim();
        const reason = note ? `${state.deleteSelectedReason}: ${note}` : state.deleteSelectedReason;
        try {
          await api.del(`api/m/programari/appointments/${a.id}`, { version: a.version, reason });
          showToast(`Programare ștearsă (${a.plate}).`);
          await loadAndRenderCurrentView();
        } catch (err) {
          showToast(err.message);
        }
      },
    });
  };

  function openConfirmModal(opts) {
    state.confirmAction = opts;
    const variant = opts.variant || 'danger';
    const barEl = document.getElementById('confirmStatusBar');
    if (barEl) barEl.className = `modal-status bg-${variant}`;
    document.getElementById('confirmTitle').textContent = opts.title || 'Confirmare';
    document.getElementById('confirmMessage').textContent = opts.message || '';
    const detailEl = document.getElementById('confirmDetail');
    if (detailEl) {
      detailEl.textContent = opts.detail || '';
      detailEl.classList.toggle('d-none', !opts.detail);
    }
    const okBtn = document.getElementById('confirmOkBtn');
    okBtn.textContent = opts.confirmText || 'Confirmă';
    okBtn.className = `btn w-100 touch-btn btn-${variant}`;

    const reasonWrap = document.getElementById('confirmReasonWrap');
    reasonWrap.classList.toggle('d-none', !opts.showDeleteReasons);
    if (opts.showDeleteReasons) {
      document.getElementById('confirmReasonNote').value = '';
      renderDeleteReasonButtons();
    }
    setModalVisible('modalConfirm', true);
  }

  function renderDeleteReasonButtons() {
    document.getElementById('confirmReasonsGrid').innerHTML = state.reasonsStergere.map(r => {
      const active = state.deleteSelectedReason === r.label;
      return `<div class="col-6"><button type="button" class="reason-check-btn delete-mode ${active ? 'active' : ''}" data-itp-action="select-delete-reason" data-itp-val="${esc(r.label)}"><span>${esc(r.label)}</span><span>${active ? '✓' : ''}</span></button></div>`;
    }).join('');
  }

  window.selectDeleteReason = (label) => {
    state.deleteSelectedReason = label;
    renderDeleteReasonButtons();
  };

  document.getElementById('confirmOkBtn').addEventListener('click', async () => {
    const okBtn = document.getElementById('confirmOkBtn');
    await withBusy('confirmOkBtn', okBtn, async () => {
      const act = state.confirmAction;
      state.confirmAction = null;
      setModalVisible('modalConfirm', false);
      if (act && typeof act.onConfirm === 'function') await act.onConfirm();
    });
  });

  document.getElementById('confirmCancelBtn').addEventListener('click', () => {
    const act = state.confirmAction;
    state.confirmAction = null;
    setModalVisible('modalConfirm', false);
    if (act && typeof act.onCancel === 'function') act.onCancel();
  });

  // Daca modalConfirm este inchis cu ESC sau click in afara ferestrei, executa onCancel curat
  document.getElementById('modalConfirm').addEventListener('hidden.bs.modal', () => {
    const act = state.confirmAction;
    if (act) {
      state.confirmAction = null;
      if (typeof act.onCancel === 'function') act.onCancel();
    }
  });

  function getHistoryDateRange() {
    const today = state.todayDate || '';
    if (state.historyDatePreset === 'today' && today) {
      return { from: today, to: today, label: `Azi (${formatDateRo(today)})` };
    }
    if (state.historyDatePreset === '7d' && today) {
      const from = addDaysIso(today, -6);
      return { from, to: today, label: `Ultimele 7 zile (${formatDateRo(from)} – ${formatDateRo(today)})` };
    }
    if (state.historyDatePreset === 'custom') {
      const from = state.historyDateFrom || today;
      const to = state.historyDateTo || from || today;
      const lbl = from && to
        ? (from === to ? formatDateRo(from) : `${formatDateRo(from)} – ${formatDateRo(to)}`)
        : 'Interval personalizat';
      return { from, to, label: lbl };
    }
    return { from: '', to: '', label: 'Toate (ultimele 60 zile)' };
  }

  function renderHistoryPagination() {
    const total = Number(state.historyTotal || 0);
    const totalPages = Math.max(1, Number(state.historyTotalPages || 1));
    const page = Math.max(1, Math.min(Number(state.historyPage || 1), totalPages));
    if (totalPages <= 1 || total === 0) return '';

    const pages = [];
    const maxButtons = 5;
    let start = Math.max(1, page - 2);
    let end = Math.min(totalPages, start + maxButtons - 1);
    if (end - start + 1 < maxButtons) {
      start = Math.max(1, end - maxButtons + 1);
    }
    for (let p = start; p <= end; p++) pages.push(p);

    return `
      <nav class="card p-2 mt-2 itp-history-pagination" aria-label="Paginare istoric">
        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
          <button type="button" class="itp-page-btn" onclick="setHistoryPage(${page - 1})" ${page <= 1 ? 'disabled' : ''} aria-label="Pagina anterioară">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            <span>Anterior</span>
          </button>
          <div class="d-flex align-items-center gap-1 flex-wrap justify-content-center">
            ${start > 1 ? `<button type="button" class="itp-page-num d-none d-sm-inline-flex" onclick="setHistoryPage(1)">1</button>${start > 2 ? '<span class="text-muted small px-1 d-none d-sm-inline">…</span>' : ''}` : ''}
            ${pages.map(p => `<button type="button" class="itp-page-num d-none d-sm-inline-flex ${p === page ? 'active' : ''}" onclick="setHistoryPage(${p})" aria-current="${p === page ? 'page' : 'false'}">${p}</button>`).join('')}
            ${end < totalPages ? `${end < totalPages - 1 ? '<span class="text-muted small px-1 d-none d-sm-inline">…</span>' : ''}<button type="button" class="itp-page-num d-none d-sm-inline-flex" onclick="setHistoryPage(${totalPages})">${totalPages}</button>` : ''}
            <span class="small fw-semibold text-secondary d-inline d-sm-none">Pagina ${page} / ${totalPages}</span>
          </div>
          <button type="button" class="itp-page-btn" onclick="setHistoryPage(${page + 1})" ${page >= totalPages ? 'disabled' : ''} aria-label="Pagina următoare">
            <span>Următor</span>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
          </button>
        </div>
      </nav>
    `;
  }

  function renderIstoricView() {
    const filters = ['Toate', 'Finalizate', 'Modificate', 'Reveniri', 'Revocate', 'Reprogramate', 'Șterse', 'Intacte'];
    const datePresets = [
      { id: 'today', label: 'Azi' },
      { id: '7d', label: '7 zile' },
      { id: 'all', label: 'Toate' },
      { id: 'custom', label: 'Interval' },
    ];
    const range = getHistoryDateRange();
    const actorPool = state.historyActors.length
      ? state.historyActors
      : Array.from(new Set(state.historyEntries.flatMap(e => e.actors || [])));
    const actors = ['TOȚI', ...actorPool];
    if (state.historyUserFilter && !actors.includes(state.historyUserFilter)) {
      actors.push(state.historyUserFilter);
    }
    const csvQs = new URLSearchParams({
      q: state.search,
      event_type: state.historyFilter,
      actor: state.historyUserFilter,
      date_from: range.from,
      date_to: range.to,
    });

    const total = Number(state.historyTotal || 0);
    const totalPages = Math.max(1, Number(state.historyTotalPages || 1));
    const page = Math.max(1, Math.min(Number(state.historyPage || 1), totalPages));
    const perPage = Number(state.historyPerPage || 10);
    const shownCount = state.historyEntries.length;
    const startIdx = total > 0 && shownCount > 0 ? ((page - 1) * perPage) + 1 : 0;
    const endIdx = total > 0 && shownCount > 0 ? Math.min(total, startIdx + shownCount - 1) : 0;

    const cards = state.historyEntries.map(h => `
      <div class="card mb-2 p-2 p-sm-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="plate-badge">${esc(h.plate)}</span>
            <span class="fw-bold">${esc(h.client)}</span>
            <span class="text-muted small">${esc(formatDateRo(h.appt_date))} ${esc(h.appt_time)} (${esc(h.line)})</span>
          </div>
          <span class="badge ${h.deleted ? 'bg-red-lt text-red' : 'bg-green-lt text-green'}">${h.deleted ? 'Ștearsă' : 'Activă'}</span>
        </div>
        <div class="list-group list-group-flush border rounded">
          ${(h.events || []).map(ev => `
            <div class="list-group-item py-1 px-2 small">
              <span class="badge bg-azure-lt text-azure">${esc(ev.event_type)}</span>
              <strong class="ms-1">${esc(formatDateRo(ev.date))} ${esc(ev.at)}</strong>
              <span class="text-muted">• ${esc(ev.actor_name)}</span>
              <div class="text-dark mt-1">${esc(ev.summary)}</div>
            </div>
          `).join('')}
        </div>
      </div>
    `).join('');

    const emptyHtml = `
      <div class="card p-3 text-center text-muted">
        <div>Nicio înregistrare în Istoric pentru filtrul curent (${esc(range.label)}).</div>
        ${state.historyDatePreset !== 'all' ? `
          <div class="d-flex justify-content-center gap-2 mt-2 flex-wrap">
            ${state.historyDatePreset !== '7d' ? `<button type="button" class="btn btn-sm btn-outline-primary" onclick="setHistoryDatePreset('7d')">Vezi ultimele 7 zile</button>` : ''}
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setHistoryDatePreset('all')">Vezi tot istoricul</button>
          </div>
        ` : ''}
      </div>
    `;

    return `
      <div class="card p-2 mb-2 itp-history-filter-card">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pb-2 border-bottom">
          <div class="itp-history-pills" role="group" aria-label="Perioadă istoric">
            ${datePresets.map(p => `<button type="button" class="filter-pill ${state.historyDatePreset === p.id ? 'active' : ''}" onclick="setHistoryDatePreset('${p.id}')">${esc(p.label)}</button>`).join('')}
          </div>
          <div class="d-flex align-items-center gap-2 flex-wrap itp-history-actions-right">
            <select class="form-select form-select-sm itp-history-actor-select" aria-label="Filtrare utilizator" onchange="setHistoryActor(this.value)">
              ${actors.map(a => `<option value="${esc(a)}" ${state.historyUserFilter === a ? 'selected' : ''}>${a === 'TOȚI' ? 'Toți utilizatorii' : esc(a)}</option>`).join('')}
            </select>
            <a class="btn btn-sm btn-outline-primary flex-shrink-0" id="btnHistoryExportCsv" href="api/m/programari/history.csv?${csvQs.toString()}" target="_blank" rel="noopener">Export CSV</a>
          </div>
        </div>
        ${state.historyDatePreset === 'custom' ? `
          <div class="itp-history-range-row pt-2 pb-2 border-bottom">
            <div class="itp-history-date-field">
              <label class="small text-muted mb-0" for="histDateFromText">De la:</label>
              <div class="itp-ro-date-wrap">
                <input type="text" class="form-control form-control-sm fw-medium itp-ro-date-text" id="histDateFromText"
                  inputmode="numeric" maxlength="10" placeholder="zz/ll/aaaa"
                  value="${esc(formatDateSlashRo(range.from))}"
                  onchange="onRoDateTextChange('histDateFromText', 'histDateFromNative', setHistoryDateFrom)">
                <span class="itp-ro-date-cal-icon" aria-hidden="true">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </span>
                <input type="date" class="itp-ro-date-native" id="histDateFromNative" aria-label="Data de început"
                  value="${esc(range.from)}"
                  onchange="onRoDatePickerChange('histDateFromNative', 'histDateFromText', setHistoryDateFrom)">
              </div>
            </div>
            <div class="itp-history-date-field">
              <label class="small text-muted mb-0" for="histDateToText">Până la:</label>
              <div class="itp-ro-date-wrap">
                <input type="text" class="form-control form-control-sm fw-medium itp-ro-date-text" id="histDateToText"
                  inputmode="numeric" maxlength="10" placeholder="zz/ll/aaaa"
                  value="${esc(formatDateSlashRo(range.to))}"
                  onchange="onRoDateTextChange('histDateToText', 'histDateToNative', setHistoryDateTo)">
                <span class="itp-ro-date-cal-icon" aria-hidden="true">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </span>
                <input type="date" class="itp-ro-date-native" id="histDateToNative" aria-label="Data de sfârșit"
                  value="${esc(range.to)}"
                  onchange="onRoDatePickerChange('histDateToNative', 'histDateToText', setHistoryDateTo)">
              </div>
            </div>
          </div>
        ` : ''}
        <div class="pt-2">
          <div class="itp-history-pills" role="group" aria-label="Tip acțiune istoric">
            ${filters.map(f => `<button type="button" class="filter-pill ${state.historyFilter === f ? 'active' : ''}" onclick="setHistoryFilter('${f}')">${esc(f)}</button>`).join('')}
          </div>
        </div>
        <div class="itp-history-summary mt-2 pt-2 border-top d-flex flex-wrap align-items-center justify-content-between gap-2 small text-muted">
          <span id="historySummaryCount">${total > 0 ? `Afișate <strong>${startIdx}–${endIdx}</strong> din <strong>${total}</strong> programări` : '<strong>0</strong> programări'} • ${esc(range.label)}</span>
          ${totalPages > 1 ? `<span class="badge bg-blue-lt text-blue">Pagina ${page} / ${totalPages}</span>` : ''}
        </div>
      </div>
      ${state.historyTruncated ? `<div class="alert alert-info py-2 px-3 mb-2 small">Se afișează ultimele <strong>${state.historyLimit}</strong> programări din istoric — folosește căutarea sau filtrul de perioadă pentru rezultate specifice.</div>` : ''}
      ${cards || emptyHtml}
      ${renderHistoryPagination()}
    `;
  }

  window.setHistoryFilter = (f) => { state.historyFilter = f; state.historyPage = 1; loadAndRenderCurrentView(); };
  window.setHistoryActor = (a) => { state.historyUserFilter = a; state.historyPage = 1; loadAndRenderCurrentView(); };
  window.setHistoryDatePreset = (preset) => {
    state.historyDatePreset = preset;
    if (preset === 'custom') {
      if (!state.historyDateFrom) state.historyDateFrom = state.todayDate || '';
      if (!state.historyDateTo) state.historyDateTo = state.todayDate || state.historyDateFrom || '';
    }
    state.historyPage = 1;
    loadAndRenderCurrentView();
  };
  window.setHistoryDateFrom = (iso) => {
    if (!iso) return;
    state.historyDatePreset = 'custom';
    state.historyDateFrom = iso;
    if (state.historyDateTo && state.historyDateTo < iso) state.historyDateTo = iso;
    state.historyPage = 1;
    loadAndRenderCurrentView();
  };
  window.setHistoryDateTo = (iso) => {
    if (!iso) return;
    state.historyDatePreset = 'custom';
    state.historyDateTo = iso;
    if (state.historyDateFrom && state.historyDateFrom > iso) state.historyDateFrom = iso;
    state.historyPage = 1;
    loadAndRenderCurrentView();
  };
  window.setHistoryPage = (p) => {
    const target = Math.max(1, Math.min(Number(p) || 1, Number(state.historyTotalPages || 1)));
    if (target === state.historyPage) return;
    state.historyPage = target;
    loadAndRenderCurrentView().then(() => {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  };

  function renderUsersView() {
    return `
      <div class="mb-2 d-flex justify-content-between align-items-center">
        <span class="text-muted small">Conturile și permisiunile se aplică imediat.</span>
        <button type="button" class="btn btn-sm btn-primary" onclick="openUserModal(null)">+ Utilizator nou</button>
      </div>
      <div class="row g-2">
        ${state.adminUsers.map(u => `
          <div class="col-md-6">
            <div class="card p-3">
              <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                  <div class="fw-bold">${esc(u.display_name)} <span class="badge ${u.role === 'admin' ? 'bg-red-lt text-red' : 'bg-blue-lt text-blue'}">${esc(u.role)}</span></div>
                  <div class="text-muted small">@${esc(u.username)} • ${u.is_active ? 'Activ' : 'Blocat'}</div>
                  <div class="text-muted small mt-1">Drepturi: ${esc((u.permissions || []).join(', ') || 'Doar citire')}</div>
                </div>
                <div class="d-flex gap-1">
                  <button type="button" class="btn btn-sm btn-outline-primary" onclick="openUserModal(${u.id})">Editează</button>
                  ${u.is_primary_admin ? '' : `<button type="button" class="btn btn-sm btn-outline-${u.is_active ? 'danger' : 'success'}" onclick="toggleUserActive(${u.id})">${u.is_active ? 'Blochează' : 'Activează'}</button>`}
                </div>
              </div>
            </div>
          </div>
        `).join('')}
      </div>
    `;
  }

  window.openUserModal = (uid) => {
    const u = uid ? state.adminUsers.find(x => x.id === uid) : null;
    state.editingUser = u || null;
    document.getElementById('userErr').classList.add('d-none');
    document.getElementById('modalUserTitle').textContent = u ? `Editează • ${u.display_name}` : 'Adaugă utilizator';
    document.getElementById('userNameInp').value = u ? u.display_name : '';
    document.getElementById('userUsernameInp').value = u ? u.username : '';
    document.getElementById('userUsernameInp').disabled = !!u;
    document.getElementById('userRoleInp').value = u ? u.role : 'user';
    document.getElementById('userRoleInp').disabled = !!(u && u.is_primary_admin);
    document.getElementById('userPinInp').value = '';
    document.getElementById('userPinLabel').textContent = u ? 'PIN nou (opțional, 4–6 cifre)' : 'PIN inițial (4–6 cifre)';

    const perms = u ? (u.permissions || []) : ['programari.edit', 'programari.revenire'];
    document.getElementById('permEditInp').checked = perms.includes('programari.edit');
    document.getElementById('permRevInp').checked = perms.includes('programari.revenire');
    document.getElementById('permDelInp').checked = perms.includes('programari.delete');
    onUserRoleSelectChange();
    setModalVisible('modalUser', true);
  };

  window.onUserRoleSelectChange = () => {
    const role = document.getElementById('userRoleInp').value;
    document.getElementById('userPermsWrap').classList.toggle('d-none', role === 'admin');
  };

  document.getElementById('formUser').addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = e.currentTarget.querySelector('button[type="submit"]');
    await withBusy('formUser', submitBtn, async () => {
      const errEl = document.getElementById('userErr');
      errEl.classList.add('d-none');
      const perms = [];
      if (document.getElementById('permEditInp').checked) perms.push('programari.edit');
      if (document.getElementById('permRevInp').checked) perms.push('programari.revenire');
      if (document.getElementById('permDelInp').checked) perms.push('programari.delete');

      try {
        const payload = {
          display_name: document.getElementById('userNameInp').value.trim(),
          username: document.getElementById('userUsernameInp').value.trim(),
          role: document.getElementById('userRoleInp').value,
          pin: document.getElementById('userPinInp').value.trim(),
          permissions: perms,
        };
        if (state.editingUser) {
          await api.put(`api/admin/users/${state.editingUser.id}`, payload);
          showToast('Utilizator actualizat.');
        } else {
          await api.post('api/admin/users', payload);
          showToast('Utilizator creat.');
        }
        setModalVisible('modalUser', false);
        await loadAndRenderCurrentView();
      } catch (err) {
        errEl.textContent = err.message;
        errEl.classList.remove('d-none');
      }
    });
  });

  window.toggleUserActive = (uid) => {
    const u = state.adminUsers.find(x => x.id === uid);
    const willBlock = u ? !!u.is_active : true;
    openConfirmModal({
      title: willBlock ? 'Blochează utilizator' : 'Activează utilizator',
      message: u
        ? `${willBlock ? 'Blochezi' : 'Reactivezi'} contul „${u.display_name}” (@${u.username})?${willBlock ? ' Sesiunile active ale acestui utilizator vor fi închise imediat.' : ''}`
        : 'Confirmi schimbarea stării acestui utilizator?',
      variant: willBlock ? 'danger' : 'primary',
      confirmText: willBlock ? 'Blochează contul' : 'Activează contul',
      onConfirm: async () => {
        try {
          await api.post(`api/admin/users/${uid}/toggle-active`, {});
          showToast('Stare utilizator actualizată.');
          await loadAndRenderCurrentView();
        } catch (err) {
          showToast(err.message);
        }
      },
    });
  };

  function renderReasonsView() {
    const renderKind = (title, kind, items) => `
      <div class="col-md-6">
        <div class="card p-3">
          <h4 class="fw-bold mb-2">${esc(title)}</h4>
          <div class="input-group input-group-sm mb-2">
            <input type="text" class="form-control" id="newReason_${kind}" placeholder="Motiv nou...">
            <button type="button" class="btn btn-primary" onclick="addReason('${kind}')">Adaugă</button>
          </div>
          <div class="list-group list-group-flush border rounded">
            ${items.map(r => state.editingReasonId === r.id ? `
              <div class="list-group-item py-1 px-2">
                <div class="input-group input-group-sm">
                  <input type="text" class="form-control" id="editReasonInp_${r.id}" value="${esc(r.label)}" maxlength="60">
                  <button type="button" class="btn btn-primary" onclick="saveEditReason(${r.id})">Salvează</button>
                  <button type="button" class="btn btn-outline-secondary" onclick="cancelEditReason()">Renunță</button>
                </div>
              </div>
            ` : `
              <div class="list-group-item d-flex justify-content-between align-items-center gap-2 py-1 px-2">
                <span class="small fw-medium text-truncate">${esc(r.label)}</span>
                <div class="d-flex align-items-center gap-1 flex-shrink-0">
                  <button type="button" class="btn btn-sm btn-outline-primary" onclick="startEditReason(${r.id})">Editează</button>
                  <button type="button" class="btn btn-sm btn-ghost-danger" onclick="deleteReason(${r.id})">Șterge</button>
                </div>
              </div>
            `).join('')}
          </div>
        </div>
      </div>
    `;
    return `<div class="row g-3">${renderKind('Motive Revenire (30 zile)', 'revenire', state.reasonsRevenire)}${renderKind('Motive Ștergere / Anulare', 'stergere', state.reasonsStergere)}</div>`;
  }

  window.startEditReason = (id) => {
    state.editingReasonId = id;
    document.getElementById('viewRoot').innerHTML = renderReasonsView();
    const inp = document.getElementById(`editReasonInp_${id}`);
    if (inp) { inp.focus(); inp.select(); }
  };

  window.cancelEditReason = () => {
    state.editingReasonId = null;
    document.getElementById('viewRoot').innerHTML = renderReasonsView();
  };

  window.saveEditReason = async (id) => {
    const inp = document.getElementById(`editReasonInp_${id}`);
    const label = inp ? inp.value.trim() : '';
    if (!label) return;
    await withBusy(`editReason_${id}`, null, async () => {
      try {
        await api.put(`api/m/programari/reasons/${id}`, { label });
        state.editingReasonId = null;
        showToast('Motiv actualizat.');
        await loadAndRenderCurrentView();
      } catch (err) {
        showToast(err.message);
      }
    });
  };

  window.addReason = async (kind) => {
    await withBusy(`addReason_${kind}`, null, async () => {
      const inp = document.getElementById(`newReason_${kind}`);
      const label = inp ? inp.value.trim() : '';
      if (!label) return;
      try {
        await api.post('api/m/programari/reasons', { kind, label });
        showToast('Motiv salvat.');
        await loadAndRenderCurrentView();
      } catch (err) {
        showToast(err.message);
      }
    });
  };

  window.deleteReason = (id) => {
    const r = [...state.reasonsRevenire, ...state.reasonsStergere].find(x => x.id === id);
    openConfirmModal({
      title: 'Ștergere motiv',
      message: r ? `Ștergi motivul „${r.label}” din lista de opțiuni rapide?` : 'Confirmi ștergerea acestui motiv?',
      variant: 'danger',
      confirmText: 'Șterge motivul',
      onConfirm: async () => {
        try {
          await api.del(`api/m/programari/reasons/${id}`, {});
          showToast('Motiv șters.');
          await loadAndRenderCurrentView();
        } catch (err) {
          showToast(err.message);
        }
      },
    });
  };

  function renderModulesView() {
    return `
      <div class="row g-2">
        ${state.modules.map(m => `
          <div class="col-md-6">
            <div class="card p-3 d-flex flex-row justify-content-between align-items-center">
              <div>
                <div class="fw-bold">${esc(m.name)} <span class="text-muted small">v${esc(m.version)}</span></div>
                <div class="text-muted small">${esc(m.description)}</div>
              </div>
              ${m.locked
                ? `<span class="badge bg-blue-lt text-blue">Obligatoriu</span>`
                : `<button type="button" class="btn btn-sm btn-outline-${m.enabled ? 'danger' : 'success'}" data-itp-action="toggle-module" data-itp-val="${esc(m.id)}">${m.enabled ? 'Dezactivează' : 'Activează'}</button>`}
            </div>
          </div>
        `).join('')}
      </div>
    `;
  }

  window.toggleModule = (id) => {
    const m = state.modules.find(x => x.id === id);
    const willDisable = m ? !!m.enabled : true;
    openConfirmModal({
      title: willDisable ? 'Dezactivează modul' : 'Activează modul',
      message: m ? `${willDisable ? 'Dezactivezi' : 'Activezi'} modulul „${m.name}”?` : 'Confirmi schimbarea stării modulului?',
      variant: willDisable ? 'warning' : 'primary',
      confirmText: willDisable ? 'Dezactivează' : 'Activează',
      onConfirm: async () => {
        try {
          await api.post(`api/admin/modules/${encodeURIComponent(id)}/toggle`, {});
          showToast('Stare modul actualizată.');
          await loadAndRenderCurrentView();
        } catch (err) {
          showToast(err.message);
        }
      },
    });
  };

  function renderSettingsView() {
    const c = state.stationConfig;
    const workDays = Array.isArray(c.work_days) ? c.work_days.map(Number) : [1, 2, 3, 4, 5, 6];
    const dayLabels = [
      [1, 'Luni'], [2, 'Marți'], [3, 'Miercuri'], [4, 'Joi'], [5, 'Vineri'], [6, 'Sâmbătă'], [7, 'Duminică'],
    ];
    return `
      <div class="card p-3" style="max-width: 660px;">
        <form onsubmit="saveStationSettings(event)">
          <div class="mb-2">
            <label class="form-label small fw-semibold mb-1">Denumire stație</label>
            <input type="text" class="form-control form-control-sm" id="cfgName" value="${esc(c.station_name)}" required>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold mb-1">Semnătură / adresă în mesajele WhatsApp și SMS</label>
            <input type="text" class="form-control form-control-sm" id="cfgSignature" value="${esc(c.station_signature || '')}" placeholder="AUTOMOTIVE CENTER WERKSTAT SRL...">
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold mb-1">Linii ITP (separate prin virgulă)</label>
            <input type="text" class="form-control form-control-sm" id="cfgLines" value="${esc((c.lines || []).join(', '))}" required>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6 col-sm-3">
              <label class="form-label small fw-semibold mb-1">Deschidere</label>
              <input type="text" class="form-control form-control-sm" id="cfgOpen" value="${esc(c.open_time)}" required>
            </div>
            <div class="col-6 col-sm-3">
              <label class="form-label small fw-semibold mb-1">Închidere</label>
              <input type="text" class="form-control form-control-sm" id="cfgClose" value="${esc(c.close_time)}" required>
            </div>
            <div class="col-6 col-sm-3">
              <label class="form-label small fw-semibold mb-1">Slot (minute)</label>
              <input type="number" class="form-control form-control-sm" id="cfgSlot" value="${esc(c.slot_minutes)}" min="15" max="60" required>
            </div>
            <div class="col-6 col-sm-3">
              <label class="form-label small fw-semibold mb-1">Termen revenire (zile)</label>
              <input type="number" class="form-control form-control-sm" id="cfgReinspectionDays" value="${esc(c.reinspection_days || 30)}" min="1" max="90" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold mb-1">Zile lucrătoare</label>
            <div class="d-flex flex-wrap gap-3">
              ${dayLabels.map(([num, lbl]) => `
                <label class="form-check form-check-inline m-0">
                  <input class="form-check-input cfg-work-day" type="checkbox" value="${num}" ${workDays.includes(num) ? 'checked' : ''}>
                  <span class="form-check-label small">${esc(lbl)}</span>
                </label>
              `).join('')}
            </div>
          </div>
          <button type="submit" class="btn btn-sm btn-primary">Salvează setările</button>
        </form>
      </div>
    `;
  }

  async function submitStationSettingsPayload(payload) {
    try {
      await api.put('api/admin/settings', payload);
      showToast('Setări stație actualizate.');
      await loadSessionAndEnterApp();
    } catch (err) {
      if (err.status === 409 && err.code === 'SETTINGS_IMPACT_CONFIRM_REQUIRED') {
        openConfirmModal({
          title: 'Programări active afectate',
          message: err.message,
          variant: 'warning',
          confirmText: 'Salvează oricum',
          onConfirm: () => submitStationSettingsPayload({ ...payload, confirm_impact: true }),
        });
        return;
      }
      showToast(err.message);
    }
  }

  window.saveStationSettings = async (e) => {
    e.preventDefault();
    const submitBtn = e.currentTarget ? e.currentTarget.querySelector('button[type="submit"]') : null;
    await withBusy('saveSettings', submitBtn, async () => {
      const lines = document.getElementById('cfgLines').value.split(',').map(s => s.trim()).filter(Boolean);
      const workDays = Array.from(document.querySelectorAll('.cfg-work-day:checked')).map(el => Number(el.value));
      await submitStationSettingsPayload({
        station_name: document.getElementById('cfgName').value.trim(),
        station_signature: document.getElementById('cfgSignature').value.trim(),
        lines,
        open_time: document.getElementById('cfgOpen').value.trim(),
        close_time: document.getElementById('cfgClose').value.trim(),
        slot_minutes: Number(document.getElementById('cfgSlot').value),
        reinspection_days: Number(document.getElementById('cfgReinspectionDays').value),
        work_days: workDays,
      });
    });
  };

  function renderBackupView() {
    return `
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div>
            <div class="fw-bold">Copii de siguranță</div>
            <div class="text-muted small">Salvează local o copie completă a datelor aplicației.</div>
          </div>
          <button type="button" class="btn btn-sm btn-primary" onclick="createBackupNow()">Generează Backup acum</button>
        </div>
        <div class="list-group list-group-flush border rounded">
          ${state.adminBackups.map(b => `
            <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
              <div>
                <div class="fw-semibold small">${esc(b.filename)}</div>
                <div class="text-muted small">${esc(b.modified_at)} • ${Math.round(b.size_bytes / 1024)} KB</div>
              </div>
              <a class="btn btn-sm btn-outline-primary" href="api/admin/backups/${encodeURIComponent(b.filename)}" target="_blank" rel="noopener">Descarcă</a>
            </div>
          `).join('') || `<div class="p-3 text-muted small text-center">Nicio copie de siguranță generată manual încă.</div>`}
        </div>
      </div>
    `;
  }

  window.createBackupNow = async () => {
    await withBusy('createBackup', null, async () => {
      try {
        await api.post('api/admin/backups', {});
        showToast('Copie de siguranță creată.');
        await loadAndRenderCurrentView();
      } catch (err) {
        showToast(err.message);
      }
    });
  };

  window.openChangePinModal = () => {
    closeMobileSidebar();
    document.getElementById('formChangePin').reset();
    document.getElementById('changePinErr').classList.add('d-none');
    setModalVisible('modalChangePin', true);
  };

  document.getElementById('formChangePin').addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = e.currentTarget.querySelector('button[type="submit"]');
    await withBusy('formChangePin', submitBtn, async () => {
      const errEl = document.getElementById('changePinErr');
      errEl.classList.add('d-none');
      const cur = document.getElementById('changePinCurrent').value.trim();
      const n1 = document.getElementById('changePinNew').value.trim();
      const n2 = document.getElementById('changePinConfirm').value.trim();
      if (n1 !== n2) {
        errEl.textContent = 'Confirmarea PIN-ului nou nu coincide.';
        errEl.classList.remove('d-none');
        return;
      }
      try {
        await api.post('api/auth/change-pin', { current_pin: cur, new_pin: n1 });
        setModalVisible('modalChangePin', false);
        showToast('PIN actualizat cu succes.');
      } catch (err) {
        errEl.textContent = err.message;
        errEl.classList.remove('d-none');
      }
    });
  });

  window.openSecurityLogModal = async () => {
    closeMobileSidebar();
    setModalVisible('modalSecurityLog', true);
    await loadSecurityLog();
  };

  // Delegare sigura pentru actiuni cu valori dinamice (evita interpolarea in atribute onclick)
  document.addEventListener('click', (e) => {
    const actEl = e.target && e.target.closest ? e.target.closest('[data-itp-action]') : null;
    if (actEl) {
      const action = actEl.getAttribute('data-itp-action');
      const val = actEl.getAttribute('data-itp-val') || '';
      if (action === 'toggle-line') window.toggleLineFilter(val);
      else if (action === 'toggle-reject-reason') window.toggleRejectReason(val);
      else if (action === 'select-delete-reason') window.selectDeleteReason(val);
      else if (action === 'toggle-module') window.toggleModule(val);
    }

    // Inchide automat meniul mobil la atingerea oricarei zone din afara meniului
    if (window.innerWidth < 992) {
      const navEl = document.getElementById('sidebar-nav');
      const sideEl = document.getElementById('sidebarMenu');
      if (navEl && navEl.classList.contains('show') && sideEl && !sideEl.contains(e.target)) {
        closeMobileSidebar();
      }
    }
  });

  window.loadSecurityLog = async () => {
    const box = document.getElementById('securityLogBody');
    const cat = document.getElementById('secLogFilterSelect').value || 'TOATE';
    const exportBtn = document.getElementById('secLogExportBtn');
    if (exportBtn) {
      exportBtn.href = `api/admin/audit.csv?category=${encodeURIComponent(cat)}`;
    }
    try {
      const data = await api.get(`api/admin/audit?category=${encodeURIComponent(cat)}`);
      const list = data.events || [];
      box.innerHTML = list.length ? `
        <div class="list-group list-group-flush border rounded">
          ${list.map(ev => `
            <div class="list-group-item py-2 px-3 small">
              <span class="badge bg-azure-lt text-azure">${esc(ev.action)}</span>
              <strong class="ms-1">${esc(formatDateRo(ev.date))} ${esc(ev.at)}</strong>
              <span class="text-muted">• ${esc(ev.actor_name)} (${esc(ev.ip_address)})</span>
              <div class="mt-1">${esc(ev.summary)}</div>
            </div>
          `).join('')}
        </div>
      ` : `<div class="text-center text-muted py-3 small">Nicio înregistrare pentru filtrul selectat.</div>`;
    } catch (err) {
      box.innerHTML = `<div class="alert alert-danger small">${esc(err.message)}</div>`;
    }
  };

  function shouldRunAgendaPoll() {
    if (!state.user) return false;
    if (document.hidden) return false;
    if (state.currentView !== 'azi') return false;
    if (document.querySelector('.modal.show')) return false;
    const activeEl = document.activeElement;
    const viewRoot = document.getElementById('viewRoot');
    if (activeEl && viewRoot && viewRoot.contains(activeEl) && ['INPUT', 'SELECT', 'TEXTAREA'].includes(activeEl.tagName)) {
      return false;
    }
    return true;
  }

  // Auto-reimprospatare agenda din DB la fiecare 10s (fara flicker, fara reset focus si fara a prelungi idle timeout)
  setInterval(() => {
    if (shouldRunAgendaPoll()) {
      loadAndRenderCurrentView({ isPoll: true });
    }
  }, HISTORY_POLL_MS);

  function syncOfflineBanner() {
    const el = document.getElementById('offlineBanner');
    if (el) el.classList.toggle('d-none', navigator.onLine !== false);
  }

  window.addEventListener('online', () => {
    syncOfflineBanner();
    showToast('Conexiune restabilită.');
    if (state.user && shouldRunAgendaPoll()) {
      loadAndRenderCurrentView({ isPoll: true });
    }
  });
  window.addEventListener('offline', () => {
    syncOfflineBanner();
  });

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && shouldRunAgendaPoll()) {
      loadAndRenderCurrentView({ isPoll: true });
    }
  });

  syncOfflineBanner();
  boot();
})();
