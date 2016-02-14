<?php

/*
 Usage : StockInd::getInstance()->search('Air Liquide');
*/

class StockInd
{
	const STOCKS_FILE = 'libelles.csv'; /* Thanks to ABC Bourse.com */
	const UNIFORM_REGEX = '/[^a-z0-9]/';
	public $Lib = array(), $Mnem = array();
	
	public function __construct()
	{
		if(!is_readable(self::STOCKS_FILE))
			return false;
		foreach(file(self::STOCKS_FILE) as $v)
		{
			$l = explode(';', $v);
			if($l[0] == 'ISIN') continue;
			$this->Lib[$l[0]] = $this->uniform($l[1]);
			$this->Mnem[$l[0]] = strtoupper(trim($l[2]));
		}
	}
	
	private function uniform($s)
	{
		return preg_replace(self::UNIFORM_REGEX, '_', strtolower($s));
	}
	
	public function search($s)
	{
		if(($re = array_search($this->uniform($s), $this->Lib))!== false)
			return $re;
		if(($re = array_search(strtoupper($s), $this->Mnem)) !== false)
			return $re;
		return false;
	}
	
	public static function registerInstance($instance)
	{
		return $GLOBALS['STOCKSIND_INSTANCE'] = $instance;
	}
	public static function getInstance()
	{
		if(isset($GLOBALS['STOCKSIND_INSTANCE']))
			return $GLOBALS['STOCKSIND_INSTANCE'];
		else 
			return self::registerInstance(new self());
	}
}
