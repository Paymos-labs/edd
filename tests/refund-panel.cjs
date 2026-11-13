const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function panel() {
    const nodes = [], requests = [];
    let reloads = 0;
    function node(html = '') {
        const el = { html, length: 1, value: '', disabled: /disabled/.test(html),
            text(value) { if (value === undefined) return this.value; this.value = value; return this; },
            appendTo() { return this; }, prop(key, value) { this[key] = value; return this; },
            on(event, callback) { this[event] = callback; return this; }, ajaxComplete() {} };
        nodes.push(el); return el;
    }
    const document = {}, root = node();
    function $(value) { if (typeof value === 'function') { value(); return; } return value === '#paymos-edd-refunds' ? root : node(value === document ? '' : value); }
    $.ajaxPrefilter = () => {};
    $.post = (url, data) => {
        const request = { data, done(fn) { this.resolve = fn; return this; }, fail(fn) { this.reject = fn; return this; } };
        requests.push(request); return request;
    };
    const state = { order_id: 42, security: 'nonce', tokens: { full: 'full_original_token', overpayment: 'over_original_token' },
        labels: { full: 'Full', overpayment: 'Overpayment', pending: 'Pending', unknown: 'Unknown', request_failed: 'Failed' } };
    vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../assets/js/refunds.js'), 'utf8'), {
        jQuery: $, document, ajaxurl: '/ajax', URLSearchParams, window: { paymosEddRefunds: state, location: { reload() { reloads++; } } }
    });
    const buttons = nodes.filter(n => n.html.startsWith('<button'));
    const status = nodes.find(n => n.html.includes('role="status"'));
    const summary = (full, over) => ({ success: true, data: { can_refund_full: full, can_refund_overpayment: over, full_refund_amount: '100', overpayment_refund_amount: '20' } });
    requests.shift().resolve(summary(true, true));
    return { buttons, status, requests, summary, reloads: () => reloads };
}

for (const eligible of [true, false]) {
    const p = panel(); p.buttons[0].click();
    const submit = p.requests.shift();
    submit.reject({ responseJSON: { data: { message: 'Refund temporarily unavailable' } } });
    assert.equal(p.status.value, 'Refund temporarily unavailable');
    const recover = p.requests.shift();
    assert.equal(recover.data.mode, 'recover');
    assert.equal(recover.data.paymos_refund_token, submit.data.paymos_refund_token);
    recover.resolve({ success: true, data: { paymos_refund: null } });
    assert.equal(p.requests[0].data.mode, 'summary');
    p.requests.shift().resolve(p.summary(eligible, false));
    assert.equal(p.buttons[0].disabled, !eligible);
    assert.equal(p.buttons[1].disabled, true);
    assert.equal(p.status.value, 'Refund temporarily unavailable');
    if (eligible) { p.buttons[0].click(); assert.equal(p.requests.shift().data.paymos_refund_token, submit.data.paymos_refund_token); }
}
{
    const p = panel(); p.buttons[0].click(); p.requests.shift().reject({});
    assert.equal(p.status.value, 'Unknown');
    assert.equal(p.buttons[0].disabled, true);
    const recovery = p.requests.shift(); assert.equal(recovery.data.mode, 'recover');
    recovery.resolve({ success: true, data: { paymos_refund: { cash_state: 'pending' } } });
    assert.equal(p.reloads(), 1);
    assert.equal(p.requests.length, 0, 'Existing intent is restored before any fresh refund request.');
}
console.log('PASS EDD refusal, eligibility refresh, stable token and unknown-intent recovery');
