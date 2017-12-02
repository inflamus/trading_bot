<?php
/*
	Stock Analyser API 
	v0.1 alpha
	Without any garantee.
	
	This lib aims to analyse and interpret stocks data with mathematical tools such as
	MACD, Williams, Stoch... and give a Hold(0), Buy (>0), or Sell(>0) directive.
	The default Stock sniffer is the Yahoo finance service. (historical daylies data)
		//TODO : Length : 1, 2, 5, 10, 90, 180, 365, 730, 1825, 3650, 5475, 7300, 9125

	Developped under php v5.6.15
		required : pecl trader extension
	Note : please verify that the "date.timezone" parameter is fulfilled in your php.ini
*/

abstract class StockCache
{
	const CACHE_DIR = 'ta_cache';
	const CACHE = true;
	const COMPRESS_LEVEL = -1; // -1 = default zlib level, 1 = minimal fastest compress, 9 = maximal slowest compression
	
	private function _file_ (StockProvider $provider, Stock $stock, $period)
	{
		return $this->_dir_($provider) . DIRECTORY_SEPARATOR  . $stock->ISIN() . $period.'.gz';
	}
	
	private function _dir_(StockProvider $provider)
	{
		return dirname(__FILE__) . DIRECTORY_SEPARATOR . self::CACHE_DIR . DIRECTORY_SEPARATOR . get_class($provider);
	}
	
	protected function _isCached(StockProvider $provider, Stock $stock, $period)
	{
		return file_exists($this->_file_($provider, $stock, $period));
	}
	
	protected function _serialize(StockProvider $provider, Stock $stock, $period, &$data)
	{
		if(!is_dir($this->_dir_($provider) . DIRECTORY_SEPARATOR))
			if(!mkdir($this->_dir_($provider), 0777, true))
				throw new Exception('Unable to create the stock cache directory ['.$this->_dir_($provider).']');
				
		return file_put_contents($this->_file_($provider, $stock, $period),
			gzencode(serialize($data), self::COMPRESS_LEVEL)
			);
	}
	
	protected function _unserialize(StockProvider $provider, Stock $stock, $period)
	{
		return unserialize(gzdecode(file_get_contents($this->_file_($provider, $stock, $period))));
	}
}

class StockQuote extends StockCache implements Iterator
{
	const OLD = 2; // Combien d'années remonter ? 
	
	/*
	 * Providers
	 */
	const PROVIDER_YAHOO = 'YahooStock';
	const PROVIDER_YAHOOCACHE = 'YahooCacheStock';
	const PROVIDER_ABCBOURSE = 'ABCBourseStock';
	
	const DEFAULT_SUB = "close"; // CLOSE
	
//	public static $YahooSBF120 = array('SOLB.BR', 'LHN.PA', 'AF.PA', 'VCT.PA', 'ALT.PA', /*'ICAD.PA',*/ 'ERF.PA', 'BOL.PA', 'NEX.PA', 'ACA.PA', 'SOP.PA', 'MAU.PA', 'ATO.PA', 'RCF.PA', 'RMS.PA', 'MMT.PA', 'DIM.PA', 'UBI.PA', 'TFI.PA', 'FDR.PA', 'ATE.PA', 'SAF.PA', 'IPS.PA', 'DEC.PA', 'AI.PA', 'CGG.PA', 'CA.PA', 'CNP.PA', 'FP.PA', 'OR.PA', 'VK.PA', 'AC.PA', 'EN.PA', 'NEO.PA', 'SAN.PA', 'CS.PA', 'BN.PA', 'KN.PA', 'RI.PA', 'NK.PA', 'BB.PA', 'MC.PA', 'RF.PA', 'EO.PA', 'MF.PA', 'SW.PA', 'RUI.PA', 'ML.PA', 'HO.PA', 'KER.PA', 'UG.PA', 'EI.PA', 'SK.PA', 'HAV.PA', 'LI.PA', 'SU.PA', 'VIE.PA', 'POM.PA', 'UL.PA', 'SGO.PA', 'CAP.PA', 'ING.PA', 'DG.PA', 'CO.PA', 'ZC.PA', 'VIV.PA', /*'ALU.PA',*/ 'MMB.PA', /*'FR.PA', */'RCO.PA', 'FGR.PA', 'PUB.PA', 'DSY.PA', 'GLE.PA', 'BNP.PA', 'TEC.PA', 'RNO.PA', 'ORA.PA', 'ORP.PA', 'ILD.PA', 'AMUN.PA', 'GNFT.PA', 'ELE.PA', 'BVI.PA', 'GFC.PA', 'BIM.PA', 'NXI.PA', 'SAFT.PA', 'ENGI.PA', 'ALO.PA', 'ETL.PA', 'MERY.PA', 'EDF.PA', 'IPN.PA', 'LR.PA', 'AKE.PA', 'IPH.PA', 'ADP.PA', 'KORI.PA', 'SCR.PA', 'DBV.PA', 'RXL.PA', 'GET.PA', 'SEV.PA', 'EDEN.PA', 'TCH.PA', 'NUM.PA', 'GTT.PA', 'ELIOR.PA', 'WLN.PA', 'ELIS.PA', 'SPIE.PA', 'EUCAR.PA', /*'SESG.PA',*/ 'MT.PA', 'APAM.AS','STM.PA', 'AIR.PA', 'GTO.PA', 'ENX.PA', 'CDI.PA', 'NOKIA.PA');
//	public static $YahooCAC40 = array('AC.PA', 'ACA.PA', 'AI.PA', 'AIR.PA', 'ALO.PA', 'BN.PA', 'BNP.PA', 'CA.PA', 'CAP.PA', 'CS.PA', 'DG.PA', 'EI.PA', 'EN.PA', 'ENGI.PA', 'FP.PA', /*'FR.PA',*/ 'GLE.PA', 'KER.PA'/*, 'LHN.PA'*/, 'LI.PA', 'LR.PA', 'MC.PA', 'ML.PA', 'MT.PA', /*'NOKIA.PA',*/ 'OR.PA', 'ORA.PA', 'PUB.PA', 'RI.PA', 'RNO.PA', 'SAF.PA', 'SAN.PA', 'SGO.PA', 'SOLB.BR', 'SU.PA', 'TEC.PA', 'UG.PA', 'UL.PA', 'VIE.PA', 'VIV.PA');

