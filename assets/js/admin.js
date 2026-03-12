(function ($) {
    'use strict';

    $(function () {

        /* ── Helpers ───────────────────────────────────────────────────── */
        function isValidApifyUrl(url) {
            try {
                var parsed = new URL(url);
                return parsed.protocol === 'https:' && parsed.hostname === 'api.apify.com';
            } catch (_) {
                return false;
            }
        }

        /* ── Settings Form ─────────────────────────────────────────────── */
        var $settingsForm   = $('#pc-settings-form');
        var $endpointInput  = $('#pc-apify-endpoint');
        var $endpointVal    = $('#pc-endpoint-validation');
        var $saveBtn        = $('#pc-save-settings-btn');
        var $saveStatus     = $('#pc-settings-status');
        var $saveIconOk     = $('#pc-save-icon-ok');
        var $syncBtn        = $('#pc-sync-btn');

        // Live validation on the endpoint field
        if ($endpointInput.length) {
            $endpointInput.on('input blur', function () {
                validateEndpointField();
            });
        }

        function validateEndpointField() {
            var val = $endpointInput.val().trim();
            if (val === '') {
                $endpointVal.removeClass('pc-field-ok pc-field-err').text('');
                return false;
            }
            if (isValidApifyUrl(val)) {
                $endpointVal
                    .removeClass('pc-field-err')
                    .addClass('pc-field-ok')
                    .html('<span class="dashicons dashicons-yes-alt"></span> Valid Apify URL');
                return true;
            } else {
                $endpointVal
                    .removeClass('pc-field-ok')
                    .addClass('pc-field-err')
                    .html('<span class="dashicons dashicons-warning"></span> Must start with <code>https://api.apify.com/</code>');
                return false;
            }
        }

        // Toggle token visibility
        $(document).on('click', '.pc-toggle-token', function () {
            var targetId = $(this).data('target');
            var $input   = $('#' + targetId);
            var isPass   = $input.attr('type') === 'password';
            $input.attr('type', isPass ? 'text' : 'password');
            $(this).find('.dashicons')
                .toggleClass('dashicons-visibility', ! isPass)
                .toggleClass('dashicons-hidden',     isPass);
        });

        if ($settingsForm.length) {
            $settingsForm.on('submit', function (e) {
                e.preventDefault();

                if (! validateEndpointField()) {
                    $saveStatus.removeClass('pc-success').addClass('pc-error')
                        .text('Fix the endpoint URL before saving.');
                    return;
                }

                $saveBtn.prop('disabled', true);
                $saveStatus.removeClass('pc-success pc-error').text('Saving…');

                $.ajax({
                    url:    pcInstagram.ajaxUrl,
                    method: 'POST',
                    data: {
                        action:            'pc_instagram_save_settings',
                        nonce:             pcInstagram.settingsNonce,
                        apify_dataset_url: $endpointInput.val().trim(),
                        apify_token:       $('#pc-apify-token').val(),
                    },
                    success: function (res) {
                        if (res.success) {
                            $saveStatus.removeClass('pc-error').addClass('pc-success').text('Settings saved.');
                            $saveIconOk.show();
                            setTimeout(function () { $saveIconOk.hide(); }, 2500);

                            // If endpoint is now valid, enable the sync button
                            if (isValidApifyUrl($endpointInput.val().trim())) {
                                $syncBtn.prop('disabled', false).removeAttr('title');
                                $('#pc-sync-status .pc-error').remove();
                                $('.pc-notice').slideUp(200);
                            }
                        } else {
                            var msg = res.data && res.data.message ? res.data.message : 'Unknown error';
                            $saveStatus.removeClass('pc-success').addClass('pc-error').text(msg);
                        }
                    },
                    error: function (_xhr, _s, err) {
                        $saveStatus.removeClass('pc-success').addClass('pc-error').text('Request failed: ' + err);
                    },
                    complete: function () {
                        $saveBtn.prop('disabled', false);
                    },
                });
            });
        }

        /* ── Manual Sync ───────────────────────────────────────────────── */
        var $status     = $('#pc-sync-status');
        var $log        = $('#pc-sync-log');
        var $logContent = $('#pc-sync-log-content');
        var $spinIcon   = $syncBtn.find('.pc-spin-icon');

        if ($syncBtn.length) {
            $syncBtn.on('click', function () {
                // Client-side guard: verify endpoint is valid before submitting
                var currentEndpoint = $endpointInput.length
                    ? $endpointInput.val().trim()
                    : pcInstagram.endpointValid ? 'https://api.apify.com/' : '';

                if (! isValidApifyUrl(currentEndpoint) && ! pcInstagram.endpointValid) {
                    $status.removeClass('pc-success').addClass('pc-error')
                        .text('Set a valid Apify endpoint in Settings before syncing.');
                    return;
                }

                $syncBtn.prop('disabled', true);
                $spinIcon.show();
                $status.removeClass('pc-success pc-error').text('Syncing… this may take up to 2 minutes.');
                $log.hide();
                $logContent.html('');

                $.ajax({
                    url:     pcInstagram.ajaxUrl,
                    method:  'POST',
                    data: {
                        action: 'pc_instagram_manual_sync',
                        nonce:  pcInstagram.nonce,
                    },
                    timeout: 180000,
                    success: function (res) {
                        if (res.success) {
                            var d = res.data;
                            $status.removeClass('pc-error').addClass('pc-success')
                                .text('Done! ' + d.ok + ' synced, ' + d.errors + ' errors — ' + d.total + ' total.');
                            renderLog(d.results);
                        } else {
                            var msg = res.data && res.data.message ? res.data.message : 'Unknown error';
                            $status.removeClass('pc-success').addClass('pc-error').text('Error: ' + msg);
                        }
                    },
                    error: function (_xhr, _s, err) {
                        $status.removeClass('pc-success').addClass('pc-error').text('Request failed: ' + err);
                    },
                    complete: function () {
                        $syncBtn.prop('disabled', false);
                        $spinIcon.hide();
                    },
                });
            });

            $('#pc-log-close').on('click', function () { $log.hide(); });
        }

        function renderLog(results) {
            if (!results || !results.length) return;
            var lines = results.map(function (r) {
                var cls  = r.ok ? 'pc-log-ok' : 'pc-log-error';
                var icon = r.ok ? '✓' : '✗';
                var msg  = r.ok
                    ? icon + ' ' + r.post_id + ' → row #' + r.row_id
                    : icon + ' ' + r.post_id + ' — ' + (r.error || 'error');
                return '<div class="' + cls + '">' + $('<span>').text(msg).html() + '</div>';
            });
            $logContent.html(lines.join(''));
            $log.show();
        }

        /* ── Copy REST URL ─────────────────────────────────────────────── */
        $(document).on('click', '.pc-copy-btn', function () {
            var text = $(this).data('copy');
            if (!text) return;
            var $el = $(this);

            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function () { flashCopy($el); });
            } else {
                var $tmp = $('<textarea>').val(text).appendTo('body').select();
                document.execCommand('copy');
                $tmp.remove();
                flashCopy($el);
            }
        });

        function flashCopy($el) {
            var orig = $el.html();
            $el.html('<span class="dashicons dashicons-yes"></span> Copied!').prop('disabled', true);
            setTimeout(function () { $el.html(orig).prop('disabled', false); }, 1800);
        }

    });
}(jQuery));
