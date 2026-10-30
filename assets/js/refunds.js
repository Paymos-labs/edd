(function ($) {
    'use strict';
    $(function () {
        var state = window.paymosEddRefunds, panel = $('#paymos-edd-refunds');
        if (!state || !panel.length) { return; }
        var labels = state.labels || {}, summary = $('<p>').appendTo(panel), status = $('<p role="status">').appendTo(panel);
        var buttons = {};
        ['full', 'overpayment'].forEach(function (mode) {
            buttons[mode] = $('<button type="button" class="button" disabled>').text(labels[mode]).appendTo(panel);
            buttons[mode].on('click', function () {
                buttons.full.prop('disabled', true); buttons.overpayment.prop('disabled', true);
                status.text(labels.pending || '');
                $.post(ajaxurl, {action: 'paymos_edd_refund', order_id: state.order_id, security: state.security,
                    mode: mode, paymos_refund_token: state.tokens[mode]}).done(function (response) {
                    if (response.data && response.data.paymos_refund) { window.location.reload(); return; }
                    status.text(response.data && response.data.message ? response.data.message : labels.request_failed); refresh();
                }).fail(function () { status.text(labels.unknown || ''); });
                // Keep the same token after a timeout. A new click recovers this exact intent.
            });
        });
        function refresh() {
            $.post(ajaxurl, {action: 'paymos_edd_refund', order_id: state.order_id, security: state.security, mode: 'summary'})
                .done(function (response) {
                    if (!response.success) { status.text(labels.request_failed); return; }
                    var value = response.data, asset = value.refund_asset || {};
                    summary.text((labels.full) + ': ' + (value.full_refund_amount || '0') + ' / '
                        + (labels.overpayment) + ': ' + (value.overpayment_refund_amount || '0')
                        + ' ' + (asset.currency || '') + '/' + (asset.network || '')
                        + (value.sender_address ? ' → ' + value.sender_address : ''));
                    buttons.full.prop('disabled', value.can_refund_full !== true);
                    buttons.overpayment.prop('disabled', value.can_refund_overpayment !== true);
                });
        }
        $.ajaxPrefilter(function (options) {
            if (typeof options.data !== 'string') { return; }
            var data = new URLSearchParams(options.data);
            if (data.get('action') === 'edd_process_refund_form' && data.get('order_id') === String(state.order_id)) {
                data.set('paymos_refund_token', state.tokens.full); options.data = data.toString();
            }
        });
        $(document).ajaxComplete(function (event, xhr) {
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.paymos_refund) { window.location.reload(); }
        });
        refresh();
    });
})(jQuery);
