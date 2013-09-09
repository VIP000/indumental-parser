<?php
require_once dirname(__FILE__) . '/job.php';
require_once dirname(__FILE__) . '/progress_bar.php';

class Download extends Job
{
	const FILENAME_URLS = 'urls.txt';
	const FILENAME_ERROR_LOG = 'download_errors.txt';

	protected $downloadDir = '';
	protected $urls = array();
    protected $queue = array();
    protected $errors = array();
    protected $redirects = array();
    protected $maxNumberOfWorkers = 5;
    
    public function setUp()
    {
    	$this->downloadDir = dirname(__FILE__) . '/../download';
    	$this->urls = $this->readCsvFile(self::FILENAME_URLS);
    	
        $errorFile = $this->logDir . DIRECTORY_SEPARATOR . self::FILENAME_ERROR_LOG;
        if (file_exists($errorFile)) {
            unlink($errorFile);
        }
        
        $this->output("Downloading files");
    }
    
    public function perform()
    {
        if ($this->numberOfworkers >= $this->maxNumberOfWorkers) {
            sleep(1);
            return;
        }
        
        $curl = new Curl($this);
        
        $numberOfRequests = $this->maxNumberOfWorkers - $this->numberOfworkers;
        for ($i = 0; $i < $numberOfRequests; $i ++) {
            if (! isset($this->urls[$this->counter])) {
                $this->stop();
                break;
            }
            $line = $this->urls[$this->counter];
            $this->counter = $this->counter + 1;            
            
            if (!isset($line[2]) || empty($line[2])) {
                continue;
            }
            $id = $line[0];
            $site = trim($line[1]);
            $url = trim($line[2]);
            
            $filename = $site . "_" . md5($url);
            if (file_exists($this->downloadDir . DIRECTORY_SEPARATOR . $filename)) {
                continue;
            }
            $this->queue[md5($url)] = array(
            	'id' 		=> $id,
            	'site' 		=> $site,
            	'url' 		=> $url,
            	'filename' 	=> $filename
            );
            
            $curl->add(new CurlRequest($url));
            $this->numberOfworkers += 1;
        }
        
        if ($curl->hasRequests()) {
            $curl->execute();
        }
    }
    
    public function asyncCallback($response, $info)
    {
        $this->numberOfworkers -= 1;
        $this->output('Workers: ' . $this->numberOfworkers);
        
        $statusCode = $info['http_code'];
        $url = trim($info['url']);
        
        $urlmd5 = md5($url);
        if (! isset($this->queue[$urlmd5])) {
        	$this->logError("-\t" . $statusCode . "\t" . $url);
        	return;
        }
        
        $request = $this->queue[$urlmd5];
        if ($statusCode == 200) {
            $this->save($this->downloadDir . DIRECTORY_SEPARATOR . $request['filename'], $response);
            $this->output($statusCode . ' - ' . $url);
        } else {
            $this->output('---> error: ' . $statusCode . ' - ' . $url);
            $this->logError($request['id'] . "\t" . $statusCode . "\t" . $url);
        }
    }
    
