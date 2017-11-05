<?php


class StockAnalysis
{
// 	public static $Weight = array(
// 		'MACD' 	=> 2,
// 		'MM'	=> 4,
// 		);
		
		private $stock = null;
		private $buildCache = array();
		public function __construct(StockQuote $stock, $closeVal = StockQuote::DEFAULT_SUB)
		{
			if(!function_exists('trader_macd')) { //Trader's php extension test.
				throw new Exception('PECL\'s Trader >= 0.4.0 package must be installed and activated in your PHP distribution.');
			}
			
			$this->stock = $stock;
			
			// Caching Data
			$this->buildData();
// 			$this->buildData('TA'); // get cached trader analysis
			
		}
		public function __destruct()
		{
			unset($this->buildCache, $this->stock);
		}
		
		private function buildData($close = StockQuote::DEFAULT_SUB)
		{
			if(isset($this->buildCache[$close]))
				return $this->buildCache[$close];
			return $this->buildCache[$close] = $this->stock->_Array($close, true);			
		}
		private function getData($sub = StockQuote::DEFAULT_SUB)
		{ //Aliasing buildData
			return $this->buildData($sub);
		}
		
		private function _cache($name)
		{
			if(isset($this->buildCache['TA'][$name]))
				return $this->buildCache['TA'][$name];
// 			else
			return $this->buildCache['TA'][$name] = array_map(function($a) use ($name) {return $a[$name];}, $this->stock->_Array('TA', true));
		}
		
		private function _trader_($function_name, $args = array())
		{
			if(substr($function_name, 0, 7) != "trader_")
				$function_name = "trader_".$function_name;
			
			//memoization
			static $cache;
			$hashed_name = md5(serialize(func_get_args()));
		
			return 
				(!is_null($cache) && array_key_exists($hashed_name, $cache)) ? 
					$cache[$hashed_name] : 
					($cache[$hashed_name] =
								$this->_indexOnDate(call_user_func_array($function_name, $args))
					);				

			//
			
// 			$hashed_name = $function_name.':'.implode(':', array_map(function($a){return !is_array($a) ? $a : null;}, $args));
// 			// try to use cached values today.
// 			if(!empty($this->stock->getLast('TA')[$hashed_name]))
// 				return $this->_cache($hashed_name);
// 			// else
// 			$return = call_user_func_array($function_name, $args);
// 			if($return !== false && !empty($return))
// 			{
// 				//TODO : handle nested arrays
// 				$return = array_values(array_pad($return, -1*count($this->stock->TAData(true)), 0));
// 				$i=0;
// 				foreach($this->stock->data as $k => $obj)
// 				{
// 	// 			print_r($obj);
// 	// 			exit();
// 					$obj->TA(
// 						$hashed_name, 
// 						$this->buildCache['TA'][$hashed_name][$k] = $return[$i++]
// 					);
// 				}
// 			
// 			}
// 			
// 			return $this->_cache($hashed_name);
		}
		
		private function _indexOnDate($arr, $index = null)
		{
			if(is_array(current($arr)))
			{ // handle nested arrays
				$re = array();
				foreach($arr as $key => $a)
					$re[$key] = $this->_indexOnDate($a);
				return $re;
			}
			return array_combine(
				array_keys($index == null ? $this->stock->TAData(true) : $index), 
				array_pad(
					array_values($arr), 
					-1*count($index == null ? $this->stock->TAData(true) : $index), 
					null)
				);
		}
		
		/* Base functions */
		// RSI :array(...rsi...)
		public function RSI($timeperiod = 14)
		{
			return $this->_trader_("rsi", array($this->getData('close'), $timeperiod));
		}
		public function FastRSI()
		{
			return $this->RSI(9);
		}
		public function SlowRSI()
		{
			return $this->RSI(25);
		}
		
		//MACD :array(..array(macd, signal, macd-signal_histogram)..)
		public function MACD($short = 12, $long = 26, $signal = 9)
		{
			return $this->_trader_("macd", array($this->getData('close'), $short, $long, $signal));
		}
		
		public function ADX($timeperiod = 14)
		{
			return $this->_trader_("adx", array($this->getData('high'), $this->getData('low'), $this->getData('close'), $timeperiod));
		}
		
