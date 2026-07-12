(function () {
  'use strict';
  var config = window.PaymosEddConnect;
  if (!config) return;
  function post(action) {
    var body = new URLSearchParams({ action: action, nonce: config.nonce });
    return fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: body.toString() })
      .then(function (response) { return response.json(); });
  }
  function status(message, failed) {
    var node = document.getElementById('paymos-edd-connect-status');
    if (node) { node.textContent = message; node.style.color = failed ? '#b91c1c' : '#374151'; }
  }
  function poll(interval) {
    window.setTimeout(function check() {
      post(config.pollAction).then(function (response) {
        if (!response.success) { status((response.data && response.data.message) || 'Paymos connection failed.', true); return; }
        if (response.data.status === 'connected') { status('Paymos connected. Reloading…', false); window.setTimeout(function () { window.location.reload(); }, 700); return; }
        status('Waiting for approval in Paymos…', false);
        window.setTimeout(check, response.data.status === 'slow_down' ? interval + 5000 : interval);
      }).catch(function () { status('Paymos connection failed.', true); });
    }, interval);
  }
  document.addEventListener('click', function (event) {
    var button = event.target.closest('#paymos-edd-connect-button');
    if (!button) return;
    event.preventDefault(); button.disabled = true; status('Starting secure connection…', false);
    post(config.startAction).then(function (response) {
      if (!response.success) throw new Error((response.data && response.data.message) || 'Paymos connection failed.');
      var popup = window.open(response.data.verification_url, '_blank', 'noopener,noreferrer');
      if (!popup) status('Allow pop-ups and try again.', true);
      else { status('Waiting for approval. Code: ' + response.data.user_code, false); poll(Math.max(1, Number(response.data.interval || 5)) * 1000); }
    }).catch(function (error) { status(error.message || 'Paymos connection failed.', true); button.disabled = false; });
  });
})();
