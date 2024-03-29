<?php
namespace TYPO3\CMS\Install\Controller\Action\Tool;

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

use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Configuration\FeatureManager;
use TYPO3\CMS\Install\Controller\Action;

/**
 * About page
 */
class Settings extends Action\AbstractAction
{
    /**
     * @var \TYPO3\CMS\Install\Configuration\FeatureManager
     */
    protected $featureManager;

    /**
     * @var \TYPO3\CMS\Core\Configuration\ConfigurationManager
     */
    protected $configurationManager;

    /**
     * Error handlers are a bit mask in PHP. This register hints the View to
     * add a fluid view helper resolving the bit mask to its representation
     * as constants again for the specified items in ['SYS'].
     *
     * @var array
     */
    protected $phpErrorCodesSettings = [
        'errorHandlerErrors',
        'exceptionalErrors',
        'syslogErrorReporting',
        'belogErrorReporting',
    ];

    /**
     * @param FeatureManager $featureManager
     * @param ConfigurationManager $configurationManager
     */
    public function __construct(FeatureManager $featureManager = null, ConfigurationManager $configurationManager = null)
    {
        $this->featureManager = $featureManager ?: GeneralUtility::makeInstance(FeatureManager::class);
        $this->configurationManager = $configurationManager ?: GeneralUtility::makeInstance(ConfigurationManager::class);
    }

    /**
     * Executes the tool
     *
     * @return string Rendered content
     */
    protected function executeAction()
    {
        $actionMessages = [];
        if (isset($this->postValues['set']['changeInstallToolPassword'])) {
            $actionMessages[] = $this->changeInstallToolPassword();
        }
        if (isset($this->postValues['set']['activate'])) {
            $actionMessages[] = $this->activate();
            $this->activate();
        }

        if (isset($this->postValues['set']['write'])) {
            $this->view->assign('configurationValuesSaved', true);
            $this->view->assign('savedConfigurationValueMessages', $this->updateLocalConfigurationValues());
        } else {
            $this->view->assign('sectionNames', $this->getSpeakingSectionNames());
            $this->view->assign('data', $this->setUpConfigurationData());
        }
        $this->view->assign('actionMessages', $actionMessages);

        $postValues = is_array($this->postValues['values']) ? $this->postValues['values'] : [];
        $this->view->assign('features', $this->featureManager->getInitializedFeatures($postValues));

        return $this->view->render();
    }