		public function ADXR($timeperiod = 14)
		{
			return $this->_trader_("adxr", array($this->getData('high'), $this->getData('low'), $this->getData('close'), $timeperiod));
		}

		/* DMI non différencié
		public function DMI($timeperiod = 14)
		{
			return $this->_trader_("dx", array($this->getData('high'), $this->getData('low'), $this->getData('close'), $timeperiod));
		}
		public function DX($timeperiod = 14) // Alias DMI
		{
			return $this->DMI($timeperiod);
		}*/
		
		public function DMIPlus($timeperiod = 14)
		{
			return $this->_trader_('plus_di', array($this->getData('high'), $this->getData('low'), $this->getData('close'), $timeperiod));
		}
		public function DMIP($timeperiod = 14)
		{
			return $this->DMIPlus($timeperiod);
		}
		public function DIP($timeperiod = 14)
		{
			return $this->DMIPlus($timeperiod);
		}
		
		public function DMI($p, $timeperiod = 14)
		{
			if((is_int($p) && $p > 0) 
				|| (is_bool($p) && $p == true) 
				|| (is_string($p) && (strtolower($p[0])=='p' || $p[0]=='+'))
				)	
				return $this->DMIPlus($timeperiod);
			return $this->DMIMinus($timeperiod);
		}
		public function DI($p, $timeperiod = 14)
		{
			return $this->DMI($p, $timeperiod);
		}
		
		public function DMIMinus($timeperiod = 14)
		{
			return $this->_trader_('minus_di', array($this->getData('high'), $this->getData('low'), $this->getData('close'), $timeperiod));
		}
		public function DMIM($timeperiod = 14)
		{
			return $this->DMIMinus($timeperiod);
		}
		public function DIM($timeperiod = 14)
		{
			return $this->DMIMinus($timeperiod);
		}
		
		public function StochRSI($timeperiod = 14, $fastK = 3, $fastD = 3, $fastDM = TRADER_MA_TYPE_SMA)
		{
			return $this->_trader_("stochrsi", array($this->getData('close'), $timeperiod, $fastK, $fastD, $fastDM));
	
		}
	
		public function Volatility(/*$period = 200*/)
		{
			// 		$vol = trader_var(array_slice($this->cache, $period *-1), $period);
			// // 		return end($vol);
			// 		return sqrt(end($vol));Volatility
			// 		$a = array_slice($this->cache, $period*-1);
			// 		$n = $period;
			$n = count($this->getData('close'));
			$mean = $this->Moyenne();
			$carry = 0.0;
			foreach ($this->getData('close') as $val) {
				$d = ((double) $val) - $mean;
				$carry += $d * $d;
			};
			// 		if ($sample) {
			// 			--$n;
			// 		}
			return sqrt($carry / $n);
		}
		
		public function TrueVolatility()
		{
			$high = $this->getData('high');
			$low = $this->getData('low');
			$re = array();
			foreach($this->getData('close') as $i => $close)
				$re[] = ($high[$i] - $low[$i]) / $close;
			return round(array_sum($re)/count($close), 2);
		}
		
		public function Moyenne()
		{
			return array_sum($this->getData('close'))/count($this->getData('close'));
		}
		
		public function PointsPivots()
		{
			$h = $this->stock->getLast('high');
			$l = $this->stock->getLast('low');
			$c = $this->stock->getLast('close');
			$pivot = round(($h+$l+$c)/3, 3);
			$support1 = $pivot*2 - $h;
			$support2 = $pivot - $l+$h;
			$resist1 = $pivot*2 + $l;
			$resist2 = $pivot +$h-$l;
			return compact('pivot','support1','support2','resist1','resist2');
		}
		
		public function MM($period = 20, $mmtype = TRADER_MA_TYPE_SMA)
		{
			return $this->_trader_('ma', array($this->getData('close'), $period, $mmtype));
		}
		
