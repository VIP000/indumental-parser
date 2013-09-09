<?php
require_once dirname(__FILE__) . '/job.php';

class Scrape extends Job
{
	public function setUp()
	{
		$this->urls = $this->readCsvFile(dirname(__FILE__) . '/urls/urls.txt');

		$this->output("");
		$this->output("Scraping files");
	}

	public function perform()
	{
		$errorStr = '';
		$data = '';
		foreach ($this->urls as $i) {
			if (!isset($i[2]) || empty($i[2])) {
				continue;
			}
			$id = $i[0];
			$site = $i[1];
			$url = $i[2];
			if (! isset($this->sites[$site]['xpath_title'])) {
				continue;
			}
			$filename = $site . "_" . md5($url);
			if (! file_exists($this->downloadDir . DIRECTORY_SEPARATOR . $filename)) {
				continue;
			}
			$html = file_get_contents($this->downloadDir . DIRECTORY_SEPARATOR . $filename);
			$this->output( "---> " . $filename);

			$dom = array('title' => $this->sites[$site]['xpath_title']);
			$r = $this->query($html, $url, $dom);
			if (empty($r['title'])) {
				$r['title'] = $site;
				$errorStr .= $id . "\t" . $site . "\t" . $url . PHP_EOL;
			}

			$data .= $id . "\t" . $r['title'] . "\t" . $site . "\t" . $url . PHP_EOL;
		}

		$this->stop();
		$rand = md5(time());
		file_put_contents($this->logDir . DIRECTORY_SEPARATOR . 'meta.txt', $data);
		if (! empty($errorStr)) {
			file_put_contents($this->logDir . DIRECTORY_SEPARATOR . 'meta_errors.txt', $errorStr);
		}
	}

	public function query($html, $url, $dom)
	{
		libxml_use_internal_errors(true);

		$doc = new DOMDocument();
		$doc->recover = true;
		$doc->strictErrorChecking = false;
		$doc->loadHtml($html);

		$xpath = new DOMXPath($doc);

		$result = array();
		foreach ($dom as $name => $element) {
			
			// regex
			if (is_array($element) && isset($element['regex'])) {
				preg_match("@".$element['regex']."@s", $html, $matches);
				if (isset($matches[1])) {
					$str = trim($matches[1]);
					if (isset($element['delimeter'])) {
						$strList = explode($element['delimeter'], $str);
						$index =  isset($element['segment']) ? $element['segment'] : 0;
						$str = isset($strList[$index]) ? trim($strList[$index]) : '';
					}
					$result[$name] = $this->cleanString($str);
					continue;
				}
			}

			// xpath (with delimeter)
			else if (is_array($element) && isset($element['xpath'])) {
				$nodelist = $xpath->query($element['xpath']);
				if ($nodelist->length > 0) {
					$str = trim($nodelist->item(0)->nodeValue);
					if (isset($element['delimeter'])) {
						$strList = explode($element['delimeter'], $str);
						$index =  isset($element['segment']) ? $element['segment'] : 0;
						$str = isset($strList[$index]) ? trim($strList[$index]) : '';
					}
					$result[$name] = $this->cleanString($str);
					continue;
				}
			}
			
			// xpath
			else if (is_string($element)) {
				$nodelist = $xpath->query($element);
				if ($nodelist->length > 0) {
					$str = trim($nodelist->item(0)->nodeValue);
					$result[$name] = $this->cleanString($str);
					continue;
				}
			}
			
			$result[$name] = '';
			$this->output('[error]  ' . $name . ' - ' . $result[$name]);
		}

		libxml_clear_errors();
		return $result;
	}

	public function cleanString($string)
	{
		$string = $this->normalizeString($string);
		$string = ucfirst(trim(preg_replace("/[^a-zA-Z.\s]/", " ", $string)));
		$string = preg_replace('!\s+!', ' ', $string);
		$string = str_replace(array("\r\n", "\n", "\r"), ' ', $string);
		return $string;
	}

	public function normalizeString($string)
	{
		$a = 'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûýýþÿŔŕ';
		$b = 'aaaaaaaceeeeiiiidnoooooouuuuybsaaaaaaaceeeeiiiidnoooooouuuyybyRr';
		$string = utf8_decode($string);
		$string = strtr($string, utf8_decode($a), $b);
		$string = strtolower($string);

		return utf8_encode($string);
	}
}
