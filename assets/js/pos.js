/*
 * POS terminal: AJAX search/scan, live cart, split tender and checkout.
 *
 * Every mutation goes to sales.php?action=pos, which returns the recomputed
 * cart and the shift state, so the screen can never drift from the server.
 * Totals shown here are display copies of server figures - the invoice is
 * priced from the database at checkout, not from anything typed here.
 */
(function () {
    'use strict';

    var app = document.getElementById('posApp');
    if (!app) { return; }

    var endpoint = app.getAttribute('data-pos-endpoint');
    var csrf = app.getAttribute('data-csrf');
    var cashCodes = [];
    try { cashCodes = JSON.parse(app.getAttribute('data-cash-codes') || '[]'); } catch (e) { cashCodes = ['cash']; }

    var scan = document.getElementById('posScan');
    var search = document.getElementById('posSearch');
    var resultsBody = document.querySelector('#posResults tbody');
    var cartBody = document.querySelector('#posCartTable tbody');
    var alertBox = document.getElementById('posAlert');
    var tenderRows = document.getElementById('posTenderRows');
    var methodOptions = document.getElementById('posMethodOptions');
    var checkoutBtn = document.getElementById('posCheckout');
    var clearBtn = document.getElementById('posClear');
    var doneBox = document.getElementById('posDone');
    var doneDetail = document.getElementById('posDoneDetail');
    var receiptLink = document.getElementById('posReceipt');

    var els = {
        subtotal: document.getElementById('posSubtotal'),
        tax: document.getElementById('posTax'),
        taxPct: document.getElementById('posTaxPct'),
        total: document.getElementById('posTotal'),
        totalBadge: document.getElementById('posTotalBadge'),
        count: document.getElementById('posCount'),
        change: document.getElementById('posChange'),
        sales: document.getElementById('posShiftSales'),
        expected: document.getElementById('posShiftExpected')
    };

    var money = function (value) {
        var node = document.createElement('div');
        node.textContent = String(Math.round(Number(value || 0)));
        return node.textContent;
    };
    var state = {
        total: Number(app.getAttribute('data-total') || 0),
        count: Number(app.getAttribute('data-count') || 0),
        busy: false,
        allowed: app.getAttribute('data-shift-allowed') === '1',
        idempotency: app.getAttribute('data-idempotency') || null
    };

    function updateCheckoutState() {
        if (checkoutBtn) { checkoutBtn.disabled = state.busy || !state.allowed || state.count === 0; }
        if (clearBtn) { clearBtn.disabled = state.count === 0; }
    }

    function showAlert(message, kind) {
        if (!alertBox) { return; }
        alertBox.className = 'alert mt-3';
        alertBox.classList.add(kind === 'success' ? 'alert-success' : 'alert-danger');
        alertBox.textContent = message;
        if (kind === 'success') { window.setTimeout(hideAlert, 4000); }
    }
    function hideAlert() {
        if (alertBox) { alertBox.className = 'alert alert-danger mt-3 d-none'; }
    }

    function randomKey() {
        var bytes = new Uint8Array(16);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            for (var i = 0; i < 16; i++) { bytes[i] = Math.floor(Math.random() * 256); }
        }
        var hex = '';
        for (var j = 0; j < bytes.length; j++) { hex += ('0' + bytes[j].toString(16)).slice(-2); }
        return hex;
    }

    function post(op, fields) {
        var form = new FormData();
        form.append('ajax', '1');
        form.append('csrf_token', csrf);
        form.append('pos_action', op);
        for (var key in fields) {
            if (!Object.prototype.hasOwnProperty.call(fields, key)) { continue; }
            var value = fields[key];
            if (Array.isArray(value)) {
                for (var i = 0; i < value.length; i++) { form.append(key + '[]', value[i]); }
            } else {
                form.append(key, value);
            }
        }
        return fetch(endpoint, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok && response.status !== 200) {
                return response.text().then(function (text) {
                    throw new Error(text.slice(0, 160) || ('HTTP ' + response.status));
                });
            }
            return response.json();
        });
    }

    /* ------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------ */

    function renderCart(cart) {
        if (!cart) { return; }
        state.total = Number(cart.total || 0);
        state.count = Number(cart.count || 0);

        cartBody.textContent = '';
        if (!cart.lines || cart.lines.length === 0) {
            var empty = document.createElement('tr');
            empty.className = 'text-muted';
            var td = document.createElement('td');
            td.colSpan = 5;
            td.textContent = 'Cart is empty.';
            empty.appendChild(td);
            cartBody.appendChild(empty);
        } else {
            cart.lines.forEach(function (line) {
                cartBody.appendChild(cartRow(line));
            });
        }

        els.subtotal.textContent = money(cart.subtotal);
        els.tax.textContent = money(cart.tax);
        els.taxPct.textContent = (Number(cart.tax_rate || 0) * 100).toFixed(2);
        els.total.textContent = money(cart.total);
        els.totalBadge.textContent = money(cart.total);
        els.count.textContent = cart.count;

        syncTenderAmounts();
        updateChangePreview();
        updateCheckoutState();
        renderOnline();
    }

    function cartRow(line) {
        var tr = document.createElement('tr');

        var name = document.createElement('td');
        var strong = document.createElement('div');
        strong.textContent = line.name;
        var price = document.createElement('div');
        price.className = 'small text-muted';
        price.textContent = money(line.unit_price) + (line.sku ? ' · ' + line.sku : '');
        name.appendChild(strong);
        name.appendChild(price);
        tr.appendChild(name);

        var qtyCell = document.createElement('td');
        var qty = document.createElement('input');
        qty.type = 'number';
        qty.className = 'form-control form-control-sm pos-qty';
        qty.step = '0.01';
        qty.min = '0';
        qty.value = Number(line.quantity).toFixed(2);
        qty.setAttribute('data-index', line.index);
        qtyCell.appendChild(qty);
        tr.appendChild(qtyCell);

        var discCell = document.createElement('td');
        var disc = document.createElement('input');
        disc.type = 'number';
        disc.className = 'form-control form-control-sm pos-disc';
        disc.step = '0.01';
        disc.min = '0';
        disc.max = '100';
        disc.value = Number(line.discount_percent || 0).toFixed(2);
        disc.setAttribute('data-index', line.index);
        discCell.appendChild(disc);
        tr.appendChild(discCell);

        var totalCell = document.createElement('td');
        totalCell.className = 'text-end';
        totalCell.textContent = money(line.line_total);
        tr.appendChild(totalCell);

        var removeCell = document.createElement('td');
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm btn-outline-danger pos-remove';
        remove.setAttribute('data-index', line.index);
        remove.innerHTML = '<i class="fas fa-times"></i>';
        removeCell.appendChild(remove);
        tr.appendChild(removeCell);

        return tr;
    }

    function renderShift(shift) {
        if (!shift) { return; }
        if (els.sales && shift.open) {
            els.sales.textContent = shift.sales_count;
            els.expected.textContent = money(shift.expected_cash);
        }
        state.allowed = !!shift.allowed;
        updateCheckoutState();
    }

    function renderResults(items) {
        resultsBody.textContent = '';
        if (!items || items.length === 0) {
            var tr = document.createElement('tr');
            tr.className = 'text-muted';
            var td = document.createElement('td');
            td.colSpan = 6;
            td.textContent = 'No products matched.';
            tr.appendChild(td);
            resultsBody.appendChild(tr);
            return;
        }
        items.forEach(function (item) {
            var row = document.createElement('tr');
            row.innerHTML = ''
                + '<td></td>'
                + '<td class="small"></td>'
                + '<td class="text-end"></td>'
                + '<td class="text-end"></td>'
                + '<td class="text-end"><input type="number" class="form-control form-control-sm res-qty" value="1" min="0.01" step="0.01"></td>'
                + '<td><button type="button" class="btn btn-sm btn-primary res-add"><i class="fas fa-plus"></i></button></td>';
            row.children[0].textContent = item.name;
            row.children[1].textContent = item.sku || '';
            row.children[2].textContent = money(item.sales_price);
            var stock = Number(item.on_hand || 0);
            row.children[3].textContent = stock > 0 ? stock.toFixed(2) : '';
            if (stock <= 0) {
                var badge = document.createElement('span');
                badge.className = 'badge bg-danger';
                badge.textContent = 'Out';
                row.children[3].appendChild(badge);
            }
            var add = row.querySelector('.res-add');
            add.setAttribute('data-product', item.product_id);
            add.addEventListener('click', function () {
                var qty = Number(row.querySelector('.res-qty').value || 1);
                mutate('add_item', { product_id: item.product_id, quantity: qty });
            });
            resultsBody.appendChild(row);
        });
    }

    function applyPayload(data) {
        if (data.cart) { renderCart(data.cart); }
        if (data.shift) { renderShift(data.shift); }
    }

    /* ------------------------------------------------------------------
     * Cart mutations
     * ------------------------------------------------------------------ */

    function mutate(op, fields) {
        if (state.busy) { return Promise.resolve(null); }
        state.busy = true;
        hideAlert();
        return post(op, fields || {})
            .then(function (data) {
                applyPayload(data);
                if (!data.ok) { showAlert(data.message || 'That action did not work.'); }
                state.busy = false;
                return data;
            })
            .catch(function (error) {
                state.busy = false;
                showAlert('Request failed: ' + error.message);
                return null;
            });
    }

    cartBody.addEventListener('change', function (event) {
        var target = event.target;
        var index = target.getAttribute('data-index');
        if (index === null) { return; }
        if (target.classList.contains('pos-qty')) {
            mutate('set_quantity', { item_index: index, quantity: target.value });
        } else if (target.classList.contains('pos-disc')) {
            mutate('set_discount', { item_index: index, discount_percent: target.value });
        }
    });

    cartBody.addEventListener('click', function (event) {
        var button = event.target.closest('.pos-remove');
        if (!button) { return; }
        mutate('remove_item', { item_index: button.getAttribute('data-index') });
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            mutate('clear_cart', {});
        });
    }

    /* Scan: a USB scanner types the code and sends Enter. */
    if (scan) {
        scan.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') { return; }
            event.preventDefault();
            var code = scan.value.trim();
            if (code === '') { return; }
            scan.disabled = true;
            mutate('scan', { code: code }).then(function (data) {
                scan.disabled = false;
                scan.value = '';
                scan.focus();
                if (data && !data.ok) { scan.select(); }
            });
        });
    }

    var searchTimer = null;
    if (search) {
        search.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            var term = search.value.trim();
            if (term.length < 2) { return; }
            searchTimer = window.setTimeout(function () {
                post('search', { q: term }).then(function (data) {
                    renderResults(data.items);
                }).catch(function (error) {
                    showAlert('Search failed: ' + error.message);
                });
            }, 220);
        });
    }

    /* Browse-list fallback (no typing needed). */
    app.addEventListener('click', function (event) {
        var button = event.target.closest('.pos-browse-add');
        if (!button) { return; }
        var productId = button.getAttribute('data-product');
        var input = app.querySelector('.pos-browse-qty[data-product="' + productId + '"]');
        mutate('add_item', { product_id: productId, quantity: input ? input.value : 1 });
    });

    /* ------------------------------------------------------------------
     * Tender
     * ------------------------------------------------------------------ */

    function isCashCode(code) { return cashCodes.indexOf(code) !== -1; }

    function methodSelect(selected) {
        var select = document.createElement('select');
        select.className = 'form-select form-select-sm tender-method';
        select.name = 'payment_method[]';
        if (methodOptions) {
            Array.prototype.forEach.call(methodOptions.content.querySelectorAll('option'), function (option) {
                var clone = option.cloneNode(true);
                if (selected && clone.value === selected) { clone.selected = true; }
                select.appendChild(clone);
            });
        }
        if (select.options.length === 0) {
            var fallback = document.createElement('option');
            fallback.value = 'cash';
            fallback.textContent = 'Cash';
            select.appendChild(fallback);
        }
        return select;
    }

    function addTenderRow(amount, method) {
        var wrap = document.createElement('div');
        wrap.className = 'row g-1 align-items-center mb-2 tender-row';

        var c1 = document.createElement('div');
        c1.className = 'col-5';
        c1.appendChild(methodSelect(method));

        var c2 = document.createElement('div');
        c2.className = 'col-4';
        var amountInput = document.createElement('input');
        amountInput.type = 'number';
        amountInput.step = '1';
        amountInput.min = '0';
        amountInput.className = 'form-control form-control-sm tender-amount';
        amountInput.placeholder = 'Amount';
        amountInput.value = amount === null || amount === undefined ? '' : String(Math.round(Number(amount)));
        c2.appendChild(amountInput);

        var c3 = document.createElement('div');
        c3.className = 'col-2';
        var ref = document.createElement('input');
        ref.type = 'text';
        ref.className = 'form-control form-control-sm tender-ref';
        ref.placeholder = 'Ref';
        ref.maxLength = 50;
        c3.appendChild(ref);

        var c4 = document.createElement('div');
        c4.className = 'col-1 text-end';
        var drop = document.createElement('button');
        drop.type = 'button';
        drop.className = 'btn btn-sm btn-outline-danger tender-drop';
        drop.innerHTML = '<i class="fas fa-times"></i>';
        c4.appendChild(drop);

        wrap.appendChild(c1);
        wrap.appendChild(c2);
        wrap.appendChild(c3);
        wrap.appendChild(c4);
        tenderRows.appendChild(wrap);
        return wrap;
    }

    function tenders() {
        var rows = [];
        tenderRows.querySelectorAll('.tender-row').forEach(function (row) {
            var method = row.querySelector('.tender-method').value;
            var amount = Number(row.querySelector('.tender-amount').value || 0);
            var reference = row.querySelector('.tender-ref').value;
            if (method && amount > 0) { rows.push({ method: method, amount: amount, reference: reference }); }
        });
        return rows;
    }

    function updateChangePreview() {
        if (!els.change) { return; }
        var sum = tenders().reduce(function (total, t) { return total + t.amount; }, 0);
        var change = Math.max(0, sum - state.total);
        els.change.textContent = money(change);
    }

    /* Keep a single tender row filled with the exact total as the default. */
    function syncTenderAmounts() {
        var rows = tenderRows.querySelectorAll('.tender-row');
        if (rows.length !== 1) { return; }
        var input = rows[0].querySelector('.tender-amount');
        if (input && (input.value === '' || Number(input.value) === Number(state.total) || input.dataset.auto === '1')) {
            input.value = String(Math.round(Number(state.total)));
            input.dataset.auto = '1';
        }
    }

    tenderRows.addEventListener('input', function () {
        tenderRows.querySelectorAll('.tender-amount').forEach(function (input) { input.dataset.auto = '0'; });
        updateChangePreview();
    });
    tenderRows.addEventListener('change', function () { updateChangePreview(); renderOnline(); });
    tenderRows.addEventListener('click', function (event) {
        var drop = event.target.closest('.tender-drop');
        if (!drop) { return; }
        if (tenderRows.querySelectorAll('.tender-row').length === 1) {
            drop.closest('.tender-row').querySelector('.tender-amount').value = String(Math.round(Number(state.total)));
        } else {
            drop.closest('.tender-row').remove();
        }
        updateChangePreview();
        renderOnline();
    });

    var addTenderBtn = document.getElementById('posAddTender');
    if (addTenderBtn) {
        addTenderBtn.addEventListener('click', function () {
            var remaining = remainingDue();
            addTenderRow(remaining > 0 ? remaining : '', null).querySelector('.tender-amount').dataset.auto = '0';
            updateChangePreview();
            renderOnline();
        });
    }

    var exactBtn = document.getElementById('posExact');
    if (exactBtn) {
        exactBtn.addEventListener('click', function () {
            tenderRows.textContent = '';
            addTenderRow(state.total, cashCodes[0] || 'cash');
            updateChangePreview();
        });
    }

    function remainingDue() {
        var paid = tenders().reduce(function (total, t) { return total + t.amount; }, 0);
        return Math.max(0, state.total - paid);
    }

    /* ------------------------------------------------------------------
     * Card tenders: a session at the gateway, not a sale yet
     * ------------------------------------------------------------------
     * Nothing here can record a sale. Opening a session asks the server for
     * one (which quotes the cart total itself); the sale appears only when the
     * gateway confirms the payment - from the trip back, a webhook, or the
     * verify button on the payment screen.
     */

    var onlinePanel = document.getElementById('posOnline');
    var onlineText = document.getElementById('posOnlineText');
    var onlineStart = document.getElementById('posOnlineStart');
    var onlineLink = document.getElementById('posOnlineLink');
    var onlineCheck = document.getElementById('posOnlineCheck');
    var onlineStatus = document.getElementById('posOnlineStatus');
    var online = { tender: '', gateway: '', reference: '', url: '', timer: null, busy: false };

    function gatewayOf(row) {
        var select = row.querySelector('.tender-method');
        var option = select && select.selectedIndex > -1 ? select.options[select.selectedIndex] : null;
        return option ? (option.getAttribute('data-gateway') || '') : '';
    }

    /** The first tender row that is a card method, or null. */
    function onlineTender() {
        var picked = null;
        tenderRows.querySelectorAll('.tender-row').forEach(function (row) {
            var gateway = gatewayOf(row);
            if (picked === null && gateway !== '') {
                picked = { code: row.querySelector('.tender-method').value, gateway: gateway };
            }
        });
        return picked;
    }

    function stopWatching() {
        if (online.timer) { window.clearInterval(online.timer); online.timer = null; }
    }

    function renderOnline() {
        if (!onlinePanel) { return; }
        var picked = onlineTender();
        if (picked === null) {
            stopWatching();
            onlinePanel.classList.add('d-none');
            return;
        }
        if (picked.code !== online.tender) {
            // A different tender means a different session; this screen stops
            // following the old one, which the payment workspace still shows.
            stopWatching();
            online.tender = picked.code;
            online.gateway = picked.gateway;
            online.reference = '';
            online.url = '';
        }
        onlinePanel.classList.remove('d-none');
        if (onlineText) {
            onlineText.textContent = picked.code + ' is taken through ' + picked.gateway + '. The gateway will be asked for '
                + money(state.total) + '. Nothing is recorded as a sale until it confirms the payment.';
        }
        if (onlineStart) { onlineStart.classList.toggle('d-none', online.reference !== ''); }
        if (onlineLink) { onlineLink.classList.toggle('d-none', online.url === ''); }
        if (onlineCheck) { onlineCheck.classList.toggle('d-none', online.reference === ''); }
        if (online.reference !== '' && online.timer === null) { watchOnline(); }
    }

    function checkOnline() {
        if (online.reference === '' || online.busy) { return; }
        online.busy = true;
        post('gateway_status', { reference: online.reference })
            .then(function (data) {
                online.busy = false;
                var pay = (data && data.payment) || {};
                applyPayload(data);
                if (!pay.found) {
                    if (onlineStatus) { onlineStatus.textContent = 'This session is no longer visible here.'; }
                    return;
                }
                if (pay.posted) { finishOnline(pay); return; }
                if (onlineStatus) { onlineStatus.textContent = pay.message; }
            })
            .catch(function () { online.busy = false; });
    }

    function watchOnline() {
        stopWatching();
        online.timer = window.setInterval(checkOnline, 4000);
        checkOnline();
    }

    function finishOnline(pay) {
        stopWatching();
        online.tender = '';
        online.reference = '';
        online.url = '';
        post('clear_cart', {})
            .then(function (data) {
                applyPayload(data);
                if (onlineStatus) { onlineStatus.textContent = ''; }
                doneDetail.textContent = 'Card payment ' + pay.reference + ' · ' + pay.invoice_number + ' · ' + money(pay.amount);
                receiptLink.href = pay.receipt_url || '#';
                doneBox.classList.remove('d-none');
                document.getElementById('posTender').classList.add('d-none');
            })
            .catch(function (error) { showAlert('The payment landed but the screen could not be reset (' + error.message
                + '). The sale is recorded - check the sales list.'); });
    }

    function startOnline() {
        if (online.busy) { return; }
        var picked = onlineTender();
        if (picked === null) { return; }
        if (state.count === 0) { showAlert('Scan the items in before taking a card payment.'); return; }

        online.busy = true;
        onlineStart.disabled = true;
        var original = onlineStart.innerHTML;
        onlineStart.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Opening';

        post('gateway_init', { tender: picked.code })
            .then(function (data) {
                online.busy = false;
                onlineStart.disabled = false;
                onlineStart.innerHTML = original;
                if (!data.ok) { showAlert(data.message || 'The gateway could not start a payment.'); return; }

                var pay = data.payment || {};
                online.tender = pay.tender;
                online.gateway = pay.gateway;
                online.reference = pay.reference;
                online.url = pay.checkout_url;
                if (onlineStatus) {
                    onlineStatus.textContent = (pay.reused ? 'Reopened the session already waiting at this total: ' : 'Session ')
                        + pay.reference + ' · ' + money(pay.amount);
                }
                renderOnline();
                if (online.url !== '') {
                    var opened = window.open(online.url, 'gatewayCheckout');
                    if (opened === null && onlineLink) { onlineLink.href = online.url; onlineLink.classList.remove('d-none'); }
                    if (onlineLink) { onlineLink.href = online.url; }
                }
                watchOnline();
            })
            .catch(function (error) {
                online.busy = false;
                onlineStart.disabled = false;
                onlineStart.innerHTML = original;
                showAlert('Could not reach the server (' + error.message + '). No payment session was opened.');
            });
    }

    if (onlineStart) { onlineStart.addEventListener('click', startOnline); }
    if (onlineCheck) { onlineCheck.addEventListener('click', checkOnline); }

    /* ------------------------------------------------------------------
     * Checkout
     * ------------------------------------------------------------------ */

    var checkoutInFlight = false;

    function doCheckout() {
        if (checkoutInFlight) { return; }
        var rows = tenders();
        if (rows.length === 0) {
            showAlert('Enter the amount tendered before completing the sale.');
            return;
        }
        if (onlineTender() !== null) {
            showAlert('"' + onlineTender().code + '" is a card tender: use the online payment button, which asks the '
                + 'gateway before anything is recorded as a sale.');
            return;
        }
        if (remainingDue() > 0.005) {
            showAlert('The payments total less than the amount due (' + money(remainingDue()) + ' short).');
            return;
        }

        checkoutInFlight = true;
        state.busy = true;
        hideAlert();
        checkoutBtn.disabled = true;
        var original = checkoutBtn.innerHTML;
        checkoutBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing';

        post('checkout', {
            payment_method: rows.map(function (r) { return r.method; }),
            payment_amount: rows.map(function (r) { return String(Math.round(r.amount)); }),
            payment_reference: rows.map(function (r) { return r.reference; }),
            idempotency_key: state.idempotency || randomKey()
        })
            .then(function (data) {
                checkoutInFlight = false;
                state.busy = false;
                checkoutBtn.innerHTML = original;
                applyPayload(data);

                if (!data.ok) {
                    if (data.code === 'shift_required') { state.allowed = false; }
                    updateCheckoutState();
                    showAlert(data.message || 'Checkout failed.');
                    return;
                }

                var detail = (data.duplicate ? 'Already recorded: ' : '')
                    + (data.invoice_number || ('#' + data.invoice_id))
                    + ' · total ' + money(data.total)
                    + ' · tendered ' + money(data.tendered)
                    + ' · change ' + money(data.change);
                doneDetail.textContent = detail;
                els.change.textContent = money(data.change);
                receiptLink.href = data.receipt_url || ('#');
                doneBox.classList.remove('d-none');
                document.getElementById('posTender').classList.add('d-none');
                state.idempotency = randomKey();
                window.setTimeout(function () { scan.focus(); }, 50);
            })
            .catch(function (error) {
                checkoutInFlight = false;
                state.busy = false;
                updateCheckoutState();
                checkoutBtn.innerHTML = original;
                // Same idempotency key is kept: retrying this request cannot
                // create a second sale, it returns the first one.
                showAlert('Checkout request failed (' + error.message + '). The sale may still have been '
                    + 'recorded - check the sales list before trying again.');
            });
    }

    var nextBtn = document.getElementById('posNext');
    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            doneBox.classList.add('d-none');
            document.getElementById('posTender').classList.remove('d-none');
            tenderRows.textContent = '';
            online.tender = '';
            online.reference = '';
            online.url = '';
            stopWatching();
            addTenderRow(state.total, cashCodes[0] || 'cash');
            updateChangePreview();
            renderOnline();
            scan.focus();
        });
    }

    if (checkoutBtn) { checkoutBtn.addEventListener('click', doCheckout); }

    /* ------------------------------------------------------------------
     * Boot
     * ------------------------------------------------------------------ */

    addTenderRow(state.total, cashCodes[0] || 'cash');
    if (scan) { scan.focus(); }

    /* Coming back from the gateway without JavaScript, or with the pop-up
     * closed, the screen picks the session up from the address bar. */
    var resumed = /[?&]payment=([^&]+)/.exec(window.location.search);
    if (resumed) {
        online.reference = decodeURIComponent(resumed[1].replace(/\+/g, ' '));
        var resumedRow = onlineTender();
        online.tender = resumedRow === null ? '' : resumedRow.code;
        renderOnline();
        if (online.reference !== '' && online.tender !== '') { watchOnline(); }
    } else {
        renderOnline();
    }

    /* Shift state can change from the Shifts screen while the POS is open. */
    window.setInterval(function () {
        if (state.busy || checkoutInFlight) { return; }
        fetch(endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'format=json', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (data) {
            if (!data) { return; }
            renderCart(data.cart);
            renderShift(data.shift);
        }).catch(function () { /* ignore polling failures */ });
    }, 30000);
})();