    /**
     * Set new password if requested
     *
     * @return \TYPO3\CMS\Install\Status\StatusInterface
     */
    protected function changeInstallToolPassword()
    {
        $values = $this->postValues['values'];
        if ($values['newInstallToolPassword'] !== $values['newInstallToolPasswordCheck']) {
            /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
            $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\ErrorStatus::class);
            $message->setTitle('Install tool password not changed');
            $message->setMessage('Given passwords do not match.');
        } elseif (strlen($values['newInstallToolPassword']) < 8) {
            /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
            $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\ErrorStatus::class);
            $message->setTitle('Install tool password not changed');
            $message->setMessage('Given password must be at least eight characters long.');
        } else {
            /** @var \TYPO3\CMS\Core\Configuration\ConfigurationManager $configurationManager */
            $configurationManager = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\ConfigurationManager::class);
            $configurationManager->setLocalConfigurationValueByPath(
                'BE/installToolPassword',
                $this->getHashedPassword($values['newInstallToolPassword'])
            );
            /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
            $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\OkStatus::class);
            $message->setTitle('Install tool password changed');
        }
        return $message;
    }

    /**
     * Configure selected feature presets to be active
     *
     * @return \TYPO3\CMS\Install\Status\StatusInterface
     */
    protected function activate()
    {
        $configurationValues = $this->featureManager->getConfigurationForSelectedFeaturePresets($this->postValues['values']);

        if (!empty($configurationValues)) {
            $this->configurationManager->setLocalConfigurationValuesByPathValuePairs($configurationValues);
            /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
            $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\OkStatus::class);
            $message->setTitle('Configuration written');
            $messageBody = [];
            foreach ($configurationValues as $configurationKey => $configurationValue) {
                $messageBody[] = '\'' . $configurationKey . '\' => \'' . $configurationValue . '\'';
            }
            $message->setMessage(implode(LF, $messageBody));
        } else {
            /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
            $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\InfoStatus::class);
            $message->setTitle('No configuration change selected');
        }
        return $message;
    }
    /**
     * Returns an array of available sections and their description
     *
     * @return string[]
     */
    protected function getSpeakingSectionNames()
    {
        return [
            'BE' => 'Backend',
            'DB' => 'Database',
            'EXT' => 'Extension Installation',
            'FE' => 'Frontend',
            'GFX' => 'Image Processing',
            'HTTP' => 'Connection',
            'MAIL' => 'Mail',
            'SYS' => 'System'
        ];
    }

    /**
     * Set up configuration data
     *
     * @return array Configuration data
     */
    protected function setUpConfigurationData()
    {
        $data = [];
        $typo3ConfVars = array_keys($GLOBALS['TYPO3_CONF_VARS']);
        sort($typo3ConfVars);
        $commentArray = $this->getDefaultConfigArrayComments();
        foreach ($typo3ConfVars as $sectionName) {
            $data[$sectionName] = [];

            foreach ($GLOBALS['TYPO3_CONF_VARS'][$sectionName] as $key => $value) {
                if (isset($GLOBALS['TYPO3_CONF_VARS_extensionAdded'][$sectionName][$key])) {
                    // Don't allow editing stuff which is added by extensions
                    // Make sure we fix potentially duplicated entries from older setups
                    $potentialValue = str_replace(['\' . LF . \'', '\' . LF . \''], [LF, LF], $value);
                    while (preg_match('/' . preg_quote($GLOBALS['TYPO3_CONF_VARS_extensionAdded'][$sectionName][$key], '/') . '$/', $potentialValue)) {
                        $potentialValue = preg_replace('/' . preg_quote($GLOBALS['TYPO3_CONF_VARS_extensionAdded'][$sectionName][$key], '/') . '$/', '', $potentialValue);
                    }
                    $value = $potentialValue;
                }

                $description = trim($commentArray[$sectionName][$key]);
                $isTextarea = (bool)preg_match('/^(<.*?>)?string \\(textarea\\)/i', $description);
                $doNotRender = (bool)preg_match('/^(<.*?>)?string \\(exclude\\)/i', $description);

                if (!is_array($value) && !$doNotRender && (!preg_match('/[' . LF . CR . ']/', $value) || $isTextarea)) {
                    $itemData = [];
                    $itemData['key'] = $key;
                    $itemData['description'] = $description;
                    if ($isTextarea) {
                        $itemData['type'] = 'textarea';
                        $itemData['value'] = str_replace(['\' . LF . \'', '\' . LF . \''], [LF, LF], $value);
                    } elseif (preg_match('/^(<.*?>)?boolean/i', $description)) {
                        $itemData['type'] = 'checkbox';
                        $itemData['value'] = $value ? '1' : '0';
                        $itemData['checked'] = (bool)$value;
                    } elseif (preg_match('/^(<.*?>)?integer/i', $description)) {
                        $itemData['type'] = 'number';
                        $itemData['value'] = (int)$value;
                    } else {
                        $itemData['type'] = 'input';
                        $itemData['value'] = $value;
                    }

                    // Check if the setting is a PHP error code, will trigger a view helper in fluid
                    if ($sectionName === 'SYS' && in_array($key, $this->phpErrorCodesSettings)) {
                        $itemData['phpErrorCode'] = true;
                    }

                    $data[$sectionName][] = $itemData;
                }
            }
        }
        return $data;
    }

    /**
     * Store changed values in LocalConfiguration
     *
     * @return string Status messages of changed values
     */
    protected function updateLocalConfigurationValues()
    {
        $statusObjects = [];
        if (isset($this->postValues['values']) && is_array($this->postValues['values'])) {
            $configurationPathValuePairs = [];
            $commentArray = $this->getDefaultConfigArrayComments();
            $formValues = $this->postValues['values'];
            foreach ($formValues as $section => $valueArray) {
                if (is_array($GLOBALS['TYPO3_CONF_VARS'][$section])) {
                    foreach ($valueArray as $valueKey => $value) {
                        if (isset($GLOBALS['TYPO3_CONF_VARS'][$section][$valueKey])) {
                            $oldValue = $GLOBALS['TYPO3_CONF_VARS'][$section][$valueKey];
                            $description = trim($commentArray[$section][$valueKey]);

                            if (preg_match('/^string \\(textarea\\)/i', $description)) {
                                // Force Unix linebreaks in textareas
                                $value = str_replace(CR, '', $value);
                                // Preserve linebreaks
                                $value = str_replace(LF, '\' . LF . \'', $value);
                            }

                            if (preg_match('/^(<.*?>)?boolean/i', $description)) {
                                // When submitting settings in the Install Tool, values that default to "FALSE" or "TRUE"
                                // in EXT:core/Configuration/DefaultConfiguration.php will be sent as "0" resp. "1".
                                $value = $value === '1';
                                $valueHasChanged = (bool)$oldValue !== $value;
                            } elseif (preg_match('/^(<.*?>)?integer/i', $description)) {
                                // Cast integer values to integers (but only for values that can not contain a string as well)
                                $value = (int)$value;
                                $valueHasChanged = (int)$oldValue !== $value;
                            } else {
                                $valueHasChanged = (string)$oldValue !== (string)$value;
                            }

                            // Save if value changed
                            if ($valueHasChanged) {
                                $configurationPathValuePairs[$section . '/' . $valueKey] = $value;
                                /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
                                $status = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\OkStatus::class);
                                $status->setTitle('$GLOBALS[\'TYPO3_CONF_VARS\'][\'' . $section . '\'][\'' . $valueKey . '\']');
                                if (is_bool($value)) {
                                    $status->setMessage('New value = ' . ($value ? 'true' : 'false'));
                                } else {
                                    $status->setMessage('New value = ' . $value);
                                }
                                $statusObjects[] = $status;
                            }
                        }
                    }
                }
            }
            if (!empty($statusObjects)) {
                /** @var \TYPO3\CMS\Core\Configuration\ConfigurationManager $configurationManager */
                $configurationManager = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\ConfigurationManager::class);
                $configurationManager->setLocalConfigurationValuesByPathValuePairs($configurationPathValuePairs);
            }
        }
        return $statusObjects;
    }

    /**
     * @return array
     */
    protected function getDefaultConfigArrayComments() : array
    {
        /** @var \TYPO3\CMS\Core\Configuration\ConfigurationManager $configurationManager */
        $configurationManager = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\ConfigurationManager::class);
        return require $configurationManager->getDefaultConfigurationDescriptionFileLocation();
    }
}
