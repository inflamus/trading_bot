<?php


class Webservice
{
	const USERAGENT = 'AndroidVersion=4.4.2;Model=i9195';
	protected $Headers = array();
	protected $PostData = array();
	
	protected $Cookies = array();
	
	public function __construct()
	{
		// Basic USerAgent, Accept headers.
		$this->Headers['Accept'] = 'text/html,application/json,application/xml,text/xml';
		$this->Headers['User-Agent'] = self::USERAGENT;
		$this->Headers['Accept-Encoding'] = 'gzip, deflate';
		return $this;
	}
	
	protected function call($url, $data = 'GET')
	{
		$raw = file_get_contents($url, false, $this->context($data));
		if($raw === false)
			throw new Exception('Invalid or unreachable URL '.$url);
		
		// Parse headers
		$headers = $this->parseResponseHeaders($http_response_header);
		if(array_key_exists('Content-Encoding', $headers))
			switch($headers['Content-Encoding'])
			{
				default:
				break;
				case 'gzip':
				case 'deflate':
					$raw = gzdecode($raw); // Requires php 5.4.0
				break;
			}
			
// 		file_put_contents('CM_DEBUG.txt', $raw);

		if(array_key_exists('Content-Type', $headers))
			switch(strstr($headers['Content-Type'], ';', true))
			{
				default:
				case 'application/xml':
				case 'text/xml':
					$xml = new SimpleXMLElement($raw);
					if($xml->code_retour != '0000')
						throw new Exception($xml->msg_retour);
					return $xml;
				break;
				case 'application/json':
				case 'text/json':
					return json_decode($raw);
				break;
				case 'text/html':
					return $raw;
				break;
			}
		
		// finally, parse as XML
		return $raw;
// 		return new SimpleXMLElement($raw);
	}
	
	protected function post($url, $data = array())
	{
		return $this->call($url, $data);
	}
/*	
	protected function get($url, $data = 'GET')
	{
		return $this->call($url, $data);
	}*/
	
	protected function context($data = 'GET')
	{
		$context = array('http' => array());
		//build Cookies
		$this->Headers['Cookie'] = '';
		foreach($this->Cookies as $k=>$v)
			$this->Headers['Cookie'] .= $k.'='.$v.'; ';
			
		// Build Headers
		$context['http']['header'] = 
			implode("\r\n", 
				array_map(
					function($v, $k){	
						return $k.': '.$v;
						}, 
					array_values($this->Headers), 
					array_keys($this->Headers)
			));
// 		print $context['http']['header'];
		if($data == 'GET' || !is_array($data))
			$context['http']['method'] = 'GET';
		else
		{
			$context['http']['Content-type'] = 'application/x-www-form-urlencoded';
			$context['http']['method'] = 'POST';
			$context['http']['content'] = http_build_query(array_merge((array)$data, $this->PostData));
// 			print $context['http']['content'];
		}
		return stream_context_create($context);
	}
	
	protected function parseResponseHeaders($response)
	{
		if(is_string($response))
			$response = explode("\n", $response);
		
		$headers = array();
		foreach($response as $h)
		{
			if(trim($h) == "") continue; // empty headers...
			if(substr($h, 0, 4) == 'HTTP') continue;
			$i = explode(': ', $h);
			$headers[$i[0]] = $i[1];
			
			// Catch Cookies
			if($i[0] == 'Set-Cookie')
				$this->Cookies[strstr($i[1], '=', true)] = 
					substr(
						strrchr(
							strstr(
								$i[1], 
								';', 
								true),
						'='),
					1);
		}
		
// 		file_put_contents('CM_HeADERS_DEBUG.txt', implode("\r\n", $headers));
		return $headers;
	}
}

class CreditMutuel extends Webservice implements Broker
{

	const URL = 'https://mobile.creditmutuel.fr/wsmobile/fr';
	
	const ISIN_REGEX = '/[A-Z]{2}[0-9]{10}/';
	const RIB_REGEX = '/[0-9]{5} ?[0-9]{5} ?[0-9]{11}/';
	
	protected $ID = ''; // Session ID
	protected $RIB = ''; // Rib or account number, 10278 07944 00020307...
	
	public $AccountDetails = null;
	
// 	private $user='', $pass='';
	
