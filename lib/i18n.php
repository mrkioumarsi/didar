<?php
function lang_load($id){
 static $cache=[];
 $id=preg_replace('/[^a-z]/','',(string)$id);
 if(isset($cache[$id]))return $cache[$id];
 $f=__DIR__.'/../lang/'.$id.'.php';
 $p=is_file($f)?include $f:[];
 return $cache[$id]=is_array($p)?$p:[];
}
function lang_list(){
 static $c;
 if($c)return $c;
 $c=[];
 foreach(glob(__DIR__.'/../lang/*.php')?:[] as $f){
  $id=basename($f,'.php');
  if(!preg_match('/^[a-z]{2}$/',$id))continue;
  $p=lang_load($id);
  $c[$id]=$p['_meta']??['name'=>$id,'dir'=>'ltr','digits'=>''];
 }
 if(!$c)$c['fa']=['name'=>'فارسی','dir'=>'rtl','digits'=>'fa'];
 return $c;
}
function lang_code($s){
 $s=strtolower(preg_replace('/[^a-z]/','',(string)$s));
 return isset(lang_list()[$s])?$s:'';
}
function lang_strings($id){
 $p=lang_load($id);
 unset($p['_meta']);
 return $p;
}
function lang_cookie($code){
 global $https;
 $exp=time()+365*86400;
 if(PHP_VERSION_ID>=70300)setcookie('dlang',$code,['expires'=>$exp,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
 else setcookie('dlang',$code,$exp,'/','',$https,true);
 $_COOKIE['dlang']=$code;
}
function i18n_boot(){
 global $T,$TF,$LANG,$LANG_META;
 $TF=lang_strings('fa');
 if(!$TF)$TF=lang_strings('en');
 $code='';
 try{
  $u=function_exists('user')?user():null;
  if($u&&lang_code($u['lang']??''))$code=$u['lang'];
 }catch(Throwable $e){$u=null;}
 if(!$code&&lang_code($_COOKIE['dlang']??''))$code=$_COOKIE['dlang'];
 if(!$code){
  $d='';
  try{$d=function_exists('setting')?setting('default_lang'):'';}catch(Throwable $e){}
  $code=lang_code($d)?:(isset(lang_list()['fa'])?'fa':array_key_first(lang_list()));
 }
 $LANG=$code?:'fa';
 $meta=lang_list()[$LANG]??['name'=>$LANG,'dir'=>'rtl','digits'=>'fa'];
 $LANG_META=$meta;
 $T=lang_strings($LANG);
 if(!$T)$T=$TF;
}
function t($k,$vars=[]){
 global $T,$TF;
 $s=$T[$k]??($TF[$k]??$k);
 foreach($vars as $a=>$b)$s=str_replace('{'.$a.'}',(string)$b,$s);
 return $s;
}
function html_lang(){global $LANG;return $LANG?:'fa';}
function html_dir(){global $LANG_META;return (($LANG_META['dir']??'rtl')==='ltr')?'ltr':'rtl';}
function locn($s){
 global $LANG_META;
 $s=(string)$s;
 if(($LANG_META['digits']??'')!=='fa')return $s;
 return strtr($s,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
}
function i18n_boot_js(){
 global $T,$LANG_META,$LANG;
 $cal=app_cal();
 $tz=app_tz_name();
 $flags=JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE;
 $m=['lang'=>$LANG,'dir'=>html_dir(),'digits'=>$LANG_META['digits']??'','cal'=>$cal,'tz'=>$tz];
 return '<script>window.T='.json_encode($T,$flags).';window.M='.json_encode($m,$flags).';window.CSRF='.json_encode($_SESSION['csrf']??'',$flags).';</script>';
}
function tz_choices(){
 $list=['Asia/Tehran','Asia/Dubai','Asia/Kabul','Asia/Karachi','Asia/Baghdad','Asia/Riyadh','Asia/Qatar','Asia/Kuwait','Asia/Muscat','Asia/Baku','Asia/Yerevan','Asia/Tbilisi','Asia/Istanbul','Asia/Jerusalem','Asia/Amman','Asia/Beirut','Europe/London','Europe/Berlin','Europe/Paris','Europe/Moscow','UTC','America/New_York','America/Chicago','America/Los_Angeles','Asia/Shanghai','Asia/Tokyo','Asia/Kolkata','Australia/Sydney'];
 $cur=app_tz_name();
 if(!in_array($cur,$list,true))array_unshift($list,$cur);
 return $list;
}
