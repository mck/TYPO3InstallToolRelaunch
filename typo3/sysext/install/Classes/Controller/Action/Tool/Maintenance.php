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

use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\ClassLoadingInformation;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Controller\Action;
use TYPO3\CMS\Install\Status\ErrorStatus;
use TYPO3\CMS\Install\Status\InfoStatus;
use TYPO3\CMS\Install\Status\OkStatus;

/**
 * Handle important actions
 */
class Maintenance extends Action\AbstractAction
{
    /**
     * Executes the tool
     *
     * @return string Rendered content
     */
    protected function executeAction()
    {
        if (isset($this->postValues['set']['changeEncryptionKey'])) {
            $this->setNewEncryptionKeyAndLogOut();
        }

        $actionMessages = [];
        if (isset($this->postValues['set']['clearTables'])) {
            $actionMessages[] = $this->clearSelectedTables();
            $this->view->assign('postAction', 'clearTables');
        }
        if (isset($this->postValues['set']['dumpAutoload'])) {
            $actionMessages[] = $this->dumpAutoload();
        }
        if (isset($this->postValues['set']['createAdministrator'])) {
            $actionMessages[] = $this->createAdministrator();
        }
        if (isset($this->postValues['set']['resetBackendUserUc'])) {
            $actionMessages[] = $this->resetBackendUserUc();
            $this->view->assign('postAction', 'resetBackendUserUc');
        }
        if (isset($this->postValues['set']['clearProcessedFiles'])) {
            $actionMessages[] = $this->clearProcessedFiles();
            $this->view->assign('postAction', 'clearProcessedFiles');
        }
        if (isset($this->postValues['set']['deleteTypo3TempFiles'])) {
            $this->view->assign('postAction', 'deleteTypo3TempFiles');
        }

        // Database analyzer handling
        if (isset($this->postValues['set']['databaseAnalyzerExecute'])
            || isset($this->postValues['set']['databaseAnalyzerAnalyze'])
        ) {
            $this->loadExtLocalconfDatabaseAndExtTables();
        }
        if (isset($this->postValues['set']['databaseAnalyzerExecute'])) {
            $actionMessages = array_merge($actionMessages, $this->databaseAnalyzerExecute());
        }
        if (isset($this->postValues['set']['databaseAnalyzerAnalyze'])) {
            try {
                $actionMessages[] = $this->databaseAnalyzerAnalyze();
            } catch (\TYPO3\CMS\Core\Database\Schema\Exception\StatementException $e) {
                $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\ErrorStatus::class);
                $message->setTitle('Database analysis failed');
                $message->setMessage($e->getMessage());
                $actionMessages[] = $message;
            }
        }

        $this->view->assign('cleanableTables', $this->getCleanableTableList());
        $typo3TempData = $this->getTypo3TempStatistics();
        $this->view->assign('typo3TempData', $typo3TempData);

        $this->view->assign('actionMessages', $actionMessages);

        $operatingSystem = TYPO3_OS === 'WIN' ? 'Windows' : 'Unix';

        /** @var \TYPO3\CMS\Install\Service\CoreUpdateService $coreUpdateService */
        $this->view
            ->assign('composerMode', Bootstrap::usesComposerClassLoading())
            ->assign('operatingSystem', $operatingSystem)
            ->assign('cgiDetected', GeneralUtility::isRunningOnCgiServerApi());

        $connectionInfos = [];
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ($connectionPool->getConnectionNames() as $connectionName) {
            $connection = $connectionPool->getConnectionByName($connectionName);
            $connectionParameters = $connection->getParams();
            $connectionInfo = [
                'connectionName' => $connectionName,
                'version' => $connection->getServerVersion(),
                'databaseName' => $connection->getDatabase(),
                'username' => $connection->getUsername(),
                'host' => $connection->getHost(),
                'port' => $connection->getPort(),
                'socket' => $connectionParameters['unix_socket'] ?? '',
                'numberOfTables' => count($connection->getSchemaManager()->listTables()),
                'numberOfMappedTables' => 0,
            ];
            if (isset($GLOBALS['TYPO3_CONF_VARS']['DB']['TableMapping'])
                && is_array($GLOBALS['TYPO3_CONF_VARS']['DB']['TableMapping'])
            ) {
                // Count number of array keys having $connectionName as value
                $connectionInfo['numberOfMappedTables'] = count(array_intersect(
                    $GLOBALS['TYPO3_CONF_VARS']['DB']['TableMapping'],
                    [$connectionName]
                ));
            }
            $connectionInfos[] = $connectionInfo;
        }

