/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

/**
 * Various JavaScript functions for the Install Tool
 */

/**
 * Handle core update
 */
var TYPO3 = {};
TYPO3.Install = {};

TYPO3.Install.Severity = {
	loading: -3,
	notice: -2,
	info: -1,
	ok: 0,
	warning: 1,
	error: 2
};

TYPO3.Install.Severity.getCssClass = function(severity) {
	var severityClass;
	switch (severity) {
		case TYPO3.Install.Severity.loading:
			severityClass = 'notice alert-loading';
			break;
		case TYPO3.Install.Severity.notice:
			severityClass = 'notice';
			break;
		case TYPO3.Install.Severity.ok:
			severityClass = 'success';
			break;
		case TYPO3.Install.Severity.warning:
			severityClass = 'warning';
			break;
		case TYPO3.Install.Severity.error:
			severityClass = 'danger';
			break;
		case TYPO3.Install.Severity.info:
		default:
			severityClass = 'info';
	}
	return severityClass;
};

TYPO3.Install.FlashMessage = {
	template: $('<div class="t3js-message typo3-message alert"><h4></h4><p class="messageText"></p></div>'),
	render: function(severity, title, message) {
		var flashMessage = this.template.clone();
		flashMessage.addClass('alert-' + TYPO3.Install.Severity.getCssClass(severity));
		if (title) {
			flashMessage.find('h4').html(title);
		}
		if (message) {
			flashMessage.find('.messageText').html(message);
		} else {
			flashMessage.find('.messageText').remove();
		}
		return flashMessage;
	}
};

TYPO3.Install.Cache = {
	/**
	 * output DOM Container
	 */
	outputContainer: {},

	/**
	 * Clone of the DOM object that contains the submit button
	 */
	submitButton: {},

	/**
	 * Default output messages
	 */
	outputMessages: {
		clearAllCache: {
			fatalTitle: 'Something went wrong',
			fatalMessage: '',
			loadingTitle: 'Loading…',
			loadingMessage: ''
		}
	},

	/**
	* Fetching the templates out of the DOM
	*
	* @param cacheCheckContainer DOM element id with all needed HTML in it
	* @return boolean DOM container could be found and initialization finished
	*/
	initialize: function(cacheCheckContainer) {
		var success = false;
		this.outputContainer[cacheCheckContainer] = $('#' + cacheCheckContainer);

		if (this.outputContainer[cacheCheckContainer]) {
			// submit button: save and delete
			if(!this.submitButton[cacheCheckContainer]) {
				var submitButton = this.outputContainer[cacheCheckContainer].find('button[type="submit"]');
				this.submitButton[cacheCheckContainer] = submitButton.clone();
			}

			// clear all messages from the run before
			this.outputContainer[cacheCheckContainer].find('.typo3-message:visible ').remove();

			success = true;
		}
		return success;
	},

	/**
	 * Ajax call to clear all caches.
	 */
	clearCache: function() {
		$.ajax({
			url: location.href + '&install[controller]=ajax&install[action]=clearCache',
			cache: false
		});
	},
	clearAllCache: function(actionName) {
		var self = this;
		var url = location.href + '&install[controller]=ajax&install[action]=' + actionName;
		if (location.hash) {
			url = url.replace(location.hash, "");
		}
		var isInitialized = self.initialize(actionName);
		if (isInitialized) {
			self.addMessage(
				TYPO3.Install.Severity.loading,
				self.outputMessages[actionName].loadingTitle,
				self.outputMessages[actionName].loadingMessage,
				actionName
			);
			$.ajax({
				url: url,
				cache: false,
				success: function(data) {
					if (data.success === true && Array.isArray(data.status)) {
						if (data.status.length > 0) {
							self.outputContainer[actionName].find('.alert-loading').hide();
							data.status.forEach((function (element) {
								//noinspection JSUnresolvedVariable
								self.addMessage(
									element.severity,
									element.title,
									element.message,
									actionName
								);
							}));
						} else {
							self.outputContainer[actionName].find('.alert-loading').hide();
							self.addMessage(
								TYPO3.Install.Severity.ok,
								self.outputMessages[actionName].successTitle,
								self.outputMessages[actionName].successMessage,
								actionName
							);
						}
					} else if (data === 'unauthorized') {
						location.reload();
					}
				}
			});
		}
	},
	/**
	 * Move the submit button to the end of the box
	 *
	 * @param cacheCheckContainer DOM container name
	 */
	moveSubmitButtonFurtherDown: function(cacheCheckContainer) {
		// first remove the currently visible button
		this.outputContainer[cacheCheckContainer].find('button[type="submit"]').remove();
		// then append the cloned template to the end
		this.outputContainer[cacheCheckContainer].append(this.submitButton[cacheCheckContainer]);
	},

	/**
	 * Show a status message
	 *
	 * @param severity
	 * @param title
	 * @param message
	 * @param cacheCheckContainer DOM container name
	 */
	addMessage: function(severity, title, message, cacheCheckContainer) {
		var domMessage = TYPO3.Install.FlashMessage.render(severity, title, message);
		this.outputContainer[cacheCheckContainer].append(domMessage);
		this.moveSubmitButtonFurtherDown(cacheCheckContainer);
	}
};

