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

/*
 * The column picker's filter: narrows the options (name, identifier, datatype, format) and selects the
 * first match, so typing and pressing "Add attribute" is enough.
 */
(function () {
    'use strict';

    var filter = document.querySelector('.xe-add-filter');
    var select = document.getElementById('xe-add');
    if (!filter || !select) {
        return;
    }
    filter.addEventListener('input', function () {
        var needle = filter.value.trim().toLowerCase();
        var first = null;
        Array.prototype.forEach.call(select.options, function (option) {
            var match = !needle || option.textContent.toLowerCase().indexOf(needle) !== -1 || option.value.toLowerCase().indexOf(needle) !== -1;
            option.hidden = !match;
            option.disabled = !match;
            if (match && !first) {
                first = option;
            }
        });
        Array.prototype.forEach.call(select.querySelectorAll('optgroup'), function (group) {
            group.hidden = !group.querySelector('option:not([hidden])');
        });
        if (first) {
            select.value = first.value;
        }
    });
    filter.addEventListener('keydown', function (event) {
        // Enter adds the selected column
        if (event.key === 'Enter') {
            event.preventDefault();
            var add = document.querySelector('input[name="AddAttribute"]');
            if (add && select.value) {
                add.click();
            }
        }
    });
}());

/*
 * After an add (a set, a column, all attributes) the page comes back at the top; bring the notice and the
 * new rows into view instead, so the result is seen at once.
 */
