<?php

/*
 Usage : StockInd::getInstance()->search('Air Liquide');
 
 or new Stock('Air Liquide');
		-> ISIN(); // return ISIN
		-> Mnemo(); // return Mnemo
*/

interface iStock
{
	public function __construct(/*string*/ $input); // take as input string mnemo, label, or isin directly;
	// or throw an exception if $input is not foud.
	public function ISIN() /*:string*/; // return ISIN;
	public function Mnemo() /*:string*/; // return mnemo
}

class Stock implements iStock
{
	public $ISIN, $Mnemo, $Label;
	public function __construct(/*string*/ $input)
	{
		$this->ISIN = $input;
		StockInd::isISIN($this->ISIN);
		if($this->ISIN == false)
			throw new Exception('Unknown '.$input.' stock.');
		$this->Mnemo = StockInd::getInstance()->searchMnemo($this->ISIN);
		$this->Label = StockInd::getInstance()->searchLabel($this->ISIN);
	}
	
	public function ISIN()
	{
		return $this->ISIN;
	}
	
	public function Mnemo()
	{
		return $this->Mnemo;
	}
}

class StockInd
{
// 	use UniqueInstance;  //trait for unique instance of a class

	const STOCKS_FILE = 'EUROLIST.ind'; /* Thanks to ABC Bourse.com */
	const UNIFORM_REGEX = '/[^a-z0-9]/';
	const UPDATE_INTERVAL = 3600*24*7; // every weeks
	public $Lib = array(), $Mnem = array();
	
	public function __construct()
	{
		if(!is_readable(self::STOCKS_FILE))
			$this->_buildDB();
		foreach(file(self::STOCKS_FILE) as $v)
		{
			$l = explode(';', $v);
			if($l[0] == 'ISIN') continue;
			$this->Lib[$l[0]] = $this->uniform($l[1]);
			$this->Mnem[$l[0]] = strtoupper(trim($l[2]));
		}
	}
	
	private function uniform($s)
	{
		return preg_replace(self::UNIFORM_REGEX, '_', strtolower($s));
	}
	
