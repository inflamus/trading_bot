#!/usr/bin/php
<?php

require('class.TradingBot.php');
require('class.StockInd.php');
require('class.Stock.php');
require('class.CM.php');

$days = $argc>1 ? $argv[1] : 1040;  // Defaults 1040 jours = 4ans
$seuil = $argc>2 ? $argv[2] : '6%';
if($argc<3) // all
	TradingBot::BuildIndicators($days, $seuil);
else
{
// 	include(TradingBot::EXTERNAL_INDICATORS);
	for($a=3; $a<$argc; $a++)
		print_r(TradingBot::BestIndicator($argv[$a], $days, $seuil));
}