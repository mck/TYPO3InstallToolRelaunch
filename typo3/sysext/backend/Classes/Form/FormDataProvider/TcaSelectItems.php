<?php
namespace TYPO3\CMS\Backend\Form\FormDataProvider;

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

use TYPO3\CMS\Backend\Configuration\TranslationConfigurationProvider;
use TYPO3\CMS\Backend\Form\FormDataProviderInterface;
use TYPO3\CMS\Backend\Module\ModuleLoader;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Lang\LanguageService;

/**
 * Resolve select items, set processed item list in processedTca, sanitize and resolve database field
 */
class TcaSelectItems extends AbstractItemProvider implements FormDataProviderInterface {

	/**
	 * Resolve select items
	 *
	 * @param array $result
	 * @return array
	 * @throws \UnexpectedValueException
	 */
	public function addData(array $result) {
		$languageService = $this->getLanguageService();

		$table = $result['tableName'];

		foreach ($result['processedTca']['columns'] as $fieldName => $fieldConfig) {
			if (empty($fieldConfig['config']['type']) || $fieldConfig['config']['type'] !== 'select') {
				continue;
			}

			// Sanitize incoming item array
			if (!is_array($fieldConfig['config']['items'])) {
				$fieldConfig['config']['items'] = [];
			}

			// Make sure maxitems is always filled with a valid integer value.
			if (
				!empty($fieldConfig['config']['maxitems'])
				&& (int)$fieldConfig['config']['maxitems'] > 1
			) {
				$fieldConfig['config']['maxitems'] = (int)$fieldConfig['config']['maxitems'];
			} else {
				$fieldConfig['config']['maxitems'] = 1;
			}

			foreach ($fieldConfig['config']['items'] as $item) {
				if (!is_array($item)) {
					throw new \UnexpectedValueException(
						'An item in field ' . $fieldName . ' of table ' . $table . ' is not an array as expected',
						1439288036
					);
				}
			}

			$fieldConfig['config']['items'] = $this->addItemsFromPageTsConfig($result, $fieldName, $fieldConfig['config']['items']);
			$fieldConfig['config']['items'] = $this->addItemsFromSpecial($result, $fieldName, $fieldConfig['config']['items']);
			$fieldConfig['config']['items'] = $this->addItemsFromFolder($result, $fieldName, $fieldConfig['config']['items']);
			$staticItems = $fieldConfig['config']['items'];

			$fieldConfig['config']['items'] = $this->addItemsFromForeignTable($result, $fieldName, $fieldConfig['config']['items']);
			$dynamicItems = array_diff_key($fieldConfig['config']['items'], $staticItems);

			$fieldConfig['config']['items'] = $this->removeItemsByKeepItemsPageTsConfig($result, $fieldName, $fieldConfig['config']['items']);
			$fieldConfig['config']['items'] = $this->removeItemsByRemoveItemsPageTsConfig($result, $fieldName, $fieldConfig['config']['items']);
			$fieldConfig['config']['items'] = $this->removeItemsByUserLanguageFieldRestriction($result, $fieldName, $fieldConfig['config']['items']);
			$fieldConfig['config']['items'] = $this->removeItemsByUserAuthMode($result, $fieldName, $fieldConfig['config']['items']);
			$fieldConfig['config']['items'] = $this->removeItemsByDoktypeUserRestriction($result, $fieldName, $fieldConfig['config']['items']);

			// Resolve "itemsProcFunc"
			if (!empty($fieldConfig['config']['itemsProcFunc'])) {
				$fieldConfig['config']['items'] = $this->resolveItemProcessorFunction($result, $fieldName, $fieldConfig['config']['items']);
				// itemsProcFunc must not be used anymore
				unset($fieldConfig['config']['itemsProcFunc']);
			}

			// Translate labels
			$staticValues = [];
			foreach ($fieldConfig['config']['items'] as $key => $item) {
				if (!isset($dynamicItems[$key])) {
					$staticValues[$item[1]] = $item;
				}
				if (isset($result['pageTsConfigMerged']['TCEFORM.'][$table . '.'][$fieldName . '.']['altLabels.'][$item[1]])
					&& !empty($result['pageTsConfigMerged']['TCEFORM.'][$table . '.'][$fieldName . '.']['altLabels.'][$item[1]])
				) {
					$label = $languageService->sL($result['pageTsConfigMerged']['TCEFORM.'][$table . '.'][$fieldName . '.']['altLabels.'][$item[1]]);
				} else {
					$label = $languageService->sL($item[0]);
				}
				$value = strlen((string)$item[1]) > 0 ? $item[1] : '';
				$icon = $item[2] ?: NULL;
				$helpText = $item[3] ?: NULL;
				$fieldConfig['config']['items'][$key] = [
					$label,
					$value,
					$icon,
					$helpText
				];
			}
			// Keys may contain table names, so a numeric array is created
			$fieldConfig['config']['items'] = array_values($fieldConfig['config']['items']);

			$result['processedTca']['columns'][$fieldName] = $fieldConfig;
			$result['databaseRow'][$fieldName] = $this->processSelectFieldValue($result, $fieldName, $staticValues);
		}

		return $result;
	}

