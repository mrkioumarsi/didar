<?php
function mon_machine(){
 $o=['cpu'=>null,'mem'=>null,'bw'=>null,'load'=>null,'sys'=>false];
 $o['mem']=mon_mem();
 $o['cpu']=mon_cpu();
 $o['bw']=mon_bw();
 if(function_exists('sys_getloadavg')){
  $l=@sys_getloadavg();
  if(is_array($l))$o['load']=round($l[0],2);
 }
 $o['sys']=(bool)($o['mem']||$o['cpu']!==null||($o['bw']&&empty($o['bw']['wait'])));
 return $o;
}
function mon_wss_url(){
 $u=trim((string)setting('lk_url'));
 if(!preg_match('#^wss://#i',$u))return '';
 $p=parse_url($u);
 $h=strtolower((string)($p['host']??''));
 if(!preg_match('/^[a-z0-9.-]+$/',$h)||$h==='localhost'||$h==='127.0.0.1')return '';
 $self=strtolower(preg_replace('/:\d+$/','',(string)($_SERVER['HTTP_HOST']??'')));
 if($h===$self)return '';
 $port=isset($p['port'])?(int)$p['port']:443;
 if($port<1||$port>65535||$port===7880||$port===7881)$port=443;
 return 'https://'.$h.($port===443?'':':'.$port).'/api.php?action=node';
}
function mon_http($url,$header){
 if(function_exists('curl_init')){
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_HTTPHEADER=>[$header],CURLOPT_FOLLOWLOCATION=>false]);
  $body=curl_exec($ch);
  $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  return $code===200&&is_string($body)?$body:null;
 }
 $ctx=stream_context_create(['http'=>['timeout'=>3,'header'=>$header."\r\n",'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
 $body=@file_get_contents($url,false,$ctx);
 return is_string($body)&&$body!==''?$body:null;
}
function mon_pull(){
 $url=mon_wss_url();
 if($url==='')return null;
 $sec=trim((string)setting('lk_secret'));
 if($sec==='')return null;
 $body=mon_http($url,'X-Didar-Node: '.hash_hmac('sha256','node',$sec));
 $j=json_decode((string)$body,true);
 return is_array($j)?$j:null;
}
function mon_snapshot(){
 $o=['online'=>0,'rooms'=>0,'cpu'=>null,'mem'=>null,'bw'=>null,'load'=>null,'sys'=>false,'src'=>''];
 try{
  $o['online']=(int)q("SELECT COUNT(*) FROM participants WHERE left_at IS NULL AND last_seen>NOW()-INTERVAL 20 SECOND")->fetchColumn();
  $o['rooms']=(int)q("SELECT COUNT(DISTINCT room_id) FROM participants WHERE left_at IS NULL AND last_seen>NOW()-INTERVAL 20 SECOND")->fetchColumn();
 }catch(Throwable $e){}
 if(mon_wss_url()!==''){
  $m=mon_pull()?:['cpu'=>null,'mem'=>null,'bw'=>null,'load'=>null,'sys'=>false];
  $o['src']='wss';
 }else $m=mon_machine();
 foreach(['cpu','mem','bw','load','sys'] as $k)if(array_key_exists($k,$m))$o[$k]=$m[$k];
 return $o;
}
function mon_node_ok(){
 $sec=trim((string)setting('lk_secret'));
 $got=(string)($_SERVER['HTTP_X_DIDAR_NODE']??'');
 return $sec!==''&&hash_equals(hash_hmac('sha256','node',$sec),$got);
}
function mon_mem(){
 $t=@file_get_contents('/proc/meminfo');
 if(!$t)return null;
 $g=function($k) use($t){return preg_match('/^'.$k.':\s+(\d+)/m',$t,$m)?(int)$m[1]*1024:0;};
 $total=$g('MemTotal');
 if($total<=0)return null;
 $avail=$g('MemAvailable');
 if($avail<=0)$avail=$g('MemFree')+$g('Buffers')+$g('Cached');
 $used=max(0,$total-$avail);
 return ['total'=>$total,'used'=>$used,'free'=>$avail];
}
function mon_cpu(){
 $line=@file('/proc/stat');
 if(!$line)return null;
 $p=preg_split('/\s+/',trim($line[0]));
 if(($p[0]??'')!=='cpu')return null;
 $n=array_map('intval',array_slice($p,1));
 $idle=($n[3]??0)+($n[4]??0);
 $total=array_sum($n);
 $f=__DIR__.'/../storage/mon-cpu.json';
 $prev=json_decode((string)@file_get_contents($f),true);
 @file_put_contents($f,json_encode(['total'=>$total,'idle'=>$idle]),LOCK_EX);
 if(!$prev)return null;
 $dt=$total-(int)$prev['total'];
 $di=$idle-(int)$prev['idle'];
 if($dt<=0)return null;
 return round((1-$di/$dt)*100,1);
}
function mon_net(){
 $lines=@file('/proc/net/dev');
 if(!$lines)return null;
 $rx=0;$tx=0;
 foreach($lines as $ln){
  if(!str_contains($ln,':'))continue;
  [$name,$rest]=explode(':',$ln,2);
  $name=trim($name);
  if($name===''||$name==='lo')continue;
  $c=preg_split('/\s+/',trim($rest));
  if(count($c)<10)continue;
  $rx+=(int)$c[0];
  $tx+=(int)$c[8];
 }
 return ['rx'=>$rx,'tx'=>$tx,'t'=>microtime(true)];
}
function mon_bw(){
 $now=mon_net();
 if(!$now)return null;
 $f=__DIR__.'/../storage/mon-net.json';
 $prev=json_decode((string)@file_get_contents($f),true);
 @file_put_contents($f,json_encode($now),LOCK_EX);
 if(!$prev||empty($prev['t'])||$now['t']<=$prev['t'])return ['wait'=>1];
 $dt=$now['t']-$prev['t'];
 if($dt<0.3||$dt>180)return ['wait'=>1];
 $rx=($now['rx']-(int)$prev['rx'])/$dt;
 $tx=($now['tx']-(int)$prev['tx'])/$dt;
 if($rx<0||$tx<0)return ['wait'=>1];
 return ['rx'=>$rx,'tx'=>$tx,'wait'=>0];
}