TYPO3.Install.Scrolling = {
	isScrolledIntoView: function(elem) {
		var $window = $(window);
		var docViewTop = $window.scrollTop();
		var docViewBottom = docViewTop + $window.height();
		var $elem = $(elem);
		var elemTop = $elem.offset().top;
		var elemBottom = elemTop + $elem.height();

		return ((elemBottom <= docViewBottom) && (elemTop >= docViewTop));
	},
	handleButtonScrolling: function() {
		var $fixedFooterHandler = $('#fixed-footer-handler');
		if ($fixedFooterHandler.length > 0) {
			var $fixedFooter = $('#fixed-footer');
			if (!this.isScrolledIntoView($fixedFooterHandler)) {
				$fixedFooter.addClass('fixed');
				$fixedFooter.width($('.content-area').width());
			} else {
				$fixedFooter.removeClass('fixed');
			}
		}
	}
};

TYPO3.Install.ExtensionChecker = {
	/**
	 * Call checkExtensionsCompatibility recursively on error
	 * so we can find all incompatible extensions
	 */
	handleCheckExtensionsError: function() {
		this.checkExtensionsCompatibility(false);
	},
	/**
	 * Send an ajax request to uninstall an extension (or multiple extensions)
	 *
	 * @param extension string of extension(s) - may be comma separated
	 */
	uninstallExtension: function(extension) {
		var self = this;
		var url = location.href + '&install[controller]=ajax&install[action]=uninstallExtension' +
			'&install[uninstallExtension][extensions]=' + extension;
		var $container = $('#checkExtensions');
		$.ajax({
			url: url,
			cache: false,
			success: function(data) {
				if (data === 'OK') {
					self.checkExtensionsCompatibility(true);
				} else {
					if (data === 'unauthorized') {
						location.reload();
					}
					// workaround for xdebug returning 200 OK on fatal errors
					if (data.substring(data.length - 2) === 'OK') {
						self.checkExtensionsCompatibility(true);
					} else {
						$('.alert-loading', $container).hide();
						var domMessage = TYPO3.Install.FlashMessage.render(
							TYPO3.Install.Severity.error,
							'Something went wrong. Check failed.',
							'Message: ' + data
						);
						$container.append(domMessage);
					}
				}
			},
			error: function(data) {
				self.handleCheckExtensionsError(data);
			}
		});
	},
	/**
	 * Handles result of extension compatibility check.
	 * Displays uninstall buttons for non-compatible extensions.
	 */
	handleCheckExtensionsSuccess: function() {
		var self = this;
		var $checkExtensions = $('#checkExtensions');

		$.ajax({
			url: $checkExtensions.data('protocolurl'),
			cache: false,
			success: function(data) {
				if (data) {
					$('.alert-danger .messageText', $checkExtensions).html(
						'The following extensions are not compatible. Please uninstall them and try again. '
					);
					var extensions = data.split(',');
					var unloadButtonWrapper = $('<fieldset class="t3-install-form-submit"></fieldset>');
					for (var i = 0; i < extensions.length; i++) {
						var extension = extensions[i];
						var unloadButton = $('<button />', {
							text: 'Uninstall ' + $.trim(extension),
							'class': 't3-js-uninstallSingle',
							'data-extension': $.trim(extension)
						});
						var fullButton = unloadButtonWrapper.append(unloadButton);
						$('.alert-danger .messageText', $checkExtensions).append(fullButton);
					}
					if (extensions.length) {
						$(document).on('click', 't3-js-uninstallSingle', function(e) {
							self.uninstallExtension($(this).data('extension'));
							e.preventDefault();
							return false;
						});
					}
					var unloadAllButton = $('<button />', {
						text: 'Uninstall all incompatible extensions: ' + data,
						click: function(e) {
							$('.alert-loading', $checkExtensions).show();
							self.uninstallExtension(data);
							e.preventDefault();
							return false;
						}
					});
					unloadButtonWrapper.append('<hr />');
					var fullUnloadAllButton = unloadButtonWrapper.append(unloadAllButton);
					$('.alert-danger .messageText', $checkExtensions).append(fullUnloadAllButton);

					$('.alert-loading', $checkExtensions).hide();
					$('button', $checkExtensions).show();
					$('.alert-danger', $checkExtensions).show();
				} else {
					$('.t3js-message', $checkExtensions).hide();
					$('.alert-success', $checkExtensions).show();
				}
			},
			error: function() {
				$('.t3js-message', $checkExtensions).hide();
				$('.alert-success', $checkExtensions).show();
			}
		});
		$.getJSON(
			$checkExtensions.data('errorprotocolurl'),
			function(data) {
				$.each(data, function(i, error) {
					var messageToDisplay = error.message + ' in ' + error.file + ' on line ' + error.line;
					var domMessage = TYPO3.Install.FlashMessage.render(TYPO3.Install.Severity.warning, error.type, messageToDisplay);
					$checkExtensions.find('.t3js-message.alert-danger').before(domMessage);
				});
			}
		);
	},
	/**
	 * Checks extension compatibility by trying to load ext_tables and ext_localconf via ajax.
	 *
	 * @param force
	 */
	checkExtensionsCompatibility: function(force) {
		var self = this;
		var url = location.href + '&install[controller]=ajax&install[action]=extensionCompatibilityTester';
		if (force) {
			TYPO3.Install.Cache.clearCache();
			url += '&install[extensionCompatibilityTester][forceCheck]=1';
		} else {
			url += '&install[extensionCompatibilityTester][forceCheck]=0';
		}
		$.ajax({
			url: url,
			cache: false,
			success: function(data) {
				if (data === 'OK') {
					self.handleCheckExtensionsSuccess();
				} else {
					if (data === 'unauthorized') {
						location.reload();
					}
					// workaround for xdebug returning 200 OK on fatal errors
					if (data.substring(data.length - 2) === 'OK') {
						self.handleCheckExtensionsSuccess();
					} else {
						self.handleCheckExtensionsError();
					}
				}
			},
			error: function() {
				self.handleCheckExtensionsError();
			}
		});
	}
};