	/**
	 * TCA config "special" evaluation. Add them to $items
	 *
	 * @param array $result Result array
	 * @param string $fieldName Current handle field name
	 * @param array $items Incoming items
	 * @return array Modified item array
	 * @throws \UnexpectedValueException
	 */
	protected function addItemsFromSpecial(array $result, $fieldName, array $items) {
		// Guard
		if (empty($result['processedTca']['columns'][$fieldName]['config']['special'])
			|| !is_string($result['processedTca']['columns'][$fieldName]['config']['special'])
		) {
			return $items;
		}

		$languageService = $this->getLanguageService();
		$iconFactory = GeneralUtility::makeInstance(IconFactory::class);

		$special = $result['processedTca']['columns'][$fieldName]['config']['special'];
		if ($special === 'tables') {
			foreach ($GLOBALS['TCA'] as $currentTable => $_) {
				if (!empty($GLOBALS['TCA'][$currentTable]['ctrl']['adminOnly'])) {
					// Hide "admin only" tables
					continue;
				}
				$label = !empty($GLOBALS['TCA'][$currentTable]['ctrl']['title']) ? $GLOBALS['TCA'][$currentTable]['ctrl']['title'] : '';
				$icon = $iconFactory->mapRecordTypeToIconIdentifier($currentTable, array());
				$helpText = array();
				$languageService->loadSingleTableDescription($currentTable);
				// @todo: check if this actually works, currently help texts are missing
				$helpTextArray = $GLOBALS['TCA_DESCR'][$currentTable]['columns'][''];
				if (!empty($helpTextArray['description'])) {
					$helpText['description'] = $helpTextArray['description'];
				}
				$items[] = array($label, $currentTable, $icon, $helpText);
			}
		} elseif ($special === 'pagetypes') {
			if (isset($GLOBALS['TCA']['pages']['columns']['doktype']['config']['items'])
				&& is_array($GLOBALS['TCA']['pages']['columns']['doktype']['config']['items'])
			) {
				$specialItems = $GLOBALS['TCA']['pages']['columns']['doktype']['config']['items'];
				foreach ($specialItems as $specialItem) {
					if (!is_array($specialItem) || $specialItem[1] === '--div--') {
						// Skip non arrays and divider items
						continue;
					}
					$label = $specialItem[0];
					$value = $specialItem[1];
					$icon = $iconFactory->mapRecordTypeToIconIdentifier('pages', array('doktype' => $specialItem[1]));
					$items[] = array($label, $value, $icon);
				}
			}
		} elseif ($special === 'exclude') {
			$excludeArrays = $this->getExcludeFields();
			foreach ($excludeArrays as $excludeArray) {
				list($theTable, $theFullField) = explode(':', $excludeArray[1]);
				// If the field comes from a FlexForm, the syntax is more complex
				$theFieldParts = explode(';', $theFullField);
				$theField = array_pop($theFieldParts);
				// Add header if not yet set for table:
				if (!array_key_exists($theTable, $items)) {
					$icon = $iconFactory->mapRecordTypeToIconIdentifier($theTable, array());
					$items[$theTable] = array(
						$GLOBALS['TCA'][$theTable]['ctrl']['title'],
						'--div--',
						$icon
					);
				}
				// Add help text
				$helpText = array();
				$languageService->loadSingleTableDescription($theTable);
				$helpTextArray = $GLOBALS['TCA_DESCR'][$theTable]['columns'][$theFullField];
				if (!empty($helpTextArray['description'])) {
					$helpText['description'] = $helpTextArray['description'];
				}
				// Item configuration:
				// @todo: the title calculation does not work well for flex form fields, see unit tests
				$items[] = array(
					rtrim($languageService->sL($GLOBALS['TCA'][$theTable]['columns'][$theField]['label']), ':') . ' (' . $theField . ')',
					$excludeArray[1],
					'empty-empty',
					$helpText
				);
			}
		} elseif ($special === 'explicitValues') {
			$theTypes = $this->getExplicitAuthFieldValues();
			$icons = array(
				'ALLOW' => 'status-status-permission-granted',
				'DENY' => 'status-status-permission-denied'
			);
			// Traverse types:
			foreach ($theTypes as $tableFieldKey => $theTypeArrays) {
				if (is_array($theTypeArrays['items'])) {
					// Add header:
					$items[] = array(
						$theTypeArrays['tableFieldLabel'],
						'--div--',
					);
					// Traverse options for this field:
					foreach ($theTypeArrays['items'] as $itemValue => $itemContent) {
						// Add item to be selected:
						$items[] = array(
							'[' . $itemContent[2] . '] ' . $itemContent[1],
							$tableFieldKey . ':' . preg_replace('/[:|,]/', '', $itemValue) . ':' . $itemContent[0],
							$icons[$itemContent[0]]
						);
					}
				}
			}
		} elseif ($special === 'languages') {
			/** @var TranslationConfigurationProvider $translationConfigurationProvider */
			$translationConfigurationProvider = GeneralUtility::makeInstance(TranslationConfigurationProvider::class);
			$languages = $translationConfigurationProvider->getSystemLanguages();
			foreach ($languages as $language) {
				if ($language['uid'] !== -1) {
					$items[] = array(
						0 => $language['title'] . ' [' . $language['uid'] . ']',
						1 => $language['uid'],
						2 => $language['flagIcon']
					);
				}
			}
		} elseif ($special === 'custom') {
			$customOptions = $GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions'];
			if (is_array($customOptions)) {
				foreach ($customOptions as $coKey => $coValue) {
					if (is_array($coValue['items'])) {
						// Add header:
						$items[] = array(
							$languageService->sL($coValue['header']),
							'--div--'
						);
						// Traverse items:
						foreach ($coValue['items'] as $itemKey => $itemCfg) {
							$icon = 'empty-empty';
							$helpText = array();
							if (!empty($itemCfg[2])) {
								$helpText['description'] = $languageService->sL($itemCfg[2]);
							}
							$items[] = array(
								$languageService->sL($itemCfg[0]),
								$coKey . ':' . preg_replace('/[:|,]/', '', $itemKey),
								$icon,
								$helpText
							);
						}
					}
				}
			}
		} elseif ($special === 'modListGroup' || $special === 'modListUser') {
			$loadModules = GeneralUtility::makeInstance(ModuleLoader::class);
			$loadModules->load($GLOBALS['TBE_MODULES']);
			$modList = $special === 'modListUser' ? $loadModules->modListUser : $loadModules->modListGroup;
			if (is_array($modList)) {
				foreach ($modList as $theMod) {
					// Icon:
					$icon = $languageService->moduleLabels['tabs_images'][$theMod . '_tab'];
					if ($icon) {
						$icon = '../' . PathUtility::stripPathSitePrefix($icon);
					}
					// Add help text
					$helpText = array(
						'title' => $languageService->moduleLabels['labels'][$theMod . '_tablabel'],
						'description' => $languageService->moduleLabels['labels'][$theMod . '_tabdescr']
					);

					$label = '';
					// Add label for main module:
					$pp = explode('_', $theMod);
					if (count($pp) > 1) {
						$label .= $languageService->moduleLabels['tabs'][($pp[0] . '_tab')] . '>';
					}
					// Add modules own label now:
					$label .= $languageService->moduleLabels['tabs'][$theMod . '_tab'];

					// Item configuration:
					$items[] = array($label, $theMod, $icon, $helpText);
				}
			}
		} else {
			throw new \UnexpectedValueException(
				'Unknown special value ' . $special . ' for field ' . $fieldName . ' of table ' . $result['tableName'],
				1439298496
			);
		}

		return $items;
	}

