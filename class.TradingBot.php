<?php

function walk_recursive_remove (array $array, callable $callback) { 
    foreach ($array as $k => $v) { 
        if (is_array($v)) { 
            $array[$k] = walk_recursive_remove($v, $callback); 
        } else { 
            if ($callback($v, $k)) { 
                unset($array[$k]); 
            }
        }
    }
    return $array; 
}

class TradingHistory
{
	const HISTORY_FILE = 'trading.dat.gz';
	const GZIP = -1; // GZIP compressing level, 1-9, -1 to defaults (usually defaults to 5)
	private $data = array(
		/*
			'[Isin]' => array(
				'Vente' => array(
					array(
						'Expire' => (timestamp),
						'Seuil|Limit' => (float),
						'Qte' => (int),
						'Odre' => OrdreEnCours $ordre,
						),
					array() // Seuil n°2 ...
				),
				'Achat' => array(
					array( )
				),
			);
		*/
	);
	public function __construct()
	{
		// construct data
		if(!file_exists(self::HISTORY_FILE))	return;
		$this->data = unserialize(gzdecode(file_get_contents(self::HISTORY_FILE)));
// 		print_r($this->data);
		$this->clean();
// 		print_r($this->data);
		return $this;
	}
	
	private function clean()
	{
		$this->data = walk_recursive_remove($this->data, function($v)
			{
				return (is_array($v) && array_key_exists('Expire', $v) && $v['Expire'] < time());
			});
		
		return $this;
	}
	
	public function getOrdersFor($Isin, $sens = null)
	{
// 		print $Isin;
		if(array_key_exists($Isin, $this->data))
			if(!empty($this->data[$Isin]))
				return is_null($sens) ? $this->data[$Isin] : 
					( ($sens == 1 || strtolower($sens[0]) == 'a') ?
						$this->data[$Isin]['Achat'] :
						$this->data[$Isin]['Vente']);
		return false;
	}
	
	public function AddOrder($sens, _OrdreEnCours $Ordre, $AdditionalData = array())
	{
		$this->data[$Ordre->IsinCode][$sens][] = array_merge(
			array('Ordre' => $Ordre),
			$AdditionalData);
		return $this;
	}
	
	/*public function Add($Ref, $Isin = null, $Qte = null, $Seuil = null, $Expire = null, $SeuilNo = null)
	{
		if(is_array($Ref))
			if(!empty(array_diff(array('SeuilNo', 'Ref', 'Isin', 'Seuil', 'Qte', 'Expire'), array_keys($Ref))))
				throw new Exception('Missing a data in TradingHistory::Add()');
			else
				extract($Ref);
		else
			if(is_null($Isin) || is_null($Qte) || is_null($Seuil) || is_null($Expire))
				throw new Exception('Missing an argument in TradingHistory::Add()');
		$this->data[(string)$Ref] = array(
			'Isin' => (string)$Isin,
			'Qte' => (int)$Qte,
			'Seuil' => (float)$Seuil,
			'Expire' => (int)is_int($Expire) ? $Expire : strtotime($Expire),
			'SeuilNo' => (int)$SeuilNo
			);
		return $this;
	}*/
	
	public function __destruct()
	{
// 		print_r($this->data);
		// Dump data.
		file_put_contents(self::HISTORY_FILE, gzencode(serialize($this->data), self::GZIP));
		return true;
	}
	
	
}

class TradingBot
{
	const VERBOSE = false; //Verbosity, true or false;
	const EXTERNAL_INDICATORS = 'TradingBotIndicators.php'; // The PHP file containing the trading indicators.

	const SOMME_MINIMALE = '1000 €';
	const FRAIS_BOURSIERS = '0.9%';
	// Vente auto
	const BENEFICE_MINIMAL = '15%'; // le benefice minimal a partir duquel la question du seuil doit se poser.
	const SEUIL_EXPIRE_WEEKS = 1;
	// Les seuils se calculent selon cours actuel - {$seuil}% 
	// eg : action à 100eur, trois seuils à 5% 6% et 7% :
	// => seuils à 95, 94 et 93euros.
	const SEUIL_POLICY = '4%;5%'; // multiple seuils allowed, splited with ";". eg: 5%;5.5%;6%
	const POLICY_PRIORITY = 'ASC'; // ASC = la priorité est le seuil le plus proche du cours. => maximise les benefices
									// DESC = la priorité est au seuil le plus lointain. => moins d'ordres executés.
	const STOPLOSS = true; // par défaut, mettre des stoploss
	// Achat Auto
	const INDICATEUR_ACHAT = 'RSI&Stochastic'; // Will check if RSI() and Stochastic() returns a buy signal. It's the default signal.
	const VALO_MAX = '4000 €'; // La valorisation maximale par action. Si la valeur portefeuille dépasse, l'ordre d'achat est annulé.
	