	public function __construct($user, $pass = null, $rib = null)
	{
		// Construct Webservice
		parent::__construct();
		
// 		$this->user = $user;
// 		$this->pass = $pass;
		if($user instanceof EncryptedCredentials)
		{
			$encrypted = $user->get($this); // Pass credit mutuel, for security reasons...
			$user = $encrypted[0];
			$pass = $encrypted[1];
		}
		else
		{
			if(!is_string($user) || is_null($pass))
				throw new Exception('You must pass credentials to CreditMutuel constructor');
		}
		
		$this->Identification($user, $pass);
		
		if(!is_null($rib))
			$this->RIB($rib);
		else
			$this->RIB($this->AccountDetails->rib);
		
		$this->PostData['_media'] = 'AN';
		$this->PostData['_wsversion'] = 7;
		
		self::registerCurrentInstance($this);
		
		return $this;
	}

	public static function registerCurrentInstance(CreditMutuel $instance)
	{
		$GLOBALS['CM_CURRENT_INSTANCE'] = $instance;
		return true;
	}
	
	public static function getCurrentInstance()
	{
		if(isset($GLOBALS['CM_CURRENT_INSTANCE']))
			return $GLOBALS['CM_CURRENT_INSTANCE'];
		else
			throw new Exception('No Instance of CreditMutuel was found. Please Login before calling a function requiring the CM Webservice');
	}
	
	public function RIB($r=null)
	{
		if(is_null($r))	return $this->RIB;
		if(!preg_match(self::RIB_REGEX, $r))
			throw new Exception('Wrong Account number !'.$r);
		$this->RIB = str_replace(' ', '', $r);
		return $this;
	}
	
	
    const CREDENTIALS_DIR = 'CM_sess/';
    protected function CRED($file = '')
    {
		return dirname(__FILE__).'/../'.self::CREDENTIALS_DIR.$file;
	}
	/*
	* Authentification initiale. 
	*  Si l'authentification est successful, crée le stream_context_set_default() avec la session correspondante.
	*/
	const URL_AUTH = '/IDE.html';
	const IDSESS = 'IdSes';
	const SAVE_SESSIONS = true; // Used for DEBUG mode, unsafe for production mode !!
	const SESSION_TIMEOUT = 600; // 10 minutes
	private function Identification($user, $pass)
	{
		// Cannot get IdSes with $http_response_headers, because of 1024 chars headers length of this internal variable.
// 		$auth = array(
// 			'_cm_user' => $user,
// 			'_cm_pwd' => $pass
// 			);
// 			
// 		return $this->AccountDetails = $this->call(self::URL . self::URL_AUTH, $auth);
// 		
		$headers = $body = '';
		$session = $this->CRED(md5($user.':'.$pass) . '.sess.gz');
		if(self::SAVE_SESSIONS && 
			is_readable($session) && 
			(filemtime($session)+self::SESSION_TIMEOUT) > time())
			// Extracting old (10 minutes) session data.
			extract(unserialize(gzinflate(file_get_contents($session))));
		else
		{
			$ch = curl_init();

			// set URL and other appropriate options
			curl_setopt($ch, CURLOPT_URL, self::URL . self::URL_AUTH);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS,
				http_build_query(array(
					'_cm_user' => $user,
					'_cm_pwd' => $pass
					)
				));
			curl_setopt($ch, CURLOPT_USERAGENT, parent::USERAGENT);
			curl_setopt($ch, CURLOPT_HEADER, true);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// 		ob_start();
			$response = curl_exec($ch);
			$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
			// close cURL resource, and free up system resources
			curl_close($ch);
			
			$headers = substr($response, 0, $header_size);
			$body = substr($response, $header_size);
						
			if(!preg_match('/200 OK/s', $headers))
				throw new Exception('Wrong credentials, or authentication error');
			if(self::SAVE_SESSIONS)
			{
				if(!is_dir($this->CRED()))
					mkdir($this->CRED());
				file_put_contents($session, gzdeflate(serialize(compact('headers', 'body'))));
			}
		}
		// parse headers and get Cookie IdSes
		$this->parseResponseHeaders($headers);
		
