/* Client HTTP REST (window.ItpApi): Wrapper fetch cu gestiune automata X-CSRF-Token, timeout 15s, detectie offline si tratare centralizata 401/409. */
window.ItpApi = (() => {
  let csrfToken = '';

  /* Actualizare token CSRF: Stocheaza tokenul primit de la server pentru cererile POST/PUT/DELETE. */
  function setCsrfToken(token) {
    csrfToken = token || '';
  }

  const SESSION_INVALID_CODES = new Set(['UNAUTHENTICATED', 'SESSION_INVALID', 'SESSION_EXPIRED']);

  /* Executor cereri HTTP (request): Atasare header JSON/CSRF, AbortController timeout 15s, parsare raspuns si trigger logout la sesiune invalida. */
  async function request(method, url, body = null, opts = {}) {
    const headers = { Accept: 'application/json' };
    if (body !== null) {
      headers['Content-Type'] = 'application/json';
    }
    if (!['GET', 'HEAD'].includes(method.toUpperCase()) && csrfToken) {
      headers['X-CSRF-Token'] = csrfToken;
    }

    const timeoutMs = Number(opts && opts.timeoutMs) || 15000;
    const timeoutCtrl = new AbortController();
    let timedOut = false;
    const extSignal = opts && opts.signal ? opts.signal : null;
    const onExtAbort = () => timeoutCtrl.abort();
    if (extSignal) {
      if (extSignal.aborted) {
        timeoutCtrl.abort();
      } else {
        extSignal.addEventListener('abort', onExtAbort, { once: true });
      }
    }
    const timerId = setTimeout(() => {
      timedOut = true;
      timeoutCtrl.abort();
    }, timeoutMs);

    const fetchOpts = {
      method: method.toUpperCase(),
      credentials: 'same-origin',
      headers,
      body: body !== null ? JSON.stringify(body) : undefined,
      signal: timeoutCtrl.signal,
    };

    let res;
    try {
      res = await fetch(url, fetchOpts);
    } catch (netErr) {
      if (timedOut) {
        const timeoutErr = new Error('Conexiune slabă (timeout 15s) — verifică semnalul și reîncearcă.');
        timeoutErr.name = 'TimeoutError';
        timeoutErr.code = 'TIMEOUT';
        throw timeoutErr;
      }
      if (extSignal && extSignal.aborted) {
        throw netErr;
      }
      const offlineMsg = navigator.onLine === false
        ? 'Ești offline — verifică conexiunea Wi-Fi / 4G și reîncearcă.'
        : 'Nu s-a putut contacta serverul — verifică conexiunea și reîncearcă.';
      const err = new Error(offlineMsg);
      err.code = 'NETWORK_ERROR';
      throw err;
    } finally {
      clearTimeout(timerId);
      if (extSignal) extSignal.removeEventListener('abort', onExtAbort);
    }

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

  /* Interfata publica ItpApi: Expune metodele setCsrfToken, get, post, put si del. */
  return {
    setCsrfToken,
    get: (url, opts) => request('GET', url, null, opts),
    post: (url, body, opts) => request('POST', url, body || {}, opts),
    put: (url, body, opts) => request('PUT', url, body || {}, opts),
    del: (url, body, opts) => request('DELETE', url, body || {}, opts),
  };
})();