	public 	$stock = null;
	public	$period = StockProvider::PERIOD_DAILY;
	private	$data = array(
				/*
				 * **** ORDERED BY DATE FROM OLDEST TO NEWEST *****
				 * [date] => StockData Object (open, close, high, low, volume, adjclose)
				 */
				)
			;
	private $provider = null; // pointer to yahoo stock url conceiver;
	
	/*
	 * Iterator internal pointers;
	 */
	private $noSliced = true;
	private $position = '';
	private $subdata = self::DEFAULT_SUB; // default to Close
	
	public function __construct(Stock $stock, $period = StockProvider::PERIOD_DAILY, $provider = self::PROVIDER_YAHOO)
	{
		$this->stock = $stock;
		$this->period = $period;
		
		if(!is_object($provider))
			$this->provider = new $provider($stock);
		else
			$this->provider =& $provider;
			
		if(!$this->provider instanceof StockProvider)
			throw new Exception('The provider ['.$provider.'] is not a valid StockProvider instance');
					
		if(!$this->provider->SmartPeriod($period))
			throw new Exception('This period '.$period.' isn\'t available with this provider ('.get_class($this->provider).').');
		// else, fecth from provider
		if(!$this->isStock())
			throw new Exception('This Stock ['.$stock->Mnemo().'] doesn\'t exist or the URL is not reachable.');
		
		$this->buildData();
		return $this;
	}
	
	public function __get($name)
	{
		// Make $this->data readonly.
		return isset($this->$name) ? $this->$name : null;
	}
	
	private function isStock()
	{
		return $this->provider->isStock();
	}
	
// 	private function Period($p)
// 	{
// 		return $this->provider->Period($p);
// 	}
	
	public function __destruct()
	{
		// Caching
		if(parent::CACHE && $this->provider->isCachable() && $this->noSliced)
			$this->_serialize($this->provider, $this->stock, $this->period, $this->data);
			
		unset($this->stock,$this->data,$this->provider); // Free memory
		return true;
	}
	