		//Handle Loggin errors.
		try{
			$this->AccountDetails = new SimpleXMLElement($body);
		}
		catch(Exception $e)
		{// If an error was fired when SimpleXMLElement the returned body, that's mean there was a bug parsing the XML data.
			sleep(5); // Wait 5 seconds, clear cache and redo.
			if(self::SAVE_SESSIONS)
				@unlink($session);
			return $this->Identification($user, $pass);
		}
		//return account details
		return $this->AccountDetails;
	}
	
	const URL_VALO = '/bourse/SecurityAccountOverview.aspx';
	public function Valorisation(/*$getraw = false*/)
	{
		$url = self::URL . self::URL_VALO;
// 		if($rib != null)
// 			$url .= '?dostit='.$rib;
			
		$args = array(
			'SecurityAccount' => $this->RIB,
			'NbElemMaxByPage' => 100,
			'currentpage' => 1,
			);
		
// 		if($getraw)	return $this->post($url, $args);
		
		foreach($this->post($url, $args)
			->SecurityAccountOverview
			->Overview
			->security as $v)
			yield new CMPosition($this, $v);
		
	}

	const URL_ORDRES = '/bourse/OrderManagement.aspx';
	public function PendingOrders(/*$Status = 'EC'*/)
	{
// 		$s = array('EC' => 'En Cours', 'EX' => 'Executé', 'AN' => 'Annulé', 'EU' => 'Echu');
// 		if(!array_key_exists($Status, $s)) 
// 			if(in_array($Status, $s))
// 				$Status = array_search($Status, $s);
// 			else
// 				throw new Exception('Wrong Status code '.$Status);
		$Status = 'EC';//En cours
			
		$url = self::URL . self::URL_ORDRES;
				
		foreach($this->call($url, array(
			'currentpage' => 1,
			'NbElemMaxByPage' => 100,
			'SecurityAccount' => $this->RIB,
			'Status' => $Status, //EC = en cours, EU = echu, AN = annulé, EX = executé
			))
			->OrderManagement
			->OrderList
			->Order as $v)
			yield new CMPendingOrder($this, $v);
		
	}
	
	const URL_ORDRE_DELETE = '/bourse/CancelOrder.aspx';
	public function DeleteOrdre($ref, $isin = null, $orderbook = 'BFR')
	{
		$url = self::URL . self::URL_ORDRE_DELETE;
		
		if($isin == null)
		{
			foreach($this->PendingOrders() as $v)
				if($v->RefOrdre == $ref)
				{
					$isin = $v->IsinCode;
					$orderbook = $v->OrderBook;
				}
		}
		elseif(!StockInd::isISIN($isin))
			throw new Exception('Wrong ISIN '.$isin);
		
		$args = array(
			'SecurityAccount' => $this->RIB,
			'IsinCode' => $isin,
			'RefOrder' => $ref,
			'OrderBook' => $orderbook
			);
// 		return $args;
		return $this->call($url, $args)
			->CancelOrder
			->Result;
	}

	public function Ordre(Stock $stock)
	{
		return new CMOrdre($this, $stock);
	}
}

class CMOrdre extends CreditMutuel implements Ordre
{	
	const FERMETURE_BOURSE = '17:35';
	const VALIDITE_MAX = '+3 months'; // strtotime like
	
	const PLACE_EURONEXT = '025';
	const PLACE_DAX = '044';
	
// 	const URL_ORDRE = '/demonstration/bourse/PlaceOrderValidConf.aspx';
	const URL_ORDRE = '/bourse/PlaceOrderValidConf.aspx';

	private $CM = null;
	private $OrdreData = array(
        "SecurityAccount" => '',
        "IsinCode" => '',
        "TradingPlace" => self::PLACE_EURONEXT,
        "Step" => 2,
        "Direction" => '',
        "Modality" => '',
        "Nominal" => '',
        "Market" => 'I',
        "Validity" => '',
        "Notification" => 'M', // (N)on, (M)ail
        "Forcing" => '',
//         "Limit" => '',
//         "StopLimit" => '',
        );
	private static	$requiredFields = array('SecurityAccount', 'IsinCode', 'Validity', 'Modality', 'Direction', 'Nominal');
	
	public static	$modal = array( // ID => array('Label', Limit ?, StopLimit ?)
			1 => array('A cours limite', true, false),
			4 => array('Au marché', false, false),
			5 => array('A la meilleure limite', false, false),
			2 => array('A seuil de déclenchement', false, true),
			3 => array('A plage de déclenchement', true, true),
			6 => array('Au dernier cours', false, false),
		);
		
