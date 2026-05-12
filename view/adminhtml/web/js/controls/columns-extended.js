/**
 * Smart Columns control panel — replaces Magento's stock dropdown with
 * a tabbed, inline-editable interface for every grid column:
 *
 *   - Tabs: Standard / Pricing / Inventory / SEO / Attributes / Extras
 *   - Per-row: drag to reorder, toggle visible, toggle inline-editable,
 *              inline custom-label rename, type badge, sort-order input
 *   - Mass actions: show-all, hide-all, reset
 *   - Apply All button batches DB writes through the column-config
 *     endpoint, then reloads the grid
 *
 * Read-only data (group, type, original label, current custom label)
 * comes from the Columns subclass via the `panthGridMeta` block we
 * inject in PHP.
 */
define([
    'jquery',
    'underscore',
    'ko',
    'Magento_Ui/js/grid/controls/columns',
    'mage/translate'
], function ($, _, ko, BaseColumns, $t) {
    'use strict';

    var TABS = [
        { id: 'standard', label: $t('Standard') },
        { id: 'pricing', label: $t('Pricing') },
        { id: 'inventory', label: $t('Inventory') },
        { id: 'seo', label: $t('SEO') },
        { id: 'attributes', label: $t('Attributes') },
        { id: 'extras', label: $t('Extras') }
    ];

    function endpoint(path) {
        return ((window.BASE_URL || '/').replace(/\/$/, '')) + '/panth_product_grid/columnManager/' + path;
    }

    return BaseColumns.extend({
        defaults: {
            template: 'Panth_AdvancedProductGrid/controls/columns-extended',
            tracks: {
                activeTab: true,
                searchQuery: true,
                dirty: true,
                saving: true
            },
            activeTab: 'standard',
            searchQuery: '',
            dirty: false,
            saving: false,
            pendingChanges: {}
        },

        initialize: function () {
            this._super();
            this.pendingChanges = {};
            return this;
        },

        getTabs: function () {
            return TABS;
        },

        setTab: function (id) {
            this.activeTab = id;
        },

        /**
         * Returns columns belonging to the current tab, filtered by the
         * search query. Each entry is the column UI Component itself —
         * we read its config + observables directly so KO bindings stay
         * live.
         */
        currentTabColumns: function () {
            var self = this;
            var query = (this.searchQuery || '').toLowerCase().trim();
            return _.sortBy(
                (this.elems() || []).filter(function (col) {
                    var meta = self._metaOf(col);
                    if (!meta) { return false; }
                    if (meta.group !== self.activeTab) { return false; }
                    if (query) {
                        var hay = (meta.code + ' ' + meta.originalLabel + ' ' + (meta.customLabel || '')).toLowerCase();
                        return hay.indexOf(query) !== -1;
                    }
                    return true;
                }),
                function (col) {
                    var meta = self._metaOf(col);
                    return meta ? (self._pendingFor(meta.code).sort_order || meta.sortOrder) : 0;
                }
            );
        },

        countInTab: function (tabId) {
            var self = this;
            return (this.elems() || []).reduce(function (sum, col) {
                var meta = self._metaOf(col);
                return sum + (meta && meta.group === tabId ? 1 : 0);
            }, 0);
        },

        visibleCountInTab: function (tabId) {
            var self = this;
            return (this.elems() || []).reduce(function (sum, col) {
                var meta = self._metaOf(col);
                if (!meta || meta.group !== tabId) { return sum; }
                return sum + (col.visible() ? 1 : 0);
            }, 0);
        },

        _metaOf: function (col) {
            var cfg = col && (col.config || (col.getData && col.getData('config')) || {});
            return (cfg && cfg.panthGridMeta) || null;
        },

        _pendingFor: function (code) {
            this.pendingChanges[code] = this.pendingChanges[code] || {};
            return this.pendingChanges[code];
        },

        // --- per-column actions (every one stages a change locally and marks dirty) -----

        toggleVisible: function (col) {
            col.visible(!col.visible());
            var meta = this._metaOf(col);
            if (!meta) { return; }
            this._pendingFor(meta.code).is_visible = col.visible() ? 1 : 0;
            this.dirty = true;
        },

        toggleEditable: function (col) {
            var meta = this._metaOf(col);
            if (!meta) { return; }
            meta.editable = !meta.editable;
            this._pendingFor(meta.code).is_editable = meta.editable ? 1 : 0;
            this.dirty = true;
        },

        toggleFilterable: function (col) {
            var meta = this._metaOf(col);
            if (!meta) { return; }
            meta.filterable = !meta.filterable;
            this._pendingFor(meta.code).is_filterable = meta.filterable ? 1 : 0;
            this.dirty = true;
        },

        updateCustomLabel: function (col, value) {
            var meta = this._metaOf(col);
            if (!meta) { return; }
            meta.customLabel = (value || '').trim();
            this._pendingFor(meta.code).custom_label = meta.customLabel;
            this.dirty = true;
        },

        updateSortOrder: function (col, value) {
            var meta = this._metaOf(col);
            if (!meta) { return; }
            var n = parseInt(value, 10);
            if (isNaN(n)) { n = meta.sortOrder; }
            meta.sortOrder = n;
            this._pendingFor(meta.code).sort_order = n;
            this.dirty = true;
        },

        // --- tab-scoped mass actions ----------------------------------------------------

        showAllInTab: function () {
            var self = this;
            this.currentTabColumns().forEach(function (col) {
                if (!col.visible()) { self.toggleVisible(col); }
            });
        },

        hideAllInTab: function () {
            var self = this;
            this.currentTabColumns().forEach(function (col) {
                if (col.visible()) { self.toggleVisible(col); }
            });
        },

        // --- batch persist + revert -----------------------------------------------------

        applyAll: function () {
            if (this.saving) { return; }
            if (!this.dirty) { return; }
            this.saving = true;
            var self = this;
            var form = new FormData();
            form.append('form_key', window.FORM_KEY || '');
            form.append('isAjax', '1');
            _.each(this.pendingChanges, function (changes, code) {
                _.each(changes, function (value, field) {
                    form.append('items[' + code + '][' + field + ']', value === null ? '' : String(value));
                });
            });
            $.ajax({
                url: endpoint('inlineEdit'),
                method: 'POST',
                data: form,
                processData: false,
                contentType: false,
                dataType: 'json'
            }).done(function (response) {
                self.saving = false;
                if (response && !response.error) {
                    location.reload();
                } else {
                    alert((response && response.messages || [$t('Save failed.')]).join('\n'));
                }
            }).fail(function () {
                self.saving = false;
                alert($t('Save failed.'));
            });
        },

        resetOverrides: function () {
            if (!confirm($t('Clear every saved column override and reload?'))) { return; }
            var self = this;
            $.ajax({
                url: endpoint('reset'),
                method: 'POST',
                data: { form_key: window.FORM_KEY || '' },
                dataType: 'json'
            }).done(function () { location.reload(); })
              .fail(function () { alert($t('Reset failed.')); });
        },

        getCustomLabel: function (col) {
            var meta = this._metaOf(col);
            return meta ? (meta.customLabel || '') : '';
        },

        getSortOrder: function (col) {
            var meta = this._metaOf(col);
            return meta ? meta.sortOrder : 100;
        },

        getOriginalLabel: function (col) {
            var meta = this._metaOf(col);
            return meta ? meta.originalLabel : col.label;
        },

        getColumnCode: function (col) {
            var meta = this._metaOf(col);
            return meta ? meta.code : (col.index || col.name);
        },

        getType: function (col) {
            var meta = this._metaOf(col);
            return meta ? (meta.type || 'text') : 'text';
        },

        isEditable: function (col) {
            var meta = this._metaOf(col);
            return meta ? !!meta.editable : false;
        },

        isFilterable: function (col) {
            var meta = this._metaOf(col);
            return meta ? !!meta.filterable : false;
        }
    });
});
