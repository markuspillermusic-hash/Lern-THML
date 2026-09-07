/* One teacher-facing start flow; the existing classroom engine remains authoritative. */
(() => {
  'use strict';
  window.LearningTeachingEntry={install:async({target,slug,cfg,request})=>{
    const el=window.LearningWorkRenderer.node,core=window.RELIGION_CLASSROOM;
    const host=el('section',undefined,{class:'learning-sync-bar teaching-entry','aria-label':'Unterricht starten'});
    const status=el('p','Klassen werden geladen …',{role:'status','aria-live':'polite'});
    host.append(el('h3','Unterricht starten'),status);target.prepend(host);
    if(location.protocol==='file:'){status.textContent='Klasse und Live-Unterricht stehen in der Serverversion zur Verfügung.';return;}
    let session,busy=false,requestId='',lastSelection='';
    try{session=await request('session',{module_slug:slug});}catch(error){status.textContent=error.message+' Bitte die Seite neu laden.';return;}
    if(!session.authenticated||session.kind!=='teacher'||!session.learning_enabled||!core?.startTeaching){status.textContent='Bitte als Lehrkraft im gemeinsamen Zugang anmelden und die Seite neu laden.';return;}
    const temporaryKey='religion:teaching-start:'+slug+':'+session.teacher_user_id;
    function rememberTemporary(result,id){try{
      if(result.teaching.mode==='temporary')sessionStorage.setItem(temporaryKey,JSON.stringify({room:core.room(),requestId:id,expiresAt:Number(core.state()?.expiresAt||0)}));
      else sessionStorage.removeItem(temporaryKey);
    }catch(error){/* Without session storage the explicit room controls remain available. */}}
    const select=el('select',undefined,{id:'teaching-class','aria-label':'Klasse auswählen'});
    select.append(el('option','Bitte auswählen …',{value:''}),el('option','Ohne Klasse · temporärer Unterricht',{value:'temporary'}));
    (session.classes||[]).filter(c=>c.status==='active'&&c.can_teach).forEach(c=>select.append(el('option',`${c.label} · ${c.school_year}`,{value:c.id})));
    const classLabel=el('label','Für wen?',{for:'teaching-class'});classLabel.append(select);
    const assignmentSelect=el('select',undefined,{id:'teaching-assignment','aria-label':'Unterricht fortsetzen'}),assignmentLabel=el('label','Welchen Verlauf fortsetzen?',{for:'teaching-assignment',hidden:''});assignmentLabel.append(assignmentSelect);
    const begin=el('button','Unterricht starten',{type:'button'}),notes=el('button','Schülerstände öffnen',{type:'button'}),actions=el('div',undefined,{class:'work-actions'});
    begin.disabled=true;notes.disabled=true;actions.append(begin,notes,el('a','Klasse anlegen oder verwalten',{href:'/zugang/?view=classes',target:'_blank',rel:'noopener'}));
    const help=el('p','Wähle eine Klasse für persönliche Mitschriften oder starte ohne Klasse mit QR-Code.',{class:'work-muted'});
    const options=el('details'),retention=el('select',undefined,{'aria-label':'Aufbewahrung neuer Mitschriften'});
    [[365,'1 Jahr'],[180,'6 Monate'],[90,'3 Monate'],[30,'30 Tage']].forEach(([value,label])=>retention.append(el('option',label,{value:String(value)})));
    retention.value='365';const retentionLabel=el('label','Neue Mitschriften aufbewahren:');retentionLabel.append(retention);
    options.append(el('summary','Weitere Optionen'),retentionLabel,el('p','Gilt nur für neu angelegte Unterrichtsverläufe. Fortsetzen ändert keine bestehende Speicherfrist.'));
    host.append(classLabel,assignmentLabel,help,actions,options);
    const manager=target.querySelector('.live-classroom-manager')||document.querySelector('.live-classroom-manager');
    if(manager){
      const oldCreate=manager.querySelector('.live-room-create');if(oldCreate)options.append(oldCreate);
      const prepared=manager.querySelector('.live-saved-room-panel');if(prepared)options.append(prepared);
      const heading=manager.querySelector('#live-classroom-title');if(heading)heading.textContent='Unterrichtssteuerung';
      const intro=manager.querySelector('.live-stage-head p:last-child');if(intro)intro.textContent='Freigaben, Abstimmungen, Timer und Beamer gehören zum oben gestarteten Unterricht.';
      manager.classList.add('has-teaching-entry');
    }
    function candidates(){return(session.assignments||[]).filter(a=>a.class_id===select.value&&a.status==='active'&&Number(a.created_by)===Number(session.teacher_user_id));}
    function updateSelection(){
      retentionLabel.hidden=select.value==='temporary';
      const matches=candidates();assignmentSelect.replaceChildren();assignmentLabel.hidden=matches.length<2;
      if(matches.length>1)assignmentSelect.append(el('option','Verlauf auswählen …',{value:''}));
      matches.forEach(a=>assignmentSelect.append(el('option',a.label,{value:a.id})));
      if(matches.length===1)assignmentSelect.value=matches[0].id;
      begin.disabled=!select.value||(matches.length>1&&!assignmentSelect.value);
      begin.textContent=matches.length?'Unterricht fortsetzen':'Unterricht starten';
      help.textContent=select.value==='temporary'?'Beitritt über QR-Code oder Raumcode. Keine persönliche Server-Mitschrift.':select.value?'Angemeldete Schüler öffnen den zugewiesenen Lernweg in „Mein Unterricht“. Ihre Mitschriften werden für die zuständigen Lehrkräfte gespeichert.':'Wähle eine Klasse oder „Ohne Klasse“.';
      requestId='';lastSelection='';
    }
    select.onchange=updateSelection;assignmentSelect.onchange=()=>{begin.disabled=!assignmentSelect.value;requestId='';lastSelection='';};
    function renderActive(){const context=core.teaching?.();notes.disabled=!context?.assignment_id;
      if(manager){manager.dataset.teachingMode=context?.mode||'';const label=manager.querySelector('.live-room-ready>.live-inline-eyebrow');if(label)label.textContent='Aktiver Unterricht';}
      if(busy)return;
      status.textContent=context?.mode==='class'?`Aktiv: ${context.class_label} · ${context.label}`:context?.mode==='temporary'?'Temporärer Unterricht aktiv · Beitritt über QR-Code oder Raumcode.':core.room()?'Ein bestehender Raum ist geöffnet. Wähle oben die Klasse, deren Unterricht du fortsetzen möchtest.':'Noch kein Unterricht gestartet.';
      if(manager&&context?.mode==='class')queueMicrotask(()=>{const message=manager.querySelector('#live-room-message');if(message&&core.teaching()?.assignment_id===context.assignment_id)message.textContent='Die Klasse öffnet den Lernweg im persönlichen Zugang. Ein zusätzlicher Raumcode ist nicht nötig.';});
    }
    begin.onclick=async()=>{
      if(busy||begin.disabled)return;
      const selected=select.value,mode=selected==='temporary'?'temporary':'class',assignment=assignmentSelect.value||'';
      const selection=selected+'|'+assignment;
      if(!requestId||lastSelection!==selection){requestId=Array.from(crypto.getRandomValues(new Uint8Array(16)),n=>n.toString(16).padStart(2,'0')).join('');lastSelection=selection;}
      busy=true;begin.disabled=true;select.disabled=true;assignmentSelect.disabled=true;status.textContent='Unterricht wird geöffnet …';
      try{
        const usedRequest=requestId,result=await core.startTeaching({mode,class_id:mode==='class'?selected:'',assignment_id:mode==='class'?assignment:'',request_id:requestId,csrf:session.csrf,label:cfg.moduleLabel||document.title,retention_days:Number(retention.value)});
        rememberTemporary(result,usedRequest);
        // A lost response retains the request key. A successful new start refreshes the candidates.
        session=await request('session',{module_slug:slug});
        updateSelection();if(result.teaching.assignment_id)assignmentSelect.value=result.teaching.assignment_id;
        if(mode==='temporary'){requestId=usedRequest;lastSelection=selection;}
        begin.textContent='Unterricht fortsetzen';
      }catch(error){status.textContent=error.message+' Es wurde nicht auf Unterricht ohne Klasse umgeschaltet.';return;}
      finally{busy=false;select.disabled=false;assignmentSelect.disabled=false;begin.disabled=!select.value||(candidates().length>1&&!assignmentSelect.value);}
      renderActive();
    };
    notes.onclick=()=>{const context=core.teaching?.();if(!context?.assignment_id)return;
      const dialog=el('dialog',undefined,{class:'work-dialog work-inspector-dialog'}),close=el('button','Schließen',{type:'button'}),frame=el('iframe',undefined,{title:'Schülerstände dieses Unterrichts',src:'/zugang/arbeiten/?'+new URLSearchParams({module:slug,room:core.room(),assignment:context.assignment_id})});
      frame.style.cssText='width:100%;height:72vh;border:0';dialog.append(close,frame);document.body.append(dialog);close.onclick=()=>dialog.close();dialog.addEventListener('close',()=>dialog.remove());dialog.showModal();
    };
    const active=(session.assignments||[]).find(a=>a.room_code&&a.room_code===core.room()&&Number(a.created_by)===Number(session.teacher_user_id));
    if(active){select.value=active.class_id;updateSelection();assignmentSelect.value=active.id;core.restoreTeachingContext?.({room:active.room_code,mode:'class',assignment_id:active.id,class_id:active.class_id,class_label:active.class_label,label:active.label});}
    else try{
      const previous=JSON.parse(sessionStorage.getItem(temporaryKey)||'null');
      if(previous&&previous.room===core.room()&&previous.expiresAt>Date.now()/1000&&/^[a-f0-9]{32}$/.test(previous.requestId)){
        select.value='temporary';updateSelection();requestId=previous.requestId;lastSelection='temporary|';begin.textContent='Unterricht fortsetzen';
        core.restoreTeachingContext?.({room:previous.room,mode:'temporary',assignment_id:null,class_id:null,class_label:null,label:'Ohne Klasse'});
      }
    }catch(error){/* A malformed local hint never starts an anonymous room. */}
    document.addEventListener('religion-classroom-state',renderActive);renderActive();
  }};
})();