	public static 	$valid = array(
			1 => 'Jour',
			2 => 'Mensuelle',
			3 => 'Maximale',
			4 => 'Jusqu\'au',
			5 => 'Hebdomadaire',
		);
	public function __construct(CreditMutuel $CM, Stock $stock)
	{
		$this->CM = $CM;
// 		if(!is_null($isin))
// 			if($isin instanceof Action)
// 				$this->ISIN($isin->IsinCode);
// 			else
// 				$this->ISIN($isin);
		$this->IsinCode = $stock->ISIN();
		$this->SecurityAccount = $CM->RIB;
		return $this;
	}
	
	public function __set($n, $v)
	{
		if(array_key_exists($n, $this->OrdreData) || $n == 'Limit' || $n == 'StopLimit')
			$this->OrdreData[$n] = $v;
		else
			throw new Exception('Unknown ['.$n.'] variable');
	}
	
	public function __get($n)
	{
		if(isset($this->OrdreData[$n]))
			return $this->OrdreData[$n];
		throw new Exception('Unknown ['.$n.'] __get value');
	}
	
	public function Place($pl = self::PLACE_EURONEXT)
	{
		$this->TradingPlace = $pl;
		return $this;
	}
	public function TradingPlace($pl = self::PLACE_EURONEXT)
	{
		return $this->Place($pl);
	}
	
	public static function _New(CreditMutuel $CM, Stock $stock)
	{
		return new self($CM, $stock);
	}
	
	public function Send()
	{
		print_r($this->OrdreData);
		$url = CreditMutuel::URL . self::URL_ORDRE;
		foreach(self::$requiredFields as $r)
			if($this->$r == '' || is_null($this->$r))
				throw new Exception('Le paramètre ['.$r.'] est requis et ne semble pas avoir été précisé.');

		return new CMPendingOrder(
			$this->CM, 
// 			new OrdreEnCours($this->CM, new SimpleXMLElement('<root><OrderPlacement IsinCode="'.$this->OrdreData['IsinCode'].'"></OrderPlacement></root>'))
			$this->CM->call($url, $this->OrdreData)
				->PlaceOrderValidConf
				->OrderPlacement
			);
	}
	public function Exec()
	{
		return $this->Send();
	}
	
// 	public function ISIN($isin)
// 	{
// 		if(!$this->isISIN($isin))
// 			throw new Exception('Invalid ISIN Code '.$isin);
// 		$this->IsinCode = $isin;
// 		return $this;
// 	}
	
	public function Sens($s)
	{
		if($s == 'A' || $s == 'V' || $s == 'T');
			$this->Direction = $s;
		return $this;
	}
	public function Direction($s)
	{
		return $this->Sens($s);
	}
	
	public function Acheter($qte)
	{
		$this->Qte($qte);
		return $this->Sens('A');
	}
	public function Achat($qte)
	{
		return $this->Acheter($qte);
	}
	public function Buy($qte)
	{
		return $this->Acheter($qte);
	}
	
	public function Vendre($qte)
	{
		$this->Qte($qte);
		return $this->Sens('V');
	}
	public function Vente($qte)
	{
		return $this->Vendre($qte);
	}
	public function Sell($qte)
	{
		return $this->Vendre($qte);
	}
	
	protected function Qte($qte)
	{
		$this->Nominal =(int) $qte;
		return $this;
	}
	protected function Nominal($qte)
	{
		return $this->Qte($qte);
	}
	
	protected function Modalite($m, $lim = null, $seuil = null)
	{
		if(!isset(self::$modal[$m]))
		{ // Rattrape les appels avec une chaine complete
			foreach(self::$modal as $k => $v)
				if(strtolower(substr($v,0,2)) == strtolower(substr($m,0,2)))
				{
					$m = $k;
					break;
				}
		}
		if(!isset(self::$modal[$m]))
			throw new Exception('Cette modalité ['.$m.'] est inconnue.');
		
		if(self::$modal[$m][1]) // Limit 
			if(is_null($lim))
				throw new Exception('A Limit should be passed.');
			else
				$this->Limit = (float)$lim;
		if(self::$modal[$m][2]) // Seuil
			if(is_null($lim))
				if(is_null($seuil))
					throw new Exception('Un seuil doit être spécifié.');
				else
					$this->StopLimit =(float)$seuil;
			else
				$this->StopLimit = (float)$lim;
		
		$this->Modality = $m;
		return $this;
	}
	protected function Modality($m, $lim=null, $seuil = null)
	{
		return $this->Modalite($m, $lim, $seuil);
	}
	public function ACoursLimite($cours)
	{
		return $this->Modalite(1, $cours);
	}
	public function AuMarche()
	{
		return $this->Modalite(4);
	}
	public function ASeuil($seuil)
	{
		return $this->Modalite(2, $seuil);
	}
	public function ALaMeilleureLimite()
	{
		return $this->Modalite(5);
	}
	public function APlage($seuil, $lim)
	{
		return $this->Modalite(3, $lim, $seuil);
	}
	public function AuDernierCours()
	{
		return $this->Modalite(6);
	}

