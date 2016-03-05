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
	const BENEFICE_MINIMAL = '5%'; // le benefice minimal a partir duquel la question du seuil doit se poser.
	const SEUIL_EXPIRE_WEEKS = 1;
	// Les seuils se calculent selon cours actuel - {$seuil}% 
	// eg : action à 100eur, trois seuils à 5% 6% et 7% :
	// => seuils à 95, 94 et 93euros.
	const SEUIL_POLICY = '2.8%;3.5%'; // multiple seuils allowed, splited with ";". eg: 5%;5.5%;6%
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
			'IndicateurAchat' => array() // 'SignalMACD&CCI' 
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
		'FR0000121501' => array( // Peugeot [UG]
// 			'IndicateurAchat' => 'RSI&Stochastic' //Defaults
			'IndicateurAchat' => array(),
			),
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
			'IndicateurAchat' => 'RSI&VolumesOscillator|RSI&Stochastic' //|RSI&CCI&VolumesOscillator
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
		'FR0000130007' => array( // Alcatel-Lucent ALU
			'IndicateurAchat' => array(), // Racheté par Nokia, se reporter sur l'action Nokia
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
		'FR0000031775' => array( // Vicat VCT
			'IndicateurAchat' => array(),
			),
		'FR0000034639' => array( // Altran tech ALT
			'IndicateurAchat' => 'RSI&VolumesOscillator', // Careful, ecartype limie.
			),
		'FR0000035081' => array( // Icade ICAD
			'IndicateurAchat' => array() // Non éligible au PEA.
			),
		'FR0011726835' => array( // GTT GTT
			'IndicateurAchat' => 'RSI&Stochastic|RSI&LongStochastic|CCI&RSI&VolumesOscillator'
			),
		'FR0010096479' => array( // Biomerieux BIM
			'IndicateurAchat' => 'RSI&LongStochastic|CCI&RSI&VolumesOscillator' // RSI|RSI&VolumesOscillator|
			),
		'FR0000037046' => array( // Montupet_sa MON
			'IndicateurAchat' => 'RSI&Stochastic|RSI&LongStochastic'
			),
		'FR0000038259' => array( // Eurofins_scient_ ERF
			'IndicateurAchat' => array() //'RSI&LongStochastic|CCI&RSI&VolumesOscillator'
			),
		'FR0000039299' => array( // Bollore BOL
			'IndicateurAchat' => array()
			),
		'FR0000044448' => array( // Nexans NEX
			'IndicateurAchat' => 'CCI&RSI&VolumesOscillator'
			),
		'FR0000050809' => array( // Sopra_steria_group SOP
			'IndicateurAchat' => array() //'RSI&LongStochastic' |SignalMACD&CCI&VolumesOscillator
			),
		'FR0000051070' => array( // Maurel_et_prom MAU
			'IndicateurAchat' => array()
			),
		'FR0000051807' => array( // Teleperformance RCF
			'IndicateurAchat' => 'RSI' //|RSI&VolumesOscillator|RSI&LongStochastic|CCI&RSI&VolumesOscillator|SignalMACD&CCI&VolumesOscillator
			),
		'FR0000052292' => array( // Hermes RMS
			'IndicateurAchat' => array() //'RSI&Stochastic'
			),
		'FR0000053225' => array( // Metropole_tv MMT
			'IndicateurAchat' => array() //'RSI&Stochastic|SignalMACD&CCI&VolumesOscillator'
			),
		'FR0000053266' => array( // Sartorius_stedim_biotech DIM
			'IndicateurAchat' => array() //'RSI&VolumesOscillator'
			),
		'FR0000054470' => array( // Ubi_soft_entertain UBI
			'IndicateurAchat' => 'RSI&LongStochastic|CCI&RSI&VolumesOscillator|SignalMACD&VolumesOscillator|SignalMACD&CCI&VolumesOscillator'
			),
		'FR0000054900' => array( // Tf1 TFI
			'IndicateurAchat' => array()
			),
		'FR0000064578' => array( // Fonciere_des_regions FDR
			'IndicateurAchat' => 'RSI&Stochastic|RSI&LongStochastic' // Moyen...
			),
		'FR0000073298' => array( // Ipsos IPS
			'IndicateurAchat' => array()
			),
		'FR0000077919' => array( // Jc_decaux_sa_ DEC
			'IndicateurAchat' => array()// 'SignalMACD&CCI&VolumesOscillator' //CCI&RSI&VolumesOscillator|
			),
		'FR0000120164' => array( // Cgg CGG
			'IndicateurAchat' => array() //'SignalMACD&CCI&VolumesOscillator'
			),
		'FR0000120354' => array( // Vallourec VK
			'IndicateurAchat' => array()
			),
		'FR0000120560' => array( // Neopost NEO
			'IndicateurAchat' => array()
			),
		'FR0000120685' => array( // Natixis KN
			'IndicateurAchat' => array() //'CCI&RSI&VolumesOscillator'
			),
		'FR0000120859' => array( // Imerys NK
			'IndicateurAchat' => 'CCI&RSI&VolumesOscillator|SignalMACD&CCI&VolumesOscillator'
			),
		'FR0000120966' => array( // Bic BB
			'IndicateurAchat' => array() //'SignalMACD&CCI&VolumesOscillator' //RSI|RSI&VolumesOscillator|RSI&LongStochastic|
			),
		'FR0000121121' => array( // Eurazeo RF
			'IndicateurAchat' => 'RSI&Stochastic|CCI&RSI&VolumesOscillator|SignalMACD&CCI&VolumesOscillator'
			),
		'FR0000121147' => array( // Faurecia EO
			'IndicateurAchat' => array() //'RSI&Stochastic'
			),
		'FR0000121204' => array( // Wendel_invest_ MF
			'IndicateurAchat' => array()
			),
		'FR0000121220' => array( // Sodexo SW
			'IndicateurAchat' => 'RSI|SignalMACD&CCI&VolumesOscillator' //|RSI&VolumesOscillator|RSI&Stochastic|RSI&LongStochastic|CCI&RSI&VolumesOscillator|
			),
		'FR0000121253' => array( // Rubis RUI
			'IndicateurAchat' => array()
			),
		'FR0000121329' => array( // Thales HO
			'IndicateurAchat' => 'RSI' // |RSI&VolumesOscillator|RSI&LongStochastic|CCI&RSI&VolumesOscillator
			),
		'FR0000121709' => array( // Seb SK
			'IndicateurAchat' => 'RSI&VolumesOscillator|CCI&RSI&VolumesOscillator'
			),
		'FR0000121881' => array( // Havas HAV
			'IndicateurAchat' => array() //'RSI&Stochastic'
			),
		'FR0000125007' => array( // Saint_gobain SGO
			'IndicateurAchat' => 'RSI&Stochastic|SignalMACD&CCI&VolumesOscillator'
			),
		'FR0000125346' => array( // Ingenico_group ING
			'IndicateurAchat' => array()
			),
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

	// Indicateurs Generator 
	// TODO : Detecter le benefice minimal
	// TODO : Detecter par la volatilité moyenne le seuilPolicy
	public static function BestIndicator($action, $days = 500, $seuil = '6%')
	{
		if(is_array($action))
		{
			$re=array();
			$re[] = self::BestIndicator($action, $days, $seuil);
			return $re;
		}
		
		$STO = new Stock($action, 'd', Stock::PROVIDER_YAHOO, floor($days/260)); // cache last data yahoo data.
		$Volatility = $STO->Analysis()->Volatility();
		unset($STO);
		
		$indics = array(
	// 		array('Williams'),
	// 		array('Williams', 'VolumesOscillator'),
	// 		array('Candle'), 
			array('RSI'), // Works better on AF, ORA
			array('RSI','VolumesOscillator'),
	// 		array('Stochastic'), // Worst than LongStochastic
	// 		array('LongStochastic'), // Better than Stochastic
			/*array('Williams','Candle'),  ben ~ 6% , ecartype proche de la moyenne*/  
			/*array('RSI', 'Williams', 'Candle'), ~never popped*/ 
			array('RSI', 'Stochastic'),  // 2nd position // Marche mieux sur FP qu'avec le longstoch
	// 		array('RSI', 'Stochastic', 'VolumesOscillator'),
			array('RSI', 'LongStochastic'), // Provisoirement le meilleur, marche mieux sur AF, ORA. Moins bien sur FP. CDI ok
			array('CCI', 'Stochastic'), // Test
	// 		array('RSI', 'LongStochastic', 'Williams'), //May be more selective on AF
			/*array('RSI', 'Candle'),*/ 
	// 		array('MACD'), //
	// 		array('MACD', 'Candle'), // Ecart type trop proche dela moyenne, résultats trop disparates entre les actions.
			/*array('MACD', 'RSI'), never popped, or ~7%*/ 
			/*array('MACD', 'Stochastic')*/ // Trop disparate comme résultats, ecart type > moyenne !!
	// 		array('RSI', 'Williams'),
	// 		array('CCI'), // commodities channel index
// 			array('CCI', 'VolumesOscillator'),
	// 		array('CCI', 'RSI'),
			array('CCI','RSI','VolumesOscillator'),
	// 		array('SignalMACD'),
// 			array('SignalMACD', 'VolumesOscillator'),
	// 		array('SignalMACD', 'CCI'),
			array('SignalMACD', 'CCI', 'VolumesOscillator'),
			);
		if(!file_exists( $file = __METHOD__ . '.csv' ))
		{
			$o = 'Action,';
			foreach($indics as $litteral)
				$o .= implode('&', $litteral).',STDV,N,';
			file_put_contents($file, $o. 'BestIndicator,ISIN,Mnemo'."\n", FILE_APPEND);
		}
		$mn = strstr($action, '.', true);
		$isin = StockInd::getInstance()->search($mn);
		file_put_contents($file, $action.',', FILE_APPEND);
		$data = array();
		foreach($indics as $funcs)
		{
			$litteralfuncs = implode('&', $funcs);
			$data[$litteralfuncs] = array();
			$slicelength = 100;
			$slice = ($days+$slicelength)*-1;
			for($slicelength; $slicelength < $slice*-1; $slicelength++)
			{
				$stock = new Stock($action, 'd', Stock::PROVIDER_CACHE);
				$closes = $stock->AdjustedClose(true);
// 				$ta = $stock->Slice($slice, $slicelength)->Analysis();
				$stock->Slice($slice, $slicelength);
				$tacache = array();
				foreach($funcs as $func)
				{
					if(!isset($tacache[$func]))
						$tacache[$func] = $stock->Analysis()->$func();
					if($tacache[$func] <= 0)
						continue 2; // Exit if not 
				}
				$max = 0;
				for($thislength = 1; $thislength< ($slice*-1 - $slicelength); $thislength++)
				{
					$m = max(array_slice($closes, $slice + $slicelength, $thislength));
					if($m > $max)
						$max = $m;
					elseif($m < $max*((100-(float)$seuil)/100))//delta 6% avant de couper court.
					{
						$slicelength += $thislength;
						break 1; // break at 
					}
					else	continue 1;
				}
				$data[$litteralfuncs][] = round(100*($max - $stock->getLast()) / $stock->getLast(), 3);
			}
			// Averaging the data...
			if(!empty($data[$litteralfuncs]))
			{
				$n = count($data[$litteralfuncs]);
				if($n>1)
				{
					$mean = array_sum($data[$litteralfuncs]) / $n;
					$carry = 0.0;
					foreach ($data[$litteralfuncs] as $val) {
						$d = ((double) $val) - $mean;
						$carry += $d * $d;
					};
					$rstdev = ($stdev = sqrt($carry / $n)) / $mean;
					$data[$litteralfuncs]['avg'] = $mean;
					$data[$litteralfuncs]['stdev'] = $stdev;
					$data[$litteralfuncs]['rstdev'] = $rstdev;
					$data[$litteralfuncs]['numb'] = $n;
					$data[$litteralfuncs]['power'] = round($stdev*$n/$mean, 2);
				}
				else
				{
					$data[$litteralfuncs]['avg'] = $data[$litteralfuncs][0];
					$data[$litteralfuncs]['stdev'] = 0;
					$data[$litteralfuncs]['rstdev'] = 0;
					$data[$litteralfuncs]['numb'] = 1;
					$data[$litteralfuncs]['power'] = 1;
				}
				file_put_contents($file, 
					$data[$litteralfuncs]['avg'].','.
					$data[$litteralfuncs]['stdev'].','.
					$data[$litteralfuncs]['numb'].',',
					FILE_APPEND);
			}
			else
				file_put_contents($file, '0,0,0,', FILE_APPEND);
		}
		//Interpret
		$pwr = array();
		$std = 100.0;
// 		$avg = array();
		foreach($data as $func => $p)
		{
// 			print 'Intervalle de Confiance 95 pour '.$func.' : '.($p['avg']-2*$p['stdev'])."\n";
			if(!isset($p['avg']))
				continue;
			if($p['avg'] < (float)$seuil) // Si la moyenne n'outrepasse pas le seuil de détection...
				continue;
			if($p['avg']-2*$p['stdev'] < (float)$seuil) // non significatif, contient 0.
				continue;
			//Puissance insuffisante => On quitte
			if($p['numb'] < 2 
// 				&& $p['avg'] < 2.5*(float)$seuil
				) // Si la puissance est insuffisante, au moins on élimine si la moyenne est inférieure à 2x le seuil afin d'approximer un IC95
				continue;
			$tests = explode('&', $func);
			if(!empty(array_intersect($tests, $pwr))) // Si une focntion composant l'indicateur existe déja seul parmi les indicateurs, c'est un indicateur redondant inutile qu'on annule.
				continue;
			$pwr[] = $func;
			if($p['stdev'] < $std)
				$std = $p['stdev'];
// 			$avg[$func] = $p['avg'];
			continue;
		}
		file_put_contents($file, implode('|', $pwr).','.$isin.','.$mn."\n", FILE_APPEND);
		return array(
			'IndicateurAchat' => implode('|', $pwr),
// 			'SeuilPolicy' => round($Volatility*100, 2),
// 			'SeuilPolicy2' => $std,
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
	public static $YahooSBF120 = array('SOLB.BR', 'LHN.PA','AF.PA','VCT.PA', 'ALT.PA', 'ICAD.PA', 'MON.PA', 'ERF.PA', 'BOL.PA', 'NEX.PA', 'ACA.PA', 'SOP.PA', 'MAU.PA', 'ATO.PA', 'RCF.PA', 'RMS.PA', 'MMT.PA', 'DIM.PA', 'UBI.PA', 'TFI.PA', 'FDR.PA', 'ATE.PA', 'SAF.PA', 'IPS.PA', 'DEC.PA', 'AI.PA', 'CGG.PA', 'CA.PA', 'CNP.PA', 'FP.PA', 'OR.PA', 'VK.PA', 'AC.PA', 'EN.PA', 'NEO.PA', 'SAN.PA', 'CS.PA', 'BN.PA', 'KN.PA', 'RI.PA', 'NK.PA', 'BB.PA', 'MC.PA', 'RF.PA', 'EO.PA', 'MF.PA', 'SW.PA', 'RUI.PA', 'ML.PA', 'HO.PA', 'KER.PA', 'UG.PA', 'EI.PA', 'SK.PA', 'HAV.PA', 'LI.PA', 'SU.PA', 'VIE.PA', 'POM.PA', 'UL.PA', 'SGO.PA', 'CAP.PA', 'ING.PA', 'DG.PA', 'CO.PA', 'ZC.PA', 'VIV.PA', /*'ALU.PA',*/ 'MMB.PA', 'FR.PA', 'RCO.PA', 'FGR.PA', 'PUB.PA', 'DSY.PA', 'GLE.PA', 'BNP.PA', 'TEC.PA', 'RNO.PA', 'ORA.PA', 'ORP.PA', 'ILD.PA', 'GNFT.PA', 'ELE.PA', 'BVI.PA', 'GFC.PA', 'BIM.PA', 'NXI.PA', 'SAFT.PA', 'ENGI.PA', 'ALO.PA', 'ETL.PA', 'MERY.PA', 'EDF.PA', 'IPN.PA', 'LR.PA', 'AKE.PA', 'IPH.PA', 'ADP.PA', 'KORI.PA', 'SCR.PA', 'DBV.PA', 'RXL.PA', 'GET.PA', 'SEV.PA', 'COFA.PA', 'EDEN.PA', 'TCH.PA', 'ADOC.PA', 'NUM.PA', 'GTT.PA', 'ELIOR.PA', 'ELIS.PA', 'EUCAR.PA', 'SESG.PA', 'MT.PA', 'APAM.AS', 'STM.PA', 'AIR.PA', 'GTO.PA', 'ENX.PA', /*'ALFIG.PA', 'SRP.PA',*/ 'CDI.PA');
	public static function BuildIndicators($days = 500, $seuil = '6%')
	{
// 		$list = StockInd::getInstance()->Lib;
		$Indicators = array();
		$i = 0;
		foreach(self::$YahooSBF120 as $ya)
		{
			print ++$i.'/'.count(self::$YahooSBF120).'...'."\n"; 
			$mn = strstr($ya, '.', true);
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
		}
		file_put_contents(self::EXTERNAL_INDICATORS, '<?php'."\n".'$Indicators = '.var_export($Indicators, true).';'."\n".'?>');
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