		public function Williams($period = 14, $surachat = -20, $survente = -80)
		{
// 			$williams = trader_willr(
// 				array_slice($this->buildData('High'), $period *-2),
// 				array_slice($this->buildData('Low'), $period *-2),
// 				array_slice($this->buildData('Adjusted'), $period *-2),
// 				$period);
			return $this->_trader_('willr', array($this->buildData('high'), $this->buildData('low'), $this->buildData('close'), $period));
			
// 				$lastw = end($williams);
// 				$last = key($williams);
// 				if($lastw < $survente) // Williams en survente
// 					return -1; // retourne un signal de vente;
// 				elseif($lastw > $surachat) // Williams en surachat
// 					return 0; // Retourne un signal neutre => HOLD
// 					else
// 					{
// 						$prev =  prev($williams);
// 						if($lastw > $survente && $prev < $survente)
// 							return 1; // Signal d'achat, le williams vient de franchir son seuil de survente
// 							if($lastw < $surachat && $prev > $surachat)
// 								return -1; // Signal de vente, le williams vient de franchir son seuil de surachat. => prendre ses bénéfices.
// 								// Failure swings
// 								//TODO with oscillator StockAnalysis
// 								return 0;
// 					}
// 					
					
		}

		/* RSI
// 		public function RSI($period = 14, $limbasse = 30, $limhaute = 70)
// 		{
// 			//TODO : interpreter
// 			$RSI = trader_rsi($this->cache, $period);
// 			print_r($RSI);
// 			exit();
// // 			$RSI = trader_rsi(array_slice($this->cache, $period *-2), $period);
// 			$R = end($RSI);
// 			if($R < 50)
// 				if(prev($RSI)<$limbasse && $R > $limbasse) // a franchi a la hausse le seuil de survente
// 					// 				return 2;
// 					// 			elseif($R < $limbasse+10)
// 					return 1;
// 				else
// 					return 0;
// 				else
// 					if(prev($RSI)>$limhaute && $R < $limhaute)
// 						// 				return -2;
// 						// 			elseif($R > $limhaute -10)
// 						return -1;
// 					else
// 						return 0;
// 					return 0;
// 		}*/

/* RSI35
// 		public function RSI35()
// 		{
// 			return $this->RSI(14, 35);
// 		}
// 		public function RSI40()
// 		{
// 			return $this->RSI(14, 40);
// 		}
// 		public function FastRSI35()
// 		{
// 			return $this->RSI(9, 35);
// 		}
// 		public function SlowRSI35()
// 		{
// 			return $this->RSI(25, 35);
// 		}
// 		public function FastRSI40()
// 		{
// 			return $this->RSI(9, 40);
// 		}
// 		public function SlowRSI40()
// 		{
// 			return $this->RSI(25, 40);
// 		} */
	
/* RegressionLineaire
// 		public function RegressionLineaire($data)
// 		{
// 			//TODO : it's for Testing only... see if it can obtain some useful infos
// 			// 		return trader_linearreg_angle(array_slice($this->cache, -150), 30);
// 			return trader_midpoint($data, 25);
// 			// 		return trader_linearreg($data);
// 		}
		
// 		public function Trendline()
// 		{	
// 			return trader_ht_trendline(array_slice($this->cache, -100));
// 		}*/
		
		public function MOM($period = 12)
		{
			return $this->_trader_("mom", array($this->getData('close'), $period));
		}	
		
		public function Bollinger($period = 20, $parm1 = 2.0, $parm2 = 2.0, $mtype = TRADER_MA_TYPE_SMA)
		{
			return $this->_trader_("bbands", array($this->getData('close'), $period, $parm1, $parm2, $mtype));
		}
		
		public function SAR($acc = .02, $max = .2)
		{
			return $this->_trader_("sar", array($this->buildData('high'), $this->buildData('low'), $acc, $max));
// 			if(end($SAR) < end($this->cache))
// 				if(prev($SAR) > prev($this->cache))
// 					return 1;// achat lorsque le SAR passe sous le cours
// 					else
// 						return 0; // sinon conserver, la tendance du sar est inchangée
// 						else
// 							if(prev($SAR) < prev($this->cache))
// 								return -1;//idem à la vente
// 								else
// 									return 0;
		}
		
