<?php
require_once dirname(__FILE__) . '/curl.php';

class Job 
{
	protected $logDir = '';
    protected $sites = array();
    protected $urls = array();
    
    protected $isRunning = false;
    protected $queue = array();
    protected $maxNumberOfWorkers = 5;
    protected $numberOfworkers = 0;
    protected $counter = 0;
    
    public function __construct(array $sites)
    {
        date_default_timezone_set('GMT');
        error_reporting(E_ALL ^ E_NOTICE);
        
        $this->sites = $sites;
        $this->downloadDir = dirname(__FILE__) . '/../download';
        $this->logDir = dirname(__FILE__) . '/../log';
    }
    
    public function setUp()
    {
    }
    
    public function tearDown()
    {
    }
    
    public function start()
    {
        $this->setUp();
        
        $this->isRunning = true;
        while ($this->isRunning) {
            $this->perform();
        }
        
        $this->tearDown();
    }

    public function stop()
    {
        $this->isRunning = false;
    }
    
    public function check() 
    {
        return true;
    }
    
    public function save($file, $data) 
    {
        file_put_contents($file, $data);
    }
    
    public function output($msg)
    {
        echo $msg . PHP_EOL;
    }
    
    public function readCsvFile($file, $delimeter="\t")
    {
    	if (! file_exists($file)) {
    		echo "File not found: " . $file . PHP_EOL;
    		exit(1);
    	}
    	
        $lines = array();
        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, $delimeter)) !== false) {
                $lines[] = $data;
            }
        }
        fclose($handle);
        return $lines;
    }
}