	/**
	 * TCA config "fileFolder" evaluation. Add them to $items
	 *
	 * @param array $result Result array
	 * @param string $fieldName Current handle field name
	 * @param array $items Incoming items
	 * @return array Modified item array
	 */
	protected function addItemsFromFolder(array $result, $fieldName, array $items) {
		if (empty($result['processedTca']['columns'][$fieldName]['config']['fileFolder'])
			|| !is_string($result['processedTca']['columns'][$fieldName]['config']['fileFolder'])
		) {
			return $items;
		}

		$fileFolder = $result['processedTca']['columns'][$fieldName]['config']['fileFolder'];
		$fileFolder = GeneralUtility::getFileAbsFileName($fileFolder);
		$fileFolder = rtrim($fileFolder, '/') . '/';

		if (@is_dir($fileFolder)) {
			$fileExtensionList = '';
			if (!empty($result['processedTca']['columns'][$fieldName]['config']['fileFolder_extList'])
				&& is_string($result['processedTca']['columns'][$fieldName]['config']['fileFolder_extList'])
			) {
				$fileExtensionList = $result['processedTca']['columns'][$fieldName]['config']['fileFolder_extList'];
			}
			$recursionLevels = isset($fieldValue['config']['fileFolder_recursions'])
				? MathUtility::forceIntegerInRange($fieldValue['config']['fileFolder_recursions'], 0, 99)
				: 99;
			$fileArray = GeneralUtility::getAllFilesAndFoldersInPath(array(), $fileFolder, $fileExtensionList, 0, $recursionLevels);
			$fileArray = GeneralUtility::removePrefixPathFromList($fileArray, $fileFolder);
			foreach ($fileArray as $fileReference) {
				$fileInformation = pathinfo($fileReference);
				$icon = GeneralUtility::inList('gif,png,jpeg,jpg', strtolower($fileInformation['extension']))
					? '../' . PathUtility::stripPathSitePrefix($fileFolder) . $fileReference
					: '';
				$items[] = array(
					$fileReference,
					$fileReference,
					$icon
				);
			}
		}

		return $items;
	}

	/**
	 * TCA config "foreign_table" evaluation. Add them to $items
	 *
	 * @param array $result Result array
	 * @param string $fieldName Current handle field name
	 * @param array $items Incoming items
	 * @return array Modified item array
	 */
	protected function addItemsFromForeignTable(array $result, $fieldName, array $items) {
		// Guard
		if (empty($result['processedTca']['columns'][$fieldName]['config']['foreign_table'])
			|| !is_string($result['processedTca']['columns'][$fieldName]['config']['foreign_table'])
		) {
			return $items;
		}

		$languageService = $this->getLanguageService();
		$database = $this->getDatabaseConnection();

		$foreignTable = $result['processedTca']['columns'][$fieldName]['config']['foreign_table'];
		$foreignTableQueryArray = $this->buildForeignTableQuery($result, $fieldName);
		$queryResource = $database->exec_SELECT_queryArray($foreignTableQueryArray);

		// Early return on error with flash message
		$databaseError = $database->sql_error();
		if (!empty($databaseError)) {
			$msg = htmlspecialchars($databaseError) . '<br />' . LF;
			$msg .= $languageService->sL('LLL:EXT:lang/locallang_core.xlf:error.database_schema_mismatch');
			$msgTitle = $languageService->sL('LLL:EXT:lang/locallang_core.xlf:error.database_schema_mismatch_title');
			/** @var $flashMessage FlashMessage */
			$flashMessage = GeneralUtility::makeInstance(FlashMessage::class, $msg, $msgTitle, FlashMessage::ERROR, TRUE);
			/** @var $flashMessageService FlashMessageService */
			$flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
			/** @var $defaultFlashMessageQueue FlashMessageQueue */
			$defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
			$defaultFlashMessageQueue->enqueue($flashMessage);
			$database->sql_free_result($queryResource);
			return $items;
		}

		$labelPrefix = '';
		if (!empty($result['processedTca']['columns'][$fieldName]['config']['foreign_table_prefix'])) {
			$labelPrefix = $result['processedTca']['columns'][$fieldName]['config']['foreign_table_prefix'];
			$labelPrefix = $languageService->sL($labelPrefix);
		}
		$iconFieldName = '';
		if (!empty($result['processedTca']['ctrl']['selicon_field'])) {
			$iconFieldName = $result['processedTca']['ctrl']['selicon_field'];
		}
		$iconPath = '';
		if (!empty($result['processedTca']['ctrl']['selicon_field_path'])) {
			$iconPath = $result['processedTca']['ctrl']['selicon_field_path'];
		}

		$iconFactory = GeneralUtility::makeInstance(IconFactory::class);

		while ($foreignRow = $database->sql_fetch_assoc($queryResource)) {
			BackendUtility::workspaceOL($foreignTable, $foreignRow);
			if (is_array($foreignRow)) {
				// Prepare the icon if available:
				if ($iconFieldName && $iconPath && $foreignRow[$iconFieldName]) {
					$iParts = GeneralUtility::trimExplode(',', $foreignRow[$iconFieldName], TRUE);
					$icon = '../' . $iconPath . '/' . trim($iParts[0]);
				} else {
					$icon = $iconFactory->mapRecordTypeToIconIdentifier($foreignTable, $foreignRow);
				}
				// Add the item
				$items[] = array(
					$labelPrefix . htmlspecialchars(BackendUtility::getRecordTitle($foreignTable, $foreignRow)),
					$foreignRow['uid'],
					$icon
				);
			}
		}

		$database->sql_free_result($queryResource);

		return $items;
	}

