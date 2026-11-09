<?php
declare(strict_types=1);
require __DIR__.'/../includes/security.php';require __DIR__.'/../includes/mfa.php';
$config=['app_key'=>str_repeat('unit-test-key-',4)];$checks=0;
function verify(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException($name);$checks++;echo "PASS $name\n";}
verify(mfaFeatureEnabled(),'MFA enabled when switch and environment are missing');
$config['app_env']='development';
verify(mfaFeatureEnabled(),'existing development config keeps MFA enabled');
$config['mfa_enabled']=false;
verify(!mfaFeatureEnabled(),'explicit false pauses MFA in development');
verify(secondFactorField()==='','paused MFA hides the second-factor field');
foreach(['production','staging','',null]as $environment){
    $config['app_env']=$environment;
    verify(mfaFeatureEnabled(),'MFA cannot be paused outside development: '.var_export($environment,true));
}
$config['app_env']='development';
foreach([true,'false','off',0,'0',null,[]]as $value){
    $config['mfa_enabled']=$value;
    verify(mfaFeatureEnabled(),'only boolean false pauses MFA: '.var_export($value,true));
}
$config['mfa_enabled']=true;
verify(str_contains(secondFactorField(),'name="code"'),'restored MFA shows the second-factor field');
$secret=base32Encode('12345678901234567890');
verify($secret==='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ','standard Base32 encoding');
verify(base32Decode($secret)==='12345678901234567890','standard Base32 decoding');
// Independent published RFC 6238 Appendix B SHA-1 vectors, not implementation-generated expectations.
foreach([59=>'94287082',1111111109=>'07081804',1111111111=>'14050471',1234567890=>'89005924',2000000000=>'69279037',20000000000=>'65353130']as $time=>$expected)
    verify(totpCode($secret,intdiv($time,30),8)===$expected,'RFC 6238 at '.$time);
verify(totpCounter($secret,'287082',-1,59)===1,'six-digit code');
verify(totpCounter($secret,'287082',1,59)===null,'replay rejected');
verify(totpCounter($secret,'287082',-1,120)===null,'expired code rejected');
verify(totpCounter($secret,'287082x',-1,59)===null,'malformed code rejected');
$cipher=encryptMfaSecret($secret);verify(decryptMfaSecret($cipher)===$secret,'authenticated encryption round trip');verify(!str_contains($cipher,$secret),'no plaintext secret in ciphertext');
$bytes=base64_decode($cipher);$bytes[30]=chr(ord($bytes[30])^1);$rejected=false;try{decryptMfaSecret(base64_encode($bytes));}catch(RuntimeException $e){$rejected=true;}verify($rejected,'ciphertext tampering rejected');
$codes=newRecoveryCodes();verify(count(array_unique($codes))===10,'ten independent recovery codes');
foreach($codes as $code)verify((bool)preg_match('/^[a-f0-9]{16}$/D',$code),'64-bit recovery code');
echo "$checks MFA assertions passed.\n";
