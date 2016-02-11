<?php
/*	CreditMutuel Bourse API
	
	v0.1 alpha
	Without any garantee.
	
	This lib uses the reverse-engineered Mobile API of the Credit Mutuel android app
	to retreive data. It emulates an android phone, and log into the service every time
	the CreditMutuel Main class is called with credentials.

	Developped under php v5.6.15
		required : openssl extension
	Note : please verify that the "date.timezone" parameter is fulfilled in your php.ini
*/


class Webservice
{
	protected $Headers = array();
	protected $PostData = array();
	
	protected $Cookies = array();
	
	public function __construct()
	{
		// Basic USerAgent, Accept headers.
		$this->Headers['Accept'] = 'text/html,application/json,application/xml,text/xml';
		$this->Headers['User-Agent'] = 'AndroidVersion=4.4.2;Model=i9195';
		$this->Headers['Accept-Encoding'] = 'gzip, deflate';
	
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
	
	protected function get($url, $data = 'GET')
	{
		return $this->call($url, $data);
	}
	
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

class CreditMutuel extends Webservice
{

	const URL = 'https://mobile.creditmutuel.fr/wsmobile/fr';
	
	const ISIN_REGEX = '/[A-Z]{2}[0-9]{10}/';
	const RIB_REGEX = '/[0-9]{5} ?[0-9]{5} ?[0-9]{11}/';
	
	protected $ID = ''; // Session ID
	protected $RIB = ''; // Rib or account number, 10278 07944 00020307...
	
	public $AccountDetails = null;
	
	public function __construct($user, $pass, $rib = null)
	{
		// Construct Webservice
		parent::__construct();
		
		$this->Identification($user, $pass);
		
		if(!is_null($rib))
			$this->RIB($rib);
		else
			$this->RIB($this->AccountsDetails->rib);
		
		$this->PostData['_media'] = 'AN';
		$this->PostData['_wsversion'] = 7;
		
		return $this;
	}

	public function RIB($r=null)
	{
		if(is_null($r))	return $this->RIB;
		if(!preg_match(self::RIB_REGEX, $r))
			throw new Exception('Wrong Account number !'.$r);
		$this->RIB = str_replace(' ', '', $r);
		return $this;
	}
	
	protected function isISIN($i)
	{
		return preg_match(self::ISIN_REGEX, $i);
	}
	
	/*
	* Authentification initiale. 
	*  Si l'authentification est successful, crée le stream_context_set_default() avec la session correspondante.
	*/
	const URL_AUTH = '/IDE.html';
	const IDSESS = 'IdSes';
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
		$ch = curl_init();

		// set URL and other appropriate options
		curl_setopt($ch, CURLOPT_URL, self::URL . self::URL_AUTH);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS,
			array(
				'_cm_user' => $user,
				'_cm_pwd' => $pass
				)
			);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// 		ob_start();
		$response = curl_exec($ch);
		$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		// close cURL resource, and free up system resources
		curl_close($ch);
		
		// parse headers and get Cookie IdSes
		$this->parseResponseHeaders(substr($response, 0, $header_size));
		
		//return account details
		return $this->AccountDetails = new SimpleXMLElement(substr($response, $header_size));
	}
	
	const URL_VALO = '/bourse/SecurityAccountOverview.aspx';
	public function Valorisation($getraw = false)
	{
		$url = self::URL . self::URL_VALO;
// 		if($rib != null)
// 			$url .= '?dostit='.$rib;
			
		$args = array(
			'SecurityAccount' => $this->RIB,
			'NbElemMaxByPage' => 100,
			'currentpage' => 1,
			);
		
		if($getraw)	return $this->post($url, $args);
		
		$re = array();
		foreach($this->post($url, $args)
			->SecurityAccountOverview
			->Overview
			->security as $v)
			$re[] = new Action($this, $v);
		
		return $re;
	}

	const URL_ORDRES = '/bourse/OrderManagement.aspx';
	public function ListeOrdres($Status = 'EC')
	{
		$s = array('EC' => 'En Cours', 'EX' => 'Executé', 'AN' => 'Annulé', 'EU' => 'Echu');
		if(!array_key_exists($Status, $s)) 
			if(in_array($Status, $s))
				$Status = array_search($Status, $s);
			else
				throw new Exception('Wrong Status code '.$Status);
			
		$url = self::URL . self::URL_ORDRES;
		
		$re = array();
		
		foreach($this->call($url, array(
			'currentpage' => 1,
			'NbElemMaxByPage' => 100,
			'SecurityAccount' => $this->RIB,
			'Status' => $Status, //EC = en cours, EU = echu, AN = annulé, EX = executé
			))
			->OrderManagement
			->OrderList
			->Order as $v)
			$re[] = new OrdreEnCours($this, $v);
		return $re;
	}
	public function Ordres($Status = 'EC')
	{
		return $this->ListeOrdres($Status);
	}
	
