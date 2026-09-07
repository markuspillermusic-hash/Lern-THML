/* Personal learning-state adapter. Anonymous classroom requests remain untouched. */
(() => {
  'use strict';
  const cfg=window.RELIGION_CLASSROOM_CONFIG||{},view=cfg.view||window.RELIGION_VIEW||'student',slug=String(cfg.moduleSlug||'');
  if(!slug)return;
  const api='/zugang/work.php',R=window.LearningWorkRenderer;
  const el=R.node;
  const request=async(action,data={},post=false,csrf='')=>{
    const response=await fetch(post?api:api+'?'+new URLSearchParams({action,...data}),{cache:'no-store',credentials:'same-origin',...(post?{method:'POST',headers:{'Content-Type':'application/json','X-Platform-CSRF':csrf},body:JSON.stringify({action,...data})}:{})});
    const result=await response.json();if(!response.ok&&!result.conflict){const error=new Error(result.error||'Verbindung fehlgeschlagen.');error.status=response.status;throw error;}return result;
  };
  if(view==='beamer') {
    let revision='',busy=false,lastSuccess=Date.now();const screen=el('section',undefined,{class:'work-screen','aria-label':'Freigegebene Schülerlösung',hidden:''});document.body.append(screen);
    async function poll(){if(busy||location.protocol==='file:')return;const room=window.RELIGION_CLASSROOM?.room();if(!room){screen.hidden=true;revision='';return;}busy=true;
      try{const result=await request('projection',{room,module_slug:slug});lastSuccess=Date.now();
        if(!result.active){screen.hidden=true;revision='';return;}if(revision===result.revision)return;revision=result.revision;screen.replaceChildren();
        const selected=result.selection;screen.append(el('p',selected.name?`Schülerlösung · ${selected.name}`:'Schülerlösung'),el('h1',selected.kind==='map'?'So sind die Begriffe verbunden':selected.label));
        if(selected.kind==='map')R.renderMap(screen,selected.map,selected.config,selected.fields);else screen.append(el('p',selected.text||'Keine Antwort gespeichert.',{class:'work-projected-text'}));screen.hidden=false;
      }catch(error){if(Date.now()-lastSuccess>15000){screen.hidden=true;revision='';}}finally{busy=false;}}
    document.addEventListener('religion-classroom-state',poll);setInterval(poll,2500);poll();return;
  }
  if(view==='teacher') {
    function teacherEntry(){const target=document.querySelector('[data-live-room-root]')||document.querySelector('#vorbereitung .wrap')||document.querySelector('main');if(!target)return;
      const bar=el('section',undefined,{class:'learning-sync-bar'});bar.append(el('strong','Klasse und Schülerstände'),el('p','Lernweg einer Klasse zuweisen, Mitschriften abrufen und eine ausgewählte Lösung am Beamer zeigen.'));
      const actions=el('div',undefined,{class:'work-actions'}),button=el('button','Schülerstände öffnen',{type:'button'}),link=el('a','Gemeinsame Klassenverwaltung',{href:'/zugang/?view=classes',target:'_blank',rel:'noopener'});actions.append(button,link);bar.append(actions);target.append(bar);
      button.addEventListener('click',()=>{if(location.protocol==='file:'){alert('Die Schülerstände sind in der Serverversion verfügbar.');return;}
        const dialog=el('dialog',undefined,{class:'work-dialog'}),close=el('button','Schließen',{type:'button'}),frame=el('iframe',undefined,{title:'Klassen und Schülerstände',src:'/zugang/arbeiten/?'+new URLSearchParams({module:slug,room:window.RELIGION_CLASSROOM?.room()||''})});
        frame.style.cssText='width:100%;height:72vh;border:0';dialog.append(close,frame);document.body.append(dialog);close.onclick=()=>dialog.close();dialog.addEventListener('close',()=>dialog.remove());dialog.showModal();
      });
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',teacherEntry,{once:true});else teacherEntry();return;
  }
  let started=false;
  async function start(){if(started||!window.RELIGION_LEARNING_STATE)return;started=true;
    const local=window.RELIGION_LEARNING_STATE,bar=el('section',undefined,{class:'learning-sync-bar','aria-label':'Persönlicher Lernstand'}),title=el('strong','Dein Lernstand'),status=el('p','Zugang wird geprüft …',{role:'status','aria-live':'polite'}),actions=el('div',undefined,{class:'work-actions'});
    bar.append(title,status,actions);const main=document.querySelector('main');if(!main)return;main.prepend(bar);
    const login=el('a','Gemeinsamen Zugang öffnen',{href:'/zugang/?next='+encodeURIComponent(location.pathname+location.search)});actions.append(login);
    if(location.protocol==='file:'){status.textContent='Lokale Fassung · Deine Einträge bleiben auf diesem Gerät. Die persönliche Synchronisation ist in der Serverversion verfügbar.';login.href='https://markuspiller.de'+(cfg.studentUrl||'/zugang/');login.textContent='Serverversion öffnen';return;}
    let session=null,assignment=null,scope='',metaKey='',meta={},paused=true,saving=false,dirty=false,timer=0,conflicting=false,readOnly=false,epoch=0,connecting=false;
    const scopedControls=new Set();
    function resetScopeControls(){for(const node of scopedControls){if(node.open)node.close();node.remove();}scopedControls.clear();conflicting=false;}
    const oldLocal=local.exportState();
    function fingerprint(payload){const p=JSON.parse(JSON.stringify(payload));delete p.title;delete p.exportedAt;delete p._savedAt;if(p.conceptMaps?.maps)Object.values(p.conceptMaps.maps).forEach(m=>delete m.summary);return JSON.stringify(p);}
    function meaningful(p){return Object.values(p.fields||{}).some(v=>String(v).trim())||Object.keys(p.learningTools?.highlights||{}).some(k=>p.learningTools.highlights[k].length)||Object.values(p.learningTools?.drawings||{}).some(d=>d.strokes?.length)||Object.values(p.conceptMaps?.maps||{}).some(m=>m.state?.edges?.length)||['A','B','C'].some(v=>p.ui?.['verdict'+v]);}
    function saveMeta(){try{localStorage.setItem(metaKey,JSON.stringify(meta));}catch(error){status.textContent='Lokaler Speicher voll. Bitte sichere den Lernstand über den Download am Seitenende.';}}
    function record(remote){meta.revision=remote.revision;meta.fingerprint=fingerprint(local.exportState());meta.updated_at=remote.updated_at;saveMeta();dirty=false;}
    function normalStatus(){status.textContent=readOnly?'Dieser Lernweg ist abgeschlossen. Weitere Änderungen bleiben nur auf diesem Gerät.':`Automatisch für ${assignment.teacher_name} und die zugewiesenen Lehrkräfte gespeichert${meta.updated_at?' · '+new Date(meta.updated_at*1000).toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit'}):''}. Texte, Markierungen und Begriffsnetze gehören zur Mitschrift.`;}
    function importRemote(remote){paused=true;local.importState(remote.payload);record(remote);paused=false;normalStatus();}
    function readable(payload){return Object.entries(payload.fields||{}).filter(([,v])=>String(v).trim()).map(([k,v])=>k+'\n'+v).join('\n\n')+'\n\nBegriffsnetze / Markierungen:\n'+JSON.stringify({conceptMaps:payload.conceptMaps,learningTools:payload.learningTools},null,2);}
    function conflict(remote){if(conflicting)return;conflicting=true;paused=true;dirty=true;status.textContent='Zwei Fassungen vorhanden. Bitte entscheide, mit welcher du weiterarbeitest.';
      const draft=local.exportState();try{localStorage.setItem(metaKey+':conflict',JSON.stringify({local:draft,remote}));}catch(error){}
      const conflictEpoch=epoch,dialog=el('dialog',undefined,{class:'work-dialog'}),columns=el('div',undefined,{class:'work-compare'}),buttons=el('div',undefined,{class:'work-actions'});scopedControls.add(dialog);
      dialog.append(el('h2','Auf einem anderen Gerät wurde ebenfalls gearbeitet.'),el('p','Beide Fassungen bleiben bis zur Entscheidung auf diesem Gerät gesichert. Prüfe die Unterschiede. Wenn du deine Fassung auf den Server übernimmst, bleibt die bisherige Serverfassung vorerst als Vorversion erhalten.'));
      for(const [label,payload] of [['Auf diesem Gerät',draft],['Auf dem Server',remote.payload]]){const column=el('section');column.append(el('h3',label),el('pre',readable(payload)));columns.append(column);}
      const remoteButton=el('button','Mit Serverfassung weiterarbeiten',{type:'button'}),localButton=el('button','Meine Gerätefassung übernehmen',{type:'button'}),later=el('button','Später entscheiden · nur lokal weiterarbeiten',{type:'button'});
      const resolve=()=>{conflicting=false;paused=false;try{localStorage.removeItem(metaKey+':conflict');}catch(error){}dialog.close();dialog.remove();};
      remoteButton.onclick=()=>{if(epoch!==conflictEpoch)return;importRemote(remote);resolve();};
      localButton.onclick=()=>{if(epoch!==conflictEpoch)return;meta.revision=remote.revision;saveMeta();resolve();dirty=true;flush();};
      later.onclick=()=>{if(epoch!==conflictEpoch)return;dialog.close();dialog.remove();const resume=el('button','Fassungen vergleichen',{type:'button'});scopedControls.add(resume);resume.onclick=()=>{if(epoch!==conflictEpoch)return;resume.remove();conflicting=false;conflict(remote);};actions.append(resume);};
      dialog.addEventListener('cancel',event=>{event.preventDefault();later.click();});buttons.append(remoteButton,localButton,later);dialog.append(columns,buttons);document.body.append(dialog);dialog.showModal();
    }
    async function flush(){clearTimeout(timer);if(paused||saving||!assignment||!dirty||readOnly)return;saving=true;const sentEpoch=epoch,draft=local.exportState(),sent=fingerprint(draft);status.textContent='Auf diesem Gerät gespeichert · wird synchronisiert …';
      try{const result=await request('save',{assignment_id:assignment.id,subject:session.subject,base_revision:Number(meta.revision||0),payload:draft},true,session.csrf);
        if(epoch!==sentEpoch)return;
        if(result.conflict){conflict(result.remote);return;}meta.revision=result.revision;meta.fingerprint=sent;meta.updated_at=result.updated_at;saveMeta();dirty=fingerprint(local.exportState())!==sent;
        if(!dirty)normalStatus();else status.textContent='Neue Änderungen werden noch synchronisiert …';
      }catch(error){if(epoch===sentEpoch)status.textContent=error.status===401||error.status===403?'Nur lokal gespeichert · '+error.message:'Nur lokal gespeichert · Die Verbindung fehlt. Bei bestehender Anmeldung wird später erneut synchronisiert.';}
      finally{saving=false;if(dirty&&!paused&&!readOnly)timer=setTimeout(flush,7000);}}
    function changed(){if(paused||!assignment)return;dirty=fingerprint(local.exportState())!==meta.fingerprint;if(!dirty)return;status.textContent=readOnly?'Abgeschlossen · neue Änderungen nur lokal gespeichert.':'Auf diesem Gerät gespeichert · Synchronisation folgt …';clearTimeout(timer);timer=setTimeout(flush,1100);}
    async function connect(selected){const readEpoch=++epoch;clearTimeout(timer);resetScopeControls();paused=true;connecting=true;assignment=selected;dirty=false;scope=session.subject+':'+selected.id;metaKey='religion:sync:'+slug+':'+scope;meta={};try{meta=JSON.parse(localStorage.getItem(metaKey)||'{}');if(!meta||typeof meta!=='object'||Array.isArray(meta))meta={};}catch(error){}
      local.setStorageScope(scope);readOnly=selected.status!=='active';title.textContent=`${session.name} · ${selected.class_label} · ${selected.label}`;
      if(selected.room_code)window.RELIGION_CLASSROOM?.setRoom(selected.room_code);else window.RELIGION_CLASSROOM?.clearRoom();
      window.RELIGION_COURSE_MATERIALS?.setAccess(selected.material_access_key||'');
      status.textContent='Persönlicher Serverstand wird geladen …';
      try{const remote=await request('read',{assignment_id:selected.id});if(epoch!==readEpoch)return;const draft=local.exportState();
        if(remote.payload){if(!meaningful(draft)||fingerprint(draft)===meta.fingerprint||fingerprint(draft)===fingerprint(remote.payload)){importRemote(remote);}else if(Number(meta.revision||0)!==remote.revision){conflict(remote);return;}}
        paused=false;dirty=fingerprint(local.exportState())!==meta.fingerprint;normalStatus();if(dirty&&!readOnly)flush();
      }catch(error){if(epoch!==readEpoch)return;paused=false;status.textContent='Serverstand konnte nicht geladen werden. Du kannst lokal weiterarbeiten; vor dem Synchronisieren wird der Serverstand erneut geprüft.';dirty=true;}
      finally{if(epoch===readEpoch)connecting=false;}
    }
    try{session=await request('session',{module_slug:slug});}catch(error){status.textContent='Lokales Arbeiten ohne Serververbindung. Melde dich in der Serverversion an, um deinen Stand zu synchronisieren.';return;}
    if(!session.authenticated){status.textContent='Ohne Anmeldung bleibt dein Lernstand nur auf diesem Gerät. Mit deinem persönlichen Zugang werden zugewiesene Lernwege automatisch gespeichert.';return;}
    if(session.kind!=='student'){status.textContent='Du bist als Lehrkraft angemeldet. Eigene Einträge auf dieser Schülerseite bleiben lokal.';return;}
    if(!session.learning_enabled){status.textContent='LernHTML ist für dein Konto nicht freigegeben. Du kannst diese Seite lokal bearbeiten.';return;}
    login.textContent='Mein Unterricht';login.href='/zugang/';
    const assignments=session.assignments||[];
    if(!assignments.length){status.textContent='Für diese Lerneinheit ist deinem Konto noch kein Unterricht zugewiesen. Deine Einträge bleiben lokal.';return;}
    const select=el('select',undefined,{'aria-label':'Zugewiesenen Unterricht auswählen'});select.append(el('option','Unterricht auswählen …',{value:''}));assignments.forEach(a=>select.append(el('option',`${a.class_label} · ${a.label}`,{value:a.id})));actions.prepend(select);
    select.onchange=async()=>{const chosen=assignments.find(a=>a.id===select.value);if(!chosen||connecting||chosen.id===assignment?.id){select.value=assignment?.id||'';return;}if(dirty&&!confirm('Es gibt noch nicht synchronisierte Änderungen. Sie bleiben in der lokalen Fassung dieses Unterrichts gespeichert. Unterricht wechseln?')){select.value=assignment?.id||'';return;}select.disabled=true;try{await connect(chosen);}finally{select.disabled=false;}};
    const wanted=new URLSearchParams(location.search).get('arbeit'),chosen=assignments.find(a=>a.id===wanted)||(!wanted&&assignments.length===1?assignments[0]:null);
    if(chosen){select.value=chosen.id;await connect(chosen);}else status.textContent=wanted?'Dieser zugewiesene Unterricht ist nicht für dein Konto verfügbar.':'Wähle den Unterricht. Bis dahin bleiben Einträge nur auf diesem Gerät.';
    if(meaningful(oldLocal)){const importButton=el('button','Bisherigen lokalen Stand übernehmen',{type:'button'});actions.append(importButton);importButton.onclick=()=>{if(!assignment||connecting||conflicting)return;if(!confirm('Nur übernehmen, wenn die bisherigen lokalen Einträge deine eigenen sind. Der aktuelle persönliche Stand wird dadurch ersetzt; vorhandene Serverfassungen bleiben vorerst in der Versionshistorie. Fortfahren?'))return;paused=true;local.importState(oldLocal);paused=false;dirty=true;changed();importButton.remove();};}
    const retry=el('button','Jetzt synchronisieren',{type:'button'});retry.onclick=()=>{dirty=fingerprint(local.exportState())!==meta.fingerprint;flush();};actions.append(retry);
    const historyButton=el('button','Vorversionen ansehen',{type:'button'});actions.append(historyButton);
    historyButton.onclick=async()=>{if(!assignment||connecting||saving||conflicting)return;const historyEpoch=epoch;let versionRequest=0;
      try{const latest=await request('read',{assignment_id:assignment.id});if(epoch!==historyEpoch)return;
        if(!latest.history?.length){status.textContent='Noch keine Vorversion vorhanden. Nach Änderungen bleiben bis zu drei vorherige Serverfassungen erhalten.';return;}
        const dialog=el('dialog',undefined,{class:'work-dialog'}),versions=el('select',undefined,{'aria-label':'Vorversion auswählen'}),preview=el('pre','Fassung wird geladen …'),restore=el('button','Diese Fassung wiederherstellen',{type:'button'}),close=el('button','Schließen',{type:'button'});let previous=null;
        scopedControls.add(dialog);latest.history.forEach(v=>versions.append(el('option',`Fassung ${v.revision} · ${new Date(v.updated_at*1000).toLocaleString('de-DE')}`,{value:v.revision})));
        dialog.append(el('h2','Vorherige Serverfassungen'),el('p','Die letzten drei Vorversionen helfen bei versehentlichen Änderungen. Eine Wiederherstellung wird als neue Fassung gespeichert.'),versions,preview,restore,close);document.body.append(dialog);restore.disabled=true;close.onclick=()=>{dialog.close();dialog.remove();};dialog.showModal();
        versions.onchange=async()=>{const turn=++versionRequest;restore.disabled=true;previous=null;try{const value=await request('read',{assignment_id:assignment.id,revision:versions.value});if(epoch!==historyEpoch||turn!==versionRequest||!dialog.open)return;previous=value;preview.textContent=readable(value.payload);restore.disabled=readOnly;}catch(error){if(dialog.open)preview.textContent=error.message;}};
        restore.onclick=()=>{if(epoch!==historyEpoch||!previous||saving||readOnly)return;if(!confirm('Diese Vorversion ersetzt deine jetzigen Eingaben. Noch nicht synchronisierte Änderungen würden dabei verloren gehen. Wiederherstellen?'))return;paused=true;local.importState(previous.payload);meta.revision=previous.current_revision;saveMeta();paused=false;dirty=true;dialog.close();dialog.remove();flush();};versions.onchange();
      }catch(error){if(epoch===historyEpoch)status.textContent='Vorversionen konnten nicht geladen werden: '+error.message;}
    };
    document.addEventListener('religion-learning-state-change',changed);document.addEventListener('religion-concept-map-change',changed);window.addEventListener('online',flush);
    window.addEventListener('beforeunload',event=>{if(dirty&&!readOnly){event.preventDefault();event.returnValue='';}});
    setInterval(async()=>{if(!assignment||saving||connecting)return;const checkedEpoch=epoch;try{const fresh=await request('session',{module_slug:slug});if(epoch!==checkedEpoch)return;if(!fresh.authenticated||fresh.subject!==session.subject||!fresh.learning_enabled){++epoch;paused=true;clearTimeout(timer);resetScopeControls();local.setStorageScope('');assignment=null;dirty=false;select.value='';select.disabled=true;title.textContent='Dein Lernstand';status.textContent='Anmeldung geändert oder abgelaufen. Persönliche Einträge wurden ausgeblendet; bitte neu anmelden.';return;}session=fresh;const found=fresh.assignments?.find(a=>a.id===assignment.id);if(!found){paused=true;status.textContent='Dieser Unterricht ist nicht mehr freigegeben. Die Gerätefassung bleibt lokal erhalten.';}else{readOnly=found.status!=='active';if(dirty)flush();}}catch(error){}},30000);
  }
  document.addEventListener('religion-learning-state-ready',start,{once:true});if(window.RELIGION_LEARNING_STATE)start();
})();
