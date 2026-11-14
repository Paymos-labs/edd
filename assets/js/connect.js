(function () {
  'use strict';
  var config = window.PaymosEddConnect;
  if (!config) return;
  var labels = config.labels;

  // Set when the browser refused the approval tab and the merchant has to open it
  // themselves. While set, polling must not overwrite the status line: the link and
  // the user code shown there are the only remaining way to finish the connection.
  var awaitingManualApproval = false;

  function post(action, flowId) {
    var body = new URLSearchParams({ action: action, nonce: config.nonce });
    if (flowId) body.set('flow_id', flowId);
    return fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: body.toString() })
      .then(function (response) { return response.json(); });
  }
  function statusNode() { return document.getElementById('paymos-edd-connect-status'); }
  function status(message, failed, force) {
    if (awaitingManualApproval && !force) return;
    var node = statusNode();
    if (node) { node.textContent = message; node.style.color = failed ? '#b91c1c' : '#374151'; }
  }
  // Renders the recovery path as real markup so the merchant can still reach the
  // approval page after the browser blocked the tab.
  function manualApproval(url, userCode) {
    var node = statusNode();
    if (!node) return;
    awaitingManualApproval = true;
    node.textContent = '';
    node.style.color = '#b91c1c';
    var link = document.createElement('a');
    link.href = url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = labels.approval_link;
    node.appendChild(document.createTextNode(labels.blocked_tab + ' '));
    node.appendChild(link);
    node.appendChild(document.createTextNode(' ' + labels.code + ' ' + userCode));
  }
  function terminalError(message) {
    awaitingManualApproval = false;
    status(message, true, true);
    var button = document.getElementById('paymos-edd-connect-button');
    if (button) button.disabled = false;
  }

  function poll(interval, flowId) {
    window.setTimeout(function check() {
      post(config.pollAction, flowId).then(function (response) {
        if (!response.success) { terminalError((response.data && response.data.message) || labels.failed); return; }
        if (response.data.status === 'connected') {
          awaitingManualApproval = false;
          status(labels.connected, false, true);
          window.setTimeout(function () { window.location.reload(); }, 700);
          return;
        }
        // retry: Paymos could not be reached this time. The request is kept, so back off like slow_down.
        var retry = response.data.status === 'retry';
        status((retry && response.data.message) || labels.waiting, false);
        window.setTimeout(check, retry || response.data.status === 'slow_down' ? interval + 5000 : interval);
      }).catch(function () { terminalError(labels.failed); });
    }, interval);
  }
  document.addEventListener('click', function (event) {
    var button = event.target.closest('#paymos-edd-connect-button');
    if (!button) return;
    event.preventDefault();
    button.disabled = true;
    awaitingManualApproval = false;
    status(labels.starting, false, true);

    // Open the tab now, while the click is still a trusted user activation. Browsers
    // only honour window.open for a few seconds after the gesture, so opening it in
    // the start-request callback is blocked whenever that request is slow. No feature
    // string is passed: any feature string asks for a popup window, which blockers
    // reject far more often than a plain tab.
    var tab = window.open('', '_blank');
    if (tab) { try { tab.opener = null; } catch (error) { /* cross-origin hardening only */ } }

    post(config.startAction).then(function (response) {
      if (!response.success) throw new Error((response.data && response.data.message) || labels.failed);
      if (tab && !tab.closed) {
        tab.location = response.data.verification_url;
        status(labels.waiting + ' ' + labels.code + ' ' + response.data.user_code, false, true);
      } else {
        manualApproval(response.data.verification_url, response.data.user_code);
      }
      // Polling starts either way: a merchant who opens the link manually must still
      // have their approval picked up.
      poll(Math.max(1, Number(response.data.interval || 5)) * 1000, response.data.flow_id);
    }).catch(function (error) {
      if (tab && !tab.closed) tab.close();
      terminalError(error.message || labels.failed);
    });
  });
})();
