/* Shared notification helper. NO database: everything is saved in the browser.
   "adminNotifications" = notifications shown on the admin Notifications page
   "notifKnownItems"    = snapshot of Inventory Management items (to detect new / removed items) */
(function (w) {
    var KEY   = 'adminNotifications';
    var KNOWN = 'notifKnownItems';
    var MAX   = 200;   // keep only the latest 200 notifications
    var LOW   = 10;    // low stock when the balance is this number or less

    var TYPE_LABEL = { Ingredients: 'ingredient', Products: 'product', Supplies: 'supply' };

    function read(k) {
        try {
            var s = JSON.parse(localStorage.getItem(k));
            return Array.isArray(s) ? s : [];
        } catch (e) { return []; }
    }

    function write(k, v) {
        try { localStorage.setItem(k, JSON.stringify(v)); return true; }
        catch (e) { return false; }
    }

    function p(n) { return String(n).padStart(2, '0'); }

    function stamp() {
        var d = new Date();
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' +
               p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }

    var Notify = {
        LOW: LOW,
        KEY: KEY,

        list: function () { return read(KEY); },

        unreadCount: function () {
            return read(KEY).filter(function (n) { return !n.is_read; }).length;
        },

        push: function (type, title, description) {
            var list = read(KEY);
            list.unshift({
                id: Date.now() + '-' + Math.random().toString(36).slice(2, 6),
                type: type,
                title: title,
                description: description,
                date: stamp(),
                is_read: false
            });
            write(KEY, list.slice(0, MAX));
        },

        markRead: function (id) {
            var list = read(KEY);
            list.forEach(function (n) { if (n.id === id) { n.is_read = true; } });
            write(KEY, list);
        },

        markAllRead: function () {
            var list = read(KEY);
            list.forEach(function (n) { n.is_read = true; });
            write(KEY, list);
        },

        clearAll: function () { write(KEY, []); },

        /* Inventory Management: call with the items array. Detects added / removed items. */
        syncItems: function (items) {
            var raw = localStorage.getItem(KNOWN);
            var now = {};

            items.forEach(function (it) {
                now[String(it.id)] = { name: it.name, type: it.type, unit: it.unit || '' };
            });

            /* first run: only remember the current items, no notifications */
            if (raw === null) {
                write(KNOWN, now);
                return;
            }

            var old;
            try { old = JSON.parse(raw) || {}; } catch (e) { old = {}; }
            if (Array.isArray(old)) { old = {}; }

            Object.keys(now).forEach(function (id) {
                if (!old[id]) {
                    var what = TYPE_LABEL[now[id].type] || 'item';
                    Notify.push(
                        'new_item',
                        'New ' + what + ' added',
                        '"' + now[id].name + '"' + (now[id].unit ? ' (' + now[id].unit + ')' : '') +
                        ' was added to Inventory Management.'
                    );
                }
            });

            Object.keys(old).forEach(function (id) {
                if (!now[id]) {
                    Notify.push('system', 'Item removed',
                        '"' + old[id].name + '" was removed from Inventory Management.');
                }
            });

            write(KNOWN, now);
        },

        /* Stocking Record: call after a stock in / stock out is saved */
        stockMoved: function (r, balance, invLabel) {
            var isIn = r.movement_type === 'IN';
            var by   = r.received_by || 'An employee';
            var qty  = Number(r.quantity).toLocaleString('en-US');

            Notify.push(
                isIn ? 'stock_in' : 'stock_out',
                isIn ? 'Stock in recorded' : 'Stock out recorded',
                by + (isIn ? ' added ' : ' removed ') + qty + ' ' + r.unit + ' of "' + r.item_name +
                '" (' + invLabel + ')' + (r.supplier ? ' - ' + r.supplier : '') +
                '. Balance: ' + Number(balance).toLocaleString('en-US') + ' ' + r.unit + '.'
            );

            if (!isIn) {
                if (balance <= 0) {
                    Notify.push('low_stock', 'Out of stock',
                        '"' + r.item_name + '" (' + invLabel + ') is now out of stock.');
                } else if (balance <= LOW) {
                    Notify.push('low_stock', 'Low stock alert',
                        '"' + r.item_name + '" (' + invLabel + ') is down to ' + balance + ' ' + r.unit + '.');
                }
            }
        }
    };

    w.Notify = Notify;
})(window);