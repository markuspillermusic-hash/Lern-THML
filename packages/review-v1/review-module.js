(function () {
  "use strict";

  var classroomConfig = window.RELIGION_CLASSROOM_CONFIG || {};
  var config = classroomConfig.review || {};
  var view = String(classroomConfig.view || window.RELIGION_VIEW || "student");
  var host = document.querySelector("[data-review-module]");
  if (!host || !Array.isArray(config.questions) || !config.questions.length) return;
  var questions = config.questions, sections = config.sections || [];
  var storageKey = String(config.storageKey || "religion-review-records-v1"), records = [];
  try { records = JSON.parse(localStorage.getItem(storageKey) || "[]"); if (!Array.isArray(records)) records = []; } catch (error) { records = []; }

  function esc(value) { return String(value == null ? "" : value).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;"); }
  function sectionTitle(id) { var item = sections.find(function (entry) { return entry.id === id; }); return item ? item.title : id; }
  function saveRecords() { try { localStorage.setItem(storageKey, JSON.stringify(records)); } catch (error) {} }
  function download(content, type, name) { var blob = new Blob([content], { type: type }), url = URL.createObjectURL(blob), link = document.createElement("a"); link.href = url; link.download = name; document.body.appendChild(link); link.click(); link.remove(); setTimeout(function () { URL.revokeObjectURL(url); }, 1000); }
  function csvCell(value) { var text = String(value == null ? "" : value); if (/^[=+\-@]/.test(text)) text = "'" + text; return '"' + text.replace(/"/g, '""') + '"'; }

  function renderAudience() {
    var isBeamer = view === "beamer";
    if (!isBeamer) {
      var section = host.closest("section");
      if (section) section.hidden = true;
      return;
    }
    host.classList.add("review-audience", isBeamer ? "review-beamer" : "review-student");
    host.innerHTML = '<div class="review-question-stage" data-review-stage><p class="review-kicker">Wiederholung und Abfrage</p><h2 data-review-title>Die Lehrkraft wählt die nächste Frage.</h2><p data-review-prompt>Auf dem Beamer erscheint immer nur die aktuell freigegebene Aufgabe.</p><p class="review-meta" data-review-meta></p></div><div class="review-hidden-controls" aria-hidden="true">' + questions.map(function (question) { return '<button type="button" tabindex="-1" data-classroom-control-group="review-question" data-classroom-control-value="' + esc(question.id) + '"></button>'; }).join("") + '</div>';
    host.querySelectorAll("[data-classroom-control-group='review-question']").forEach(function (button) {
      button.addEventListener("click", function () {
        var question = questions.find(function (item) { return item.id === button.dataset.classroomControlValue; });
        if (!question) return;
        host.querySelectorAll("[data-classroom-control-group='review-question']").forEach(function (item) { item.classList.toggle("active", item === button); item.setAttribute("aria-pressed", String(item === button)); });
        host.querySelector("[data-review-title]").textContent = "AFB " + question.afb + " · " + sectionTitle(question.section);
        host.querySelector("[data-review-prompt]").textContent = question.prompt;
        host.querySelector("[data-review-meta]").textContent = question.level === "ea" ? "Zusatzfrage für das erhöhte Anforderungsniveau" : "gA/eA";
      });
    });
  }

  function renderTeacher() {
    host.classList.add("review-teacher");
    host.innerHTML = '<div class="review-head"><div><p class="review-kicker">Lehreransicht · wiederverwendbares Abfragemodul</p><h2>Wiederholung und mündliche Abfrage vorbereiten</h2><p>Wähle die behandelten Abschnitte und anschließend die gewünschten Aufgaben. Alle Fragen eines gewählten Abschnitts sind zunächst aktiv. Die Beameransicht zeigt immer nur die aktuell aufgerufene Frage. Das Modul ist von der Schülerfreigabe entkoppelt.</p></div><span class="review-version">Modul v1.1</span></div><div class="review-layout"><section><h3>1 · Stoff und Aufgaben wählen</h3><div class="review-section-select" data-review-sections></div><div class="review-question-select" data-review-questions></div></section><aside class="review-control"><h3>2 · Frage steuern und bewerten</h3><div class="review-current" data-review-current><p>Noch keine Frage aufgerufen.</p></div><div class="review-nav"><button type="button" class="live-btn ghost" data-review-prev>Zurück</button><button type="button" class="live-btn" data-review-next>Nächste Frage</button></div><h4>Bewertungsbausteine</h4><p class="review-help">Bausteine markieren Stärken oder noch zu prüfende Aspekte. Die Note bleibt eine pädagogische Gesamtentscheidung.</p><div class="review-criteria" data-review-criteria></div><h4>Notenskala 1–6</h4><div class="review-grades" data-review-grades role="group" aria-label="Note auswählen">' + [1,2,3,4,5,6].map(function (grade) { return '<button type="button" data-review-grade="' + grade + '" aria-pressed="false" title="Note ' + grade + '">' + grade + '</button>'; }).join("") + '<button type="button" class="review-grade-reset" data-review-grade="" aria-pressed="true">ohne Note</button></div><label for="review-code">Pseudonym <input id="review-code" maxlength="18" placeholder="z. B. P-03"></label><label for="review-note">Notiz <textarea id="review-note" placeholder="Beobachtung, Nachfrage, nächster Lernschritt"></textarea></label><button type="button" class="live-btn" data-review-save>Beobachtung lokal sichern</button><p class="live-status" data-review-status aria-live="polite"></p></aside></div><details class="review-records"><summary>3 · Pseudonymisierte Beobachtungen</summary><div data-review-records></div><div class="review-nav"><button type="button" class="live-btn ghost" data-review-export-json>JSON sichern</button><button type="button" class="live-btn ghost" data-review-export-csv>CSV sichern</button><button type="button" class="live-btn warn" data-review-clear>Lokale Beobachtungen löschen</button></div></details>';

    var sectionBox = host.querySelector("[data-review-sections]"), questionBox = host.querySelector("[data-review-questions]"), currentBox = host.querySelector("[data-review-current]"), criteriaBox = host.querySelector("[data-review-criteria]"), gradeBox = host.querySelector("[data-review-grades]"), status = host.querySelector("[data-review-status]");
    var currentId = "", activeCriteria = [], activeGrade = "";
    sectionBox.innerHTML = sections.map(function (section) { return '<label><input type="checkbox" value="' + esc(section.id) + '" checked> <span>' + esc(section.short || section.title) + '</span></label>'; }).join("");

    function selectedSections() { return Array.from(sectionBox.querySelectorAll("input:checked")).map(function (input) { return input.value; }); }
    function checkedQuestions() { return Array.from(questionBox.querySelectorAll("input[data-review-question]:checked")).map(function (input) { return input.value; }); }
    function renderQuestionSelection(previous) {
      var selected = selectedSections(), level = document.body.dataset.level || "ga";
      questionBox.innerHTML = sections.filter(function (section) { return selected.indexOf(section.id) >= 0; }).map(function (section) {
        var items = questions.filter(function (question) { return question.section === section.id; });
        return '<details open><summary>' + esc(section.title) + '</summary><div>' + items.map(function (question) { var checked = previous ? previous.indexOf(question.id) >= 0 : question.level !== "ea" || level === "ea"; return '<label class="review-question-option"><input type="checkbox" data-review-question value="' + esc(question.id) + '" ' + (checked ? "checked" : "") + '> <span><b>AFB ' + esc(question.afb) + (question.level === "ea" ? " · eA" : "") + '</b> ' + esc(question.prompt) + '</span><button type="button" class="live-btn ghost" data-review-show="' + esc(question.id) + '">Am Beamer zeigen</button></label>'; }).join("") + '</div></details>';
      }).join("") || '<p class="review-empty">Mindestens einen Abschnitt wählen.</p>';
      questionBox.querySelectorAll("[data-review-show]").forEach(function (button) { button.addEventListener("click", function () { showQuestion(button.dataset.reviewShow); }); });
    }

    function showQuestion(id) {
      var question = questions.find(function (item) { return item.id === id; });
      if (!question) return;
      currentId = id; activeCriteria = []; activeGrade = "";
      questionBox.querySelectorAll("[data-review-show]").forEach(function (button) { var active = button.dataset.reviewShow === id; button.classList.toggle("active", active); button.setAttribute("aria-pressed", String(active)); });
      currentBox.innerHTML = '<p class="review-meta">AFB ' + esc(question.afb) + ' · ' + esc(sectionTitle(question.section)) + (question.level === "ea" ? " · eA" : "") + '</p><p class="review-prompt">' + esc(question.prompt) + '</p>' + (question.followup ? '<p><b>Mögliche Nachfrage:</b> ' + esc(question.followup) + '</p>' : "");
      criteriaBox.innerHTML = (question.criteria || []).map(function (criterion, index) { return '<button type="button" data-review-criterion="' + index + '" aria-pressed="false">' + esc(criterion) + '</button>'; }).join("");
      criteriaBox.querySelectorAll("[data-review-criterion]").forEach(function (button) { button.addEventListener("click", function () { var value = (question.criteria || [])[Number(button.dataset.reviewCriterion)], active = activeCriteria.indexOf(value) >= 0; if (active) activeCriteria = activeCriteria.filter(function (item) { return item !== value; }); else activeCriteria.push(value); button.classList.toggle("active", !active); button.setAttribute("aria-pressed", String(!active)); }); });
      gradeBox.querySelectorAll("[data-review-grade]").forEach(function (button) { var active = button.dataset.reviewGrade === ""; button.classList.toggle("active", active); button.setAttribute("aria-pressed", String(active)); });
      if (window.RELIGION_PRESENTATION && typeof window.RELIGION_PRESENTATION.send === "function") {
        window.RELIGION_PRESENTATION.send({ type: "control", payload: { group: "review-question", value: id, focus: "abfrage-modul" } }).catch(function () {});
      }
      status.textContent = "Frage am Beamer freigegeben.";
    }

    function move(delta) {
      var ids = checkedQuestions(); if (!ids.length) { status.textContent = "Zuerst mindestens eine Aufgabe aktivieren."; return; }
      var index = ids.indexOf(currentId); if (index < 0) index = delta > 0 ? -1 : 0;
      var next = ids[(index + delta + ids.length) % ids.length], button = questionBox.querySelector('[data-review-show="' + CSS.escape(next) + '"]');
      if (button) button.click(); else showQuestion(next);
    }

    function renderRecords() {
      var box = host.querySelector("[data-review-records]");
      box.innerHTML = records.length ? records.slice().reverse().map(function (record) { return '<article class="review-record"><p><b>' + esc(record.pseudonym) + '</b> · AFB ' + esc(record.afb) + ' · ' + esc(record.section) + ' · <b>Note ' + esc(record.grade || "–") + '</b></p><p>' + esc(record.prompt) + '</p><p><b>Markiert:</b> ' + esc((record.criteria || []).join(" · ") || "keine Bausteine") + '</p><p><b>Notiz:</b> ' + esc(record.note || "–") + '</p></article>'; }).join("") : '<p class="review-empty">Noch keine Beobachtung lokal gesichert.</p>';
    }

    sectionBox.addEventListener("change", function () { renderQuestionSelection(); });
    host.querySelector("[data-review-prev]").addEventListener("click", function () { move(-1); });
    host.querySelector("[data-review-next]").addEventListener("click", function () { move(1); });
    gradeBox.querySelectorAll("[data-review-grade]").forEach(function (button) { button.addEventListener("click", function () { activeGrade = button.dataset.reviewGrade; gradeBox.querySelectorAll("[data-review-grade]").forEach(function (item) { var active = item === button; item.classList.toggle("active", active); item.setAttribute("aria-pressed", String(active)); }); }); });
    host.querySelector("[data-review-save]").addEventListener("click", function () {
      var question = questions.find(function (item) { return item.id === currentId; }), pseudonym = host.querySelector("#review-code").value.trim().replace(/[^a-zA-Z0-9ÄÖÜäöü_-]/g, "").slice(0, 18), note = host.querySelector("#review-note").value.trim();
      if (!question) { status.textContent = "Zuerst eine Frage aufrufen."; return; }
      if (!pseudonym) { status.textContent = "Bitte ein neutrales Pseudonym wie P-03 eintragen."; return; }
      records.push({ savedAt: new Date().toISOString(), pseudonym: pseudonym, questionId: question.id, section: sectionTitle(question.section), afb: question.afb, prompt: question.prompt, criteria: activeCriteria.slice(), grade: activeGrade, note: note });
      saveRecords(); renderRecords(); status.textContent = "Beobachtung ausschließlich auf diesem Gerät gesichert."; host.querySelector("#review-note").value = "";
    });
    host.querySelector("[data-review-export-json]").addEventListener("click", function () { download(JSON.stringify({ schema: "religion-review-observations-1", exportedAt: new Date().toISOString(), records: records }, null, 2), "application/json;charset=utf-8", "abfrage-beobachtungen-" + new Date().toISOString().slice(0, 10) + ".json"); });
    host.querySelector("[data-review-export-csv]").addEventListener("click", function () { var rows = [["Zeit", "Pseudonym", "Abschnitt", "AFB", "Frage", "Bausteine", "Note", "Notiz"]]; records.forEach(function (record) { rows.push([record.savedAt, record.pseudonym, record.section, record.afb, record.prompt, (record.criteria || []).join(" | "), record.grade || "", record.note]); }); download("\uFEFF" + rows.map(function (row) { return row.map(csvCell).join(";"); }).join("\r\n"), "text/csv;charset=utf-8", "abfrage-beobachtungen-" + new Date().toISOString().slice(0, 10) + ".csv"); });
    host.querySelector("[data-review-clear]").addEventListener("click", function () { if (records.length && confirm("Alle lokal gesicherten Beobachtungen löschen?")) { records = []; saveRecords(); renderRecords(); } });
    renderQuestionSelection(); renderRecords();
  }

  if (view === "teacher") renderTeacher(); else renderAudience();
}());