		public function Trix($period = 8)
		{
			return $this->_trader_("trix", array($this->getData("close"), $period));
/*			
			$lastx = end($trix);
			$prevx = prev($trix);
			if($lastx > 0 && $prevx <0)
				return 1; // signal d'achat
				if($lastx <0 && $prevx > 0)
					return -1; // signal de vente;
				return 0; // signal neutre*/
		}
		
		public function Aroon($period = 14)
		{
			return $this->_trader_('aroon', array($this->getData('high'), $this->getData('low'), $period));
		}
		
		public function AroonOscillator($period = 14)
		{
			return $this->_trader_('aroonosc', array($this->getData('high'), $this->getData('low'), $period));
		}
		
		public function ROC($period = 10)
		{
			return $this->_trader_('rocr100', array($this->getData('close'), $period));
		}
		
		public function MoneyFlowIndex($period = 14)
		{
			return $this->_trader_('mfi', array($this->getData('high'), $this->getData('low'), $this->getData('close'), $this->getData('volume'), $period));
		}
		public function MFI($period = 14)
		{
			return $this->MoneyFlowIndex($period);
		}
		
/* Chaikinn		
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
		// 	}*/
		
		/* Stochastics  */
		public $StochHigh = 80, $StochLow = 20;
		public function StochasticLimit($low = 20, $high = 80)
		{
			$this->StochHigh = $high;
			$this->StochLow = $low;
			return $this;
		}
		public function Stochastic($KPeriod = 14, $slowKPeriod = 3, $slowDPeriod=3, $kmm = TRADER_MA_TYPE_SMA, $dmm = TRADER_MA_TYPE_SMA)
		{
			return $this->_trader_("stoch", array($this->getData('high'), $this->getData('low'), $this->getData('close'), $KPeriod, $slowKPeriod, $kmm, $slowDPeriod, $dmm));

		}
		public function LongStochastic()
		{
			return $this->Stochastic(39,1,1);
		}
		
		public function OBV()
		{
			return $this->_trader_('obv', array($this->getData('close'), $this->getData('volume')));
		}
		
		// Returns somekind of weight of volumes during the 5 last days.
		// Donne la puissance de la tendance.
		public function Volumes($period = 5)
		{
			$hashed_name = __FUNCTION__.":$period";
			// try to use cached values today.
			if(!empty($this->stock->getLast('TA')[$hashed_name]))
				return $this->_cache($hashed_name);
			
			$arr = array();
			foreach($this->getData('volume') as $date => $vol)
			{
				$arr[] = $vol;
				if(count($arr >= $period))
				{
					if(count($arr)>$period)	
						array_shift($arr);
					$this->stock->data[$date]->TA(
						$hashed_name,
						$this->buildCache['TA'][$hashed_name][$date] = 
							round(array_sum($arr)/$period, 4));
				}
			}
			/*
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
						//TODO : buildCache
			
			$this->buildCache['TA'][$hashed_name][$date] = $supertrend;*/
			return $this->_cache($hashed_name);
		}
		public function LongVolumes()
		{
			return $this->VolumesOscillator(14,28);
		}
		public function VolumesOscillator($short = 5, $long = 20)
		{
			$hashed_name = __FUNCTION__.":$short:$long";
			// try to use cached values today.
			if(!empty($this->stock->getLast('TA')[$hashed_name]))
				return $this->_cache($hashed_name);
			
			$short = $this->Volumes($short);
			foreach($this->Volumes($long) as $date => $long)
			{
				$this->stock->data[$date]->TA(
					$hashed_name,
					$this->buildCache['TA'][$hashed_name][$date] = 
					round($short[$date] / $long, 4));
			}
			
			return $this->_cache($hashed_name);
			
// 			return $this->Volumes($short,$long) <1 ? -1 : 1;
// 			$mean = array_sum(array_slice($this->buildData('Volume'), $period*-1))/$period;
// 			return round(( end($this->buildData('Volume')) - $mean ) / $mean, 3);
		}
		public function VolumeOscillator($short = 5,$long = 20)
		{
			return $this->VolumesOscillator($short,$long);
		}
		public function LongVolumesOscillator()
		{
			return $this->VolumesOscillator(14, 28);
		}
		
