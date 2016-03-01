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

	const SOMME_MINIMALE = '1000 €';
	const FRAIS_BOURSIERS = '0.9%';
	// Vente auto
	const BENEFICE_MINIMAL = '5%'; // le benefice minimal a partir duquel la question du seuil doit se poser.
	const SEUIL_EXPIRE_WEEKS = 1;
	// Les seuils se calculent selon cours actuel - {$seuil}% 
	// eg : action à 100eur, trois seuils à 5% 6% et 7% :
	// => seuils à 95, 94 et 93euros.
	const SEUIL_POLICY = '3%;3.5%'; // multiple seuils allowed, splited with ";". eg: 5%;5.5%;6%
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
		// Adjusted params from a quick study https://docs.google.com/spreadsheets/d/1ekQSj2Y0468rR16UQAm1m702RulnPcs4Bcezd-N98wI/edit
		'FR0000120073' => array( // Air Liquide [AI]
			'IndicateurAchat' => 'RSI&LongStochastic|SignalMACD&CCI&VolumesOscillator' //|RSI&Stochastic specific buy signal
			),
		'BE0003470755' => array( // Solvay [SOLB]
			'IndicateurAchat' => array() // Aucun Indicateur significativement meilleur à l'achat.
			),
		'FR0010307819' => array( // Legrand [LR]
			'IndicateurAchat' => array(), // 'SignalMACD&CCI' 
			),
		'FR0000125486' => array( // Vinci [DG]
			'IndicateurAchat' => 'CCI&SignalMACD|RSI&CCI&VolumesOscillator' // Less selective than RSI&Stoch
			),
		'FR0000130452' => array( // Eiffage [FGR]
			'IndicateurAchat' => 'RSI&VolumesOscillator|CCI&SignalMACD' // Idem vinci
			),
		'FR0000120321' => array( // L'Oreal [OR]
			'IndicateurAchat' => 'RSI&Williams|RSI&CCI|CCI&SignalMACD'  //RSI + williams fait mieux que RSI+Stoch
			),
		'FR0000127771' => array( // Vivendi [VIV]
			'IndicateurAchat' => 'SignalMACD&VolumesOscillator' // 25|18|11, les performances se trouvent dans le dividende...
			),
		'FR0000130213' => array( // Lagardere [MMB]
			'IndicateurAchat' => 'RSI&LongStochastic' //|RSI&Stochastic
			),
		'FR0000131104' => array( // BNP Paribas [BNP]
			'IndicateurAchat' => array() // Aucun
			),
		'FR0000120644' => array( // Danone [BN]
			'IndicateurAchat' => 'RSI|CCI&SignalMACD' //|RSI&CCI|RSI&LongStochastic|RSI&Stochastic
			),
		'FR0000120693' => array( // Pernod Ricard [RI]
			'IndicateurAchat' => 'RSI&LongStochastic|RSI&CCI' //RSI&Stoch or RSI
			),
		'FR0000121667' => array( // Essilor [EI]
			'IndicateurAchat' => 'RSI&Williams|CCI&RSI',
			),
		'FR0000120578' => array( // Sanofi [SAN]
			'IndicateurAchat' => 'CCI&SignalMACD&VolumesOscillator' // Aucun
			),
		'FR0000125585' => array( // Casino [CO]
			'IndicateurAchat' => 'RSI&LongStochastic|RSI&Stochastic&Williams|CCI&SignalMACD' //|RSI&Stochastic
			),
		'FR0000120172' => array( // Carrefour [CA]
			'IndicateurAchat' => array() // aucun aucun
			),
		'FR0000120628' => array( // Axa [CS]
			'IndicateurAchat' => array() // Aucun aucun
			),
		'FR0000120222' => array( // CNP Assurances [CNP]
			'IndicateurAchat' => 'SignalMACD&CCI|SignalMACD&VolumesOscillator' // Aucun
			),
		'FR0000121485' => array( // Kering [KER]
			'IndicateurAchat' => 'SignalMACD&CCI' // Le seul qui semble performer a 20% sur ce titre.
			),
		'FR0004035913' => array( // Iliad [ILD]
			'IndicateurAchat' => 'RSI&LongStochastic|RSI&CCI&VolumesOscillator'
			),
		'FR0000133308' => array( // Orange [ORA]
			'IndicateurAchat' => 'RSI&VolumesOscillator'
			),
