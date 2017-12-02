<?php

define('DIRNAME', dirname(__FILE__));
/*
 Usage : StockInd::getInstance()->search('Air Liquide');
 
 or new Stock('Air Liquide');
		-> ISIN(); // return ISIN
		-> Mnemo(); // return Mnemo
		-> Label; // return Label
*/

interface iStock
{
	public function __construct(string $input); // take as input string mnemo, label, or isin directly;
	// or throw an exception if $input is not foud.
	public function ISIN() :string; // return ISIN;
	public function Mnemo() :string; // return mnemo
}

class Stock implements iStock
{
	public $ISIN, $Mnemo, $Label;
	public function __construct($input)
	{
		$this->ISIN = $input;
		StockInd::isISIN($this->ISIN);
		if($this->ISIN == false)
			throw new Exception('Unknown '.$input.' stock.');
		$this->Mnemo = StockInd::getInstance()->searchMnemo($this->ISIN);
		$this->Label = StockInd::getInstance()->searchLabel($this->ISIN);
	}
	
	public function ISIN() : string
	{
		return $this->ISIN;
	}
	
	public function Mnemo() : string
	{
		return $this->Mnemo;
	}
	
	public function Yahoo()
	{
// 		$places = array(
// 			'FR' => '.PA',
// 			'BE' => '.BR',
// 			'NL' => '.AS',
// 			'GB' => '',
// 			
// 		);
// 		return $this->Mnemo. $places[substr($this->ISIN, 0, 2)];
		switch($this->Mnemo)
		{
			case 'SOLB':
				return 'SOLB.BR'; break;
			case 'UL':
				return 'UL.AS'; break;
			case 'APAM':
				return 'APAM.AS'; break;
			case 'PX1':
				return '^FCHI'; break;
			default:
				return $this->Mnemo .'.PA';
		}		
	}
}

class StockInd
{
	use UniqueInstance;  //trait for unique instance of a class

	const STOCKS_FILE = DIRNAME.DIRECTORY_SEPARATOR.'EUROLIST.ind'; /* Thanks to ABC Bourse.com */
	const UNIFORM_REGEX = '/[^a-z0-9]/';
	const UPDATE_INTERVAL = 3600*24*7; // every weeks
	public $Lib = array(), $Mnem = array();
	
	public function __construct()
	{
		if(!is_readable(self::STOCKS_FILE) || filesize(self::STOCKS_FILE) < 10)
			$this->_buildDB();
		foreach(file(self::STOCKS_FILE) as $v)
		{
			$l = explode(';', $v);
			if($l[0] == 'ISIN') continue;
			$this->Lib[$l[0]] = $this->uniform($l[1]);
			$this->Mnem[$l[0]] = strtoupper(trim($l[2]));
		}
		// CAC40 et autres indices
		$this->Lib['FR0003500008'] = 'cac40';
		$this->Mnem['FR0003500008'] = 'PX1';
// 		$this->Lib['DE0008469008'] = 'dax30';
// 		$this->Mnem['DE0008469008'] = 'DAX';
	}
	
	private function uniform($s)
	{
		return preg_replace(self::UNIFORM_REGEX, '_', strtolower($s));
	}
	
	public function search($s)
	{
		if(($re = array_search($this->uniform($s), $this->Lib))!== false)
			return strtoupper($re);
		if(($re = array_search(strtoupper($s), $this->Mnem)) !== false)
			return strtoupper($re);
		return false;
	}
	
	public function searchMnemo($isin)
	{
		return $this->Mnem[$isin];
	}
	
	public function searchLabel($isin)
	{
		return $this->Lib[$isin];
	}
	
	//from https://github.com/pear/Validate_Finance/tree/master/Validate/Finance/ISIN.php
	public static function ISIN($isin)
    {
        // Formal check.
        if (!preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/i', $isin)) {
            return false;
        }
        // Convert letters to numbers.
        $base10 = '';
        for ($i = 0; $i <= 11; $i++) {
            $base10 .= base_convert($isin{$i}, 36, 10);
        }
        // Calculate double-add-double checksum.
        $checksum = 0;
        $len      = strlen($base10) - 1;
        $parity   = $len % 2;
        // Iterate over every digit, starting with the rightmost (=check digit).
        for ($i = $len; $i >= 0; $i--) {
            // Multiply every other digit by two.
            $weighted = $base10{$i} << (($i - $parity) & 1);
            // Sum up the weighted digit's digit sum.
            $checksum += $weighted % 10 + (int)($weighted / 10);
        }
        return !(bool)($checksum % 10);
    } // end func Validate_Finance_ISIN
    
