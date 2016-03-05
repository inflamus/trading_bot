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

interface Broker
{
	public function isISIN(&$i);
	public function Valorisation($getraw = false);
}
interface _OrdreEnCours 
{
}
class SimulatorAccount
{
	const BROKER_FEE = '0.9%';
	protected 	$cash = 0,
				$portefeuille = array(/* Isin => [qte, cours_d'achat, cours_actuel] */),
				$ordres = array(/* Rref => array('sens' => 'achat', 'isin', 'qte', 'cours')*/);
	private 	$slicestart = -600, $slicelength = 100;
	private		$stockCache = array();
	public function __construct()
	{
		return $this;
	}
	
	private function getStock($isin)
	{
		if(!isset($this->stockCache[$this->slicelength][$isin]))
		{
			$mn = StockInd::getInstance()->searchMnemo($isin);
			if($mn == 'SOLB')	$mn .= '.BR';
			elseif($mn == 'APAM')	$mn .= '.AS';
			else	$mn .= '.PA';
			$stock = new Stock($mn, 'd', Stock::PROVIDER_CACHE);
			return $this->stockCache[$this->slicelength][$isin] = $stock->Slice($this->slicestart, $this->slicelength);
		}
		else
			return $this->stockCache[$this->slicelength][$isin];
	}
	private function clearStockCache()
	{
		unset($this->stockCache[$this->slicelength]);
		return $this;
	}
	public function Start($start)
	{
		$this->slicestart = -1*($start+100);
		return $this;
	}
	public function NewDay($today=1)
	{
		$this->clearStockCache();
		$this->slicelength += $today;
// 		print_r($this->ordres);
// 		print $this->slicelength;
		if(!empty($this->portefeuille))
			print_r($this->portefeuille);
		$this
			->checkOrders()
			->reloadPortfolio();
// 		print $this->cash;
		return $this;
	}
	private function reloadPortfolio()
	{
		foreach($this->portefeuille as $isin => $d)
		{
			$s = $this->getStock($isin)->getLast();
			$this->portefeuille[$isin]['DernierCours'] = $s;
			$this->portefeuille[$isin]['PlusvaluePCT'] = round(($s-$this->portefeuille[$isin]['c_achat'])/$this->portefeuille[$isin]['c_achat'], 4)*100 .'%';
			$this->portefeuille[$isin]['UnitCostPrice'] = round($this->portefeuille[$isin]['c_achat']*(100+(float)self::BROKER_FEE)/100, 3);
			$this->portefeuille[$isin]['ValueInEur'] = $s * $this->portefeuille[$isin]['qte'];
		}
		return $this;
	}
	private function checkOrders()
	{
		foreach($this->ordres as $ref => $d)
		{
			if($d['expire'] < $this->slicelength)
			{
				$this->removeOrder($ref);
				continue;
			}
			$s = $this->getStock($d['isin'])->getLast();
// 			print 'Cours actuel : '.$s.' plus bas :'.$this->getStock($d['isin'])->getLast('Low');
			if($d['sens'] >0) // Achat
			{
				if(isset($d['cours']) && $d['cours'] <= $this->getStock($d['isin'])->getLast('Low')) // Cours limité, le cours est supérieur
					continue;
				else
					$s = $d['cours'];
				$somme = round($d['qte']*$s*(1+(float)self::BROKER_FEE/100), 2);
// 				print 'pour une somme = '.$somme;
// 				print $this->cash - $somme;
				if($this->cash < $somme)
				{
// 					print (float)$this->cash . '<' .$somme;
					print 'Pas assez de cash.';
					$this->removeOrder($ref);
					continue;
				}
				$this->Withdraw($somme);
				if(!isset($this->portefeuille[$d['isin']]))
					$this->portefeuille[$d['isin']] = array('qte'=>0,'c_achat'=>$s);
				$this->portefeuille[$d['isin']]['c_achat'] = ($this->portefeuille[$d['isin']]['c_achat']*
					$this->portefeuille[$d['isin']]['qte']+$s*$d['qte'])/($d['qte']+$this->portefeuille[$d['isin']]['qte']);
				$this->portefeuille[$d['isin']]['qte'] += $d['qte'];
				$this->portefeuille[$d['isin']]['stock'] = StockInd::getInstance()->searchLabel($d['isin']);
				$this->portefeuille[$d['isin']]['isin'] = $d['isin'];
				print "\n".' => Ordre Achat passé sur ['.$this->portefeuille[$d['isin']]['stock'].'] x '.$d['qte'].' pour un total de = '.$somme.'€'."\n";
				$this->removeOrder($ref);
			}
			else
			{ //vente
				if(isset($d['cours']) && $d['cours'] > $this->getStock($d['isin'])->getLast('High')) // cours limité non requis
					continue;
				elseif(isset($d['cours']))
					$s = $d['cours'];
				if(isset($d['seuil']) && $d['seuil'] < $this->getStock($d['isin'])->getLast('Low')) // le seuil n'est pas passé.
					continue;
				else
					$s = $d['seuil'];
// 				print_r( $d );
				//Ordre de vente passé
				$somme = $d['qte'] * $s * (1-(float)self::BROKER_FEE/100);
				$this->Deposit($somme);
				$this->portefeuille[$d['isin']]['qte'] -= $d['qte']; //TODO ontice
				print "\n".' => Ordre de Vente passé sur ['.$this->portefeuille[$d['isin']]['stock'].'] x '.$d['qte'].' à '.$s.' pour un total de = '.$somme.'€. Limite à '.$d['cours']*$d['qte'].'€' ."\n";
				if($this->portefeuille[$d['isin']]['qte'] <= 0)
					unset($this->portefeuille[$d['isin']]);
				$this->removeOrder($ref);
			}
		}
		return $this;
	}
	public function removeOrder($ref)
	{
		unset($this->ordres[$ref]);
		return $this;
	}
	public function addOrder($data)
	{
// 		print 'Receiving order ... '.print_r($data, true);
		$lim = $seuil = null;
		extract($data);
		$ref = uniqid();
		$this->ordres[$ref] = array(
			'isin' => $isin,
			'sens' => (int)$sens,
			'qte' => (int)$qte);
		if($lim != null)
			$this->ordres[$ref]['cours'] = (float)$lim;
		if($seuil != null)
			$this->ordres[$ref]['seuil'] = (float)$seuil;
		$this->ordres[$ref]['expire'] = $this->slicelength +1;
		return $ref;
	}
	
