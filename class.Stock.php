<?php
/*
	Stock Analyser API 
	v0.1 alpha
	Without any garantee.
	
	This lib aims to analyse and interpret stocks data with mathematical tools such as
	MACD, Williams, Stoch... and give a Hold(0), Buy (>0), or Sell(>0) directive.
	The default Stock sniffer is the Yahoo finance service. (historical daylies data)
	
	Developped under php v5.6.15
		required : pecl trader extension
	Note : please verify that the "date.timezone" parameter is fulfilled in your php.ini
*/

abstract class StockCache
{
	const CACHE_DIR = 'ta_cache/';
	const CACHE = true;
	const COMPRESS_LEVEL = -1; // -1 = default zlib level, 1 = minimal fastest compress, 9 = maximal slowest compression
	
	protected static function _isCached($file)
	{
		return file_exists(self::CACHE_DIR.$file.'.gz');
	}
	
	protected static function _serialize($file, &$data)
	{
		if(!is_dir(self::CACHE_DIR))
			if(!mkdir(self::CACHE_DIR))
				throw new Exception('Unable to create the stock cache directory ['.self::CACHE_DIR.']');
		return file_put_contents(self::CACHE_DIR.$file.'.gz',
			gzencode(serialize($data), self::COMPRESS_LEVEL)
			);
	}
	