// 		'FR0000121501' => array( // Peugeot [UG]
// 			'IndicateurAchat' => 'RSI&Stochastic' //Defaults
// 			),
		'FR0000124570' => array( // Plastic Ominum [POM]
			'IndicateurAchat' => 'RSI&LongStochastic&Williams|RSI&LongStochastic|CCI&RSI&VolumesOscillator' //|RSI&Stochastic
			),
		'FR0000121261' => array( // Michelin [ML]
			'IndicateurAchat' => 'RSI&Stochastic|SignalMACD&CCI&VolumesOscillator' // defaults
			),
		'FR0010112524' => array( // Nexity NXI
			'IndicateurAchat' => 'RSI&Stochastic|RSI&VolumesOscillator'
			),
		'FR0000124141' => array( // Veolia Environnement VIE
			'IndicateurAchat' => 'RSI&VolumesOscillator' //|RSI&CCI&VolumesOscillator
			),
		'FR0000120404' => array( // Accor Hotels AC
			'IndicateurAchat' => 'CCI&RSI&VolumesOscillator'
			),
		'NL0000235190' => array( // Airbus AIR
			'IndicateurAchat' => 'CCI&RSI&VolumesOscillator'
			),
		'FR0010220475' => array( // Alstom ALO
			'IndicateurAchat' => array(),
			),
		'FR0000121964' => array( // Klepierre LI
			'IndicateurAchat' => array(),
			),
		'FR0010208488' => array( // Engie ENGI
			'IndicateurAchat' => 'CCI&RSI&VolumesOscillator'
			),
		'FR0000130577' => array( // Publicis PUB
			'IndicateurAchat' => array(),
			),
		'FR0000131906' => array( // Renault RNO
			'IndicateurAchat' => 'RSI&Stochastic|CCI&RSI&VolumesOscillator'
			),
		'FR0000121972' => array( // Schneider Electric SU
			'IndicateurAchat' => array(),
			),
// 		'FR0000131708' => array( // Tecnip TEC
// 			'IndicateurAchat' => 'RSI&Stochastic'
// 			),
		'FR0000124711' => array( // Unibail Rodamco UL
			'IndicateurAchat' => array(),
			),
		'FR0000130338' => array( // Valeo FR
			'IndicateurAchat' => array(),
			),
		'CH0012214059' => array( // Lafarge LHN
			'IndicateurAchat' => array(),
			),
// 		'FR0000073272' => array( // Safran SAF
// 			'IndicateurAchat' => 'RSI&Stochastic'
// 			),
// 		'FR0000130403' => array( // Christian Dior CDI
// 			'IndicateurAchat' => 'RSI&Stochastic'
// 			),
// 		'FR0000120503' => array( // Bouygues EN
// 			'IndicateurAchat' => 'RSI&Stochastic'
// 			),
// 		'FR0000121014' => array( // LVMH MC
// 			'IndicateurAchat' => 'RSI&Stochastic'
// 			),
// 		'FR0011594233' => array( // Numéricable NUM
// 			'IndicateurAchat' => 'RSI&Stochastic'
// 			),
// 		'FR0000031122' => array( // Air France - KLM AF
// 			'IndicateurAchat' => 'RSI&Stochastic'
// 			),
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
		$DB = $this->DB = new TradingHistory();
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
	
	public function IsinParams($isin, $key, $val)
	{
		if(!$this->CM->isISIN($isin))
			throw new Exception('Wrong ISIN ['.$isin.']');
		$this->ByISINParams[$isin][$key] = $val;
		return $this;
	}
	
	public function Watchlist(Stock $stock, $isin = '', $sens='A')
	{
		if(!$this->CM->isISIN($isin))
		{
			$isin = strstr($stock->stock, '.', true);
			if(!$this->CM->isISIN($isin))
				throw new Exception('Wrong ISIN');
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
		foreach($ind as $ou)
		{
			if(self::VERBOSE)
				print "\n".' '.$stock->stock.' '.implode('&',$ou)." :";
			foreach($ou as $func) //required func
				if($stock->Analysis()->$func() <= 0)
				{
					if(self::VERBOSE)
						print '  '.$func.' negatif, aborting.';
					continue 2; // Passe au second indicateur si le premier rétorque faux.
				}
				else
					if(self::VERBOSE)
						print '  '.$func.' positif';
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
		->AuDernierCours($stock->getLast()) // pass this arg for simulator compatibility
// 		->ACoursLimite($stock->getLast()) // For Simulator
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
						->ASeuil($seuils[$i][0]) // Seuil
						->Hebdomadaire($this->SeuilExpireWeeks)
						->Exec()
						;
					if(self::VERBOSE)
						print 'ADDING ORDER '.$stock->isin.' : '.$seuils[$i][1].' * seuil = '.$seuils[$i][0].', expire '.$this->SeuilExpireWeeks ." week\n";
					$this->DB->AddOrder('Vente',
						$dat, 
						array(
							'Seuil' => $seuils[$i][0],
							'Expire' => strtotime('last Friday of +'.$this->SeuilExpireWeeks.'weeks')
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
// 	public function NouveauSeuil(
}

