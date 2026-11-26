/**
 * Wrapper HTTP REST pentru backend-ul PHP + MariaDB cu gestiune automata X-CSRF-Token si erori 401/409.
 */
window.ItpApi = (() => {
  let csrfToken = '';

  function setCsrfToken(token) {
    csrfToken = token || '';
  }

  async function request(method, url, body = null) {
    const headers = { Accept: 'application/json' };
    if (body !== null) {
      headers['Content-Type'] = 'application/json';
    }
    if (!['GET', 'HEAD'].includes(method.toUpperCase()) && csrfToken) {
      headers['X-CSRF-Token'] = csrfToken;
    }

    const res = await fetch(url, {
      method: method.toUpperCase(),
      credentials: 'same-origin',
      headers,
      body: body !== null ? JSON.stringify(body) : undefined,
    });

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
      if (res.status === 401 && typeof window.onItpUnauthorized === 'function') {
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
    get: (url) => request('GET', url),
    post: (url, body) => request('POST', url, body || {}),
    put: (url, body) => request('PUT', url, body || {}),
    del: (url, body) => request('DELETE', url, body || {}),
  };
})();
