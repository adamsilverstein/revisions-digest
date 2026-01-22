/**
 * Revisions Digest Dashboard Widget JavaScript
 *
 * @package
 */

(function () {
	'use strict';

	const STORAGE_KEY = 'revisions_digest_period';
	let widget = null;
	let resultsContainer = null;
	let loadingContainer = null;
	let errorContainer = null;
	let periodButtons = null;

	/**
	 * Initialize the widget.
	 */
	function init() {
		widget = document.querySelector('.revisions-digest-widget');
		if (!widget) {
			return;
		}

		resultsContainer = widget.querySelector('.revisions-digest-results');
		loadingContainer = widget.querySelector('.revisions-digest-loading');
		errorContainer = widget.querySelector('.revisions-digest-error');
		periodButtons = widget.querySelectorAll('.revisions-digest-period-btn');

		// Set up event listeners for period buttons.
		periodButtons.forEach(function (button) {
			button.addEventListener('click', onPeriodClick);
		});

		// Restore saved period preference from sessionStorage.
		const savedPeriod = sessionStorage.getItem(STORAGE_KEY);
		if (savedPeriod) {
			const savedButton = widget.querySelector(
				'.revisions-digest-period-btn[data-period="' +
					savedPeriod +
					'"]'
			);
			if (savedButton && !savedButton.classList.contains('active')) {
				setActivePeriod(savedPeriod);
				fetchDigest(savedPeriod);
			}
		}
	}

	/**
	 * Handle period button click.
	 *
	 * @param {Event} event Click event.
	 */
	function onPeriodClick(event) {
		const button = event.currentTarget;

		if (button.classList.contains('active')) {
			return;
		}

		const period = button.getAttribute('data-period');
		setActivePeriod(period);
		sessionStorage.setItem(STORAGE_KEY, period);
		fetchDigest(period);
	}

	/**
	 * Set the active period button.
	 *
	 * @param {string} period The period value.
	 */
	function setActivePeriod(period) {
		periodButtons.forEach(function (btn) {
			btn.classList.remove('active');
		});

		const activeButton = widget.querySelector(
			'.revisions-digest-period-btn[data-period="' + period + '"]'
		);
		if (activeButton) {
			activeButton.classList.add('active');
		}
	}

	/**
	 * Fetch digest data from REST API.
	 *
	 * @param {string} period The period to fetch.
	 */
	function fetchDigest(period) {
		showLoading();

		wp.apiFetch({
			path:
				'revisions-digest/v1/digest?period=' +
				encodeURIComponent(period),
		})
			.then(function (response) {
				hideLoading();
				renderResults(response);
			})
			.catch(function (error) {
				hideLoading();
				showError(
					error.message || 'An error occurred while fetching data.'
				);
			});
	}

	/**
	 * Show loading state.
	 */
	function showLoading() {
		loadingContainer.style.display = 'flex';
		resultsContainer.style.display = 'none';
		errorContainer.style.display = 'none';
	}

	/**
	 * Hide loading state.
	 */
	function hideLoading() {
		loadingContainer.style.display = 'none';
	}

	/**
	 * Show error message.
	 *
	 * @param {string} message Error message.
	 */
	function showError(message) {
		errorContainer.textContent = message;
		errorContainer.style.display = 'block';
		resultsContainer.style.display = 'none';
	}

	/**
	 * Render results from API response.
	 *
	 * @param {Object} response API response.
	 */
	function renderResults(response) {
		resultsContainer.style.display = 'block';
		errorContainer.style.display = 'none';

		if (!response.changes || response.changes.length === 0) {
			resultsContainer.innerHTML =
				'<p class="revisions-digest-empty">There have been no content changes in this period.</p>';
			return;
		}

		let html = '';

		response.changes.forEach(function (change) {
			html += '<div class="activity-block">';
			html += '<h3>';
			html +=
				'<a href="' +
				escapeHtml(change.post_url) +
				'">' +
				escapeHtml(change.post_title) +
				'</a>';
			if (change.edit_url) {
				html +=
					' <a href="' +
					escapeHtml(change.edit_url) +
					'" class="revisions-digest-edit-link">Edit</a>';
			}
			html += '</h3>';

			// Authors
			const authorNames = change.authors.map(function (author) {
				return author.display_name;
			});
			if (authorNames.length > 0) {
				html +=
					'<p>Changed by ' +
					escapeHtml(formatAuthorList(authorNames)) +
					'</p>';
			}

			// Diff
			html += '<table class="diff">' + change.rendered + '</table>';
			html += '</div>';
		});

		resultsContainer.innerHTML = html;
	}

	/**
	 * Format a list of author names.
	 *
	 * @param {Array} names Array of author names.
	 * @return {string} Formatted string.
	 */
	function formatAuthorList(names) {
		if (names.length === 0) {
			return '';
		}
		if (names.length === 1) {
			return names[0];
		}
		if (names.length === 2) {
			return names[0] + ' and ' + names[1];
		}
		return (
			names.slice(0, -1).join(', ') + ', and ' + names[names.length - 1]
		);
	}

	/**
	 * Escape HTML special characters.
	 *
	 * @param {string} text Text to escape.
	 * @return {string} Escaped text.
	 */
	function escapeHtml(text) {
		if (!text) {
			return '';
		}
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	// Initialize when DOM is ready.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