TYPO3.Install.TcaIntegrityChecker = {

	/**
	 * Default output messages
	 */
	outputMessages: {
		tcaMigrationsCheck: {
			fatalTitle: 'Something went wrong',
			fatalMessage: 'Use "Check for broken extensions!"',
			loadingTitle: 'Loading…',
			loadingMessage: '',
			successTitle: 'No TCA migrations need to be applied',
			successMessage: 'Your TCA looks good.',
			warningTitle: 'TCA migrations need to be applied',
			warningMessage: 'Check the following list and apply needed changes.'
		},
		tcaExtTablesCheck: {
			fatalTitle: 'Something went wrong',
			fatalMessage: 'Use "Check for broken extensions!"',
			loadingTitle: 'Loading…',
			loadingMessage: '',
			successTitle: 'No TCA changes in ext_tables.php files. Good job!',
			successMessage: '',
			warningTitle: 'Extensions change TCA in ext_tables.php',
			warningMessage: 'Check for ExtensionManagementUtility and $GLOBALS["TCA"].'
		}
	},

	/**
	 * output DOM Container
	 */
	outputContainer: {},

	/**
	 * Clone of the DOM object that contains the submit button
	 */
	submitButton: {},

	/**
	 * Fetching the templates out of the DOM
	 *
	 * @param tcaIntegrityCheckContainer DOM element id with all needed HTML in it
	 * @return boolean DOM container could be found and initialization finished
	 */
	initialize: function(tcaIntegrityCheckContainer) {
		var success = false;
		this.outputContainer[tcaIntegrityCheckContainer] = $('#' + tcaIntegrityCheckContainer);

		if (this.outputContainer[tcaIntegrityCheckContainer]) {
			// submit button: save and delete
			if(!this.submitButton[tcaIntegrityCheckContainer]) {
				var submitButton = this.outputContainer[tcaIntegrityCheckContainer].find('button[type="submit"]');
				this.submitButton[tcaIntegrityCheckContainer] = submitButton.clone();
				// submitButton.remove();
			}

			// clear all messages from the run before
			this.outputContainer[tcaIntegrityCheckContainer].find('.typo3-message:visible ').remove();

			success = true;
		}
		return success;
	},

	checkTcaIntegrity: function(actionName) {
		var self = this;
		var url = location.href + '&install[controller]=ajax&install[action]=' + actionName;
		if (location.hash) {
			url = url.replace(location.hash, "");
		}

		var isInitialized = self.initialize(actionName);
		if(isInitialized) {
			self.addMessage(
				TYPO3.Install.Severity.loading,
				self.outputMessages[actionName].loadingTitle,
				self.outputMessages[actionName].loadingMessage,
				actionName
			);

			$.ajax({
				url: url,
				cache: false,
				success: function(data) {

					if(data.success === true && Array.isArray(data.status)) {
						if(data.status.length > 0) {
							self.outputContainer[actionName].find('.alert-loading').hide();
							self.addMessage(
								TYPO3.Install.Severity.warning,
								self.outputMessages[actionName].warningTitle,
								self.outputMessages[actionName].warningMessage,
								actionName
							);
							data.status.forEach((function (element) {
								//noinspection JSUnresolvedVariable
								self.addMessage(
									element.severity,
									element.title,
									element.message,
									actionName
								);
							}));
						} else {
							// nothing to complain, everything fine
							self.outputContainer[actionName].find('.alert-loading').hide();
							self.addMessage(
								TYPO3.Install.Severity.ok,
								self.outputMessages[actionName].successTitle,
								self.outputMessages[actionName].successMessage,
								actionName
							);
						}
					} else if (data === 'unauthorized') {
						location.reload();
					}
				},
				error: function() {
					self.outputContainer[actionName].find('.alert-loading').hide();
					self.addMessage(
						TYPO3.Install.Severity.error,
						self.outputMessages[actionName].fatalTitle,
						self.outputMessages[actionName].fatalMessage,
						actionName
					);
				}
			});
		}
	},

	/**
	 * Move the submit button to the end of the box
	 *
	 * @param tcaIntegrityCheckContainer DOM container name
	 */
	moveSubmitButtonFurtherDown: function(tcaIntegrityCheckContainer) {
		// first remove the currently visible button
		this.outputContainer[tcaIntegrityCheckContainer].find('button[type="submit"]').remove();
		// then append the cloned template to the end
		this.outputContainer[tcaIntegrityCheckContainer].append(this.submitButton[tcaIntegrityCheckContainer]);
	},

	/**
	 * Show a status message
	 *
	 * @param severity
	 * @param title
	 * @param message
	 * @param tcaIntegrityCheckContainer DOM container name
	 */
	addMessage: function(severity, title, message, tcaIntegrityCheckContainer) {
		var domMessage = TYPO3.Install.FlashMessage.render(severity, title, message);
		this.outputContainer[tcaIntegrityCheckContainer].append(domMessage);
		this.moveSubmitButtonFurtherDown(tcaIntegrityCheckContainer);
	}

};

