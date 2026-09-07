const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../classroom-core.js'),'utf8');
function load(view,storage,search='',tabStorage=new Map()){
  const window={RELIGION_CLASSROOM_CONFIG:{moduleId:'qa-room',roomStorageKey:'qa-room-key',view}};
  const document={title:'QA',body:{classList:{contains:()=>false}},getElementById:()=>null};
  const localStorage={getItem:k=>storage.get(k)??null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)};
  // No fetch: initialise the real public storage API without starting browser widgets.
  const sessionStorage={getItem:k=>tabStorage.get(k)??null,setItem:(k,v)=>tabStorage.set(k,v),removeItem:k=>tabStorage.delete(k)};
  vm.runInNewContext(source,{window,document,localStorage,sessionStorage,URLSearchParams,location:{search,pathname:'/qa/',hash:''},history:{replaceState:()=>{}}});
  return window.RELIGION_CLASSROOM;
}
test('beamer URL and later room writes cannot overwrite the teacher room',()=>{
  const storage=new Map();const teacher=load('teacher',storage);teacher.setRoom('ABC234');
  const screen=load('beamer',storage,'?room=DEF567');assert.equal(screen.room(),'DEF567');
  screen.setRoom('GHI789');assert.equal(load('teacher',storage).room(),'ABC234');
  assert.equal(load('beamer',storage).room(),'GHI789');screen.clearRoom();assert.equal(load('teacher',storage).room(),'ABC234');
});
test('a first beamer still discovers the existing teacher room without migrating or deleting it',()=>{
  const storage=new Map([['qa-room-key','ABC234']]);
  assert.equal(load('beamer',storage).room(),'ABC234');assert.equal(storage.get('qa-room-key'),'ABC234');
  assert.equal(load('student',storage).room(),'ABC234');
});
test('two teacher tabs retain their own room across reloads despite a shared browser profile',()=>{
  const shared=new Map(),firstTab=new Map(),secondTab=new Map();
  load('teacher',shared,'',firstTab).setRoom('ABC234');load('teacher',shared,'',secondTab).setRoom('DEF567');
  assert.equal(load('teacher',shared,'',firstTab).room(),'ABC234');
  assert.equal(load('teacher',shared,'',secondTab).room(),'DEF567');
  assert.equal(load('teacher',shared).room(),'DEF567');
  load('teacher',shared,'',firstTab).clearRoom();load('teacher',shared,'',secondTab).setRoom('DEF567');
  assert.equal(load('teacher',shared,'',firstTab).room(),'');
});
