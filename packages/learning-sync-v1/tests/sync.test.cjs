/* Deterministic transport races. This runs the real adapter in a synthetic DOM. */
const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
const code=fs.readFileSync(path.join(__dirname,'../learning-sync.js'),'utf8');
const clone=v=>JSON.parse(JSON.stringify(v));
const tick=()=>new Promise(resolve=>setImmediate(resolve));
class Element{
  constructor(tag,text,attrs={}){this.tag=tag;this.textContent=text||'';this.attrs=attrs;this.children=[];this.style={};this.handlers={};this.value=attrs.value||'';}
  append(...nodes){this.children.push(...nodes);}prepend(...nodes){this.children.unshift(...nodes);}replaceChildren(...nodes){this.children=nodes;}
  setAttribute(k,v){this.attrs[k]=v;}addEventListener(k,fn){this.handlers[k]=fn;}remove(){this.removed=true;}showModal(){this.open=true;}close(){this.open=false;}
}
async function harness(){
  const nodes=[],main=new Element('main'),events={},intervals=[],storage=new Map(),scopes=new Map(),pending=[],posts=[];
  let scope='',state={version:2,moduleId:'qa',fields:{},ui:{}},confirmResult=true;
  const local={exportState:()=>clone(state),importState:p=>{state=clone(p);scopes.set(scope,clone(state));},setStorageScope:s=>{scopes.set(scope,clone(state));scope=s;state=clone(scopes.get(s)||{version:2,moduleId:'qa',fields:{},ui:{}});}};
  const session={authenticated:true,kind:'student',learning_enabled:true,subject:'qa-student',name:'QA',csrf:'test',assignments:['A','B'].map(id=>({id,status:'active',teacher_name:'QA Lehrkraft',class_label:'QA Klasse',label:id}))};
  const node=(...args)=>{const n=new Element(...args);nodes.push(n);return n;};
  const window={RELIGION_CLASSROOM_CONFIG:{moduleSlug:'qa',view:'student'},RELIGION_LEARNING_STATE:local,LearningWorkRenderer:{node},addEventListener:()=>{}};
  const document={querySelector:s=>s==='main'?main:null,body:new Element('body'),addEventListener:(k,fn)=>{events[k]=fn;}};
  async function fetch(url,options){const input=options.method==='POST'?JSON.parse(options.body):Object.fromEntries(new URL(url,'https://qa.invalid').searchParams);
    if(input.action==='session')return{ok:true,json:async()=>clone(session)};
    if(input.action==='read')return{ok:true,json:async()=>({revision:input.assignment_id==='A'?2:5,updated_at:1,payload:{version:2,moduleId:'qa',fields:{answer:input.assignment_id+' remote'},ui:{}}})};
    if(input.action==='save'){posts.push(input);if(input.assignment_id==='A')return new Promise(resolve=>pending.push(value=>resolve({ok:true,json:async()=>value})));return{ok:true,json:async()=>({revision:6,updated_at:2})};}
    throw Error('Unexpected request');
  }
  vm.runInNewContext(code,{window,document,fetch,URLSearchParams,location:{protocol:'https:',pathname:'/qa/',search:'?arbeit=A'},localStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},setTimeout:()=>1,clearTimeout:()=>{},setInterval:fn=>intervals.push(fn),confirm:()=>confirmResult,console});
  await tick();await tick();
  return{nodes,storage,session,intervals,pending,posts,local,get scope(){return scope;},edit:v=>{state.fields.answer=v;events['religion-learning-state-change']();},confirm:v=>confirmResult=v};
}
test('late save from assignment A never changes revision or fields of assignment B',async()=>{
  const h=await harness(),select=h.nodes.find(n=>n.tag==='select'),sync=h.nodes.find(n=>n.textContent==='Jetzt synchronisieren');
  h.edit('A local change');sync.onclick();await tick();assert.equal(h.posts[0].assignment_id,'A');
  select.value='B';await select.onchange();assert.equal(h.local.exportState().fields.answer,'B remote');
  h.pending.shift()({revision:3,updated_at:2});await tick();
  assert.equal(JSON.parse(h.storage.get('religion:sync:qa:qa-student:B')).revision,5);
  h.edit('B local change');sync.onclick();await tick();assert.equal(h.posts[1].assignment_id,'B');assert.equal(h.posts[1].base_revision,5);
});
test('cancelled or empty assignment selection keeps the visible selector aligned with its storage scope',async()=>{
  const h=await harness(),select=h.nodes.find(n=>n.tag==='select');h.edit('unfinished');h.confirm(false);select.value='B';await select.onchange();assert.equal(select.value,'A');assert.equal(h.scope,'qa-student:A');
  select.value='';await select.onchange();assert.equal(select.value,'A');
});
test('expired account hides personal state and disables stale assignment controls',async()=>{
  const h=await harness(),select=h.nodes.find(n=>n.tag==='select');h.session.authenticated=false;await h.intervals[0]();assert.equal(h.scope,'');assert.equal(select.disabled,true);assert.deepEqual(h.local.exportState().fields,{});
});
