/**
 * Tiny extension over Magento's standard listing component. Magento's
 * built-in editor (Magento_Ui/js/grid/editing/editor) already handles
 * the click-to-edit flow, Save/Cancel banner, and POST to the configured
 * saveUrl — we just sit on top and expose a hook for the unsaved-changes
 * dirty flag so our cross-component mixins can react.
 */
define([
    'Magento_Ui/js/grid/listing',
    'Panth_AdvancedProductGrid/js/model/unsaved-flag'
], function (Listing, dirtyFlag) {
    'use strict';

    return Listing.extend({
        defaults: {
            listens: {
                '${ $.name }_editor:isDirty': 'onEditorDirty'
            }
        },

        onEditorDirty: function (isDirty) {
            dirtyFlag.set(!!isDirty);
        }
    });
});