TYPO3.Install.Status = {
	getFolderStatus: function() {
		var url = location.href + '&install[controller]=ajax&install[action]=folderStatus';
		$.ajax({
			url: url,
			cache: false,
			success: function(data) {
				if (data > 0) {
					$('.t3js-install-menu-folderStructure').append('<span class="badge badge-danger">' + data + '</span>');
				}
			}
		});
	},
	getEnvironmentStatus: function() {
		var url = location.href + '&install[controller]=ajax&install[action]=environmentStatus';
		$.ajax({
			url: url,
			cache: false,
			success: function(data) {
				if (data > 0) {
					$('.t3js-install-menu-systemEnvironment').append('<span class="badge badge-danger">' + data + '</span>');
				}
			}
		});
	}
};

TYPO3.Install.coreUpdate = {
	/**
	 * The action queue defines what actions are called in which order
	 */
	actionQueue: {
		coreUpdateUpdateVersionMatrix: {
			loadingMessage: 'Fetching list of released versions from typo3.org',
			finishMessage: 'Fetched list of released versions',
			nextActionName: 'coreUpdateIsUpdateAvailable'
		},
		coreUpdateIsUpdateAvailable: {
			loadingMessage: 'Checking for possible regular or security update',
			finishMessage: undefined,
			nextActionName: undefined
		},
		coreUpdateCheckPreConditions: {
			loadingMessage: 'Checking if update is possible',
			finishMessage: 'System can be updated',
			nextActionName: 'coreUpdateDownload'
		},
		coreUpdateDownload: {
			loadingMessage: 'Downloading new core',
			finishMessage: undefined,
			nextActionName: 'coreUpdateVerifyChecksum'
		},
		coreUpdateVerifyChecksum: {
			loadingMessage: 'Verifying checksum of downloaded core',
			finishMessage: undefined,
			nextActionName: 'coreUpdateUnpack'
		},
		coreUpdateUnpack: {
			loadingMessage: 'Unpacking core',
			finishMessage: undefined,
			nextActionName: 'coreUpdateMove'
		},
		coreUpdateMove: {
			loadingMessage: 'Moving core',
			finishMessage: undefined,
			nextActionName: 'clearCache'
		},
		clearCache: {
			loadingMessage: 'Clearing caches',
			finishMessage: 'Caches cleared',
			nextActionName: 'coreUpdateActivate'
		},
		coreUpdateActivate: {
			loadingMessage: 'Activating core',
			finishMessage: 'Core updated - please reload your browser',
			nextActionName: undefined
		}
	},

	/**
	 * Clone of a DOM object acts as button template
	 */
	buttonTemplate: null,

	/**
	 * Fetching the templates out of the DOM
	 */
	initialize: function() {
		var buttonTemplateSection = $('#buttonTemplate');
		this.buttonTemplate = buttonTemplateSection.children().clone();
	},

	/**
	 * Public method checkForUpdate
	 */
	checkForUpdate: function() {
		this.callAction('coreUpdateUpdateVersionMatrix');
	},

	/**
	 * Public method updateDevelopment
	 */
	updateDevelopment: function() {
		this.update('development');
	},

	/**
	 * Public method updateRegular
	 */
	updateRegular: function() {
		this.update('regular');
	},

	/**
	 * Execute core update.
	 *
	 * @param type Either 'development' or 'regular'
	 */
	update: function(type) {
		if (type !== "development") {
			type = 'regular';
		}
		this.callAction('coreUpdateCheckPreConditions', type);
	},

	/**
	 * Generic method to call actions from the queue
	 *
	 * @param actionName Name of the action to be called
	 * @param type Update type (optional)
	 */
	callAction: function(actionName, type) {
		var self = this;
		var data = {
			install: {
				controller: 'ajax',
				action: actionName
			}
		};
		if (type !== undefined) {
			data.install["type"] = type;
		}
		this.addLoadingMessage(this.actionQueue[actionName].loadingMessage);
		$.ajax({
			url: location.href,
			data: data,
			cache: false,
			success: function(result) {
				var canContinue = self.handleResult(result, self.actionQueue[actionName].finishMessage);
				if (canContinue === true && (self.actionQueue[actionName].nextActionName !== undefined)) {
					self.callAction(self.actionQueue[actionName].nextActionName, type);
				}
			},
			error: function(result) {
				self.handleResult(result);
			}
		});
	},

	/**
	 * Handle ajax result of core update step.
	 *
	 * @param data
	 * @param successMessage Optional success message
	 */
	handleResult: function(data, successMessage) {
		var canContinue = false;
		this.removeLoadingMessage();
		if (data.success === true) {
			canContinue = true;
			if (data.status && typeof(data.status) === 'object') {
				this.showStatusMessages(data.status);
			}
			if (data.action && typeof(data.action) === 'object') {
				this.showActionButton(data.action);
			}
			if (successMessage) {
				this.addMessage(TYPO3.Install.Severity.ok, successMessage);
			}
		} else {
			// Handle clearcache until it uses the new view object
			if (data === "OK") {
				canContinue = true;
				if (successMessage) {
					this.addMessage(TYPO3.Install.Severity.ok, successMessage);
				}
			} else {
				canContinue = false;
				if (data.status && typeof(data.status) === 'object') {
					this.showStatusMessages(data.status);
				} else {
					this.addMessage(TYPO3.Install.Severity.error, 'General error');
				}
			}
		}
		return canContinue;
	},

	/**
	 * Add a loading message with some text.
	 *
	 * @param messageTitle
	 */
	addLoadingMessage: function(messageTitle) {
		var domMessage = TYPO3.Install.FlashMessage.render(TYPO3.Install.Severity.loading, messageTitle);
		$('#coreUpdate').append(domMessage);
	},

	/**
	 * Remove an enabled loading message
	 */
	removeLoadingMessage: function() {
		$('#coreUpdate').find('.alert-loading').remove();
	},

	/**
	 * Show a list of status messages
	 *
	 * @param messages
	 */
	showStatusMessages: function(messages) {
		var self = this;
		$.each(messages, function(index, element) {
			var title = false;
			var message = false;
			var severity = element.severity;
			if (element.title) {
				title = element.title;
			}
			if (element.message) {
				message = element.message;
			}
			self.addMessage(severity, title, message);
		});
	},

	/**
	 * Show an action button
	 *
	 * @param button
	 */
	showActionButton: function(button) {
		var title = false;
		var action = false;
		if (button.title) {
			title = button.title;
		}
		if (button.action) {
			action = button.action;
		}
		var domButton = this.buttonTemplate;
		if (action) {
			domButton.find('button').data('action', action);
		}
		if (title) {
			domButton.find('button').html(title);
		}
		$('#coreUpdate').append(domButton);
	},

	/**
	 * Show a status message
	 *
	 * @param severity
	 * @param title
	 * @param message
	 */
	addMessage: function(severity, title, message) {
		var domMessage = TYPO3.Install.FlashMessage.render(severity, title, message);
		$('#coreUpdate').append(domMessage);
	}
};

