<?php


class ExportToolkit_ConfigController extends Pimcore_Controller_Action_Admin {

    public function listAction() {
        $list = ExportToolkit_Configuration::getList();

        $dataArray = array();
        foreach($list as $config) {
            if (\Pimcore\Tool\Admin::isExtJS6()) {
                $dataArray[] = array("id" => $config->getName(),
                    "text" => $config->getName(),
                    "expandable" => false,
                    "leaf" => true

                );
            } else {
                $dataArray[] = array("id" => $config->getName(), "text" => $config->getName());
            }
        }

        $this->_helper->json($dataArray);
    }

    public function deleteAction() {
        try {
            $name = $this->getParam("name");

            $config = ExportToolkit_Configuration::getByName($name);
            if(empty($config)) {
                throw new Exception("Name does not exist.");
            }

            $config->delete();

            $this->_helper->json(["success" => true]);
        } catch(Exception $e) {
            $this->_helper->json(["success" => false, "message" => $e->getMessage()]);
        }
    }


    public function addAction() {
        try {
            $name = $this->getParam("name");

            $config = ExportToolkit_Configuration::getByName($name);
            if(!empty($config)) {
                throw new Exception("Name already exists.");
            }

            $config = new ExportToolkit_Configuration($name);
            $config->save();

            $this->_helper->json(["success" => true, "name" => $name]);
        } catch(Exception $e) {
            $this->_helper->json(["success" => false, "message" => $e->getMessage()]);
        }
    }

    public function cloneAction() {
        try {
            $name = $this->getParam("name");

            $config = ExportToolkit_Configuration::getByName($name);
            if(!empty($config)) {
                throw new Exception("Name already exists.");
            }

            $originalName = $this->getParam("originalName");
            $originalConfig = ExportToolkit_Configuration::getByName($originalName);
            if (!$originalConfig) {
                throw new Exception("Configuration not found");
            }
            $originalConfig->setName($name);
            $originalConfig->save($name);

            $this->_helper->json(["success" => true, "name" => $name]);
        } catch(Exception $e) {
            $this->_helper->json(["success" => false, "message" => $e->getMessage()]);
        }
    }

    protected function getCliCommand($configName){
        return Pimcore_Tool_Console::getPhpCli() . " " . PIMCORE_DOCUMENT_ROOT.'/pimcore/cli/console.php export-toolkit:export --config-name="' . $configName.'"';
    }

    public function getAction() {
        $name = $this->getParam("name");

        $configuration = ExportToolkit_Configuration::getByName($name);
        if(empty($configuration)) {
            throw new Exception("Name does not exist.");
        }

        if ($configuration && $configuration->configuration->general->executor) {
            /** @var  $className ExportToolkit_ExportService_IExecutor */
            $className = $configuration->configuration->general->executor;
            $cli = $className::getCli($name, null);
        } else {
            $cli = $this->getCliCommand($configuration->getName());
        }

        $this->_helper->json(
            [
                "name" => $configuration->getName(),
                "execute" => $cli,
                "configuration" => $configuration->getConfiguration()
            ]
        );
    }

    public function saveAction() {

        try {
            $data = $this->getParam("data");
            $dataDecoded = json_decode($data);

            $name = $dataDecoded->general->name;
            $config = ExportToolkit_Configuration::getByName($name);
            $config->setConfiguration($dataDecoded);
            $config->save();

            $this->_helper->json(["success" => true]);
        } catch(Exception $e) {
            $this->_helper->json(["success" => false, "message" => $e->getMessage()]);
        }
    }


    private function loadClasses() {


        $config = ExportToolkit_Helper::getPluginConfig()->toArray();

        $classmap = PIMCORE_CONFIGURATION_DIRECTORY . "/autoload-classmap.php";

        if ($config["classes"]["override"]) {

            $classes = array();
            $classlist = $config["classes"]["classlist"];
            if ($classlist) {
                foreach(preg_split("/((\r?\n)|(\r\n?))/", $classlist) as $line){
                    if ($line) {
                        $classes[] = $line;
                    }
                }
            }
        } else {
            if(file_exists($classmap)) {
                $classMapAutoLoader = new Zend_Loader_ClassMapAutoloader(array($classmap));
                $classes = array_keys($classMapAutoLoader->getAutoloadMap());
            } else {
                $classes = get_declared_classes();
            }
        }


        $whiteListedClasses = $classes;
        $blackListedClasses = ['/^Whoops/i','/^Zend_/i', '/^Sabre_/i', '/^PEAR_/i', '/^VersionControl_/i', '/^Google_/i','/^PHPExcel_/i'];



        $additionalBlacklisted = $config["classes"]["blacklist"];
        if ($additionalBlacklisted) {
            foreach(preg_split("/((\r?\n)|(\r\n?))/", $additionalBlacklisted) as $line){
                if ($line) {
                    $blackListedClasses[] = $line;
                }
            }
        }
        foreach($blackListedClasses as $blackList) {
            $whiteListedClasses = preg_grep($blackList, $whiteListedClasses,  PREG_GREP_INVERT);
        }

        return $whiteListedClasses;
    }