	public $GlobalParams = array(
		'FraisBoursiers' => self::FRAIS_BOURSIERS,
		'BeneficeMinimal'=> self::BENEFICE_MINIMAL,
		'SommeMinimale' => self::SOMME_MINIMALE,
		'SeuilExpireWeeks' => self::SEUIL_EXPIRE_WEEKS,
		'SeuilPolicy' => self::SEUIL_POLICY,
		'PolicyPriority' => self::POLICY_PRIORITY,
		'StopLoss' => self::STOPLOSS,
		'IndicateurAchat' => self::INDICATEUR_ACHAT,
		'ValorisationMax' => self::VALO_MAX,
		);
	public $ByISINParams = array(
		/*
			'Isin' => array(
				//GlobalParams like
				);
		*/
		// See more benchmarks https://docs.google.com/spreadsheets/d/1ekQSj2Y0468rR16UQAm1m702RulnPcs4Bcezd-N98wI/edit
// 		'FR0000120073' => array( // Air Liquide [AI]
// 			'IndicateurAchat' => 'RSI&LongStochastic|SignalMACD&CCI&VolumesOscillator' //|RSI&Stochastic specific buy signal
// 			),
		'FR0000121667' => array( // Essilor [EI]
			'IndicateurAchat' => 'RSI&Williams|CCI&RSI|RSI&Stochastic',
			),
		'FR0000120578' => array( // Sanofi [SAN]
			'IndicateurAchat' => 'CCI&SignalMACD&VolumesOscillator' // Aucun
			),
// 		'FR0000120222' => array( // CNP Assurances [CNP]
// 			'IndicateurAchat' => 'RSI&LongStochastic|SignalMACD&CCI&VolumesOscillator' // Aucun
// 			),
		'FR0004035913' => array( // Iliad [ILD]
			'IndicateurAchat' => 'RSI&LongStochastic|RSI&CCI&VolumesOscillator'
			),
		'FR0000133308' => array( // Orange [ORA]
			'IndicateurAchat' => 'RSI&VolumesOscillator' //Auto to RSI, enhanced with volumes to slightly increase specificity
			),
		'FR0000121261' => array( // Michelin [ML]
			'IndicateurAchat' => 'RSI&Stochastic|SignalMACD&CCI&VolumesOscillator' // defaults
			),
		'CH0012214059' => array( // Lafarge LHN
			'IndicateurAchat' => array(), // A voir...
			),
		'FR0000130007' => array( // Alcatel-Lucent ALU
			'IndicateurAchat' => array(), // Racheté par Nokia, se reporter sur l'action Nokia
			),
		'FR0000035081' => array( // Icade ICAD
			'IndicateurAchat' => array() // Non éligible au PEA.
			),
		'FR0010667147' => array( // Coface COFA
			'IndicateurAchat' => array() // Non éligible au PEA.
			),
		// Par défaut, les données auto générées par le générateur, puis dans tous les cas, en IsinParams
		// Le reste est par défaut RSI&Stochastic
		);
	protected $Watchlist = array();

	private $DB = null;
	private $CM = null;
// 	private $Stock = null;
	
	public function __construct(Broker $CM)
	{
		$this->CM = $CM;
// 		$this->Stock = $Stock;
		$this->DB = new TradingHistory();
		
		//Load External Indicators Data
		$this->ByISINParams = array_merge(self::getExternalIndicators(), $this->ByISINParams);
		
		return $this;
	}
	
	public function __get($k)
	{
		if(array_key_exists($this->curr, $this->ByISINParams))
			if(array_key_exists($k, $this->ByISINParams[$this->curr]))
				return $this->ByISINParams[$this->curr][$k];
		if(array_key_exists($k, $this->GlobalParams))
			return $this->GlobalParams[$k];
		throw new Exception('Wrong key ['.$k.'] in Params');
	}
	
	public function GlobalParams($key, $val)
	{
		if(!array_key_exists($key, $this->GlobalParams))
			throw new Exception('Wrong Param');
		$this->GlobalParams[$key] = $val;
		return $this;
	}
	
	public function IsinParams($isin, $key, $val, $append = false)
	{
		if(!$this->CM->isISIN($isin))
			throw new Exception('Wrong ISIN ['.$isin.']');
		if($append)
			$this->ByISINParams[$isin][$key] .= $val;
		else
			$this->ByISINParams[$isin][$key] = $val;
		return $this;
	}
	/*
	public function BuildWatchlist($list = Stock::$YahooSBF120)
	{
		$CAC40 = new Stock(YahooStock::CAC40);
		foreach($list as $act)
		{
			//TODO pondérer avec la valeur du Beta, portefeuille management.
			$this->Watchlist(new Stock($act));
		}
		return $this;
	}*/
	
	public function Watchlist(Stock $stock, $isin = '', $sens='A')
	{
		if(!$this->CM->isISIN($isin))
		{
			$isin = strstr($stock->stock, '.', true);
			if(!$this->CM->isISIN($isin))
				throw new Exception('Wrong ISIN ['.$isin.']');
		}
		if(is_string($sens) && (strtolower($sens[0])=='v' || strtolower($sens[0])=='s'))
			$sens = -1;
		else
			$sens = 1;
// 		if(is_string($ind))
// 			foreach(explode('|', $ind) as $ou)
// 				$ind[] = explode('&', $ou); // $et
		$this->Watchlist[$isin] = array($stock, $isin, $sens);
		return $this;
	}
	
	private function IndSplit($ind)
	{
		if(is_string($ind) && !empty($ind))
		{
			$i = array();
			foreach(explode('|', $ind) as $ou)
				$i[] = explode('&', $ou); // $et
			return $i;
		}
// 		print_r($i);
		return $ind;
	}
	
	private $Valorisation = array();
	private $curr = 'isin';
	const DAILYCHECKUP_VENTE = 0x1;
	const DAILYCHECKUP_ACHAT = 0x2;
	const DAILYCHECKUP_BOTH = 0x3;
	public function DailyCheckup($mode = self::DAILYCHECKUP_BOTH)
	{
		if(self::VERBOSE)
			print "Daily Tasks starting...\n\n";
		if($mode & self::DAILYCHECKUP_VENTE)
			foreach($this->CM->Valorisation() as $stock)
			{
				$this->Valorisation[$stock->isin] = $stock;
				$this->curr = $stock->isin;
				if(self::VERBOSE)
					print (string)$stock . ' ('.$stock->PlusvaluePCT . ")\n";
				
				if($this->StopLoss)
					// Placer des ordres Stops si position favorable, selon la Seuil_policy
					$this->Seuils($stock);
				
			}
		if($mode & self::DAILYCHECKUP_ACHAT)
			foreach($this->Watchlist as $isin => $watch)
			{
				$this->curr = $isin;
				try{
					if(self::VERBOSE)
						print "\n".$this->curr ." Recherche d'indicateurs à l'achat :";
					$this->Achats($watch);
				}catch(Exception $e)
				{
					print $e->getMessage();
				}
			}
		
		$this->curr = null; // reset isin pointer
		return $this;
	}
	
