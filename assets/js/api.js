/**
 * Wrapper HTTP REST pentru backend-ul PHP + MariaDB cu gestiune automata X-CSRF-Token si erori 401/409.
 */
window.ItpApi = (() => {
  let csrfToken = '';

  function setCsrfToken(token) {
    csrfToken = token || '';
  }

  const SESSION_INVALID_CODES = new Set(['UNAUTHENTICATED', 'SESSION_INVALID', 'SESSION_EXPIRED']);

  async function request(method, url, body = null, opts = {}) {
    const headers = { Accept: 'application/json' };
    if (body !== null) {
      headers['Content-Type'] = 'application/json';
    }
    if (!['GET', 'HEAD'].includes(method.toUpperCase()) && csrfToken) {
      headers['X-CSRF-Token'] = csrfToken;
    }

    const fetchOpts = {
      method: method.toUpperCase(),
      credentials: 'same-origin',
      headers,
      body: body !== null ? JSON.stringify(body) : undefined,
    };
    if (opts && opts.signal) {
      fetchOpts.signal = opts.signal;
    }

    const res = await fetch(url, fetchOpts);

    let data = {};
    const contentType = res.headers.get('content-type') || '';
    if (contentType.includes('application/json')) {
      data = await res.json().catch(() => ({}));
    }

    if (!res.ok) {
      const err = new Error(data.message || `Eroare HTTP ${res.status}`);
      err.status = res.status;
      err.code = data.error || 'HTTP_ERROR';
      err.data = data;
      // Doar codurile de sesiune invalida/expirata delogheaza (nu LOGIN_FAILED / MASTER_PIN_INVALID / INVALID_CURRENT_PIN)
      if (res.status === 401 && SESSION_INVALID_CODES.has(err.code) && typeof window.onItpUnauthorized === 'function') {
        window.onItpUnauthorized();
      }
      throw err;
    }

    if (data && data.csrf_token) {
      csrfToken = data.csrf_token;
    }
    return data;
  }

  return {
    setCsrfToken,
    get: (url, opts) => request('GET', url, null, opts),
    post: (url, body, opts) => request('POST', url, body || {}, opts),
    put: (url, body, opts) => request('PUT', url, body || {}, opts),
    del: (url, body, opts) => request('DELETE', url, body || {}, opts),
  };
})();
