// QuickGanyu – shared API helpers for all pages
(() => {

  // Detect whether we're in /html/ or at the root.
  const inHtmlDir = window.location.pathname.includes('/html/');

  // Build a URL relative to /api/.
  const apiUrl = (endpoint) => {
    const base = inHtmlDir ? '../api/' : 'api/';
    return base + endpoint;
  };

  // Build a URL relative to /html/.
  const htmlUrl = (page) => {
    return inHtmlDir ? page : 'html/' + page;
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
      delete options.headers;
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

  // Small non-blocking toast notification.
  function toast(message, type = 'success') {
    const colors = {
      success: '#0b0b0b',
      error:   '#b00020',
      info:    '#14532d',
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

  // HTML entity escaper – prevents XSS when inserting user content into innerHTML.
  function esc(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // Render field-level error messages next to form inputs.
  function showFormErrors(errors) {
    if (!errors || typeof errors !== 'object') return;
    // Clear old errors first.
    document.querySelectorAll('.field-error').forEach(el => el.remove());
    document.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));

    for (const key of Object.keys(errors)) {
      const input = document.querySelector(`[name="${key}"]`);
      if (!input) continue;
      const msg = document.createElement('div');
      msg.className = 'field-error';
      msg.textContent = errors[key];
      input.parentElement.appendChild(msg);
      input.classList.add('input-error');
    }
    setTimeout(() => {
      document.querySelectorAll('.field-error').forEach(el => el.remove());
      document.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));
    }, 6000);
  }

  // Expose the public API.
  window.QuickGanyu = { apiUrl, htmlUrl, apiFetch, fetchMe, toast, esc, showFormErrors };

  // Auto-update navbar on every page load.
  document.addEventListener('DOMContentLoaded', async () => {
    const navActions = document.querySelector('.nav-actions');
    if (!navActions) return;

    try {
      const me = await fetchMe();
      if (me) {
        const roleLabel   = me.role === 'admin' ? 'Admin' : me.role === 'provider' ? 'Provider' : 'Client';
        const profileHref = me.role === 'admin' ? htmlUrl('admin.html') : htmlUrl('profile.html');

        navActions.innerHTML = `
          <span class="user-chip">
            <i class="fas fa-user-circle"></i>
            ${esc(me.username || me.full_name)}
            <em>${roleLabel}</em>
          </span>
          <a class="btn-outline-light" href="${profileHref}">
            <i class="fas fa-user-edit"></i> My account
          </a>
          <button class="btn-light" id="logout-btn" type="button">
            <i class="fas fa-sign-out-alt"></i> Log out
          </button>
        `;
        document.getElementById('logout-btn').addEventListener('click', async () => {
          await apiFetch('logout.php', {});
          toast('You have been logged out.', 'info');
          setTimeout(() => { window.location.href = htmlUrl('login.html'); }, 600);
        });
      } else {
        // Not logged in – show sign up / log in links.
        navActions.innerHTML = `
          <a class="btn-outline-light" href="${htmlUrl('signup.html')}">
            <i class="fas fa-user-plus"></i> Sign up
          </a>
          <a class="btn-light" href="${htmlUrl('login.html')}">
            <i class="fas fa-sign-in-alt"></i> Log in
          </a>
        `;
      }
    } catch (e) {
      // Leave existing nav-actions content untouched on error.
    }
  });
})();