	private function Achats($watch)
	{
		list($stock, $isin, $sens) = $watch;
		$ind = $this->IndSplit($this->IndicateurAchat);
// 		print_r($ind);
		if(empty($ind))
		{
			if(self::VERBOSE)
				print "Aucun indicateur n'a été spécifié.";
			return $this; // Si aucun indicateur n'est spécifié, abort.
		}
		$tacache = array();
		foreach($ind as $ou)
		{
			if(self::VERBOSE)
				print "\n".' '.$stock->stock.' '.implode('&',$ou)." :";
			foreach($ou as $func) //required func
			{
				if(!isset($tacache[$func]))
					$tacache[$func] = $stock->Analysis()->$func();
				if($tacache[$func] <= 0)
				{
					if(self::VERBOSE)
						print '  '.$func.' negatif, aborting.';
					continue 2; // Passe au second indicateur si le premier rétorque faux.
				}
				else
					if(self::VERBOSE)
						print '  '.$func.' positif';
			}
			// La combinaison d'indicateur donne un signal d'achat.
// 			return $sens == 1 ? $this->OrdreAchat($stock) : $this->OrdreVente($stock);
			if(self::VERBOSE)
				print ' => Signal d\'achat !'."\n";
			return $this->OrdreAchat($stock, $isin);
			break;
		}
	}
	private function OrdreAchat(Stock $stock, $isin)
	{
// 		foreach($this->Valorisation() as $valeur)
		if(array_key_exists($isin, $this->Valorisation))
			//La valeur à acheter est déja dans le portefeuille,
			if((float)$this->Valorisation[$isin]->ValueInEur >= (float)$this->ValorisationMax)
				throw new Exception('Valorisation maximale pour '.(string)$this->Valorisation[$isin].' est atteinte à '.$this->Valorisation[$isin]->ValueInEur);
		$nominal = ceil((float)$this->SommeMinimale / (float)$stock->getLast());
		
		if(self::VERBOSE)
			print 'ACHAT '.$nominal.' '.$stock->stock.' ['.$isin.'] au dernier cours ('.$stock->getLast().'€)'."\n";
			
		$this->CM->Ordre($isin)->Achat($nominal)
// 		->AuDernierCours($stock->getLast()) // pass this arg for simulator compatibility
		->ACoursLimite($stock->getLast()) // For Simulator
		->Jour()->Exec();
		return $this;
	}
	
	private function Seuils(Action $stock)
	{
// 		print $stock->PlusvaluePCT . $this->BeneficeMinimal . $this->FraisBoursiers;
		if((float)$stock->PlusvaluePCT > (float)$this->BeneficeMinimal+(float)$this->FraisBoursiers) // Gain supérieur à 5% depuis le prix de revient, comprenant les frais boursiers.
		{
			// En position de mettre un ou des seuil(s) --
			// nouveau seuil = 
// 			$ordres = array();
			$qte = (int) $stock->qte;
			$cours = (float) $stock->DernierCours;
			
			// les seuils à faire...
			$policy = explode(';',$this->SeuilPolicy);
			if($this->PolicyPriority == 'ASC')
				sort($policy, SORT_NUMERIC);
			else
				rsort($policy, SORT_NUMERIC);
			array_walk($policy, function(&$v){
				if($v > (float) $this->BeneficeMinimal)
// 					exit( 'Position perdante !' );
					$v = $this->BeneficeMinimal; // Previent le seuil d'être inférieur au bénéfice minimal. Le but est de ne jamais perdre d'argent.
				if($v < $this->FraisBoursiers*2) // Si le seuil est inférieur aux frais boursiers, 
					$v = $this->FraisBoursiers*2; // arbitrairement à 2, pour ne pas gagner moins que le courtier.
				});
			$policy = array_unique($policy, SORT_NUMERIC); // Remove duplicates if any.
// 			print_r($policy);
			// les quantités d'actions pour chaque seuil.
			$nbSeuilsAFaire = min(
				array(
					floor(
						$this->CalcCours($cours, end($policy)) // le cours * le dernier seuil possible, comprenant les frais boursiers.
						*$qte		// le cours par la quantité représente une somme obtensible maximale
						/(int)$this->SommeMinimale // 1000e, car les frais boursiers ont un prix plancher.
					), 
					count($policy)
				)
				);
			if($nbSeuilsAFaire <1)	$nbSeuilsAFaire = 1; // Si la quantité d'actions * le cours est inférieure a la somme minimale... genre ~500e de plus value latente
// 			print 'nbSeuils : '.$nbSeuilsAFaire;
			
			$seuils = array_map(null,
				// Seuils  (index 0)
				array_slice(
					array_map(
						function($v) use ($cours) {
							return $this->Step($this->CalcCours($cours, $v)); // Constuit les seuils sur le dernier cours connu.
						},	
						$policy),
				0, $nbSeuilsAFaire),// selectionne les seuils compatibles.
				// Qté (index 1)
				array_fill(0, $nbSeuilsAFaire, floor($qte/$nbSeuilsAFaire))
				);
			$seuils[0][1] += $qte%$nbSeuilsAFaire;
			
			$i = 0;
			do // Determine les ordres à passer selon les multiples seuils
			{
				
				try{
// 				print "Nseuil $nseuil - QSeuil {$qseuil[$i]}\n";
				$oseuil = $this->DB->getOrdersFor($stock->isin, 'Vente');
// 				var_dump($oseuil);
				if(is_array($oseuil))
					if(isset($oseuil[$i]))
					{
						if($oseuil[$i]['Seuil'] <= $seuils[$i][0])
							$oseuil[$i]['Ordre']->Delete(); //Supprime l'ancien ordre avec un seuil inf.
					}
				}catch(Exception $e)
				{
					print $e->getMessage();
				}
				// Ajout de l'ordre
				try{
					$dat = $this->CM->Ordre($stock->isin)
						->Vendre($seuils[$i][1]) // Qté
// 						->ASeuil($seuils[$i][0]) // Seuil
						->APlage($seuils[$i][0], $this->Step($this->CalcCours($stock->PrixDeRevient, 0))) //A Plage de déclenchement, dont la limite est le prix de revient à bénéfice 0 frais de bourse inclus
						->Hebdomadaire($this->SeuilExpireWeeks)
						->Exec()
						;
					if(self::VERBOSE)
						print 'ADDING ORDER '.$stock->isin.' : '.$seuils[$i][1].' * seuil = '.$seuils[$i][0].', expire '.$this->SeuilExpireWeeks ." week\n";
					$this->DB->AddOrder('Vente',
						$dat, 
						array(
							'Seuil' => $seuils[$i][0],
							'Expire' => date('w') == 5 && strtotime(Ordre::FERMETURE_BOURSE) > time() ? strtotime(Ordre::FERMETURE_BOURSE) : strtotime('Friday +'.$this->SeuilExpireWeeks -1 .'weeks '.Ordre::FERMETURE_BOURSE)
						));
				}catch(Exception $e)
				{
					print $e->getMessage();
				}
			}while(++$i < $nbSeuilsAFaire);
		}
		else
			if(self::VERBOSE) 
				print ' Position perdante, pas de vente pour le moment car pas de vente à perte.'."\n";
	}
	
