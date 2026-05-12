/**
 * Three-tab Columns control panel (Default / Extra / Attribute).
 *
 * The view's source of truth is the bookmark — toggling visible/editable/
 * filterable/marker writes back to the current bookmark via the standard
 * bookmark service so a reload picks up the latest state.
 */
define([
    'underscore',
    'uiRegistry',
    'Magento_Ui/js/grid/controls/columns'
], function (_, registry, BaseColumns) {
    'use strict';

    return BaseColumns.extend({
        defaults: {
            template: 'Panth_AdvancedProductGrid/controls/columns',
            tracks: { activeTab: true },
            activeTab: 'default'
        },

        initialize: function () {
            this._super();
            return this;
        },

        groupedColumns: function () {
            var groups = { default: [], extra: [], attribute: [] };
            (this.elems() || []).forEach(function (col) {
                var cfg = col.config || (col.getData && col.getData('config')) || {};
                var panth = cfg.panthGridColumn || {};
                if (panth.is_attribute) {
                    groups.attribute.push(col);
                } else if (panth.extra) {
                    groups.extra.push(col);
                } else {
                    groups.default.push(col);
                }
            });
            return groups;
        },

        setTab: function (tab) {
            this.activeTab = tab;
        },

        toggleVisible: function (col) {
            col.visible(!col.visible());
            this.persistColumn(col);
        },

        toggleEditable: function (col) {
            var cfg = col.config || {};
            cfg.panthGridColumn = cfg.panthGridColumn || {};
            cfg.panthGridColumn.editable = !cfg.panthGridColumn.editable;
            col.config = cfg;
            this.persistColumn(col);
        },

        toggleFilterable: function (col) {
            var cfg = col.config || {};
            cfg.panthGridColumn = cfg.panthGridColumn || {};
            cfg.panthGridColumn.filterable = !cfg.panthGridColumn.filterable;
            col.config = cfg;
            this.persistColumn(col);
        },

        persistColumn: function (col) {
            var bookmarks = registry.get(this.bookmarksProvider || 'bookmarks');
            if (!bookmarks) { return; }
            var current = bookmarks.current || (bookmarks.viewIndex && bookmarks.viewIndex.current);
            if (!current) { return; }
            var path = ['panthGridColumns', col.index || col.name];
            var cfg = col.config || {};
            var payload = cfg.panthGridColumn || {};
            payload.visible = !!col.visible();
            current.data = current.data || {};
            current.data.panthGridColumns = current.data.panthGridColumns || {};
            current.data.panthGridColumns[col.index || col.name] = payload;
            if (typeof bookmarks.saveCurrent === 'function') {
                bookmarks.saveCurrent();
            }
        }
    });
});