	public function Valorisation()
	{
// 		print_r((object) $this->portefeuille);
// 		return json_decode(json_encode($this->portefeuille), false);
// <security isincode="DE0007236101" tradingplace="044" flagsrd="0" nominal="+2000" lastquote="+92.700EUR" daybeforevariation="-0.39%" unitcostprice="+2.0000EUR" valueineur="+185400.00EUR" valueinprct="+0.25%" gainlostvalueineur="+181400.00EUR" gainlostprct="+4535.00%" form="Au porteur" depositplace="USA" lastoperationdate="20110107" flagbuy="0" flagsell="0" positionsrd="0">SIEMENS</security>
		return $this->portefeuille;
	}
	
	public function Withdraw($cash)
	{
		$this->cash -= (int)$cash;
		return $this;
	}
	public function Deposit($cash)
	{
		$this->cash += (int)$cash;
		return $this;
	}

	public function __toString()
	{
		return $this->cash.'€'."\n Portefeuille : ".print_r($this->portefeuille, true);
	}
	public static function getInstance()
	{
		if(isset($GLOBALS[__CLASS__]))	return $GLOBALS[__CLASS__];
		else	return $GLOBALS[__CLASS__] = new self();
	}
}

class Simulator implements Broker
{
	private $s = null;
	public function __construct(SimulatorAccount $sim)
	{
		$this->s = $sim;
	}
	public function isISIN(&$i)
	{
		return CreditMutuel::isISIN($i);
	}
	public function Valorisation($getraw = false)
	{
// 		return $this;
		$re = array();
		foreach($this->s->Valorisation() as $isin => $d)
		{
			$re[] = new Action($this, new SimpleXMLElement('<security IsinCode="'.$d['isin'].'" Nominal="'.$d['qte'].'" TradingPlace="024" GainLostPrct="'.$d['PlusvaluePCT'].'" LastQuote="'.$d['DernierCours'].'" ValueInEur="'.$d['ValueInEur'].'" UnitCostPrice="'.$d['UnitCostPrice'].'">'.$d['stock'].'</security>'));
		}
		return $re;
	}
	public function Ordre($isin)
	{
		return new OrdreSimulator($isin);
	}
}