	/**
	 * Remove items using "keepItems" pageTsConfig
	 *
	 * @param array $result Result array
	 * @param string $fieldName Current handle field name
	 * @param array $items Incoming items
	 * @return array Modified item array
	 */
	protected function removeItemsByKeepItemsPageTsConfig(array $result, $fieldName, array $items) {
		$table = $result['tableName'];
		if (empty($result['pageTsConfigMerged']['TCEFORM.'][$table . '.'][$fieldName . '.']['keepItems'])
			|| !is_string($result['pageTsConfigMerged']['TCEFORM.'][$table . '.'][$fieldName . '.']['keepItems'])
		) {
			return $items;
		}

		return ArrayUtility::keepItemsInArray(
			$items,
			$result['pageTsConfigMerged']['TCEFORM.'][$table . '.'][$fieldName . '.']['keepItems'],
			function ($value) {
				return $value[1];
			}
		);
	}

	/**
	 * Remove items using "removeItems" pageTsConfig
	 *
	 * @param array $result Result array
	 * @param string $fieldName Current handle field name
	 * @param array $items Incoming items
	 * @return array Modified item array
	 */
	protected function removeItemsByRemoveItemsPageTsConfig(array $result, $fieldName, array $items) {
		$table = $result['tableName'];
		if (empty($result['pageTsConfigMerged']['TCEFORM.'][$table . '.'][$fieldName . '.']['removeItems'])
			|| !is_string($result['pageTsConfigMerged']['TCEFORM.'][$table . '.'][$fieldName . '.']['removeItems'])
		) {
			return $items;
		}

		$removeItems = GeneralUtility::trimExplode(
			',',
			$result['pageTsConfigMerged']['TCEFORM.'][$table . '.'][$fieldName . '.']['removeItems'],
			TRUE
		);
		foreach ($items as $key => $itemValues) {
			if (in_array($itemValues[1], $removeItems)) {
				unset($items[$key]);
			}
		}

		return $items;
	}

	/**
	 * Remove items user restriction on language field
	 *
	 * @param array $result Result array
	 * @param string $fieldName Current handle field name
	 * @param array $items Incoming items
	 * @return array Modified item array
	 */
	protected function removeItemsByUserLanguageFieldRestriction(array $result, $fieldName, array $items) {
		// Guard clause returns if not a language field is handled
		if (empty($result['processedTca']['ctrl']['languageField'])
			|| $result['processedTca']['ctrl']['languageField'] !== $fieldName
		) {
			return $items;
		}

		$backendUser = $this->getBackendUser();
		foreach ($items as $key => $itemValues) {
			if (!$backendUser->checkLanguageAccess($itemValues[1])) {
				unset($items[$key]);
			}
		}

		return $items;
	}

	/**
	 * Remove items by user restriction on authMode items
	 *
	 * @param array $result Result array
	 * @param string $fieldName Current handle field name
	 * @param array $items Incoming items
	 * @return array Modified item array
	 */
	protected function removeItemsByUserAuthMode(array $result, $fieldName, array $items) {
		// Guard clause returns early if no authMode field is configured
		if (!isset($result['processedTca']['columns'][$fieldName]['config']['authMode'])
			|| !is_string($result['processedTca']['columns'][$fieldName]['config']['authMode'])
		) {
			return $items;
		}

		$backendUser = $this->getBackendUser();
		$authMode = $result['processedTca']['columns'][$fieldName]['config']['authMode'];
		foreach ($items as $key => $itemValues) {
			// @todo: checkAuthMode() uses $GLOBAL access for "individual" authMode - get rid of this
			if (!$backendUser->checkAuthMode($result['tableName'], $fieldName, $itemValues[1], $authMode)) {
				unset($items[$key]);
			}
		}

		return $items;
	}

	/**
	 * Remove items if doktype is handled for non admin users
	 *
	 * @param array $result Result array
	 * @param string $fieldName Current handle field name
	 * @param array $items Incoming items
	 * @return array Modified item array
	 */
	protected function removeItemsByDoktypeUserRestriction(array $result, $fieldName, array $items) {
		$table = $result['tableName'];
		$backendUser = $this->getBackendUser();
		// Guard clause returns if not correct table and field or if user is admin
		if ($table !== 'pages' && $table !== 'pages_language_overlay'
			|| $fieldName !== 'doktype' || $backendUser->isAdmin()
		) {
			return $items;
		}

		$allowedPageTypes = $backendUser->groupData['pagetypes_select'];
		foreach ($items as $key => $itemValues) {
			if (!GeneralUtility::inList($allowedPageTypes, $itemValues[1])) {
				unset($items[$key]);
			}
		}

		return $items;
	}

