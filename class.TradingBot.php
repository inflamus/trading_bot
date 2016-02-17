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
	
	public function AddOrder($sens, OrdreEnCours $Ordre, $AdditionalData = array())
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
	const SOMME_MINIMALE = '1000 €';
	const FRAIS_BOURSIERS = '0.9%';
	const BENEFICE_MINIMAL = '5%'; // le benefice minimal a partir duquel la question du seuil doit se poser.
	const SEUIL_EXPIRE_WEEKS = 1;
	// Les seuils se calculent selon cours actuel - {$seuil}% 
	// eg : action à 100eur, trois seuils à 5% 6% et 7% :
	// => seuils à 95, 94 et 93euros.
	const SEUIL_POLICY = '3%;3.5%'; // multiple seuils allowed, splited with ";". eg: 5%;5.5%;6%
	const POLICY_PRIORITY = 'ASC'; // ASC = la priorité est le seuil le plus proche du cours. => maximise les benefices
									// DESC = la priorité est au seuil le plus lointain. => moins d'ordres executés.
	const STOPLOSS = true; // par défaut, mettre des stoploss
	
	public $GlobalParams = array(
		'FraisBoursiers' => self::FRAIS_BOURSIERS,
		'BeneficeMinimal'=> self::BENEFICE_MINIMAL,
		'SommeMinimale' => self::SOMME_MINIMALE,
		'SeuilExpireWeeks' => self::SEUIL_EXPIRE_WEEKS,
		'SeuilPolicy' => self::SEUIL_POLICY,
		'PolicyPriority' => self::POLICY_PRIORITY,
		'StopLoss' => self::STOPLOSS,
		);
	public $ByISINParams = array(
		/*
			'Isin' => array(
				//GlobalParams like
				);
		*/
		);

	private $DB = null;
	private $CM = null;
	private $Stock = null;
	
// 	public function __construct(CreditMutuel $CM, Stock $Stock)
	public function __construct(CreditMutuel $CM, $Stock = null)
	{
		$this->CM = $CM;
		$this->Stock = $Stock;
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
		throw new Exception('Wrong key ['.$key.'] in Params');
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
			throw new Exception('Wrong ISIN');
		$this->ByISINParams[$isin][$key] = $val;
		return $this;
	}
	
	private $curr = 'isin';
	const DAILYCHECKUP_VENTE = 0x1;
	const DAILYCHECKUP_ACHAT = 0x2;
	const DAILYCHECKUP_BOTH = 0x3;
	public function DailyCheckup($mode = self::DAILYCHECKUP_BOTH)
	{
		foreach($this->CM->Valorisation() as $stock)
		{
			$this->curr = $stock->isin;
			print (string)$stock . "\n";
// 			print $stock->PlusvaluePCT . "\n";
			
			if($mode & self::DAILYCHECKUP_VENTE && $this->StopLoss)
				// Placer des ordres Stops si position favorable, selon la Seuil_policy
				$this->Seuils($stock);
			
		}
		$this->curr = null; // reset isin pointer
		return $this;
	}
	
	private function Seuils(Action $stock)
	{
		$min = ((float)$this->BeneficeMinimal + (float)$this->FraisBoursiers);
// 		print $min .'<< '.$stock->PlusvaluePCT;
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
				
// 				print "Nseuil $nseuil - QSeuil {$qseuil[$i]}\n";
				$oseuil = $this->DB->getOrdersFor($stock->isin, 'Vente');
// 				var_dump($oseuil);
				if(is_array($oseuil))
					if(isset($oseuil[$i]))
					{
						if($oseuil[$i]['Seuil'] < $seuils[$i][0])
							$oseuil[$i]['Ordre']->Delete(); //Supprime l'ancien ordre avec un seuil inf.
					}
					
				// Ajout de l'ordre
				try{
					$dat = $this->CM->Ordre($stock->isin)
						->Vendre($seuils[$i][1]) // Qté
						->ASeuil($seuils[$i][0]) // Seuil
						->Hebdomadaire($this->SeuilExpireWeeks)
						->Exec()
						;

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

// 	public function NouveauSeuil(
}
