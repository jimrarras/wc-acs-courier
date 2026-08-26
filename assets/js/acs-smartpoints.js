/**
 * ACS Smartpoints Picker — Checkout Frontend
 *
 * Handles the pickup point selection UI at the WooCommerce checkout.
 */
(function ($) {
    'use strict';

    const SP = {
        points: [],
        debounceTimer: null,
        selectedPoint: null,
        useSmartpoint: false,

        init() {
            this.bindEvents();
        },

        bindEvents() {
            // Toggle smartpoint picker visibility
            $(document).on('change', '#wc-acs-use-smartpoint', this.togglePicker.bind(this));

            // Search input with debounce
            $(document).on('input', '#wc-acs-smartpoint-search', this.onSearchInput.bind(this));

            // Select a point
            $(document).on('click', '.wc-acs-sp-item', this.selectPoint.bind(this));

            // Change selection
            $(document).on('click', '#wc-acs-smartpoint-change', this.changePicker.bind(this));

            // Listen for shipping method changes to show/hide the selector
            $(document.body).on('updated_checkout', this.onCheckoutUpdate.bind(this));
        },

        togglePicker() {
            const checked = $('#wc-acs-use-smartpoint').is(':checked');
            SP.useSmartpoint = checked;

            if (checked) {
                $('#wc-acs-smartpoint-picker').slideDown();
                // Load initial points using the shipping postcode
                const postcode = $('#shipping_postcode').val() || $('#billing_postcode').val() || '';
                if (postcode) {
                    this.loadPoints(postcode);
                }
            } else {
                $('#wc-acs-smartpoint-picker').slideUp();
                // Clear selection
                SP.selectedPoint = null;
                $('#wc-acs-smartpoint-id').val('');
                $('#wc-acs-smartpoint-name').val('');
                $('#wc-acs-smartpoint-address').val('');
                $('#wc-acs-smartpoint-zipcode').val('');
            }
        },

        onSearchInput() {
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => {
                const query = $('#wc-acs-smartpoint-search').val().trim();
                if (query.length >= 2) {
                    this.loadPoints(query);
                }
            }, 400);
        },

        loadPoints(search) {
            const $list = $('#wc-acs-smartpoint-list');
            $list.html('<p class="loading">' + wc_acs_sp.i18n.loading + '</p>');

            $.post(wc_acs_sp.ajax_url, {
                action: 'wc_acs_get_smartpoints',
                nonce: wc_acs_sp.nonce,
                search: search,
                country: $('#shipping_country').val() || $('#billing_country').val() || 'GR',
            })
                .done(function (res) {
                    if (res.success) {
                        SP.points = res.data.points || [];
                        SP.renderList();
                    } else {
                        $list.html('<p>' + wc_acs_sp.i18n.no_results + '</p>');
                    }
                })
                .fail(function () {
                    $list.html('<p>Error loading pickup points.</p>');
                });
        },

        renderList() {
            const $list = $('#wc-acs-smartpoint-list');
            $list.empty();

            if (this.points.length === 0) {
                $list.html('<p>' + wc_acs_sp.i18n.no_results + '</p>');
                return;
            }

            this.points.forEach(function (point) {
                const typeLabel =
                    point.type === 'locker' ? wc_acs_sp.i18n.locker : wc_acs_sp.i18n.store;
                const typeClass = point.type === 'locker' ? 'sp-locker' : 'sp-store';

                const $item = $('<div class="wc-acs-sp-item ' + typeClass + '">' +
                    '<div class="sp-name">' + SP.escapeHtml(point.name) + '</div>' +
                    '<div class="sp-address">' + SP.escapeHtml(point.address) + ', ' + SP.escapeHtml(point.zipcode) + '</div>' +
                    '<div class="sp-meta">' +
                        '<span class="sp-type">' + typeLabel + '</span>' +
                        (point.hours ? ' · ' + SP.escapeHtml(point.hours) : '') +
                    '</div>' +
                '</div>');

                $item.data('point', point);
                $list.append($item);
            });
        },

        selectPoint(e) {
            const $item = $(e.currentTarget);
            const point = $item.data('point');

            if (!point) return;

            // Save selection for restoration after checkout updates
            SP.selectedPoint = point;

            // Set hidden fields
            $('#wc-acs-smartpoint-id').val(point.id);
            $('#wc-acs-smartpoint-name').val(point.name);
            $('#wc-acs-smartpoint-address').val(point.address);
            $('#wc-acs-smartpoint-zipcode').val(point.zipcode);

            // Show selection
            $('#wc-acs-smartpoint-selected-name').text(point.name);
            $('#wc-acs-smartpoint-selected-address').text(point.address + ', ' + point.zipcode);
            $('#wc-acs-smartpoint-selected').slideDown();

            // Hide the list and search
            $('#wc-acs-smartpoint-search').hide();
            $('#wc-acs-smartpoint-list').slideUp();
        },

        changePicker(e) {
            e.preventDefault();
            // Clear selection
            SP.selectedPoint = null;
            $('#wc-acs-smartpoint-id').val('');
            $('#wc-acs-smartpoint-selected').slideUp();
            $('#wc-acs-smartpoint-search').show().val('').focus();
            $('#wc-acs-smartpoint-list').slideDown();
        },

        onCheckoutUpdate() {
            const $container = $('#wc-acs-smartpoint-container');
            if ($container.length === 0) {
                return;
            }

            // Restore smartpoint checkbox state
            if (SP.useSmartpoint) {
                const $checkbox = $('#wc-acs-use-smartpoint');
                if ($checkbox.length && !$checkbox.is(':checked')) {
                    $checkbox.prop('checked', true);
                    $('#wc-acs-smartpoint-picker').show();
                }
            }

            // Restore selected point after WC checkout refresh
            if (SP.selectedPoint) {
                const point = SP.selectedPoint;
                $('#wc-acs-smartpoint-id').val(point.id);
                $('#wc-acs-smartpoint-name').val(point.name);
                $('#wc-acs-smartpoint-address').val(point.address);
                $('#wc-acs-smartpoint-zipcode').val(point.zipcode);
                $('#wc-acs-smartpoint-selected-name').text(point.name);
                $('#wc-acs-smartpoint-selected-address').text(point.address + ', ' + point.zipcode);
                $('#wc-acs-smartpoint-selected').show();
                $('#wc-acs-smartpoint-search').hide();
                $('#wc-acs-smartpoint-list').hide();
            }
        },

        escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        },
    };

    $(document).ready(function () {
        SP.init();
    });
})(jQuery);
