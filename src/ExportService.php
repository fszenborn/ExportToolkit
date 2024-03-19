<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Elements\Bundle\ExportToolkitBundle;

use Elements\Bundle\ExportToolkitBundle\ExportService\Worker;
use Elements\Bundle\ExportToolkitBundle\Traits\ApiGatewayClientTrait;
use Elements\Bundle\ProcessManagerBundle\ExecutionTrait;
use Pimcore\Logger;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\Attribute\Required;

class ExportService implements LoggerAwareInterface
{
    use ExecutionTrait;
    use LoggerAwareTrait;
    use ApiGatewayClientTrait;

    /**
     * @var Worker[]
     */
    protected $workers;

    public function __construct()
    {
        $exporters = Configuration\Dao::getList();
        $this->workers = [];
        foreach ($exporters as $exporter) {
            $this->workers[$exporter->getName()] = new Worker($exporter);
        }
    }

    /**
     * Sets a logger.
     * @param LoggerInterface $logger
     */
    #[Required]
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setUpExport($objectHook = false, $hookType = 'save')
    {
        foreach ($this->workers as $workerName => $worker) {
            if ($worker->checkIfToConsider($objectHook, $hookType)) {
                $worker->setUpExport();
            }
        }
    }

    public function deleteFromExport(AbstractObject $object, $objectHook = false)
    {
        foreach ($this->workers as $workerName => $worker) {
            if ($worker->checkIfToConsider($objectHook, 'delete')) {
                if ($worker->checkClass($object)) {
                    $worker->deleteFromExport($object);
                } else {
                    Logger::info('do not delete from export - object ' . $object->getId() . ' for ' . $workerName . '.');
                }
            }
        }
    }

    public function updateExport(AbstractObject $object, $objectHook = false, $hookType = 'save')
    {
        foreach ($this->workers as $workerName => $worker) {
            if ($worker->checkIfToConsider($objectHook, $hookType)) {
                if ($worker->checkClass($object)) {
                    $worker->updateExport($object);
                } else {
                    Logger::info('do not update export object ' . $object->getId() . ' for ' . $workerName . '.');
                }
            }
        }
    }

    public function commitData($objectHook = false, $hookType = 'save')
    {
        foreach ($this->workers as $workerName => $worker) {
            if ($worker->checkIfToConsider($objectHook, $hookType)) {
                $worker->commitData();
            }
        }
    }

    public function executeExport($workerName = null)
    {
        if ($workerName) {
            $worker = $this->workers[$workerName];
            $this->doExecuteExport($worker, $workerName);
        } else {
            foreach ($this->workers as $workerName => $worker) {
                $this->doExecuteExport($worker, $workerName);
            }
        }
    }

    protected function doExecuteExport(Worker $worker, $workerName)
    {
        $this->initProcessManager(null, ['name' => $workerName, 'autoCreate' => true]);

        $monitoringItem = $this->getMonitoringItem();
        $monitoringItem->getLogger()->info('export-toolkit-' . $workerName);

        $worker->setLogger($monitoringItem->getLogger());
        $worker->setClient($this->getClient());

        $this->logger->info('export-toolkit-' . $workerName);

        //step 1 - setting up export
        $monitoringItem->setTotalSteps(3)->setCurrentStep(1)->setMessage("Setting up export $workerName")->save();

        $limit = (int)$worker->getWorkerConfig()->getConfiguration()->general->limit;

        $page = $i = 0;
        $pageSize = 100;
        $count = $pageSize;

        $totalObjectCount = $worker->getObjectList()->count();
        if ($pageSize > $totalObjectCount) {
            $pageSize = $totalObjectCount;
        }

        $worker->setUpExport(false);

        //step 2 - exporting data
        $monitoringItem->setCurrentStep(2)->setMessage('Starting Exporting Data')->setTotalWorkload($totalObjectCount)->save();

        while ($count > 0) {
            $this->logger->info('export-toolkit-' . $workerName . ' =========================');
            $this->logger->info('export-toolkit-' . $workerName . " Page $workerName: $page");
            $this->logger->info('export-toolkit-' . $workerName . ' =========================');

            $objects = $worker->getObjectList();
            $offset = $page * $pageSize;
            $objects->setOffset($offset);
            $objects->setLimit($pageSize);

            $items = $objects->load();
            $monitoringItem->setCurrentWorkload(($offset) ?: 1)->setDefaultProcessMessage(isset($items[0]) ? $items[0]->getClassName() : 'Items')->save();
            foreach ($items as $object) {
                $this->logger->info('export-toolkit-' . $workerName . ' Updating object ' . $object->getId());
                $monitoringItem->getLogger()->debug('Updating object ' . $object->getId());

                if ($worker->checkClass($object)) {
                    $worker->updateExport($object);
                } else {
                    $monitoringItem->getLogger()->debug('do not update export object ' . $object->getId() . ' for ' . $workerName . '.');
                    $this->logger->info('export-toolkit-' . $workerName . ' do not update export object ' . $object->getId() . ' for ' . $workerName . '.');
                }
                $i++;
                if ($limit && ($i == $limit)) {
                    break 2;
                }
            }
            $page++;
            $count = count($objects->getObjects());

            $monitoringItem->setCurrentWorkload($page * $pageSize)->setTotalWorkload($totalObjectCount)->save();
            $monitoringItem->getLogger()->info("Process Export $workerName, finished page: $page");

            \Pimcore::collectGarbage();
        }

        $monitoringItem->setWorkloadCompleted()->save();

        //step 3 - committing data
        $monitoringItem->setCurrentStep(3)->setMessage('Committing Data')->save();

        $worker->commitData();

        $monitoringItem->setMessage('Job finished')->setCompleted();
    }
}
