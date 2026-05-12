/**
 * Shared mixin factory.
 *
 * Wraps the target method on the host so it first awaits a confirm-if-dirty
 * dialog. Used by every Magento UI grid mixin in this module so the
 * "are there pending edits" pattern lives in one place.
 *
 * Returns a function that, given the mixin's targetMethod name, returns
 * a mixin object.
 */
define([
    'Panth_AdvancedProductGrid/js/model/unsaved-flag',
    'Panth_AdvancedProductGrid/js/action/confirm-navigation'
], function (dirtyFlag, confirmNavigation) {
    'use strict';

    return function (methodName) {
        var mixin = {};
        mixin[methodName] = function () {
            var args = Array.prototype.slice.call(arguments);
            var self = this;

            // Fast path: when nothing is dirty, call through synchronously
            // and preserve the original return value so any chainable
            // behavior (return this) still works for downstream listeners.
            if (!dirtyFlag.get()) {
                return this._super.apply(this, args);
            }

            var orig = this._super;
            confirmNavigation().then(function (proceed) {
                if (proceed) {
                    orig.apply(self, args);
                }
            });
        };
        return function (target) { return target.extend(mixin); };
    };
});