$(function() {
	// Used in database compare section to select/deselect checkboxes
	$('.checkall').on('click', function() {
		$(this).closest('fieldset').find(':checkbox').prop('checked', this.checked);
	});

	$('.item-description').find('a').on('click', function() {
		var targetToggleGroupId = $(this.hash);
		if (targetToggleGroupId) {
			var $currentToggleGroup = $(this).closest('.toggleGroup');
			var $targetToggleGroup = $(targetToggleGroupId).closest('.toggleGroup');
			if ($targetToggleGroup !== $currentToggleGroup) {
				$currentToggleGroup.removeClass('expanded');
				$currentToggleGroup.find('.toggleData').hide();
				$targetToggleGroup.addClass('expanded');
				$targetToggleGroup.find('.toggleData').show();
				TYPO3.Install.Scrolling.handleButtonScrolling();
			}
		}
	});

	$(document).on('click', '.t3js-all-configuration-toggle', function() {
		var $panels = $('.panel-collapse', '#allConfiguration');
		var action = ($panels.eq(0).hasClass('in')) ? 'hide' : 'show';
		$panels.collapse(action);
	});

	var $configSearch = $('#configSearch');
	if ($configSearch.length > 0) {
		$(window).on('keydown', function(event) {
			if (event.ctrlKey || event.metaKey) {
				switch (String.fromCharCode(event.which).toLowerCase()) {
					case 'f':
						event.preventDefault();
						$configSearch.focus();
						break;
				}
			} else if (event.keyCode === 27) {
				event.preventDefault();
				$configSearch.val('').focus();
			}
		});
	}

	// Simple password strength indicator
	$('.t3-install-form-password-strength').on('keyup', function() {
		var value = $(this).val();
		var strongRegex = new RegExp('^(?=.{8,})(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*\\W).*$', 'g');
		var mediumRegex = new RegExp('^(?=.{8,})(((?=.*[A-Z])(?=.*[a-z]))|((?=.*[A-Z])(?=.*[0-9]))|((?=.*[a-z])(?=.*[0-9]))).*$', 'g');
		var enoughRegex = new RegExp('(?=.{8,}).*', 'g');

		if (value.length === 0) {
			$(this).attr('style', 'background-color:#FBB19B; border:1px solid #DC4C42');
		} else  if (!enoughRegex.test(value)) {
			$(this).attr('style', 'background-color:#FBB19B; border:1px solid #DC4C42');
		} else if (strongRegex.test(value)) {
			$(this).attr('style', 'background-color:#CDEACA; border:1px solid #58B548');
		} else if (mediumRegex.test(value)) {
			$(this).attr('style', 'background-color:#FBFFB3; border:1px solid #C4B70D');
		} else {
			$(this).attr('style', 'background-color:#FBFFB3; border:1px solid #C4B70D');
		}
	});

	// Install step database settings
	$('#t3js-connect-database-driver').on('change', function() {
		var driver = $(this).val();
		$('.t3-install-driver-data').hide();
		$('.t3-install-driver-data input').attr('disabled', 'disabled');
		$('#' + driver + ' input').attr('disabled', false);
		$('#' + driver).show();
	}).trigger('change');

	// Extension compatibility check
	var $container = $('#checkExtensions');
	$('.t3js-message', $container).hide();
	$('button', $container).click(function(e) {
		$('button', $container).hide();
		$('.t3js-message', $container).hide();
		$('.alert-loading', $container).show();
		TYPO3.Install.ExtensionChecker.checkExtensionsCompatibility(true);
		e.preventDefault();
		return false;
	});

	// Handle core update
	var $coreUpdateSection = $('#coreUpdate');
	if ($coreUpdateSection) {
		TYPO3.Install.coreUpdate.initialize();
		$coreUpdateSection.on('click', 'button', (function(e) {
			e.preventDefault();
			var action = $(e.target).data('action');
			TYPO3.Install.coreUpdate[action]();
			$(e.target).closest('.t3-install-form-submit').remove();
		}));
	}

	// Handle clearAllCache
	var $clearAllCacheSection = $('#clearAllCache');
	if ($clearAllCacheSection) {
		$clearAllCacheSection.on('click', 'button', (function(e) {
			TYPO3.Install.Cache.clearAllCache('clearAllCache');
			e.preventDefault();
			return false;
		}));
	}
	// Handle TCA ext_tables check
	var $tcaExtTablesCheckSection = $('#tcaExtTablesCheck');
	if ($tcaExtTablesCheckSection) {
		$tcaExtTablesCheckSection.on('click', 'button', (function(e) {
			TYPO3.Install.TcaIntegrityChecker.checkTcaIntegrity('tcaExtTablesCheck');
			e.preventDefault();
			return false;
		}));
	}

	// Handle TCA Migrations check
	var $tcaMigrationsCheckSection = $('#tcaMigrationsCheck');
	if ($tcaMigrationsCheckSection) {
		$tcaMigrationsCheckSection.on('click', 'button', (function(e) {
			TYPO3.Install.TcaIntegrityChecker.checkTcaIntegrity('tcaMigrationsCheck');
			e.preventDefault();
			return false;
		}));
	}

	var $installLeft = $('.t3js-list-group-wrapper');
	if ($installLeft.length > 0) {
		TYPO3.Install.Status.getFolderStatus();
		TYPO3.Install.Status.getEnvironmentStatus();
	}
	// This makes jquerys "contains" work case-insensitive
	jQuery.expr[':'].contains = jQuery.expr.createPseudo(function(arg) {
		return function(elem) {
			return jQuery(elem).text().toUpperCase().indexOf(arg.toUpperCase()) >= 0;
		};
	});
	$configSearch.keyup(function() {
		var typedQuery = $(this).val();
		$('div.item').each(function() {
			var $item = $(this);
			if ($(':contains(' + typedQuery + ')', $item).length > 0 || $('input[value*="' + typedQuery + '"]', $item).length > 0) {
				$item.removeClass('hidden').addClass('searchhit');
			} else {
				$item.removeClass('searchhit').addClass('hidden');
			}
		});
		$('.searchhit').parent().collapse('show');
	});
	var $searchFields = $configSearch;
	var searchResultShown = ('' !== $searchFields.first().val());

	// make search field clearable
	$searchFields.clearable({
		onClear: function() {
			if (searchResultShown) {
				$(this).closest('form').submit();
			}
		}
	});

	// Define width of fixed menu
	var $menuWrapper = $('#menuWrapper');
	var $menuListGroup = $menuWrapper.children('.t3js-list-group-wrapper');
	$menuWrapper.on('affixed.bs.affix', function() {
		$menuListGroup.width($(this).parent().width());
	});
	$menuListGroup.width($menuWrapper.parent().width());
	$(window).resize(function() {
		$menuListGroup.width($('#menuWrapper').parent().width());
	});
	var $collapse = $('.collapse');
	$collapse.on('shown.bs.collapse', function() {
		TYPO3.Install.Scrolling.handleButtonScrolling();
	});
	$collapse.on('hidden.bs.collapse', function() {
		TYPO3.Install.Scrolling.handleButtonScrolling();
	});

	// trigger 'handleButtonScrolling' on page scroll
	// if the user scroll until page bottom, we need to remove 'position: fixed'
	// so that the copyright info (footer) is not overlaid by the 'fixed button'
	var scrollTimeout;
	$(window).on('scroll', function() {
		clearTimeout(scrollTimeout);
		scrollTimeout = setTimeout(function() {
			TYPO3.Install.Scrolling.handleButtonScrolling();
		}, 50);
	});

	// automatically select the custom preset if a value in one of its input fields is changed
	$('.t3js-custom-preset').on('input', function() {
		$('#' + $(this).data('radio')).prop('checked', true);
	});

	TYPO3.Install.upgradeAnalysis.initialize();
	TYPO3.Install.upgradeAnalysis.showFilterManager();
	TYPO3.Install.upgradeAnalysis.hideDoumentationFile();
	TYPO3.Install.upgradeAnalysis.restoreDocumentationFile();

    TYPO3.Install.navigation.initialize();
});

