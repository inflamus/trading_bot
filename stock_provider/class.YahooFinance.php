<?php

class YahooStock implements StockProvider
{
	const STOCKFEED = 'https://query1.finance.yahoo.com/v7/finance/download/';
	const CAC40 = '^FCHI';
	const COOKIE = "B=d1vq4ppbh4gfq&b=3&s=g7";
	const CRED_FILE = "YAHOOCREDENTIALS.txt";
	const USER_AGENT = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/49.0.2623.87 Safari/537.36";
	
	private $s = "";
	private $params = array(
		'period1' => 0,
		'period2' => 0,
		'interval' => '1d', //1mo, 1wk
		'events' => 'history',
		'crumb' => '3V80Kj7qyxp',
		);
	private $COOKIE = self::COOKIE;
	private $context = null;
	private $h = null;
	public function __construct(Stock $stock)
	{
		$this->params['period1'] = strtotime("-1 year");
		$this->params['period2'] = strtotime("today 00:00");
		$this->s = $stock->Yahoo();
		$this->generateCrumb(); //credentials
		$this->_create_context();
		return $this;
	}
	
	private function generateCrumb($force = false)
	{
	//{"crumb":"cK3eeMIjJV4"}
	// COOKIE "B=.+";
		$file = sys_get_temp_dir()."/".self::CRED_FILE;
		$cookie = "";
		$crumb = "";
		if($force)	@unlink($file);
		if(!is_readable($file))
		{
// 			$url = "https://finance.yahoo.com";
			$url = "https://fr.finance.yahoo.com/quote/".$this->s."/history?p=".$this->s;
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_HEADER, 1);
			$response = curl_exec($ch);
			$header = substr($response, 0, $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE));
			$body = substr($response, $header_size);
			if(
				preg_match("/B=[a-z0-9]+&b=[0-9]+&s=[a-z0-9]+/", str_replace("\r\n", "", $header), $_cookie) &&
				preg_match('/CrumbStore":\{"crumb":"([a-zA-Z0-9]+)"/s', $body, $_crumb))
				{
					$crumb = $_crumb[1];
					$cookie = $_cookie[0];
					file_put_contents($file, serialize(compact("crumb", "cookie")));				
				}
			else
			{
				throw new Exception('Error getting yahoo credentials.'.$url.$response);
			}
			curl_close($ch);
		}
		else
			extract(unserialize(file_get_contents($file)));
		$this->COOKIE = $cookie;
		$this->params['crumb'] = $crumb;
		return true;
	}

	public function isCachable()
	{
		return true;
	}
	
	public function SmartPeriod($p = self::PERIOD_DAILY)
	{
		switch($p)
		{
			case self::PERIOD_DAILY: default:
				return $this->Daily();	break;
			case self::PERIOD_WEEKLY:
				return $this->Weekly(); break;
			case self::PERIOD_MONTHLY:
				return $this->Monthly(); break;
			case self::PERIOD_10MIN:
			case self::PERIOD_5MIN:
			case self::PERIOD_2MIN:
				return false; break;
		}
	}
	
	public function Period($p = '1d')
	{
		$this->params['interval'] = $p;
		return $this;
	}
	
	public function Daily()
	{
		$this->Period('1d');
		$this->Length('5 years');
		return $this;
	}
	
	public function Weekly()
	{
		$this->Period('1wk');
		$this->Length('10 years');
		return $this;
	}
	
	public function Monthly()
	{
		$this->Period('1mo');
		$this->Length('20 years');
		return $this;
	}
	
	public function IntraDay($period = '')
	{
		return false;
	}
	
	private function _MkTime($y,$m,$d,$param)
	{
		if(is_string($y))
			$y = explode('-', $y);
		if(is_array($y))
		{
			$d = $y[2];
			$m = $y[1];
			$y = $y[0];
		}
		$this->params[$param] = mktime(0, 0, 0, $m, $d, $y);
		return $this;
	}
	
	public function To($y, $m=1, $d=1)
	{
		$this->_MkTime($y, $m, $d, 'period2');
		return $this;
	}
	
	public function From($y,$m=1,$d=1)
	{
		$this->_MkTime($y, $m, $d, 'period1');
		return $this;
	}
	
	public function Length($l)
	{
		$this->params['period1'] = strtotime('today -'.$l);
		$this->params['period2'] = strtotime('today 00:00');
		return $this;
	}
	
// 	public function setStartYear($y)
// 	{
// 		$this->params('c',(int)$y);
// 		return $this;
// 	}
// 	
// 	public function setStartMonth($m=1)
// 	{
// 		$this->params('a', str_pad($m-1, 2, '0', STR_PAD_LEFT));
// 		return $this;
// 	}
// 	
// 	public function setStartDay($d=1)
// 	{
// 		$this->params('b',(int)$d);
// 		return $this;
// 	}
// 	
	private function params($k, $v)
	{
		$this->params[$k] = $v;
		return $this;
	}
	
	private function CSVToArray()
	{
		if($this->_fopen_handle())
		{
			while(($a = fgetcsv($this->h, 128, ",")) !== false)
			{
				if($a[0] == "Date") continue;
// 				yield $a[0] => array(
// 					(float)$a[1], // Open
// 					(float)$a[2], // High
// 					(float)$a[3], //Low
// 					(float)$a[4], //Close
// 					(int)$a[6],   //Volume
// 					(float)$a[5]  //Adj Close
// 					);
				yield $a[0] => StockData::__New()
					->open($a[1])
					->high($a[2])
					->low($a[3])
					->close($a[4])
					->adjclose($a[5])
					->volume($a[6]);
			}
		}
	}
	
	public function getData()
	{
		return $this->CSVToArray();
	}
	
	public function isAvailable()
	{
		return $this->isStock();
	}
	
	private function _create_context()
	{
		$this->context = stream_context_create(array(
		'http' => array(
			'method' => "GET",
			'header' => 
				"User-agent: ".self::USER_AGENT."\r\n"
				."Cookie: ".$this->COOKIE."\r\n"
			)
		));
		return true;
	}
	
	private function _fopen_handle()
	{
		if(is_null($this->h))
		{
			static $tries = 0;
			if($tries++ > 2) throw new Exception('Can\'t get to have a valid url file socket. Exiting.');
			if(($this->h = @fopen($this->getURL(), 'r', null, $this->context)) === false)
				switch((int)substr($http_response_header[0], 9, 3))
				{
					case 401: //handle unauthorized error
						$this->generateCrumb(true);
						return $this->_fopen_handle();
					break;
					default: 
						return false;
						throw new Exception('Yahoo URL fetch error.'.print_r($http_response_header, true));
					break;
				}
		}
		return $this->h;
	}
	
	public function isStock()
	{
		return ($this->_fopen_handle() === false) ? false : true;
// 		$h = get_headers($this->getURL());
// 		print_r($h);
// 		return $h[0] == 'HTTP/1.1 200 OK' ? true : false;
	}
	
	public function getURL()
	{
		return self::STOCKFEED.$this->s.'?'.http_build_query($this->params);
	}
	
	public function __destruct()
	{
		@fclose($this->h);
	}
	
	public function __toString()
	{
		return $this->getURL();
	}
}

class YahooCacheStock extends StockCache implements StockProvider
{	// ABstract class in order to use Cache only
	use CacheStock;
	private function _provider(Stock $stock)
	{
		$this->provider = new YahooStock($stock);
		return $this;
	}
}

?>