class OrdreSimulator implements _OrdreEnCours
{//$this->CM->Ordre($isin)->Achat($nominal)->AuDernierCours()->Jour()->Exec();
	private $data = array();
	public function __construct($isin)
	{
		$this->isin = $isin;
		$this->IsinCode = $isin;
	}
	public function __get($n)
	{
		return $this->data[$n];
	}
	public function __set($n, $v)
	{
		$this->data[$n] = $v;
		return true;
	}
	public function __call($n,$v)
	{
		return $this;
	}
	public function Achat($qte)
	{
		$this->sens = 1;
		$this->qte = $qte;
		return $this;
	}
	public function Vendre($qte)
	{
		$this->sens = -1;
		$this->qte = $qte;
		return $this;
	}
	public function ASeuil($seuil)
	{
		$this->seuil = $seuil;
		return $this;
	}
	public function APlage($seuil, $lim)
	{
		$this->seuil = $seuil;
		$this->lim = $lim;
		return $this;
	}
	public function ACoursLimite($lim)
	{
		$this->lim = $lim;
		return $this;
	}
	public function AuDernierCours($lim)
	{
		return $this->AcoursLimite($lim);
	}
	public function Exec()
	{
		$this->ref = SimulatorAccount::getInstance()->addOrder($this->data);
		return $this;
	}
	public function Delete()
	{
		SimulatorAccount::getInstance()->removeOrder($this->ref);
		return $this;
	}
	public function __destruct()
	{
		unset($this->data);
	}
}

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
			$this->RIB($this->AccountsDetails->rib);
		
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
	
	public function isISIN(&$i)
	{
		if(preg_match(self::ISIN_REGEX, $i))
			return true;
		// Optionnal
		if(!class_exists('StockInd'))
			if(is_readable('class.StockInd.php'))
				require_once('class.StockInd.php');
			else
				return false; // couldn't search for indice in DB
		// Try to correct ISIN by reference, searching into DB by Stock label, or Mnemo.
		if(($re = StockInd::getInstance()->search($i)) !== false)
		{
			$i = $re;
			return true;
		}
		return false;
	}
	
	/*
	* Authentification initiale. 
	*  Si l'authentification est successful, crée le stream_context_set_default() avec la session correspondante.
	*/
	const URL_AUTH = '/IDE.html';
	const IDSESS = 'IdSes';
	const SAVE_SESSIONS = true; // Used for DEBUG mode, unsafe for production mode !!
	const SESSION_PATH = 'CM_sess/';
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
		$session = self::SESSION_PATH . md5($user.':'.$pass) . '.sess.gz';
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
			
			$headers = substr($response, 0, $header_size);
			$body = substr($response, $header_size);
			if(self::SAVE_SESSIONS)
			{
				if(!is_dir(self::SESSION_PATH))
					mkdir(self::SESSION_PATH);
				file_put_contents($session, gzdeflate(serialize(compact('headers', 'body'))));
			}
		}
		// parse headers and get Cookie IdSes
		$this->parseResponseHeaders($headers);
		
		//return account details
		return $this->AccountDetails = new SimpleXMLElement($body);
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
	public function AnnulerOrdre($ref, $isin = null, $orderbook = 'BFR')
	{
		return $this->DeleteOrdre($ref,$isin,$orderboook);
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
	public function __construct(Broker $C, $isin, $nom = '')
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
			'derniercours' => 'LastQuote',
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

	public function __save()
	{
		return $this->V->asXML();
	}
	
	public function __sleep()
	{
		$this->C = null;
		$this->V = $this->V->asXML();
		return array('C', 'V');
	}
	public function __wakeup()
	{
		$this->V = new SimpleXMLElement($this->V);
		$this->C = parent::getCurrentInstance();
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

class OrdreEnCours extends CreditMutuel implements _OrdreEnCours
{
	private $O = null;
	private $C = null;
	private $Action = null;
	public function __construct(Broker $C, SimpleXMLElement $t)
	{
		$this->C = $C;
		$this->O = $t;
		$this->Action = new Action($C, $t->attributes()['IsinCode'], (string)$t);
		return $this;
	}
	
	public static function NewOrder(Broker $C, $ref, $isin=null, $nom = null)
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

	public function __sleep()
	{
		$this->C = null;
// 		$isin = $this->Action->isin;
		$this->Action = $this->isin;
		$this->O = $this->O->asXML();
		return array('O', 'C', 'Action');
	}
	public function __wakeup()
	{
// 		print_r($this);
		$this->O = new SimpleXMLElement($this->O);
		$this->C = parent::getCurrentInstance();
		$this->Action = new Action($this->C, $this->Action);
	}
}

class Ordre extends CreditMutuel
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

		return OrdreEnCours::NewOrder(
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

class EncryptedCredentials extends CreditMutuel {

    private $skey = "CreditMutuelCredentialsEncryption"; // you can change it
	const CREDENTIALS_DIR = 'CM_sess/';
	private $file = '';
	
    public function __construct($file = null)
    {
		if(!function_exists('mcrypt_create_iv'))
			throw new Exception('mcrypt extension must be enabled in php');
		if(substr($file, -3) != 'ids')
			$file .= '.ids';
		if(!is_null($file) && is_readable(self::CREDENTIALS_DIR.$file))
			$this->constructKey(filemtime(self::CREDENTIALS_DIR.$file));
// 		else
// 			throw new Exception('Unknown encrypted credentials...');
		$this->file = self::CREDENTIALS_DIR.$file;
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
		if(!is_dir(self::CREDENTIALS_DIR))
			if(!mkdir(self::CREDENTIALS_DIR))
				throw new Exception('Cannot create Credentials directory.');
		$string = $user.':'.$pass;
		$file = uniqid();
		$cred = new self(__FILE__);
		if(file_put_contents(self::CREDENTIALS_DIR.$file.'.ids', $cred->constructKey(time())->encode($string), LOCK_EX))
			print "ENCRYPTED CREDENTIALS CREATED. PLEASE NOW CALL CreditMutuel() with \n  new EncryptedCredentials('$file')   as argument.";
		else
			print "An error occured.";
		@chmod(self::CREDENTIALS_DIR.$file, 0400);
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