    public function getClassesAction() {

        $classes = $this->loadClasses();

        if($this->getParam("type") == "attribute-cluster-interpreter") {

            $implementsIConfig = array();
            foreach($classes as $class) {
                try {
                    $reflect = new ReflectionClass($class);
                    if (is_subclass_of($class, "ExportToolkit_ExportService_AttributeClusterInterpreter_Abstract") && $reflect->isInstantiable()) {
                        $implementsIConfig[] = array($class);
                    }
                } catch (Exception $e) {}
            }

            $this->_helper->json($implementsIConfig);

        } else if($this->getParam("type") == "export-filter") {

            $implementsIConfig = array();
            foreach($classes as $class) {
                try {
                    $reflect = new ReflectionClass($class);
                    if ($reflect->implementsInterface('ExportToolkit_ExportService_IFilter') && $reflect->isInstantiable()) {
                        $implementsIConfig[] = array($class);
                    }
                } catch (Exception $e) {}
            }

            $this->_helper->json($implementsIConfig);

        } else if($this->getParam("type") == "export-conditionmodificator") {

            $implementsIConfig = array();
            foreach($classes as $class) {
                try {
                    $reflect = new ReflectionClass($class);
                    if($reflect->implementsInterface('ExportToolkit_ExportService_IConditionModificator') && $reflect->isInstantiable()) {
                        $implementsIConfig[] = array($class);
                    }
                } catch (Exception $e) {}
            }

            $this->_helper->json($implementsIConfig);

        } else if($this->getParam("type") == "attribute-getter") {

            $implementsIConfig = array();
            foreach($classes as $class) {
                try {
                    $reflect = new ReflectionClass($class);
                    if ($reflect->implementsInterface('ExportToolkit_ExportService_IGetter') && $reflect->isInstantiable()) {
                        $implementsIConfig[] = array($class);
                    }
                } catch (Exception $e) {}
            }

            $this->_helper->json($implementsIConfig);

        } else if($this->getParam("type") == "attribute-interpreter") {

            $implementsIConfig = array();
            foreach($classes as $class) {
                try {
                    $reflect = new ReflectionClass($class);
                    if ($reflect->implementsInterface('ExportToolkit_ExportService_IInterpreter') && $reflect->isInstantiable()) {
                        $implementsIConfig[] = array($class);
                    }
                } catch (Exception $e) {}
            }

            $this->_helper->json($implementsIConfig);

        } else {
            throw new Exception("unknown class type");
        }

    }


    public function clearCacheAction() {

        Pimcore_Model_Cache::clearTag("exporttoolkit");
        exit;

    }


    public function executeExportAction() {

        $workername = $this->getParam("name");
        $config = ExportToolkit_Configuration::getByName($workername);

        if ($config && $config->configuration->general->executor) {
            /** @var  $className ExportToolkit_ExportService_IExecutor */
            $className = $config->configuration->general->executor;
            try {
                $className::execute($workername, null);
            } catch (Exception $e) {
                $this->_helper->json(["success" => false, "message" => $e->getMessage()]);
            }
        } else {
            $lockkey = "exporttoolkit_" . $workername;
            if (Tool_Lock::isLocked($lockkey, 3 * 60 * 60)) { //lock for 3h
                $this->_helper->json(["success" => false]);
            }
            $cmd = $this->getCliCommand($workername);
            Logger::info($cmd);
            Pimcore_Tool_Console::execInBackground($cmd, PIMCORE_LOG_DIRECTORY . DIRECTORY_SEPARATOR . "exporttoolkit-output.log");
        }

        $this->_helper->json(["success" => true]);
    }


    public function isExportRunningAction() {
        $workername = $this->getParam("name");
        $lockkey = "exporttoolkit_" . $workername;

        if(Tool_Lock::isLocked($lockkey, 3 * 60 * 60)) { //lock for 3h
            $this->_helper->json(["success" => true, "locked" => true]);
        } else {
            $this->_helper->json(["success" => true, "locked" => false]);
        }
    }

}
