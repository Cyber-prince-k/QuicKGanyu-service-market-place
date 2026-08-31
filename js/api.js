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

    let res, payload;
    try {
      res = await fetch(apiUrl(endpoint), options);
    } catch (networkErr) {
      // fetch() itself threw — server unreachable, CORS blocked, etc.
      return { ok: false, error: 'Could not reach the server. Check your connection and try again.' };
    }

    try {
      payload = await res.json();
    } catch {
      // Server replied but not with valid JSON (e.g. a PHP fatal error page)
      return { ok: false, error: `Server error (HTTP ${res.status}). Check the PHP error log.` };
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
        // Build initials from full name (up to 2 letters)
        const initials = me.full_name
          .trim()
          .split(/\s+/)
          .slice(0, 2)
          .map(w => w[0].toUpperCase())
          .join('');

        const roleLabel = me.role === 'admin'
          ? 'Admin'
          : me.role === 'provider'
            ? 'Provider'
            : 'Client';

        // Each role gets its own dashboard link
        const dashHref = me.role === 'admin'
          ? htmlUrl('admin.html')
          : me.role === 'provider'
            ? htmlUrl('provider-dashboard.html')
            : htmlUrl('customer-dashboard.html');

        // Colour the avatar ring by role
        const roleColor = me.role === 'admin'
          ? '#f5c842'
          : me.role === 'provider'
            ? '#4ade80'
            : '#60a5fa';

        navActions.innerHTML = `
          <a class="nav-user-chip" href="${dashHref}" title="Go to my dashboard">
            <span class="nav-avatar" style="background:${roleColor};">${esc(initials)}</span>
            <span class="nav-user-info">
              <span class="nav-user-name">${esc(me.username || me.full_name)}</span>
              <span class="nav-user-role">${roleLabel}</span>
            </span>
          </a>
          <button class="btn-light nav-logout-btn" id="logout-btn" type="button">
            <i class="fas fa-sign-out-alt"></i> Log out
          </button>
        `;

        document.getElementById('logout-btn').addEventListener('click', async () => {
          try {
            await apiFetch('logout.php', {});
          } catch (_) { /* ignore network errors on logout */ }
          toast('You have been logged out.', 'info');
          setTimeout(() => { window.location.href = htmlUrl('login.html'); }, 600);
        });

        // ── Inject role-specific links into .nav-links ──────────────────
        const navLinks = document.querySelector('.nav-links');
        if (navLinks) {
          // Remove any stale injected links from a previous render
          navLinks.querySelectorAll('.nav-injected').forEach(el => el.remove());

          if (me.role === 'provider' || me.role === 'client') {
            // Messages link — goes to provider-dashboard#messages for providers
            // and messages.html for customers
            const msgHref = me.role === 'provider'
              ? htmlUrl('provider-dashboard.html') + '#messages'
              : htmlUrl('messages.html');

            const msgLink = document.createElement('a');
            msgLink.href      = msgHref;
            msgLink.className = 'nav-injected nav-messages-link';
            msgLink.innerHTML = '<i class="fas fa-comments"></i> Messages';

            // Highlight if we're already on the messages page
            const onMessages = window.location.pathname.includes('messages') ||
                               (window.location.hash === '#messages');
            if (onMessages) msgLink.style.color = '#f5c842';

            navLinks.appendChild(msgLink);
          }

          if (me.role === 'admin') {
            const adminLink = document.createElement('a');
            adminLink.href      = htmlUrl('admin.html');
            adminLink.className = 'nav-injected';
            adminLink.innerHTML = '<i class="fas fa-user-shield"></i> Dashboard';
            navLinks.appendChild(adminLink);
          }
        }

      } else {
        // Not logged in — show sign up / log in links
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
      // Session check failed — leave nav untouched
    }
  });
})();
