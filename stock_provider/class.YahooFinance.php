<?php

class YahooStock implements StockProvider
{
	const STOCKFEED = 'http://real-chart.finance.yahoo.com/table.csv';
	const CAC40 = '^FCHI';
	
	private $params = array(
		's' => '',
		'g' => 'd', // journalier
// 		'b' => date('j', strtotime('-5 years')), //debut jour
// 		'a' => date('m', strtotime('-5 years'))-1, //debut mois -1
// 		'c' => date('Y')-5, // debut annee
//		'e' => 15, // fin jour
//		'd' => '00', // fin mois -1
//		'f' => 2016, // fin annee 
		);
	public function __construct(Stock $stock)
	{
		$this->params['s'] = $stock->Yahoo();
		return $this;
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
	
	public function Period($p = 'd')
	{
		$this->params['g'] = $p;
		return $this;
	}
	
	public function Daily()
	{
		$this->Period('d');
		$this->Length('5 years');
		return $this;
	}
	
	public function Weekly()
	{
		$this->Period('w');
		$this->Length('10 years');
		return $this;
	}
	
	public function Monthly()
	{
		$this->Period('m');
		$this->Length('20 years');
		return $this;
	}
	
	public function IntraDay($period = '')
	{
		return false;
	}
	
	public function To($y, $m=1, $e=1)
	{
		if(is_string($y))
			$y = explode('-', $y);
		if(is_array($y))
		{
			$d = $y[2];
			$m = $y[1];
			$y = $y[0];
		}
		$this->params('e', $e);
		$this->params('d', $m);
		$this->params('f', $y);
		return $this;
	}
	
	public function From($y,$m=1,$d=1)
	{
		if(is_string($y))
			$y = explode('-', $y);
		if(is_array($y))
		{
			$d = $y[2];
			$m = $y[1];
			$y = $y[0];
		}

		$this->setStartYear($y)
			->setStartMonth($m)
			->setStartDay($d);
		return $this;
	}
	
	public function Length($l)
	{
		$this->From(date('Y-m-d', strtotime('today -'.$l)));
		$this->To(date('Y-m-d'));
		return $this;
	}
	
	public function setStartYear($y)
	{
		$this->params('c',(int)$y);
		return $this;
	}
	
	public function setStartMonth($m=1)
	{
		$this->params('a', str_pad($m-1, 2, '0', STR_PAD_LEFT));
		return $this;
	}
	
	public function setStartDay($d=1)
	{
		$this->params('b',(int)$d);
		return $this;
	}
	
	private function params($k, $v)
	{
		$this->params[$k] = $v;
		return $this;
	}
	
	private function CSVToArray()
	{
// 		$arr = array();
		$file = file($this->getURL());
		for($i=count($file)-1; $i>0; $i--)
		{
			$a = explode(',', $file[$i]);
			yield $a[0] => 
			/*$arr[$a['0']] =*/ array(
				(float)$a[1], // Open
				(float)$a[2], // High
				(float)$a[3], //Low
				(float)$a[4], //Close
				(int)$a[5],   //Volume
				(float)$a[6]  //Adj Close
				);
		}
// 		return $arr;
	}
	
	public function getData()
	{
		return $this->CSVToArray();
	}
	
	public function isAvailable()
	{
		return $this->isStock();
	}
	
	public function isStock()
	{
		$h = get_headers($this->getURL());
		return $h[0] == 'HTTP/1.1 200 OK' ? true : false;
	}
	
	public function getURL()
	{
// 		print($this->url.'?'.http_build_query($this->params));
		return self::STOCKFEED.'?'.http_build_query($this->params);
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
