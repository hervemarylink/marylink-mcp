/**
 * MCP No Headless - Admin Scripts
 */

(function($) {
    'use strict';

    const api = {
        call: function(endpoint, method, data) {
            return $.ajax({
                url: mcpnhAdmin.restUrl + endpoint,
                method: method || 'GET',
                data: data,
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', mcpnhAdmin.nonce);
                }
            });
        }
    };

    function showResult(message, type) {
        const $result = $('#mcpnh-action-result');
        const className = type === 'error' ? 'notice-error' : 'notice-success';
        $result.html('<div class="notice ' + className + ' is-dismissible"><p>' + message + '</p></div>');
    }

    function setLoading($btn, loading) {
        if (loading) {
            $btn.addClass('mcpnh-loading').prop('disabled', true);
        } else {
            $btn.removeClass('mcpnh-loading').prop('disabled', false);
        }
    }

    // Recalculate Scores
    $(document).on('click', '.mcpnh-btn-recalc-scores', function() {
        const $btn = $(this);

        if (!confirm('This will recalculate all publication scores. Continue?')) {
            return;
        }

        setLoading($btn, true);

        api.call('recalculate-scores', 'POST')
            .done(function(response) {
                showResult('Scores recalculated: ' + response.updated + ' publications updated in ' + response.elapsed_ms + 'ms', 'success');
            })
            .fail(function(xhr) {
                const message = xhr.responseJSON?.message || 'Failed to recalculate scores';
                showResult(message, 'error');
            })
            .always(function() {
                setLoading($btn, false);
            });
    });

    // Reset Rate Limits
    $(document).on('click', '.mcpnh-btn-reset-rates', function() {
        const $btn = $(this);
        const resetAll = $btn.data('all');
        const userId = $btn.data('user-id');

        let confirmMsg = resetAll
            ? 'This will reset ALL rate limits. Continue?'
            : 'Reset rate limits for user #' + userId + '?';

        if (!confirm(confirmMsg)) {
            return;
        }

        setLoading($btn, true);

        const data = resetAll ? { all: true } : { user_id: userId };

        api.call('rate-limits/reset', 'POST', data)
            .done(function(response) {
                showResult(response.message, 'success');
            })
            .fail(function(xhr) {
                const message = xhr.responseJSON?.message || 'Failed to reset rate limits';
                showResult(message, 'error');
            })
            .always(function() {
                setLoading($btn, false);
            });
    });

    // Run Diagnostics
    $(document).on('click', '.mcpnh-btn-run-diagnostics', function() {
        const $btn = $(this);
        setLoading($btn, true);

        api.call('diagnostics', 'GET')
            .done(function(response) {
                let html = '<div class="notice notice-info"><p><strong>Diagnostics Results:</strong></p><ul>';

                $.each(response.diagnostics, function(key, test) {
                    const icon = test.ok ? '✓' : '✗';
                    const color = test.ok ? 'green' : 'red';
                    html += '<li style="color:' + color + '">' + icon + ' ' + test.test;
                    if (test.debug_id) {
                        html += ' <code>' + test.debug_id + '</code>';
                    }
                    if (test.error) {
                        html += ' - ' + test.error;
                    }
                    html += '</li>';
                });

                html += '</ul></div>';
                $('#mcpnh-action-result').html(html);
            })
            .fail(function(xhr) {
                const message = xhr.responseJSON?.message || 'Failed to run diagnostics';
                showResult(message, 'error');
            })
            .always(function() {
                setLoading($btn, false);
            });
    });

    // Copy debug ID to clipboard
    $(document).on('click', '.mcpnh-debug-id', function() {
        const text = $(this).text();
        navigator.clipboard.writeText(text).then(function() {
            // Brief visual feedback
            $(this).css('background', '#d4edda');
            setTimeout(function() {
                $(this).css('background', '');
            }.bind(this), 500);
        }.bind(this));
    });

})(jQuery);
