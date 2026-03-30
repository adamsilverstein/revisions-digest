/**
 * Revisions Digest Subscription Management
 *
 * @package
 */

/* global revisionsDigestSubscriptions */

(function () {
	'use strict';

	const nonce = revisionsDigestSubscriptions.nonce;
	const ajaxurl = revisionsDigestSubscriptions.ajaxurl;
	const frequencyLabels = revisionsDigestSubscriptions.frequencyLabels;
	const i18n = revisionsDigestSubscriptions.i18n;

	function showMessage(container, message, type) {
		let msgEl = container.querySelector('.revisions-digest-message');
		if (!msgEl) {
			msgEl = document.createElement('div');
			msgEl.className = 'revisions-digest-message';
			container.appendChild(msgEl);
		}
		msgEl.textContent = message;
		msgEl.className = 'revisions-digest-message ' + type;
		msgEl.style.display = 'block';
		setTimeout(function () {
			msgEl.style.display = 'none';
		}, 5000);
	}

	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	// Add subscription form.
	const addForm = document.getElementById(
		'revisions-digest-add-subscription'
	);
	if (addForm) {
		addForm.addEventListener('submit', function (e) {
			e.preventDefault();
			const email = document.getElementById(
				'revisions-digest-email'
			).value;
			const frequency = document.getElementById(
				'revisions-digest-frequency'
			).value;

			const formData = new FormData();
			formData.append('action', 'revisions_digest_add_subscription');
			formData.append('nonce', nonce);
			formData.append('email', email);
			formData.append('frequency', frequency);

			fetch(ajaxurl, {
				method: 'POST',
				body: formData,
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (data) {
					if (data.success) {
						showMessage(addForm, data.data.message, 'success');
						let list = document.getElementById(
							'revisions-digest-subscription-list'
						);
						if (!list) {
							const h4 = document.createElement('h4');
							h4.textContent = i18n.currentSubscriptions;
							addForm.parentNode.appendChild(h4);
							list = document.createElement('ul');
							list.id = 'revisions-digest-subscription-list';
							addForm.parentNode.appendChild(list);
						}
						list.classList.remove('hidden');
						const li = document.createElement('li');
						li.setAttribute('data-id', data.data.id);
						li.innerHTML =
							'<span class="subscription-email">' +
							escapeHtml(data.data.email) +
							'</span> ' +
							'<span class="subscription-frequency">(' +
							escapeHtml(
								frequencyLabels[data.data.frequency] ||
									data.data.frequency
							) +
							')</span> ' +
							'<span class="subscription-actions">' +
							'<a href="#" class="edit-subscription" data-id="' +
							escapeHtml(data.data.id) +
							'" data-email="' +
							escapeHtml(data.data.email) +
							'" data-frequency="' +
							escapeHtml(data.data.frequency) +
							'">' +
							escapeHtml(i18n.edit) +
							'</a> | ' +
							'<a href="#" class="delete-subscription" data-id="' +
							escapeHtml(data.data.id) +
							'">' +
							escapeHtml(i18n.deleteLabel) +
							'</a> | ' +
							'<a href="#" class="test-subscription" data-id="' +
							escapeHtml(data.data.id) +
							'">' +
							escapeHtml(i18n.sendTest || 'Send Test') +
							'</a>' +
							'</span>';
						list.appendChild(li);
						bindDeleteHandlers();
						bindEditHandlers();
						bindTestHandlers();
					} else {
						showMessage(addForm, data.data.message, 'error');
					}
				})
				.catch(function () {
					showMessage(addForm, i18n.errorOccurred, 'error');
				});
		});
	}

	// Delete handlers.
	function bindDeleteHandlers() {
		const deleteLinks = document.querySelectorAll('.delete-subscription');
		deleteLinks.forEach(function (link) {
			link.onclick = function (e) {
				e.preventDefault();
				// eslint-disable-next-line no-alert
				if (!confirm(i18n.confirmDelete)) {
					return;
				}
				const id = this.getAttribute('data-id');
				const li = this.closest('li');

				const formData = new FormData();
				formData.append(
					'action',
					'revisions_digest_delete_subscription'
				);
				formData.append('nonce', nonce);
				formData.append('id', id);

				fetch(ajaxurl, {
					method: 'POST',
					body: formData,
				})
					.then(function (response) {
						return response.json();
					})
					.then(function (data) {
						if (data.success) {
							li.remove();
						} else {
							// eslint-disable-next-line no-alert
							alert(data.data.message);
						}
					})
					.catch(function () {
						// eslint-disable-next-line no-alert
						alert(i18n.errorOccurred);
					});
			};
		});
	}

	// Edit handlers.
	function bindEditHandlers() {
		const editLinks = document.querySelectorAll('.edit-subscription');
		editLinks.forEach(function (link) {
			link.onclick = function (e) {
				e.preventDefault();
				const id = this.getAttribute('data-id');
				const email = this.getAttribute('data-email');
				const frequency = this.getAttribute('data-frequency');

				document.getElementById('edit-subscription-id').value = id;
				document.getElementById('edit-subscription-email').value =
					email;
				document.getElementById('edit-subscription-frequency').value =
					frequency;

				const modal = document.getElementById(
					'revisions-digest-edit-modal'
				);
				modal.style.display = 'block';
			};
		});
	}

	// Edit form submission.
	const editForm = document.getElementById('revisions-digest-edit-form');
	if (editForm) {
		editForm.addEventListener('submit', function (e) {
			e.preventDefault();
			const id = document.getElementById('edit-subscription-id').value;
			const email = document.getElementById(
				'edit-subscription-email'
			).value;
			const frequency = document.getElementById(
				'edit-subscription-frequency'
			).value;

			const formData = new FormData();
			formData.append('action', 'revisions_digest_update_subscription');
			formData.append('nonce', nonce);
			formData.append('id', id);
			formData.append('email', email);
			formData.append('frequency', frequency);

			fetch(ajaxurl, {
				method: 'POST',
				body: formData,
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (data) {
					if (data.success) {
						const li = document.querySelector(
							'li[data-id="' + id + '"]'
						);
						if (li) {
							li.querySelector(
								'.subscription-email'
							).textContent = email;
							li.querySelector(
								'.subscription-frequency'
							).textContent =
								'(' +
								(frequencyLabels[frequency] || frequency) +
								')';
							li.querySelector('.edit-subscription').setAttribute(
								'data-email',
								email
							);
							li.querySelector('.edit-subscription').setAttribute(
								'data-frequency',
								frequency
							);
						}
						document.getElementById(
							'revisions-digest-edit-modal'
						).style.display = 'none';
					} else {
						// eslint-disable-next-line no-alert
						alert(data.data.message);
					}
				})
				.catch(function () {
					// eslint-disable-next-line no-alert
					alert(i18n.errorOccurred);
				});
		});
	}

	// Cancel edit.
	const cancelBtn = document.querySelector('.cancel-edit');
	if (cancelBtn) {
		cancelBtn.addEventListener('click', function () {
			document.getElementById(
				'revisions-digest-edit-modal'
			).style.display = 'none';
		});
	}

	// Send test email handlers.
	function bindTestHandlers() {
		const testLinks = document.querySelectorAll('.test-subscription');
		testLinks.forEach(function (link) {
			link.onclick = function (e) {
				e.preventDefault();
				const id = this.getAttribute('data-id');
				const btn = this;
				btn.textContent = i18n.sending || 'Sending...';

				const formData = new FormData();
				formData.append('action', 'revisions_digest_send_test_email');
				formData.append('nonce', nonce);
				formData.append('id', id);

				fetch(ajaxurl, {
					method: 'POST',
					body: formData,
				})
					.then(function (response) {
						return response.json();
					})
					.then(function (data) {
						btn.textContent = i18n.sendTest || 'Send Test';
						if (data.success) {
							btn.textContent = i18n.sent || 'Sent!';
							setTimeout(function () {
								btn.textContent = i18n.sendTest || 'Send Test';
							}, 3000);
						} else {
							// eslint-disable-next-line no-alert
							alert(data.data.message);
						}
					})
					.catch(function () {
						btn.textContent = i18n.sendTest || 'Send Test';
						// eslint-disable-next-line no-alert
						alert(i18n.errorOccurred);
					});
			};
		});
	}

	// Initial binding.
	bindDeleteHandlers();
	bindEditHandlers();
	bindTestHandlers();
})();
