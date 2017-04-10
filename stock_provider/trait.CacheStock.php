<?php

//Use : 
//class [prov]CacheStock extends StockCache implements StockProvider
//{
//	use CacheStock;
//	private function _provider(Stock $stock)
//	{
//		return $this->provider = new [provider]($stock);
//	}
//}
trait CacheStock
{	// ABstract class in order to use Cache only
	private $stock = '', $period = 'd', $provider = null;
	public function isCachable()
	{
		return false;
	}
	public function Period($p)
	{
// 		$this->period = $p;
		return $this;
	} // $p = 'd'ay, 'w'eek, 'm'onth
	public function isStock()
	{
		return $this->_isCached($this->provider, $this->stock, $this->period);
	}// Return true if Stock data is available, or false otherwise.
	public function __construct(Stock $stock)
	{
		$this->stock = $stock;
		$this->_provider($stock);
		return $this;
	} // Construct with the Stock ID;
	public function Length($l)
	{
		return $this;
	}
	public function SmartPeriod($p)
	{
		$this->period = $p;
		return $this;
	}
	public function getData()
	{
		foreach($this->_unserialize($this->provider, $this->stock, $this->period) as $k=>$v)
			yield $k=>$v;
	}// Get the data array with the constant format as Stock::$data[]
	public function Daily() {return $this;}
	public function Weekly(){return $this;}
	public function Monthly(){return $this;}
	public function IntraDay($period){return $this;} // in minutes
}

