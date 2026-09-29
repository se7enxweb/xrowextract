/*
 * xrowextract: chunked upload, shared by the import view's "File" card and the Package tab's
 * upload form. Each .xe-chunked-upload widget names the one <input type=file> it belongs to
 * (data-file-field: "ImportFile" or "PackageBinaryFile") so one script instance tracks either
 * form without hard-coding either one. Splits the chosen file into chunks (Blob.slice) and posts
 * each to xrowextract/upload_chunk with fetch(), so a file of any size uploads independent of
 * PHP's upload_max_filesize/post_max_size - only real free disk space limits it, and (on
 * Velocity) a huge single-request body never has to be read whole before the application sees
 * it, which is what a slow-arriving multi-megabyte multipart POST is for a worker's own read
 * loop to stay "busy" in for far longer than a chunk ever does. A progress bar shows bytes sent;
 * Pause stops between chunks, Resume continues; a failed chunk (a dropped connection) is retried
 * a few times, then re-synced against the server's own count of bytes received before trying
 * again, so a resend can never duplicate or corrupt what already arrived.
 *
 * Progressive enhancement: without this script (or old browsers lacking fetch/Blob.slice), the
 * plain <input type="file"> still submits the whole file in the one request the form's own
 * submit button already posts, unchanged.
 *
 * Delegated at the document level, and every element looked up fresh from
 * the event target rather than cached at load time: the admin shell's own
 * navigation/content refresh can replace the card's markup after this
 * script first ran, and a second copy of this same script may then load
 * and run again - both must still find a live, working file input.
 */