(function () {
    'use strict';
    var notice = document.querySelector('.xe-column-notice');
    if (!notice) {
        return;
    }
    var added = document.querySelectorAll('.xe-columns .xe-added');
    var target = added.length ? added[0] : notice;
    window.requestAnimationFrame(function () {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
}());

/*
 * The Jobs page: every queued or running job is polled every 2 seconds (xrowextract/job_status/<id>,
 * a small JSON view) and its row updated in place -- state, progress bar, rows, size, a download link
 * once it is done, or the error once it failed. Without JavaScript the page still shows the state as
 * of the last full load; reloading it works the same way the polling does.
 */
(function () {
    'use strict';

    var list = document.querySelector('.xe-jobs');
    if (!list || !window.fetch) {
        return;
    }
    var pollBase = list.getAttribute('data-poll-base');
    var downloadBase = list.getAttribute('data-download-base');
    var downloadLabel = list.getAttribute('data-download-label') || 'Download';

    function activeRows() {
        return Array.prototype.slice.call(list.querySelectorAll('.xe-job[data-poll="1"]'));
    }

    function setText(row, role, text) {
        var el = row.querySelector('[data-role="' + role + '"]');
        if (el) {
            el.textContent = text;
        }
    }

    // Same wording as the page's PHP: "8 s", "3 min 12 s", "2 h 5 min"
    function duration(seconds) {
        seconds = Math.max(0, Math.round(seconds));
        if (seconds < 60) {
            return seconds + ' s';
        }
        if (seconds < 3600) {
            return Math.floor(seconds / 60) + ' min' + (seconds % 60 ? ' ' + (seconds % 60) + ' s' : '');
        }
        var minutes = Math.floor(seconds % 3600 / 60);
        return Math.floor(seconds / 3600) + ' h' + (minutes ? ' ' + minutes + ' min' : '');
    }

    // The Started / Ended steps of the job's timeline, once the poll reports them
    function applyTimes(row, data) {
        var times = row.querySelector('.xe-job-times');
        var meta = row.querySelector('.xe-job-meta');
        if (!times || !meta) {
            return;
        }
        var created = parseInt(times.getAttribute('data-created'), 10);
        var stamp = function (el, value) {
            if (el && !el.hasAttribute('datetime')) {
                var date = new Date(value * 1000);
                el.setAttribute('datetime', date.toISOString());
                el.textContent = date.toLocaleString([], { dateStyle: 'short', timeStyle: 'short' });
            }
        };
        if (data.started) {
            var startedStep = row.querySelector('[data-role="started-step"]');
            if (startedStep) {
                startedStep.className = 'xe-time-done';
            }
            stamp(row.querySelector('[data-role="started"]'), data.started);
            if (created) {
                setText(row, 'wait', meta.getAttribute('data-label-wait').replace('%time', duration(data.started - created)));
            }
        }
        if (data.ended) {
            var endedStep = row.querySelector('[data-role="ended-step"]');
            if (endedStep) {
                endedStep.className = 'xe-time-done' + (data.state === 'failed' ? ' xe-time-bad' : '');
            }
            if (data.state === 'failed') {
                setText(row, 'ended-label', meta.getAttribute('data-label-failed'));
            }
            stamp(row.querySelector('[data-role="ended"]'), data.ended);
            if (data.started) {
                setText(row, 'took', meta.getAttribute('data-label-took').replace('%time', duration(data.ended - data.started)));
            }
        }
    }

    // The Total / Completed / Running / Queued / Failed tiles, recounted from the rows' states
    function applyCounts() {
        var stats = document.querySelector('[data-role="job-stats"]');
        if (!stats) {
            return;
        }
        var counts = { total: 0, done: 0, running: 0, queued: 0, failed: 0 };
        Array.prototype.forEach.call(list.querySelectorAll('.xe-job'), function (row) {
            counts.total++;
            var state = row.getAttribute('data-state');
            if (counts.hasOwnProperty(state)) {
                counts[state]++;
            }
        });
        Object.keys(counts).forEach(function (key) {
            var el = stats.querySelector('[data-count="' + key + '"]');
            if (el) {
                el.textContent = counts[key];
                if (key !== 'total' && key !== 'done') {
                    el.parentNode.classList.toggle('xe-stat-zero', counts[key] === 0);
                }
            }
        });
    }

    // The job's log: append what the poll brought since the last offset; follow the end while the reader
    // is at the bottom, leave the scroll alone when they scrolled up to read something
    function applyLog(row, data) {
        var log = data.log;
        if (!log) {
            return;
        }
        var el = row.querySelector('[data-role="log"]');
        if (!el && log.text) {
            var details = document.createElement('details');
            details.className = 'xe-job-log';
            details.open = true;
            details.innerHTML = '<summary></summary><pre class="xe-job-log-text" data-role="log" data-offset="0" tabindex="0"></pre>';
            details.querySelector('summary').textContent = list.getAttribute('data-log-label') || 'Log';
            var anchor = row.querySelector('.xe-job-buttons');
            row.insertBefore(details, anchor);
            el = details.querySelector('[data-role="log"]');
        }
        if (!el || !log.text) {
            return;
        }
        var atEnd = el.scrollHeight - el.scrollTop - el.clientHeight < 24;
        el.appendChild(document.createTextNode(log.text));
        el.setAttribute('data-offset', String(log.offset));
        if (atEnd) {
            el.scrollTop = el.scrollHeight;
        }
    }

    // A package install: classes/objects written so far (counted in the database) and the latest objects
    function applyInstall(row, data) {
        var install = data.install;
        var box = row.querySelector('[data-role="install"]');
        if (!install || !box) {
            return;
        }
        setText(box, 'install-classes', String(install.classes_done));
        setText(box, 'install-objects', String(install.objects_done));
        var recent = box.querySelector('[data-role="install-recent"]');
        if (recent && install.recent) {
            recent.textContent = '';
            install.recent.forEach(function (item) {
                var li = document.createElement('li');
                li.textContent = item.name + ' ';
                var small = document.createElement('small');
                small.textContent = new Date(item.at * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                li.appendChild(small);
                recent.appendChild(li);
            });
        }
    }

    function applyState(row, data) {
        applyLog(row, data);
        applyInstall(row, data);
        row.className = row.className.replace(/\bxe-job-\S+/, 'xe-job-' + data.state);
        row.setAttribute('data-state', data.state);
        var badge = row.querySelector('[data-role="state"]');
        if (badge) {
            badge.className = 'xe-job-state xe-badge xe-state-' + data.state;
            badge.textContent = data.state;
        }
        if (data.progress) {
            var percent = data.progress.total > 0 ? Math.max(0, Math.min(100, Math.round(100 * data.progress.done / data.progress.total))) : 0;
            var bar = row.querySelector('[data-role="progress-bar"]');
            if (bar) {
                bar.style.width = percent + '%';
            }
            setText(row, 'progress-text', data.progress.done + ' / ' + data.progress.total + (data.progress.phase ? ' · ' + data.progress.phase : ''));
        }
        applyTimes(row, data);
        if (data.rows !== null && data.rows !== undefined) {
            setText(row, 'rows', data.rows + ' rows');
        }
        if (data.size !== null && data.size !== undefined) {
            setText(row, 'size', Math.ceil(data.size / 1024) + ' KB');
        }
        if (data.error) {
            var error = row.querySelector('[data-role="error"]');
            if (!error) {
                error = document.createElement('p');
                error.className = 'xe-note xe-note-bad';
                error.setAttribute('data-role', 'error');
                row.appendChild(error);
            }
            error.textContent = data.error;
        }
        if (data.state === 'done' && data.has_file && downloadBase && !row.querySelector('[data-role="download"]')) {
            var buttons = row.querySelector('.xe-job-buttons');
            if (buttons) {
                var link = document.createElement('a');
                link.className = 'button';
                link.setAttribute('data-role', 'download');
                link.href = downloadBase + '/' + row.getAttribute('data-job-id');
                link.textContent = downloadLabel;
                buttons.insertBefore(link, buttons.firstChild);
            }
        }
        if (data.state !== 'queued' && data.state !== 'running') {
            row.removeAttribute('data-poll');
            var wrap = row.querySelector('[data-role="progress-wrap"]');
            if (wrap && data.state !== 'running') {
                wrap.hidden = true;
            }
        }
        applyCounts();
    }

    function poll() {
        var rows = activeRows();
        if (!rows.length) {
            window.clearInterval(timer);
            return;
        }
        rows.forEach(function (row) {
            var id = row.getAttribute('data-job-id');
            var logEl = row.querySelector('[data-role="log"]');
            var logOffset = logEl ? (parseInt(logEl.getAttribute('data-offset'), 10) || 0) : 0;
            fetch(pollBase + '/' + id + '?log_offset=' + logOffset, { credentials: 'same-origin' })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (data) { if (data && !data.error) { applyState(row, data); } })
                .catch(function () {});
        });
    }

    var timer = window.setInterval(poll, 2000);

    list.addEventListener('submit', function (event) {
        var form = event.target.closest('.xe-job-delete-form');
        if (!form) {
            return;
        }
        var button = form.querySelector('button[data-confirm]');
        if (button && !window.confirm(button.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });
}());

/*
 * "Run in the background" on the CSV and archive pages: disable the button once clicked so a slow
 * redirect cannot be doubled into two jobs. The form still submits normally.
 */
(function () {
    'use strict';
    document.querySelectorAll('input[name="RunInBackground"]').forEach(function (button) {
        var form = button.form;
        if (!form) {
            return;
        }
        form.addEventListener('submit', function (event) {
            if (event.submitter === button || (!event.submitter && document.activeElement === button)) {
                window.setTimeout(function () { button.disabled = true; }, 0);
            }
        });
    });
}());

/**
 * Sections that remember whether they are open (<details class="xe-remember" data-preference-url=...>):
 * opening or closing one stores "open" or "closed" in the user's eZ preference (words, not 1/0: an unset
 * preference is false in the template, and false|ne( '0' ) is false), so the next page load shows it the same way.
 */
(function () {
    'use strict';

    if (!window.fetch) {
        return;
    }
    Array.prototype.forEach.call(document.querySelectorAll('details.xe-remember[data-preference-url]'), function (details) {
        details.addEventListener('toggle', function () {
            var url = details.getAttribute('data-preference-url');
            fetch(url + '/' + (details.open ? 'open' : 'closed'), { credentials: 'same-origin', redirect: 'manual' }).catch(function () {});
        });
    });

    // A link to a closed section (e.g. the "Content package" chip, or #xe-ref-packages in the address) opens
    // it first, so the jump lands on its contents and not on a folded heading
    function openTarget(hash) {
        var target = hash && hash.length > 1 ? document.getElementById(hash.slice(1)) : null;
        if (target && target.tagName === 'DETAILS' && !target.open) {
            target.open = true;
        }
        return target;
    }
    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[href^="#"]') : null;
        var target = link ? openTarget(link.getAttribute('href')) : null;
        if (target) {
            event.preventDefault();
            target.scrollIntoView({ block: 'start' });
            if (history.replaceState) {
                history.replaceState(null, '', link.getAttribute('href'));
            }
        }
    });
    openTarget(location.hash);
}());

/**
 * Keeping the reader's place across a reload. Many buttons of these views post the form and the page comes
 * back from the top; with this many sections that loses the reader. When a form is submitted, the pressed
 * button (name and value) and its section are remembered with their height on screen (sessionStorage, this
 * tab only). When the same view loads again within ten minutes - after the post, a redirect, or a trip to the
 * content browser and back - the page scrolls so that button (or, if it is gone, its section) sits where it
 * was, outlines the section for a moment, and gives the button the keyboard focus again. A submit that
 * downloads a file instead of reloading is forgotten as soon as the page is used again, so a later visit
 * starts at the top as usual; so is a link with its own #anchor.
 */
(function () {
    'use strict';

    if (!document.querySelector('.xe-view')) {
        return;
    }
    var KEY = 'xrowextract-place:' + location.pathname.replace(/\/+$/, '');
    var store = null;
    try {
        store = window.sessionStorage;
    } catch (e) {
        return;
    }

    function sectionOf(el) {
        var section = el && el.closest ? el.closest('section, .xe-card, fieldset') : null;
        if (!section) {
            return null;
        }
        var id = section.id || section.getAttribute('aria-labelledby') || '';
        return id ? { el: section, id: id, byLabel: !section.id } : null;
    }

    function findSection(note) {
        if (note.byLabel) {
            var heading = document.getElementById(note.section);
            return heading ? (heading.closest('section, .xe-card, fieldset') || heading) : null;
        }
        return document.getElementById(note.section);
    }

    function findButton(note) {
        if (!note.name) {
            return null;
        }
        var candidates = document.querySelectorAll('[name="' + note.name.replace(/(["\\])/g, '\\$1') + '"]');
        for (var i = 0; i < candidates.length; i++) {
            var c = candidates[i];
            if ((c.type === 'submit' || c.tagName === 'BUTTON') && (note.value === null || c.value === note.value)) {
                return c;
            }
        }
        return null;
    }

    document.addEventListener('submit', function (event) {
        var button = event.submitter || document.activeElement;
        var section = sectionOf(button && button.form ? button : event.target);
        if (!section) {
            try { store.removeItem(KEY); } catch (e) {}
            return;
        }
        var anchor = button && button.getBoundingClientRect ? button : section.el;
        var note = {
            section: section.id,
            byLabel: section.byLabel,
            name: button && button.name ? button.name : '',
            value: button && button.name ? button.value : null,
            top: Math.round(anchor.getBoundingClientRect().top),
            sectionTop: Math.round(section.el.getBoundingClientRect().top),
            when: Date.now()
        };
        try { store.setItem(KEY, JSON.stringify(note)); } catch (e) {}
        // Still being used here afterwards (a click, a key, a scroll a moment later): the submit was a
        // download, not a reload, so the note is stale. A slow post that is still loading keeps it.
        window.setTimeout(function () {
            var events = ['pointerdown', 'keydown', 'wheel', 'touchstart'];
            var forget = function () {
                try { store.removeItem(KEY); } catch (e) {}
                events.forEach(function (type) { document.removeEventListener(type, forget, true); });
            };
            events.forEach(function (type) { document.addEventListener(type, forget, true); });
        }, 1500);
    }, true);

    var note = null;
    try {
        note = JSON.parse(store.getItem(KEY) || 'null');
        store.removeItem(KEY);
    } catch (e) {
        note = null;
    }
    if (!note || !note.when || Date.now() - note.when > 10 * 60 * 1000 || location.hash) {
        return;
    }
    function restore() {
        var button = findButton(note);
        var section = findSection(note);
        var anchor = button || section;
        if (!anchor) {
            return;
        }
        var wanted = button ? note.top : note.sectionTop;
        window.scrollTo(0, Math.max(0, window.pageYOffset + anchor.getBoundingClientRect().top - wanted));
        // Highlight the block the reader was working in - the button's own field group, which is always on
        // screen next to the button - not the whole card: a tall card's outline starts above the window and
        // reads as the section above. The card only gets a quiet edge mark.
        var group = button ? (button.closest('.xe-field, fieldset, .xe-toolbar, .xe-format-tile') || button.parentNode) : null;
        var card = (button || section) ? (button || section).closest('section, .xe-card') : null;
        var marks = [];
        if (group && group !== card) {
            marks.push([group, 'xe-returned']);
        } else if (card) {
            marks.push([card, 'xe-returned']);
        }
        if (card && group && group !== card) {
            marks.push([card, 'xe-returned-card']);
        }
        // Next frame, after the scroll, so the pulse starts where the reader is looking
        window.requestAnimationFrame(function () {
            marks.forEach(function (m) { m[0].classList.add(m[1]); });
            window.setTimeout(function () {
                marks.forEach(function (m) { m[0].classList.remove(m[1]); });
            }, 2600);
        });
        if (button && button.focus) {
            try { button.focus({ preventScroll: true }); } catch (e) { button.focus(); }
        }
    }
    if (document.readyState === 'complete') {
        restore();
    } else {
        window.addEventListener('load', restore);
    }
}());

/**
 * A submit button carrying data-confirm asks for confirmation before its form submits - used by
 * "Install this package"/"Install as a background job" on xrowextract/import when the package is a
 * "Try a sample" one (it writes real content), the same data-confirm attribute convention the Jobs
 * page's own delete buttons already use, generalised here to any button on any of this module's forms
 * (the Import page is one single form, not one form per button, so the Jobs-page listener above - scoped
 * to .xe-job-delete-form - does not reach it).
 */
(function () {
    'use strict';
    document.querySelectorAll('button[data-confirm], input[data-confirm]').forEach(function (control) {
        var form = control.form;
        if (!form) {
            return;
        }
        form.addEventListener('submit', function (event) {
            var submitter = event.submitter || (document.activeElement === control ? control : null);
            if (submitter === control && !window.confirm(control.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });
}());

/**
 * "Upload a file of this kind" on a File-card format tile (pass 1 of the redesign): scrolls to and
 * focuses the one shared upload field, narrows its accept filter to that format's own extensions (a
 * convenience for the OS file picker only - detection stays server-side, by content, once a file is
 * actually chosen: XrowExtractPackage::detectUploadKind()/XrowExtractImport::detectFormat()), and
 * shows which kind is expected next to it until a file is picked or another tile is used instead.
 */
(function () {
    'use strict';
    var fileInput = document.getElementById('xe-file');
    var expecting = document.querySelector('.xe-upload-expecting');
    if (!fileInput) {
        return;
    }
    var defaultAccept = fileInput.getAttribute('accept') || '';
    document.querySelectorAll('.xe-format-upload-hint').forEach(function (button) {
        button.addEventListener('click', function () {
            var format = button.getAttribute('data-format') || '';
            var accept = button.getAttribute('data-accept');
            if (accept) {
                fileInput.setAttribute('accept', accept);
            }
            if (expecting) {
                var template = expecting.getAttribute('data-expecting') || '%format';
                expecting.textContent = format ? template.replace('%format', format) : '';
                expecting.hidden = !format;
            }
            fileInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
            try { fileInput.focus({ preventScroll: true }); } catch (e) { fileInput.focus(); }
        });
    });
    // Choosing a file directly (any tile's hint, or the field itself) is not itself a commitment to
    // that one format - widen the filter back once a file is actually picked, so nothing is hidden
    // from the OS file picker if the user changes their mind before clicking Upload.
    fileInput.addEventListener('change', function () {
        if (expecting) {
            expecting.hidden = true;
        }
        if (fileInput.files && fileInput.files.length) {
            fileInput.setAttribute('accept', defaultAccept);
        }
    });
}());

/**
 * One class choice, not two: the File card's "Class for these actions" select
 * (#xe-sample-class, drives every format tile) and "Class and matching"'s own Class select
 * (#xe-class) show the same underlying class list and are kept mirrored here, so picking either
 * one is reflected in the other without a page reload. import.php's own fallback (either posted
 * value drives $ClassID, the File-card one taking priority) covers a visitor without JavaScript,
 * for whom this sync never runs.
 */
(function () {
    'use strict';
    var top = document.getElementById('xe-sample-class');
    var step2 = document.getElementById('xe-class');
    if (!top || !step2) {
        return;
    }
    var mirror = function (from, to) {
        if (to.value !== from.value && to.querySelector('option[value="' + from.value + '"]')) {
            to.value = from.value;
        }
    };
    top.addEventListener('change', function () { mirror(top, step2); });
    step2.addEventListener('change', function () { mirror(step2, top); });
}());
