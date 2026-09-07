(function () {
  "use strict";

  var VERSION = 1;
  var maps = new Map();
  var storageScope = "";

  function safeJson(text) { try { return JSON.parse(text); } catch (error) { return null; } }
  function clone(value) { return JSON.parse(JSON.stringify(value)); }
  function clamp(value, min, max) { return Math.min(max, Math.max(min, value)); }
  function seededShuffle(items, seed) {
    var value = 2166136261;
    String(seed || "concept-map").split("").forEach(function (char) { value ^= char.charCodeAt(0); value = Math.imul(value, 16777619); });
    var result = items.slice();
    for (var i = result.length - 1; i > 0; i -= 1) { value = (Math.imul(value, 1664525) + 1013904223) >>> 0; var j = value % (i + 1); var temp = result[i]; result[i] = result[j]; result[j] = temp; }
    return result;
  }
  function storageKey(id) { return "religion:concept-map:" + id + ":v1" + (storageScope ? ":person:" + storageScope : ""); }
  function blankState(config) {
    var starts = [[84,76],[548,82],[1020,104],[102,522],[570,548],[1048,512],[540,305],[930,305]];
    return {
      version: VERSION,
      id: config.id,
      viewport: { scale: .78, x: 18, y: 16 },
      nodes: config.nodes.map(function (node, index) { var pos = starts[index % starts.length]; return { id: node.id, x: pos[0], y: pos[1], color: ["brass","blue","rose","sage","paper","brass"][index % 6] }; }),
      edges: []
    };
  }
  function normalizeState(value, config) {
    if (!value || Number(value.version) !== VERSION || value.id !== config.id) return blankState(config);
    var known = new Set(config.nodes.map(function (node) { return node.id; }));
    var base = blankState(config);
    var positions = new Map(Array.isArray(value.nodes) ? value.nodes.map(function (node) { return [String(node.id || ""), node]; }) : []);
    base.nodes = base.nodes.map(function (node) {
      var saved = positions.get(node.id) || {};
      return { id: node.id, x: clamp(Number(saved.x) || node.x, 0, 1160), y: clamp(Number(saved.y) || node.y, 0, 650), color: /^(brass|rose|sage|blue|paper)$/.test(saved.color) ? saved.color : node.color };
    });
    base.edges = (Array.isArray(value.edges) ? value.edges : []).filter(function (edge) { return known.has(edge.from) && known.has(edge.to) && edge.from !== edge.to; }).slice(0, 40).map(function (edge, index) {
      return { id: String(edge.id || ("edge-" + Date.now() + "-" + index)), from: String(edge.from), to: String(edge.to), label: String(edge.label || "").slice(0, 54), reason: String(edge.reason || "").slice(0, 500) };
    });
    var view = value.viewport || {};
    base.viewport = { scale: clamp(Number(view.scale) || .78, .45, 1.35), x: clamp(Number(view.x) || 18, -1100, 900), y: clamp(Number(view.y) || 16, -650, 600) };
    return base;
  }
  function readConfig(host) {
    var node = host.querySelector("[data-concept-map-config]");
    var source = node && safeJson(node.textContent || "");
    if (!source || !Array.isArray(source.nodes)) return null;
    source.id = String(host.dataset.conceptMapId || source.id || "").trim();
    source.nodes = source.nodes.map(function (item) { return { id: String(item.id || "").trim(), title: String(item.title || item.id || "").trim(), sourceField: String(item.sourceField || "").trim() }; }).filter(function (item) { return item.id && item.title; });
    return source.id && source.nodes.length > 1 ? source : null;
  }

  function ConceptMap(host, config) {
    this.host = host;
    this.config = config;
    this.history = [];
    this.future = [];
    this.selected = config.nodes[0].id;
    this.drag = null;
    this.state = this.load();
    this.build();
    this.render();
    this.bind();
    maps.set(config.id, this);
    this.host.dispatchEvent(new CustomEvent("religion-concept-map-change", { bubbles: true, detail: { id: this.config.id, state: clone(this.state), summary: this.summary() } }));
  }
  ConceptMap.prototype.load = function () {
    var raw = null;
    try { raw = safeJson(localStorage.getItem(storageKey(this.config.id)) || ""); } catch (error) {}
    return normalizeState(raw, this.config);
  };
  ConceptMap.prototype.persist = function () {
    try { localStorage.setItem(storageKey(this.config.id), JSON.stringify(this.state)); } catch (error) { this.status("Das Begriffsnetz konnte nicht lokal gespeichert werden. Bitte sichere den Lernstand."); }
    this.host.dispatchEvent(new CustomEvent("religion-concept-map-change", { bubbles: true, detail: { id: this.config.id, state: clone(this.state), summary: this.summary() } }));
  };
  ConceptMap.prototype.commit = function () {
    this.history.push(clone(this.state));
    if (this.history.length > 30) this.history.shift();
    this.future = [];
  };
  ConceptMap.prototype.build = function () {
    var template = this.host.querySelector("[data-concept-map-config]");
    if (template) template.remove();
    this.host.classList.add("concept-map-shell");
    this.host.innerHTML = '<div class="concept-map-toolbar"><div class="concept-map-toolbar-group"><button type="button" data-cm-undo disabled>↶ Rückgängig</button><button type="button" data-cm-redo disabled>↷ Wiederholen</button><button type="button" data-cm-fit>Ansicht einpassen</button></div><div class="concept-map-toolbar-group"><button type="button" data-cm-zoom-out aria-label="Verkleinern">−</button><output class="concept-map-zoom" data-cm-zoom>78 %</output><button type="button" data-cm-zoom-in aria-label="Vergrößern">+</button><button type="button" data-cm-expand aria-pressed="false">Groß öffnen</button></div></div><p class="concept-map-hint">Karten ziehen oder mit den Pfeiltasten verschieben. Ziehe eine freie Stelle, um die Fläche zu bewegen. Beziehungen legst du unter der Fläche an.</p><div class="concept-map-viewport" data-cm-viewport tabindex="0" aria-label="Verschiebbare und zoombare Arbeitsfläche für das Begriffsnetz"><div class="concept-map-world" data-cm-world><svg class="concept-map-lines" data-cm-lines viewBox="0 0 1400 820" aria-hidden="true"><defs><marker id="cm-arrow-' + this.config.id.replace(/[^a-zA-Z0-9_-]/g, "") + '" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="currentColor"></path></marker></defs></svg><div data-cm-nodes></div></div></div><div class="concept-map-editor"><form class="concept-map-relation-form" data-cm-form><h4>Begriffe in Beziehung setzen</h4><div class="concept-map-relation-grid"><label>Ausgangsbegriff<select data-cm-from required></select></label><label>Zielbegriff<select data-cm-to required></select></label><label class="concept-map-wide">Verknüpfungswort oder kurze Aussage<input type="text" data-cm-label maxlength="54" placeholder="z. B. konkretisiert, prüft, prägt …" required></label><label class="concept-map-wide">Warum passt diese Verbindung?<textarea data-cm-reason maxlength="500" placeholder="Die Verbindung ist sinnvoll, weil …" required></textarea></label></div><div class="concept-map-palette"><span>Farbe der ausgewählten Karte:</span><button type="button" class="concept-map-color" data-cm-color="brass" aria-label="Gold"></button><button type="button" class="concept-map-color" data-cm-color="rose" aria-label="Rot"></button><button type="button" class="concept-map-color" data-cm-color="sage" aria-label="Grün"></button><button type="button" class="concept-map-color" data-cm-color="blue" aria-label="Blau"></button><button type="button" class="concept-map-color" data-cm-color="paper" aria-label="Hell"></button></div><div class="concept-map-relation-actions"><button type="submit">Verbindung hinzufügen</button><button type="button" data-cm-print>Begriffsnetz drucken</button><button type="button" data-cm-reset>Netz zurücksetzen</button></div><p class="concept-map-status" data-cm-status role="status" aria-live="polite"></p></form><section class="concept-map-edge-panel"><h4>Deine Beziehungen <output data-cm-edge-count>0</output></h4><ol class="concept-map-edge-list" data-cm-edge-list></ol></section></div>';
    this.viewport = this.host.querySelector("[data-cm-viewport]");
    this.world = this.host.querySelector("[data-cm-world]");
    this.nodesRoot = this.host.querySelector("[data-cm-nodes]");
    this.lines = this.host.querySelector("[data-cm-lines]");
    this.form = this.host.querySelector("[data-cm-form]");
    this.from = this.host.querySelector("[data-cm-from]");
    this.to = this.host.querySelector("[data-cm-to]");
    this.label = this.host.querySelector("[data-cm-label]");
    this.reason = this.host.querySelector("[data-cm-reason]");
    this.edgeList = this.host.querySelector("[data-cm-edge-list]");
    var options = this.config.nodes.map(function (node) { return '<option value="' + node.id.replace(/"/g, "") + '">' + node.title.replace(/</g, "&lt;") + '</option>'; });
    this.from.innerHTML = '<option value="">Begriff wählen …</option>' + seededShuffle(options, this.config.id + "from").join("");
    this.to.innerHTML = '<option value="">Begriff wählen …</option>' + seededShuffle(options, this.config.id + "to-other-order").join("");
  };
  ConceptMap.prototype.title = function (id) { var node = this.config.nodes.find(function (item) { return item.id === id; }); return node ? node.title : id; };
  ConceptMap.prototype.sourceText = function (item) {
    var field = item.sourceField && document.querySelector('[data-save="' + CSS.escape(item.sourceField) + '"]');
    return String(field && field.value || "").trim();
  };
  ConceptMap.prototype.renderNodes = function () {
    var self = this;
    this.nodesRoot.innerHTML = "";
    this.state.nodes.forEach(function (saved) {
      var item = self.config.nodes.find(function (node) { return node.id === saved.id; });
      var text = self.sourceText(item);
      var card = document.createElement("article");
      card.className = "concept-map-node" + (self.selected === saved.id ? " is-selected" : "");
      card.dataset.cmNode = saved.id; card.dataset.color = saved.color; card.tabIndex = 0; card.setAttribute("role", "button"); card.setAttribute("aria-pressed", String(self.selected === saved.id));
      card.style.left = saved.x + "px"; card.style.top = saved.y + "px";
      var header = document.createElement("header"); var heading = document.createElement("h4"); heading.textContent = item.title; var badge = document.createElement("small"); badge.textContent = "Lernkarte"; header.append(heading, badge);
      var paragraph = document.createElement("p"); paragraph.textContent = text || "Noch keine Lernkarte ausgefüllt."; if (!text) paragraph.className = "concept-map-empty";
      card.append(header, paragraph); self.nodesRoot.appendChild(card);
    });
  };
  ConceptMap.prototype.renderLines = function () {
    var self = this; var marker = "url(#cm-arrow-" + this.config.id.replace(/[^a-zA-Z0-9_-]/g, "") + ")";
    Array.from(this.lines.querySelectorAll(".concept-map-edge-group")).forEach(function (node) { node.remove(); });
    this.state.edges.forEach(function (edge) {
      var from = self.state.nodes.find(function (node) { return node.id === edge.from; }); var to = self.state.nodes.find(function (node) { return node.id === edge.to; }); if (!from || !to) return;
      var x1 = from.x + 115, y1 = from.y + 63, x2 = to.x + 115, y2 = to.y + 63; var dx = x2 - x1, dy = y2 - y1; var length = Math.max(1, Math.sqrt(dx * dx + dy * dy));
      var startX = x1 + dx / length * 105, startY = y1 + dy / length * 62, endX = x2 - dx / length * 124, endY = y2 - dy / length * 70;
      var group = document.createElementNS("http://www.w3.org/2000/svg", "g"); group.setAttribute("class", "concept-map-edge-group");
      var path = document.createElementNS("http://www.w3.org/2000/svg", "path"); path.setAttribute("class", "concept-map-line"); path.setAttribute("d", "M" + startX + " " + startY + " Q" + ((startX + endX) / 2) + " " + (((startY + endY) / 2) - 28) + " " + endX + " " + endY); path.setAttribute("marker-end", marker);
      var label = document.createElementNS("http://www.w3.org/2000/svg", "text"); label.setAttribute("class", "concept-map-line-label"); label.setAttribute("x", String((startX + endX) / 2)); label.setAttribute("y", String((startY + endY) / 2 - 20)); label.textContent = edge.label;
      group.append(path, label); self.lines.appendChild(group);
    });
  };
  ConceptMap.prototype.renderEdges = function () {
    var self = this; this.edgeList.innerHTML = "";
    this.state.edges.forEach(function (edge) {
      var li = document.createElement("li"); var text = document.createElement("p"); var strong = document.createElement("strong"); strong.textContent = self.title(edge.from) + " — " + edge.label + " → " + self.title(edge.to); var small = document.createElement("small"); small.textContent = edge.reason; text.append(strong, small);
      var button = document.createElement("button"); button.type = "button"; button.dataset.cmDeleteEdge = edge.id; button.setAttribute("aria-label", "Verbindung löschen"); button.textContent = "×"; li.append(text, button); self.edgeList.appendChild(li);
    });
    if (!this.state.edges.length) { var empty = document.createElement("li"); empty.innerHTML = "<p><strong>Noch keine Verbindung.</strong><small>Beginne mit zwei Begriffen und einem passenden Verknüpfungswort.</small></p>"; this.edgeList.appendChild(empty); }
    var count = this.host.querySelector("[data-cm-edge-count]"); if (count) count.textContent = "(" + this.state.edges.length + ")";
  };
  ConceptMap.prototype.renderView = function () { this.world.style.transform = "translate(" + this.state.viewport.x + "px," + this.state.viewport.y + "px) scale(" + this.state.viewport.scale + ")"; var zoom = this.host.querySelector("[data-cm-zoom]"); if (zoom) zoom.textContent = Math.round(this.state.viewport.scale * 100) + " %"; };
  ConceptMap.prototype.render = function () { this.renderNodes(); this.renderLines(); this.renderEdges(); this.renderView(); this.host.querySelector("[data-cm-undo]").disabled = !this.history.length; this.host.querySelector("[data-cm-redo]").disabled = !this.future.length; };
  ConceptMap.prototype.refreshSources = function () { var self = this; this.config.nodes.forEach(function (item) { var field = item.sourceField && document.querySelector('[data-save="' + CSS.escape(item.sourceField) + '"]'); if (field && field.dataset.cmBound !== self.config.id) { field.dataset.cmBound = self.config.id; field.addEventListener("input", function () { self.renderNodes(); self.renderLines(); }); } }); this.renderNodes(); this.renderLines(); };
  ConceptMap.prototype.status = function (message) { var node = this.host.querySelector("[data-cm-status]"); if (node) node.textContent = message || ""; };
  ConceptMap.prototype.summary = function () { var self = this; return this.state.edges.map(function (edge) { return self.title(edge.from) + " — " + edge.label + " → " + self.title(edge.to) + ": " + edge.reason; }).join("\n"); };
  ConceptMap.prototype.selectNode = function (id) { this.selected = id; this.renderNodes(); this.renderLines(); };
  ConceptMap.prototype.zoom = function (delta) { this.commit(); this.state.viewport.scale = clamp(this.state.viewport.scale + delta, .45, 1.35); this.render(); this.persist(); };
  ConceptMap.prototype.fit = function () { this.commit(); var width = this.viewport.clientWidth || 900; var height = this.viewport.clientHeight || 560; this.state.viewport.scale = clamp(Math.min((width - 32) / 1400, (height - 32) / 820), .45, 1); this.state.viewport.x = 16; this.state.viewport.y = 16; this.render(); this.persist(); };
  ConceptMap.prototype.bind = function () {
    var self = this;
    this.form.addEventListener("submit", function (event) {
      event.preventDefault(); var from = self.from.value, to = self.to.value, label = self.label.value.trim(), reason = self.reason.value.trim();
      if (!from || !to || !label || !reason) { self.status("Wähle zwei Begriffe und ergänze Verknüpfungswort sowie Begründung."); return; }
      if (from === to) { self.status("Ausgangs- und Zielbegriff müssen verschieden sein."); return; }
      self.commit(); self.state.edges.push({ id: "edge-" + Date.now() + "-" + Math.random().toString(36).slice(2, 7), from: from, to: to, label: label.slice(0, 54), reason: reason.slice(0, 500) }); self.label.value = ""; self.reason.value = ""; self.render(); self.persist(); self.status("Verbindung hinzugefügt. Prüfe jetzt, ob ihre Richtung passt.");
    });
    this.host.addEventListener("click", function (event) {
      var node = event.target.closest && event.target.closest("[data-cm-node]"); if (node) { self.selectNode(node.dataset.cmNode); return; }
      var del = event.target.closest && event.target.closest("[data-cm-delete-edge]"); if (del) { self.commit(); self.state.edges = self.state.edges.filter(function (edge) { return edge.id !== del.dataset.cmDeleteEdge; }); self.render(); self.persist(); return; }
      var color = event.target.closest && event.target.closest("[data-cm-color]"); if (color) { self.commit(); var selected = self.state.nodes.find(function (item) { return item.id === self.selected; }); if (selected) selected.color = color.dataset.cmColor; self.render(); self.persist(); return; }
      if (event.target.closest && event.target.closest("[data-cm-zoom-in]")) self.zoom(.1);
      if (event.target.closest && event.target.closest("[data-cm-zoom-out]")) self.zoom(-.1);
      if (event.target.closest && event.target.closest("[data-cm-fit]")) self.fit();
      if (event.target.closest && event.target.closest("[data-cm-undo]")) { if (!self.history.length) return; self.future.push(clone(self.state)); self.state = self.history.pop(); self.render(); self.persist(); }
      if (event.target.closest && event.target.closest("[data-cm-redo]")) { if (!self.future.length) return; self.history.push(clone(self.state)); self.state = self.future.pop(); self.render(); self.persist(); }
      if (event.target.closest && event.target.closest("[data-cm-expand]")) { var expand = !self.host.classList.contains("is-expanded"); self.host.classList.toggle("is-expanded", expand); document.body.classList.toggle("concept-map-expanded", expand); var button = self.host.querySelector("[data-cm-expand]"); button.setAttribute("aria-pressed", String(expand)); button.textContent = expand ? "Verkleinern" : "Groß öffnen"; setTimeout(function () { self.fit(); }, 0); }
      if (event.target.closest && event.target.closest("[data-cm-print]")) window.print();
      if (event.target.closest && event.target.closest("[data-cm-reset]")) { if (!window.confirm("Positionen, Farben und Beziehungen dieses Begriffsnetzes zurücksetzen?")) return; self.commit(); self.state = blankState(self.config); self.render(); self.persist(); self.status("Begriffsnetz zurückgesetzt."); }
    });
    this.nodesRoot.addEventListener("pointerdown", function (event) {
      var card = event.target.closest && event.target.closest("[data-cm-node]"); if (!card) return; event.preventDefault(); var item = self.state.nodes.find(function (node) { return node.id === card.dataset.cmNode; }); if (!item) return; self.commit(); self.selectNode(item.id); self.drag = { type: "node", id: item.id, x: event.clientX, y: event.clientY, startX: item.x, startY: item.y }; card.setPointerCapture(event.pointerId);
    });
    this.viewport.addEventListener("pointerdown", function (event) { if (event.target.closest && event.target.closest("[data-cm-node]")) return; self.commit(); self.drag = { type: "pan", x: event.clientX, y: event.clientY, startX: self.state.viewport.x, startY: self.state.viewport.y }; self.viewport.classList.add("is-panning"); self.viewport.setPointerCapture(event.pointerId); });
    function move(event) { if (!self.drag) return; if (self.drag.type === "node") { var item = self.state.nodes.find(function (node) { return node.id === self.drag.id; }); if (!item) return; item.x = clamp(self.drag.startX + (event.clientX - self.drag.x) / self.state.viewport.scale, 0, 1160); item.y = clamp(self.drag.startY + (event.clientY - self.drag.y) / self.state.viewport.scale, 0, 650); var card = self.nodesRoot.querySelector('[data-cm-node="' + CSS.escape(item.id) + '"]'); if (card) { card.style.left = item.x + "px"; card.style.top = item.y + "px"; } self.renderLines(); } else { self.state.viewport.x = clamp(self.drag.startX + event.clientX - self.drag.x, -1100, 900); self.state.viewport.y = clamp(self.drag.startY + event.clientY - self.drag.y, -650, 600); self.renderView(); } }
    function end() { if (!self.drag) return; self.drag = null; self.viewport.classList.remove("is-panning"); self.render(); self.persist(); }
    this.host.addEventListener("pointermove", move); this.host.addEventListener("pointerup", end); this.host.addEventListener("pointercancel", end);
    this.nodesRoot.addEventListener("keydown", function (event) { var card = event.target.closest && event.target.closest("[data-cm-node]"); if (!card || !/^Arrow/.test(event.key)) return; event.preventDefault(); var item = self.state.nodes.find(function (node) { return node.id === card.dataset.cmNode; }); if (!item) return; self.commit(); var step = event.shiftKey ? 40 : 12; if (event.key === "ArrowLeft") item.x -= step; if (event.key === "ArrowRight") item.x += step; if (event.key === "ArrowUp") item.y -= step; if (event.key === "ArrowDown") item.y += step; item.x = clamp(item.x, 0, 1160); item.y = clamp(item.y, 0, 650); self.selectNode(item.id); self.persist(); });
    document.addEventListener("keydown", function (event) { if (event.key === "Escape" && self.host.classList.contains("is-expanded")) { self.host.querySelector("[data-cm-expand]").click(); } });
    window.addEventListener("resize", function () { self.renderLines(); });
    setTimeout(function () { self.refreshSources(); }, 0);
  };
  ConceptMap.prototype.exportState = function () { return { version: VERSION, id: this.config.id, state: clone(this.state), summary: this.summary() }; };
  ConceptMap.prototype.importState = function (payload) { var value = payload && payload.state ? payload.state : payload; this.commit(); this.state = normalizeState(value, this.config); this.render(); this.persist(); this.refreshSources(); };
  ConceptMap.prototype.clear = function () { this.state = blankState(this.config); this.history = []; this.future = []; try { localStorage.removeItem(storageKey(this.config.id)); } catch (error) {} this.render(); };

  window.RELIGION_CONCEPT_MAPS = {
    version: VERSION,
    exportState: function () { var result = { version: VERSION, maps: {} }; maps.forEach(function (map, id) { result.maps[id] = map.exportState(); }); return result; },
    importState: function (payload) { if (!payload || Number(payload.version) !== VERSION || !payload.maps) return false; Object.keys(payload.maps).forEach(function (id) { if (maps.has(id)) maps.get(id).importState(payload.maps[id]); }); return true; },
    clearState: function () { maps.forEach(function (map) { map.clear(); }); },
    setStorageScope: function(scope) { storageScope=String(scope || ""); maps.forEach(function(map){ map.state=map.load();map.history=[];map.future=[];map.render();map.refreshSources(); }); },
    get: function (id) { return maps.has(id) ? maps.get(id).exportState() : null; }
  };

  function init() { document.querySelectorAll("[data-concept-map]").forEach(function (host) { if (host.dataset.conceptMapReady === "true") return; var config = readConfig(host); if (!config) return; host.dataset.conceptMapReady = "true"; new ConceptMap(host, config); }); }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init); else init();
}());
