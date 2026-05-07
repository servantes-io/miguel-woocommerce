/* global jQuery */
(function ($) {
	'use strict';

	$(document).ready(function () {
		var allowOtherEnvs = localStorage.getItem('MIGUEL_ALLOW_OTHER_ENVIRONMENTS') === 'true';
		var $serverRow = $('#miguel_api_server').closest('tr');

		if (!allowOtherEnvs) {
			$serverRow.hide();
		}
	});
})(jQuery);
