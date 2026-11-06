<?php
function j_g2j($gy,$gm,$gd){
 $t=[0,31,59,90,120,151,181,212,243,273,304,334];
 if($gy>1600){$jy=979;$gy-=1600;}else{$jy=0;$gy-=621;}
 $g2=$gm>2?$gy+1:$gy;
 $d=365*$gy+intdiv($g2+3,4)-intdiv($g2+99,100)+intdiv($g2+399,400)-80+$gd+$t[$gm-1];
 $jy+=33*intdiv($d,12053);$d%=12053;
 $jy+=4*intdiv($d,1461);$d%=1461;
 if($d>365){$jy+=intdiv($d-1,365);$d=($d-1)%365;}
 $jm=$d<186?1+intdiv($d,31):7+intdiv($d-186,30);
 $jd=1+($d<186?$d%31:($d-186)%30);
 return [$jy,$jm,$jd];
}
function j_j2g($jy,$jm,$jd){
 if($jy>979){$gy=1600;$jy-=979;}else $gy=621;
 $d=365*$jy+intdiv($jy,33)*8+intdiv(($jy%33)+3,4)+78+$jd+($jm<7?($jm-1)*31:($jm-7)*30+186);
 $gy+=400*intdiv($d,146097);$d%=146097;
 if($d>36524){$d--;$gy+=100*intdiv($d,36524);$d%=36524;if($d>=365)$d++;}
 $gy+=4*intdiv($d,1461);$d%=1461;
 if($d>365){$gy+=intdiv($d-1,365);$d=($d-1)%365;}
 $gd=$d+1;
 $sa=[0,31,(($gy%4==0&&$gy%100!=0)||$gy%400==0)?29:28,31,30,31,30,31,31,30,31,30,31];
 for($gm=0;$gm<13;$gm++){$v=$sa[$gm];if($gd<=$v)break;$gd-=$v;}
 return [$gy,$gm,$gd];
}
function app_cal(){
 $c=function_exists('setting')?setting('calendar'):'';
 if($c==='gregorian'||$c==='jalali')return $c;
 $lang=function_exists('html_lang')?html_lang():'fa';
 return $lang==='en'?'gregorian':'jalali';
}
function app_tz_name(){
 $z=function_exists('setting')?setting('timezone'):'';
 if(!$z||!in_array($z,timezone_identifiers_list(),true))$z='Asia/Tehran';
 return $z;
}
function dfmt($dt,$withTime=false){
 if(!$dt)return '';
 $d=substr($dt,0,10);
 $tm=substr($dt,11,5);
 if(app_cal()==='jalali'){
  $p=array_map('intval',explode('-',$d));
  if(count($p)<3)return '';
  $j=j_g2j($p[0],$p[1],$p[2]);
  $s=sprintf('%04d/%02d/%02d',$j[0],$j[1],$j[2]);
 }else $s=str_replace('-','/',$d);
 if($withTime&&$tm!=='')$s.=' '.$tm;
 return function_exists('locn')?locn($s):$s;
}