	public function Confirmation($ouinon = true)
	{
		$this->Notify = $ouinon ? 'M' : 'N';
		return $this;
	}
	public function Notify($ouinon = true)
	{
		return $this->Confirmation($ouinon);
	}
	
	public function Validite($v, $date = null)
	{
		if(is_string($v) && strtotime($v) > time()) // raccourci pour Ordre Jusqu'au date spécifiée;
			return $this->Validite(4, strtotime($v));
		if(!isset(self::$valid[$v]))
		{ // Rattrape les "Mensuelle", "journalier", "hebdomadaire"...
			$va = array_flip(self::$valid);
			array_walk($va, function($v, &$k){ return strtolower($k); });
			$v = strtolower($v);
			if(!isset($va[$v]))
				throw new Exception('Validité incorrecte '.$v);
			$v = $va[$v];
		}
		switch($v)
		{
			default:
			case 1: // jour
				if(strtotime(self::FERMETURE_BOURSE)-time() > 0) // Nous sommes en journée, valide jusqu'a ce soir.
					return $this->Validite(4, time()+10);
				// Sinon, nous le remettons à demain.
				$tomorrow = strtotime('+1 day');
				if(date('w', $tomorrow) == 6 || date('w', $tomorrow) == 0)
					// mais si demain est samedi ou dimanche
					// nous le remettons à lundi prochain
					return $this->Validite(4, strtotime('next Monday'));
				else
					return $this->Validite(4, $tomorrow);
			break;
			case 5: // Hebdo
				return date('w') == 5 && strtotime(self::FERMETURE_BOURSE) - time() > 0 ? 
					$this->Validite(4, time()+10) : // nous sommes vendredi en journée, set la validité jsuqu'a ce soir. 
					$this->Validite(4, strtotime('next Friday')); // Nous sommes en semaine, set la validité jusqu'a vendredi prochain
			break;
			case 2: // Mensuelle
				return $this->Validite(4, strtotime('last Friday of this month'));
			break;
			case 3: // Maximale
				return $this->Validite(4, strtotime('last Friday of '.self::VALIDITE_MAX));
			break;
			case 4:
				if(is_null($date))
					throw new Exception('Vous devez spécifier une date avec ce mode de validité');
				if(is_int($date) && $date > time())
				{
					$w = date('w', $date);
					if($w == 0 || $w == 6) // sunday, saturday,
						$date = strtotime('next Monday', $date);
					$this->Validity = date('Ymd', $date);
				}
				else
					if(is_string($date) && strtotime($date) > time())
						return $this->Validite(4, strtotime($date));
// 						$this->Validity = $date;
					else
						throw new Exception('Une date est requise. Celle que vous avez spécifié est antérieure à aujourd\'hui, ou n\'est pas valide. Vous devez la spécifier au format YYYYmmdd, ou sous forme de timestamp(), correspondant aux jours ouvrables boursiers.');
			break;
		}
		return $this;
	}
	public function Jour()
	{
		return $this->Validite(1);
	}
	public function Jusquau($date)
	{
		return $this->Validite($date);
	}
	public function Mensuelle()
	{
		return $this->Validite(2);
	}
	public function Maximale()
	{
		return $this->Validite(3);
	}
	public function Hebdo($h=0)
	{
		return $this->Hebdomadaire($h);
	}
	public function Hebdomadaire($h=0)
	{
		if((int)$h>1)
			return $this->Validite(4, strtotime("Friday +$h weeks"));
		return $this->Validite(5);
	}
	public function BiHebdomadaire()
	{
		return $this->Hebdomadaire(2);
	}
	public function Expire($date)
	{
		return $this->Jusquau($date);
	}

	public function Reglement($m = 'I')
	{
		$m = strtolower($m);
		$this->Market = in_array($m, array('m', 'srd', 'différé', 'differe', 'diff', 'd')) ? 'M' : 'I';
		return $this;
	}
	public function Market($m = 'I')
	{
		return $this->Reglement($m);
	}
	public function Comptant()
	{
		return $this->Reglement('I');
	}
	public function Differe()
	{
		return $this->Reglement('M');
	}
}