	/**
	 * Returns an array with the exclude fields as defined in TCA and FlexForms
	 * Used for listing the exclude fields in be_groups forms.
	 *
	 * @return array Array of arrays with excludeFields (fieldName, table:fieldName) from TCA
	 *               and FlexForms (fieldName, table:extKey;sheetName;fieldName)
	 */
	protected function getExcludeFields() {
		$languageService = $this->getLanguageService();
		$finalExcludeArray = array();

		// Fetch translations for table names
		$tableToTranslation = array();
		// All TCA keys
		foreach ($GLOBALS['TCA'] as $table => $conf) {
			$tableToTranslation[$table] = $languageService->sl($conf['ctrl']['title']);
		}
		// Sort by translations
		asort($tableToTranslation);
		foreach ($tableToTranslation as $table => $translatedTable) {
			$excludeArrayTable = array();

			// All field names configured and not restricted to admins
			if (is_array($GLOBALS['TCA'][$table]['columns'])
				&& empty($GLOBALS['TCA'][$table]['ctrl']['adminOnly'])
				&& (empty($GLOBALS['TCA'][$table]['ctrl']['rootLevel']) || !empty($GLOBALS['TCA'][$table]['ctrl']['security']['ignoreRootLevelRestriction']))
			) {
				foreach ($GLOBALS['TCA'][$table]['columns'] as $field => $_) {
					if ($GLOBALS['TCA'][$table]['columns'][$field]['exclude']) {
						// Get human readable names of fields
						$translatedField = $languageService->sl($GLOBALS['TCA'][$table]['columns'][$field]['label']);
						// Add entry
						$excludeArrayTable[] = array($translatedTable . ': ' . $translatedField, $table . ':' . $field);
					}
				}
			}
			// All FlexForm fields
			$flexFormArray = $this->getRegisteredFlexForms($table);
			foreach ($flexFormArray as $tableField => $flexForms) {
				// Prefix for field label, e.g. "Plugin Options:"
				$labelPrefix = '';
				if (!empty($GLOBALS['TCA'][$table]['columns'][$tableField]['label'])) {
					$labelPrefix = $languageService->sl($GLOBALS['TCA'][$table]['columns'][$tableField]['label']);
				}
				// Get all sheets and title
				foreach ($flexForms as $extIdent => $extConf) {
					$extTitle = $languageService->sl($extConf['title']);
					// Get all fields in sheet
					foreach ($extConf['ds']['sheets'] as $sheetName => $sheet) {
						if (empty($sheet['ROOT']['el']) || !is_array($sheet['ROOT']['el'])) {
							continue;
						}
						foreach ($sheet['ROOT']['el'] as $fieldName => $field) {
							// Use only fields that have exclude flag set
							if (empty($field['TCEforms']['exclude'])) {
								continue;
							}
							$fieldLabel = !empty($field['TCEforms']['label']) ? $languageService->sl($field['TCEforms']['label']) : $fieldName;
							$fieldIdent = $table . ':' . $tableField . ';' . $extIdent . ';' . $sheetName . ';' . $fieldName;
							$excludeArrayTable[] = array(trim($labelPrefix . ' ' . $extTitle, ': ') . ': ' . $fieldLabel, $fieldIdent);
						}
					}
				}
			}
			// Sort fields by the translated value
			if (!empty($excludeArrayTable)) {
				usort($excludeArrayTable, function (array $array1, array $array2) {
					$array1 = reset($array1);
					$array2 = reset($array2);
					if (is_string($array1) && is_string($array2)) {
						return strcasecmp($array1, $array2);
					}
					return 0;
				});
				$finalExcludeArray = array_merge($finalExcludeArray, $excludeArrayTable);
			}
		}

		return $finalExcludeArray;
	}

	/**
	 * Returns all registered FlexForm definitions with title and fields
	 *
	 * @param string $table Table to handle
	 * @return array Data structures with speaking extension title
	 */
	protected function getRegisteredFlexForms($table) {
		if (empty($table) || empty($GLOBALS['TCA'][$table]['columns'])) {
			return array();
		}
		$flexForms = array();
		foreach ($GLOBALS['TCA'][$table]['columns'] as $tableField => $fieldConf) {
			if (!empty($fieldConf['config']['type']) && !empty($fieldConf['config']['ds']) && $fieldConf['config']['type'] == 'flex') {
				$flexForms[$tableField] = array();
				unset($fieldConf['config']['ds']['default']);
				// Get pointer fields
				$pointerFields = !empty($fieldConf['config']['ds_pointerField']) ? $fieldConf['config']['ds_pointerField'] : 'list_type,CType';
				$pointerFields = GeneralUtility::trimExplode(',', $pointerFields);
				// Get FlexForms
				foreach ($fieldConf['config']['ds'] as $flexFormKey => $dataStructure) {
					// Get extension identifier (uses second value if it's not empty, "list" or "*", else first one)
					$identFields = GeneralUtility::trimExplode(',', $flexFormKey);
					$extIdent = $identFields[0];
					if (!empty($identFields[1]) && $identFields[1] !== 'list' && $identFields[1] !== '*') {
						$extIdent = $identFields[1];
					}
					// Load external file references
					if (!is_array($dataStructure)) {
						$file = GeneralUtility::getFileAbsFileName(str_ireplace('FILE:', '', $dataStructure));
						if ($file && @is_file($file)) {
							$dataStructure = GeneralUtility::getUrl($file);
						}
						$dataStructure = GeneralUtility::xml2array($dataStructure);
						if (!is_array($dataStructure)) {
							continue;
						}
					}
					// Get flexform content
					$dataStructure = GeneralUtility::resolveAllSheetsInDS($dataStructure);
					if (empty($dataStructure['sheets']) || !is_array($dataStructure['sheets'])) {
						continue;
					}
					// Use DS pointer to get extension title from TCA
					// @todo: I don't understand this code ... does it make sense at all?
					$title = $extIdent;
					$keyFields = GeneralUtility::trimExplode(',', $flexFormKey);
					foreach ($pointerFields as $pointerKey => $pointerName) {
						if (empty($keyFields[$pointerKey]) || $keyFields[$pointerKey] === '*' || $keyFields[$pointerKey] === 'list') {
							continue;
						}
						if (!empty($GLOBALS['TCA'][$table]['columns'][$pointerName]['config']['items'])) {
							$items = $GLOBALS['TCA'][$table]['columns'][$pointerName]['config']['items'];
							if (!is_array($items)) {
								continue;
							}
							foreach ($items as $itemConf) {
								if (!empty($itemConf[0]) && !empty($itemConf[1]) && $itemConf[1] == $keyFields[$pointerKey]) {
									$title = $itemConf[0];
									break 2;
								}
							}
						}
					}
					$flexForms[$tableField][$extIdent] = array(
						'title' => $title,
						'ds' => $dataStructure
					);
				}
			}
		}
		return $flexForms;
	}

