// QuickGanyu – shared API helpers for all pages
(() => {

  // Build a URL relative to the current page (root index or /html/*).
  const apiUrl = (endpoint) => {
    const base = window.location.pathname.includes('/html/') ? '../api/' : 'api/';
    return base + endpoint;
  };

  // POST JSON (or GET when data is null).
  async function apiFetch(endpoint, data = null) {
    const options = {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
    };
    if (data === null) {
      options.method = 'GET';
      delete options.headers; // GET with no body
    } else {
      options.body = JSON.stringify(data);
    }
    const res = await fetch(apiUrl(endpoint), options);
    let payload;
    try {
      payload = await res.json();
    } catch {
      payload = { ok: false, error: 'The server returned an invalid response.' };
    }
    if (!res.ok && !payload.error && !payload.errors) {
      payload.error = `Request failed (HTTP ${res.status}).`;
    }
    return payload;
  }

  // Fetch the current session user.
  async function fetchMe() {
    const res = await apiFetch('session.php');
    return res.user || null;
  }

  // Surviving accessor used by other scripts.
  window.QuickGanyu = {
    apiUrl,
    apiFetch,
    fetchMe,
    esc(str) {
      return String(str ?? '')
        .replace(/&/g, '&')
        .replace(/</g, '<')
        .replace(/>/g, '>')
        .replace(/"/g, '"')
        .replace(/'/g, ''');
    },
  };

  // Small non-blocking toast.
  function toast(message, type = 'success') {
    const colors = {
      success: '#0b0b0b',
      error: '#b00020',
      info: '#14532d',
    };
    const el = document.createElement('div');
    el.style.cssText = `
      position: fixed; bottom: 24px; right: 24px; z-index: 9999;
      background: ${colors[type] || '#0b0b0b'}; color: #f9f9f9;
      padding: 0.9rem 1.6rem; border-radius: 40px; font-weight: 600;
      box-shadow: 0 8px 24px rgba(0,0,0,0.25); max-width: 340px;
      font-size: 0.95rem; line-height: 1.4;
    `;
    el.textContent = message;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 4600);
  }
  window.QuickGanyu.toast = toast;

  // Render error messages next to form fields or as a banner.
  function showFormErrors(errors) {
    if (!errors || typeof errors !== 'object') return;
    const anyShown = Object.keys(errors).length > 0;
    for (const key of Object.keys(errors)) {
      const input = document.querySelector(`[name="${key}"]`);
      if (!input) continue;
      let msg = input.parentElement.querySelector('.field-error');
      if (!msg) {
        msg = document.createElement('div');
        msg.className = 'field-error';
        input.parentElement.appendChild(msg);
      }
      msg.textContent = errors[key];
      input.classList.add('input-error');
    }
    if (!anyShown) return;
    setTimeout(() => {
      document.querySelectorAll('.field-error').forEach((el) => el.remove());
      document.querySelectorAll('.input-error').forEach((el) => el.classList.remove('input-error'));
    }, 6000);
  }
  window.QuickGanyu.showFormErrors = showFormErrors;

  // Update the account buttons in the navbar based on login state.
  function applyNavbar(user) {
    const navActions = document.querySelector('.nav-actions');
    if (!navActions) return;

    if (!user) {
      navActions.innerHTML = `
        <a class="btn-outline-light" href="${apiUrl('../../html/signup.html')}"><i class="fas fa-user-plus"></i> Sign up</a>
        <a class="btn-light" href="${apiUrl('../../html/login.html')}"><i class="fas fa-sign-in-alt"></i> Log in</a>
      `;
      return;
    }
  }

  // Public init: run nav refresh automatically.
  document.addEventListener('DOMContentLoaded', async () => {
    const navActions = document.querySelector('.nav-actions');
    if (!navActions) return;

    try {
      const me = await fetchMe();
      if (me) {
        const roleLabel = me.role === 'admin' ? 'Admin' : me.role === 'provider' ? 'Provider' : 'Client';
        const profileHref = me.role === 'admin'
          ? (window.location.pathname.includes('/html/') ? 'admin.html' : '../html/admin.html')
          : (window.location.pathname.includes('/html/') ? 'profile.html' : '../html/profile.html');

        navActions.innerHTML = `
          <span class="user-chip"><i class="fas fa-user-circle"></i> ${window.QuickGanyu.esc(me.username || me.full_name)} <em>${roleLabel}</em></span>
          <a class="btn-outline-light" href="${profileHref}"><i class="fas fa-user-edit"></i> My account</a>
          <button class="btn-light" id="logout-btn" type="button"><i class="fas fa-sign-out-alt"></i> Log out</button>
        `;
        document.getElementById('logout-btn').addEventListener('click', async () => {
          await apiFetch('logout.php', {});
          toast('You have been logged out.', 'info');
          setTimeout(() => window.location.reload(), 500);
        });
      }
    } catch (e) {
      // Leave the default sign up / log in links untouched.
    }
  });
})();