TYPO3.Install.upgradeAnalysis = {
	provideTags: function () {
		$('#tagsort_tags_container').tagSort({
			selector: '.upgrade_analysis_item_to_filter'
		});
	},

	initialize: function () {
		TYPO3.Install.upgradeAnalysis.provideTags();
	},

	showFilterManager: function () {
		$(document).on('click', '#t3js-showFilterManager', function () {
			var classOpen = 'display_open';
			if ($('#t3js-showFilterManager').hasClass(classOpen)) {
				$('#t3js-showFilterManager').removeClass(classOpen);
				$('.t3js-filterManager').html('');
			} else {
				$.ajax({
					url: location.href + '&install[controller]=ajax&install[action]=filterManager',
					cache: false,
					success: function (res) {
						var result = $(res).html();
						$('.t3js-filterManager').html(result);
						$('#t3js-showFilterManager').addClass(classOpen);
					}
				});
			}
		});
	},

	hideDoumentationFile: function () {
		$(document).on('change', '.t3js-upgradeanalysis-ignore', function () {
			var filepath = $(this).data('filepath');
			if (this.checked) {
				var token = $('#saveIgnoredItemsToken').html();
				$(this).closest('.panel').fadeOut();
				var postData = {
					'install': {
						'ignoreFile': filepath,
						'token': token,
						'action': 'saveIgnoredItems'
					}
				};
				$.ajax({
					method: 'POST',
					data: postData,
					url: location.href + '&install[controller]=ajax'
				});
			}
		});
	},

	restoreDocumentationFile: function () {
		$(document).on('change', '.t3js-upgradeanalysis-restore', function () {
			var filepath = $(this).data('filepath');
			if (this.checked) {
				var token = $('#removeIgnoredItemsToken').html();
				$(this).closest('.panel').fadeOut();
				var postData = {
					'install': {
						'ignoreFile': filepath,
						'token': token,
						'action': 'removeIgnoredItems'
					}
				};
				$.ajax({
					method: 'POST',
					data: postData,
					url: location.href + '&install[controller]=ajax',
					success: function (res) {
						// append to restored panel
						$('#upgrade_analysis_restored_files').append(res);
					}
				});
			}
		});
	}
};
TYPO3.Install.navigation = {

    initialize: function () {
        $('#myModal.in').modal('show');

        $('a[role="tab"]').on("shown.bs.tab", function(e) {
        	console.log($(e.target).attr("href").substr(1));
            var id = $(e.target).attr("href").substr(1);
            window.location.hash = id;

            $('form').prop('action', function(i, val) {
                var actionUrl = val.split('#')[0] + window.location.hash;
                console.log(actionUrl);
                return actionUrl;
            });

        });
        $(".collapse").on('show.bs.collapse', function(e) {
            window.location.hash = this.id;
            $('form').prop('action', function(i, val) {
            	var actionUrl = val.split('#')[0] + window.location.hash;
                console.log(actionUrl);
                return actionUrl;
            });
        });
        var hash = window.location.hash;
		if ($('a[href="' + hash + '"][role=tab]').length > 0) {
            $('a[href="' + hash + '"]').tab('show');
		}
		else if ($('a.collapsed[href="' + hash + '"]').length > 0){
            $(hash).collapse('show');
			var tabId = $(hash).closest('div.tab-pane').attr('id');
            $('a[href="#' + tabId + '"]').tab('show');
		}

        $(document).on('click', '.t3js-topbar-button-modulemenu',
            function (evt) {
                evt.preventDefault();
                TYPO3.Install.navigation.toggleMenu();
            }
        );
    },
    /**
     * @param {bool} collapse
     */
    toggleMenu: function (collapse) {
        //TYPO3.Backend.NavigationContainer.cleanup();

        var $mainContainer = $('.t3js-scaffold');
        var expandedClass = 'scaffold-modulemenu-expanded';

        if (typeof collapse === 'undefined') {
            collapse = $mainContainer.hasClass(expandedClass);
        }
        $mainContainer.toggleClass(expandedClass, !collapse);
        if (!collapse) {
            $('.scaffold')
                .removeClass('scaffold-search-expanded')
                .removeClass('scaffold-toolbar-expanded');
        }

        // Persist collapsed state in the UC of the current user
        Storage.Persistent.set(
            'BackendComponents.States.typo3-module-menu',
            {
                collapsed: collapse
            }
        );

        //TYPO3.Backend.doLayout();
    }

};