	/**
	 * Returns an array with explicit Allow/Deny fields.
	 * Used for listing these field/value pairs in be_groups forms
	 *
	 * @return array Array with information from all of $GLOBALS['TCA']
	 */
	protected function getExplicitAuthFieldValues() {
		$languageService = static::getLanguageService();
		$adLabel = array(
			'ALLOW' => $languageService->sl('LLL:EXT:lang/locallang_core.xlf:labels.allow'),
			'DENY' => $languageService->sl('LLL:EXT:lang/locallang_core.xlf:labels.deny')
		);
		$allowDenyOptions = array();
		foreach ($GLOBALS['TCA'] as $table => $_) {
			// All field names configured:
			if (is_array($GLOBALS['TCA'][$table]['columns'])) {
				foreach ($GLOBALS['TCA'][$table]['columns'] as $field => $_) {
					$fieldConfig = $GLOBALS['TCA'][$table]['columns'][$field]['config'];
					if ($fieldConfig['type'] === 'select' && $fieldConfig['authMode']) {
						// Check for items
						if (is_array($fieldConfig['items'])) {
							// Get Human Readable names of fields and table:
							$allowDenyOptions[$table . ':' . $field]['tableFieldLabel'] =
								$languageService->sl($GLOBALS['TCA'][$table]['ctrl']['title']) . ': '
								. $languageService->sl($GLOBALS['TCA'][$table]['columns'][$field]['label']);
							foreach ($fieldConfig['items'] as $iVal) {
								// Values '' is not controlled by this setting.
								if ((string)$iVal[1] !== '') {
									// Find iMode
									$iMode = '';
									switch ((string)$fieldConfig['authMode']) {
										case 'explicitAllow':
											$iMode = 'ALLOW';
											break;
										case 'explicitDeny':
											$iMode = 'DENY';
											break;
										case 'individual':
											if ($iVal[4] === 'EXPL_ALLOW') {
												$iMode = 'ALLOW';
											} elseif ($iVal[4] === 'EXPL_DENY') {
												$iMode = 'DENY';
											}
											break;
									}
									// Set iMode
									if ($iMode) {
										$allowDenyOptions[$table . ':' . $field]['items'][$iVal[1]] = array($iMode, $languageService->sl($iVal[0]), $adLabel[$iMode]);
									}
								}
							}
						}
					}
				}
			}
		}
		return $allowDenyOptions;
	}

	/**
	 * Build query to fetch foreign records
	 *
	 * @param array $result Result array
	 * @param string $localFieldName Current handle field name
	 * @return array Query array ready to be executed via Database->exec_SELECT_queryArray()
	 * @throws \UnexpectedValueException
	 */
	protected function buildForeignTableQuery(array $result, $localFieldName) {
		$backendUser = $this->getBackendUser();

		$foreignTableName = $result['processedTca']['columns'][$localFieldName]['config']['foreign_table'];

		if (!is_array($GLOBALS['TCA'][$foreignTableName])) {
			throw new \UnexpectedValueException(
				'Field ' . $localFieldName . ' of table ' . $result['tableName'] . ' reference to foreign table '
				. $foreignTableName . ', but this table is not defined in TCA',
				1439569743
			);
		}

		$foreignTableClauseArray = $this->processForeignTableClause($result, $foreignTableName, $localFieldName);

		$queryArray = array();
		$queryArray['SELECT'] = BackendUtility::getCommonSelectFields($foreignTableName, $foreignTableName . '.');

		// rootLevel = -1 means that elements can be on the rootlevel OR on any page (pid!=-1)
		// rootLevel = 0 means that elements are not allowed on root level
		// rootLevel = 1 means that elements are only on the root level (pid=0)
		$rootLevel = 0;
		if (isset($GLOBALS['TCA'][$foreignTableName]['ctrl']['rootLevel'])) {
			$rootLevel = $GLOBALS['TCA'][$foreignTableName]['ctrl']['rootLevel'];
		}
		$deleteClause = BackendUtility::deleteClause($foreignTableName);
		if ($rootLevel == 1 || $rootLevel == -1) {
			$pidWhere = $foreignTableName . '.pid' . (($rootLevel == -1) ? '<>-1' : '=0');
			$queryArray['FROM'] = $foreignTableName;
			$queryArray['WHERE'] = $pidWhere . $deleteClause . $foreignTableClauseArray['WHERE'];
		} else {
			$pageClause = $backendUser->getPagePermsClause(1);
			if ($foreignTableName === 'pages') {
				$queryArray['FROM'] = 'pages';
				$queryArray['WHERE'] = '1=1' . $deleteClause . ' AND' . $pageClause . $foreignTableClauseArray['WHERE'];
			} else {
				$queryArray['FROM'] = $foreignTableName . ', pages';
				$queryArray['WHERE'] = 'pages.uid=' . $foreignTableName . '.pid AND pages.deleted=0'
					. $deleteClause . ' AND' . $pageClause .  $foreignTableClauseArray['WHERE'];
			}
		}

		$queryArray['GROUPBY'] = $foreignTableClauseArray['GROUPBY'];
		$queryArray['ORDERBY'] = $foreignTableClauseArray['ORDERBY'];
		$queryArray['LIMIT'] = $foreignTableClauseArray['LIMIT'];

		return $queryArray;
	}

