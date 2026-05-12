define([
    'underscore',
    'Magento_Ui/js/grid/columns/select'
], function (_, Select) {
    'use strict';

    return Select.extend({
        getLabel: function () {
            var options = this.flatOptions(this.options || []),
                rawValue = this._super(),
                values,
                labels = [];

            if (rawValue === null || rawValue === undefined || rawValue === '') {
                return '';
            }

            if (_.isArray(rawValue)) {
                values = rawValue.map(function (v) { return v + ''; });
            } else {
                values = [rawValue + ''];
            }

            values.forEach(function (val) {
                var hit = _.findWhere(options, { value: val });
                if (!hit) {
                    var opts = options.filter(function (o) { return (o.value + '') === val; });
                    hit = opts.length ? opts[0] : null;
                }
                if (hit) {
                    labels.push(hit.label);
                } else if (val !== '') {
                    labels.push(val);
                }
            });

            return labels.join(', ');
        }
    });
});
