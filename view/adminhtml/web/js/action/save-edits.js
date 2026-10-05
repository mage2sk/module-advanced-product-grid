/**
 * POSTs queued cell edits to the inline-edit endpoint and resolves with
 * the server response. Caller is responsible for refreshing the grid
 * data source after the resolve.
 */
define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function ($, alert, $t) {
    'use strict';

    return function (url, items, storeId) {
        var formData = new FormData();
        formData.append('form_key', window.FORM_KEY || '');
        formData.append('store_id', String(storeId || 0));

        Object.keys(items).forEach(function (productId) {
            Object.keys(items[productId]).forEach(function (code) {
                var value = items[productId][code];
                if (Array.isArray(value)) {
                    value.forEach(function (v) {
                        formData.append('items[' + productId + '][' + code + '][]', v);
                    });
                } else if (typeof value === 'object' && value !== null) {
                    formData.append('items[' + productId + '][' + code + ']', JSON.stringify(value));
                } else {
                    formData.append('items[' + productId + '][' + code + ']', value === null ? '' : String(value));
                }
            });
        });

        return $.ajax({
            url: url,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false
        }).then(
            function (response) { return response || { success: false }; },
            function (xhr) {
                alert({ title: $t('Save failed'), content: $t('Server error: ') + (xhr.statusText || '') });
                return { success: false, errors: {}, messages: [] };
            }
        );
    };
});
