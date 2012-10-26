<?php
require_once 'phing/Task.php';
require_once 'phing/BuildException.php';

class PHPLocTask extends Task
{
    protected $suffixesToCheck = null;
    protected $acceptedReportTypes = null;
    protected $reportDirectory = null;
    protected $reportType = null;
    protected $countTests = null;
    protected $fileToCheck = null;
    protected $filesToCheck = null;
    protected $reportFileName = null;
    protected $fileSets = null;
    
    public function init() {
        $this->suffixesToCheck = array('php');
        $this->acceptedReportTypes = array('cli', 'txt', 'xml', 'csv');
        $this->reportType = 'cli';
        $this->reportFileName = 'phploc-report';
        $this->fileSets = array();
        $this->filesToCheck = array();
        $this->countTests = false;
    }
    public function setSuffixes($suffixListOrSingleSuffix) {
        if (stripos($suffixListOrSingleSuffix, ',')) {
            $suffixes = explode(',', $suffixListOrSingleSuffix);
            $this->suffixesToCheck = array_map('trim', $suffixes);
        } else {
            array_push($this->suffixesToCheck, trim($suffixListOrSingleSuffix));
        }
    }
    public function setFile(PhingFile $file) {
        $this->fileToCheck = trim($file);
    }

    public function setCountTests($countTests) {
        $this->countTests = (bool) $countTests;
    }

    public function createFileSet() {
        $num = array_push($this->fileSets, new FileSet());
        return $this->fileSets[$num - 1];
    }
    public function setReportType($type) {
        $this->reportType = trim($type);
    }
    public function setReportName($name) {
        $this->reportFileName = trim($name);
    }
    public function setReportDirectory($directory) {
        $this->reportDirectory = trim($directory);
    }
    public function main() {
        
        /**
         * Find PHPLoc
         */
        
        if (!@include_once('SebastianBergmann/PHPLOC/Analyser.php')) {
            throw new BuildException(
                'PHPLocTask depends on PHPLoc being installed '
                . 'and on include_path.',
                $this->getLocation()
            );
        }
        
        $this->_validateProperties();
        if (!is_null($this->reportDirectory) && !is_dir($this->reportDirectory)) {
            $reportOutputDir = new PhingFile($this->reportDirectory);
            $logMessage = "Report output directory doesn't exist, creating: " 
                . $reportOutputDir->getAbsolutePath() . '.';
            $this->log($logMessage);
            $reportOutputDir->mkdirs();
        }
        if ($this->reportType !== 'cli') {
            $this->reportFileName.= '.' . trim($this->reportType);
        }
        if (count($this->fileSets) > 0) {
            $project = $this->getProject();
            foreach ($this->fileSets as $fileSet) {
                $directoryScanner = $fileSet->getDirectoryScanner($project);
                $files = $directoryScanner->getIncludedFiles();
                $directory = $fileSet->getDir($this->project)->getPath();
                foreach ($files as $file) {
                    if ($this->isFileSuffixSet($file)) {
                        $this->filesToCheck[] = $directory . DIRECTORY_SEPARATOR 
                            . $file;
                    }
                }
            }
            $this->filesToCheck = array_unique($this->filesToCheck);
        }
        $this->runPhpLocCheck();
    }
    private function _validateProperties() {
        if (!isset($this->fileToCheck) && count($this->fileSets) === 0) {
            $exceptionMessage = "Missing either a nested fileset or the "
                . "attribute 'file' set.";
            throw new BuildException($exceptionMessage);
        }
        if (count($this->suffixesToCheck) === 0) {
            throw new BuildException("No file suffix defined.");
        }
        if (is_null($this->reportType)) {
            throw new BuildException("No report type defined.");
        }
        if (!is_null($this->reportType) && 
            !in_array($this->reportType, $this->acceptedReportTypes)) {
            throw new BuildException("Unaccepted report type defined.");
        }
        if (!is_null($this->fileToCheck) && !file_exists($this->fileToCheck)) {
            throw new BuildException("File to check doesn't exist.");
        }
        if ($this->reportType !== 'cli' && is_null($this->reportDirectory)) {
            throw new BuildException("No report output directory defined.");
        }
        if (count($this->fileSets) > 0 && !is_null($this->fileToCheck)) {
            $exceptionMessage = "Either use a nested fileset or 'file' " 
                . "attribute; not both.";
            throw new BuildException($exceptionMessage);
        }
        if (!is_bool($this->countTests)) {
            $exceptionMessage = "'countTests' attribute has no boolean value";
            throw new BuildException($exceptionMessage);
        }
        if (!is_null($this->fileToCheck)) {
            if (!$this->isFileSuffixSet($file)) {
                $exceptionMessage = "Suffix of file to check is not defined in"
                    . " 'suffixes' attribute.";
                throw new BuildException($exceptionMessage);
            }
        }
    }
    protected function isFileSuffixSet($filename) {
        $pathinfo = pathinfo($filename);
        $fileSuffix = $pathinfo['extension'];
        return in_array($fileSuffix, $this->suffixesToCheck);
    }
    protected function runPhpLocCheck() {
        $files = $this->getFilesToCheck();
        $result = $this->getCountForFiles($files); 

        if ($this->reportType === 'cli' || $this->reportType === 'txt') {
            require_once 'PHPLOC/TextUI/ResultPrinter/Text.php';
            $printer = new PHPLOC_TextUI_ResultPrinter_Text();
            ob_start();
            $printer->printResult($result, $this->countTests); 
            $result = ob_get_contents(); 
            ob_end_clean();
            if ($this->reportType === 'txt') {
                file_put_contents($this->reportDirectory 
                    . DIRECTORY_SEPARATOR . $this->reportFileName, $result);
                $reportDir = new PhingFile($this->reportDirectory);
                $logMessage = "Writing report to: " 
                    . $reportDir->getAbsolutePath() . DIRECTORY_SEPARATOR 
                        . $this->reportFileName;
                $this->log($logMessage);
            } else {
                $this->log("\n" . $result);
            }
        } elseif ($this->reportType === 'xml' || $this->reportType === 'csv') {
            $printerClass = sprintf('PHPLOC_TextUI_ResultPrinter_%s', strtoupper($this->reportType)) ;            
            $printerClassFile = str_replace('_', DIRECTORY_SEPARATOR, $printerClass) . '.php';
            require_once $printerClassFile;
            
            $printer = new $printerClass();
            $reportDir = new PhingFile($this->reportDirectory);
            $logMessage = "Writing report to: " . $reportDir->getAbsolutePath()
                . DIRECTORY_SEPARATOR . $this->reportFileName;
            $this->log($logMessage);
            $printer->printResult($this->reportDirectory . DIRECTORY_SEPARATOR
                . $this->reportFileName, $result);
        }
    }
    protected function getFilesToCheck() {
        if (count($this->filesToCheck) > 0) {
            $files = array();
            foreach ($this->filesToCheck as $file) {
                $files[] = new SPLFileInfo($file);
            }
        } elseif (!is_null($this->fileToCheck)) {
            $files = array(new SPLFileInfo($this->fileToCheck));
        }
        return $files;
    }
    protected function getCountForFiles($files) {
        $analyser = new Analyser(); 
        return $analyser->countFiles($files, $this->countTests);
    }
}