	const URL_ORDRE_DELETE = '/bourse/CancelOrder.aspx';
	public function DeleteOrdre($ref, $isin = null, $orderbook = 'BFR')
	{
		$url = self::URL . self::URL_ORDRE_DELETE;
		
		if($isin == null)
		{
			foreach($this->ListeOrdres('EC') as $v)
				if($v->RefOrdre == $ref)
				{
					$isin = $v->IsinCode;
					$orderbook = $v->OrderBook;
				}
		}
		elseif(!$this->isISIN($isin))
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

	public function Ordre($isin = null)
	{
		return new Ordre($this, $isin);
	}
	public function OrdreBourse($isin = null)
	{
		return $this->Ordre($isin);
	}
	public function NouvelOrdre($isin = null)
	{
		return $this->Ordre($isin);
	}
}

class Action extends CreditMutuel
{
	public $V = null;
	private $C = null;
	public function __construct(CreditMutuel $C, $isin, $nom = '')
	{
// 		print_r($isin->attributes());
		$this->C = $C;
		if($isin instanceof SimpleXMLElement)
			$this->V = $isin;
		else
		{
			if(!$this->isISIN($isin))
				throw new Exception('Wrong ISIN : '.$isin);
			if($nom == '')
				$nom = $isin;
			$this->V = new SimpleXMLElement('<root><security isincode="'.$isin.'">'.$nom.'</security></root>');
		}
		return $this;
	}
	
	public function __toString()
	{
		return (string) $this->V;
	}
	
	public function __get($n)
	{
		if($n == 'nom' || $n == 'valeur' || $n == 'name' || $n == 'action' || $n == 'Action')
			return (string)$this->V;
		$corr = array(
			'isin' => 'IsinCode',
			'id' => 'IsinCode',
			'place' => 'TradingPlace',
			'plusvalue' => 'GainLostValueInEur',
			'pctplusvalue' => 'GainLostPrct',
			'plusvaluepct' => 'GainLostPrct',
			'plusvalue%' => 'GainLostPrct',
			'qte' => 'Nominal',
			'quantite' => 'Nominal',
			'eligiblesrd' => 'FlagSRD',
			'issrd' => 'FlagSRD',
			'prixderevient' => 'UnitCostPrice',
			'prixrevient' => 'UnitCostPrice',
			'valorisation' => 'ValueInEur',
			'valorisationpct' => 'ValueInPrct',
			'variationjour' => 'DayBeforeVariation',
			'cours' => 'LastQuote',
			'devise' => 'QuoteCurrency',
			'vendable' => 'FlagSell',
			'achetable' => 'FlagBuy',
			'opcvm' => 'FlagOpcvm',
			'srd' => 'PositionSRD',
			);
		$att = $this->V->attributes();
		if(array_key_exists(strtolower($n), $corr))
			return (string) $att[$corr[strtolower($n)]];
		if(isset($att[$n]))
			return (string) $att[$n];
		throw new Exception($n.' doesn\'t exist in this value '.$this);
		
	}
	
	public function Ordre()
	{
		return Ordre::_New($this->C, $this->V->IsinCode)
			->Place($this->V->TradingPlace)
			;
	}
	
	public function Acheter($qte = null, $cours = null)
	{
		return $this->Ordre()->Acheter($qte, $cours);
	}
	
	public function Vendre($qte = null, $cours = null)
	{
		return $this->Ordre->Vendre($qte, $cours);
	}
}

/* AccountDetails
class AccountDetails extends CreditMutuel
{
	protected $account = array();
	public function __construct(SimpleXMLElement $r)
	{
		$this->data = $r;
	}
	
	public function __get($n)
	{
		return $this->account->$n;
	}
	
}*/

class OrdreEnCours extends CreditMutuel
{
	private $O = null;
	private $C = null;
	private $Action = null;
	public function __construct(CreditMutuel $C, SimpleXMLElement $t)
	{
		$this->C = $C;
		$this->O = $t;
		$this->Action = new Action($C, $t->attributes()['IsinCode'], (string)$t);
		return $this;
	}
	
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
	
	public function __toString()
	{
		return $this->O->attributes()['OrderType'].' '.$this->O->attributes()['Nominal'].' '.(string)$this->O;
	}
	
	public function __get($n)
	{
		$corr = array(
			'sens' => 'OrderType',
			'type' => 'OrderType',
			'isin' => 'IsinCode',
			'id' => 'RefOrdre',
			'ref' => 'RefOrdre',
			'reforder' => 'RefOrdre',
			'qte' => 'Nominal',
			'quantite' => 'Nominal',
			'nombre' => 'Nominal',
			'status' => 'StatusLabel',
			'statuscode' => 'StatusCode',
			'date' => 'DateOrder',
			'heure' => 'TimeOrder',
			'encours' => 'OrderPending',
			'livre' => 'OrderBook',
			);
		$att = $this->O->attributes();
		if(array_key_exists(strtolower($n), $corr))
			return (string)$att[$corr[strtolower($n)]];
		if(isset($att[$n]))
			return (string)$att[$n];
		throw new Exception($n.' doesn\'t exist in this value '.$this);
			
	}
	