class CMPosition extends CMOrdre implements Position
{
	use PositionScheme;
	
	public $V = null;
	private $C = null;

	public function __construct(CreditMutuel $C, SimpleXMLElement $lign)
	{
// 		print_r($isin->attributes());
		$this->C = $C;
		
// 		if($isin instanceof SimpleXMLElement)
		$this->V = $lign;
// 		else
// 		{
// 			if(!StockInd::isISIN($isin))
// 				throw new Exception('Wrong ISIN : '.$isin);
// 			if($nom == '')
// 				$nom = $isin;
// 			$this->V = new SimpleXMLElement('<root><security isincode="'.$isin.'">'.$nom.'</security></root>');
// 		}
		// position interface features
		$att = $this->V->attributes();
		$this	->set('SENS', $att->FlagSell ? 1 : 0)
				->set('QTY', $att->Nominal)
				->set('PRIXREVIENT', $att->UnitCostPrice)
				->set('LASTQUOTE', $att->LastQuote)
				->set('DAYVAR', $att->DayBeforeVariation)
				->set('GAINEUR', $att->GainLostValueInEur)
				->set('GAINPCT', $att->GainLostPrct)
				->set('CAPITAL', $att->ValueInEur)
				->set('SRD', $att->FlagSRD)
				->set('TRADINGPLACE', $att->TradingPlace)
				->set('DEVISE', $att->QuoteCurrency)
				->set('OPCVM', $att->FlagOpcvm)
				;
				
		//construct ordre
		parent::__construct($C, $this->Stock = new Stock((string)$att->IsinCode));
		
		$this->Qte();
		$this->Sens((boolean)$this->get('SENS') ? 'V' : 'A');
		$this->Hebdomadaire();
		
		return $this;
	}
	
	public function __toString()
	{
		return (string) $this->V;
	}
	
// 	public function __get($n)
// 	{
// 		if($n == 'nom' || $n == 'valeur' || $n == 'name' || $n == 'action' || $n == 'Action')
// 			return (string)$this->V;
// 		$corr = array(
// 			'isin' => 'IsinCode',
// 			'id' => 'IsinCode',
// 			'place' => 'TradingPlace',
// 			'plusvalue' => 'GainLostValueInEur',
// 			'pctplusvalue' => 'GainLostPrct',
// 			'plusvaluepct' => 'GainLostPrct',
// 			'plusvalue%' => 'GainLostPrct',
// 			'qte' => 'Nominal',
// 			'quantite' => 'Nominal',
// 			'qty' => 'Nominal',
// 			'eligiblesrd' => 'FlagSRD',
// 			'issrd' => 'FlagSRD',
// 			'prixderevient' => 'UnitCostPrice',
// 			'prixrevient' => 'UnitCostPrice',
// 			'capital' => 'ValueInEur',
// 			'valorisation' => 'ValueInEur',
// 			'valorisationpct' => 'ValueInPrct',
// 			'variationjour' => 'DayBeforeVariation',
// 			'cours' => 'LastQuote',
// 			'derniercours' => 'LastQuote',
// 			'devise' => 'QuoteCurrency',
// 			'vendable' => 'FlagSell',
// 			'achetable' => 'FlagBuy',
// 			'opcvm' => 'FlagOpcvm',
// 			'srd' => 'PositionSRD',
// 			);
// 		$att = $this->V->attributes();
// 		if(array_key_exists(strtolower($n), $corr))
// 			return (string) $att[$corr[strtolower($n)]];
// 		if(isset($att[$n]))
// 			return (string) $att[$n];
// 		throw new Exception($n.' doesn\'t exist in this position '.$this);
// 		
// 	}
	
// 	public function Ordre()
// 	{
// 		return Ordre::_New($this->C, $this->V->IsinCode)
// 			->Place($this->V->TradingPlace)
// 			;
// 	}
	
	public function Qte($qte = 0)
	{
		$this->Nominal = ($qte <= 0 || $qte > (int)$this->V->attributes()['Nominal']) ? (int)$this->V->attributes()['Nominal'] : $qte;
		return $this;
	}
/*	
	public function Acheter($qte = null, $cours = null)
	{
		return $this->Ordre()->Acheter($qte, $cours);
	}
	
	public function Vendre($qte = null, $cours = null)
	{
		return $this->Ordre->Vendre($qte, $cours);
	}*/
/*
	public function __save()
	{
		return $this->V->asXML();
	}*/
	
