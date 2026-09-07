(function () {
  "use strict";

  var config = window.RELIGION_LEARNING_TOOLS_CONFIG || {};
  var view = String(config.view || window.RELIGION_VIEW || "student");
  var storageKey = String(config.storageKey || "religion-learning-tools-v1");
  var baseStorageKey = storageKey;
  var colors = ["gold", "coral", "teal", "blue"];
  var colorLabels = { gold: "Gelb", coral: "Koralle", teal: "Türkis", blue: "Blau" };
  var state = { version: 1, highlights: {}, drawings: {} };
  var observed = false;
  var activeHighlighter = null;
  var publishRetry = null;

  if (view !== "beamer") {
    try {
      var loaded = JSON.parse(localStorage.getItem(storageKey) || "null");
      if (loaded && loaded.version === 1) state = Object.assign(state, loaded);
    } catch (error) {}
  }

  function save() {
    try { localStorage.setItem(storageKey, JSON.stringify(state)); } catch (error) {}
    document.dispatchEvent(new CustomEvent("religion-learning-state-change"));
  }

  function publishHighlights(retryCount) {
    if (view !== "teacher") return;
    if (!window.RELIGION_PRESENTATION || typeof window.RELIGION_PRESENTATION.send !== "function") {
      if (Number(retryCount || 0) >= 20 || publishRetry) return;
      publishRetry = window.setTimeout(function () {
        publishRetry = null;
        publishHighlights(Number(retryCount || 0) + 1);
      }, 150);
      return;
    }
    window.RELIGION_PRESENTATION.send({
      type: "learning-highlights",
      payload: { highlights: JSON.parse(JSON.stringify(state.highlights || {})) }
    }).catch(function () {});
  }

  function saveHighlights() {
    save();
    rebuildHighlights();
    publishHighlights();
  }

  function textNodes(root) {
    var nodes = [];
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode: function (node) {
        return node.parentElement && node.parentElement.closest(".learning-highlight-toolbar")
          ? NodeFilter.FILTER_REJECT
          : NodeFilter.FILTER_ACCEPT;
      }
    });
    while (walker.nextNode()) nodes.push(walker.currentNode);
    return nodes;
  }

  function rangeOffsets(root, range) {
    var nodes = textNodes(root), start = 0, end = 0, cursor = 0, foundStart = false, foundEnd = false;
    nodes.forEach(function (node) {
      if (node === range.startContainer) { start = cursor + range.startOffset; foundStart = true; }
      if (node === range.endContainer) { end = cursor + range.endOffset; foundEnd = true; }
      cursor += node.nodeValue.length;
    });
    return foundStart && foundEnd && end > start ? { start: start, end: end } : null;
  }

  function rangeFromOffsets(root, start, end) {
    var nodes = textNodes(root), range = document.createRange(), cursor = 0, hasStart = false;
    for (var i = 0; i < nodes.length; i += 1) {
      var next = cursor + nodes[i].nodeValue.length;
      if (!hasStart && start >= cursor && start <= next) {
        range.setStart(nodes[i], Math.min(nodes[i].nodeValue.length, start - cursor));
        hasStart = true;
      }
      if (hasStart && end >= cursor && end <= next) {
        range.setEnd(nodes[i], Math.min(nodes[i].nodeValue.length, end - cursor));
        return range;
      }
      cursor = next;
    }
    return null;
  }

  // Highlight is a constructor that accepts a variadic list of ranges; Reflect.construct
  // keeps the implementation compatible with Chromium's native Custom Highlight API.
  function createHighlight(ranges) {
    return Reflect.construct(Highlight, ranges);
  }

  function rebuildHighlights() {
    if (!window.CSS || !CSS.highlights || typeof window.Highlight !== "function") return;
    colors.forEach(function (color) {
      var ranges = [];
      document.querySelectorAll("[data-learning-highlighter-id]").forEach(function (root) {
        (state.highlights[root.dataset.learningHighlighterId] || []).forEach(function (entry) {
          if (entry.color !== color) return;
          var range = rangeFromOffsets(root, Number(entry.start), Number(entry.end));
          if (range) ranges.push(range);
        });
      });
      CSS.highlights.delete("religion-" + color);
      if (ranges.length) CSS.highlights.set("religion-" + color, createHighlight(ranges));
    });
  }

  function highlighterId(root) {
    if (root.dataset.learningHighlighterId) return root.dataset.learningHighlighterId;
    var material = root.closest("[data-course-material]");
    var base = root.id || (material && material.dataset.courseMaterial) || "text";
    var scope = material || document;
    var siblings = Array.from(scope.querySelectorAll(".source-excerpt,[data-highlightable]"));
    root.dataset.learningHighlighterId = base + "-" + String(Math.max(0, siblings.indexOf(root)) + 1);
    return root.dataset.learningHighlighterId;
  }

  function initHighlighter(root) {
    if (view === "beamer" || root.dataset.learningHighlighterReady === "true") return;
    root.dataset.learningHighlighterReady = "true";
    var id = highlighterId(root), selectedColor = null, selectedMode = "marker";
    var toolbar = document.createElement("div");
    toolbar.className = "learning-highlight-toolbar";
    toolbar.setAttribute("aria-label", "Text markieren");
    toolbar.innerHTML = '<span class="learning-tool-label">Textmarker</span>' + colors.map(function (color) {
      return '<button type="button" class="learning-color is-' + color + '" data-highlight-color="' + color + '" aria-label="Marker ' + colorLabels[color] + ' ein- oder ausschalten" aria-pressed="false" title="' + colorLabels[color] + 'en Marker ein- oder ausschalten"></button>';
    }).join("") + '<button type="button" class="learning-tool-button learning-eraser" data-highlight-eraser aria-pressed="false" title="Radierer ein- oder ausschalten">Radierer</button><button type="button" class="learning-tool-button" data-highlight-undo>Letzte zurück</button><button type="button" class="learning-tool-button" data-highlight-clear>Alle löschen</button><span class="learning-tool-status" data-highlight-status aria-live="polite">Werkzeug aus – Farbe oder Radierer wählen.</span>';
    root.before(toolbar);
    var status = toolbar.querySelector("[data-highlight-status]");
    var floatingStop = document.createElement("button");
    floatingStop.type = "button";
    floatingStop.className = "learning-marker-stop private";
    floatingStop.hidden = true;
    floatingStop.innerHTML = '<span class="learning-marker-stop-swatch" aria-hidden="true"></span><span>Marker aktiv · beenden</span>';
    floatingStop.title = "Marker ausschalten (Esc)";
    document.body.appendChild(floatingStop);

    var controller = {
      root: root,
      deactivate: deactivate,
      activate: activate,
      applySelection: applySelection
    };

    function syncControls() {
      var active = activeHighlighter === controller;
      toolbar.classList.toggle("is-marker-active", active);
      toolbar.querySelectorAll("[data-highlight-color]").forEach(function (button) {
        var pressed = active && selectedMode === "marker" && button.dataset.highlightColor === selectedColor;
        button.classList.toggle("active", pressed);
        button.setAttribute("aria-pressed", String(pressed));
      });
      var eraserButton = toolbar.querySelector("[data-highlight-eraser]");
      var eraserActive = active && selectedMode === "eraser";
      eraserButton.classList.toggle("active", eraserActive);
      eraserButton.setAttribute("aria-pressed", String(eraserActive));
      if (active) {
        root.dataset.learningMarkerActive = "true";
        root.dataset.learningMarkerMode = selectedMode;
        var activeColor = selectedMode === "eraser" ? "var(--learning-eraser)" : "var(--learning-" + selectedColor + ")";
        root.style.setProperty("--learning-active-marker", activeColor);
        floatingStop.style.setProperty("--learning-active-marker", activeColor);
        floatingStop.querySelector("span:last-child").textContent = selectedMode === "eraser" ? "Radierer aktiv · beenden" : "Marker aktiv · beenden";
        floatingStop.hidden = false;
      } else {
        delete root.dataset.learningMarkerActive;
        delete root.dataset.learningMarkerMode;
        root.style.removeProperty("--learning-active-marker");
        floatingStop.hidden = true;
      }
    }

    function activate(color) {
      if (activeHighlighter && activeHighlighter !== controller) {
        activeHighlighter.deactivate("Marker in diesem Text ausgeschaltet.");
      }
      selectedColor = color;
      selectedMode = "marker";
      activeHighlighter = controller;
      var selection = document.getSelection();
      if (selection) selection.removeAllRanges();
      syncControls();
      status.textContent = colorLabels[color] + "er Marker aktiv – Text direkt markieren. Erneut auf die Farbe klicken oder Esc drücken, um ihn auszuschalten.";
    }

    function activateEraser() {
      if (activeHighlighter && activeHighlighter !== controller) activeHighlighter.deactivate("Werkzeug in diesem Text ausgeschaltet.");
      selectedMode = "eraser";
      activeHighlighter = controller;
      var selection = document.getSelection();
      if (selection) selection.removeAllRanges();
      syncControls();
      status.textContent = "Radierer aktiv – markiere die Stelle, aus der Hervorhebungen entfernt werden sollen. Erneut auf Radierer klicken oder Esc drücken, um ihn auszuschalten.";
    }

    function deactivate(message) {
      if (activeHighlighter === controller) activeHighlighter = null;
      syncControls();
      status.textContent = message || "Werkzeug aus – Farbe oder Radierer wählen.";
      var selection = document.getSelection();
      if (selection) selection.removeAllRanges();
    }

    function applySelection() {
      if (activeHighlighter !== controller || selectedMode === "marker" && !selectedColor) return;
      var selection = document.getSelection();
      if (!selection || selection.rangeCount === 0 || selection.isCollapsed) return;
      var range = selection.getRangeAt(0);
      if (!root.contains(range.startContainer) || !root.contains(range.endContainer)) {
        status.textContent = "Die Markierung muss vollständig innerhalb dieses Textes liegen.";
        return;
      }
      var offsets = rangeOffsets(root, range);
      if (!offsets) {
        status.textContent = "Diese Auswahl kann nicht markiert werden.";
        return;
      }
      state.highlights[id] = state.highlights[id] || [];
      if (selectedMode === "eraser") {
        var next = [];
        state.highlights[id].forEach(function (entry) {
          var start = Number(entry.start), end = Number(entry.end);
          if (end <= offsets.start || start >= offsets.end) { next.push(entry); return; }
          if (start < offsets.start) next.push({ start: start, end: offsets.start, color: entry.color });
          if (end > offsets.end) next.push({ start: offsets.end, end: end, color: entry.color });
        });
        state.highlights[id] = next;
        saveHighlights();
        status.textContent = "Hervorhebung im gewählten Bereich entfernt – der Radierer bleibt aktiv.";
      } else {
        state.highlights[id].push({ start: offsets.start, end: offsets.end, color: selectedColor });
        saveHighlights();
        status.textContent = "Textstelle " + colorLabels[selectedColor].toLowerCase() + " markiert – der Marker bleibt aktiv.";
      }
      selection.removeAllRanges();
    }

    toolbar.addEventListener("pointerdown", function (event) { if (event.target.closest("button")) event.preventDefault(); });
    toolbar.querySelectorAll("[data-highlight-color]").forEach(function (button) {
      function toggleColor() {
        var color = button.dataset.highlightColor;
        if (activeHighlighter === controller && selectedColor === color) deactivate();
        else activate(color);
      }
      button.addEventListener("pointerdown", function (event) {
        event.preventDefault();
        toggleColor();
      });
      button.addEventListener("click", function (event) {
        if (event.detail === 0) toggleColor();
      });
    });
    var eraserToggle = toolbar.querySelector("[data-highlight-eraser]");
    function toggleEraser() {
      if (activeHighlighter === controller && selectedMode === "eraser") deactivate();
      else activateEraser();
    }
    eraserToggle.addEventListener("pointerdown", function (event) {
      event.preventDefault();
      toggleEraser();
    });
    eraserToggle.addEventListener("click", function (event) {
      if (event.detail === 0) toggleEraser();
    });
    document.addEventListener("pointerup", function () {
      if (activeHighlighter !== controller) return;
      requestAnimationFrame(applySelection);
    });
    document.addEventListener("keyup", function (event) {
      if (activeHighlighter === controller && event.key === "Shift") requestAnimationFrame(applySelection);
    });
    document.addEventListener("keydown", function (event) {
      if (activeHighlighter === controller && event.key === "Escape") {
        event.preventDefault();
        deactivate("Werkzeug mit Esc ausgeschaltet.");
      }
    });
    floatingStop.addEventListener("click", function () { deactivate(); });
    toolbar.querySelector("[data-highlight-undo]").addEventListener("click", function () {
      var entries = state.highlights[id] || [];
      if (entries.length) entries.pop();
      state.highlights[id] = entries; saveHighlights();
      status.textContent = entries.length ? "Letzte Markierung entfernt." : "Keine Markierung mehr vorhanden.";
    });
    toolbar.querySelector("[data-highlight-clear]").addEventListener("click", function () {
      state.highlights[id] = []; saveHighlights(); status.textContent = "Alle Markierungen dieses Textes gelöscht.";
    });
  }

  function drawingStore(id) {
    if (!state.drawings[id]) state.drawings[id] = { strokes: [] };
    return state.drawings[id];
  }

  function initDrawing(stage) {
    if (view === "beamer" || stage.dataset.learningDrawingReady === "true") return;
    stage.dataset.learningDrawingReady = "true";
    var id = String(stage.dataset.drawingId || "drawing"), drawing = drawingStore(id);
    var canvas = document.createElement("canvas");
    canvas.className = "learning-drawing-canvas";
    canvas.setAttribute("role", "img");
    canvas.setAttribute("aria-label", stage.dataset.drawingLabel || "Eigene Annotation der Darstellung");
    stage.appendChild(canvas);
    var host = stage.closest("[data-drawing-host]") || stage.parentElement;
    var controls = document.createElement("section");
    controls.className = "learning-drawing-tools private";
    controls.innerHTML = '<div><span class="learning-tool-label">Malwerkzeuge</span><button type="button" class="learning-tool-button" data-draw-toggle aria-pressed="false">Annotation einschalten</button><button type="button" class="learning-tool-button active" data-draw-mode="pen">Stift</button><button type="button" class="learning-tool-button" data-draw-mode="marker">Marker</button><button type="button" class="learning-tool-button" data-draw-mode="eraser">Radierer</button></div><div class="learning-drawing-colors">' + ["#17242a", "#24746e", "#255f75", "#d39b37", "#d97870", "#7c63a5"].map(function (color, index) { return '<button type="button" class="learning-color" data-draw-color="' + color + '" style="--learning-color:' + color + '" aria-label="Farbe ' + (index + 1) + '" aria-pressed="' + (index === 1) + '"></button>'; }).join("") + '</div><div><label>Strichbreite <select data-draw-width><option value="2">fein</option><option value="5" selected>mittel</option><option value="9">breit</option></select></label><button type="button" class="learning-tool-button" data-draw-undo>Rückgängig</button><button type="button" class="learning-tool-button" data-draw-clear>Zeichnung löschen</button></div><p class="learning-tool-status" data-draw-status aria-live="polite">Annotation ist aus – Bildklick und Vergrößerung bleiben verfügbar.</p>';
    host.after(controls);
    var ctx = canvas.getContext("2d"), active = null, mode = "pen", color = "#24746e", width = 5, drawingEnabled = false;

    function setDrawingEnabled(enabled) {
      drawingEnabled = Boolean(enabled);
      canvas.classList.toggle("is-active", drawingEnabled);
      var toggle = controls.querySelector("[data-draw-toggle]");
      toggle.setAttribute("aria-pressed", String(drawingEnabled));
      toggle.textContent = drawingEnabled ? "Annotation ausschalten" : "Annotation einschalten";
      controls.querySelector("[data-draw-status]").textContent = drawingEnabled ? "Mit Maus oder Eingabestift direkt auf der Darstellung zeichnen." : "Annotation ist aus – Bildklick und Vergrößerung bleiben verfügbar.";
    }
    controls.querySelector("[data-draw-toggle]").addEventListener("click", function () { setDrawingEnabled(!drawingEnabled); });

    function sizeCanvas() {
      var rect = stage.getBoundingClientRect(), ratio = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
      canvas.width = Math.max(1, Math.round(rect.width * ratio)); canvas.height = Math.max(1, Math.round(rect.height * ratio));
      canvas.style.width = rect.width + "px"; canvas.style.height = rect.height + "px";
      redraw();
    }
    function drawStroke(stroke) {
      if (!stroke.points || stroke.points.length < 1) return;
      ctx.save();
      ctx.globalCompositeOperation = stroke.mode === "eraser" ? "destination-out" : "source-over";
      ctx.globalAlpha = stroke.mode === "marker" ? 0.32 : 1;
      ctx.strokeStyle = stroke.color; ctx.lineWidth = stroke.mode === "marker" ? stroke.width * 3 : stroke.mode === "eraser" ? stroke.width * 4 : stroke.width;
      ctx.lineCap = "round"; ctx.lineJoin = "round"; ctx.beginPath();
      stroke.points.forEach(function (point, index) {
        var x = point.x * canvas.width, y = point.y * canvas.height;
        if (index === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
      });
      if (stroke.points.length === 1) ctx.lineTo(stroke.points[0].x * canvas.width + 0.1, stroke.points[0].y * canvas.height + 0.1);
      ctx.stroke(); ctx.restore();
    }
    function redraw() { drawing = drawingStore(id); ctx.clearRect(0, 0, canvas.width, canvas.height); drawing.strokes.forEach(drawStroke); }
    canvas.learningRedraw = redraw;
    function point(event) { var rect = canvas.getBoundingClientRect(); return { x: Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width)), y: Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height)) }; }
    canvas.addEventListener("pointerdown", function (event) {
      if (!drawingEnabled || event.pointerType === "touch") return;
      event.preventDefault(); canvas.setPointerCapture(event.pointerId);
      active = { mode: mode, color: color, width: width, points: [point(event)] }; drawing.strokes.push(active); redraw();
    });
    canvas.addEventListener("pointermove", function (event) {
      if (!active || !canvas.hasPointerCapture(event.pointerId)) return;
      event.preventDefault(); var next = point(event), last = active.points[active.points.length - 1];
      if (!last || Math.hypot(next.x - last.x, next.y - last.y) > 0.003) active.points.push(next);
      redraw();
    });
    function end(event) { if (!active) return; if (canvas.hasPointerCapture(event.pointerId)) canvas.releasePointerCapture(event.pointerId); active = null; save(); }
    canvas.addEventListener("pointerup", end); canvas.addEventListener("pointercancel", end);
    controls.querySelectorAll("[data-draw-mode]").forEach(function (button) { button.addEventListener("click", function () { mode = button.dataset.drawMode; controls.querySelectorAll("[data-draw-mode]").forEach(function (item) { item.classList.toggle("active", item === button); }); }); });
    controls.querySelectorAll("[data-draw-color]").forEach(function (button) { button.addEventListener("click", function () { color = button.dataset.drawColor; controls.querySelectorAll("[data-draw-color]").forEach(function (item) { item.setAttribute("aria-pressed", String(item === button)); }); }); });
    controls.querySelector("[data-draw-width]").addEventListener("change", function (event) { width = Number(event.target.value || 5); });
    controls.querySelector("[data-draw-undo]").addEventListener("click", function () { drawing.strokes.pop(); save(); redraw(); });
    controls.querySelector("[data-draw-clear]").addEventListener("click", function () { if (!drawing.strokes.length || confirm("Eigene Zeichnung wirklich löschen?")) { drawing.strokes = []; save(); redraw(); } });
    if (window.ResizeObserver) new ResizeObserver(sizeCanvas).observe(stage); else addEventListener("resize", sizeCanvas);
    sizeCanvas();
  }

  function scan(root) {
    (root || document).querySelectorAll(".source-excerpt,[data-highlightable]").forEach(function (textRoot) {
      highlighterId(textRoot);
      if (view !== "beamer") initHighlighter(textRoot);
    });
    (root || document).querySelectorAll("[data-drawing-id]").forEach(initDrawing);
    rebuildHighlights();
  }

  scan(document);
  if (!observed) {
    observed = true;
    new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) { mutation.addedNodes.forEach(function (node) { if (node.nodeType === 1) scan(node); }); });
    }).observe(document.body, { childList: true, subtree: true });
  }
  if (view === "teacher") setTimeout(function () { publishHighlights(0); }, 350);
  if (view === "beamer") {
    document.addEventListener("religion-classroom-learning-highlights", function (event) {
      var next = event.detail && event.detail.highlights;
      if (!next || typeof next !== "object") return;
      state.highlights = JSON.parse(JSON.stringify(next));
      rebuildHighlights();
    });
  }

  function importTools(next) {
    if (!next || next.version !== 1) return false;
    state = { version: 1, highlights: Object.assign({}, next.highlights || {}), drawings: Object.assign({}, next.drawings || {}) };
    save(); rebuildHighlights();
    document.querySelectorAll("canvas").forEach(function(canvas){ if(typeof canvas.learningRedraw === "function")canvas.learningRedraw(); });
    return true;
  }
  window.RELIGION_LEARNING_TOOLS = {
    version: "1.3.0",
    exportState: function () { return JSON.parse(JSON.stringify(state)); },
    importState: importTools,
    setStorageScope: function(scope) {
      storageKey = baseStorageKey + (scope ? ":person:" + scope : "");
      var next=null;try{next=JSON.parse(localStorage.getItem(storageKey) || "null");}catch(error){}
      importTools(next && next.version === 1 ? next : {version:1,highlights:{},drawings:{}});
    },
    clear: function () { importTools({version:1,highlights:{},drawings:{}}); }
  };
}());
