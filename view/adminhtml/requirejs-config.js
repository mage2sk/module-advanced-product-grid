/**
 * Mixins on Magento UI Components that need to detect "unsaved edits in
 * the grid" and confirm before changing view state (paging, sorting,
 * filtering, mass actions, exports, bookmarks).
 */
var config = {
    config: {
        mixins: {
            'Magento_Ui/js/grid/paging/paging':       { 'Panth_AdvancedProductGrid/js/mixin/paging-mixin': true },
            'Magento_Ui/js/grid/search/search':       { 'Panth_AdvancedProductGrid/js/mixin/search-mixin': true },
            'Magento_Ui/js/grid/paging/sizes':        { 'Panth_AdvancedProductGrid/js/mixin/sizes-mixin': true },
            'Magento_Ui/js/grid/filters/filters':     { 'Panth_AdvancedProductGrid/js/mixin/filters-mixin': true },
            'Magento_Ui/js/grid/toolbar':             { 'Panth_AdvancedProductGrid/js/mixin/toolbar-mixin': true },
            'Magento_Ui/js/grid/controls/bookmarks/bookmarks': { 'Panth_AdvancedProductGrid/js/mixin/bookmarks-mixin': true },
            'Magento_Ui/js/grid/massactions':         { 'Panth_AdvancedProductGrid/js/mixin/massactions-mixin': true },
            'Magento_Ui/js/grid/tree-massactions':    { 'Panth_AdvancedProductGrid/js/mixin/tree-massactions-mixin': true },
            'Magento_Ui/js/grid/export':              { 'Panth_AdvancedProductGrid/js/mixin/export-mixin': true },
            'Magento_Ui/js/grid/columns/select':      { 'Panth_AdvancedProductGrid/js/mixin/select-column-mixin': true }
        }
    }
};
