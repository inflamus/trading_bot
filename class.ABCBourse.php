<?php

abstract class ABCBourse
{
	CONST ABCUSER_COOKIE = "ABCUser=73D8658BBF3166B610537BD10F93956F9251561EF949B989895FE1C9BCC601A8BD30E129E27AB6A84C3E9EB550799B1FE917449813B258D88B49CDD32EBEF2A028FD4876A0DFFD29A510FE757F6B2F2A5A01960F903C23645D74C3F179573199E7D26713B9BBE0B0072AD296BDAAB40809C7BEB47F2997C9B32AA0E7;";
	CONST VALO_URL = "https://www.abcbourse.com/game/displayp.aspx";
	CONST HOST = "www.abcbourse.com";
	CONST ORIGIN = "https://www.abcbourse.com";
	CONST GZIP = true;

	protected function post($url, $data)
	{
// 		print_r($this->data);
		$data_string = json_encode($data);
// 		print($data_string);                                                                                       
		$ch = curl_init($url);                                                                      
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);     
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
			'Content-Type: application/json; charset=UTF-8',                                                                                
			'Content-Length: ' . strlen($data_string),
			'Cookie: '.self::ABCUSER_COOKIE,
// 			'Referer: '.self::VALO_URL,
			'Host: '.self::HOST,
			'Origin: '.self::ORIGIN,
			'User-agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/49.0.2623.87 Safari/537.36',
			'Accept-Encoding: '.(self::GZIP ? 'gzip, deflate' : 'raw'),
			)                                                                       
		);                                                                                                                   
		list($head, $body) = explode("\r\n\r\n", curl_exec($ch));
		if(preg_match("/content-encoding: ?(gzip)|(deflate)/si", $head))
			$body = gzdecode($body);
// 		print $re;
// 		print_r($re);
// 		print_r(json_decode($re[1]));
		return json_decode($body);
// 		return $this;
	}
}
