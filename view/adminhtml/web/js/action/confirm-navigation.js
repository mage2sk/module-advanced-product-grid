/**
 * Confirms a navigation/grid-state change if the grid has unsaved edits.
 *
 * Returns a Promise<boolean>:
 *   - true  → proceed
 *   - false → user wants to stay
 *
 * Mixins call this before invoking the original method.
 */
define([
    'Magento_Ui/js/modal/confirm',
    'mage/translate',
    'Panth_AdvancedProductGrid/js/model/unsaved-flag'
], function (confirm, $t, dirtyFlag) {
    'use strict';

    return function () {
        return new Promise(function (resolve) {
            if (!dirtyFlag.get()) {
                resolve(true);
                return;
            }
            confirm({
                title: $t('Unsaved changes'),
                content: $t('You have unsaved edits in the grid. Continuing will discard them. Continue?'),
                actions: {
                    confirm: function () {
                        dirtyFlag.clear();
                        resolve(true);
                    },
                    cancel: function () { resolve(false); }
                }
            });
        });
    };
});
