<?php


class StockAnalysis
{
	public static $Weight = array(
		'MACD' 	=> 2,
		'MM'	=> 4,
		);
		
		private $stock = null;
		private $cache = array();
		public function __construct(StockQuote $stock, $closeVal = StockQuote::DEFAULT_SUB)
		{
			if(!function_exists('trader_macd'))
				throw new Exception('PECL\'s Trader >= 0.4.0 package must be installed and activated in your PHP distribution.');
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
		private function buildData($close = StockQuote::DEFAULT_SUB)
		{
			$close = $this->stock->subDataIndex($close);
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
		
		public function Volatility(/*$period = 200*/)
		{
			// 		$vol = trader_var(array_slice($this->cache, $period *-1), $period);
			// // 		return end($vol);
			// 		return sqrt(end($vol));Volatility
			// 		$a = array_slice($this->cache, $period*-1);
			// 		$n = $period;
			$n = count($this->cache);
			$mean = $this->Moyenne();
			$carry = 0.0;
			foreach ($this->cache as $val) {
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
			$high = $this->BuildData('High');
			$low = $this->BuildData('Low');
			$re = array();
			foreach($this->cache as $i => $close)
				$re[] = ($high[$i] - $low[$i]) / $close;
			return round(array_sum($re)/count($close), 2);
		}
		
		public function Moyenne()
		{
			return array_sum($this->cache)/count($this->cache);
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
					// 				return 2;
					// 			elseif($R < $limbasse+10)
					return 1;
				else
					return 0;
				else
					if(prev($RSI)>$limhaute && $R < $limhaute)
						// 				return -2;
						// 			elseif($R > $limhaute -10)
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
		public function RSI35()
		{
			return $this->RSI(14, 35);
		}
		public function RSI40()
		{
			return $this->RSI(14, 40);
		}
		public function FastRSI35()
		{
			return $this->RSI(9, 35);
		}
		public function SlowRSI35()
		{
			return $this->RSI(25, 35);
		}
		public function FastRSI40()
		{
			return $this->RSI(9, 40);
		}
		public function SlowRSI40()
		{
			return $this->RSI(25, 40);
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
		
		public function SAR($acc = .02, $max = .2)
		{
			$SAR = trader_sar($this->buildData('High'), $this->buildData('Low'), $acc, $max);
			if(end($SAR) < end($this->cache))
				if(prev($SAR) > prev($this->cache))
					return 1;// achat lorsque le SAR passe sous le cours
					else
						return 0; // sinon conserver, la tendance du sar est inchangée
						else
							if(prev($SAR) < prev($this->cache))
								return -1;//idem à la vente
								else
									return 0;
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
		public function LongVolumesOscillator()
		{
			return $this->VolumesOscillator(14, 28);
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
				 *	$indecisecdl = array(
				 *		'highwave',
				 *		'harami', 
				 *		'haramicross', 
				 *		'spinningtop',
				 *		'rickshawman', 
				 *		);
				 *	$retournementcdl = array(
				 / */ 			'upsidegap2crows', 
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
		
		public function Beta(Stock $CAC40)
		{
			// 		var_dump($CAC40);
			$m = array();
			foreach($CAC40 as $d)
				$m[] = $d;
			return standard_covariance(array_values($this->cache), $m)*100 / variance($m);
			
			// 		$beta = trader_beta($this->cache, $m, count($m)-1);
			// 		return end($beta)*100;
			
		}
		
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
		public function _trader_supertrend($period = 10, $coeff = 3)
		{
			$closes = $this->buildData('Close');
			$highs = $this->buildData('High');
			$lows = $this->buildData('Low');
			for($i = $period+1; $i<= count($this->cache); $i++)
			{
				$av = ($highs[$i] + $lows[2]) / 2;
				$atr = end(trader_atr(
					array_slice($highs, $i-$period, $period),
					array_slice($lows, $i-$period, $period),
					array_slice($closes, $i-$period, $period),
					$period
					);
					$offset = $coeff*$atr;
					//TODO : continuer supertrend
					// 			La première étape consiste à calculer l'ATR ("Average true Range" qui est une mesure de la volatilité) sur toute la période d'historique puis ensuite les deux bandes de l'indicateur :
					// 
					// Tendance haussière = (plus haut + plus bas) / 2 + c * ATR(n)
					// Tendance baissière = (plus haut + plus bas) / 2 - c * ATR(n)
					// 
					// Avec :
					// 
					// n : période de calcul de l'ATR, typiquement 10
					// c : coefficient permettant de pondérer la volatilité mesurée par l'ATR. La formule standard utilise un coefficient de 3
					
			}
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
