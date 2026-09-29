/*
 * xrowextract: the CSV view's spreadsheet preview.
 * "Preview" loads the panel in place (the same export code as the download,
 * read back as a spreadsheet would); without JavaScript the button posts the
 * form and the page shows the same panel. Filter, sort, wrap, copy and cell
 * expansion work on the rows shown and never change the download.
 */
(function () {
    'use strict';

    var form = document.forms.eZExtract;
    var slot = document.getElementById('xe-preview-slot');
    if (!form || !slot || !window.fetch || !window.FormData) {
        return;
    }
    var lastSubmitter = null;

    form.addEventListener('click', function (event) {
        var button = event.target.closest('input[type=submit], button[type=submit]');
        if (button) {
            lastSubmitter = button;
        }
    });

    form.addEventListener('submit', function (event) {
        var submitter = event.submitter || lastSubmitter;
        lastSubmitter = null;
        if (submitter && submitter.name === 'Preview') {
            event.preventDefault();
            loadPreview();
        }
    });

    slot.addEventListener('change', function (event) {
        if (event.target.name === 'PreviewRows') {
            loadPreview();
        } else if (event.target.classList.contains('xe-wrap-toggle')) {
            setWrap(event.target.checked);
        }
    });

    slot.addEventListener('click', function (event) {
        var target = event.target;
        if (target.closest('.xe-close')) {
            slot.innerHTML = '';
            return;
        }
        if (target.closest('.xe-copy')) {
            copyRows(target.closest('.xe-copy'));
            return;
        }
        var sort = target.closest('.xe-sort');
        if (sort) {
            sortBy(sort);
            return;
        }
        var cell = target.closest('.xe-table td');
        if (cell && !window.getSelection().toString()) {
            cell.classList.toggle('xe-open');
        }
    });

    var filterTimer = null;
    slot.addEventListener('input', function (event) {
        if (event.target.closest('.xe-filter')) {
            window.clearTimeout(filterTimer);
            filterTimer = window.setTimeout(function () { filterRows(event.target.value); }, 120);
        }
    });
    slot.addEventListener('keydown', function (event) {
        // Enter in the filter must not submit the form
        if (event.key === 'Enter' && event.target.closest('.xe-filter')) {
            event.preventDefault();
        }
    });

    function loadPreview() {
        var data = new FormData(form);
        data.append('Preview', '1');
        data.append('PreviewOnly', '1');
        var rows = slot.querySelector('select[name=PreviewRows]');
        if (rows) {
            data.set('PreviewRows', rows.value);
        }
        var filter = slot.querySelector('.xe-filter input');
        var keepFilter = filter ? filter.value : '';
        form.classList.add('xe-busy');
        fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin',
                             headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                return response.text().then(function (text) { return { ok: response.ok, text: text }; });
            })
            .then(function (result) {
                form.classList.remove('xe-busy');
                if (!result.ok || result.text.indexOf('id="xe-preview"') === -1) {
                    // Signed out, a changed form token or an error page: let the page handle it
                    submitNormally();
                    return;
                }
                slot.innerHTML = result.text;
                if (keepFilter) {
                    var input = slot.querySelector('.xe-filter input');
                    if (input) {
                        input.value = keepFilter;
                        filterRows(keepFilter);
                    }
                }
                var panel = slot.querySelector('.xe-preview');
                var top = panel.getBoundingClientRect().top;
                if (top < 0 || top > window.innerHeight - 80) {
                    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            })
            .catch(function () {
                form.classList.remove('xe-busy');
                submitNormally();
            });
    }

    function submitNormally() {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'Preview';
        hidden.value = '1';
        form.appendChild(hidden);
        form.submit();
    }

    function bodyRows() {
        var body = slot.querySelector('.xe-table tbody');
        return body ? Array.prototype.slice.call(body.rows) : [];
    }

    function filterRows(text) {
        var needle = text.trim().toLowerCase();
        var rows = bodyRows();
        var shown = 0;
        rows.forEach(function (row) {
            var match = !needle || row.textContent.toLowerCase().indexOf(needle) !== -1;
            row.classList.toggle('xe-hidden', !match);
            shown += match ? 1 : 0;
        });
        var status = slot.querySelector('.xe-match');
        var panel = slot.querySelector('.xe-preview');
        if (status && panel) {
            status.textContent = needle ? panel.getAttribute('data-matches').replace('%shown', shown).replace('%all', rows.length) : '';
        }
    }

    function sortBy(button) {
        var column = parseInt(button.parentNode.getAttribute('data-col'), 10) + 1; // after the row number
        var direction = button.getAttribute('data-dir') === 'asc' ? 'desc' : (button.getAttribute('data-dir') === 'desc' ? '' : 'asc');
        slot.querySelectorAll('.xe-sort[data-dir]').forEach(function (other) { other.removeAttribute('data-dir'); });
        if (direction) {
            button.setAttribute('data-dir', direction);
        }
        var rows = bodyRows();
        var value = function (row, index) {
            return index < row.cells.length ? row.cells[index].textContent : '';
        };
        rows.sort(function (a, b) {
            if (!direction) {
                return parseInt(a.cells[0].textContent, 10) - parseInt(b.cells[0].textContent, 10);
            }
            var x = value(a, column), y = value(b, column);
            var nx = parseFloat(x), ny = parseFloat(y);
            var order = (!isNaN(nx) && !isNaN(ny) && String(nx) === x.trim() && String(ny) === y.trim())
                ? nx - ny
                : x.localeCompare(y, undefined, { numeric: true, sensitivity: 'base' });
            if (x === '' && y !== '') { return 1; }   // empty cells last in both directions
            if (y === '' && x !== '') { return -1; }
            return direction === 'asc' ? order : -order;
        });
        var body = slot.querySelector('.xe-table tbody');
        rows.forEach(function (row) { body.appendChild(row); });
    }

    function setWrap(on) {
        var table = slot.querySelector('.xe-table');
        if (table) {
            table.classList.toggle('xe-wrap', on);
        }
        var panel = slot.querySelector('.xe-preview');
        var url = panel && panel.getAttribute('data-preference-url');
        if (url) {
            fetch(url + '/' + (on ? '1' : '0'), { credentials: 'same-origin', redirect: 'manual' }).catch(function () {});
        }
    }

    function copyRows(button) {
        var clean = function (text) { return text.replace(/[\t\r\n]+/g, ' '); };
        var lines = [];
        var names = slot.querySelectorAll('.xe-table thead tr:nth-child(2) .xe-colname');
        lines.push(Array.prototype.map.call(names, function (n) { return clean(n.textContent); }).join('\t'));
        bodyRows().forEach(function (row) {
            if (row.classList.contains('xe-hidden')) {
                return;
            }
            var cells = Array.prototype.slice.call(row.cells, 1);
            lines.push(cells.map(function (c) { return clean(c.textContent); }).join('\t'));
        });
        var text = lines.join('\n') + '\n';
        var done = function () {
            var label = button.textContent;
            button.textContent = slot.querySelector('.xe-preview').getAttribute('data-copied');
            window.setTimeout(function () { button.textContent = label; }, 1500);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
        } else {
            fallbackCopy(text);
            done();
        }
    }

    function fallbackCopy(text) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(area);
    }
}());