	public function __sleep()
	{
		$this->C = null;
		$this->V = $this->V->asXML();
		return array('C', 'V', 'Features', 'Stock');
	}
	public function __wakeup()
	{
		$this->V = new SimpleXMLElement($this->V);
		$this->C = parent::getCurrentInstance();
	}
}


class CMPendingOrder extends CreditMutuel implements PendingOrder
{
	use PendingOrderScheme;
	
	private $O = null;
	private $C = null;
// 	private $Action = null;
	private $Ref = '', $Dir = '', $OB = '';
	public function __construct(CreditMutuel $C, SimpleXMLElement $t)
	{
		$at = $t->attributes();
		$this->C = $C;
		$this->O = $t;
		$this->Ref = (string) (isset($at->RefOrdre) ? $at->RefOrdre : $at->OrderNumber);
		$this->Dir = strtolower(isset($at->OrderType) ? (string)$at->OrderType[0] : $at->Direction) == 'a' ? 1 : 0;
		$this->OB = isset($at->OrderBook) ? (string)$at->OrderBook : 'B'.substr($at->IsinCode, 0, 2);
		$this->set('SENS', $this->Dir)->set('REF', $this->Ref)->set('QTY', $this->qty);
		$this->Stock = new Stock($this->IsinCode);
// 		$this->Action = new Action($C, $t->attributes()['IsinCode'], (string)$t);
// 		$this->Action = new Stock((string)$at->IsinCode);
		return $this;
	}
/*	
	public static function NewOrder(CreditMutuel $C, $ref, $isin=null, $nom = null)
	{
		$a = new SimpleXMLElement('<root></root>');
		if($ref instanceof SimpleXMLElement)
		{
			$att = $ref->attributes();
			$a->addChild('Order', $att['SecurityLabel']);
			$a->addAttribute('Nominal', (int) ($att['Direction'] == 'A' ? '+':'-') .$att['Nominal']);
			$a->addAttribute('OrderType', ($att['Direction']=='A' ? 'ACHAT' : 'VENTE' ).' '. ($att['Market']=='I'?'COMPTANT' : 'DIFFERE'));
			$a->addAttribute('RefOrdre', $att['OrderNumber']);
			$a->addAttribute('OrderPending', 1);
			$a->addAttribute('IsinCode', $att['IsinCode']);
			$a->addAttribute('StatusCode', 'EC');
			$a->addAttribute('StatusLabel', 'En cours');
				$a->addAttribute('OrderBook', 'B'.substr($att['IsinCode'], 0, 2));
			$a->addAttribute('DateOrder', date('Ymd'));
			$a->addAttribute('TimeOrder', date('His'));
		}
		else
		{
			if(is_null($isin))
				throw new Exception('An ISIN Code must be passed to OrdreEnCours::NewOrder()');
			$a->addChild('Order', $nom);
			$a->addAttribute('Nominal', '??');
			$a->addAttribute('OrderType', '??');
			$a->addAttribute('RefOrdre', $ref);
			$a->addAttribute('OrderPending', 1);
			$a->addAttribute('IsinCode', $isin);
			$a->addAttribute('StatusCode', 'EC');
			$a->addAttribute('StatusLabel', 'En cours');
			$a->addAttribute('OrderBook', 'B'.substr($isin, 0, 2));
			$a->addAttribute('DateOrder', date('Ymd'));
			$a->addAttribute('TimeOrder', date('His'));
		}
		return new self($C, $a);
	}
	*/
	public function __toString()
	{
		return ($this->direction ? 'Achat' : 'Vente').' '.$this->nominal.' '.(string)$this->O;
	}
	
