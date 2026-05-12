/**
 * Shared "are there unsaved edits in the grid?" signal.
 *
 * Stored as a window-level singleton so every Magento_Ui mixin can read
 * the same value without depending on the editor component's instance
 * being available in the dependency graph (some mixins fire on global
 * grid events).
 */
define([], function () {
    'use strict';

    var KEY = '__panthPgridDirty';

    return {
        get: function () {
            return !!window[KEY];
        },
        set: function (value) {
            window[KEY] = !!value;
        },
        clear: function () {
            window[KEY] = false;
        }
    };
});
