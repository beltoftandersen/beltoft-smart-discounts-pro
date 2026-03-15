/**
 * Beltoft Smart Discounts Pro — CSV import logic.
 *
 * @package BeltoftSmartDiscountsPro
 */

/* global jQuery, bsdiscProImport */
(function ($) {
	'use strict';

	$(function () {
		var nonce = bsdiscProImport.nonce;
		var i18n  = bsdiscProImport.i18n;

		$('#bsdisc-pro-import-rules').on('click', function () {
			var file = $('#bsdisc-pro-import-file')[0].files[0];
			if (!file) {
				$('#bsdisc-pro-import-message').text(i18n.chooseFile).css('color', '#d63638');
				return;
			}

			var $btn = $(this).prop('disabled', true);
			var $msg = $('#bsdisc-pro-import-message');
			$msg.text(i18n.importing).css('color', '');

			var formData = new FormData();
			formData.append('action', 'bsdisc_pro_import_rules');
			formData.append('nonce', nonce);
			formData.append('import_file', file);

			$.ajax({
				url: bsdiscProImport.ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false
			}).done(function (response) {
				if (response.success) {
					$msg.text(response.data.message).css('color', '#00a32a');
				} else {
					$msg.text(response.data ? response.data.message : i18n.importFailed).css('color', '#d63638');
				}
				$btn.prop('disabled', false);
			}).fail(function () {
				$msg.text(i18n.requestFailed).css('color', '#d63638');
				$btn.prop('disabled', false);
			});
		});
	});
}(jQuery));
