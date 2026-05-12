/**
 * Detects mass actions of type `panth_mass_edit` and routes them through
 * the bulk-edit modal instead of letting Magento POST straight to the URL.
 * Other action types pass through to the standard handler.
 */
define([
    'underscore',
    'uiRegistry',
    'Panth_AdvancedProductGrid/js/action/confirm-navigation'
], function (_, registry, confirmNavigation) {
    'use strict';

    var MASS_EDIT_TYPE = 'panth_mass_edit';
    var MODAL_URL_PATH = 'panth_product_grid/massEdit/form';

    function buildAdminUrl(path) {
        var base = (window.BASE_URL || '/').replace(/\/$/, '');
        return base + '/' + path.replace(/^\//, '');
    }

    var mixin = {
        applyAction: function (actionIndex) {
            var orig = this._super;
            var self = this;
            var args = Array.prototype.slice.call(arguments);
            var action = _.findWhere(this.actions(), { type: actionIndex });

            confirmNavigation().then(function (proceed) {
                if (!proceed) { return; }
                if (action && action.type === MASS_EDIT_TYPE) {
                    self._panthLaunchMassEdit(action);
                    return;
                }
                orig.apply(self, args);
            });
        },

        _panthLaunchMassEdit: function (action) {
            var self = this;
            require(['Panth_AdvancedProductGrid/js/massaction/mass-edit'], function (handler) {
                var ids = self._panthGetSelections();
                handler.execute({
                    url: action.url,
                    modalUrl: action.modalUrl || buildAdminUrl(MODAL_URL_PATH),
                    storeId: 0
                }, { selected: ids });
            });
        },

        _panthGetSelections: function () {
            var selections = registry.get(this.selectProvider);
            if (!selections) { return []; }
            return selections.selected ? (selections.selected() || []) : [];
        }
    };

    return function (target) {
        return target.extend(mixin);
    };
});