		public function Candle()
		{
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
// 				'3blackcrows',
// 				'counterattack',
// 				'mathold',
// 				'tasukigap',
// 				'gapsidesidewhite',
// 				'2crows',
				// 			'darkcloudcover', // A voir... donne des ordres de vente un peu trop faciles
				// 			'xsidegap3methods', // too late. too obvious.
				// 			'hangingman'
// 				);
				/*  Testing ...
				 *	$indecisecdl = array(
				 *		'highwave',
				 *		'harami', 
				 *		'haramicross', 
				 *		'spinningtop',
				 *		'rickshawman', 
				 *		);
				 *	$retournementcdl = array(
				 / */ 			
// 				 'upsidegap2crows', 
// 				 'doji', 
// 				 'dojistar', 
// 				 'dragonflydoji', 
// 				 'morningdojistar', 
// 				 'eveningdojistar',
// 				 'gravestonedoji',
// 				 'longleggeddoji',
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
				
				 
				static $re = array();
				if(empty($re))
				foreach($usefulcdl as $func)
				{
					foreach($this->_trader_('cdl'.$func, array(
						$this->getData('open'),
						$this->getData('high'),
						$this->getData('low'),
						$this->getData('close'))) as $date => $v)
						
						@$re[$date] += $v;
				}
				
				return $re;
		}
		
		// returns -100 for a
		public function Gaps()
		{
			static $re = array();
			if(empty($re))
			{
				$i = 0;
				$hi = $this->getData('high');
				$p = array();
				foreach($this->getData('low') as $date => $l)
				{
					$h = $hi[$date];
					if($i++){ 
						$re[$date] = 0;
						if($l > $p[0]) // high < low ==> gap à la hausse
							$re[$date] = 100;
						if($h < $p[1])
							$re[$date] = -100;
					}
					$p = array($h,$l);
				}
			}
			return $re;
		}
		
		public function Engulfing()
		{
			static $re = array();
			if(empty($re))
			{
				$i = 0;
				$op = $this->getData('open');
				$p = array();
				foreach($this->getData('close') as $date => $c)
				{
					$o = $op[$date];
					if($i++){ 
						$re[$date] = 0;
						if($p[0] > $p[1] && $o <= $p[1] && $c >= $p[0]) // engolfante à la hausse
							$re[$date] = 100;
						if($p[0] < $p[1] && $o >= $p[1] && $c <= $p[0]) // engolfante à la baisse
							$re[$date] = -100;
					}
					$p = array($o,$c);
				}
			}
			return $re;
		}
		
		public function Harami()
		{
			static $re = array();
			if(empty($re))
			{
				$i = 0;
				$op = $this->getData('open');
				$hi = $this->getData('high');
				$lo = $this->getData('low');
				$p = array();
				foreach($this->getData('close') as $date => $c)
				{
					$o = $op[$date];
					$h = $hi[$date];
					$l = $lo[$date];
					if($i++){ 
						$re[$date] = 0;
						if($l >= min($p[0], $p[1]) && $h <= max($p[0], $p[1]))
						{
							if($p[0] > $p[1] && $o <= $c) // harami à la hausse
								$re[$date] = 100;
							if($p[0] < $p[1] && $o >= $c) // harami à la baisse
								$re[$date] = -100;
						}
					}
					$p = array($o,$c);
				}
			}
			return $re;
		}
		
		public function NewHigh($period = 30)
		{
			static $re = array();
			if(empty($re))
			{
				$hi = $this->getData('high');
				if(count($hi) < $period+1)	return false;
				$i = 0;
				foreach($hi as $date => $h)
				{
					$re[$date] = 0;
					if($i++ < $period) continue;
					if(max(array_slice($hi, $i-$period, $period)) == $h)
						$re[$date] = $h;
				}
			}
			return $re;
		}
		
		public function NewLow($period = 30)
		{
			static $re = array();
			if(empty($re))
			{
				$lo = $this->getData('low');
				if(count($lo) < $period+1)	return false;
				$i = 0;
				foreach($lo as $date => $l)
				{
					$re[$date] = 0;
					if($i++ < $period) continue;
					if(min(array_slice($lo, $i-$period, $period)) == $l)
						$re[$date] = $l;
				}
			}
			return $re;
		}
		
