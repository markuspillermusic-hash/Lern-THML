(function () {
  "use strict";

  var config = window.RELIGION_LEARNING_STATE_CONFIG || {};
  var labels = config.labels || {};

  function esc(value) {
    return String(value == null ? "" : value).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }

  function valueText(value) {
    if (value == null) return "";
    if (typeof value === "boolean") return value ? "ja" : "nein";
    if (Array.isArray(value)) return value.map(valueText).filter(Boolean).join(" | ");
    if (typeof value === "object") return Object.keys(value).map(function (key) { return key + ": " + valueText(value[key]); }).filter(function (item) { return !/:\s*$/.test(item); }).join(" | ");
    return String(value).trim();
  }

  function normalizeState(payload) {
    var state = payload && payload.state && typeof payload.state === "object" ? payload.state : payload;
    if (!state || typeof state !== "object" || Array.isArray(state)) throw new Error("Kein unterstützter Lernstand");
    if (Number(state.version || state._formatVersion || payload.version || payload._formatVersion || 0) !== 2) throw new Error("Nicht unterstützte Lernstandsversion");
    var fields = state.fields && typeof state.fields === "object" ? state.fields : state;
    var rows = Object.keys(fields).map(function (key) {
      return { key: key, label: labels[key] || key, value: valueText(fields[key]) };
    }).filter(function (row) {
      return row.value !== "" && row.key.charAt(0) !== "_" && row.key.indexOf("hand-") !== 0 && row.value.indexOf("data:image/") !== 0;
    });
    var handwriting = Object.keys(fields).filter(function (key) { return key.indexOf("hand-") === 0 && /^data:image\//.test(String(fields[key] || "")); }).length;
    return { rows: rows, handwriting: handwriting, exportedAt: payload.exportedAt || state.exportedAt || state._lastExportAt || state._savedAt || "" };
  }

  function payloadFromText(text) {
    var clean = text.replace(/^\uFEFF/, "").trim();
    if (!clean) throw new Error("Datei ist leer");
    if (clean.charAt(0) === "{") return JSON.parse(clean);
    var documentNode = new DOMParser().parseFromString(clean, "text/html");
    var embedded = documentNode.querySelector('script#lp-daten[type="application/json"]');
    if (!embedded) throw new Error("Kein maschinenlesbarer Lernstand gefunden");
    return JSON.parse(String(embedded.textContent || "").replace(/\\u003c/g, "<"));
  }

  function parseFile(file, index) {
    return file.text().then(function (text) {
      var payload = payloadFromText(text);
      var normalized = normalizeState(payload);
      normalized.pseudonym = "Lernstand " + String(index + 1).padStart(2, "0");
      return normalized;
    });
  }

  function dateText(value) {
    if (!value) return "–";
    var date = new Date(value);
    return isNaN(date.getTime()) ? String(value) : date.toLocaleString("de-DE");
  }

  function csvCell(value) {
    var text = String(value == null ? "" : value);
    if (/^[=+\-@]/.test(text)) text = "'" + text;
    return '"' + text.replace(/"/g, '""') + '"';
  }

  function download(content, type, name) {
    var blob = new Blob([content], { type: type }), url = URL.createObjectURL(blob), link = document.createElement("a");
    link.href = url; link.download = name; document.body.appendChild(link); link.click(); link.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }

  function aggregate(items) {
    var map = {};
    items.forEach(function (item) {
      item.rows.forEach(function (row) {
        if (!map[row.key]) map[row.key] = { key: row.key, label: row.label, complete: 0, samples: [] };
        map[row.key].complete += 1;
        if (map[row.key].samples.length < 3) map[row.key].samples.push({ pseudonym: item.pseudonym, value: row.value });
      });
    });
    return Object.keys(map).map(function (key) { return map[key]; }).sort(function (a, b) { return a.label.localeCompare(b.label, "de"); });
  }

  function render(host, items, errors) {
    var summary = host.querySelector("[data-learning-state-summary]"), results = host.querySelector("[data-learning-state-results]"), csv = host.querySelector("[data-learning-state-csv]");
    summary.textContent = items.length + " Lernstand" + (items.length === 1 ? "" : "stände") + " lokal gelesen" + (errors.length ? " · " + errors.length + " Datei(en) nicht lesbar" : "") + ".";
    summary.classList.toggle("error", Boolean(errors.length)); csv.disabled = items.length === 0;
    if (!items.length) { results.innerHTML = errors.map(function (error) { return '<p class="learning-state-error">' + esc(error) + '</p>'; }).join(""); return; }
    var rows = aggregate(items), matrix = rows.map(function (row) {
      var percent = Math.round(row.complete / items.length * 100);
      return '<tr><th>' + esc(row.label) + '</th><td>' + row.complete + ' von ' + items.length + '</td><td><span class="learning-state-meter"><i style="width:' + percent + '%"></i></span> ' + percent + ' %</td></tr>';
    }).join("");
    var individuals = items.map(function (item) {
      var table = item.rows.length ? item.rows.map(function (row) { return '<tr><th>' + esc(row.label) + '</th><td>' + esc(row.value) + '</td></tr>'; }).join("") : '<tr><td>Keine ausgefüllten Felder gefunden.</td></tr>';
      return '<details class="learning-state-file"><summary><b>' + esc(item.pseudonym) + '</b> · ' + item.rows.length + ' Texte' + (item.handwriting ? ' · ' + item.handwriting + ' handschriftliche Fläche(n)' : '') + '</summary><p>Exportiert: ' + esc(dateText(item.exportedAt)) + '</p><div class="learning-state-table-wrap"><table><tbody>' + table + '</tbody></table></div></details>';
    }).join("");
    results.innerHTML = '<section class="learning-state-overview"><h4>Bearbeitungsübersicht</h4><p>Die Prozentwerte zeigen nur, ob ein Feld ausgefüllt wurde. Sie sind keine automatische Leistungsbewertung.</p><div class="learning-state-table-wrap"><table><thead><tr><th>Aufgabe</th><th>bearbeitet</th><th>Anteil</th></tr></thead><tbody>' + matrix + '</tbody></table></div></section><section><h4>Pseudonymisierte Einzelansicht</h4>' + individuals + '</section>' + errors.map(function (error) { return '<p class="learning-state-error">' + esc(error) + '</p>'; }).join("");
  }

  function init(host) {
    var buttonClass = host.closest(".entry-live-teacher") ? "entry-live-btn" : "live-btn", items = [];
    host.innerHTML = '<p>Wähle mehrere in ByCS abgegebene Lernstand-Dateien gleichzeitig aus. Unterstützt werden JSON-Dateien und die von der Lernseite erzeugten HTML-Sicherungen. Alles wird ausschließlich in diesem Browser verarbeitet; keine Datei wird hochgeladen. Dateinamen werden durch neutrale Nummern ersetzt. Handschriftliche Flächen werden gezählt, aber nicht automatisch gelesen.</p><div class="learning-state-actions"><label class="' + buttonClass + ' learning-state-file-button">Dateien auswählen<input type="file" multiple accept=".json,.html,application/json,text/html" data-learning-state-files></label><button class="' + buttonClass + ' ghost" type="button" data-learning-state-csv disabled>Auswertung als CSV</button></div><p class="live-status" data-learning-state-summary role="status" aria-live="polite">Noch keine Dateien ausgewählt.</p><div data-learning-state-results></div>';
    var input = host.querySelector("[data-learning-state-files]"), csv = host.querySelector("[data-learning-state-csv]");
    input.addEventListener("change", function () {
      var files = Array.prototype.slice.call(input.files || []);
      Promise.all(files.map(function (file, index) {
        return parseFile(file, index).then(function (item) { return { ok: true, item: item }; }).catch(function (error) { return { ok: false, error: "Datei " + String(index + 1).padStart(2, "0") + ": " + (error.message || "nicht lesbar") }; });
      })).then(function (outcomes) {
        items = outcomes.filter(function (outcome) { return outcome.ok; }).map(function (outcome) { return outcome.item; });
        render(host, items, outcomes.filter(function (outcome) { return !outcome.ok; }).map(function (outcome) { return outcome.error; }));
      });
    });
    csv.addEventListener("click", function () {
      if (!items.length) return;
      var rows = [["Pseudonym", "Feld", "Antwort", "Exportiert"]];
      items.forEach(function (item) { item.rows.forEach(function (row) { rows.push([item.pseudonym, row.label, row.value, dateText(item.exportedAt)]); }); });
      download("\uFEFF" + rows.map(function (row) { return row.map(csvCell).join(";"); }).join("\r\n"), "text/csv;charset=utf-8", "bycs-lernstaende-" + new Date().toISOString().slice(0, 10) + ".csv");
    });
  }

  document.querySelectorAll("[data-learning-state-evaluator]").forEach(init);
}());