    public function processErrorLog()
    {
        $this->output("Counting files");
        
    	$this->downloadDir = '.' . DIRECTORY_SEPARATOR . 'download';
    	$this->urls = $this->readCsvFile(self::FILENAME_URLS);
        
        $mlist = array();
        foreach ($this->urls as $i) {
            if (!isset($i[2]) || empty($i[2])) {
                continue;
            }
            $filename = $i[1] . "_" . md5($i[2]);
            if (file_exists($this->downloadDir . DIRECTORY_SEPARATOR . $filename)) {
                continue;
            }
            $mlist[] = $i;            
        }
        
        $this->output(sprintf("Files missing: %s of %s", count($mlist), count($this->urls)));
        $this->output("Analyzing error log");
        
        $missing = array();
        foreach ($mlist as $m) {
        	$missing[$m[2]] = array('image_id'=>$m[0], 'image_path'=>$m[1]);
        }
        
        $this->errors = array();
        $errorStr = '';
        $errorFile = $this->logDir . DIRECTORY_SEPARATOR . self::FILENAME_ERROR_LOG;
        if (! file_exists($errorFile)) {
            $this->output('No errors found');
            return;
        }
        $errors = $this->readCsvFile($errorFile);
        $this->output('Total errors: ' . count($errors));
        
        $redirects = array();
        foreach ($errors as $e) {
            if (!isset($e[2]) || !isset($missing[$e[2]])) {
                continue;
            }
            
            $id = $e[0];
            $statusCode = $e[1];
            $url = $e[2];
            
            if (! in_array($statusCode, array(300, 301, 302, 303, 304, 305, 306, 307))) {
            	$this->errors[$id] = $url;
                $errorStr .= $id."\t".$statusCode."\t".$url . PHP_EOL;
                continue;
            }
            
            $filename = $missing[$url]['image_path'] . "_" . md5($url);
            if (file_exists($this->downloadDir . DIRECTORY_SEPARATOR . $filename)) {
                continue;
            }
            $redirects[] = array('filename'=>$filename, 'status_code'=>$statusCode, 'url'=>$url) + $missing[$url];
        }
        
        $countRedirects = count($redirects);
        $this->output('Total redirects: ' . $countRedirects);
        if ($countRedirects < 1) {
        	return;
        }
        
        ProgressBar::start($countRedirects);
        
        $this->queue = array('request'=>null);
        $curl = new Curl($this, false);
        foreach ($redirects as $r) {
        	echo ProgressBar::next();
        	
            $this->queue['request'] = $r;
            $curl->add(new CurlRequest($r['url']));            
            $curl->setOption(CURLOPT_FOLLOWLOCATION, true);
            $curl->setOption(CURLOPT_MAXREDIRS, 3);
            $curl->execute();
        }
        
        echo ProgressBar::finish();
        $this->output("Disabling images");
        
        $queries = array();
        foreach ($this->errors as $id => $url) {
        	$queries[] = "UPDATE psg_images SET image_active = 0 WHERE image_id = " . $id . ";";
        }
        $this->executeQueries($queries);
    }
    
    public function callback($response, $info)
    {
        $url = trim($info['url']);
        $statusCode = $info['http_code'];
        
        $request = $this->queue['request'];
        $filename = $request['image_path'] . "_" . md5($request['url']);
        $id =  $request['image_id'];
        
        $requestId = $request['image_path'] . "_" . md5($url);
        if (isset($this->redirects[$requestId])) {
            unlink($this->downloadDir . DIRECTORY_SEPARATOR . $this->redirects[$requestId]);
            unset($this->redirects[$requestId]);
            
            // add to error list
            $this->errors[$id] = $url;
        } else {
            $this->redirects[$requestId] = $filename;
            $filename = $request['image_path'] . "_" . md5($request['url']);        
            $this->save($this->downloadDir . DIRECTORY_SEPARATOR . $filename, $response);
            
            // remove from error list
            unset($this->errors[$id]);
        }
    }
    
    public function logError($msg)
    {
        $file = $this->logDir . DIRECTORY_SEPARATOR . self::FILENAME_ERROR_LOG;        
        if (! $handle = fopen($file, 'a+')) {
            return false;
        }
        
        $msg = trim($msg) . PHP_EOL;
        if (fwrite($handle, $msg) === false) {
            return false;
        }
        fclose($handle);
    }
    
    public function executeQueries($q)
    {
        echo "Connecting to database\n";
        $dbconn = mysql_connect('host', 'user', 'pass');
        if (!$dbconn) {
            exit_script("Could not connect to database: " . mysql_error());
        }
        
        mysql_select_db("database", $dbconn) OR exit_script(mysql_error());
        
    	foreach ($q as $query) {
    		$result = mysql_query($query, $dbconn);
    		if (!$result) {
    			exit_script("\nMySQL Error: " . mysql_error());
    		}
    		echo $query . PHP_EOL;
    	}
            
        echo "Done\n";    
        mysql_close($dbconn);
    }
}

