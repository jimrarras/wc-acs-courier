/**
 * ACS Courier Admin JavaScript
 *
 * Handles voucher creation, printing (PDF), deletion, and tracking in the order metabox.
 */
(function ($) {
    'use strict';

    const ACS = {
        init() {
            this.bindEvents();
        },

        bindEvents() {
            // Settings page — Test connection
            $(document).on('click', '#wc-acs-test-connection', this.testConnection);
            $(document).on('click', '#wc-acs-refresh-points', this.refreshPoints);

            // Order metabox — Voucher actions
            $(document).on('click', '.wc-acs-create-voucher', this.createVoucher);
            $(document).on('click', '.wc-acs-print-voucher', this.printVoucher);
            $(document).on('click', '.wc-acs-delete-voucher', this.deleteVoucher);
            $(document).on('click', '.wc-acs-track-voucher', this.trackVoucher);
            $(document).on('click', '.wc-acs-issue-pickup-list', this.issuePickupList);
            $(document).on('click', '.wc-acs-print-pickup-list', this.printPickupList);

            // Order screen: change the ACS Point while no voucher exists
            $(document).on('input', '.wc-acs-point-search', this.searchPoints);
            $(document).on('click', '.wc-acs-point-results li', this.setOrderPoint);
        },

        getOrderId() {
            return $('#wc-acs-voucher-box').data('order-id');
        },

        showNotice(message, type) {
            const $notice = $('.wc-acs-notice');
            $notice
                .removeClass('success error')
                .addClass(type || 'success')
                .text(message)
                .slideDown();

            if (type !== 'error') {
                setTimeout(() => $notice.slideUp(), 5000);
            }
        },

        // ─── Test Connection ───────────────────────────────────
        testConnection(e) {
            e.preventDefault();
            const $btn = $(this);
            const $result = $('#wc-acs-test-result');

            $btn.prop('disabled', true);
            $result.text(wc_acs.i18n.testing).removeClass('success error');

            $.post(wc_acs.ajax_url, {
                action: 'wc_acs_test_connection',
                nonce: wc_acs.nonce,
            })
                .done(function (res) {
                    if (res.success) {
                        $result.text('✓ ' + res.data).addClass('success');
                    } else {
                        $result.text('✗ ' + res.data).addClass('error');
                    }
                })
                .fail(function () {
                    $result.text('✗ Request failed').addClass('error');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        },

        // ─── Refresh ACS points ────────────────────────────────
        refreshPoints(e) {
            e.preventDefault();
            const $btn = $(this);
            const $result = $('#wc-acs-refresh-result');

            $btn.prop('disabled', true);
            $result.text(wc_acs.i18n.refreshing).removeClass('success error');

            $.post(wc_acs.ajax_url, {
                action: 'wc_acs_refresh_points',
                nonce: wc_acs.nonce,
            })
                .done(function (res) {
                    if (res.success) {
                        $result.text('✓ ' + res.data.count + ' (' + res.data.fetched + ')').addClass('success');
                        $('#wc-acs-points-status').text(res.data.count + ' / ' + res.data.fetched);
                    } else {
                        $result.text('✗ ' + res.data).addClass('error');
                    }
                })
                .fail(function () {
                    $result.text('✗ Request failed').addClass('error');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        },

        // ─── ACS Point change control ───────────────────────────
        pointSearchTimer: null,

        searchPoints() {
            const $input = $(this);
            const $box = $input.closest('.wc-acs-point-admin');
            const $results = $box.find('.wc-acs-point-results');
            const q = $.trim($input.val());

            clearTimeout(ACS.pointSearchTimer);
            if (q.length < 2) {
                $results.empty().prop('hidden', true);
                return;
            }

            ACS.pointSearchTimer = setTimeout(function () {
                $.post(wc_acs.ajax_url, { action: 'wc_acs_search_points', nonce: wc_acs.nonce, q: q })
                    .done(function (res) {
                        $results.empty();
                        if (!res.success || !res.data.points.length) {
                            $results.prop('hidden', true);
                            return;
                        }
                        res.data.points.forEach(function (p) {
                            $('<li>').attr('data-id', p.id).addClass('wc-acs-point-result--' + p.type).text(p.label).appendTo($results);
                        });
                        $results.prop('hidden', false);
                    });
            }, 300);
        },

        setOrderPoint() {
            const $li = $(this);
            const $box = $li.closest('.wc-acs-point-admin');

            $.post(wc_acs.ajax_url, {
                action: 'wc_acs_set_order_point',
                nonce: wc_acs.nonce,
                order_id: $box.data('order-id'),
                point_id: $li.data('id'),
            })
                .done(function (res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        window.alert(res.data);
                    }
                })
                .fail(function () {
                    window.alert('Request failed.');
                });
        },

        // ─── Create Voucher ───────────────────────────────────
        createVoucher(e) {
            e.preventDefault();
            const $btn = $(this);
            const originalHtml = $btn.html();
            $btn.prop('disabled', true).text(wc_acs.i18n.creating);

            // Collect extra services
            const services = [];
            $('.wc-acs-service:checked').each(function () {
                services.push($(this).val());
            });

            $.post(wc_acs.ajax_url, {
                action: 'wc_acs_create_voucher',
                nonce: wc_acs.nonce,
                order_id: ACS.getOrderId(),
                item_qty: $('#wc-acs-item-qty').val(),
                weight: $('#wc-acs-weight').val(),
                services: services.join(','),
                notes: $('#wc-acs-notes').val(),
            })
                .done(function (res) {
                    if (res.success) {
                        // Reload the page to show the new voucher
                        location.reload();
                    } else {
                        ACS.showNotice(res.data, 'error');
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                })
                .fail(function () {
                    ACS.showNotice('Request failed.', 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        // ─── Print Voucher ────────────────────────────────────
        printVoucher(e) {
            e.preventDefault();
            const $btn = $(this);
            const printType = $btn.data('type');

            $btn.prop('disabled', true);

            $.post(wc_acs.ajax_url, {
                action: 'wc_acs_print_voucher',
                nonce: wc_acs.nonce,
                order_id: ACS.getOrderId(),
                print_type: printType,
            })
                .done(function (res) {
                    if (res.success && res.data.pdf_base64) {
                        ACS.openPDF(res.data.pdf_base64, res.data.filename);
                    } else {
                        ACS.showNotice(res.data || 'Print failed.', 'error');
                    }
                })
                .fail(function () {
                    ACS.showNotice('Print request failed.', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        },

        // ─── Delete Voucher ───────────────────────────────────
        deleteVoucher(e) {
            e.preventDefault();

            if (!confirm(wc_acs.i18n.confirm_delete)) {
                return;
            }

            const $btn = $(this);
            $btn.prop('disabled', true);

            $.post(wc_acs.ajax_url, {
                action: 'wc_acs_delete_voucher',
                nonce: wc_acs.nonce,
                order_id: ACS.getOrderId(),
            })
                .done(function (res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        ACS.showNotice(res.data, 'error');
                    }
                })
                .fail(function () {
                    ACS.showNotice('Delete failed.', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        },

        // ─── Track Voucher ────────────────────────────────────
        trackVoucher(e) {
            e.preventDefault();
            const $btn = $(this);
            const $details = $('#wc-acs-tracking-details');

            $btn.prop('disabled', true);

            $.post(wc_acs.ajax_url, {
                action: 'wc_acs_track_voucher',
                nonce: wc_acs.nonce,
                order_id: ACS.getOrderId(),
            })
                .done(function (res) {
                    if (res.success) {
                        const checkpoints = res.data.checkpoints || [];
                        let html = '<strong>Tracking Details:</strong>';
                        html += '<table class="widefat striped" style="margin-top:5px;">';
                        html += '<thead><tr><th>Date</th><th>Action</th><th>Location</th></tr></thead><tbody>';

                        if (checkpoints.length === 0) {
                            html += '<tr><td colspan="3">No tracking data yet.</td></tr>';
                        } else {
                            checkpoints.forEach(function (cp) {
                                const date = cp.checkpoint_date_time
                                    ? new Date(cp.checkpoint_date_time).toLocaleString()
                                    : '';
                                html +=
                                    '<tr><td>' +
                                    ACS.escapeHtml(date) +
                                    '</td><td>' +
                                    ACS.escapeHtml(cp.checkpoint_action || '') +
                                    '</td><td>' +
                                    ACS.escapeHtml(cp.checkpoint_location || '') +
                                    '</td></tr>';
                            });
                        }

                        html += '</tbody></table>';
                        $details.html(html).slideDown();
                    } else {
                        ACS.showNotice(res.data, 'error');
                    }
                })
                .fail(function () {
                    ACS.showNotice('Tracking request failed.', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        },

        // ─── Issue Pickup List ────────────────────────────────
        issuePickupList(e) {
            e.preventDefault();
            const $btn = $(this);
            $btn.prop('disabled', true);

            $.post(wc_acs.ajax_url, {
                action: 'wc_acs_issue_pickup_list',
                nonce: wc_acs.nonce,
                order_id: ACS.getOrderId(),
            })
                .done(function (res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        ACS.showNotice(res.data, 'error');
                    }
                })
                .fail(function () {
                    ACS.showNotice('Failed to issue pickup list.', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        },

        // ─── Print Pickup List ────────────────────────────────
        printPickupList(e) {
            e.preventDefault();
            const $btn = $(this);
            $btn.prop('disabled', true);

            $.post(wc_acs.ajax_url, {
                action: 'wc_acs_print_pickup_list',
                nonce: wc_acs.nonce,
                order_id: ACS.getOrderId(),
            })
                .done(function (res) {
                    if (res.success && res.data.pdf_base64) {
                        ACS.openPDF(res.data.pdf_base64, res.data.filename);
                    } else {
                        ACS.showNotice(res.data || 'Print failed.', 'error');
                    }
                })
                .fail(function () {
                    ACS.showNotice('Print failed.', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        },

        // ─── Utility: Open PDF ────────────────────────────────
        openPDF(base64Data, filename) {
            try {
                // Handle if data is a nested object (ACS sometimes wraps it)
                if (typeof base64Data === 'object') {
                    base64Data = Object.values(base64Data)[0];
                }

                const byteCharacters = atob(base64Data);
                const byteNumbers = new Array(byteCharacters.length);
                for (let i = 0; i < byteCharacters.length; i++) {
                    byteNumbers[i] = byteCharacters.charCodeAt(i);
                }
                const byteArray = new Uint8Array(byteNumbers);
                const blob = new Blob([byteArray], { type: 'application/pdf' });
                const url = URL.createObjectURL(blob);

                // Open in new tab
                const win = window.open(url, '_blank');
                if (!win) {
                    // Fallback: download
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = filename || 'acs-voucher.pdf';
                    a.click();
                }

                // Revoke the object URL after a delay to free memory
                setTimeout(function () {
                    URL.revokeObjectURL(url);
                }, 60000);
            } catch (err) {
                ACS.showNotice('Failed to open PDF: ' + err.message, 'error');
            }
        },

        // ─── Utility: Escape HTML ─────────────────────────────
        escapeHtml(text) {
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        },
    };

    $(document).ready(function () {
        ACS.init();
    });
})(jQuery);