	public function search($s)
	{
		if(($re = array_search($this->uniform($s), $this->Lib))!== false)
			return $re;
		if(($re = array_search(strtoupper($s), $this->Mnem)) !== false)
			return $re;
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
	
	public static function registerInstance($instance)
	{
		return $GLOBALS[__CLASS__ . 'INSTANCE'] = $instance;
	}
	public static function getInstance()
	{
		if(isset($GLOBALS[__CLASS__ . 'INSTANCE']))
			return $GLOBALS[__CLASS__ . 'INSTANCE'];
		else 
			return self::registerInstance(new self());
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
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,            "https://www.abcbourse.com/download/libelles.aspx" );
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt($ch, CURLOPT_POST,           1 );
		curl_setopt($ch, CURLOPT_POSTFIELDS,     http_build_query(
		array(
			"__VIEWSTATE" => "/wEPDwULLTEzOTkwMTQxNjkPZBYCZg9kFgICBA9kFgYCBQ9kFgICVQ9kFgICAg8WAh4HVmlzaWJsZWdkAgkPZBYCAgIPFgIfAGdkAgsPZBYCAgEPDxYCHgRUZXh0BSlCYXNjdWxlciBzdXIgbGEgdmVyc2lvbiBjbGFzc2lxdWUgZHUgc2l0ZWRkGAEFHl9fQ29udHJvbHNSZXF1aXJlUG9zdEJhY2tLZXlfXxYpBRVjdGwwMCRCb2R5QUJDJHhjYWM0MHAFFmN0bDAwJEJvZHlBQkMkeHNiZjEyMHAFFWN0bDAwJEJvZHlBQkMkeGNhY2F0cAUWY3RsMDAkQm9keUFCQyR4Y2FjbjIwcAUYY3RsMDAkQm9keUFCQyR4Y2Fjc21hbGxwBRVjdGwwMCRCb2R5QUJDJHhjYWM2MHAFFmN0bDAwJEJvZHlBQkMkeGNhY2w2MHAFFWN0bDAwJEJvZHlBQkMkeGNhY21zcAUVY3RsMDAkQm9keUFCQyR4YmVsMjBnBRVjdGwwMCRCb2R5QUJDJHhhZXgyNW4FEWN0bDAwJEJvZHlBQkMkZGp1BRJjdGwwMCRCb2R5QUJDJG5hc3UFFGN0bDAwJEJvZHlBQkMkc3A1MDB1BRZjdGwwMCRCb2R5QUJDJGdlcm1hbnlmBRFjdGwwMCRCb2R5QUJDJHVrZQUSY3RsMDAkQm9keUFCQyRiZWxnBRJjdGwwMCRCb2R5QUJDJGRldnAFFGN0bDAwJEJvZHlBQkMkc3BhaW5tBRVjdGwwMCRCb2R5QUJDJGl0YWxpYWkFE2N0bDAwJEJvZHlBQkMkaG9sbG4FFWN0bDAwJEJvZHlBQkMkbGlzYm9hbAUUY3RsMDAkQm9keUFCQyRzd2l0enMFEmN0bDAwJEJvZHlBQkMkdXNhdQUUY3RsMDAkQm9keUFCQyRhbHRlcnAFEWN0bDAwJEJvZHlBQkMkYnNwBRhjdGwwMCRCb2R5QUJDJGV1cm9saXN0QXAFGGN0bDAwJEJvZHlBQkMkZXVyb2xpc3RCcAUYY3RsMDAkQm9keUFCQyRldXJvbGlzdENwBRljdGwwMCRCb2R5QUJDJGV1cm9saXN0emVwBRpjdGwwMCRCb2R5QUJDJGV1cm9saXN0aHplcAUYY3RsMDAkQm9keUFCQyRpbmRpY2VzbWtwBRljdGwwMCRCb2R5QUJDJGluZGljZXNzZWNwBRFjdGwwMCRCb2R5QUJDJG1scAUTY3RsMDAkQm9keUFCQyRvYmwycAUSY3RsMDAkQm9keUFCQyRvYmxwBRdjdGwwMCRCb2R5QUJDJG9wY3ZtMzYwcAUSY3RsMDAkQm9keUFCQyRzcmRwBRRjdGwwMCRCb2R5QUJDJHNyZGxvcAUUY3RsMDAkQm9keUFCQyR0cmFja3AFFmN0bDAwJEJvZHlBQkMkd2FycmFudHMFFWN0bDAwJEJvZHlBQkMkY2JQbGFjZTjRS9F7NcJ2xPMXIxVYqBmk4ALe",
			"__VIEWSTATEGENERATOR" => '63AB8707',
			"__EVENTVALIDATION"=> "/wEdACtDxG4CxEY7PLl81wuaGWiK8hOKHkjEHKgE6Cl+PlWP6CsBz2dyy933VqldEv71pnrWB5fl7SDH6+LCeR6Cj3hBml1ipBDbFFYwrN937W/pOlYevFxpTuQO4S87Jds5qM1RyrZ1RzKjY7kpf1Uy1EsRjq0lzGo3UDCLR8Qzg+ICOaGQP60Muea7Jt2Mvrk5dP50a3x3ndE82QKf/stnRZsbrDvGsRZUo73a6kgCRfaABEjb6VehtduCyrNNbiEE/szy7cIA2+GZ1fAM4FpZyQ0JQYbnRAQISh2SLDGw6kCjm8bengUhKB5UkNIenkLIxtxVNRGPAtf9BhmQxdFVjtqGE3LKYP0CSBKO8s+AkdN+2rYiFYBGCMxIZG/SpWGZsnu/5yZPFqmm9xa2kSkQODR+EjJG69LLH4QzaePL67dWk6Cyv8bmJMXg1Cdo8hAobgnGTQM2+Tp+KxxD/R8sIIWBGD0kjqjVantioGJ6/jSUcfQLfrpgs2Etrj6F6v3VSQcsge0bGzF1Ktpc3PHbfeTzvpAhX1WtTIA5FTd0942PykCATytdhV2MjBjtP+Sj4KpjO+yLcIp0lw5UAUHExxuSckwDB5o7p7dmz/4/4DxTnv/mn/4kA26jbb3J0X5B9qIY3YenVEOZvtlUzWFpBsWrbUYPRCihOb3stRT0ZQqmcg4L67/Zb65bIa5c3TZRsKLQBi3yzHLNr8sNaxE4jXoUErsbOH1TVXp4/WwcDTLXfdl6GdH4ABcdFWEaYFLm8ekmhY9T9Z7NZO7Gj9tArTr+NFYdXyAUuxt6+0jbZc5NwtAP+EZsNvl6OsZ5B1J7HBy+Rgs4xm0Y8WrzA6574vdooZmrulbiQC7mbx9vlyLTOPZK8VW20G8fq75mHTRTGK8a6n1yqBv3gW0GFevOJ3B1ddxM4/l1yE0ScTMJOOTe4RdQYzg=",
			'ctl00$BodyABC$alterp' => true, // Alternext
			'ctl00$BodyABC$eurolistAp' => true, // Eurolist A
			'ctl00$BodyABC$eurolistBp' => true, // Eurolist B
			'ctl00$BodyABC$eurolistCp' => true, // Eurolist C
			'ctl00$BodyABC$trackp' => true, // Trackers
			'ctl00$BodyABC$Button1' => 'Télécharger'
			)) ); 
		curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Content-Type: application/x-www-form-urlencoded')); 
		file_put_contents(self::STOCKS_FILE, curl_exec($ch));
		return true;
	}
	
	public function __destruct()
	{
		if(time() - filemtime(self::STOCKS_FILE) < self::UPDATE_INTERVAL)
			return;
		// update file index every month
		return $this->_buildDB();
	}
}
