define(['underscore'], function (_) {
    'use strict';

    var NAMESPACE = 'product_listing';

    function isProductGrid(editor) {
        return String(editor.name || '').indexOf(NAMESPACE + '.') === 0;
    }

    function isBlank(value) {
        return _.isUndefined(value) || _.isNull(value) || value === '';
    }

    function isSame(a, b) {
        return (isBlank(a) && isBlank(b)) || _.isEqual(a, b);
    }

    function changedFields(record, indexField) {
        var current = record.getData(),
            normalized = record.normalizeData(_.extend({}, current)),
            saved = record.getSavedData(),
            out = {};

        _.each(current, function (value, key) {
            if (key === indexField || !isSame(normalized[key], saved[key])) {
                out[key] = _.isUndefined(value) ? null : value;
            }
        });

        if (!_.has(out, indexField)) {
            out[indexField] = (record.data || {})[indexField];
        }

        return out;
    }

    return function (Editor) {
        return Editor.extend({
            getData: function () {
                var indexField = this.indexField,
                    data;

                if (!isProductGrid(this)) {
                    return this._super();
                }

                data = this.activeRecords.map(function (record) {
                    return changedFields(record, indexField);
                });

                return _.indexBy(data, indexField);
            }
        });
    };
});
