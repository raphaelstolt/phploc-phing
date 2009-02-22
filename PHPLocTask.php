<?php
require_once 'phing/Task.php';
require_once 'phing/BuildException.php';
require_once 'PHPLOC/Analyser.php';
require_once 'PHPLOC/Util/FilterIterator.php';
require_once 'PHPLOC/TextUI/ResultPrinter.php';

class PHPLocTask extends Task
{
    protected $suffixesToCheck = null;
    protected $acceptedReportTypes = null;
    protected $reportDirectory = null;
    protected $reportType = null;
    protected $fileToCheck = null;
    protected $filesToCheck = null;
    protected $reportFileName = null;
    protected $fileSets = null;
    
    public function init() {
        $this->suffixesToCheck = array('php');
        $this->acceptedReportTypes = array('cli', 'txt', 'xml');
        $this->reportType = 'cli';
        $this->reportFileName = 'phploc-report';
        $this->fileSets = array();
        $this->filesToCheck = array();
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
        if (!is_null($this->reportDirectory) && !is_dir($this->reportDirectory)) {
            $reportOutputDir = new PhingFile($this->reportDirectory);
            $logMessage = "Report output directory does't exist, creating: " 
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
        if (!is_null($this->fileToCheck)) {
            if (!$this->isFileSuffixSet($file)) {
                $exceptionMessage = "Suffix of file to check is not defined in"
                    . " 'suffixes' attribute.";
                throw new BuildException($exceptionMessage);
            }
        }
        $this->runPhpLocCheck();
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
            $printer = new PHPLOC_TextUI_ResultPrinter;
            if ($this->reportType === 'txt') {
                ob_start();
                $printer->printResult($result);
                file_put_contents($this->reportDirectory 
                    . DIRECTORY_SEPARATOR . $this->reportFileName, 
                        ob_get_contents());
                ob_end_clean();
                $reportDir = new PhingFile($this->reportDirectory);
                $logMessage = "Writing report to: " 
                    . $reportDir->getAbsolutePath() . DIRECTORY_SEPARATOR 
                        . $this->reportFileName;
                $this->log($logMessage);
            } else {
                $printer->printResult($result);
            }
        } elseif ($this->reportType === 'xml') {
            $xml = $this->getResultAsXml($result);
            $reportDir = new PhingFile($this->reportDirectory);
            $logMessage = "Writing report to: " . $reportDir->getAbsolutePath()
                . DIRECTORY_SEPARATOR . $this->reportFileName;
            $this->log($logMessage);
            file_put_contents($this->reportDirectory . DIRECTORY_SEPARATOR
                . $this->reportFileName, $xml);
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
        $count = array('files' => 0, 'loc' => 0, 'cloc' => 0, 'ncloc' => 0,
            'eloc' => 0, 'interfaces' => 0, 'classes' => 0, 'functions' => 0);
        $directories = array();

        foreach ($files as $file) {
            $directory = $file->getPath();
            if (!isset($directories[$directory])) {
                $directories[$directory] = TRUE;
            }          
            PHPLOC_Analyser::countFile($file->getPathName(), $count);
        }
        
        if (!function_exists('parsekit_compile_file')) {
            unset($count['eloc']);
        }
        $count['directories'] = count($directories) - 1;
        return $count;
    }
    protected function getResultAsXml($result) {        
        $newline = "\n";
        $newlineWithSpaces = sprintf("\n%4s",'');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml.= $newline . '<phploc>'; 
        
        if ($result['directories'] > 0) {
            $xml.= $newlineWithSpaces . '<directories>' . $result['directories'] . '</directories>';
            $xml.= $newlineWithSpaces . '<files>' . $result['files'] . '</files>';
        }
        $xml.= $newlineWithSpaces . '<loc>' . $result['loc'] . '</loc>';
        
        if (isset($result['eloc'])) {
            $xml.= $newlineWithSpaces . '<eloc>' . $result['eloc'] . '</eloc>';
        }
        $xml.= $newlineWithSpaces . '<cloc>' . $result['cloc'] . '</cloc>';
        $xml.= $newlineWithSpaces . '<ncloc>' . $result['ncloc'] . '</ncloc>';
        $xml.= $newlineWithSpaces . '<interfaces>' . $result['interfaces'] . '</interfaces>';
        $xml.= $newlineWithSpaces . '<classes>' . $result['classes'] . '</classes>';
        $xml.= $newlineWithSpaces . '<methods>' . $result['functions'] . '</methods>' . $newline;
        $xml.= '</phploc>';
        return $xml;
    }
}