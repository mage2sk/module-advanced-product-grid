define(['underscore'], function (_) {
    'use strict';

    /**
     * Magento_Ui/js/grid/columns/select.getLabel does:
     *   var values = this._super();          // raw record value
     *   if (_.isString(values)) values = values.split(',');
     *   ...lookup each in options...
     *
     * The unconditional `.split(',')` breaks single-select attributes
     * whose option values contain commas — e.g. meta_robots stores
     * `INDEX,FOLLOW` and the split yields `['INDEX','FOLLOW']` which
     * never matches any option.
     *
     * This mixin replaces getLabel entirely. It reads the raw value
     * from the record (NOT from `this._super()` — which would re-enter
     * the buggy method), tries a direct un-split lookup first, and
     * only falls back to comma-split for genuine multiselect-shaped
     * data (`12,34,56`-style option_id lists).
     */
    return function (Select) {
        return Select.extend({
            getLabel: function (record) {
                var options = this.flatOptions(this.options || []),
                    raw;

                if (record !== undefined && record !== null) {
                    raw = record[this.index];
                } else if (typeof this.value === 'function') {
                    raw = this.value();
                }

                if (raw === null || raw === undefined || raw === '') {
                    return '';
                }

                if (!_.isArray(raw)) {
                    var direct = _.find(options, function (opt) {
                        return (opt.value + '') === (raw + '');
                    });
                    if (direct) {
                        return direct.label;
                    }
                }

                var values = raw;
                if (_.isString(values)) {
                    values = values.split(',');
                }
                if (!_.isArray(values)) {
                    values = [values];
                }
                values = values.map(function (v) { return v + ''; });

                var labels = [];
                options.forEach(function (item) {
                    if (_.contains(values, item.value + '')) {
                        labels.push(item.label);
                    }
                });

                if (labels.length) {
                    return labels.join(', ');
                }

                return _.isArray(raw) ? raw.join(', ') : (raw + '');
            },

            getLabelUnsanitizedHtml: function (record) {
                return this.getLabel(record);
            }
        });
    };
});
