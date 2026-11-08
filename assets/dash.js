$(function(){let U=null,RM={},APP={langs:[]};
const post=(a,d)=>$.post('api.php?action='+a,d||{},null,'json').fail(x=>toast((x.responseJSON||{}).error||t('err.generic')));
let tab='rooms';const isAd=()=>U&&U.role==='admin';
const row=(title,desc,f,on,id)=>`<div class="sec setrow"><div><b>${title}</b><p class="mu">${desc}</p></div><label class="toggle"><span class="sw"><input type="checkbox" ${f?`data-f="${f}"`:`id="${id}"`} ${on?'checked':''}><i></i></span></label></div>`;
const langSel=(id,cur)=>`<label>${t('profile.lang')}</label><select id="${id}">${(APP.langs||[]).map(l=>`<option value="${esc(l.id)}" ${l.id===cur?'selected':''}>${esc(l.name)}</option>`).join('')}</select>`;
function init(){post('me').done(r=>{U=r.user;APP=r;if(!U){$('#login').removeAttr('hidden');return}$('#login').attr('hidden',true);$('#app').show();$('#un').html(avh(U,26)+'<span>'+esc(U.name)+'</span>');$('.adm').toggle(isAd());$('#mainnav').toggle(isAd());
 if(r.cal)window.CAL=r.cal;if(r.tz)window.TZ=r.tz;
 $('#sb').val(r.brand||'');$('#sd').val(r.domain||'');$('#sst').val(r.stun||'');$('#stu').val(r.turn_url||'');$('#stuu').val(r.turn_user||'');$('#stup').val(r.turn_pass||'');$('#stus').val(r.turn_secret||'');$('#sr').prop('checked',r.ice_relay=='1');$('#sq').val(r.quality||'mid');
 $('#smode').val(r.mode||'p2p');$('#slu').val(r.lk_url||'');$('#slk').val(r.lk_key||'');$('#sls').val(r.lk_secret||'');
 if(r.calendar)$('#scal').val(r.calendar);if(r.timezone)$('#stz').val(r.timezone);if(r.pass_policy)$('#spp').val(r.pass_policy);if(r.default_lang)$('#sdl').val(r.default_lang);
 if(isAd()){sslSaved=r.ssl_mode==='manual'?'manual':'auto';sslPaint(sslSaved)}
 modeUI();rooms()})}
const doLogin=()=>post('login',{username:$('#lu').val(),password:$('#lp').val(),lang:$('#llang').val()}).done(r=>{if(r.csrf)window.CSRF=r.csrf;init()});
$('#lb').click(doLogin);$('#lp,#lu').keydown(e=>{if(e.key=='Enter')doLogin()});
$('#llang').change(function(){post('lang_pick',{lang:this.value}).done(()=>location.reload())});
$('#lo').click(()=>post('logout').done(()=>location.reload()));
$('.seg button').click(function(){const tname=$(this).data('t');if(!tname)return;$('.seg button').removeClass('act');$(this).addClass('act');$('.pane0').hide();$('#'+tname).show();tab=tname;$('#fab').toggle((tname=='rooms'||tname=='users')&&isAd()).attr('title',tname=='users'?t('rooms.adduser'):t('rooms.new'));if(tname=='users')users();if(tname==='set')updCheck(0);monKick()});
$('#fab').click(()=>tab=='users'?addUser():createRoom());
$('#un').click(profile);
$('#sstd').click(()=>$('#sst').val('stun:stun.l.google.com:19302'));
$('#smode').on('change',modeUI);
function modeUI(){const sfu=$('#smode').val()==='sfu';$('#lkbox').toggleClass('na',!sfu);$('#hintp2p').toggleClass('na',sfu);$('#hintsfu').toggleClass('na',!sfu)}
function attendance(x){let rows=[],view='d';
 const ph=window.CAL==='jalali'?t('att.ph.j'):t('att.ph.g');
 const md=modal(`<h3>${t('att.title',{name:esc(x.name)})}</h3>
 <div class="afil"><div><label>${t('att.from')}</label><input id="af" dir="ltr" inputmode="numeric" placeholder="${ph}"></div><div><label>${t('att.to')}</label><input id="at" dir="ltr" inputmode="numeric" placeholder="${ph}"></div><button class="btn" id="ago">${t('att.show')}</button></div>
 <div class="chips"><button data-r="0">${t('att.today')}</button><button data-r="7">${t('att.d7')}</button><button data-r="30">${t('att.d30')}</button><button data-r="m">${t('att.month')}</button><button data-r="all">${t('att.all')}</button></div>
 <div class="stats" id="ast"></div>
 <div class="inl" style="margin:10px 0;flex-wrap:wrap"><nav class="seg" style="margin:0"><button class="act" data-v="d">${t('att.detail')}</button><button data-v="s">${t('att.sum')}</button></nav><div class="gx"></div><input id="aq" placeholder="${t('att.search')}" style="max-width:190px"><button class="btn ghost sm" id="aex">${ic('down',16)}${t('att.export')}</button></div>
 <div class="tw" id="atb"></div>`,'xl');
 const setR=(a,b)=>{md.find('#af').val(a?dfmt(a):'');md.find('#at').val(b?dfmt(b):'')};
 const get=a=>dparse(md.find(a).val());
 function render(){const q=md.find('#aq').val().trim(),rs=rows.filter(r=>!q||r.name.includes(q));
  const uniq=new Set(rs.map(r=>r.name+r.is_guest)).size,tot=rs.reduce((a,r)=>a+(+r.mins||0),0);
  md.find('#ast').html(`<div class="stat"><b>${locn(uniq)}</b><span>${t('att.people')}</span></div><div class="stat"><b>${locn(rs.length)}</b><span>${t('att.visits')}</span></div><div class="stat"><b>${fmtMin(tot)}</b><span>${t('att.total')}</span></div><div class="stat"><b>${rs.length?fmtMin(tot/rs.length):locn(0)}</b><span>${t('att.avg')}</span></div>`);
  let h;if(!rs.length)h='<div class="emp">'+t('att.empty')+'</div>';
  else if(view=='d')h=`<table><tr><th>#</th><th>${t('att.name')}</th><th>${t('att.kind')}</th><th>${t('att.date')}</th><th>${t('att.in')}</th><th>${t('att.out')}</th><th>${t('att.mins')}</th><th>${t('att.status')}</th></tr>${rs.map((r,i)=>{const ad=dfmt(r.joined_at),bd=dfmt(r.le);return `<tr><td>${locn(i+1)}</td><td>${esc(r.name)}</td><td>${+r.is_guest?t('kind.guest'):t('kind.user')}</td><td>${ad}</td><td>${tfm(r.joined_at)}</td><td>${tfm(r.le)}${bd!=ad?' <span class="mu">('+bd+')</span>':''}</td><td>${fmtMin(r.mins)}</td><td>${+r.online?'<span class="badge gr">'+t('att.online')+'</span>':''}</td></tr>`}).join('')}</table>`;
  else{const g={};rs.forEach(r=>{const k=r.name+'|'+r.is_guest,o=g[k]=g[k]||{name:r.name,guest:+r.is_guest,n:0,m:0,f:r.joined_at,l:r.le};o.n++;o.m+=+r.mins||0;if(r.joined_at<o.f)o.f=r.joined_at;if(r.le>o.l)o.l=r.le});
   h=`<table><tr><th>#</th><th>${t('att.name')}</th><th>${t('att.kind')}</th><th>${t('att.count')}</th><th>${t('att.total')}</th><th>${t('att.first')}</th><th>${t('att.last')}</th></tr>${Object.values(g).sort((a,b)=>b.m-a.m).map((o,i)=>`<tr><td>${locn(i+1)}</td><td>${esc(o.name)}</td><td>${o.guest?t('kind.guest'):t('kind.user')}</td><td>${locn(o.n)}</td><td>${fmtMin(o.m)}</td><td>${dtfmt(o.f)}</td><td>${dtfmt(o.l)}</td></tr>`).join('')}</table>`}
  md.find('#atb').html(h)}
 const load=()=>post('attendance_list',{room_id:x.id,from:get('#af'),to:get('#at')}).done(r=>{rows=r.rows;render()});
 md.find('#ago').click(load);md.find('#af,#at').keydown(e=>{if(e.key=='Enter')load()});md.find('#aq').on('input',render);
 md.find('.chips button').click(function(){const k=$(this).data('r'),now=todayYmd();
  if(k==='all')setR('','');
  else if(k==='m'){if(window.CAL==='jalali'){const j=g2j(...now.split('-').map(Number)),g=j2g(j[0],j[1],1);setR(g[0]+'-'+String(g[1]).padStart(2,'0')+'-'+String(g[2]).padStart(2,'0'),now)}else setR(now.slice(0,8)+'01',now)}
  else setR(todayYmd(-(+k>0?+k-1:0)),now);load()});
 md.find('.seg button').click(function(){md.find('.seg button').removeClass('act');$(this).addClass('act');view=$(this).data('v');render()});
 md.find('#aex').click(()=>{const q=md.find('#aq').val().trim(),rs=rows.filter(r=>!q||r.name.includes(q));if(!rs.length)return toast(t('att.noexport'));
  const esc2=v=>'"'+String(v).replace(/"/g,'""')+'"',L=[[t('att.name'),t('att.kind'),t('att.date'),t('att.in'),t('att.out'),t('att.mins.n'),t('att.status')].map(esc2).join(',')];
  rs.forEach(r=>L.push([r.name,+r.is_guest?t('kind.guest'):t('kind.user'),dfmt(r.joined_at),String(r.joined_at).slice(11,16),String(r.le).slice(11,16),r.mins,+r.online?t('att.online'):''].map(esc2).join(',')));
  const a=document.createElement('a');a.href=URL.createObjectURL(new Blob(['\uFEFF'+L.join('\r\n')],{type:'text/csv;charset=utf-8'}));a.download='attendance_'+x.code+'.csv';a.click()});
 setR(todayYmd(-6),todayYmd());load()}
function rooms(){post('rooms_list').done(r=>{RM={};$('#rsub').text(t('rooms.count',{n:locn(r.rooms.length)}));$('#rl').html(r.rooms.map(x=>{RM[x.id]=x;const m=+x.mgr;return `<div class="card rc" style="--hue:${(x.id*47)%360}"><div class="rc-h"><span class="rc-ic">${ic('video',22)}</span><div class="gx"><h3>${esc(x.name)}</h3><span class="mu ltr">${x.code}</span></div>${m?`<span class="badge pr">${t('rooms.role.mgr')}</span>`:''}${+x.online?`<span class="mt live"><i class="ld"></i>${t('rooms.online',{n:locn(x.online)})}</span>`:''}</div><div class="rc-meta"><span class="mt">${ic('users',14)}${t('rooms.members',{n:locn(x.members)})}</span>${+x.chat_enabled?`<span class="mt">${ic('chat',14)}${t('rooms.chaton')}</span>`:''}${+x.rec_enabled?`<span class="mt">${ic('rec',14)}${t('rooms.recon')}</span>`:''}${+x.guest_enabled?`<span class="mt">${ic('door',14)}${t('rooms.gueston')}</span>`:''}</div><div class="rc-a"><a class="btn" href="room.php?c=${x.code}">${ic('enter')}${t('rooms.enter')}</a><button class="btn ib2" title="${t('rooms.recfiles')}" data-a="rec" data-i="${x.id}">${ic('video')}</button>${m?`<button class="btn ib2" title="${t('rooms.att')}" data-a="att" data-i="${x.id}">${ic('sheet')}</button><button class="btn ib2" title="${t('rooms.rset')}" data-a="set" data-i="${x.id}">${ic('gear')}</button>`:''}</div></div>`}).join('')||'<p class="mu">'+t('rooms.empty')+'</p>');filterRooms()})}
const filterRooms=()=>{const q=$('#rsq').val().trim();$('#rl .rc').each(function(){$(this).toggle(!q||$(this).find('h3').text().includes(q))})};
$('#rsq').on('input',filterRooms);
$('#rl').on('click','[data-a]',function(){const a=$(this).data('a'),i=$(this).data('i');
 if(a=='att')attendance(RM[i]);if(a=='rec')recs(i);if(a=='set')settings(RM[i])});
function recs(i){post('rec_list',{room_id:i}).done(r=>{const body=r.recs.map(c=>{const src='api.php?action=rec_dl&id='+encodeURIComponent(c.id)+'&v='+encodeURIComponent(c.size||0);return '<div class="recitem"><div class="recrow"><button type="button" class="recopen" data-src="'+src+'"><span class="recgo"></span><span class="gx">'+dtfmt(c.created)+'<span class="mu">'+locn((c.size/1048576).toFixed(1))+' '+t('rec.mb')+'</span></span><span class="chev">'+ic('chev',18)+'</span></button>'+(r.mgr?'<button type="button" class="btn sm danger" data-d="'+(+c.id)+'">'+ic('trash',16)+'</button>':'')+'</div><div class="recdrop" hidden></div></div>'}).join('')||'<p class="mu">'+t('rec.empty')+'</p>';
 const foot=isAd()&&r.recs.length?'<button type="button" class="btn danger block" id="recall">'+t('rec.delall')+'</button>':'';
 const md=modal('<h3>'+t('rec.title')+'</h3>'+body+foot,'lg');
 md.on('click','.recopen',function(){const row=$(this).closest('.recitem'),drop=row.find('.recdrop'),open=row.hasClass('open'),src=this.getAttribute('data-src');
  md.find('video').each(function(){try{this.pause()}catch(e){}});md.find('.recitem').removeClass('open');md.find('.recdrop').attr('hidden',true).empty();
  if(open)return;row.addClass('open');drop.removeAttr('hidden');
  const v=document.createElement('video');v.className='recplay';v.controls=true;v.playsInline=true;v.preload='auto';v.src=src;drop[0].appendChild(v);v.play().catch(function(){})});
 md.on('click','[data-d]',function(){confirm(t('confirm.rec'))&&post('rec_del',{id:$(this).data('d')}).done(()=>{md.remove();recs(i)})});
 md.on('click','#recall',function(){confirm(t('confirm.recall'))&&post('rec_clear',{room_id:i,confirm:1}).done(()=>{md.remove();recs(i)})})})}
function picker($el,room,onChange){const sel=new Set();let tm;
 $el.html(`<div class="srch">${ic('search',18)}<input type="search" placeholder="${t('member.search')}"></div><div class="plist"></div>`);
 const load=()=>post('users_search',{q:$el.find('input').val(),room_id:room||0}).done(r=>$el.find('.plist').html(r.users.map(u=>`<label class="pk"><input type="checkbox" value="${u.id}" ${sel.has(String(u.id))?'checked':''}>${avh(u,32)}<span class="gx">${esc(u.name)} <span class="mu ltr">${esc(u.username)}</span></span></label>`).join('')||'<p class="mu">'+t('member.none')+'</p>'));
 $el.on('input','input[type=search]',()=>{clearTimeout(tm);tm=setTimeout(load,250)});
 $el.on('change','.pk input',function(){this.checked?sel.add(this.value):sel.delete(this.value);onChange&&onChange(sel)});load();return sel}
function createRoom(){const md=modal(`<h3>${t('room.new.title')}</h3><label>${t('room.field.name')}</label><input id="cn" placeholder="${t('room.field.name.ph')}"><label>${t('room.field.desc')}</label><textarea id="cd"></textarea>
${row(t('room.guest'),t('room.guest.d'),0,0,'cg')}${row(t('room.chatopt'),t('room.chatopt.d'),0,0,'cc')}${row(t('room.recopt'),t('room.recopt.d'),0,1,'cr')}
<div class="sec"><label>${t('room.pick')}</label><div id="pk"></div><div class="inl" style="margin-top:8px"><select id="cro"><option value="0">${t('room.role.user')}</option><option value="1">${t('room.role.mgr')}</option></select></div></div>
<button class="btn block" id="cgo">${t('room.create')}</button>`,'lg');let sel=new Set();sel=picker(md.find('#pk'),0,s=>md.find('#cgo').text(s.size?t('room.create.n',{n:locn(s.size)}):t('room.create')));
 md.find('#cgo').click(()=>post('room_add',{name:md.find('#cn').val(),description:md.find('#cd').val(),guest_enabled:+md.find('#cg').prop('checked'),chat_enabled:+md.find('#cc').prop('checked'),rec_enabled:+md.find('#cr').prop('checked')}).done(r=>{
  const fin=()=>{md.remove();rooms();toast(t('room.created'))};sel.size?post('member_add',{room_id:r.id,user_ids:[...sel].join(','),is_manager:md.find('#cro').val()}).done(fin):fin()}))}
function settings(x){const md=modal(`<h3>${t('room.set.title',{name:esc(x.name)})}</h3>
<div class="sec"><label>${t('room.set.name')}</label><div class="inl"><input id="sn" value="${esc(x.name)}"><button class="btn" id="snb">${t('profile.save')}</button></div><p class="mu">${t('room.set.name.d')}</p></div>
<div class="sec"><label>${t('room.set.desc')}</label><textarea id="sdd">${esc(x.description)}</textarea><button class="btn ghost sm" id="sdb" style="margin-top:6px">${t('room.set.desc.btn')}</button><p class="mu">${t('room.set.desc.d')}</p></div>
<div class="sec"><label>${t('room.set.link')}</label><div class="inl"><input readonly dir="ltr" value="${esc(x.link)}"><button class="btn ghost ib2" id="scp" title="${t('room.copy')}">${ic('link')}</button><button class="btn ghost ib2" id="sqr" title="${t('room.qr')}">${ic('grid')}</button></div><p class="mu">${t('room.set.link.d')}</p></div>
<div class="sec setrow"><div><b>${t('room.set.people')}</b><p class="mu">${t('room.set.people.d')}</p></div><button class="btn ghost" id="smm">${t('room.set.manage')}</button></div>
${row(t('room.guest'),t('room.set.guest.d'),'guest_enabled',+x.guest_enabled)}${row(t('room.chatopt'),t('room.set.chat.d'),'chat_enabled',+x.chat_enabled)}${row(t('room.recopt'),t('room.set.rec.d'),'rec_enabled',+x.rec_enabled)}
${isAd()?`<div class="sec setrow"><div><b>${t('room.set.del')}</b><p class="mu">${t('room.set.del.d')}</p></div><button class="btn dangersolid" id="sdel">${t('users.del.btn')}</button></div>`:''}`,'lg');
 const save=d=>post('room_set',Object.assign({id:x.id},d)).done(()=>{Object.assign(x,d);rooms();toast(t('set.saved'))});
 md.find('#snb').click(()=>save({name:md.find('#sn').val()}));md.find('#sdb').click(()=>save({description:md.find('#sdd').val()}));
 md.find('#scp').click(()=>copyText(x.link));md.find('#sqr').click(()=>linkModal(x.link));
 md.find('#smm').click(()=>members(x.id));md.on('change','[data-f]',function(){save({[$(this).data('f')]:this.checked?1:0})});
 md.find('#sdel').click(()=>confirm(t('confirm.room',{name:x.name}))&&post('room_del',{id:x.id}).done(()=>{md.remove();rooms()}))}
const PM=[['mic','mic','perm.mic'],['cam','cam','perm.cam'],['screen','screen','perm.screen'],['board','board','perm.board'],['file','sheet','perm.file']];
function members(i){post('members',{room_id:i}).done(m=>{const ms=m.members,ad=isAd();
 const md=modal(`<h3>${t('member.title')}</h3>${ad?`<div class="sec"><label>${t('member.add')}</label><div id="pk"></div><div class="inl" style="margin-top:8px"><select id="mr"><option value="0">${t('member.user')}</option><option value="1">${t('member.mgr')}</option></select><button class="btn" id="mad">${t('member.add.btn')}</button></div></div>`:''}
 <div>${ms.map(x=>`<div class="mrow" data-u="${x.id}"><div class="mh">${avh(x,40)}<div class="gx"><b>${esc(x.name)}</b><div class="mu ltr">${esc(x.username)}</div></div>${ad?`<select data-k="m"><option value="0" ${+x.is_manager?'':'selected'}>${t('member.user')}</option><option value="1" ${+x.is_manager?'selected':''}>${t('member.mgr')}</option></select>`:`<span class="badge ${+x.is_manager?'pr':''}">${+x.is_manager?t('member.mgr'):t('rooms.role.member')}</span>`}${ad?`<button class="btn ib2 danger" data-d="1" style="width:34px;height:34px">${ic('x',16)}</button>`:''}<span class="chev">${ic('chev',18)}</span></div>
 <div class="mperms">${PM.map(([k,icn,key])=>`<label class="pm"><span>${ic(icn,16)}${t(key)}</span><span class="sw"><input type="checkbox" data-k="${k}" ${+x['can_'+k]?'checked':''}><i></i></span></label>`).join('')}</div></div>`).join('')||'<p class="mu">'+t('member.empty')+'</p>'}</div>`,'lg');
 if(ad){const sel=picker(md.find('#pk'),i,s=>md.find('#mad').text(s.size?t('member.add.n',{n:locn(s.size)}):t('member.add.btn')));
  md.find('#mad').click(()=>sel.size?post('member_add',{room_id:i,user_ids:[...sel].join(','),is_manager:md.find('#mr').val()}).done(()=>{md.remove();members(i)}):toast(t('users.pick')))}
 md.on('click','.mh',function(e){if($(e.target).closest('select,button.btn').length)return;$(this).closest('.mrow').toggleClass('open')});
 md.on('change','[data-k]',function(){const r=$(this).closest('.mrow'),c=k=>+r.find(`input[data-k=${k}]`).prop('checked')||0;post('member_set',{room_id:i,user_id:r.data('u'),is_manager:+r.find('select[data-k=m]').val()||0,can_mic:c('mic'),can_cam:c('cam'),can_screen:c('screen'),can_board:c('board'),can_file:c('file')})});
 md.on('click','[data-d]',function(){confirm(t('confirm.member'))&&post('member_del',{room_id:i,user_id:$(this).closest('.mrow').data('u')}).done(()=>{md.remove();members(i)})})})}
let ULIST=[],uf='all';
function users(){post('users_list').done(r=>{ULIST=r.users;renderUsers()})}
function renderUsers(){const q=$('#usq').val().trim().toLowerCase(),L=ULIST.filter(x=>(uf=='all'||x.role==uf)&&(!q||(x.name+' '+x.username).toLowerCase().includes(q)));$('#usub').text(t('users.count',{n:locn(ULIST.length)}));
 $('#ul').html(L.map(x=>`<div class="card ucard" data-id="${x.id}">${avh(x,54)}<div class="gx"><div class="un1"><b>${esc(x.name)}</b><span class="badge ${x.role=='admin'?'pr':''}">${x.role=='admin'?t('users.role.admin'):t('users.role.user')}</span></div><div class="mu ltr">@${esc(x.username)}</div><p class="ubio">${esc(x.bio)||'<span class="mu">'+t('users.nobio')+'</span>'}</p></div><div class="ucnt"><b>${locn(x.rooms)}</b><span>${t('users.rooms')}</span></div></div>`).join('')||'<p class="mu">'+t('users.empty')+'</p>')}
$('#usq').on('input',renderUsers);$('#ufl button').click(function(){uf=$(this).data('f');$('#ufl button').removeClass('act');$(this).addClass('act');renderUsers()});
$('#ul').on('click','.ucard',function(){userModal($(this).data('id'))});
function addUser(){const md=modal(`<h3>${t('users.add')}</h3><label>${t('login.user')}</label><input id="nu" dir="ltr" autocomplete="off"><label>${t('profile.name')}</label><input id="nn"><label>${t('login.pass')}</label><input id="np" dir="ltr" autocomplete="new-password"><label>${t('users.role')}</label><select id="nr"><option value="user">${t('users.role.user')}</option><option value="admin">${t('users.role.admin')}</option></select>${langSel('nlang',APP.lang||'')}<button class="btn block" id="na">${ic('plus')}${t('users.add')}</button>`);
 md.find('#na').click(()=>post('user_add',{username:md.find('#nu').val(),name:md.find('#nn').val(),password:md.find('#np').val(),role:md.find('#nr').val(),lang:md.find('#nlang').val()}).done(()=>{md.remove();users();toast(t('users.added'))}))}
function avatarBox(usr){return `<div class="phead"><div class="av-prev">${avh(usr,84)}</div><div class="gx"><b style="display:block;font-size:1.05rem">${esc(usr.name)}</b><div class="mu ltr">@${esc(usr.username)}</div><div class="inl" style="margin-top:10px;flex-wrap:wrap"><button type="button" class="btn ghost sm" data-av="up">${ic('plus',14)}${t('profile.photo')}</button><button type="button" class="btn danger sm" data-av="del">${t('profile.delphoto')}</button><input type="file" accept="image/*" hidden data-av="file"></div></div></div>`}
function avatarBind(md,usr,self,after){const send=fd=>$.ajax({url:'api.php?action=avatar_set',method:'POST',data:fd,processData:false,contentType:false,dataType:'json'});
 md.on('click','[data-av=up]',()=>md.find('[data-av=file]').click());
 md.on('change','[data-av=file]',function(){const f=this.files[0];if(!f)return;const fd=new FormData();fd.append('f',f);if(!self)fd.append('id',usr.id);
  send(fd).done(r=>{usr.avatar=r.avatar;md.find('.av-prev').html(avh(usr,84));after();toast(t('profile.savedphoto'))}).fail(x=>toast((x.responseJSON||{}).error||t('profile.uploaderr')));this.value=''});
 md.on('click','[data-av=del]',()=>{if(!usr.avatar)return toast(t('profile.nophoto'));post('avatar_del',self?{}:{id:usr.id}).done(()=>{usr.avatar=null;md.find('.av-prev').html(avh(usr,84));after();toast(t('profile.delok'))})})}
function profile(){const usr=Object.assign({},U),md=modal(`<h3>${t('profile.title')}</h3>${avatarBox(usr)}
 <div class="sec"><label>${t('profile.name')}</label><input id="pn" value="${esc(usr.name)}"><label>${t('profile.bio')}</label><textarea id="pb" maxlength="200" placeholder="${t('profile.bio.ph')}">${esc(usr.bio)}</textarea>
 ${langSel('plang',usr.lang||APP.lang||'')}
 <details class="mini"><summary>${t('profile.pass')}</summary><label>${t('profile.current')}</label><input id="pc" type="password" autocomplete="current-password" dir="ltr"><label>${t('profile.new',{n:locn(APP.pass_min||4)})}</label><input id="pw" type="password" autocomplete="new-password" dir="ltr"></details>
 <button class="btn block" id="psv">${t('profile.save')}</button></div>`);
 avatarBind(md,usr,1,()=>{U.avatar=usr.avatar;$('#un').html(avh(U,26)+'<span>'+esc(U.name)+'</span>')});
 md.find('#psv').click(()=>post('profile_save',{name:md.find('#pn').val(),bio:md.find('#pb').val(),lang:md.find('#plang').val(),current:md.find('#pc').val(),password:md.find('#pw').val()}).done(r=>{U.name=md.find('#pn').val().trim()||U.name;U.bio=md.find('#pb').val();U.lang=md.find('#plang').val();$('#un').html(avh(U,26)+'<span>'+esc(U.name)+'</span>');if(r.reload)location.reload();else{md.remove();toast(t('profile.saved'));if(tab=='users')users()}}))}
function userModal(id){post('user_get',{id}).done(r=>{const usr=r.user,self=U.id==usr.id;
 const md=modal(`<h3>${t('users.manage')}</h3>${avatarBox(usr)}
 <div class="sec"><label>${t('profile.name')}</label><input id="un2" value="${esc(usr.name)}"><label>${t('profile.bio')}</label><textarea id="ub" maxlength="200">${esc(usr.bio)}</textarea>
 <label>${t('users.role')}</label><select id="ur" ${self?'disabled':''}><option value="user" ${usr.role=='user'?'selected':''}>${t('users.role.user')}</option><option value="admin" ${usr.role=='admin'?'selected':''}>${t('users.role.admin')}</option></select>
 ${langSel('ulang',usr.lang||APP.lang||'')}
 <label>${t('users.passopt')}</label><input id="up2" dir="ltr" autocomplete="new-password" placeholder="${t('users.passph')}"><button class="btn block" id="usv">${t('users.save')}</button></div>
 <div class="sec"><b>${t('users.member')}</b><p class="hint">${t('users.member.hint')}</p>
 <div id="urooms">${r.rooms.map(rm=>`<div class="mrow" data-r="${rm.id}"><div class="urow"><label class="toggle"><span class="sw"><input type="checkbox" class="rm" ${+rm.member?'checked':''}><i></i></span><span class="gx">${esc(rm.name)}</span></label><select class="rr" style="${+rm.member?'':'visibility:hidden'}"><option value="0" ${+rm.is_manager?'':'selected'}>${t('member.user')}</option><option value="1" ${+rm.is_manager?'selected':''}>${t('member.mgr')}</option></select></div></div>`).join('')||'<p class="mu">'+t('users.none.rooms')+'</p>'}</div></div>
 ${self?'':`<div class="sec setrow"><div><b>${t('users.del')}</b><p class="hint">${t('users.del.hint')}</p></div><button class="btn dangersolid" id="udel">${t('users.del.btn')}</button></div>`}`,'lg');
 avatarBind(md,usr,0,users);
 md.find('#usv').click(()=>post('user_set',{id:usr.id,name:md.find('#un2').val(),bio:md.find('#ub').val(),role:md.find('#ur').val(),lang:md.find('#ulang').val(),password:md.find('#up2').val()}).done(()=>{md.find('#up2').val('');users();toast(t('users.saved'))}));
 md.on('change','.rm,.rr',function(){const row=$(this).closest('.mrow'),on=+row.find('.rm').prop('checked');row.find('.rr').css('visibility',on?'visible':'hidden');
  post('user_room_set',{user_id:usr.id,room_id:row.data('r'),member:on,is_manager:+row.find('.rr').val()||0}).done(()=>{rooms();users()})});
 md.find('#udel').click(()=>confirm(t('users.confirm',{name:usr.name}))&&post('user_del',{id:usr.id}).done(()=>{md.remove();users();rooms()}))})}
$('#ss').click(()=>post('settings_save',{brand:$('#sb').val(),domain:$('#sd').val(),stun:$('#sst').val(),turn_url:$('#stu').val(),turn_user:$('#stuu').val(),turn_pass:$('#stup').val(),turn_secret:$('#stus').val(),ice_relay:+$('#sr').prop('checked'),quality:$('#sq').val(),link_mode:$('#smode').val(),lk_url:$('#slu').val(),lk_key:$('#slk').val(),lk_secret:$('#sls').val(),calendar:$('#scal').val(),timezone:$('#stz').val(),pass_policy:$('#spp').val(),default_lang:$('#sdl').val()}).done(()=>{window.CAL=$('#scal').val()==='gregorian'?'gregorian':'jalali';window.TZ=$('#stz').val()||window.TZ;toast(t('set.saved'))}));
$('#sic').click(()=>{const f=$('#si')[0].files[0];if(!f)return;const fd=new FormData();fd.append('f',f);$.ajax({url:'api.php?action=set_icon',method:'POST',data:fd,processData:false,contentType:false,dataType:'json'}).done(()=>toast(t('set.icon.ok'))).fail(x=>toast((x.responseJSON||{}).error||t('err.generic')))});
$('#stest').click(function(){const el=$('#sres').text(t('net.testing'));post('ice').done(async r=>{const types=new Set();let pc;try{pc=new RTCPeerConnection({iceServers:r.ice});pc.createDataChannel('t');pc.onicecandidate=e=>{if(!e.candidate||!e.candidate.candidate)return;const m=/typ (\w+)/.exec(e.candidate.candidate);if(m)types.add(m[1])};await pc.setLocalDescription(await pc.createOffer());await new Promise(res=>setTimeout(res,4000))}catch(e){el.text(t('net.fail'));return}finally{pc&&pc.close()}
 if(types.has('relay'))el.text(t('net.turn_ok'));else if(types.has('srflx'))el.text(t('net.stun_ok'));else if(types.has('host'))el.text(t('net.host_only'));else el.text(t('net.fail'))})});
let monT=0;
function monOnce(){$.post('api.php?action=monitor',{},null,'json').done(r=>{
 const cell=(n,l)=>`<div class="stat"><b>${n}</b><span>${l}</span></div>`;
 const cpu=r.cpu==null?(r.mem?t('mon.wait'):'—'):locn(r.cpu)+'%';
 const mem=r.mem?fmtB(r.mem.used)+' / '+fmtB(r.mem.total):'—';
 const bw=!r.bw?'—':(r.bw.wait?t('mon.wait'):'↓ '+fmtB(r.bw.rx)+'/s · ↑ '+fmtB(r.bw.tx)+'/s');
 const load=r.load==null?'—':locn(r.load);
 $('#monbox').html(cell(locn(r.online),t('mon.online'))+cell(locn(r.rooms),t('mon.rooms'))+cell(cpu,t('mon.cpu'))+cell(mem,t('mon.mem'))+cell(bw,t('mon.bw'))+cell(load,t('mon.load')));
 let note=!r.sys?t('mon.unavailable'):'';
 if(r.sys&&r.src==='wss')note=t('mon.wss');
 $('#monnote').text(note);
})}
function monKick(){clearInterval(monT);if(tab==='set'&&isAd()){monOnce();monT=setInterval(monOnce,4000)}}
function updNote(r){
 const el=$('#updnote');
 if(!r||!r.ok){$('#updgo').prop('disabled',true);el.text(t('upd.net'));return}
 if(r.newer){el.text(t('upd.now',{cur:locn(r.cur),latest:locn(r.latest)})+(r.can?'':' '+t('upd.perm')));$('#updgo').prop('disabled',!r.can)}
 else{el.text(t('upd.same',{cur:locn(r.cur)}));$('#updgo').prop('disabled',true)}
}
function updCheck(force){if(!isAd())return;post('update_check',{force:force?1:0}).done(updNote)}
$('#updchk').click(()=>updCheck(1));
$('#updgo').click(function(){if(!confirm(t('upd.confirm')))return;const b=$(this).prop('disabled',true);const done=()=>{toast(t('upd.done'));setTimeout(()=>location.reload(),1200)};$.post('api.php?action=update_apply',{},null,'json').done(done).fail(()=>{setTimeout(()=>{$.post('api.php?action=update_check',{force:1},null,'json').done(r=>{if(r&&r.ok&&!r.newer)done();else{toast(t('upd.fail'));b.prop('disabled',false)}}).fail(()=>{toast(t('upd.fail'));b.prop('disabled',false)})},2000)})});
$('#updre').click(function(){if(!confirm(t('upd.reconfirm')))return;const b=$(this).prop('disabled',true);$.post('api.php?action=update_apply',{reinstall:1,confirm:1},null,'json').done(function(r){if(!r||!r.ok){toast(t('upd.fail'));b.prop('disabled',false);return}toast(t('upd.redone'));setTimeout(()=>location.reload(),1200)}).fail(function(x){toast((x.responseJSON||{}).error||t('upd.fail'));b.prop('disabled',false)})});
$('#sbak').click(function(){const b=$(this).prop('disabled',true);$.ajax({url:'api.php?action=backup',method:'POST',xhrFields:{responseType:'blob'}}).done(function(blob,s,xhr){const ct=xhr.getResponseHeader('Content-Type')||'';if(ct.indexOf('json')>=0){toast(t('bak.fail'));return}const a=document.createElement('a');const name=(/filename="([^"]+)"/.exec(xhr.getResponseHeader('Content-Disposition')||'')||[])[1]||'didar-backup.sql';a.href=URL.createObjectURL(blob);a.download=name;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(a.href),2000)}).fail(()=>toast(t('bak.fail'))).always(()=>b.prop('disabled',false))});
$('#srest').click(function(){const f=$('#sresf')[0].files[0];if(!f)return toast(t('bak.badfile'));if(!confirm(t('bak.restore.confirm')))return;const fd=new FormData();fd.append('f',f);fd.append('confirm','1');const b=$(this).prop('disabled',true);$.ajax({url:'api.php?action=restore',method:'POST',data:fd,processData:false,contentType:false,dataType:'json'}).done(()=>{toast(t('bak.restored'));setTimeout(()=>location.reload(),800)}).fail(x=>toast((x.responseJSON||{}).error||t('bak.fail'))).always(()=>b.prop('disabled',false))});
let sslSaved='auto';
function sslPaint(m){$('#sslmode [data-ssl]').removeClass('on');$('#sslmode [data-ssl="'+m+'"]').addClass('on');$('#sslauto').toggleClass('na',m!=='auto');$('#sslman').toggleClass('na',m!=='manual')}
function sslSend(data){const b=$('#sslsave,#sslmode [data-ssl]').prop('disabled',true);$.post('api.php?action=ssl_apply',data,null,'json').done(function(r){sslSaved=(r&&r.mode)==='manual'?'manual':'auto';sslPaint(sslSaved);$('#sslpriv,#sslpub,#sslcert,#sslbundle').val('');toast(t('ssl.saved'))}).fail(function(x){const msg=(x.responseJSON||{}).error;if(msg){toast(msg);sslPaint(sslSaved)}else setTimeout(function(){location.reload()},2500)}).always(function(){b.prop('disabled',false)})}
$('#sslmode').on('click','[data-ssl]',function(){const m=$(this).data('ssl');if(m==='auto'){if(sslSaved==='manual'){if(!confirm(t('ssl.restart')))return;sslSend({mode:'auto'})}else sslPaint('auto');return}sslPaint('manual')});
$('#sslsave').click(function(){if(!confirm(t('ssl.restart')))return;sslSend({mode:'manual',priv:$('#sslpriv').val(),pub:$('#sslpub').val(),cert:$('#sslcert').val(),bundle:$('#sslbundle').val()})});
$('#sssl').click(function(){if(!confirm(t('ssl.confirm')))return;const b=$(this).prop('disabled',true);post('ssl_renew',{}).done(()=>toast(t('ssl.done'))).always(()=>b.prop('disabled',false))});
$('#swrooms').click(function(){if(!confirm(t('wipe.rooms.confirm')))return;post('rooms_clear',{confirm:1}).done(()=>{toast(t('wipe.done'));rooms()})});
$('#swusers').click(function(){if(!confirm(t('wipe.users.confirm')))return;post('users_clear',{confirm:1}).done(()=>{toast(t('wipe.done'));setTimeout(()=>location.reload(),600)})});
init()});