        $this->view->assign('connections', $connectionInfos);

        return $this->view->render();
    }

    /**
     * Create administrator user
     *
     * @return \TYPO3\CMS\Install\Status\StatusInterface
     */
    protected function createAdministrator()
    {
        $values = $this->postValues['values'];
        $username = preg_replace('/\\s/i', '', $values['newUserUsername']);
        $password = $values['newUserPassword'];
        $passwordCheck = $values['newUserPasswordCheck'];

        if (strlen($username) < 1) {
            /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
            $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\ErrorStatus::class);
            $message->setTitle('Administrator user not created');
            $message->setMessage('No valid username given.');
        } elseif ($password !== $passwordCheck) {
            /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
            $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\ErrorStatus::class);
            $message->setTitle('Administrator user not created');
            $message->setMessage('Passwords do not match.');
        } elseif (strlen($password) < 8) {
            /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
            $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\ErrorStatus::class);
            $message->setTitle('Administrator user not created');
            $message->setMessage('Password must be at least eight characters long.');
        } else {
            $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
            $userExists = $connectionPool->getConnectionForTable('be_users')
                ->count(
                    'uid',
                    'be_users',
                    ['username' => $username]
                );

            if ($userExists) {
                /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
                $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\ErrorStatus::class);
                $message->setTitle('Administrator user not created');
                $message->setMessage('A user with username "' . $username . '" exists already.');
            } else {
                $hashedPassword = $this->getHashedPassword($password);
                $adminUserFields = [
                    'username' => $username,
                    'password' => $hashedPassword,
                    'admin' => 1,
                    'tstamp' => $GLOBALS['EXEC_TIME'],
                    'crdate' => $GLOBALS['EXEC_TIME']
                ];
                $connectionPool->getConnectionForTable('be_users')
                    ->insert('be_users', $adminUserFields);
                /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
                $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\OkStatus::class);
                $message->setTitle('Administrator created with username "' . $username . '".');
            }
        }

        return $message;
    }

    /**
     * Reset uc field of all be_users to empty string
     *
     * @return \TYPO3\CMS\Install\Status\StatusInterface
     */
    protected function resetBackendUserUc()
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('be_users')
            ->update('be_users')
            ->set('uc', '')
            ->execute();
        /** @var OkStatus $message */
        $message = GeneralUtility::makeInstance(OkStatus::class);
        $message->setTitle('Reset all backend users preferences');
        return $message;
    }

    /**
     * Clear processed files
     *
     * The sys_file_processedfile table is truncated and the physical files of local storages are deleted.
     *
     * @return \TYPO3\CMS\Install\Status\StatusInterface
     */
    protected function clearProcessedFiles()
    {
        $repository = GeneralUtility::makeInstance(ProcessedFileRepository::class);
        $failedDeletions = $repository->removeAll();
        if ($failedDeletions) {
            /** @var ErrorStatus $message */
            $message = GeneralUtility::makeInstance(ErrorStatus::class);
            $message->setTitle('Failed to delete ' . $failedDeletions . ' processed files. See TYPO3 log (by default typo3temp/var/logs/typo3_*.log)');
        } else {
            /** @var OkStatus $message */
            $message = GeneralUtility::makeInstance(OkStatus::class);
            $message->setTitle('Cleared processed files');
        }

        return $message;
    }

    /**
     * Get list of existing tables that could be truncated.
     *
     * @return array List of cleanable tables with name, description and number of rows
     */
    protected function getCleanableTableList()
    {
        $tableCandidates = [
            [
                'name' => 'be_sessions',
                'description' => 'Backend user sessions'
            ],
            [
                'name' => 'cache_md5params',
                'description' => 'Frontend redirects',
            ],
            [
                'name' => 'fe_sessions',
                'description' => 'Frontend user sessions',
            ],
            [
                'name' => 'sys_history',
                'description' => 'Tracking of database record changes through TYPO3 backend forms',
            ],
            [
                'name' => 'sys_lockedrecords',
                'description' => 'Record locking of backend user editing',
            ],
            [
                'name' => 'sys_log',
                'description' => 'General log table',
            ],
            [
                'name' => 'sys_preview',
                'description' => 'Workspace preview links',
            ],
            [
                'name' => 'tx_extensionmanager_domain_model_extension',
                'description' => 'List of TER extensions',
            ],
            [
                'name' => 'tx_rsaauth_keys',
                'description' => 'Login process key storage'
            ],
        ];

        $tables = [];
        foreach ($tableCandidates as $candidate) {
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable($candidate['name']);
            if ($connection->getSchemaManager()->tablesExist([$candidate['name']])) {
                $candidate['rows'] = $connection->count(
                    '*',
                    $candidate['name'],
                    []
                );
                $tables[] = $candidate;
            }
        }
        return $tables;
    }

    /**
     * Truncate selected tables
     *
     * @return \TYPO3\CMS\Install\Status\StatusInterface
     */
    protected function clearSelectedTables()
    {
        $clearedTables = [];
        if (isset($this->postValues['values']) && is_array($this->postValues['values'])) {
            foreach ($this->postValues['values'] as $tableName => $selected) {
                if ($selected == 1) {
                    GeneralUtility::makeInstance(ConnectionPool::class)
                        ->getConnectionForTable($tableName)
                        ->truncate($tableName);
                    $clearedTables[] = $tableName;
                }
            }
        }
        if (!empty($clearedTables)) {
            /** @var OkStatus $message */
            $message = GeneralUtility::makeInstance(OkStatus::class);
            $message->setTitle('Cleared tables');
            $message->setMessage('List of cleared tables: ' . implode(', ', $clearedTables));
        } else {
            /** @var InfoStatus $message */
            $message = GeneralUtility::makeInstance(InfoStatus::class);
            $message->setTitle('No tables selected to clear');
        }
        return $message;
    }

    /**
     * Data for the typo3temp/ deletion view
     *
     * @return array Data array
     */
    protected function getTypo3TempStatistics()
    {
        $data = [];
        $pathTypo3Temp = PATH_site . 'typo3temp/';
        $postValues = $this->postValues['values'];

        $condition = '0';
        if (isset($postValues['condition'])) {
            $condition = $postValues['condition'];
        }
        $numberOfFilesToDelete = 0;
        if (isset($postValues['numberOfFiles'])) {
            $numberOfFilesToDelete = $postValues['numberOfFiles'];
        }
        $subDirectory = '';
        if (isset($postValues['subDirectory'])) {
            $subDirectory = $postValues['subDirectory'];
        }

        // Run through files
        $fileCounter = 0;
        $deleteCounter = 0;
        $criteriaMatch = 0;
        $timeMap = ['day' => 1, 'week' => 7, 'month' => 30];
        $directory = @dir($pathTypo3Temp . $subDirectory);
        if (is_object($directory)) {
            while ($entry = $directory->read()) {
                $absoluteFile = $pathTypo3Temp . $subDirectory . '/' . $entry;
                if (@is_file($absoluteFile)) {
                    $ok = false;
                    $fileCounter++;
                    if ($condition) {
                        if (MathUtility::canBeInterpretedAsInteger($condition)) {
                            if (filesize($absoluteFile) > $condition * 1024) {
                                $ok = true;
                            }
                        } else {
                            if (fileatime($absoluteFile) < $GLOBALS['EXEC_TIME'] - (int)$timeMap[$condition] * 60 * 60 * 24) {
                                $ok = true;
                            }
                        }
                    } else {
                        $ok = true;
                    }
                    if ($ok) {
                        $hashPart = substr(basename($absoluteFile), -14, 10);
                        // This is a kind of check that the file being deleted has a 10 char hash in it
                        if (
                            !preg_match('/[^a-f0-9]/', $hashPart)
                            || substr($absoluteFile, -6) === '.cache'
                            || substr($absoluteFile, -4) === '.tbl'
                            || substr($absoluteFile, -4) === '.css'
                            || substr($absoluteFile, -3) === '.js'
                            || substr($absoluteFile, -5) === '.gzip'
                            || substr(basename($absoluteFile), 0, 8) === 'installTool'
                        ) {
                            if ($numberOfFilesToDelete && $deleteCounter < $numberOfFilesToDelete) {
                                $deleteCounter++;
                                unlink($absoluteFile);
                            } else {
                                $criteriaMatch++;
                            }
                        }
                    }
                }
            }
            $directory->close();
        }
        $data['numberOfFilesMatchingCriteria'] = $criteriaMatch;
        $data['numberOfDeletedFiles'] = $deleteCounter;

        if ($deleteCounter > 0) {
            $message = GeneralUtility::makeInstance(OkStatus::class);
            $message->setTitle('Deleted ' . $deleteCounter . ' files from typo3temp/' . $subDirectory . '/');
            $this->actionMessages[] = $message;
        }

        $data['selectedCondition'] = $condition;
        $data['numberOfFiles'] = $numberOfFilesToDelete;
        $data['selectedSubDirectory'] = $subDirectory;

        // Set up sub directory data
        $data['subDirectories'] = [
            '' => [
                'name' => '',
                'filesNumber' => count(GeneralUtility::getFilesInDir($pathTypo3Temp)),
            ],
        ];
        $directories = dir($pathTypo3Temp);
        if (is_object($directories)) {
            while ($entry = $directories->read()) {
                if (is_dir($pathTypo3Temp . $entry) && $entry !== '..' && $entry !== '.') {
                    $data['subDirectories'][$entry]['name'] = $entry;
                    $data['subDirectories'][$entry]['filesNumber'] = count(GeneralUtility::getFilesInDir($pathTypo3Temp . $entry));
                    $data['subDirectories'][$entry]['selected'] = false;
                    if ($entry === $data['selectedSubDirectory']) {
                        $data['subDirectories'][$entry]['selected'] = true;
                    }
                }
            }
        }
        $data['numberOfFilesInSelectedDirectory'] = $data['subDirectories'][$data['selectedSubDirectory']]['filesNumber'];

        return $data;
    }

    /**
     * Execute database migration
     *
     * @return array<\TYPO3\CMS\Install\Status\StatusInterface>
     */
    protected function databaseAnalyzerExecute()
    {
        $messages = [];

        // Early return in case no update was selected
        if (empty($this->postValues['values'])) {
            /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
            $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\WarningStatus::class);
            $message->setTitle('No database changes selected');
            $messages[] = $message;
            return $messages;
        }

        $sqlReader = GeneralUtility::makeInstance(SqlReader::class);
        $sqlStatements = $sqlReader->getCreateTableStatementArray($sqlReader->getTablesDefinitionString());
        $schemaMigrationService = GeneralUtility::makeInstance(SchemaMigrator::class);

        $statementHashesToPerform = $this->postValues['values'];

        $results = $schemaMigrationService->migrate($sqlStatements, $statementHashesToPerform);

        // Create error flash messages if any
        foreach ($results as $errorMessage) {
            $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\ErrorStatus::class);
            $message->setTitle('Database update failed');
            $message->setMessage('Error: ' . $errorMessage);
            $messages[] = $message;
        }

        $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\OkStatus::class);
        $message->setTitle('Executed database updates');
        $messages[] = $message;

        return $messages;
    }

    /**
     * "Compare" action of analyzer
     *
     * @return \TYPO3\CMS\Install\Status\StatusInterface
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \InvalidArgumentException
     * @throws \TYPO3\CMS\Core\Database\Schema\Exception\StatementException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Core\Database\Schema\Exception\UnexpectedSignalReturnValueTypeException
     * @throws \RuntimeException
     */
    protected function databaseAnalyzerAnalyze()
    {
        $databaseAnalyzerSuggestion = [];

        $sqlReader = GeneralUtility::makeInstance(SqlReader::class);
        $sqlStatements = $sqlReader->getCreateTableStatementArray($sqlReader->getTablesDefinitionString());
        $schemaMigrationService = GeneralUtility::makeInstance(SchemaMigrator::class);

        $addCreateChange = $schemaMigrationService->getUpdateSuggestions($sqlStatements);
        // Aggregate the per-connection statements into one flat array
        $addCreateChange = array_merge_recursive(...array_values($addCreateChange));

        if (!empty($addCreateChange['create_table'])) {
            $databaseAnalyzerSuggestion['addTable'] = [];
            foreach ($addCreateChange['create_table'] as $hash => $statement) {
                $databaseAnalyzerSuggestion['addTable'][$hash] = [
                    'hash' => $hash,
                    'statement' => $statement,
                ];
            }
        }
        if (!empty($addCreateChange['add'])) {
            $databaseAnalyzerSuggestion['addField'] = [];
            foreach ($addCreateChange['add'] as $hash => $statement) {
                $databaseAnalyzerSuggestion['addField'][$hash] = [
                    'hash' => $hash,
                    'statement' => $statement,
                ];
            }
        }
        if (!empty($addCreateChange['change'])) {
            $databaseAnalyzerSuggestion['change'] = [];
            foreach ($addCreateChange['change'] as $hash => $statement) {
                $databaseAnalyzerSuggestion['change'][$hash] = [
                    'hash' => $hash,
                    'statement' => $statement,
                ];
                if (isset($addCreateChange['change_currentValue'][$hash])) {
                    $databaseAnalyzerSuggestion['change'][$hash]['current'] = $addCreateChange['change_currentValue'][$hash];
                }
            }
        }

        // Difference from current to expected
        $dropRename = $schemaMigrationService->getUpdateSuggestions($sqlStatements, true);
        // Aggregate the per-connection statements into one flat array
        $dropRename = array_merge_recursive(...array_values($dropRename));
        if (!empty($dropRename['change_table'])) {
            $databaseAnalyzerSuggestion['renameTableToUnused'] = [];
            foreach ($dropRename['change_table'] as $hash => $statement) {
                $databaseAnalyzerSuggestion['renameTableToUnused'][$hash] = [
                    'hash' => $hash,
                    'statement' => $statement,
                ];
                if (!empty($dropRename['tables_count'][$hash])) {
                    $databaseAnalyzerSuggestion['renameTableToUnused'][$hash]['count'] = $dropRename['tables_count'][$hash];
                }
            }
        }
        if (!empty($dropRename['change'])) {
            $databaseAnalyzerSuggestion['renameTableFieldToUnused'] = [];
            foreach ($dropRename['change'] as $hash => $statement) {
                $databaseAnalyzerSuggestion['renameTableFieldToUnused'][$hash] = [
                    'hash' => $hash,
                    'statement' => $statement,
                ];
            }
        }
        if (!empty($dropRename['drop'])) {
            $databaseAnalyzerSuggestion['deleteField'] = [];
            foreach ($dropRename['drop'] as $hash => $statement) {
                $databaseAnalyzerSuggestion['deleteField'][$hash] = [
                    'hash' => $hash,
                    'statement' => $statement,
                ];
            }
        }
        if (!empty($dropRename['drop_table'])) {
            $databaseAnalyzerSuggestion['deleteTable'] = [];
            foreach ($dropRename['drop_table'] as $hash => $statement) {
                $databaseAnalyzerSuggestion['deleteTable'][$hash] = [
                    'hash' => $hash,
                    'statement' => $statement,
                ];
                if (!empty($dropRename['tables_count'][$hash])) {
                    $databaseAnalyzerSuggestion['deleteTable'][$hash]['count'] = $dropRename['tables_count'][$hash];
                }
            }
        }

        $this->view->assign('databaseAnalyzerSuggestion', $databaseAnalyzerSuggestion);

        /** @var $message \TYPO3\CMS\Install\Status\StatusInterface */
        $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\OkStatus::class);
        $message->setTitle('Analyzed current database');

        return $message;
    }

    /**
     * Dumps Extension Autoload Information
     *
     * @return \TYPO3\CMS\Install\Status\StatusInterface
     */
    protected function dumpAutoload(): \TYPO3\CMS\Install\Status\StatusInterface
    {
        if (Bootstrap::usesComposerClassLoading()) {
            $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\NoticeStatus::class);
            $message->setTitle('Skipped generating additional class loading information in composer mode.');
        } else {
            ClassLoadingInformation::dumpClassLoadingInformation();
            $message = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Status\OkStatus::class);
            $message->setTitle('Successfully dumped class loading information for extensions.');
        }
        return $message;
    }
}
