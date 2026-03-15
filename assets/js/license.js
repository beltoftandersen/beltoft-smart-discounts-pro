/**
 * Beltoft Smart Discounts Pro — License activation/deactivation.
 *
 * @package BeltoftSmartDiscountsPro
 */

/* global jQuery, bsdiscProLicense */
(function ($) {
	'use strict';

	$(function () {
		var nonce = bsdiscProLicense.nonce;
		var url   = bsdiscProLicense.ajaxUrl;
		var i18n  = bsdiscProLicense.i18n;

		$('#bsdisc-pro-activate-license').on('click', function () {
			var key = $('#bsdisc-pro-license-key').val().trim();
			if (!key) return;

			var $btn = $(this).prop('disabled', true).text(i18n.activating);
			var $msg = $('#bsdisc-pro-license-message');

			$.post(url, {
				action: 'bsdisc_pro_activate_license',
				nonce: nonce,
				license_key: key
			}).done(function (response) {
				if (response.success) {
					$msg.text(response.data.message).css('color', '#00a32a');
					setTimeout(function () { location.reload(); }, 1000);
				} else {
					$msg.text(response.data.message).css('color', '#d63638');
					$btn.prop('disabled', false).text(i18n.activate);
				}
			}).fail(function (xhr) {
				$msg.text(i18n.requestFailed + ' (HTTP ' + xhr.status + ')').css('color', '#d63638');
				$btn.prop('disabled', false).text(i18n.activate);
			});
		});

		$('#bsdisc-pro-deactivate-license').on('click', function () {
			if (!confirm(i18n.confirmDeactivate)) return;

			var $btn = $(this).prop('disabled', true).text(i18n.deactivating);
			var $msg = $('#bsdisc-pro-license-message');

			$.post(url, {
				action: 'bsdisc_pro_deactivate_license',
				nonce: nonce
			}).done(function (response) {
				if (response.success) {
					$msg.text(response.data.message).css('color', '#00a32a');
					setTimeout(function () { location.reload(); }, 500);
				} else {
					$msg.text(response.data ? response.data.message : i18n.deactivationFailed).css('color', '#d63638');
					$btn.prop('disabled', false).text(i18n.deactivate);
				}
			}).fail(function (xhr) {
				$msg.text(i18n.requestFailed + ' (HTTP ' + xhr.status + ')').css('color', '#d63638');
				$btn.prop('disabled', false).text(i18n.deactivate);
			});
		});
	});
}(jQuery));
