/*
 * xrowextract: the schedule form. Shows only the panel of the chosen kind (preset, site archive,
 * package, import) and the fields of the chosen frequency; fills the placeholder values of a preset
 * with its defaults when the field is still empty. Without this script every panel stays visible and
 * the form works the same way (the server only reads the chosen kind's fields).
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-role="schedule-form"]');
    if (!form) {
        return;
    }
    document.documentElement.classList.add('xe-js');

    function checked(role) {
        var input = form.querySelector('input[data-role="' + role + '"]:checked');
        return input ? input.value : '';
    }

    function showKind() {
        var kind = checked('kind');
        form.querySelectorAll('.xe-kind-panel').forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-kind') !== kind;
        });
    }

    function showFrequency() {
        var frequency = checked('frequency');
        form.querySelectorAll('[data-frequency]').forEach(function (field) {
            field.hidden = field.getAttribute('data-frequency').split(' ').indexOf(frequency) === -1;
        });
    }

    var presetSelect = form.querySelector('[data-role="preset-select"]');
    var presetParams = form.querySelector('[data-role="preset-params"]');
    function fillPlaceholders() {
        if (!presetSelect || !presetParams || presetParams.value.trim() !== '') {
            return;
        }
        var option = presetSelect.options[presetSelect.selectedIndex];
        var defaults = option ? option.getAttribute('data-placeholders') : '';
        if (defaults) {
            presetParams.value = defaults;
        }
    }

    form.addEventListener('change', function (event) {
        var role = event.target.getAttribute('data-role');
        if (role === 'kind') {
            showKind();
        } else if (role === 'frequency') {
            showFrequency();
        } else if (role === 'preset-select') {
            fillPlaceholders();
        }
    });
    showKind();
    showFrequency();
}());