	/**
	 * Replace markers in a where clause from TCA foreign_table_where
	 *
	 * ###REC_FIELD_[field name]###
	 * ###THIS_UID### - is current element uid (zero if new).
	 * ###CURRENT_PID### - is the current page id (pid of the record).
	 * ###SITEROOT###
	 * ###PAGE_TSCONFIG_ID### - a value you can set from Page TSconfig dynamically.
	 * ###PAGE_TSCONFIG_IDLIST### - a value you can set from Page TSconfig dynamically.
	 * ###PAGE_TSCONFIG_STR### - a value you can set from Page TSconfig dynamically.
	 *
	 * @param array $result Result array
	 * @param string $foreignTableName Name of foreign table
	 * @param string $localFieldName Current handle field name
	 * @return array Query parts with keys WHERE, ORDERBY, GROUPBY, LIMIT
	 */
	protected function processForeignTableClause(array $result, $foreignTableName, $localFieldName) {
		$database = $this->getDatabaseConnection();
		$localTable = $result['tableName'];

		$foreignTableClause = '';
		if (!empty($result['processedTca']['columns'][$localFieldName]['config']['foreign_table_where'])
			&& is_string($result['processedTca']['columns'][$localFieldName]['config']['foreign_table_where'])
		) {
			$foreignTableClause = $result['processedTca']['columns'][$localFieldName]['config']['foreign_table_where'];
			// Replace possible markers in query
			if (strstr($foreignTableClause, '###REC_FIELD_')) {
				// " AND table.field='###REC_FIELD_field1###' AND ..." -> array(" AND table.field='", "field1###' AND ...")
				$whereClauseParts = explode('###REC_FIELD_', $foreignTableClause);
				foreach ($whereClauseParts as $key => $value) {
					if ($key !== 0) {
						// "field1###' AND ..." -> array("field1", "' AND ...")
						$whereClauseSubParts = explode('###', $value, 2);
						// @todo: Throw exception if there is no value? What happens for NEW records?
						$rowFieldValue = $result['databaseRow'][$whereClauseSubParts[0]];
						if (is_array($rowFieldValue)) {
							// If a select or group field is used here, it may have been processed already and
							// is now an array. Use first selected value in this case.
							$rowFieldValue = $rowFieldValue[0];
						}
						if (substr($whereClauseParts[0], -1) === '\'' && $whereClauseSubParts[1][0] === '\'') {
							$whereClauseParts[$key] = $database->quoteStr($rowFieldValue, $foreignTableName) . $whereClauseSubParts[1];
						} else {
							$whereClauseParts[$key] = $database->fullQuoteStr($rowFieldValue, $foreignTableName) . $whereClauseSubParts[1];
						}
					}
				}
				$foreignTableClause = implode('', $whereClauseParts);
			}

			$siteRootUid = 0;
			foreach ($result['rootline'] as $rootlinePage) {
				if (!empty($rootlinePage['is_siteroot'])) {
					$siteRootUid = (int)$rootlinePage['uid'];
					break;
				}
			}
			$pageTsConfigId = 0;
			if ($result['pageTsConfigMerged']['TCEFORM.'][$localTable . '.'][$localFieldName . '.']['PAGE_TSCONFIG_ID']) {
				$pageTsConfigId = (int)$result['pageTsConfigMerged']['TCEFORM.'][$localTable . '.'][$localFieldName . '.']['PAGE_TSCONFIG_ID'];
			}
			$pageTsConfigIdList = 0;
			if ($result['pageTsConfigMerged']['TCEFORM.'][$localTable . '.'][$localFieldName . '.']['PAGE_TSCONFIG_IDLIST']) {
				$pageTsConfigIdList = $result['pageTsConfigMerged']['TCEFORM.'][$localTable . '.'][$localFieldName . '.']['PAGE_TSCONFIG_IDLIST'];
				$pageTsConfigIdListArray = GeneralUtility::trimExplode(',', $pageTsConfigIdList, TRUE);
				$pageTsConfigIdList = array();
				foreach ($pageTsConfigIdListArray as $pageTsConfigIdListElement) {
					if (MathUtility::canBeInterpretedAsInteger($pageTsConfigIdListElement)) {
						$pageTsConfigIdList[] = (int)$pageTsConfigIdListElement;
					}
				}
				$pageTsConfigIdList = implode(',', $pageTsConfigIdList);
			}
			$pageTsConfigString = '';
			if ($result['pageTsConfigMerged']['TCEFORM.'][$localTable . '.'][$localFieldName . '.']['PAGE_TSCONFIG_STR']) {
				$pageTsConfigString = $result['pageTsConfigMerged']['TCEFORM.'][$localTable . '.'][$localFieldName . '.']['PAGE_TSCONFIG_STR'];
				$pageTsConfigString = $database->quoteStr($pageTsConfigString, $foreignTableName);
			}

			$foreignTableClause = str_replace(
				array(
					'###CURRENT_PID###',
					'###THIS_UID###',
					'###SITEROOT###',
					'###PAGE_TSCONFIG_ID###',
					'###PAGE_TSCONFIG_IDLIST###',
					'###PAGE_TSCONFIG_STR###'
				),
				array(
					(int)$result['effectivePid'],
					(int)$result['databaseRow']['uid'],
					$siteRootUid,
					$pageTsConfigId,
					$pageTsConfigIdList,
					$pageTsConfigString
				),
				$foreignTableClause
			);
		}

		// Split the clause into an array with keys WHERE, GROUPBY, ORDERBY, LIMIT
		// Prepend a space to make sure "[[:space:]]+" will find a space there for the first element.
		$foreignTableClause = ' ' . $foreignTableClause;
		$foreignTableClauseArray = array(
			'WHERE' => '',
			'GROUPBY' => '',
			'ORDERBY' => '',
			'LIMIT' => '',
		);
		// Find LIMIT
		$reg = array();
		if (preg_match('/^(.*)[[:space:]]+LIMIT[[:space:]]+([[:alnum:][:space:],._]+)$/i', $foreignTableClause, $reg)) {
			$foreignTableClauseArray['LIMIT'] = trim($reg[2]);
			$foreignTableClause = $reg[1];
		}
		// Find ORDER BY
		$reg = array();
		if (preg_match('/^(.*)[[:space:]]+ORDER[[:space:]]+BY[[:space:]]+([[:alnum:][:space:],._]+)$/i', $foreignTableClause, $reg)) {
			$foreignTableClauseArray['ORDERBY'] = trim($reg[2]);
			$foreignTableClause = $reg[1];
		}
		// Find GROUP BY
		$reg = array();
		if (preg_match('/^(.*)[[:space:]]+GROUP[[:space:]]+BY[[:space:]]+([[:alnum:][:space:],._]+)$/i', $foreignTableClause, $reg)) {
			$foreignTableClauseArray['GROUPBY'] = trim($reg[2]);
			$foreignTableClause = $reg[1];
		}
		// Rest is assumed to be "WHERE" clause
		$foreignTableClauseArray['WHERE'] = $foreignTableClause;

		return $foreignTableClauseArray;
	}

