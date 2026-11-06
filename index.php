<?php
require 'config.php';
if(!file_exists(__DIR__.'/storage/installed.lock')){
 if(is_file(__DIR__.'/install/install.php')){header('Location: install/install.php');exit;}
 http_response_code(503);
 header('Content-Type: text/plain; charset=utf-8');
 echo t('install.missing');
 exit;
}
$b=h(setting('brand')?:'دیدار');
$langs=lang_list();
$cur=html_lang();
$langOpt='';
foreach($langs as $id=>$m)$langOpt.='<option value="'.h($id).'"'.($id===$cur?' selected':'').'>'.h($m['name']??$id).'</option>';
$tzOpt='';
foreach(tz_choices() as $z)$tzOpt.='<option value="'.h($z).'"'.($z===app_tz_name()?' selected':'').'>'.h($z).'</option>';
$cal=app_cal();
?>
<!doctype html><html lang="<?=h(html_lang())?>" dir="<?=h(html_dir())?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"><title><?=$b?></title><link rel="manifest" href="manifest.php"><link rel="apple-touch-icon" href="assets/icon-192.png"><meta name="theme-color" content="#4f46e5"><link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?=asset('assets/app.css')?>"></head><body>
<div id="login" class="loginwrap" hidden><div class="card login"><img src="assets/icon-192.png" alt=""><h2><?=$b?></h2><p class="mu"><?=t('login.hint')?></p>
<label><?=t('login.lang')?></label><select id="llang"><?=$langOpt?></select>
<label><?=t('login.user')?></label><input id="lu" autocomplete="username"><label><?=t('login.pass')?></label><input id="lp" type="password" autocomplete="current-password"><button class="btn block" id="lb"><?=t('login.submit')?></button></div></div>
<div id="app" hidden><header class="top"><div class="brand"><img src="assets/icon-192.png" alt=""><?=$b?></div><div class="usr"><button type="button" class="chip" id="un" title="<?=h(t('profile.title'))?>" style="border:0;font:inherit"></button><button class="btn ghost sm" id="lo" data-i="leave" data-s="16" title="<?=h(t('nav.leave'))?>"></button></div></header>
<div class="wrap"><nav class="seg" id="mainnav"><button data-t="rooms" class="act"><i data-i="video" data-s="16"></i><?=t('nav.rooms')?></button><button data-t="users" class="adm"><i data-i="users" data-s="16"></i><?=t('nav.users')?></button><button data-t="set" class="adm"><i data-i="sliders" data-s="16"></i><?=t('nav.settings')?></button></nav>
<section id="rooms" class="pane0"><div class="phd"><div><h2><?=t('rooms.title')?></h2><p class="mu" id="rsub"></p></div><div class="tools2"><div class="srch2"><span data-i="search" data-s="18"></span><input id="rsq" placeholder="<?=h(t('rooms.search'))?>"></div></div></div><div id="rl" class="cards"></div></section>
<section id="users" class="pane0" hidden><div class="phd"><div><h2><?=t('users.title')?></h2><p class="mu" id="usub"></p></div><div class="tools2"><div class="fchips" id="ufl"><button class="act" data-f="all"><?=t('users.all')?></button><button data-f="admin"><?=t('users.admin')?></button><button data-f="user"><?=t('users.user')?></button></div><div class="srch2"><span data-i="search" data-s="18"></span><input id="usq" placeholder="<?=h(t('users.search'))?>"></div></div></div><p class="mu" style="margin:-8px 0 16px"><?=t('users.hint')?></p><div id="ul" class="cards"></div></section>
<section id="set" class="pane0" hidden>
<div class="phd"><div><h2><?=t('set.title')?></h2><p class="mu"><?=t('set.sub')?></p></div></div>
<div class="sgrid">
<div class="card scard wide"><div class="sh2"><span class="rc-ic" data-i="act" data-s="22"></span><div><h3><?=t('mon.title')?></h3><p class="mu"><?=t('mon.sub')?></p></div></div>
<div id="monbox" class="stats"></div><p id="monnote" class="hint"></p></div>
<div class="card scard"><div class="sh2"><span class="rc-ic" data-i="gear" data-s="22"></span><div><h3><?=t('set.general')?></h3><p class="mu"><?=t('set.general.sub')?></p></div></div>
<label><?=t('set.brand')?></label><input id="sb"><p class="hint"><?=t('set.brand.hint')?></p>
<label><?=t('set.domain')?></label><input id="sd" dir="ltr" placeholder="<?=h(t('set.domain.ph'))?>"><p class="hint"><?=t('set.domain.hint')?></p>
<label><?=t('set.icon')?></label><input type="file" id="si" accept="image/*"><p class="hint"><?=t('set.icon.hint')?></p><button class="btn ghost sm" id="sic" style="margin-top:8px"><?=t('set.icon.up')?></button></div>
<div class="card scard"><div class="sh2"><span class="rc-ic" data-i="grid" data-s="22"></span><div><h3><?=t('set.region')?></h3><p class="mu"><?=t('set.region.sub')?></p></div></div>
<label><?=t('set.tz')?></label><select id="stz"><?=$tzOpt?></select><p class="hint"><?=t('set.tz.hint')?></p>
<label><?=t('set.cal')?></label><select id="scal"><option value="jalali"<?=$cal==='jalali'?' selected':''?>><?=t('set.cal.jalali')?></option><option value="gregorian"<?=$cal==='gregorian'?' selected':''?>><?=t('set.cal.greg')?></option></select><p class="hint"><?=t('set.cal.hint')?></p>
<label><?=t('set.deflang')?></label><select id="sdl"><?=$langOpt?></select><p class="hint"><?=t('set.deflang.hint')?></p></div>
<div class="card scard"><div class="sh2"><span class="rc-ic" data-i="sliders" data-s="22"></span><div><h3><?=t('set.sec')?></h3><p class="mu"><?=t('set.sec.sub')?></p></div></div>
<label><?=t('set.policy')?></label><select id="spp"><option value="low"><?=t('set.policy.low')?></option><option value="mid"><?=t('set.policy.mid')?></option><option value="high"><?=t('set.policy.high')?></option></select><p class="hint"><?=t('set.policy.hint')?></p></div>
<div class="card scard"><div class="sh2"><span class="rc-ic" data-i="link" data-s="22"></span><div><h3><?=t('set.net')?></h3><p class="mu"><?=t('set.net.sub')?></p></div></div>
<label><?=t('set.mode')?></label><select id="smode"><option value="p2p"><?=t('mode.p2p')?></option><option value="sfu"><?=t('mode.sfu')?></option></select>
<p class="hint" id="hintp2p"><?=t('set.mode.hint.p2p')?></p><p class="hint na" id="hintsfu"><?=t('set.mode.hint.sfu')?></p>
<div id="lkbox" class="na"><label><?=t('set.lk')?></label><p class="hint"><?=t('set.lk.hint')?></p>
<label><?=t('set.lkurl')?></label><input id="slu" dir="ltr" placeholder="wss://meet.example.com">
<label><?=t('set.lkkey')?></label><input id="slk" dir="ltr">
<label><?=t('set.lksec')?></label><input id="sls" dir="ltr"></div></div>
<div class="card scard"><div class="sh2"><span class="rc-ic" data-i="send" data-s="22"></span><div><h3><?=t('set.ice')?></h3><p class="mu"><?=t('set.ice.sub')?></p></div></div>
<label><?=t('set.stun')?></label><div class="inl"><input id="sst" dir="ltr" placeholder="<?=h(t('set.stun.ph'))?>"><button type="button" class="btn ghost sm" id="sstd"><?=t('set.stun.def')?></button></div><p class="hint"><?=t('set.stun.hint')?></p>
<label><?=t('set.turn')?></label><input id="stu" dir="ltr" placeholder="<?=h(t('set.turn.ph'))?>"><p class="hint"><?=t('set.turn.hint')?></p>
<label><?=t('set.turnsec')?></label><input id="stus" dir="ltr" placeholder="<?=h(t('set.turnsec.ph'))?>"><p class="hint"><?=t('set.turnsec.hint')?></p>
<details class="mini"><summary><?=t('set.turnfixed')?></summary><label><?=t('set.turnuser')?></label><input id="stuu" dir="ltr"><label><?=t('set.turnpass')?></label><input id="stup" dir="ltr"></details>
<label><?=t('set.quality')?></label><select id="sq"><option value="low"><?=t('set.q.low')?></option><option value="mid"><?=t('set.q.mid')?></option><option value="high"><?=t('set.q.high')?></option></select><p class="hint"><?=t('set.q.hint')?></p>
<div class="sec setrow"><div><b><?=t('set.relay')?></b><p class="hint"><?=t('set.relay.hint')?></p></div><label class="toggle"><span class="sw"><input type="checkbox" id="sr"><i></i></span></label></div>
<div class="inl"><button type="button" class="btn ghost sm" id="stest"><?=t('set.test')?></button></div><p id="sres" class="hint"></p></div>
<div class="card scard"><div class="sh2"><span class="rc-ic" data-i="down" data-s="22"></span><div><h3><?=t('bak.title')?></h3><p class="mu"><?=t('bak.sub')?></p></div></div>
<p class="hint"><?=t('bak.hint')?></p><button type="button" class="btn ghost sm" id="sbak"><?=t('bak.btn')?></button></div>
<div class="card scard"><div class="sh2"><span class="rc-ic" data-i="arrow" data-s="22"></span><div><h3><?=t('upd.title')?></h3><p class="mu"><?=t('upd.sub')?></p></div></div>
<p class="hint" id="updnote"><?=t('upd.cur',['v'=>locn(trim((string)@file_get_contents(__DIR__.'/VERSION')))])?></p>
<div class="inl"><button type="button" class="btn ghost sm" id="updchk"><?=t('upd.check')?></button><button type="button" class="btn sm" id="updgo" disabled><?=t('upd.apply')?></button></div></div>
</div>
<div class="sbar"><button class="btn" id="ss"><?=t('set.save')?></button></div>
<h3 class="gh"><?=t('help.title')?></h3>
<details class="help" open><summary><?=t('help.dns.sum')?></summary><div class="hb"><?=t('help.dns.body')?></div></details>
<details class="help" open><summary><?=t('help.run.sum')?></summary><div class="hb"><?=t('help.run.body')?></div></details>
<details class="help"><summary><?=t('help.after.sum')?></summary><div class="hb"><?=t('help.after.body')?></div></details>
</section></div>
<button class="fab adm" id="fab" title="<?=h(t('rooms.new'))?>" data-i="plus" data-s="26"></button></div>
<?=i18n_boot_js()?>
<script src="<?=asset('assets/jquery.min.js')?>"></script><script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script><script src="<?=asset('assets/common.js')?>"></script><script src="<?=asset('assets/dash.js')?>"></script><script>if('serviceWorker'in navigator)navigator.serviceWorker.register('sw.js')</script></body></html>