	protected static function _unserialize($file)
	{
		return unserialize(gzdecode(file_get_contents(self::CACHE_DIR.$file.'.gz')));
	}
}

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
// 				'e' => 15, // fin jour
// 				'd' => '00', // fin mois -1
// 				'f' => 2016, // fin annee 
		);
	public function __construct($s)
	{
		$this->params['s'] = $s;
		return $this;
	}

	public function isCachable()
	{
		return true;
	}
	
	public function Period($p = 'd')
	{
		$this->params['g'] = $p;
		return $this;
	}
	
	public function To($y, $m=1, $m=1)
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
	
	public function CSVToArray()
	{
		$arr = array();
		$file = file($this->getURL());
		for($i=1; $i<count($file); $i++)
		{
			$a = explode(',', $file[$i]);
			$arr[$a['0']] = array(
				(float)$a[1], // Open
				(float)$a[2], // High
				(float)$a[3], //Low
				(float)$a[4], //Close
				(int)$a[5],   //Volume
				(float)$a[6]  //Adj Close
				);
		}
		return $arr;
	}
	
	public function getData()
	{
		return array_reverse($this->CSVToArray());
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

class CacheStock implements StockProvider 
{	// ABstract class in order to use Cache only
	private $stock = '';
	public function isCachable()
	{
		return false;
	}
	public function Period($p)
	{
		return $this;
	} // $p = 'd'ay, 'w'eek, 'm'onth
	public function isStock()
	{
		return file_exists(StockCache::CACHE_DIR.$this->stock.'.gz');
	}// Return true if Stock data is available, or false otherwise.
	public function __construct($stock)
	{
		$this->stock = $stock;
		return $this;
	} // Construct with the Stock ID;
	public function From($year, $month = null, $day = null)
	{
		return $this;
	}// Set the beginning date.
		// $year may be a int(4), and then requires $month and $day to be not null,
		// or an array($y, $m, $d) or a string of english formated date
	public function To($year, $month=1, $day = 1)
	{
		return $this;
	}//Idem
	public function getData()
	{
		return array();
	}// Get the data array with the constant format as Stock::$data[]
}

interface StockProvider
{
	public function isCachable(); // Must return true if cachable, of false if not. Very Provider-dependant.
	public function Period($p); // $p = 'd'ay, 'w'eek, 'm'onth
	public function isStock(); // Return true if Stock data is available, or false otherwise.
	public function __construct($stock); // Construct with the Stock ID;
	public function From($year, $month = null, $day = null); // Set the beginning date.
		// $year may be a int(4), and then requires $month and $day to be not null,
		// or an array($y, $m, $d) or a string of english formated date
	public function To($year, $month=1, $day = 1); //Idem
	public function getData(); // Get the data array with the constant format as Stock::$data[]
}

class Stock extends StockCache implements Iterator
{
	const OLD = 5; // Combien d'années remonter ? 5 ans est le default.
	
	/*
	 * Providers
	 */
	const PROVIDER_YAHOO = 'YahooStock';
	const PROVIDER_CACHE = 'CacheStock';

	public 	$stock = '';
	private	$data = array(
				/*
				 * **** ORDERED BY DATE FROM OLDEST TO NEWEST *****
				 * [date] => array( //date(string) as yyyy-mm-dd
				 * 		[Open],		//(float)
				 * 		[High],		//(float)
				 * 		[Low],		//(float)
				 * 		[Close],	//(float)
				 * 		[Volume],	//(int)
				 * 		[AdjustedClose] // ajusté par le dividende et les divisions si il y a
				 * 	),
				 */
				)
			;
	private $provider = null; // pointer to yahoo stock url conceiver;
	private	$oldishery = 5;
	
	/*
	 * Iterator internal pointers;
	 */
	private $position = '';
	private $subdata = 5; // default to AdjustedClose
	
	
	public function __construct($stockid, $period='d', $provider = self::PROVIDER_YAHOO, $old = self::OLD)
	{
		$this->stock = $stockid;
		$this->provider = new $provider($stockid);
		if(!$this->provider instanceof StockProvider)
			throw new Exception('The provider ['.$provider.'] is not a valid StockProvider instance');
		$this->oldishery = $old;
		
		if(parent::_isCached($stockid) && parent::CACHE)
			$this->data = parent::_unserialize($stockid);
		
		// Removed, may be unstraight
// 		end($this->data);
// 		if(key($this->data) == date('Y-m-d'))
// 			return $this; // if already fetched, only use cache.
		
		// else, fecth from provider
		if(!$this->Period($period)->isStock())
			throw new Exception('This Stock ['.$stockid.'] doesn\'t exist or the URL is not reachable.');
		
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
	
	private function Period($p)
	{
		return $this->provider->Period($p);
	}
	
	public function __destruct()
	{
		if(parent::CACHE && $this->provider->isCachable())
			parent::_serialize($this->stock, $this->data);
		unset($this->stock,$this->data,$this->provider); // Free memory
		return true;
	}
	
	private function buildData()
	{
		if(empty($this->data))
			// Dump all data from 5 years old
			$this->provider->From(date('Y')-$this->oldishery, 1, 1);
		else
		{	// Dump only the lastests data.
			$this->data = parent::_unserialize($this->stock);
			end($this->data);
			$this->provider->From(key($this->data));
		}
		//Retrieving data
		$this->data += $this->provider->getData();
		return true;
	}
	
// 	private $SliceFailsafe = 0;
	public function Slice($from, $length = null)
	{
		if(!is_int($from))
			throw new Exception('Wrong format for Stock::Slice($from). Must be integer');
// 		if(++$this->SliceFailsafe > 5)
// 			throw new Exception('There is something incorrect in your $from or $to args into Stock::Slice call');
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

	public function getLast($sub = 5)
	{
		return end($this->data)[$this->subDataIndex($sub)];
	}
	
	private function subDataIndex($d = 5)
	{
		$a = array('O', 'H', 'L', 'C', 'V', 'A');
		if(is_int($d) && $d<count($a) && $d>0)
			return $d;
		elseif(is_string($d) && ($c = array_search(strtoupper($d[0]), $a))!==false)
			return $c;
		else
			throw new Exception('Unknown data type ['.$d.'].');
	}
	/*
	 * Iterator functions
	 */
	public function setSubData($d = 5)
	{
// 		$a = array('Open', 'Low', 'High', 'Close', 'Volume', 'AdjustedClose');
		$this->subdata = $this->subDataIndex($d);
		return $this;
	}
	public function Volume($return_as_array = false)
	{
		return $return_as_array && function_exists('array_column') ? array_column($this->data, 4) : $this->setSubData(4);
	}
	public function Open($return_as_array = false)
	{
		return $return_as_array && function_exists('array_column') ? array_column($this->data, 0) : $this->setSubData(0);
	}
	public function Low($return_as_array = false)
	{
		return $return_as_array && function_exists('array_column') ? array_column($this->data, 1) : $this->setSubData(1);
	}
	public function High($return_as_array = false)
	{
		return $return_as_array && function_exists('array_column') ? array_column($this->data, 2) : $this->setSubData(2);
	}
	public function AdjustedClose($return_as_array = false)
	{
		return $return_as_array && function_exists('array_column') ? array_column($this->data, 5) : $this->setSubData(5); // Adjusted close, in euros.
	}
	public function Close($return_as_array = false)
	{
		return $return_as_array && function_exists('array_column') ? array_column($this->data, 3) : $this->setSubData(3);
	}
	
	/* Iterator functions */
	public function rewind() {
// 		var_dump(__METHOD__);
		reset($this->data);
		$this->position = key($this->data);
	}
	public function current() {
// 		var_dump(__METHOD__);
		return $this->data[$this->position][$this->subdata]; // get AdjustedClose data;
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
	public function Analysis($closeval = 'AdjustedClose')
	{
		return new StockAnalysis($this, $closeval);
	}
}

class StockAnalysis
{
	
	public static $Weight = array(
		'MACD' 	=> 2,
		'MM'	=> 4,
	);
	
	private $stock = null;
	private $cache = array();
	public function __construct(Stock $stock, $closeVal = 'AdjustedClose')
	{
		if(!function_exists('trader_macd'))
			throw new Exception('PECL\'s Trader >=0.4.0 package must be installed and activated in your PHP distribution.');
		$this->stock = $stock;
		
		// Caching Data
		$this->cache = $this->buildData($closeVal);
// 		$this->data->AdjustedClose = array_map(function($i){return $i[5];}, $stock->data);
// 		return var_dump(trader_macd($stock, 12, 26, 9));
	}
	public function __destruct()
	{
		unset($this->buildCache, $this->cache, $this->stock);
	}
	
	private $buildCache = array();
	private function buildData($close = 'AdjustedClose')
	{
		if(isset($this->buildCache[$close]))
			return $this->buildCache[$close];
		$re = array();
		foreach($this->stock->setSubData($close) as $date => $val)
			$re[$date] = $val;
		return $this->buildCache[$close] = $re;
	}
	
	public function SimpleMACD($short = 12, $long = 26, $signal = 9)
	{
		// Data.
		// Extract the last data 5 times the long period, for performance reasons.
		$macd = trader_macd($this->cache, $short, $long, $signal);
		return end($macd[2])>0 ? 1 : -1;
	}
	public function SignalMACD($short = 12, $long = 26, $signal = 9)
	{
		// Data.
		// Extract the last data 5 times the long period, for performance reasons.
		$macd = trader_macd($this->cache, $short, $long, $signal);
		if(end($macd[2])>0 && prev($macd[2])<0)
			return 1;
		if(end($macd[2])<0 && prev($macd[2])>0)
			return -1;
		return 0;
// 		return end($macd[2])>0 &&  ? 1 : -1;
	}
	public function MACD($short=12, $long=26, $signal=9)
	{
		// Data.
		// Extract the last data 5 times the long period, for performance reasons.
		$macd = trader_macd($this->cache, $short, $long, $signal);
		
// 		print_r(array_combine(
// 			array_slice(array_keys($this->cache), -50), 
// 			array_slice(array_values($macd[0]), -50)
// 			));
		/*
		 * return array(
		 * 	array(MACDvalues),
		 * 	array(Signal values),
		 * 	array(Divergence values)
		 */
		$return = 0;
		end($macd[0]);
// 		print('DEBUG : last occurence : '.key($macd[0]));
		$last = key($macd[0]);
		if($macd[2][$last] > 0) // MACD supérieure à son signal -- Hausse
		{
			// Maintenant on va pondérer la force de ce signal de hausse.
// 			print('DEBUG : MACD Au dessus de son signal au dernier offset '.$last.' : '.$macd[0][$last].";".$macd[1][$last].";".$macd[2][$last]."\n");
			$crossed = 0;
			// savoir quand la MACD a crossé son signal 
			while($macd[2][$last+ --$crossed]>0);
// 				$crossed--;
// 			print('DEBUG : Dernier MACD inférieur a son signal a l\'offset : last '.$crossed."\n");
			$return += $crossed; // On décrémente la valeur de retour de la somme des jours passés.
			// Ainsi, plus la macd a crossé il y a longtemps, moins le signal est à l'achat.
			//
			// Verifier combien de temps la MACD a été sous son signal auparavant.
			// Regle de verification classique : 14 periodes minimales
			$verif = 0;
			while($macd[2][$last+$crossed+ --$verif]<0);
// 			print('DEBUG : MACD inférieur a son signal pendant :'.$verif."\n");
			$return -= $verif; // On incrémente par le nombre de jour précédent le cross où la MACD a été inférieure à son signal. 
			//C'est la règle de validation minimale à 14 jours pour que la MACD ait du sens.
			//Et plus elle est longue, plus elle a du sens.
			//
			// Maintenant, Check le cross de la ligne 0, signifiant le passage au dessus de ses moyennes mobiles exponentielles.
// 			print('DEBUG : MACD MAX '. max($macd[0])." MACD MIN ".min($macd[0])." MACD :".$macd[0][$last]."\n");
// 			$potentiel = max($macd[0])-min($macd[0])+$macd[0][$last];
// 			print('DEBUG : Potentiel : '.$potentiel."\n");
			// Coefficient = Max-MACD / Min-MACD
			// => Maximal lorsque la MACD est proche de son minimum.
			// => <1, proche de 0 lorsque la MACD a dépassé la médiane entre le maximum et le minimum historique sur les dernieres MACD.
			$coeff = round(
					(-1)*
					(max($macd[0])-$macd[0][$last])
					/(min($macd[0])-$macd[0][$last])
				, 3);
// 			print('DEBUG : Result initial '.$return."\n");
// 			print('DEBUG : Coeff : '.$coeff."\n");
			$return = round($coeff * $return);
			
// 			print('DEBUG : MACD score final : '.$return."\n");
			
		}
		else // la MACD est Inférieure à son signal.
		{
// 			print('DEBUG : MACD inférieure à son signal : '.$macd[2][$last]."\n");
			
			$return = $macd[0][$last]<0 ? -500 : 0; 
			// retourne -500 si la MACD est négative, => SELL
			// et 0 si elle reste positive. => HOLD
		} 
		
		return $return;
		
	}
	
	public function Volatility($period = 200)
	{
// 		$vol = trader_var(array_slice($this->cache, $period *-1), $period);
// // 		return end($vol);
// 		return sqrt(end($vol));
		$a = array_slice($this->cache, $period*-1);
		$n = $period;
		$mean = array_sum($a) / $n;
		$carry = 0.0;
		foreach ($a as $val) {
			$d = ((double) $val) - $mean;
			$carry += $d * $d;
		};
// 		if ($sample) {
// 			--$n;
// 		}
		return sqrt($carry / $n);
	}
	
	public function PointsPivots()
	{
		$data = end($this->stock->data);
		$pivot = round(($data[1]+$data[2]+$data[3])/3, 3);
		$support1 = $pivot*2 -$data[2];
		$support2 = $pivot - $data[2]+$data[1];
		$resist1 = $pivot*2 + $data[1];
		$resist2 = $pivot +$data[2]-$data[1];
		return compact('pivot','support1','support2','resist1','resist2');
	}
	
// 	Moving Average (Moyenne Mobiles)
//	Return a score from -6 to +6,
// 	public function MM($short=20, $moy = 50, $long=100)
// 	{
// 		$ret = 0;
// 		$mm20 = end(trader_ma($this->cache, $short, TRADER_MA_TYPE_SMA));
// 		$mm50 = end(trader_ma($this->cache, $moy, TRADER_MA_TYPE_SMA));
// 		$mm100 = end(trader_ma($this->cache, $long, TRADER_MA_TYPE_SMA));
// 		
// 		$ret += (end($this->cache) - $mm20)/$mm20;// haussier ou baissier court terme
// 		$ret += (($mm20 - $mm50)/$mm50)*2; // haussier moyen terme
// 		$ret += (($mm50 - $mm100)/$mm100)*3; // haussier long terme
// 		
// 		return $ret;
// 	}
	
	// Returns the percentage of the diff between the short and the long value.
	public function MM($short=1, $long = 20, $prev = false)
	{
		$short = $short==1 ? $this->cache : trader_ma(@array_slice($this->cache, $short*-5), $short, TRADER_MA_TYPE_SMA);
		$long = trader_ma(@array_slice($this->cache, $long*-5), $long, TRADER_MA_TYPE_SMA);
		
		$sh = end($short);
		$ln = end($long);
		if($prev)
		{	$sh = prev($short);
			$ln = prev($long);
		}
		return ($sh-$ln)/$ln;
	}
	
	/* Synthesis of Moving Averages */
	public function SMM($short = 20, $mid = 50, $long = 100)
	{
		$re = array(
			'Short' => array(
				$this->MM(1, $short),
				$this->MM(1, $short, true)
				),
			'Mid' => array(
				$this->MM($short, $mid),
// 				$this->MM($short, $mid, true)
				),
			'Long' => array(
				$this->MM($mid, $long),
// 				$this->MM($mid, $long, true)
				),
			);
		if($re['Short'][0] <0 && $re['Short'][0]-$re['Short'][1]<0) 
			//la MM courte passe a la baisse le 0, signal de vente.
			return -1;
		if($re['Short'][0] <0 && $re['Short'][0]-$re['Short'][1]>0)
			//MM courte haussiere.
			if($re['Short'][0] > $re['Mid'][0] ||
				$re['Short'][0] > $re['Long'][0])
				// Si la MM courte coupe une moyenne plus longue à la hausse, signal d'achat.
				return 1;
		// Sinon, retourne un signal neutre.
		return 0;
// 		return $re;
	}
	public function SMA($s = 20, $m = 50, $l = 100)
	{
		return $this->SMM($s, $m, $l);
	}
// 	private function Oscillator($data )
	
	public function Williams($period = 14, $surachat = -20, $survente = -80)
	{
		$williams = trader_willr(
			array_slice($this->buildData('High'), $period *-2),
			array_slice($this->buildData('Low'), $period *-2),
			array_slice($this->buildData('Adjusted'), $period *-2),
			$period);
		
		$lastw = end($williams);
		$last = key($williams);
		if($lastw < $survente) // Williams en survente
			return -1; // retourne un signal de vente;
		elseif($lastw > $surachat) // Williams en surachat
			return 0; // Retourne un signal neutre => HOLD
		else
		{
			$prev =  prev($williams);
			if($lastw > $survente && $prev < $survente)
				return 1; // Signal d'achat, le williams vient de franchir son seuil de survente
			if($lastw < $surachat && $prev > $surachat)
				return -1; // Signal de vente, le williams vient de franchir son seuil de surachat. => prendre ses bénéfices.
			// Failure swings
			//TODO with oscillator StockAnalysis
			return 0;
		}
		
		
	}
		
	public function RSI($period = 14, $limbasse = 30, $limhaute = 70)
	{
		//TODO : interpreter
		$RSI = trader_rsi(array_slice($this->cache, $period *-2), $period);
		$R = end($RSI);
		if($R < 50)
			if(prev($RSI)<$limbasse && $R > $limbasse) // a franchi a la hausse le seuil de survente
				return 2;
			elseif($R < $limbasse-10)
				return 1;
			else
				return 0;
			else
			if(prev($RSI)>$limhaute && $R < $limhaute)
				return -2;
			elseif($R > $limhaute -10)
				return -1;
			else
				return 0;
		return 0;
	}
	public function FastRSI()
	{
		return $this->RSI(9);
	}
	public function SlowRSI()
	{
		return $this->RSI(25);
	}
	
	public function RegressionLineaire($data)
	{
		//TODO : it's for Testing only... see if it can obtain some useful infos
// 		return trader_linearreg_angle(array_slice($this->cache, -150), 30);
		return trader_midpoint($data, 25);
// 		return trader_linearreg($data);
	}
	
	public function Trendline()
	{	
		return trader_ht_trendline(array_slice($this->cache, -100));
	}
	
	public function MOM($period = 12)
	{
		return end(trader_mom(array_slice($this->cache, $period*-3), $period)) > 0 ? 1 : -1;
	}	
	
	public function Bollinger($period = 20)
	{
		$BB = trader_bbands($this->cache, $period, 2.0, 2.0, TRADER_MA_TYPE_SMA);
		//TODO : Interpreter
		return $BB;
	}
	
	public function Trix($period = 8)
	{
		$trix = trader_trix(array_slice($this->cache, $period *-3), $period);
		
		$lastx = end($trix);
		$prevx = prev($trix);
		if($lastx > 0 && $prevx <0)
			return 1; // signal d'achat
		if($lastx <0 && $prevx > 0)
			return -1; // signal de vente;
		return 0; // signal neutre
	}
	
// 	public function Chaikin($period = 21)
// 	{
// 		//TODO : Wrong function
// 		$cmf = trader_mfi(
// 			array_slice($this->buildData('High'), $period *-5),
// 			array_slice($this->buildData('Low'), $period *-5),
// 			array_slice($this->buildData('Close'), $period *-5),
// 			array_slice($this->buildData('Volume'), $period *-5),
// 			$period
// 			);
// 		print_r($cmf);
// 		$lastc = end($cmf);
// 		$prevc = prev($cmf);
// 		
// 		if($lastc > 0 && $prevc <0) // Franchit à la hausse le signal,
// 			if(array_sum(array_slice($cmf, $period *-2)) / $period*2 < $prevc*1.9)
// 			// Règle de validation empirique basée sur une approximation de la divergence par la moyenne des valeurs de 2 périodes précédentes :
// 			// Si la moyenne des valeurs de deux périodes précédentes est inférieure à 1.9x la dernière valeur négative, on considère que le chainkin a été suffisament négatif pour réaliser un mouvement haussier.
// 				return 1; // Signal d'achat
// 		if($lastc <0 && $prevc >0)
// 			if(array_sum(array_slice($cmf, $preiod *-2)) / $period-2 > $prevc*1.9)
// 				return -1; // signal de vente
// 		return 0;
// 				
// 	}
	
	/* Stochastics  */
	public $StochHigh = 80, $StochLow = 20;
	public function StochasticLimit($low = 20, $high = 80)
	{
		$this->StochHigh = $high;
		$this->StochLow = $low;
		return $this;
	}
	public function Stochastic($KPeriod = 14, $slowKPeriod = 3, $slowDPeriod=3)
	{
		if($KPeriod <1 || $slowKPeriod <1 || $slowDPeriod <1)
			throw new LogicException('A Parameter is not valid for the Stochastic function');
		$sto = trader_stoch(
			array_slice($this->buildData('High'), $KPeriod *-3),
			array_slice($this->buildData('Low'), $KPeriod *-3),
			array_slice($this->buildData('Close'), $KPeriod *-3),
			$KPeriod,
			$slowKPeriod,
			TRADER_MA_TYPE_SMA,
			$slowDPeriod,
			TRADER_MA_TYPE_SMA
			);
// 		print_r($sto);
		$lastSto = end($sto[0]);
		$prevSto = prev($sto[0]);
		$lastSig = end($sto[1]);
		$prevSig = prev($sto[1]);
		if($lastSto < $this->StochHigh && $prevSto > $this->StochHigh) // cross 80 a la baisse
			return -1;
		if($lastSto > $this->StochLow && $prevSto < $this->StochLow) // cross 20 a la hausse,
			if($lastSto >= $lastSig)// Stochastique a passé son signal, 
				if($lastSig > $prevSig) // Signal montant
					return 2;  // Force plus forte.
				else
					return 1; // Signal d'achat normal
		//TODO : Divergences.
		return 0; // signal neutre;
	}
	public function LongStochastic()
	{
		return $this->Stochastic(39,1,1);
	}
	
	public function OBV()
	{
		$OBV = trader_obv(array_slice($this->cache, -60), array_slice($this->buildData('Volume'), -60));
// 		return $OBV;
		return round(end($OBV)/100) > 0 ? 1 : -1;
	}
	
	// Returns somekind of weight of volumes during the 5 last days.
	// Donne la puissance de la tendance.
	public function Volumes($short = 5, $long = 20)
	{
		return round(
			round(
				array_sum(array_slice($this->buildData('Volume'), $short*-1))
				/ $short) 
			/ round(
				array_sum(array_slice($this->buildData('Volume'), $long*-1))
				/ $long)
			, 3);
// 			? 1
// 			: -1;
	}
	public function LongVolumes()
	{
		return $this->Volumes(14,28);
	}
	public function VolumesOscillator($short=5, $long=20)
	{
		return $this->Volumes($short,$long) <1 ? -1 : 1;
		$mean = array_sum(array_slice($this->buildData('Volume'), $period*-1))/$period;
		return round(( end($this->buildData('Volume')) - $mean ) / $mean, 3);
	}
	public function VolumeOscillator($short = 5,$long=20)
	{
		return $this->VolumesOscillator($short,$long);
	}
	
	public function Candle()
	{
		$open = array_slice($this->buildData('Open'), -21);
		$high = array_slice($this->buildData('High'), -21);
		$low = array_slice($this->buildData('Low'), -21);
		$close = array_slice($this->buildData('Close'), -21);
		
		$usefulcdl = array(
			'piercing',
			'hammer',
			'engulfing',
			'morningstar',
			'eveningstar', 
// 			'dojistar', // plus précoce, plus sensiblr mais moins spécifique.
			'abandonedbaby',
			'shootingstar',
			//Tesing
// 			'longline', // trop peu précis.
			'3blackcrows',
			'counterattack',
			'mathold',
			'tasukigap',
			'gapsidesidewhite',
			'2crows',
// 			'darkcloudcover', // A voir... donne des ordres de vente un peu trop faciles
// 			'xsidegap3methods', // too late. too obvious.
// 			'hangingman'
		);
		/*  Testing ...
		$indecisecdl = array(
			'highwave',
			'harami', 
			'haramicross', 
			'spinningtop',
			'rickshawman', 
			);
		$retournementcdl = array(
// 			'upsidegap2crows', 
			'doji', 
			'dojistar', 
			'dragonflydoji', 
			'morningdojistar', 
			'eveningdojistar',
			'gravestonedoji',
			'longleggeddoji',
// 			'shootingstar',
// 			'morningstar', 
// 			'eveningstar', 
// 			'abandonedbaby', 
// 			'darkcloudcover', 
// 			'unique3river', 
// 			'engulfing', 
// 			'counterattack', 
// 			'belthold', 
// 			'3blackcrows', 
// 			'identical3crows', 
// 			'risefall3methods', 
// 			'2crows', 
// 			'piercing', 
// 			'hammer',
// 			'tristar',
// 			'3inside', 
// 			'3outside', 
// 			'3starsinsouth',
// 			'breakaway', 
// 			'kicking',
// 			'kickingbylength',
// 			'ladderbottom',
// 			'takuri',
// 			'thrusting',
// 			'hikkake', 'hikkakemod', 
// 			'shortline', 'stalledpattern', 
			);
		$confirmationcdl = array( 
			'matchinglow',   
			'separatinglines',
			'sticksandwich', 
			'longline', 
			'invertedhammer', 
			'marubozu',
			'closingmarubozu',
			'advanceblock', 
			'homingpigeon', 
			'tasukigap', 
			'xsidegap3methods', 
			'gapsidesidewhite', 
			'3whitesoldiers', 
			'3linestrike', 
			'mathold', 
			'concealbabyswall', 
			'inneck',
			'onneck',
			);
			
			*/
		$re = 0;
		foreach($usefulcdl as $func)
		{
			$res = end(call_user_func('trader_cdl'.$func, $open, $high, $low, $close));
// 			if( $res > 0 ) print $func.' resulted '.$res."\n";
// 			if( $res < 0 ) print $func.' resulted '.$res."\n";
			$re += $res;
		}
		
		return (int)( $re/100 );
	}
	
	public function CCI($period = 14) // Commodity Channel Index
	{
		$cci = trader_cci(
			array_slice($this->buildData('High'), $period*-2),
			array_slice($this->buildData('Low'), $period*-2),
			array_slice($this->buildData('AdjustedClose'), $period*-2),
			$period);
		if(end($cci) >-100 && prev($cci) <-100) // cross CCI de la limite de survente à la hausse
			return 1;
		if(end($cci) <100 && prev($cci)>100) // cross CCI a la baisse ..
			return -1;
		return 0;
		//TODO : Interpret Divergences
	}
	
// 	public function Beta(Stock $CAC40)
// 	{
// 		return trader_beta($this->cache, $CAC40->Analysis()->buildData('A'), 900);
// 		
// 	}
	
	// Results seems weird...
// 	public function Benchmark()
// 	{
// 		$Closes = array_values($this->cache);
// 		$binary = function(&$v, $k, $data)
// 			{
// 				if($v>$data[1][0] && $data[0][$k-1]<$data[1][0]) return $v = 1;
// 				if($v<$data[1][1] && $data[0][$k-1]>$data[1][1]) return $v = -1;
// 				return $v = 0;
// 			};
// 		$WILL = trader_willr(
// 			$this->buildData('High'),
// 			$this->buildData('Low'),
// 			$this->buildData('Adjusted'),
// 			14);
// 		array_walk($WILL, $binary, array($WILL, array(-80,-20)));
// 		$CCI = trader_cci(
// 			$this->buildData('High'),
// 			$this->buildData('Low'),
// 			$this->buildData('AdjustedClose'),
// 			14);
// 		array_walk($CCI, $binary, array($CCI, array(-100,100)));
// 		$RSI = trader_rsi($this->buildData('AdjustedClose'), 14);
// 		array_walk($RSI, $binary, array($RSI, array(30,70)));
// 		$MACD = trader_macd($this->buildData('AdjustedClose'), 12,26,9);
// 		$MACD = $MACD[2];
// 		array_walk($MACD, $binary, array($MACD, array(0,0)));
// 		$STO = trader_stoch(
// 			$this->buildData('High'),
// 			$this->buildData('Low'),
// 			$this->buildData('Close'),
// 			14,
// 			3,
// 			TRADER_MA_TYPE_SMA,
// 			3,
// 			TRADER_MA_TYPE_SMA
// 			);
// 		$STO = $STO[0];
// 		array_walk($STO, $binary, array($STO, array(20,80)));
// 		$LSTO = trader_stoch(
// 			$this->buildData('High'),
// 			$this->buildData('Low'),
// 			$this->buildData('Close'),
// 			39,
// 			1,
// 			TRADER_MA_TYPE_SMA,
// 			1,
// 			TRADER_MA_TYPE_SMA
// 			);
// 		$LSTO = $LSTO[0];
// 		array_walk($LSTO, $binary, array($LSTO, array(20,80)));
// 		// Interpret.
// 		$intersect = array(
// 			'SignalMACD' => array_diff($MACD, array(0,-1)),
// 			'CCI&SignalMACD' => array_intersect_assoc(array_diff($CCI,array(0, -1)), $MACD),
// 			'RSI&Stochastic' => array_intersect_assoc(array_diff($STO,array(0, -1)), $RSI),
// 			'RSI&LongStochastic' => array_intersect_assoc(array_diff($LSTO,array(0,-1)),$RSI),
// 			'SignalMACD&LongStochastic' => array_intersect_assoc(array_diff($MACD,array(0,-1)),$LSTO),
// 			'SignalMACD&RSI' => array_intersect_assoc(array_diff($MACD,array(0,-1)),$RSI),
// 			'RSI' => array_diff($RSI, array(0,-1)),
// // 			'CCI' => array_diff($CCI, array(0,-1)),
// 			'CCI&RSI' => array_intersect_assoc(array_diff($RSI, array(0,-1)),$CCI),
// 			'RSI&Williams' => array_intersect_assoc(array_diff($RSI, array(0,-1)),$WILL),
// 			);
// 		$D = array();
// 		$best = 'none';
// 		$best_ = 5;
// 		foreach($intersect as $ind => $data)
// 		{
// 			$D[$ind] = array('hausse' => array());
// 			foreach($data as $k => $unusable)
// 			{
// 				$max = 0;
// 				for($i = $k+1; $i < count($Closes); $i++)
// 					if($Closes[$i] > $max)
// 						$max = $Closes[$i];
// 					elseif($Closes[$i] < $max*0.94)//delta 3% avant de couper court.
// 						break 1; // break at 
// 					else
// 						continue 1;
// 				$D[$ind]['hausse'][] = round(100*($max-$Closes[$k])/$Closes[$k], 2);
// 			}
// 			$n=count($D[$ind]['hausse']);
// 			$D[$ind]['AVG'] = round(array_sum($D[$ind]['hausse']) / $n,3);
// 			$carry = 0.0;
// 			foreach ($D[$ind]['hausse'] as $val) {
// 				$d = ((double) $val) - $D[$ind]['AVG'];
// 				$carry += $d * $d;
// 			}
// 			$D[$ind]['STD'] = round(sqrt($carry / $n),3);
// 			$D[$ind]['N'] = $n;
// 			$D[$ind]['POWER'] = array_sum($D[$ind]['hausse']);
// 			$D[$ind]['SMART'] = round($D[$ind]['AVG']/($D[$ind]['STD']==0 ? 1 : $D[$ind]['STD']),3);
// 			if($D[$ind]['SMART']>$best_)
// 			{
// 				$best_ = $D[$ind]['SMART'];
// 				$best = $ind;
// 			}
// 		}
// 		$D['best'] = $best;
// 		return $D;
// 	}
}