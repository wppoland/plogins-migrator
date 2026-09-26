/**
 * Scheduled Backups screen: show only the selected destination's fields.
 *
 * The fields are rendered by the destination registry, so this never names a
 * destination; it matches whatever the select offers against the blocks that
 * were printed alongside it.
 */
(function () {
	'use strict';

	var select = document.querySelector('[data-migrator-offsite-type]');

	if (!select) {
		return;
	}

	var blocks = Array.prototype.slice.call(
		document.querySelectorAll('[data-migrator-dest]')
	);

	function sync() {
		blocks.forEach(function (block) {
			block.hidden = block.getAttribute('data-migrator-dest') !== select.value;
		});
	}

	select.addEventListener('change', sync);
	sync();
})();