	private function Step($nseuil)
	{
// 		print $nseuil;
		if($nseuil < 20) $nseuil = round($nseuil*200)/200; //<20 => 3 chiffres apres la virgule,
		elseif($nseuil >= 100) $nseuil = round($nseuil*20)/ 20; // >100 => step every .05
		else	$nseuil = round($nseuil, 2); // 20-100 , deux chiffres apres la virgule.
		return $nseuil;
	}
	
	private function CalcCours($cours, $pct)
	{
		return (float)$cours * (100 - (float)$pct + (float)$this->FraisBoursiers)/100;
	}

	public function __destruct()
	{
		foreach(get_object_vars($this) as $t)
			unset($t);
	}

	// Indicateur, $seuil = '6%'s Generator 
	public static function BestIndicator($action, $days = 500/*, $seuil = '6%'*/)
	{
		if(is_array($action))
		{
			$re=array();
			$re[] = self::BestIndicator($action, $days, $seuil);
			return $re;
		}
		$STO = new Stock($action, 'd', Stock::PROVIDER_CACHE/*, floor($days/260)*/); // cache last data yahoo data.
		// Calcule la Volatilité relative sur la dernière année.
		$STO = $STO->Slice($days*-1)->Analysis();
		$SeuilPolicy = ($STO->Volatility() / $STO->Moyenne());// /2 
		unset($STO);
		
		/*
		 *
		 * Build Indicators array
		 *
		 *
		 */
		$indics = array();
		$Indics = array(
			array('RSI', /*'RSI35', 'RSI40', 'FastRSI', 'FastRSI35', 'FastRSI40', 'SlowRSI', 'SlowRSI35', 'SlowRSI40'*/),
			array('SignalMACD'),
			array('CCI'),
			array('Williams', 'Stochastic', 'LongStochastic'),
			array('Candle'),
			array('SAR'),
			array('VolumesOscillator'/*, 'LongVolumesOscillator'*/),
			);
		
		// Flat indicators
		$_indics = array();
	    array_walk_recursive($Indics, function($a) use (&$_indics) { $_indics[] = $a; });
	    $_indics = array_unique($_indics);
	    
		for($i=0; $i<count($_indics); $i++)
	    {
			if($_indics[$i] != 'VolumesOscillator' && $_indics[$i] != 'LongVolumesOscillator')
				$indics[] = array($_indics[$i]);
			for($ii=$i; $ii<count($_indics); $ii++)
			{
				for($iii=$ii; $iii<count($_indics); $iii++)
				{
					if($i!=$ii && $iii!=$ii)
					{
						foreach($Indics as $ind)
							if((in_array($_indics[$i], $ind) && in_array($_indics[$ii], $ind) )||  (in_array($_indics[$iii], $ind) && in_array($_indics[$ii], $ind)) || (in_array($_indics[$i], $ind) && in_array($_indics[$iii], $ind)) )
								continue 2;
						$indics[] = array($_indics[$i], $_indics[$ii], $_indics[$iii]);
					}
				}
				if($i!=$ii)
				{
					foreach($Indics as $ind)
						if(in_array($_indics[$i], $ind) && in_array($_indics[$ii], $ind))
							continue 2;
					$indics[] = array($_indics[$i], $_indics[$ii]);
				}
			}
	    }
	    // And sorting by complexity, from 1 indicator to 3.
	    uasort($indics, function($a, $b){ return count($a)<count($b) ? -1 : 1;});

/*		$indics = array( // OBSOLETE,  AUTO GENERATED ABOVE
			//RSI seul en mode Buy sur sortie de survente, plusieurs sensibilités.
			array('RSI'), // 14 30 70
			array('RSI35'), // 14 35 70
			array('RSI40'), // 14 40 70
			array('FastRSI'), // 9 30 70
			array('SlowRSI'), // 25 30 70
			array('FastRSI35'), // 9 35 70
			array('FastRSI40'), // 9 40 70
			array('SlowRSI35'), // 25 35 70
			array('SlowRSI40'), // 25 40 70
			//Williams
			array('Williams'),
			//Candle
			array('Candle'), 
			//Stochastic
			array('Stochastic'),
			//LongStochastic
			array('LongStochastic'),
			//CCI
			array('CCI'),
			//SignalMACD
			array('SignalMACD'),
			//SAR
			array('SAR'),
			
			//-----2 passes
			//-------------
			// VolumesOscillator
			array('Williams', 'VolumesOscillator'),
			array('Candle', 'VolumesOscillator'),
			array('Stochastic', 'VolumesOscillator'),
			array('LongStochastic', 'VolumesOscillator'),
			array('CCI', 'VolumesOscillator'),
			array('SignalMACD', 'VolumesOscillator'),
			array('SAR', 'VolumesOscillator'),
			array('RSI', 'VolumesOscillator'),
			array('RSI35', 'VolumesOscillator'),
			array('RSI40', 'VolumesOscillator'),
			array('FastRSI', 'VolumesOscillator'),
			array('FastRSI35', 'VolumesOscillator'),
			array('FastRSI40', 'VolumesOscillator'),
			array('SlowRSI', 'VolumesOscillator'),
			array('SlowRSI35', 'VolumesOscillator'),
			array('SlowRSI40', 'VolumesOscillator'),
			// LongVolumes
			array('Williams', 'LongVolumesOscillator'),
			array('Candle', 'LongVolumesOscillator'),
			array('Stochastic', 'LongVolumesOscillator'),
			array('LongStochastic', 'LongVolumesOscillator'),
			array('CCI', 'LongVolumesOscillator'),
			array('SignalMACD', 'LongVolumesOscillator'),
			array('SAR', 'LongVolumesOscillator'),
			array('RSI', 'LongVolumesOscillator'),
			array('RSI35', 'LongVolumesOscillator'),
			array('RSI40', 'LongVolumesOscillator'),
			array('FastRSI', 'LongVolumesOscillator'),
			array('FastRSI35', 'LongVolumesOscillator'),
			array('FastRSI40', 'LongVolumesOscillator'),
			array('SlowRSI', 'LongVolumesOscillator'),
			array('SlowRSI35', 'LongVolumesOscillator'),
			array('SlowRSI40', 'LongVolumesOscillator'),
			//Williams
			array('Candle', 'Williams'),
			array('CCI', 'Williams'),
			array('Stochastic', 'Williams'),
			array('LongStochastic', 'Williams'),
			array('SignalMACD', 'Williams'),
			array('SAR', 'Williams'),
			array('RSI', 'Williams'), 
			array('RSI35', 'Williams'),
			array('RSI40', 'Williams'),
			array('FastRSI', 'Williams'),
			array('FastRSI35', 'Williams'),
			array('FastRSI40', 'Williams'),
			array('SlowRSI', 'Williams'),
			array('SlowRSI35', 'Williams'),
			array('SlowRSI40', 'Williams'),
			// Stochastic
			array('RSI', 'Stochastic'),
			array('RSI35', 'Stochastic'),
			array('RSI40', 'Stochastic'),
			array('FastRSI', 'Stochastic'),
			array('SlowRSI', 'Stochastic'),
			array('FastRSI35', 'Stochastic'),
			array('SlowRSI35', 'Stochastic'),
			array('FastRSI40', 'Stochastic'),
			array('SlowRSI40', 'Stochastic'),
			array('Candle', 'Stochastic'),
			array('SignalMACD', 'Stochastic'),
			array('CCI', 'Stochastic'),
			array('SAR', 'Stochastic'),
			//LongStochastic
			array('RSI', 'LongStochastic'),
			array('RSI35', 'LongStochastic'),
			array('RSI40', 'LongStochastic'),
			array('FastRSI', 'LongStochastic'),
			array('FastRSI35', 'LongStochastic'),
			array('FastRSI40', 'LongStochastic'),
			array('SlowRSI', 'LongStochastic'),
			array('SlowRSI35', 'LongStochastic'),
			array('SlowRSI40', 'LongStochastic'),
			array('Candle', 'LongStochastic'),
			array('SignalMACD', 'LongStochastic'),
			array('CCI', 'LongStochastic'),
			array('SAR', 'LongStochastic'),
			//CCI
			array('RSI', 'CCI'),
			array('RSI35', 'CCI'),
			array('RSI40', 'CCI'),
			array('FastRSI', 'CCI'),
			array('FastRSI35', 'CCI'),
			array('FastRSI40', 'CCI'),
			array('SlowRSI', 'CCI'),
			array('SlowRSI35', 'CCI'),
			array('SlowRSI40', 'CCI'),
			array('Candle', 'CCI'),
			array('SignalMACD', 'CCI'),
			array('SAR', 'CCI'),
			//SAR
			array('RSI', 'SAR'),
			array('RSI35', 'SAR'),
			array('RSI40', 'SAR'),
			array('FastRSI', 'SAR'),
			array('FastRSI35', 'SAR'),
			array('FastRSI40', 'SAR'),
			array('SlowRSI', 'SAR'),
			array('SlowRSI35', 'SAR'),
			array('SlowRSI40', 'SAR'),
			array('SignalMACD', 'SAR'),
			array('Candle', 'SAR'),
			//Candle
			array('RSI', 'Candle'),
			array('RSI35', 'Candle'),
			array('RSI40', 'Candle'),
			array('FastRSI', 'Candle'),
			array('FastRSI35', 'Candle'),
			array('FastRSI40', 'Candle'),
			array('SlowRSI', 'Candle'),
			array('SlowRSI35', 'Candle'),
			array('SlowRSI40', 'Candle'),
			array('SignalMACD', 'Candle'),
			//SignalMACD
			array('RSI', 'SignalMACD'),
			array('RSI35', 'SignalMACD'),
			array('RSI40', 'SignalMACD'),
			array('FastRSI', 'SignalMACD'),
			array('FastRSI35', 'SignalMACD'),
			array('FastRSI40', 'SignalMACD'),
			array('SlowRSI', 'SignalMACD'),
			array('SlowRSI35', 'SignalMACD'),
			array('SlowRSI40', 'SignalMACD'),
			
			//------
			// 3 passes
			array('RSI', 'Williams', 'Candle'), //~never popped
			
			array('RSI', 'Stochastic', 'VolumesOscillator'),
			array('RSI35', 'Stochastic', 'VolumesOscillator'),
			array('RSI40', 'Stochastic', 'VolumesOscillator'),
			array('FastRSI', 'Stochastic', 'VolumesOscillator'),
			array('SlowRSI', 'Stochastic', 'VolumesOscillator'),
			array('FastRSI35', 'Stochastic', 'VolumesOscillator'),
			array('SlowRSI35', 'Stochastic', 'VolumesOscillator'),
			array('FastRSI40', 'Stochastic', 'VolumesOscillator'),
			array('SlowRSI40', 'Stochastic', 'VolumesOscillator'),
			
			array('CCI', 'RSI', 'VolumesOscillator'),
			array('CCI', 'RSI35', 'VolumesOscillator'),
			array('CCI', 'RSI40', 'VolumesOscillator'),
			array('CCI', 'FastRSI', 'VolumesOscillator'),
			array('CCI', 'FastRSI35', 'VolumesOscillator'),
			array('CCI', 'FastRSI40', 'VolumesOscillator'),
			array('CCI', 'SlowRSI', 'VolumesOscillator'),
			array('CCI', 'SlowRSI35', 'VolumesOscillator'),
			array('CCI', 'SlowRSI40', 'VolumesOscillator'),
			
			array('CCI', 'RSI', 'LongVolumesOscillator'),
			array('CCI', 'RSI35', 'LongVolumesOscillator'),
			array('CCI', 'RSI40', 'LongVolumesOscillator'),
			array('CCI', 'FastRSI', 'LongVolumesOscillator'),
			array('CCI', 'FastRSI35', 'LongVolumesOscillator'),
			array('CCI', 'FastRSI40', 'LongVolumesOscillator'),
			array('CCI', 'SlowRSI', 'LongVolumesOscillator'),
			array('CCI', 'SlowRSI35', 'LongVolumesOscillator'),
			array('CCI', 'SlowRSI40', 'LongVolumesOscillator'),
			
			array('SignalMACD', 'CCI'),
			array('SignalMACD', 'CCI', 'VolumesOscillator'),
			array('SignalMACD', 'CCI', 'LongVolumesOscillator'),

			array('SAR', 'RSI'),
			array('SAR', 'FastRSI'),
			array('SAR', 'Stochastic'),
			array('SAR', 'LongStochastic'),
			array('SAR', 'Williams'),
			array('SAR', 'CCI'), // CCI&SAR && SAR&SignalMACD are comparable...
			array('SAR', 'SignalMACD'),
			array('SAR', 'VolumesOscillator'),
			array('SAR', 'LongVolumesOscillator'),
			
	//		array('SAR', 'Stochastic'), // useless
			);
			*/
// 		if(!file_exists( $file = __METHOD__ . '.csv' ))
// 		{
// 			$o = 'Action,';
// 			foreach($indics as $litteral)
// 				$o .= implode('&', $litteral).',STDV,N,';
// 			file_put_contents($file, $o. 'BestIndicator,ISIN,Mnemo'."\n", FILE_APPEND);
// 		}
		
		//Mnemo
		$mn = strstr($action, '.', true);
		$isin = StockInd::getInstance()->search($mn);
// 		file_put_contents($file, $action.',', FILE_APPEND);

		// final Data
		$data = array();

// 		$tacache = array();
		
		// Array with Data :
		// ID		123456789--------------------------------------------------
		// Cours	-------------++++++++--+++++----------++++++++----------+++		Array sur Stockcache
		// RSI		------------|----------|--------------|-----------------|--		Map franchissement
		// Volumes	---|||||||-------|||---||||----------|||-------------|--||-		Map sur volumes
		// Stoch	---------|---|---------|--------------|-----------------|--		map sur stoch etc...
		// CCI		--------------|--|-----|--------------|--------------|--|--		map sur cci
		// MACD		-----------|----|----|------|---|------------|--|----|-----		map sur signalmacd
		$DATA = array();
	    
	    // Loop to populate $DATA array
		$slicelength = 100;
		$slice = ($days+$slicelength)*-1;
		for($slicelength; $slicelength < $slice*-1; $slicelength++)
		{
			$stock = new Stock($action, 'd', Stock::PROVIDER_CACHE);
			$stock->Slice($slice, $slicelength); //slice until today
			
			$DATA['COURS'][$slicelength] = $stock->getLast();
			
			$TA = $stock->Analysis();
			foreach($_indics as $func)
				$DATA[$func][$slicelength] = $TA->$func();
		}
		
		//DEBUG
// 		file_put_contents('AI.PA.csv', implode(',', $keys=array_keys($DATA))."\n");
// 		$min = min($DATA['COURS']);
// 		foreach($DATA['COURS'] as $i => $cours)
// 		{
// 			$line = '';
// 			foreach($keys as $k)
// 				$line .= ($DATA[$k][$i]>0 ? $cours : $min) .',';
// 			$line .= "\n";
// 			file_put_contents('AI.PA.csv', $line, FILE_APPEND);
// 		}
// 		exit();

		/* 
		 * Build Data indicators
		 */
		foreach($DATA['COURS'] as $slice => $cours)
			foreach($indics as $funcs)
			{
				$litteralfuncs = implode('&', $funcs);
				if(!isset($data[$litteralfuncs]))
					$data[$litteralfuncs] = array();
				foreach($funcs as $func)
					if($DATA[$func][$slice] <= 0)
						continue 2;
				// si les deux indicateurs sont positifs,
				// Indicator fired ! 
				$max = $cours;
				$seuilinf = $cours*((1-(float)$SeuilPolicy/2));  // Seuil inférieur, le cours - la volatilité historique
				$seuilsup = $cours*((1+(float)$SeuilPolicy/2));  // Seuil supérieur
				$sens = 0; // Sens neutre
				$slicing = 0;
				for($thislength = $slice; $thislength < end(array_keys($DATA['COURS'])); $thislength++)
				{
					$m = $DATA['COURS'][$thislength];
					if($m > $seuilsup && $sens !== -1)
					// le cours dépasse le seuil de volatilité à la hausse, dans un environnement neutre ou gagnant,
					{
// 						print $m.' ';
						if($m > $max)
							$max = $m; // on cherche le maximum
// 						if($m < $max*(1-$SeuilPolicy/2)) // Si le cours refranchit à la baisse la volatilité, 
// 							break;
						$sens = 1; // on met le sens gagnant;
					}
					elseif($m < $seuilinf && $sens !== 1)// dépasse le seuil de volatilité à la baisse, perdant.
					{
// 						print 'FAIL';
						if($m < $max)
							$max = $m;
// 						if($m > $max*(1+$SeuilPolicy/2))
// 							break;
						$sens = -1;
					}
					elseif($m > $seuilinf && $m < $seuilsup && $sens == 0) // le cours oscille entre les deux bornes sup et inf
						continue 1;
					else // le cours avait dépassé le seuil haut dans un environnement gagnant et l'a repassé à la baisse, ou inversement pour le coup perdant. On coupe la mesure.
					{
						$slicing = $thislength;
						break;
					}
				}
				if($max != $cours) // si le cours est resté entre les deux bornes, cela signifie qu'il n'y a pas assez de données pour décider (derniers cours en cache...)
				// Auquel cas on n'enregistrerait pas le résultat.
				{
					print ($max > $cours ? 'Hausse' : 'Baisse'). ' de '.$data[$litteralfuncs][] = round(100*($max - $cours) / $cours, 3).'% en '.$slicing.' jours.'."\n";
				}
				// On enregistre les performances maximales par rapport au cours de triggering
			}


		/*
		 *
		 * Moyennes, Ecart type sur les résultats.
		 *
		 */
		array_walk($data, function(&$d) {
			$n = count($d);
// 			if($n == 0)
// 				$d = array('avg' => 0, 'stdev' => 0, 'numb' => 0);
			if($n>1)
			{
				$winloose = 0;
				$mean = array_sum($d) / $n;
				$carry = 0.0;
				foreach ($d as $val) {
					$a = ((double) $val) - $mean;
					$carry += $a * $a;
					$winloose += $val > 0 ? 1 : 0; // L'opération était-elle gagnante ou perdante ?
				};
				$stdev = sqrt($carry / $n);
// 				$rstdev = ($stdev) / $mean;
				$d['avg'] = $mean;
				$d['stdev'] = $stdev;
// 				$d['rstdev'] = $rstdev;
				$d['tstdev'] = $stdev / sqrt($n);
				$d['numb'] = $n;
				$d['winloose'] = $winloose*100/$n.'%'; // pourcentage de trades gagnants
// 				$d['power'] = round($stdev*$n/$mean, 2);
			}
			elseif($n==1)
			{
				$d['avg'] = $d[0];
				$d['stdev'] = 0;
// 				$d['rstdev'] = 0;
				$d['tstdev'] = 0;
				$d['numb'] = 1;
				$d['winloose'] = '50%'; // par convention, lorsqu'on a qu'une seule valeur sporadique, on considère un ratio de gain 50/50, pile ou face.
// 				$d['power'] = 1;
			}
			return ;
		});
		
// 		print_r($data);
		
// 		foreach($indics as $funcs)
// 		{
// 			$litteralfuncs = implode('&', $funcs);
// 			$data[$litteralfuncs] = array();
// 			$slicelength = 100;
// 			$slice = ($days+$slicelength)*-1;
// 			for($slicelength; $slicelength < $slice*-1; $slicelength++)
// 			{
// 				$stock = new Stock($action, 'd', Stock::PROVIDER_CACHE);
// 				$closes = $stock->AdjustedClose(true);
// // 				$ta = $stock->Slice($slice, $slicelength)->Analysis();
// 				$stock->Slice($slice, $slicelength);
// 				foreach($funcs as $func)
// 				{
// 					if(!isset($tacache[$func][$slicelength]))
// 						$tacache[$func][$slicelength] = $stock->Analysis()->$func();
// 					if($tacache[$func][$slicelength] <= 0)
// 						continue 2; // Exit if not 
// 				}
// 				$max = 0;
// 				for($thislength = 1; $thislength< ($slice*-1 - $slicelength); $thislength++)
// 				{
// 					$m = max(array_slice($closes, $slice + $slicelength, $thislength));
// 					if($m > $max)
// 						$max = $m;
// 					elseif($m < $max*((100-(float)$seuil)/100))//delta 6% avant de couper court.
// 					{
// 						$slicelength += $thislength;
// 						break 1; // break at 
// 					}
// 					else	continue 1;
// 				}
// 				$data[$litteralfuncs][] = round(100*($max - $stock->getLast()) / $stock->getLast(), 3);
// 			}
// 			// Averaging the data...
// 			if(!empty($data[$litteralfuncs]))
// 			{
// 				$n = count($data[$litteralfuncs]);
// 				if($n>1)
// 				{
// 					$mean = array_sum($data[$litteralfuncs]) / $n;
// 					$carry = 0.0;
// 					foreach ($data[$litteralfuncs] as $val) {
// 						$d = ((double) $val) - $mean;
// 						$carry += $d * $d;
// 					};
// 					$rstdev = ($stdev = sqrt($carry / $n)) / $mean;
// 					$data[$litteralfuncs]['avg'] = $mean;
// 					$data[$litteralfuncs]['stdev'] = $stdev;
// 					$data[$litteralfuncs]['rstdev'] = $rstdev;
// 					$data[$litteralfuncs]['numb'] = $n;
// 					$data[$litteralfuncs]['power'] = round($stdev*$n/$mean, 2);
// 				}
// 				else
// 				{
// 					$data[$litteralfuncs]['avg'] = $data[$litteralfuncs][0];
// 					$data[$litteralfuncs]['stdev'] = 0;
// 					$data[$litteralfuncs]['rstdev'] = 0;
// 					$data[$litteralfuncs]['numb'] = 1;
// 					$data[$litteralfuncs]['power'] = 1;
// 				}
// 				file_put_contents($file, 
// 					$data[$litteralfuncs]['avg'].','.
// 					$data[$litteralfuncs]['stdev'].','.
// 					$data[$litteralfuncs]['numb'].',',
// 					FILE_APPEND);
// 			}
// 			else
// 				file_put_contents($file, '0,0,0,', FILE_APPEND);
// 		}

		/*
		 *
		 * Faire le tri entre les indicateurs
		 *
		 */
		$pwr = $pwri = array();
// 		$std = 100.0;
		$avg = array();
// 		foreach($data as $func => $p)
// 		{
// // 			print 'Intervalle de Confiance 95 pour '.$func.' : '.($p['avg']-2*$p['stdev'])."\n";
// 			$seuil = $SeuilPolicy*100;
// 			if(!isset($p['avg']))
// 				continue;
// 			if($p['avg'] < (float)$seuil) // Si la moyenne n'outrepasse pas le seuil de détection...
// 				continue;
// 			if($p['avg']-2*$p['stdev'] < (float)$seuil) // non significatif, contient 0.
// 				continue;
// 			//Puissance insuffisante => On quitte
// 			if($p['numb'] < 2 
// // 				&& $p['avg'] < 2.5*(float)$seuil
// 				) // Si la puissance est insuffisante, au moins on élimine si la moyenne est inférieure à 2x le seuil afin d'approximer un IC95
// 				continue;
// 			$tests = explode('&', $func);
// 			if(!empty(array_intersect($tests, $pwr))) // Si une focntion composant l'indicateur existe déja seul parmi les indicateurs, c'est un indicateur redondant inutile qu'on annule.
// 				continue;
// 			$pwr[] = $func;
// // 			if($p['stdev'] < $std)
// // 				$std = $p['stdev'];
// 			$avg[$func] = $p['avg'];
// 			continue;
// 		}
		foreach($data as $func => $p)
		{
			// 0. Skip empty, not fired indicators
			if(empty($p))
				continue;
			
			// 1. Skip anti-winning signals, loose>win or average gains negatives.
			if((int)$p['winloose'] < 50)	continue; 
			if((int)$p['avg'] < 0)			continue;
			
			// 2. Skip not powerful enough data
			if((int)$p['numb'] < 2)			continue;
				
			// 3 Skip when average perf is lower than historical volatility
// 			if($p['avg'] < $SeuilPolicy*100)	continue; // OBSOLETE, déjà déterminé précédemment.
			
			// 4. Select only IC95 good values with TrueSTDEV, assuming means are normally distributed
			$IC95low = $p['avg'] - 1.96*$p['stdev'];
// 			$IC95up = $p['avg'] + 1.96*$p['tstdev'];
			if($IC95low < $SeuilPolicy*50) // Si l'intervalle comprend 0, exit, on est dedans.
				continue;
			
			// 5. Si une focntion composant l'indicateur existe déja seule parmi les indicateurs, c'est un indicateur redondant qu'on annule.
			$exploded = explode('&', $func);
			foreach($pwri as $pw)
				if(empty(array_diff($pw, $exploded)))
					continue 2;

			// On ajoute la fonction aux indicateurs pertinents
			$pwr[] = $func;
			$pwri[] = $exploded;
			$avg[$func] = $p['avg'];
			//DEBUG
			print $func;
			print_r($p);
			continue;
		}
		//DEBUG
// 		print_r( $avg );
// 		file_put_contents($file, implode('|', $pwr).','.$isin.','.$mn."\n", FILE_APPEND);
		return array(
			'IndicateurAchat' => implode('|', $pwr),
// 			'SeuilDeDetection' => $SeuilPolicy*100 .'%',
			'SeuilPolicy' => round($SeuilPolicy*50, 2) .'%', /*round($Volatility/2, 2)*/ 
// 			'SeuilPolicy2' => $std,
			'ObjectifMoyen' => $objMoy = round(array_sum(array_unique($avg))/count(array_unique($avg)), 2).'%',
			'BeneficeMinimal' => round($objMoy/4, 2).'%',
		);
// 			if($p['rstdev'] > .3) //30% de divergences à la moyenne
// 				continue;
// // 			if($p['power'] > 3)
// // 				continue;
// 			$avg[$func] = $p['avg'];
// 			$pwr[$func] = $p['power'];
// 			if(isset($p['power']) && $p['power'] < $power)
// 			{
// 				if(($p['power']-$power) / $p['power'] <0.1) // 10% ÉQUIVALENT
// 					$pfunc .= '|'.$func;
// 				else
// 					$pfunc = $func;
// 				$power = $p['power'];
// 			}
// 			if(isset($p['rstdev']) && $p['rstdev'] < $rst)
// 			{
// 				if(($p['rstdev']-$rst) / $p['rstdev'] <0.1) // 10% ÉQUIVALENT
// 					$pfunc .= '|'.$func;
// 				else
// 					$pfunc = $func;
// 				$rst = $p['rstdev'];
// 			}
		
// 		return $data;
	}
	public static function BuildIndicators($days = 500)
	{
// 		$list = StockInd::getInstance()->Lib;
		$Indicators = array();
		$i = 0;
		foreach(Stock::$YahooCAC40 as $ya)
		{
			$mn = strstr($ya, '.', true);
			print ++$i.'/'.count(Stock::$YahooCAC40).'... '."$mn\n"; 
			$isin = StockInd::getInstance()->search($mn);
			if($isin == false)
			{
				print $ya.' ['.$mn.'] was not found.';
				continue;
			}
			$label = StockInd::getInstance()->searchLabel($isin);
			$Indicators[$isin] = self::BestIndicator($ya);
			$Indicators[$isin]['_AutoLabel'] = ucwords($label);
			$Indicators[$isin]['_AutoMnemo'] = $mn;
			print_r($Indicators);
			print "\n";
		}
		file_put_contents(self::EXTERNAL_INDICATORS, '<?php'."\n".'// Généré le '.date('d/m/Y à H:i:s')."\n".'$Indicators = '.var_export($Indicators, true).';'."\n".'?>');
		
		return true;
	}
	public static function getExternalIndicators()
	{
		static $Inds = array();
		if(empty($Inds) && file_exists(self::EXTERNAL_INDICATORS) && is_readable(self::EXTERNAL_INDICATORS))
		{
			require_once(self::EXTERNAL_INDICATORS);
			$Inds = $Indicators;
		}
		return $Inds;
	}
}