	/**
	 * Validate and sanitize database row values of the select field with the given name.
	 * Creates an array out of databaseRow[selectField] values.
	 *
	 * @param array $result The current result array.
	 * @param string $fieldName Name of the current select field.
	 * @param array $staticValues Array with statically defined items, item value is used as array key.
	 * @return array
	 */
	protected function processSelectFieldValue(array $result, $fieldName, array $staticValues) {
		$fieldConfig = $result['processedTca']['columns'][$fieldName];

		// For single select fields we just keep the current value because the renderer
		// will take care of showing the "Invalid value" text.
		// For maxitems=1 select fields is is also possible to select empty values.
		// @todo: move handling of invalid values to this data provider.
		if ($fieldConfig['config']['maxitems'] === 1) {
			return array($result['databaseRow'][$fieldName]);
		}

		$currentDatabaseValues = array_key_exists($fieldName, $result['databaseRow']) ? $result['databaseRow'][$fieldName] : '';
		// Selecting empty values does not make sense for fields that can contain more than one item
		// because it is impossible to determine if the empty value or nothing is selected.
		// This is why empty values will be removed for multi value fields.
		$currentDatabaseValuesArray = GeneralUtility::trimExplode(',', $currentDatabaseValues, TRUE);
		$newDatabaseValueArray = [];

		// Add all values that were defined by static methods and do not come from the relation
		// e.g. TCA, TSconfig, itemProcFunc etc.
		foreach ($currentDatabaseValuesArray as $value) {
			if (isset($staticValues[$value])) {
				$newDatabaseValueArray[] = $value;
			}
		}

		if (isset($fieldConfig['config']['foreign_table']) && !empty($fieldConfig['config']['foreign_table'])) {
			/** @var RelationHandler $relationHandler */
			$relationHandler = GeneralUtility::makeInstance(RelationHandler::class);
			$relationHandler->registerNonTableValues = !empty($fieldConfig['config']['allowNonIdValues']);
			if (isset($fieldConfig['config']['MM']) && !empty($fieldConfig['config']['MM'])) {
				// MM relation
				$relationHandler->start(
					$currentDatabaseValues,
					$fieldConfig['config']['foreign_table'],
					$fieldConfig['config']['MM'],
					$result['databaseRow']['uid'],
					$result['tableName'],
					$fieldConfig['config']
				);
			} else {
				// Non MM relation
				// If not dealing with MM relations, use default live uid, not versioned uid for record relations
				$relationHandler->start(
					$currentDatabaseValues,
					$fieldConfig['config']['foreign_table'],
					'',
					$this->getLiveUid($result),
					$result['tableName'],
					$fieldConfig['config']
				);
			}
			$newDatabaseValueArray = array_merge($newDatabaseValueArray, $relationHandler->getValueArray());
		}

		return array_unique($newDatabaseValueArray);
	}

	/**
	 * Gets the record uid of the live default record. If already
	 * pointing to the live record, the submitted record uid is returned.
	 *
	 * @param array $result Result array
	 * @return int
	 * @throws \UnexpectedValueException
	 */
	protected function getLiveUid(array $result) {
		$table = $result['tableName'];
		$row = $result['databaseRow'];
		$uid = $row['uid'];
		if (!empty($result['processedTca']['ctrl']['versioningWS'])
			&& $result['pid'] === -1
		) {
			if (empty($row['t3ver_oid'])) {
				throw new \UnexpectedValueException(
					'No t3ver_oid found for record ' . $row['uid'] . ' on table ' . $table,
					1440066481
				);
			}
			$uid = $row['t3ver_oid'];
		}
		return $uid;
	}

	/**
	 * @return LanguageService
	 */
	protected function getLanguageService() {
		return $GLOBALS['LANG'];
	}

	/**
	 * @return DatabaseConnection
	 */
	protected function getDatabaseConnection() {
		return $GLOBALS['TYPO3_DB'];
	}

	/**
	 * @return BackendUserAuthentication
	 */
	protected function getBackendUser() {
		return $GLOBALS['BE_USER'];
	}

}