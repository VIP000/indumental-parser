<?php
require_once dirname(__FILE__) . '/lib/job_downloader.php';
require_once dirname(__FILE__) . '/lib/job_scraper.php';

echo PHP_EOL . '(1) Export URLs from database'; 
echo PHP_EOL . '(2) Download HTML files';
echo PHP_EOL . '(3) Process error log';
echo PHP_EOL . '(4) Scrape HTML files';
echo PHP_EOL . '(5) Generate SQL files';
echo PHP_EOL . '----------------------'; 
echo PHP_EOL . 'Enter to exit' . PHP_EOL;

$sites = get_sites();
$opt = get_user_input("\nSelect option: ");

if ('1' == $opt) {
    export_urls();
} else if ('2' == $opt) {
    $download = new Download($sites);
    $download->start();
} else if ('3' == $opt) {
    $download = new Download($sites);
    $download->processErrorLog();
} else if ('4' == $opt) {
    $scrape = new Scrape($sites);
    $scrape->start();
} else if ('5' == $opt) {
	create_sql_files($sites);
}

exit_script();

function get_user_input($msg = '') {
    if (! empty($msg)) {
        echo $msg;
    }
    $stdin = fopen('php://stdin', 'r');
    return trim(fgets($stdin));
}

function get_sites() {
    return include dirname(__FILE__) . '/config/sites.php';
}

function exit_script($code = 0) {
    get_user_input("Press any key to continue ...\n");
    exit($code);
}

function export_urls() {
    echo "Connecting to database\n";
    $dbconn = mysql_connect('host', 'user', 'pass');
    if (!$dbconn) {
        exit_script("Could not connect to database: " . mysql_error());
    }
    
    mysql_select_db("database", $dbconn) OR exit_script(mysql_error());
    
    $query = 'SELECT image_id, image_path, image_extra_01 FROM psg_images WHERE image_extra_01 <> "" AND (image_title = "Prenda" OR image_title = "") AND image_active = 1 ORDER BY image_id ASC';
    
    echo "Exporting data" . PHP_EOL;
    $result = mysql_query($query, $dbconn);
    if (!$result) {
        echo "\nMySQL Error: " . mysql_error() . "\n";
    }
    
    $data = '';
    while ($row = mysql_fetch_row($result)) {
        $line = '';
        $data .= $row[0]."\t".$row[1]."\t".$row[2] . PHP_EOL;
    }
    
    echo "Done\n";
    file_put_contents(dirname(__FILE__) . '/urls/urls.txt', $data);
    mysql_close($dbconn);
}

function create_sql_files($sites) {
    $download = new Download($sites);
    $urls = $download->readCsvFile(dirname(__FILE__) . '/log/meta.txt');
    
    $pageNumber = 0;
    $i = 0;
    $sql = '';
    
    foreach ($urls as $u) {
    	$sql .= "UPDATE psg_images SET image_title = '".$u[1]."' WHERE image_id = ".$u[0].";" . PHP_EOL;
    	$i++;
    	if ($i === 999) {
    		$pageNumber++;
    		file_put_contents('titulos_'.$pageNumber.'.sql', $sql);
    		$sql = '';
    		$i = 0;
    	}
    }
    
    $pageNumber++;
    file_put_contents('titulos_'.$pageNumber.'.sql', $sql);
}

function clean_files() {
    $iter = new DirectoryIterator(dirname(__FILE__) . '/download');
    $i=0;
    foreach ($iter as $f) {
        if ($f->isFile() && strstr($f->getFilename(), 'Cook')) {
			$c = file_get_contents(dirname(__FILE__) . '/download/'.$f->getFilename());

			preg_match('/<td height="18" class="fg15ma">([a-zA-Z\s]*)<\/td>/s', $c, $m);
			if (isset($m[1])) {
				$c = preg_replace('/<title>(.*)<\/title>/', '<title>'.trim($m[1]).'</title>', $c);
				file_put_contents(dirname(__FILE__) . '/download/'.$f->getFilename(), $c);
			} else {
				$c = preg_replace('/<title>(.*)<\/title>/', '<title>Cook</title>', $c);
				file_put_contents(dirname(__FILE__) . '/download/'.$f->getFilename(), $c);
			}
			
			echo $i."\n";
			$i++;
        }
    }	
}
