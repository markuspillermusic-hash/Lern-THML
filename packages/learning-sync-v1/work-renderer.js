/* Read-only rendering shared by the teacher inspector and the classroom screen. */
(() => {
  'use strict';
  const node=(tag,text,attrs={})=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;Object.entries(attrs).forEach(([k,v])=>el.setAttribute(k,v));return el;};
  const svg=(tag,attrs={})=>{const el=document.createElementNS('http://www.w3.org/2000/svg',tag);Object.entries(attrs).forEach(([k,v])=>el.setAttribute(k,v));return el;};
  const colors={brass:['#fff0b6','#3f3100'],rose:['#ffe0e4','#571b2a'],sage:['#d8f0e7','#173e35'],blue:['#dceafb','#1f3657'],paper:['#f7f6f0','#293337']};
  function wrapped(text,width=32,max=6){const lines=[];let line='';for(const word of String(text||'').split(/\s+/)){if(line.length+word.length>width&&line){lines.push(line);line='';}line+=(line?' ':'')+word;}if(line)lines.push(line);return lines.slice(0,max).map((v,i)=>i===max-1&&lines.length>max?v+' …':v);}
  function renderMap(host,payload,config,fields={}) {
    const state=payload?.state||payload||{},items=config?.nodes||[];
    const positions=new Map((state.nodes||[]).map(n=>[n.id,n]));
    const coordinate=(value,fallback)=>value!==undefined&&Number.isFinite(Number(value))?Number(value):fallback;
    const cards=items.map((n,i)=>({...n,...positions.get(n.id),x:coordinate(positions.get(n.id)?.x,i%3*300),y:coordinate(positions.get(n.id)?.y,Math.floor(i/3)*260)}));
    if(!cards.length){host.append(node('p','Noch kein Begriffsnetz vorhanden.'));return;}
    const minX=Math.min(...cards.map(n=>n.x))-20,minY=Math.min(...cards.map(n=>n.y))-30,maxX=Math.max(...cards.map(n=>n.x))+270,maxY=Math.max(...cards.map(n=>n.y))+210;
    const root=svg('svg',{viewBox:`${minX} ${minY} ${maxX-minX} ${maxY-minY}`,role:'img','aria-label':'Begriffsnetz der ausgewählten Schülerlösung',class:'work-map'});
    const markerId='work-arrow-'+Math.random().toString(36).slice(2),defs=svg('defs'),marker=svg('marker',{id:markerId,viewBox:'0 0 10 10',refX:9,refY:5,markerWidth:7,markerHeight:7,orient:'auto-start-reverse'});
    marker.append(svg('path',{d:'M 0 0 L 10 5 L 0 10 z',fill:'currentColor'}));defs.append(marker);root.append(defs);
    const labels=[];
    for(const edge of state.edges||[]){const from=cards.find(n=>n.id===edge.from),to=cards.find(n=>n.id===edge.to);if(!from||!to)continue;
      const sx=from.x+125,sy=from.y+95,tx=to.x+125,ty=to.y+95,dx=tx-sx,dy=ty-sy;
      const factor=Math.min(125/Math.max(1,Math.abs(dx)),95/Math.max(1,Math.abs(dy)));
      const x1=sx+dx*factor,y1=sy+dy*factor,x2=tx-dx*factor,y2=ty-dy*factor;
      root.append(svg('line',{x1,y1,x2,y2,stroke:'currentColor','stroke-width':2,'marker-end':`url(#${markerId})`}));
      labels.push({x:(x1+x2)/2,y:(y1+y2)/2,label:edge.label});
    }
    for(const card of cards){const group=svg('g'),[bg,fg]=colors[card.color]||colors.paper;
      group.append(svg('rect',{x:card.x,y:card.y,width:250,height:190,rx:10,fill:bg,stroke:fg,'stroke-width':1.5}));
      const title=svg('text',{x:card.x+15,y:card.y+29,fill:fg,'font-weight':700,'font-size':20});title.textContent=card.title;group.append(title);
      wrapped(fields[card.sourceField]||'Noch keine Definition',32,7).forEach((line,i)=>{const text=svg('text',{x:card.x+15,y:card.y+56+i*17,fill:fg,'font-size':13});text.textContent=line;group.append(text);});root.append(group);
    }
    for(const label of labels){const group=svg('g'),text=svg('text',{x:label.x,y:label.y,'text-anchor':'middle','font-size':13,'font-weight':600,fill:'currentColor',stroke:'var(--work-surface)','stroke-width':5,'paint-order':'stroke'});text.textContent=label.label;group.append(text);root.append(group);}
    host.append(root);
    const relations=node('details'),list=node('ol');relations.append(node('summary','Beziehungen und Begründungen lesen'));
    for(const edge of state.edges||[]){const from=items.find(n=>n.id===edge.from),to=items.find(n=>n.id===edge.to);list.append(node('li',`${from?.title||edge.from} ${edge.label||'→'} ${to?.title||edge.to}${edge.reason?' — '+edge.reason:''}`));}relations.append(list);host.append(relations);
  }
  function renderText(host,text,entries=[]) {
    text=String(text||'');const cuts=new Set([0,text.length]);for(const e of entries){cuts.add(Math.max(0,Math.min(text.length,e.start)));cuts.add(Math.max(0,Math.min(text.length,e.end)));}
    const sorted=[...cuts].sort((a,b)=>a-b);const p=node('div',undefined,{class:'work-source'});
    sorted.slice(0,-1).forEach((start,i)=>{const end=sorted[i+1],active=entries.find(e=>e.start<=start&&e.end>=end);p.append(active?node('mark',text.slice(start,end),{'data-color':active.color}):document.createTextNode(text.slice(start,end)));});host.append(p);
  }
  function renderDrawing(host,drawing) {
    const canvas=node('canvas',undefined,{width:1200,height:700,role:'img','aria-label':'Gespeicherte Zeichnung',class:'work-drawing'}),ctx=canvas.getContext('2d');host.append(canvas);
    for(const stroke of drawing?.strokes||[]){ctx.save();ctx.globalCompositeOperation=stroke.mode==='eraser'?'destination-out':'source-over';ctx.globalAlpha=stroke.mode==='marker'?.32:1;ctx.strokeStyle=stroke.color;ctx.lineWidth=stroke.width*(stroke.mode==='marker'?3:stroke.mode==='eraser'?4:1);ctx.lineCap='round';ctx.lineJoin='round';ctx.beginPath();stroke.points.forEach((p,i)=>{if(i===0)ctx.moveTo(p.x*1200,p.y*700);else ctx.lineTo(p.x*1200,p.y*700);});ctx.stroke();ctx.restore();}
  }
  window.LearningWorkRenderer={node,renderMap,renderText,renderDrawing};
})();