	public function __get($n)
	{
		$att = $this->O->attributes();
		switch(strtolower($n))
		{
			case 'sens': case 'type': case 'direction': case 'ordertype':
				return $this->Dir; break;
			case 'isin' : case 'isincode':
				return (string)$att->IsinCode; break;
			case 'id': case 'reforder': case 'ref': case 'refordre': case 'ordernumber':
				return $this->Ref; break;
			case 'qte': case 'qty': case 'quantity': case 'quantite': case 'nombre': case 'nb': case 'nominal':
				return (int)$att->Nominal; break;
			case 'status': case 'statuslabel':
				return (string)$att->StatusLabel; break;
			case 'statuscode': 
				return (string)$att->StatusCode; break;
			case 'date': case 'dateorder':
				return (string)$att->DateOrder; break;
			case 'heure': case 'timeorder':
				return (string)$att->TimeOrder; break;
			case 'pending': case 'encours': case 'orderpending':
				return (boolean)isset($att->OrderPending) ? $att->OrderPending : true; break;
			case 'orderbook': case 'book': case 'livre':
				return $this->OB; break;
			default:
					throw new Exception($n.' doesn\'t exist in this value '.$this);
				break;
		}
	}
	
	public function Delete()
	{
		if(!$this->OrderPending)
			return false;
		return $this->C->DeleteOrdre($this->Ref, $this->isin, $this->OB);
	}
// 	public function Supprimer()
// 	{
// 		return $this->Delete();
// 	}
// 	public function Annuler()
// 	{
// 		return $this->Delete();
// 	}

	public function __sleep()
	{
		$this->C = null;
// 		$isin = $this->Action->isin;
// 		$this->Action = $this->isin;
		$this->O = $this->O->asXML();
		return array('O', 'C', /*'Action',*/ 'Dir', 'Ref', 'OB');
	}
	public function __wakeup()
	{
// 		print_r($this);
		$this->O = new SimpleXMLElement($this->O);
		$this->C = parent::getCurrentInstance();
// 		$this->Action = new Action($this->C, $this->Action);
	}
}

class EncryptedCredentials extends CreditMutuel {

    private $skey = "CreditMutuelCredentialsEncryption"; // you can change it
	private $file = '';
	
    public function __construct($file = null)
    {
		if(!function_exists('mcrypt_create_iv'))
			throw new Exception('mcrypt extension must be enabled in php');
		if(substr($file, -3) != 'ids')
			$file .= '.ids';
		if(!is_null($file) && is_readable($this->CRED($file)))
			$this->constructKey(filemtime($this->CRED($file)));
// 		else
// 			throw new Exception('Unknown encrypted credentials...');
		$this->file = $this->CRED($file);
		return $this;
    }
    
    private function constructKey($time, $createfile = false)
    {
// 		print ($time);
		$time = ceil($time/10);
		$this->skey = substr($this->skey, 0, 32-strlen($time)).$time;
// 		print "\n".$this->skey;
// 		print "\nstrlen = ".strlen($this->skey);
		return $this;
    }
    
    // return user:pass
    final protected function get(CreditMutuel $C)
    {
		return explode(':', $this->decode(file_get_contents($this->file)));
    }
    
    public static function create($user, $pass)
    {
		if(!is_dir($this->CRED()))
			if(!mkdir($this->CRED()))
				throw new Exception('Cannot create Credentials directory.');
		$string = $user.':'.$pass;
		$file = uniqid();
		$cred = new self(__FILE__);
		if(file_put_contents($this->CRED($file.'.ids'), $cred->constructKey(time())->encode($string), LOCK_EX))
			print "ENCRYPTED CREDENTIALS CREATED. PLEASE NOW CALL CreditMutuel() with \n  new EncryptedCredentials('$file')   as argument.";
		else
			print "An error occured.";
		@chmod($this->CRED($file), 0400);
		return $file;
    }
    
    private function safe_b64encode($string) 
    {
        $data = base64_encode($string);
        $data = str_replace(array('+','/','='),array('-','_',''),$data);
        return $data;
    }
    private function safe_b64decode($string) 
    {
        $data = str_replace(array('-','_'),array('+','/'),$string);
        $mod4 = strlen($data) % 4;
        if ($mod4) 
        {
            $data .= substr('====', $mod4);
        }
        return base64_decode($data);
    }
    private function encode($value)
    { 
        if(!$value)	return false;
        $text = $value;
        $iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB);
        $iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
        $crypttext = mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $this->skey, $text, MCRYPT_MODE_ECB, $iv);
        return trim($this->safe_b64encode($crypttext)); 
    }
    private function decode($value)
    {
        if(!$value)	return false;
        $crypttext = $this->safe_b64decode($value); 
        $iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB);
        $iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
        $decrypttext = mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $this->skey, $crypttext, MCRYPT_MODE_ECB, $iv);
        return trim($decrypttext);
    }
}
