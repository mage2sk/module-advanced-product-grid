/**
 * Custom mass-action handler.
 *
 * Magento_Ui/js/grid/tree-massactions handles all mass actions; the
 * mass-edit mixin (mixin/tree-massactions-mixin.js) listens for actions
 * whose `type === 'panth_mass_edit'` and routes them here.
 *
 * Flow:
 *   1. Fetch the attribute catalogue from `modalUrl` (already in the
 *      action config from MassEditOption.php).
 *   2. Mount a KO modal with one collapsible section per group.
 *      Each attribute row has an "enabled" toggle + the right input
 *      type (text / textarea / select / multiselect / date / price).
 *   3. On Save, POST `selected[]` + `changes{}` to the action's url.
 *
 * The modal does NOT do client-side validation beyond "is this attribute
 * enabled to apply" — the server is the source of truth for which fields
 * pass per-attribute validation.
 */
define([
    'jquery',
    'underscore',
    'ko',
    'Magento_Ui/js/modal/modal',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function ($, _, ko, modal, alert, $t) {
    'use strict';

    var openModalState = null;

    function buildModal(action, selectedIds) {
        var $node = $('<div class="panth-pg-massedit-modal"></div>');
        var state = {
            loading: ko.observable(true),
            error: ko.observable(''),
            groups: ko.observableArray([]),
            saving: ko.observable(false),
            saveButtonLabel: ko.computed(function () {
                return $t('Apply to ') + selectedIds.length + ' ' + (selectedIds.length === 1 ? $t('product') : $t('products'));
            }, this),
            enabledCount: ko.observable(0),
            recalcEnabled: function () {
                var count = 0;
                ko.utils.arrayForEach(state.groups(), function (group) {
                    ko.utils.arrayForEach(group.attributes, function (attr) {
                        if (attr.enabled()) { count++; }
                    });
                });
                state.enabledCount(count);
            },
            close: function () { $node.trigger('closeModal'); },
            save: function () { performSave(); }
        };

        var $modal = modal({
            title: $t('Mass Edit Attributes') + ' — ' + selectedIds.length + ' ' + (selectedIds.length === 1 ? $t('product') : $t('products')) + ' ' + $t('selected'),
            modalClass: 'panth-pg-massedit',
            type: 'slide',
            buttons: [
                { text: $t('Cancel'), class: 'action-secondary', click: function () { this.closeModal(); } },
                {
                    text: $t('Apply Changes'),
                    class: 'action-primary panth-pg-massedit__apply',
                    click: function () { state.save(); }
                }
            ]
        }, $node);

        $node.html(renderTemplate());
        ko.applyBindings(state, $node[0]);
        $node.trigger('openModal');

        $.ajax({
            url: action.modalUrl,
            method: 'GET',
            dataType: 'json'
        }).done(function (response) {
            if (!response || !response.success) {
                state.error(response && response.error ? response.error : $t('Failed to load attributes.'));
                state.loading(false);
                return;
            }
            var groups = [];
            _.each(response.groups, function (attrs, groupName) {
                groups.push({
                    name: groupName,
                    expanded: ko.observable(true),
                    attributes: attrs.map(function (a) {
                        return {
                            code: a.code,
                            label: a.label,
                            editorType: a.editor_type,
                            options: a.options || [],
                            enabled: ko.observable(false),
                            value: ko.observable(a.editor_type === 'multiselect' ? [] : ''),
                            placeholder: a.placeholder || ''
                        };
                    })
                });
            });
            state.groups(groups);
            // Wire change events on the enabled observables.
            ko.utils.arrayForEach(groups, function (group) {
                ko.utils.arrayForEach(group.attributes, function (attr) {
                    attr.enabled.subscribe(state.recalcEnabled);
                });
            });
            state.loading(false);
        }).fail(function (xhr) {
            state.error($t('Failed to load attributes: ') + (xhr.statusText || 'unknown'));
            state.loading(false);
        });

        function performSave() {
            var changes = {};
            ko.utils.arrayForEach(state.groups(), function (group) {
                ko.utils.arrayForEach(group.attributes, function (attr) {
                    if (!attr.enabled()) { return; }
                    var value = attr.value();
                    if (attr.editorType === 'multiselect' && value && value.split) {
                        value = value.split(',').map(function (v) { return v.trim(); }).filter(Boolean);
                    }
                    changes[attr.code] = value;
                });
            });
            if (_.isEmpty(changes)) {
                alert({ title: $t('Nothing to apply'), content: $t('Toggle at least one attribute and provide a value.') });
                return;
            }
            state.saving(true);
            var form = new FormData();
            form.append('form_key', window.FORM_KEY || '');
            form.append('store_id', String(action.storeId || 0));
            selectedIds.forEach(function (id) { form.append('selected[]', String(id)); });
            _.each(changes, function (value, key) {
                if (Array.isArray(value)) {
                    value.forEach(function (v) { form.append('changes[' + key + '][]', v); });
                } else if (typeof value === 'object' && value !== null) {
                    form.append('changes[' + key + ']', JSON.stringify(value));
                } else {
                    form.append('changes[' + key + ']', value === null ? '' : String(value));
                }
            });

            $.ajax({
                url: action.url,
                method: 'POST',
                data: form,
                processData: false,
                contentType: false,
                dataType: 'json'
            }).done(function (response) {
                state.saving(false);
                if (response && response.success) {
                    $node.trigger('closeModal');
                    location.reload();
                    return;
                }
                state.error((response && response.messages || []).join('\n') || (response && response.error) || $t('Save failed.'));
            }).fail(function (xhr) {
                state.saving(false);
                state.error($t('Server error: ') + (xhr.statusText || 'unknown'));
            });
        }

        function renderTemplate() {
            return [
                '<div data-bind="visible: loading" class="panth-pg-massedit__loading">',
                '  <span data-bind="i18n: \'Loading attributes…\'"></span>',
                '</div>',
                '<div data-bind="visible: error" class="message message-error panth-pg-massedit__error">',
                '  <span data-bind="text: error"></span>',
                '</div>',
                '<div data-bind="visible: !loading() && !error()" class="panth-pg-massedit__body">',
                '  <p class="panth-pg-massedit__hint">',
                '    <span data-bind="i18n: \'Toggle the attributes you want to change. Values from disabled attributes are ignored.\'"></span>',
                '  </p>',
                '  <p class="panth-pg-massedit__counter">',
                '    <span data-bind="i18n: \'Enabled:\'"></span>',
                '    <strong data-bind="text: enabledCount"></strong>',
                '  </p>',
                '  <!-- ko foreach: groups -->',
                '    <section class="panth-pg-massedit__group">',
                '      <header data-bind="click: function () { expanded(!expanded()); }">',
                '        <span data-bind="css: { collapsed: !expanded() }">▾</span>',
                '        <span data-bind="text: name"></span>',
                '        <span class="panth-pg-massedit__group-count">',
                '          <span data-bind="text: attributes.length"></span>',
                '        </span>',
                '      </header>',
                '      <div class="panth-pg-massedit__rows" data-bind="visible: expanded">',
                '        <!-- ko foreach: attributes -->',
                '          <div class="panth-pg-massedit__row" data-bind="css: { _enabled: enabled() }">',
                '            <label class="panth-pg-massedit__toggle">',
                '              <input type="checkbox" data-bind="checked: enabled"/>',
                '              <span data-bind="text: label"></span>',
                '              <span class="panth-pg-massedit__code" data-bind="text: code"></span>',
                '            </label>',
                '            <div class="panth-pg-massedit__input" data-bind="visible: enabled">',
                '              <!-- ko if: editorType === \'text\' -->',
                '                <input type="text" class="admin__control-text" data-bind="value: value, attr: { placeholder: placeholder }"/>',
                '              <!-- /ko -->',
                '              <!-- ko if: editorType === \'price\' -->',
                '                <input type="number" step="any" class="admin__control-text" data-bind="value: value"/>',
                '              <!-- /ko -->',
                '              <!-- ko if: editorType === \'date\' -->',
                '                <input type="date" class="admin__control-text" data-bind="value: value"/>',
                '              <!-- /ko -->',
                '              <!-- ko if: editorType === \'textarea\' -->',
                '                <textarea class="admin__control-textarea" rows="3" data-bind="value: value"></textarea>',
                '              <!-- /ko -->',
                '              <!-- ko if: editorType === \'select\' -->',
                '                <select class="admin__control-select" data-bind="options: options, optionsText: \'label\', optionsValue: \'value\', value: value, optionsCaption: \'-- Select --\'"></select>',
                '              <!-- /ko -->',
                '              <!-- ko if: editorType === \'multiselect\' -->',
                '                <input type="text" class="admin__control-text" data-bind="value: value, attr: { placeholder: placeholder || \'value1,value2,value3\' }"/>',
                '              <!-- /ko -->',
                '            </div>',
                '          </div>',
                '        <!-- /ko -->',
                '      </div>',
                '    </section>',
                '  <!-- /ko -->',
                '</div>'
            ].join('\n');
        }
    }

    return {
        execute: function (action, data) {
            var selectedIds = (data && data.selected) || [];
            if (selectedIds.length === 0) {
                alert({ title: $t('No products selected'), content: $t('Tick at least one product checkbox before mass editing.') });
                return;
            }
            buildModal(action, selectedIds.map(function (id) { return parseInt(id, 10); }));
        }
    };
});
