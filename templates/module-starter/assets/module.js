(function(){
  "use strict";
  document.querySelectorAll("textarea[data-local-key]").forEach(function(field){
    var key="lernhhtml:"+(window.RELIGION_CLASSROOM_CONFIG.moduleId||"module")+":"+field.dataset.localKey;
    try{field.value=localStorage.getItem(key)||"";}catch(e){}
    field.addEventListener("input",function(){try{localStorage.setItem(key,field.value);}catch(e){}});
  });
  window.RELIGION_GET_CLASS_CHECK_POLLS=function(){return [{id:"staerkstes-indiz",q:"Welcher Zusammenhang erklärt Spannung am besten?",o:["Ein einzelnes Mittel wirkt immer gleich","Erkannte Erwartung und ihre Behandlung wirken zusammen","Nur Lautstärke erzeugt Spannung"]}];};
})();
