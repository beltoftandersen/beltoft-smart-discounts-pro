/**
 * Beltoft Smart Discounts Pro — Frontend scripts.
 *
 * @package BeltoftSmartDiscountsPro
 */

/* global jQuery */
(function ($) {
	'use strict';

	/**
	 * Initialize countdown timers.
	 */
	function initCountdowns() {
		$( '.bsdisc-pro-countdown' ).each( function () {
			var $el     = $( this );
			var endTs   = parseInt( $el.data( 'end-ts' ), 10 ) || 0;
			var endDate = endTs > 0 ? endTs * 1000 : new Date( $el.data( 'end' ) ).getTime();

			if ( isNaN( endDate ) ) {
				return;
			}

			var startDiff  = endDate - Date.now();
			var $days      = $el.find( '.bsdisc-pro-countdown__days' );
			var $hours     = $el.find( '.bsdisc-pro-countdown__hours' );
			var $minutes   = $el.find( '.bsdisc-pro-countdown__minutes' );
			var $seconds   = $el.find( '.bsdisc-pro-countdown__seconds' );
			var $progress  = $el.find( '.bsdisc-pro-countdown__progress' );

			function updateTimer() {
				var now  = Date.now();
				var diff = endDate - now;

				if ( diff <= 0 ) {
					$days.text( '0' );
					$hours.text( '0' );
					$minutes.text( '0' );
					$seconds.text( '0' );
					$progress.css( 'width', '0%' );
					return;
				}

				var d = Math.floor( diff / 86400000 );
				var h = Math.floor( ( diff % 86400000 ) / 3600000 );
				var m = Math.floor( ( diff % 3600000 ) / 60000 );
				var s = Math.floor( ( diff % 60000 ) / 1000 );

				$days.text( d );
				$hours.text( h );
				$minutes.text( m );
				$seconds.text( s );

				/* Update progress bar */
				if ( startDiff > 0 ) {
					var pct = Math.max( 0, ( diff / startDiff ) * 100 );
					$progress.css( 'width', pct.toFixed( 1 ) + '%' );
				}
			}

			updateTimer();
			setInterval( updateTimer, 1000 );
		} );
	}

	/* ── DOM ready ──────────────────────────── */
	$( function () {
		initCountdowns();
	} );
}( jQuery ));