(function () {
    'use strict';

    if (window.__xeChunkedUploadInit) {
        return;
    }
    window.__xeChunkedUploadInit = true;

    // Deliberately well under the common php.ini post_max_size default (8M): a chunk this size plus
    // the multipart form's own overhead (boundaries, the other fields) was measured to exceed an 8M
    // post_max_size and fail every chunk - the whole point of chunking is to need no change there.
    var CHUNK_SIZE = 1 * 1024 * 1024; // 1 MB
    var MAX_RETRIES = 5;

    if (!window.fetch || !window.Blob || !Blob.prototype.slice || !window.FormData) {
        return;
    }

    var state = null; // { file, uploadId, sent, paused, cancelled, widget, form }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function human(bytes) {
        var units = ['B', 'KB', 'MB', 'GB'];
        var n = bytes;
        var i = 0;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return (i === 0 ? Math.round(n) : Math.round(n * 10) / 10) + ' ' + units[i];
    }

    function widgetParts(widget) {
        return {
            widget: widget,
            uploadIdInput: widget.querySelector('input[name=UploadID]'),
            nameInput: widget.querySelector('input[name=UploadName]'),
            bar: widget.querySelector('.xe-progress-bar'),
            statusText: widget.querySelector('.xe-upload-status'),
            pauseButton: widget.querySelector('.xe-upload-pause'),
            resumeButton: widget.querySelector('.xe-upload-resume'),
            cancelButton: widget.querySelector('.xe-upload-cancel'),
            uploadUrl: widget.getAttribute('data-upload-url'),
            // Which <input type=file> this widget belongs to: the import view's own ("ImportFile",
            // the default, for a widget predating this attribute) or the Package tab's
            // ("PackageBinaryFile") - one widget, one form, so this is enough to scope every lookup
            // below to the right field without hard-coding a single field/form name.
            fileField: widget.getAttribute('data-file-field') || 'ImportFile',
        };
    }

    function setStatus(text) {
        if (state && state.parts.statusText) state.parts.statusText.textContent = text;
    }

    function setProgress(sent, total) {
        var pct = total > 0 ? Math.min(100, Math.round((sent / total) * 100)) : 0;
        if (state && state.parts.bar) state.parts.bar.style.width = pct + '%';
        setStatus(human(sent) + ' of ' + human(total) + ' (' + pct + '%)');
    }

    function post(uploadUrl, fields, fileField) {
        var body = new FormData();
        Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
        if (fileField) body.append('Chunk', fileField.blob, 'chunk');
        var headers = {};
        var token = csrfToken();
        if (token) headers['X-CSRF-Token'] = token;
        return fetch(uploadUrl, { method: 'POST', body: body, headers: headers, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (data) { return { status: r.status, data: data }; }); });
    }

    function submitButtonFor(form) {
        // Both forms this script runs in (the import view's "Upload", the Package tab's
        // "UploadPackage") mark their real submit button this way; neither form has a second one.
        return form.querySelector('input.defaultbutton[type=submit]') || form.querySelector('input[name=Upload]');
    }

    function fail(message) {
        if (!state) return;
        state.parts.widget.classList.remove('xe-uploading');
        state.parts.widget.classList.add('xe-upload-error');
        setStatus(message);
        var submit = submitButtonFor(state.form);
        if (submit) submit.disabled = false;
    }

    function resync() {
        return post(state.parts.uploadUrl, { Action: 'status', UploadID: state.uploadId }).then(function (res) {
            if (!res.data.ok) throw new Error('status failed');
            state.sent = res.data.received;
            setProgress(state.sent, state.file.size);
            return state.sent;
        });
    }

    function sendChunk(retries) {
        if (!state || state.cancelled) return;
        if (state.paused) { setStatus('Paused at ' + human(state.sent) + ' of ' + human(state.file.size) + '.'); return; }
        if (state.sent >= state.file.size) {
            finish();
            return;
        }
        var end = Math.min(state.sent + CHUNK_SIZE, state.file.size);
        var blob = state.file.slice(state.sent, end);
        var offset = state.sent;
        post(state.parts.uploadUrl, { Action: 'chunk', UploadID: state.uploadId, Offset: offset }, { blob: blob })
            .then(function (res) {
                if (!state || state.cancelled) return;
                if (res.data && res.data.ok) {
                    state.sent = res.data.received;
                    setProgress(state.sent, state.file.size);
                    sendChunk(MAX_RETRIES);
                    return;
                }
                if (res.data && res.data.error === 'offset_mismatch' && typeof res.data.received === 'number') {
                    state.sent = res.data.received;
                    setProgress(state.sent, state.file.size);
                    sendChunk(MAX_RETRIES);
                    return;
                }
                throw new Error((res.data && res.data.error) || ('http ' + res.status));
            })
            .catch(function () {
                if (!state || state.cancelled) return;
                if (retries > 0) {
                    setStatus('Network error, retrying (' + retries + ' left)...');
                    setTimeout(function () { sendChunk(retries - 1); }, 800);
                } else {
                    resync().then(function () {
                        setStatus('Reconnected; resend when ready.');
                        state.parts.widget.classList.remove('xe-uploading');
                        if (state.parts.resumeButton) state.parts.resumeButton.hidden = false;
                        state.paused = true;
                    }).catch(function () {
                        fail('The upload could not continue (network error). Use Resume to try again.');
                        state.paused = true;
                        if (state.parts.resumeButton) state.parts.resumeButton.hidden = false;
                    });
                }
            });
    }

    function finish() {
        setStatus('Uploaded ' + human(state.file.size) + '.');
        state.parts.widget.classList.remove('xe-uploading');
        state.parts.widget.classList.add('xe-upload-done');
        state.parts.uploadIdInput.value = state.uploadId;
        state.parts.nameInput.value = state.file.name;
        // The real <input type="file"> still holds the original selection: clearing it here is
        // essential, not cosmetic. The click() below fires a genuine form submit, and a browser
        // submits whatever a file input currently holds regardless of how it got there - if this
        // were left alone, the "small" adopt-by-UploadID request would silently balloon back into
        // a multipart body carrying the entire original file, defeating the chunking above and
        // exceeding post_max_size exactly as an unchunked upload would (PHP then drops the whole
        // $_POST body, including UploadID, with no error - the adopt request just goes nowhere).
        var fileInput = state.form.querySelector('input[name="' + state.parts.fileField + '"]');
        if (fileInput) fileInput.value = '';
        var submit = submitButtonFor(state.form);
        if (submit) {
            submit.disabled = false;
            state.form.dataset.xeChunkedDone = '1';
            submit.click();
        }
    }

    function start(file, widget, form) {
        state = { file: file, uploadId: null, sent: 0, paused: false, cancelled: false, widget: widget, form: form, parts: widgetParts(widget) };
        var parts = state.parts;
        parts.widget.classList.remove('xe-upload-error', 'xe-upload-done');
        parts.widget.classList.add('xe-uploading');
        var submit = submitButtonFor(form);
        if (submit) submit.disabled = true;
        if (parts.pauseButton) parts.pauseButton.hidden = false;
        if (parts.resumeButton) parts.resumeButton.hidden = true;
        if (parts.cancelButton) parts.cancelButton.hidden = false;
        setProgress(0, file.size);
        post(parts.uploadUrl, { Action: 'start', Name: file.name, TotalSize: file.size }).then(function (res) {
            if (!res.data.ok) {
                fail(res.data.error || 'The upload could not start.');
                return;
            }
            state.uploadId = res.data.upload_id;
            sendChunk(MAX_RETRIES);
        }).catch(function () {
            fail('The upload could not start (network error).');
        });
    }

    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!input || input.type !== 'file' || !input.files || !input.files[0]) return;
        var form = input.form;
        var widget = form ? form.querySelector('.xe-chunked-upload') : null;
        // The widget names the one field it belongs to (data-file-field, default "ImportFile"):
        // a form can hold more than one plain file input (or none of interest to this script), and
        // only a change on that specific one starts a chunked upload.
        if (!form || !widget || input.name !== widgetParts(widget).fileField) return;
        start(input.files[0], widget, form);
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.xe-upload-pause, .xe-upload-resume, .xe-upload-cancel');
        if (!button || !state) return;
        event.preventDefault();
        if (button.classList.contains('xe-upload-pause')) {
            state.paused = true;
            button.hidden = true;
            if (state.parts.resumeButton) state.parts.resumeButton.hidden = false;
            setStatus('Paused at ' + human(state.sent) + ' of ' + human(state.file.size) + '.');
        } else if (button.classList.contains('xe-upload-resume')) {
            button.hidden = true;
            if (state.parts.pauseButton) state.parts.pauseButton.hidden = false;
            state.parts.widget.classList.add('xe-uploading');
            state.parts.widget.classList.remove('xe-upload-error');
            state.paused = false;
            resync().then(function () { sendChunk(MAX_RETRIES); }).catch(function () { sendChunk(MAX_RETRIES); });
        } else if (button.classList.contains('xe-upload-cancel')) {
            state.cancelled = true;
            state.parts.widget.classList.remove('xe-uploading', 'xe-upload-error', 'xe-upload-done');
            setStatus('');
            var form = state.form;
            var fileInput = form.querySelector('input[name="' + state.parts.fileField + '"]');
            if (fileInput) fileInput.value = '';
            var submit = submitButtonFor(form);
            if (submit) submit.disabled = false;
            state = null;
        }
    });

    // The plain submit button must not fire a normal (whole-file) submit once a chunked upload is in
    // flight; adopt-by-UploadID happens through the hidden field instead, and finish() clicks Upload
    // itself once the adopt fields are filled in (dataset.xeChunkedDone marks that click as allowed).
    document.addEventListener('submit', function (event) {
        var form = event.target;
        // Any form this script is tracking a chunked upload for, not just the import view's
        // "eZImport": the Package tab's "eZPackageUpload" needs the exact same guard.
        if (!state || state.form !== form) return;
        if (state.uploadId && !state.parts.uploadIdInput.value && form.dataset.xeChunkedDone !== '1') {
            event.preventDefault();
        }
    });
})();
