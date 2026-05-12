define([], function () {
    'use strict';
    // Toolbar has no single navigation hook to wrap; the per-child mixins
    // (filters, paging, sizes) cover the actual state changes. Empty so
    // the requirejs mixin map can keep a slot for future toolbar UX.
    return function (target) { return target; };
});