		public function CCI($period = 14) // Commodity Channel Index
		{
			return $this->_trader_('cci', array($this->getData('high'), $this->getData('low'), $this->getData('close'), $period));
/*			
			$cci = trader_cci(
				array_slice($this->buildData('High'), $period*-2),
				array_slice($this->buildData('Low'), $period*-2),
				array_slice($this->buildData('AdjustedClose'), $period*-2),
				$period);
				if(end($cci) >-100 && prev($cci) <-100) // cross CCI de la limite de survente à la hausse
					return 1;
				if(end($cci) <100 && prev($cci)>100) // cross CCI a la baisse ..
					return -1;
				return 0;*/
				//TODO : Interpret Divergences
		}
		
		public function Beta_(StockQuote $CAC40, $period = 100)
		{
			return $this->_trader_('beta', array($this->getData('close'), $CAC40->Close(true), $period));
		}
		
		public function Beta()
		{
			// 		var_dump($CAC40);
			$CAC40 = new StockQuote(new Stock('cac40'));
			$CAC = array_values($CAC40->Close(true));
			return standard_covariance(array_values($this->getData('close')), $CAC) / variance($CAC);
			
			// 		$beta = trader_beta($this->cache, $m, count($m)-1);
			// 		return end($beta)*100;
			
		}
/*Benchmark		
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
		// 	}*/
		
		public function Supertrend($period = 10, $coeff = 3)
		{
			// Try to use cached values
			$hashed_name = __FUNCTION__ .':'.$period.':'.$coeff;
			// try to use cached values today.
			if(!empty($this->stock->getLast('TA')[$hashed_name]))
				return $this->_cache($hashed_name);
			
			//else, generate supertrend values
			$Highs = $this->getData('high');
			$Lows = $this->getData('low');
			$Closes = $this->getData('close');
			//buffer
// 			$re = array();
			foreach(
				$this->_trader_('atr', array(	
					$this->getData('high'), 
					$this->getData('low'), 
					$this->getData('close'), 
					$period
				))
				as $date => $atr)
			{
				static $upband = 0, $downband = 0, $close = 0, $supertrend = 0;
				$high = $Highs[$date];
				$low = $Lows[$date];
				if($close == 0)
				{
					$Closes[$date];
					$close = prev($Closes);
				}
				// Calculate the upband basic,
				$uptrend = (($high + $low) /2) + ($coeff*$atr); //UPTREND
				// decrement the upband.
				$_upband = ($uptrend < $upband || $close > $upband) ? $uptrend : $upband; 
				// idem with down trends
				$downtrend = (($high + $low) /2) - ($coeff*$atr);  //DOWNTREND
				$_downband = ($downtrend > $downband || $close < $downband) ? $downtrend : $downband;
				
				$close = $Closes[$date];
				
				// generate the supertrend value in itself.
				/*$re[$date] = */$supertrend = 
				($supertrend == $upband) ? 
					($close < $_upband ? 
						$_upband :
						$_downband)
						:
					($close > $_downband ?
						$_downband :
						$_upband);
						
				//update the statics.
				$downband = $_downband;
				$upband = $_upband;
				
				//push data to cache
				$this->stock->data[$date]->TA(
					$hashed_name,
					$this->buildCache['TA'][$hashed_name][$date] = $supertrend);
			}
			return $this->_cache($hashed_name);
		}
}

function standard_covariance(Array $aValues, Array $bValues)
{
	$a= (array_sum($aValues)*array_sum($bValues))/count($aValues);
	$ret = array();
	for($i=0;$i<count($aValues);$i++)
	{
		$ret[$i]=$aValues[$i]*$bValues[$i];
	}
	$b=(array_sum($ret)-$a)/(count($aValues)-1);        
	return (float) $b;
}
function variance($aValues, $bSample = false){
	$fMean = array_sum($aValues) / count($aValues);
	$fVariance = 0.0;
	foreach ($aValues as $i)
	{
		$fVariance += pow($i - $fMean, 2);
	}
	$fVariance /= ( $bSample ? count($aValues) - 1 : count($aValues) );
	return $fVariance;
}
