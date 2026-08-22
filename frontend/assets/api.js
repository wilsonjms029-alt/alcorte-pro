/**
 * Cliente HTTP para API AlCorte Pro (/api/v1)
 */
(function (global) {
  'use strict';

  function basePath() {
    var meta = document.querySelector('meta[name="alcorte-base"]');
    if (meta && meta.content) {
      return meta.content.replace(/\/$/, '');
    }
    var path = window.location.pathname;
    if (path.indexOf('/frontend/') !== -1) {
      return path.split('/frontend/')[0];
    }
    return '';
  }

  function apiUrl(path) {
    var p = (path || '').replace(/^\//, '');
    return basePath() + '/api/v1/' + p;
  }

  function parseJsonResponse(res) {
    return res.text().then(function (text) {
      var data = {};
      try {
        data = text ? JSON.parse(text) : {};
      } catch (e) {
        throw new Error('Respuesta inválida del servidor');
      }
      if (!res.ok || data.ok === false) {
        var err = new Error(data.error || data.message || 'Error en la solicitud');
        err.status = res.status;
        err.data = data;
        throw err;
      }
      return data;
    });
  }

  function get(path, params) {
    var url = apiUrl(path);
    if (params) {
      var qs = new URLSearchParams(params).toString();
      if (qs) url += (url.indexOf('?') === -1 ? '?' : '&') + qs;
    }
    return fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }).then(parseJsonResponse);
  }

  function postJson(path, body) {
    return fetch(apiUrl(path), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(body || {}),
    }).then(parseJsonResponse);
  }

  function postForm(path, form) {
    var fd = form instanceof FormData ? form : new FormData(form);
    return fetch(apiUrl(path), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      body: fd,
    }).then(parseJsonResponse);
  }

  function redirectAfterAction(data, fallbackPage) {
    var page = (data.data && data.data.page) || fallbackPage || '';
    var msg = data.message || '';
    var extra = data.data || {};
    var qs = [];
    if (page) qs.push('page=' + encodeURIComponent(page));
    if (msg) qs.push('msg=' + encodeURIComponent(msg));
    ['wa_tel', 'wa_nom', 'wa_svc', 'wa_fecha', 'wa_hora'].forEach(function (k) {
      if (extra[k]) qs.push(k + '=' + encodeURIComponent(extra[k]));
    });
    var base = window.location.pathname;
    window.location.href = base + (qs.length ? '?' + qs.join('&') : '');
  }

  function bindApiForms(selector, apiPath, fallbackPage) {
    document.querySelectorAll(selector).forEach(function (form) {
      if (form.dataset.apiBound) return;
      form.dataset.apiBound = '1';
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = form.querySelector('[type="submit"]');
        if (btn) btn.disabled = true;
        postForm(apiPath, form)
          .then(function (data) {
            if (data.data && data.data.redirect) {
              window.location.href = data.data.redirect;
              return;
            }
            redirectAfterAction(data, fallbackPage);
          })
          .catch(function (err) {
            alert(err.message || 'Error al procesar la solicitud');
            if (btn) btn.disabled = false;
          });
      });
    });
  }

  global.AlCorte = {
    basePath: basePath,
    apiUrl: apiUrl,
    get: get,
    postJson: postJson,
    postForm: postForm,
    redirectAfterAction: redirectAfterAction,
    bindApiForms: bindApiForms,
  };
})(window);