	private function buildData()
	{
		// if there is cached data, populate the buffer
		if($this->_isCached($this->provider, $this->stock, $this->period) && parent::CACHE)
		{
			$this->data = $this->_unserialize($this->provider, $this->stock, $this->period);
			if(method_exists($this->provider, 'From')) // partial data already fetched from cache, 
			{
				end($this->data);
				$this->provider->From(key($this->data));
			}
		}
		//Retrieving latest data
		$this->data += iterator_to_array($this->provider->getData());
		//TODO make stockdata cache analysis indicators, and stock analysis check on cache to unserialize only necessary.

		return true;
	}
	
// 	private $SliceFailsafe = 0;
	public function Slice($from, $length = null)
	{
		if(!is_int($from))
			throw new Exception('Wrong format for Stock::Slice($from). Must be integer');
// 		if(++$this->SliceFailsafe > 5)
// 			throw new Exception('There is something incorrect in your $from or $to args into Stock::Slice call');
		$this->noSliced = false;
		$this->data = array_combine(
			array_slice(
				array_keys($this->data), 
				$from, 
				$length),
			array_slice(
				array_values($this->data),
				$from,
				$length)
			);
// 		print_r(end($this->data));
		return $this;
// 		$fromk = array_search($from, $this->data);
// 		if($fromk === false)
// 		{
// 			$from = explode('-', $from);
// 			$from[2] = str_pad(++$from[2], 2, '0', STR_PAD_LEFT);
// 			return $this->Slice(implode('-', $from), $to);
// 		}
// 		if(!is_null($to))
// 		{
// 			$tok = array_search($to, $this->data);
// 			if($tok === false)
// 			{
// 				$to = explode('-', $to);
// 				$to[2]++;
// 				return $this->Slice($from, implode('-', $to));
// 			}
// 		}
// 		else
// 			$tok = null;
// 		$length = $tok==null ? null : $tok - $fromk;
// 		if($length<0)
// 			throw new Exception('It seems that $to is newer than $from ... ');
// 		$this->data = array_combine(
// 			array_slice(
// 				array_keys($this->data), 
// 				$fromk, 
// 				$length),
// 			array_slice(
// 				array_values($this->data),
// 				$fromk,
// 				$length)
// 			);
// 		print_r($this->data);
// 		return $this;
	}
// 	public function getData($d = 5)
// 	{
// 		$sub = $this->subDataIndex($d);
// // 		print($sub);
// 		return array_map(function($i){global $sub; return $i[$sub];}, $this->data);
// 	}
// 	

	public function getLast($sub = self::DEFAULT_SUB)
	{
		return end($this->data)->$sub;
	}
	
	public function getFirst($sub = self::DEFAULT_SUB)
	{
		return array_shift(array_slice($this->data, 0, 1))->$sub;
	}
	
// 	public function subDataIndex($d = self::DEFAULT_SUB)
// 	{
// // 		$a = array('O', 'H', 'L', 'C', 'V', 'A');
// // 		if(is_int($d) && $d<count($a) && $d>0)
// // 			return $d;
// 		/*else*/if(is_string($d) && ($c = array_search(strtoupper($d[0]), $a))!==false)
// 			return $c;
// 		else
// 			throw new Exception('Unknown data type ['.$d.'].');
// 	}
	/*
	 * Iterator functions
	 */
	public function setSubData($d = self::DEFAULT_SUB)
	{
		$this->subdata = $d=="TA" ? "TA" : strtolower($d);
		return $this;
	}
	public function _Array($sub, $return_as_array = false)
	{
		if($return_as_array)
		{
			$re = array();
			foreach($this->setSubData($sub) as $k => $v)
				$re[$k] = $v;
			return $re;
		}
		return $this->setSubData($sub);
	}
	public function Volume($return_as_array = false)
	{
		return $this->_Array('volume', $return_as_array);
	}
	public function Open($return_as_array = false)
	{
		return $this->_Array('open', $return_as_array);
	}
	public function Low($return_as_array = false)
	{
		return $this->_Array('low', $return_as_array);
	}
	public function High($return_as_array = false)
	{
		return $this->_Array('high', $return_as_array);
	}
	public function AdjustedClose($return_as_array = false)
	{
		return $this->_Array('adjclose', $return_as_array);
	}
	public function Close($return_as_array = false)
	{
		return $this->_Array('close', $return_as_array);
	}
	public function TAData($return_as_array = false)
	{
		return $this->_Array('TA', $return_as_array);
	}
	
	/* Iterator functions */
	public function rewind() {
// 		var_dump(__METHOD__);
		reset($this->data);
		$this->position = key($this->data);
	}
	public function current() {
// 		var_dump(__METHOD__);
		return $this->data[$this->position]->{$this->subdata}; // get AdjustedClose data;
	}
	public function key() {
// 		var_dump(__METHOD__);
		return $this->position;
	}
	public function next() {
// 		var_dump(__METHOD__);
		next($this->data);
		$this->position = key($this->data);
	}
	public function valid() {
// 		var_dump(__METHOD__);
		return isset($this->data[$this->position]);
	}

	/* Analysis bridge */
	public function Analysis($closeval = self::DEFAULT_SUB)
	{
		return new StockAnalysis($this, $closeval);
	}
	
	/* Interpreter bridge */
	public function Interpreter($today = false)
	{
		return new StockInterpreter($this, $today);
	}
}