    public static function isISIN(&$i)
    {
		if(self::ISIN($i))
			return true;
		// Optionnal
		// Try to correct ISIN by reference, searching into DB by Stock label, or Mnemo.
		if(($re = self::getInstance()->search($i)) !== false)
		{
			$i = $re;
			return true;
		}
		return false;
    }
	
	private function _buildDB()
	{
		$host = "https://www.abcbourse.com/download/libelles.aspx";
		$viewstate = "/wEPDwUKMTM4NDUwMzkwNA9kFgJmD2QWAgIED2QWBgIFD2QWAgJTD2QWAmYPFgIeB1Zpc2libGVnZAIJD2QWAmYPFgIfAGdkAgsPZBYCAgEPDxYCHgRUZXh0BSlCYXNjdWxlciBzdXIgbGEgdmVyc2lvbiBjbGFzc2lxdWUgZHUgc2l0ZWRkGAEFHl9fQ29udHJvbHNSZXF1aXJlUG9zdEJhY2tLZXlfXxYoBRVjdGwwMCRCb2R5QUJDJHhjYWM0MHAFFmN0bDAwJEJvZHlBQkMkeHNiZjEyMHAFFWN0bDAwJEJvZHlBQkMkeGNhY2F0cAUWY3RsMDAkQm9keUFCQyR4Y2FjbjIwcAUYY3RsMDAkQm9keUFCQyR4Y2Fjc21hbGxwBRVjdGwwMCRCb2R5QUJDJHhjYWM2MHAFFmN0bDAwJEJvZHlBQkMkeGNhY2w2MHAFFWN0bDAwJEJvZHlBQkMkeGNhY21zcAUVY3RsMDAkQm9keUFCQyR4YmVsMjBnBRVjdGwwMCRCb2R5QUJDJHhhZXgyNW4FEWN0bDAwJEJvZHlBQkMkZGp1BRJjdGwwMCRCb2R5QUJDJG5hc3UFFGN0bDAwJEJvZHlBQkMkc3A1MDB1BRZjdGwwMCRCb2R5QUJDJGdlcm1hbnlmBRFjdGwwMCRCb2R5QUJDJHVrZQUSY3RsMDAkQm9keUFCQyRiZWxnBRJjdGwwMCRCb2R5QUJDJGRldnAFFGN0bDAwJEJvZHlBQkMkc3BhaW5tBRVjdGwwMCRCb2R5QUJDJGl0YWxpYWkFE2N0bDAwJEJvZHlBQkMkaG9sbG4FFWN0bDAwJEJvZHlBQkMkbGlzYm9hbAUUY3RsMDAkQm9keUFCQyRzd2l0enMFEmN0bDAwJEJvZHlBQkMkdXNhdQUYY3RsMDAkQm9keUFCQyRldXJvbGlzdEFwBRhjdGwwMCRCb2R5QUJDJGV1cm9saXN0QnAFGGN0bDAwJEJvZHlBQkMkZXVyb2xpc3RDcAUZY3RsMDAkQm9keUFCQyRldXJvbGlzdHplcAUaY3RsMDAkQm9keUFCQyRldXJvbGlzdGh6ZXAFFGN0bDAwJEJvZHlBQkMkZXVyb2FwBRRjdGwwMCRCb2R5QUJDJGV1cm9ncAUYY3RsMDAkQm9keUFCQyRpbmRpY2VzbWtwBRljdGwwMCRCb2R5QUJDJGluZGljZXNzZWNwBRNjdGwwMCRCb2R5QUJDJG9ibDJwBRJjdGwwMCRCb2R5QUJDJG9ibHAFF2N0bDAwJEJvZHlBQkMkb3Bjdm0zNjBwBRJjdGwwMCRCb2R5QUJDJHNyZHAFFGN0bDAwJEJvZHlBQkMkc3JkbG9wBRRjdGwwMCRCb2R5QUJDJHRyYWNrcAUWY3RsMDAkQm9keUFCQyR3YXJyYW50cwUVY3RsMDAkQm9keUFCQyRjYlBsYWNl095trWFT9yJFTv4Gub85XAsSjAw=";
		$eventvalidation = "/wEdACpxB1ntiTbBKNzZY0hkFmJs8hOKHkjEHKgE6Cl+PlWP6CsBz2dyy933VqldEv71pnrWB5fl7SDH6+LCeR6Cj3hBml1ipBDbFFYwrN937W/pOlYevFxpTuQO4S87Jds5qM1RyrZ1RzKjY7kpf1Uy1EsRjq0lzGo3UDCLR8Qzg+ICOaGQP60Muea7Jt2Mvrk5dP50a3x3ndE82QKf/stnRZsbrDvGsRZUo73a6kgCRfaABEjb6VehtduCyrNNbiEE/szy7cIA2+GZ1fAM4FpZyQ0JQYbnRAQISh2SLDGw6kCjm8bengUhKB5UkNIenkLIxtxVNRGPAtf9BhmQxdFVjtqGE3LKYP0CSBKO8s+AkdN+2rYiFYBGCMxIZG/SpWGZsnu/5yZPFqmm9xa2kSkQODR+EjJG69LLH4QzaePL67dWk6Cyv8bmJMXg1Cdo8hAobgnGTQM2+Tp+KxxD/R8sIIWBGD0kjqjVantioGJ6/jSUcfQLfrpgs2Etrj6F6v3VSQdAgE8rXYVdjIwY7T/ko+CqYzvsi3CKdJcOVAFBxMcbknJMAweaO6e3Zs/+P+A8U57/5p/+JANuo229ydF+QfaiGN2Hp1RDmb7ZVM1haQbFqwWhuCUe80dQYaPeR9wAkLEbNhh1C6FM+wNbNJJn6+xObUYPRCihOb3stRT0ZQqmcg4L67/Zb65bIa5c3TZRsKISuxs4fVNVenj9bBwNMtd92XoZ0fgAFx0VYRpgUubx6SaFj1P1ns1k7saP20CtOv40Vh1fIBS7G3r7SNtlzk3C0A/4Rmw2+Xo6xnkHUnscHL5GCzjGbRjxavMDrnvi92ihmau6VuJALuZvH2+XItM49krxVbbQbx+rvmYdNFMYrxrqfXKoG/eBbQYV684ncHUPVwdVJY4QSiDVjHzHrQVhkvZCvQ==";
		$base = file_get_contents($host);
		if($base !== false)
		{
			if(preg_match('/__VIEWSTATE" value="(.+)"/', $base, $_viewstate))
				$viewstate = $_viewstate[1];
			if(preg_match('/__EVENTVALIDATION" value="(.+)"/', $base, $_eventvalidation))
				$eventvalidation = $_eventvalidation[1];
		}
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,            $host );
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt($ch, CURLOPT_POST,           true );
		curl_setopt($ch, CURLOPT_POSTFIELDS,     http_build_query(
		array(
			"__VIEWSTATE" => $viewstate,
			"__VIEWSTATEGENERATOR" => '63AB8707',
			"__EVENTVALIDATION"=> $eventvalidation,
			'ctl00$BodyABC$alterp' => true, // Alternext
			'ctl00$BodyABC$eurolistAp' => true, // Eurolist A
			'ctl00$BodyABC$eurolistBp' => true, // Eurolist B
			'ctl00$BodyABC$eurolistCp' => true, // Eurolist C
			'ctl00$BodyABC$trackp' => true, // Trackers
			'ctl00$BodyABC$Button1' => 'Télécharger'
			)) ); 
		curl_setopt($ch, CURLOPT_ENCODING, "");
		curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Content-Type: application/x-www-form-urlencoded')); 
		$output = curl_exec($ch);
		if($output !== false)
			file_put_contents(self::STOCKS_FILE, $output);
		else
			throw new Exception(curl_error($ch));
		curl_close($ch);
		return true;
	}
	
	public function __destruct()
	{
		if(time() - @filemtime(self::STOCKS_FILE) < self::UPDATE_INTERVAL)
			return;
		// update file index every month
		return $this->_buildDB();
	}
}
