/**
 * MetroVPS B2B - Dynamic Package Detail Card
 *
 * Auto-detects MetroVPS B2B <select> dropdowns, reads product metadata
 * from a global JSON blob injected by PHP, and renders a styled detail
 * card beneath the selected option.
 */
(function () {
    'use strict';

    var CARD_ID = 'metrovps-detail-card';
    var METADATA_ID = 'metrovps-product-metadata';
    var SELECT_NAME_MATCH = 'configoption';

    /* Helpers */

    function parseNum(v, fallback) {
        if (v === undefined || v === null || v === '') return fallback;
        var n = parseFloat(v);
        return isNaN(n) ? fallback : n;
    }

    function fmtPrice(value, symbol) {
        symbol = symbol || '\u09f3';  // ৳
        var n = parseNum(value, 0);
        if (n === Math.floor(n)) return symbol + n.toLocaleString();
        return symbol + n.toFixed(2);
    }

    function escHtml(s) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(s));
        return div.innerHTML;
    }

    /* Render */

    function renderCard(product) {
        if (!product || !product.id) return '';

        var cpu     = parseNum(product.cpu_core || product.vcpu_cores, 0);
        var memory  = parseNum(product.memory_gb || product.ram_gb, 0);
        var storage = parseNum(product.storage_gb || product.disk_gb, 0);
        var bw      = parseNum(product.traffic_gb || product.bandwidth_gb || product.network_bandwidth_gb, 0);
        var ipv4    = parseNum(product.ipv4_count || product.ipv4 || product.ip_count, 0);
        var loc     = product.product_location || product.location || '-';
        var title   = product.product_title || product.title || 'Unknown Package';
        var monthly = product.reseller_monthly_price || product.monthly_price || 0;
        var annual  = product.reseller_annual_price || product.annual_price || 0;

        var html = '<div class="metrovps-detail-card">' +
            '<div class="metrovps-detail-header">' +
                '<h3>' + escHtml(title) + '</h3>' +
            '</div>' +
            '<div class="metrovps-detail-grid">' +
                gridItem('vCPU Cores', cpu) +
                gridItem('RAM', memory + ' GB') +
                gridItem('Storage', storage + ' GB SSD') +
                specItem('Bandwidth', bw, ' GB') +
                specItem('IPv4 Addresses', ipv4, '') +
                gridItem('Location', escHtml(loc)) +
                gridItem('Monthly Price', fmtPrice(monthly) + '/mo') +
                fullRow('Annual Price', fmtPrice(annual) + '/yr') +
            '</div>' +
        '</div>';

        return html;
    }

    function gridItem(label, value) {
        return '<div class="metrovps-detail-item">' +
            '<span class="metrovps-detail-label">' + escHtml(label) + '</span>' +
            '<span class="metrovps-detail-value">' + escHtml(value) + '</span></div>';
    }

    /* A spec row, dropped entirely when the API returned no value for it.
       Bandwidth is a plain quantity, not a price, so it must not go through
       fmtPrice() — that would prepend the currency symbol. */
    function specItem(label, value, suffix) {
        var n = parseNum(value, 0);
        if (!n) return '';
        return gridItem(label, n.toLocaleString() + suffix);
    }

    function fullRow(label, value) {
        return '<div class="metrovps-detail-item metrovps-detail-full">' +
            '<span class="metrovps-detail-label">' + escHtml(label) + '</span>' +
            '<span class="metrovps-detail-value">' + escHtml(value) + '</span></div>';
    }

    /* Core logic */

    function attachToSelect(select, products) {
        var card = document.createElement('div');
        card.id = CARD_ID;
        card.className = 'metrovps-detail-container';
        select.parentNode.insertBefore(card, select.nextSibling);

        function handleChange() {
            var pid = select.value;
            card.innerHTML = '';

            if (!pid || !products[pid]) return;

            card.innerHTML = renderCard(products[pid]);
        }

        if (select.addEventListener) {
            select.addEventListener('change', handleChange);
        } else {
            select.attachEvent('onchange', handleChange);
        }

        if (select.value) handleChange();
    }

    function init() {
        var el = document.getElementById(METADATA_ID);
        var products = {};
        if (el && el.textContent) {
            try { products = JSON.parse(el.textContent); } catch (_) {}
        }
        if (Object.keys(products).length === 0) return;

        var selects = document.querySelectorAll('[name*="' + SELECT_NAME_MATCH + '"], [id*="' + SELECT_NAME_MATCH + '"]');
        selects.forEach(function (sel) { attachToSelect(sel, products); });
    }

    function run() {
        ensureStylesheet();
        init();
    }

    function ensureStylesheet() {
        var href = '/modules/servers/MetroVPSB2B/assets/css/metrovps-detail.css?v=1';
        var existing = document.querySelectorAll('link[rel="stylesheet"]');

        for (var i = 0; i < existing.length; i++) {
            if (existing[i].href && existing[i].href.indexOf('/assets/css/metrovps-detail.css') !== -1) {
                return;
            }
        }

        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        var head = document.head || document.getElementsByTagName('head')[0];
        if (head) head.appendChild(link);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }

    if (typeof MutationObserver !== 'undefined') {
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) run();
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
})();