	public function Delete()
	{
		if(!(boolean)$this->OrderPending)
			return false;
		return $this->C->DeleteOrdre($this->RefOrdre, $this->IsinCode, $this->OrderBook);
	}
	public function Supprimer()
	{
		return $this->Delete();
	}
	public function Annuler()
	{
		return $this->Delete();
	}
}

class Ordre extends CreditMutuel
{	
	const FERMETURE_BOURSE = '17:35';
	const VALIDITE_MAX = '+3 months'; // strtotime like
	
	const PLACE_EURONEXT = '025';
	const PLACE_DAX = '044';
	
// 	const URL_ORDRE = '/banque/ORD_ValeurSaisie.aspx';
	const URL_ORDRE = '/bourse/PlaceOrderValidConf.aspx';
// 	const URL_VALIDATION = '/banque/ORD_ValeurValidation.aspx';
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
			2 => array('Au marché', false, false),
			3 => array('A la meilleure limite', false, false),
			4 => array('A seuil de déclenchement', false, true),
			5 => array('A plage de déclenchement', true, true),
			6 => array('Au dernier cours', false, false),
		);
		
	public static 	$valid = array(
			1 => 'Jour',
			2 => 'Mensuelle',
			3 => 'Maximale',
			4 => 'Jusqu\'au',
			5 => 'Hebdomadaire',
		);
	public function __construct(CreditMutuel $CM, $isin = null)
	{
		$this->CM = $CM;
		if(!is_null($isin))
			if($isin instanceof Action)
				$this->ISIN($isin->IsinCode);
			else
				$this->ISIN($isin);
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
	
	public static function _New(CreditMutuel $CM, $isin = null)
	{
		return new self($CM, $isin);
	}
	
	public function Send()
	{
		$url = parent::URL . self::URL_ORDRE;
		foreach(self::$requiredFields as $r)
			if($this->$r == '' || is_null($this->$r))
				throw new Exception('Le paramètre ['.$r.'] est requis et ne semble pas avoir été précisé.');
		
// 		print_r($this->OrdreData);
		return OrdreEnCours::NewOrder(
			$this->CM, 
			$this->CM->call($url, $this->OrdreData)
				->PlaceOrderValidConf
				->OrderPlacement
			);
	}
	public function Exec()
	{
		return $this->Send();
	}
	
	public function ISIN($isin)
	{
		if(!$this->isISIN($isin))
			throw new Exception('Invalid ISIN Code '.$isin);
		$this->IsinCode = $isin;
		return $this;
	}
	
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
	
	public function Acheter($qte = null, $cours = null)
	{
		if(!is_null($qte))
			$this->Qte($qte);
		if(!is_null($cours))
			$this->ACoursLimite($cours);
		return $this->Sens('A');
	}
	public function Achat($qte=null,$cours=null)
	{
		return $this->Acheter($qte,$cours);
	}
	public function Buy($qte=null,$cours=null)
	{
		return $this->Acheter($qte,$cours);
	}
	
	public function Vendre($qte = null, $cours = null)
	{
		if(!is_null($qte))
			$this->Qte($qte);
		if(!is_null($cours))
			$this->ACoursLimite($cours);
		return $this->Sens('V');
	}
	public function Vente($qte =null,$cours=null)
	{
		return $this->Vendre($qte,$cours);
	}
	public function Sell($qte=null,$cours=null)
	{
		return $this->Vendre($qte,$cours);
	}
	
	public function Qte($qte)
	{
		$this->Nominal =(int) $qte;
		return $this;
	}
	public function Nominal($qte)
	{
		return $this->Qte($qte);
	}
	
	public function Modalite($m, $lim = null, $seuil = null)
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
		
		if(self::$modal[$m][1]) // Limite
			$this->Limit = $lim;
		if(self::$modal[$m][2]) // Seuil
			$this->StopLimit = $seuil;

		$this->Modality = $m;
		return $this;
	}
	public function Modality($m, $lim=null, $seuil = null)
	{
		return $this->Modalite($m, $lim, $seuil);
	}
	public function ACoursLimite($cours)
	{
		return $this->Modalite(1, $cours);
	}
	public function AuMarche()
	{
		return $this->Modalite(2);
	}
	public function ASeuil($seuil)
	{
		return $this->Modalite(4, $seuil);
	}
	public function ALaMeilleureLimite()
	{
		return $this->Modalite(3);
	}
	public function APlage($seuil, $lim)
	{
		return $this->Modalite(5, $lim, $seuil);
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

