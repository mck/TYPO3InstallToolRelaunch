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
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\Service\OpcodeCacheService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Controller\Action;


/**
 * About page
 */
class Upgrade extends Action\AbstractAction
{
    /**
     * Executes the tool
     *
     * @return string Rendered content
     */
    protected function executeAction()
    {
        $opcodeCacheService = GeneralUtility::makeInstance(OpcodeCacheService::class);

        /** @var \TYPO3\CMS\Install\Service\CoreUpdateService $coreUpdateService */
        $coreUpdateService = GeneralUtility::makeInstance(\TYPO3\CMS\Install\Service\CoreUpdateService::class);
        $this->view
            ->assign('enableCoreUpdate', $coreUpdateService->isCoreUpdateEnabled());
        return $this->view->render();
    }
}
