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
        // Sent urlencoded, as the form itself posts: the form has no file field, and not every
        // server reads nested names (Attributes[0][id]) from a multipart body
        fetch(form.action, { method: 'POST', body: new URLSearchParams(data), credentials: 'same-origin',
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

/*
 * The settings cards: separator presets, a live sample of a row in the
 * chosen format, and a class change that loads that class's columns.
 */
(function () {
    'use strict';

    var form = document.forms.eZExtract;
    if (!form) {
        return;
    }
    var input = form.elements.Separator;
    var presets = form.querySelector('.xe-presets');
    var sample = form.querySelector('.xe-sample-line');
    var tabNotation = presets ? presets.getAttribute('data-tab') : '\\t';

    // The separator the server will use: one character, \t is a tab, never a quote or line break
    function separator() {
        var value = input ? input.value : ',';
        if (value === tabNotation) {
            return '\t';
        }
        return (value.length === 1 && '"\r\n'.indexOf(value) === -1) ? value : ',';
    }

    function checkedValue(name, fallback) {
        var field = form.querySelector('input[name="' + name + '"]:checked');
        return field ? field.value : fallback;
    }

    function markPreset() {
        if (!presets) {
            return;
        }
        var current = separator();
        presets.querySelectorAll('.xe-preset').forEach(function (button) {
            var sep = button.getAttribute('data-sep') === 'tab' ? '\t' : button.getAttribute('data-sep');
            button.classList.toggle('xe-active', sep === current);
            button.setAttribute('aria-pressed', sep === current ? 'true' : 'false');
        });
    }

    function renderSample() {
        if (!sample) {
            return;
        }
        var sep = separator();
        var quoted = checkedValue('Escape', '1') === '1';
        var eol = { win32: '␍␊', unix: '␊', mac: '␍' }[checkedValue('LineSeparator', 'unix')] || '␊';
        var columns = (sample.getAttribute('data-columns') || '').split('\n').filter(Boolean);
        if (!columns.length) {
            columns = ['title', 'name'];
        }
        var header = columns.map(function (c) { return c.replace(/_/g, '-'); });
        var values = [sample.getAttribute('data-value'), '42', ''].slice(0, columns.length);
        while (values.length < columns.length) {
            values.push('');
        }
        var cell = function (value) {
            return quoted ? '"' + value.replace(/"/g, '""') + '"' : value.replace(/[\r\n]+/g, '');
        };
        sample.textContent = '';
        [header, values].forEach(function (row) {
            row.forEach(function (value, index) {
                if (index) {
                    var mark = document.createElement('span');
                    mark.className = 'xe-sep';
                    mark.textContent = sep === '\t' ? '⇥' : sep;
                    sample.appendChild(mark);
                }
                sample.appendChild(document.createTextNode(cell(value)));
            });
            var end = document.createElement('span');
            end.className = 'xe-eol';
            end.textContent = eol + '\n';
            sample.appendChild(end);
        });
    }

    if (presets) {
        presets.addEventListener('click', function (event) {
            var button = event.target.closest('.xe-preset');
            if (!button) {
                return;
            }
            input.value = button.getAttribute('data-sep') === 'tab' ? tabNotation : button.getAttribute('data-sep');
            markPreset();
            renderSample();
        });
    }
    form.addEventListener('input', function (event) {
        if (event.target === input) {
            markPreset();
            renderSample();
        }
    });
    form.addEventListener('change', function (event) {
        var target = event.target;
        if (target.name === 'Escape' || target.name === 'LineSeparator') {
            renderSample();
        }
        if (target.classList.contains('xe-autosubmit')) {
            // A new class has other columns: load them right away (the button stays for use without JavaScript)
            var update = form.querySelector('input[name=Update]');
            if (update) {
                update.classList.add('xe-pending');
                if (form.requestSubmit) {
                    form.requestSubmit(update);
                } else {
                    update.click();
                }
            }
        }
    });
    markPreset();
    renderSample();
}());

/*
 * The column list: remove one or all, move with the arrows (or Alt+Up/Down), drag to reorder.
 * Done in place; the buttons also work without JavaScript. The form posts the list in its
 * order with every action, so nothing is lost.
 */
(function () {
    'use strict';

    var form = document.forms.eZExtract;
    var list = form && form.querySelector('ol.xe-columns'); // the column list, not the archive's node list
    if (!list) {
        return;
    }
    var empty = form.querySelector('.xe-columns-empty');
    var removeAll = form.querySelector('.xe-remove-all');

    function items() {
        return Array.prototype.slice.call(list.querySelectorAll('.xe-column'));
    }

    // Names and positions follow the order on screen: Attributes[<position>][...], Action[<position>]
    function renumber() {
        var rows = items();
        rows.forEach(function (row, index) {
            row.querySelectorAll('input[name^="Attributes["]').forEach(function (input) {
                input.name = input.name.replace(/^Attributes\[\d+\]/, 'Attributes[' + index + ']');
            });
            row.querySelectorAll('button[name]').forEach(function (button) {
                button.name = button.name.replace(/\[\d+\]$/, '[' + index + ']');
            });
            row.querySelector('.xe-colpos').textContent = index + 1;
            row.querySelector('.xe-up').disabled = index === 0;
            row.querySelector('.xe-down').disabled = index === rows.length - 1;
        });
        form.querySelectorAll('.xe-column-total').forEach(function (n) { n.textContent = rows.length; });
        var badge = form.querySelector('[aria-labelledby="xe-card-columns"] .xe-count strong');
        if (badge) {
            badge.textContent = rows.length;
        }
        if (empty) {
            empty.hidden = rows.length > 0;
        }
        if (removeAll) {
            removeAll.disabled = rows.length === 0;
        }
    }

    function flash(row) {
        row.classList.remove('xe-flash');
        void row.offsetWidth;
        row.classList.add('xe-flash');
    }

    function move(row, step) {
        var rows = items();
        var index = rows.indexOf(row);
        var target = rows[index + step];
        if (!target) {
            return;
        }
        list.insertBefore(row, step < 0 ? target : target.nextSibling);
        renumber();
        flash(row);
    }

    list.addEventListener('click', function (event) {
        var button = event.target.closest('button');
        if (!button) {
            return;
        }
        event.preventDefault();
        var row = button.closest('.xe-column');
        if (button.classList.contains('xe-remove')) {
            var next = row.nextElementSibling || row.previousElementSibling;
            row.parentNode.removeChild(row);
            renumber();
            var focus = next && next.querySelector('.xe-remove');
            if (focus) {
                focus.focus();
            }
        } else if (button.classList.contains('xe-up')) {
            move(row, -1);
            button.disabled || button.focus();
        } else if (button.classList.contains('xe-down')) {
            move(row, 1);
            button.disabled || button.focus();
        }
    });

    list.addEventListener('keydown', function (event) {
        if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
            var row = event.target.closest('.xe-column');
            if (row) {
                event.preventDefault();
                move(row, event.key === 'ArrowUp' ? -1 : 1);
                event.target.focus();
            }
        }
    });

    if (removeAll) {
        removeAll.addEventListener('click', function (event) {
            event.preventDefault();
            items().forEach(function (row) { row.parentNode.removeChild(row); });
            renumber();
        });
    }

    // Drag and drop: the row follows the pointer; a line shows where it will land
    var dragged = null;
    // Only the handle starts a drag, so text in the name field can be selected as usual
    list.addEventListener('pointerdown', function (event) {
        var handle = event.target.closest('.xe-handle');
        items().forEach(function (row) { row.draggable = false; });
        if (handle) {
            handle.closest('.xe-column').draggable = true;
        }
    });
    list.addEventListener('dragstart', function (event) {
        var row = event.target.closest && event.target.closest('.xe-column');
        if (!row || event.target.closest('input')) {
            event.preventDefault();
            return;
        }
        dragged = row;
        row.classList.add('xe-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', row.querySelector('.xe-colinfo strong').textContent);
    });
    function clearMarks() {
        items().forEach(function (row) { row.classList.remove('xe-drop-before', 'xe-drop-after'); });
    }
    list.addEventListener('dragover', function (event) {
        if (!dragged) {
            return;
        }
        event.preventDefault();
        var row = event.target.closest('.xe-column');
        clearMarks();
        if (row && row !== dragged) {
            var box = row.getBoundingClientRect();
            row.classList.add(event.clientY < box.top + box.height / 2 ? 'xe-drop-before' : 'xe-drop-after');
        }
    });
    list.addEventListener('drop', function (event) {
        if (!dragged) {
            return;
        }
        event.preventDefault();
        var row = event.target.closest('.xe-column');
        if (row && row !== dragged) {
            var box = row.getBoundingClientRect();
            list.insertBefore(dragged, event.clientY < box.top + box.height / 2 ? row : row.nextSibling);
        }
        clearMarks();
        renumber();
        flash(dragged);
    });
    list.addEventListener('dragend', function () {
        if (dragged) {
            dragged.classList.remove('xe-dragging');
            dragged.draggable = false;
        }
        dragged = null;
        clearMarks();
    });
}());

/*
 * The site archive page: filter the node picker and the classes, tick all or
 * none in place with live totals, and download with a progress note.
 */
(function () {
    'use strict';

    var form = document.querySelector('form.xe-archive-form');
    if (!form) {
        return;
    }
    var picker = form.querySelector('.xe-picker');
    var download = form.querySelector('.xe-download-archive');

    function applyPickerFilter() {
        if (!picker) {
            return;
        }
        var needle = (picker.querySelector('.xe-picker-filter').value || '').trim().toLowerCase();
        var nonEmpty = picker.querySelector('.xe-picker-nonempty').checked;
        picker.querySelectorAll('.xe-picker-item').forEach(function (item) {
            var match = (!needle || item.getAttribute('data-search').indexOf(needle) !== -1)
                && (!nonEmpty || parseInt(item.getAttribute('data-count'), 10) > 1);
            item.hidden = !match;
        });
    }
    if (picker) {
        picker.addEventListener('input', applyPickerFilter);
        picker.addEventListener('change', applyPickerFilter);
        applyPickerFilter();
    }

    var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="ClassIDs[]"]'));
    function totals() {
        var on = 0, rows = 0;
        boxes.forEach(function (box) {
            if (box.checked) {
                on++;
                rows += parseInt(box.getAttribute('data-rows'), 10) || 0;
            }
        });
        form.querySelectorAll('.xe-classes-on').forEach(function (n) { n.textContent = on; });
        form.querySelectorAll('.xe-rows-on').forEach(function (n) { n.textContent = rows; });
        if (download) {
            download.disabled = on === 0 || !form.querySelector('.xe-nodes');
        }
    }
    var classFilter = form.querySelector('.xe-class-filter');
    if (classFilter) {
        classFilter.addEventListener('input', function () {
            var needle = classFilter.value.trim().toLowerCase();
            form.querySelectorAll('.xe-class-grid li').forEach(function (li) {
                li.hidden = needle !== '' && li.getAttribute('data-search').indexOf(needle) === -1;
            });
        });
        classFilter.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });
    }
    // All / none tick the classes shown (the filter narrows them)
    [['.xe-classes-all', true], ['.xe-classes-none', false]].forEach(function (pair) {
        var button = form.querySelector(pair[0]);
        if (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                boxes.forEach(function (box) {
                    if (!box.closest('li').hidden) {
                        box.checked = pair[1];
                    }
                });
                totals();
            });
        }
    });
    form.addEventListener('change', function (event) {
        if (event.target.name === 'ClassIDs[]') {
            totals();
        }
    });

    // Download: fetch the archive so the page can say what it is doing; errors come back as the page
    if (download && window.fetch && window.URLSearchParams && window.Blob) {
        var bar = form.querySelector('.xe-actionbar');
        var status = document.createElement('div');
        status.className = 'xe-actionbar-status';
        status.setAttribute('aria-live', 'polite');
        bar.appendChild(status);
        download.addEventListener('click', function (event) {
            event.preventDefault();
            var data = new FormData(form);
            data.append('DownloadArchive', '1');
            var label = download.value;
            var started = Date.now();
            download.classList.add('xe-working');
            download.disabled = true;
            download.value = download.getAttribute('data-working') || '…';
            status.textContent = form.getAttribute('data-writing') || '';
            fetch(form.action, { method: 'POST', body: new URLSearchParams(data), credentials: 'same-origin' })
                .then(function (response) {
                    var disposition = response.headers.get('Content-Disposition') || '';
                    var match = /filename="([^"]+)"/.exec(disposition);
                    if (!response.ok || !match) {
                        throw new Error('page');
                    }
                    return response.blob().then(function (blob) { return { blob: blob, name: match[1] }; });
                })
                .then(function (file) {
                    var url = URL.createObjectURL(file.blob);
                    var link = document.createElement('a');
                    link.href = url;
                    link.download = file.name;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    window.setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
                    var kb = Math.max(1, Math.round(file.blob.size / 1024));
                    status.textContent = (form.getAttribute('data-done') || '%name, %size KB, %seconds s')
                        .replace('%name', file.name).replace('%size', kb).replace('%seconds', ((Date.now() - started) / 1000).toFixed(1));
                })
                .catch(function () {
                    // Show the page with its message (for example a format that failed)
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'DownloadArchive';
                    hidden.value = '1';
                    form.appendChild(hidden);
                    form.submit();
                })
                .then(function () {
                    download.classList.remove('xe-working');
                    download.value = label;
                    totals();
                });
        });
    }
}());
