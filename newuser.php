#!$(which php)
<?php

require('class.CM.php');

print "Nouvel utilisateur Credit Mutuel encrypté.\n";
// $line = trim(fgets(STDIN)); // lit une ligne depuis STDIN
print "Entrez votre nom d'utilisateur... \n";
print "(d'habitude le numéro de votre compte bancaire principal)\n";
$user = trim(fgets(STDIN)); // lit une ligne depuis STDIN
print "Merci.\n";
print "Maintenant, votre mot de passe...\n";
$pass = trim(fgets(STDIN)); // lit une ligne depuis STDIN
print "Encryptage...";
try{
	EncryptedCredentials::create($user, $pass);
}
catch(Exception $e)
{
	fwrite(STDERR, $e->getMessage());
}
exit();
