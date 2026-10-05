/**
 * KO component that drives the tier-price modal rows.
 *
 * On Save (handled by the dialog wrapper) the current rows are routed
 * through the inline-edit endpoint as a tier_price array.
 */
define([
    'underscore',
    'ko',
    'uiRegistry',
    'Magento_Ui/js/lib/core/element/element'
], function (_, ko, registry, Element) {
    'use strict';

    return Element.extend({
        defaults: {
            productId: 0,
            websites: [],
            groups: [],
            rows: [],
            tracks: { rows: true }
        },

        initialize: function () {
            this._super();
            this.rows = (this.rows || []).map(this.normalizeRow);
            return this;
        },

        normalizeRow: function (row) {
            return {
                website_id: row.website_id || 0,
                cust_group: row.cust_group || 32000,
                qty: row.qty || 1,
                value: row.value || 0,
                value_type: row.value_type || 'fixed',
                percentage_value: row.percentage_value || 0
            };
        },

        addRow: function () {
            this.rows = this.rows.concat(this.normalizeRow({}));
        },

        removeRow: function (row) {
            this.rows = this.rows.filter(function (r) { return r !== row; });
        },

        snapshot: function () {
            return this.rows.map(function (r) {
                return {
                    website_id: parseInt(r.website_id, 10) || 0,
                    cust_group: parseInt(r.cust_group, 10) || 0,
                    qty: parseFloat(r.qty) || 1,
                    value: parseFloat(r.value) || 0,
                    value_type: r.value_type === 'percent' ? 'percent' : 'fixed',
                    percentage_value: r.value_type === 'percent' ? parseFloat(r.value) || 0 : 0
                };
            });
        }
    });
});
