(function () {
  "use strict";

  var classroom = window.RELIGION_CLASSROOM;
  var config = window.RELIGION_CLASSROOM_CONFIG || {};
  if (!classroom || !config.presentation) return;

  var options = config.presentation || {};
  var view = config.view || window.RELIGION_VIEW || "student";
  var moduleId = String(config.moduleId || "learning-html-module");
  var channelKey = String(options.channelKey || moduleId + "-presentation-command-v1");
  var broadcastName = String(options.broadcastName || "religion-classroom-" + moduleId);
  var sectionSelector = String(options.sectionSelector || "main > section[id]");
  var cueSelector = String(options.cueSelector || "[data-beamer-anchor],[data-step-id],[data-beamer-step]");
  var flowCueAttribute = "data-classroom-flow-cue";
  var flowCueSelector = "[" + flowCueAttribute + "]";
  var allCueSelector = cueSelector + "," + flowCueSelector;
  var activeEnvelope = null;
  var lastSignature = "";
  var lastAppliedSentAt = 0;
  var lastAppliedMediaSignature = "";
  var lastAppliedYoutubeSignature = "";
  var beamerWindow = null;
  var broadcast = null;
  var joinScreen = null;
  var shade = null;
  var beamerDimmed = false;
  var teacherToolbar = null;
  var teacherCueIndex = -1;
  var teacherDimmed = false;
  var teacherFollowEnabled = options.autoFollow !== false;
  var teacherFollowTimer = null;
  var teacherTransientTimer = null;
  var lastTransientAt = 0;
  var lastTransientFollow = "";
  var teacherStableCue = null;
  var teacherLastScrollY = window.scrollY || 0;
  var teacherImageOpen = false;
  var teacherManagerActive = false;
  var persistentQueue = Promise.resolve();
  var presenterState = { theme: "light", focus: "", progress: 0, mode: "content", join: null, details: {}, controls: {}, learningHighlights: {}, media: null, youtube: null, image: null };
  var headerToggle = null;
  var beamerScrollFrame = 0;
  var beamerScrollGoal = null;

  try {
    if ("BroadcastChannel" in window) broadcast = new BroadcastChannel(broadcastName);
  } catch (error) {}

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function roomCode() {
    return classroom.codeClean(classroom.room());
  }

  function currentTheme() {
    var htmlTheme = String(document.documentElement.dataset.theme || "").toLowerCase();
    var bodyTheme = document.body ? String(document.body.dataset.theme || "").toLowerCase() : "";
    if (htmlTheme === "dark" || bodyTheme === "dark" || document.documentElement.classList.contains("dark") || document.body && (document.body.classList.contains("dark") || document.body.classList.contains("theme-dark"))) return "dark";
    return "light";
  }

  function updatePresenterState(command) {
    command = command || {};
    var payload = command.payload || {};
    presenterState.theme = currentTheme();
    if (command.type === "goto" || command.type === "follow") {
      var nextFocus = String(payload.step || payload.target || payload.key || presenterState.focus || "");
      if (presenterState.media && presenterState.media.target !== nextFocus) presenterState.media = null;
      if (presenterState.youtube && presenterState.youtube.target !== nextFocus) presenterState.youtube = null;
      if (presenterState.image && presenterState.image.target !== nextFocus) presenterState.image = { action: "close" };
      presenterState.focus = nextFocus;
      presenterState.progress = command.type === "follow" ? Math.max(0, Math.min(1, Number(payload.progress) || 0)) : 0;
      if (presenterState.mode !== "dim") presenterState.mode = "content";
    } else if (payload.focus) {
      presenterState.focus = String(payload.focus);
      presenterState.progress = 0;
      if (presenterState.mode !== "dim") presenterState.mode = "content";
    }
    if (command.type === "join") { presenterState.mode = "join"; presenterState.join = payload; }
    if (command.type === "dim") presenterState.mode = payload.active === false ? "content" : "dim";
    if (command.type === "theme") presenterState.theme = payload.theme === "dark" ? "dark" : "light";
    if (command.type === "media") {
      presenterState.media = {
        target: String(payload.target || payload.key || ""),
        action: String(payload.action || "play"),
        time: Math.max(0, Number(payload.time || 0))
      };
      presenterState.youtube = null;
      if (presenterState.media.target) presenterState.focus = presenterState.media.target;
    }
    if (command.type === "youtube") {
      presenterState.youtube = {
        target: String(payload.target || payload.key || ""),
        action: String(payload.action || "play"),
        videoId: String(payload.videoId || ""),
        start: Math.max(0, Number(payload.start || 0)),
        end: Math.max(0, Number(payload.end || 0)),
        title: String(payload.title || "Video")
      };
      presenterState.media = null;
      if (presenterState.youtube.target) presenterState.focus = presenterState.youtube.target;
    }
    if (command.type === "image") {
      presenterState.image = payload.action === "close" ? { action: "close" } : {
        target: String(payload.target || payload.key || ""),
        action: "open",
        src: String(payload.src || ""),
        alt: String(payload.alt || ""),
        caption: String(payload.caption || "")
      };
      if (presenterState.image.target) presenterState.focus = presenterState.image.target;
    }
    if (command.type === "learning-highlights" && payload.highlights && typeof payload.highlights === "object") {
      presenterState.learningHighlights = JSON.parse(JSON.stringify(payload.highlights));
    }
    if (command.type === "control" && payload.group) {
      presenterState.controls[String(payload.group)] = String(payload.value == null ? "" : payload.value);
    }
  }

  function presenterSnapshot() {
    var detailKeys = Object.keys(presenterState.details).slice(-60);
    var controlKeys = Object.keys(presenterState.controls).slice(-30);
    var details = {}, controls = {};
    detailKeys.forEach(function (key) { details[key] = Boolean(presenterState.details[key]); });
    controlKeys.forEach(function (key) { controls[key] = String(presenterState.controls[key]); });
    return {
      version: 1,
      theme: presenterState.theme,
      focus: presenterState.focus,
      progress: presenterState.progress,
      mode: presenterState.mode,
      join: presenterState.mode === "join" ? presenterState.join : null,
      details: details,
      controls: controls,
      learningHighlights: presenterState.learningHighlights,
      media: presenterState.media,
      youtube: presenterState.youtube,
      image: presenterState.image
    };
  }

  function envelope(command) {
    updatePresenterState(command);
    var payload = command && command.payload && typeof command.payload === "object" ? Object.assign({}, command.payload) : {};
    payload._classroom = presenterSnapshot();
    return {
      moduleId: moduleId,
      room: roomCode(),
      type: String(command && command.type || ""),
      payload: payload,
      sentAt: Date.now(),
      nonce: Math.random().toString(36).slice(2, 12)
    };
  }

  function signature(value) {
    if (!value || value.moduleId !== moduleId || !value.type) return "";
    return [value.moduleId, value.type, value.sentAt || 0, value.nonce || ""].join(":");
  }

  function announce(command) {
    try {
      document.dispatchEvent(new CustomEvent("religion-classroom-presentation-command", {
        detail: { command: command, moduleId: moduleId, room: roomCode() }
      }));
    } catch (error) {}
  }

  function sendLocal(value) {
    try { localStorage.setItem(channelKey, JSON.stringify(value)); } catch (error) {}
    try { if (broadcast) broadcast.postMessage(value); } catch (error) {}
    try { if (beamerWindow && !beamerWindow.closed) beamerWindow.postMessage({ religionClassroomPresentation: value }, location.origin); } catch (error) {}
  }

  function send(command) {
    var value = envelope(command);
    if (!value.type) return Promise.reject(new Error("Präsentationsbefehl ohne Typ."));
    sendLocal(value);
    activeEnvelope = value;
    announce(value);
    releaseFromCommand(value);
    if (!roomCode()) return Promise.resolve(value);
    persistentQueue = persistentQueue.catch(function () {}).then(function () {
      return classroom.request("set_presentation", { room: roomCode(), presentation: value });
    });
    return persistentQueue.then(function (data) { classroom.announce(data); return value; }).catch(function () { return value; });
  }

  /* Scroll commands are intentionally local-first. Sending every animation
     frame to the server would be wasteful; the settled position is persisted
     separately after the teacher stops scrolling. */
  function sendTransient(command) {
    var value = envelope(command);
    if (!value.type) return null;
    sendLocal(value);
    activeEnvelope = value;
    announce(value);
    return value;
  }

  function explicitTargetKey(node) {
    if (!node) return "";
    return String(node.dataset.beamerAnchor || node.dataset.stepId || node.dataset.beamerStep || node.id || "");
  }

  function targetKey(node) {
    if (!node) return "";
    return String(node.dataset.beamerAnchor || node.dataset.stepId || node.dataset.beamerStep || node.dataset.classroomFlowCue || node.id || "");
  }

  function targetFor(key) {
    var clean = String(key || "");
    if (!clean) return null;
    var escaped = window.CSS && CSS.escape ? CSS.escape(clean) : clean.replace(/[^a-zA-Z0-9_-]/g, "");
    return document.querySelector('[data-beamer-anchor="' + escaped + '"],[data-step-id="' + escaped + '"],[data-beamer-step="' + escaped + '"],[' + flowCueAttribute + '="' + escaped + '"],#' + escaped);
  }

  function releaseFromCommand(command) {
    if (view !== "teacher" || !window.RELIGION_LIVE_RELEASE || !command) return;
    var payload = command.payload || {};
    var key = payload.step || payload.target || payload.focus || payload.key || "";
    var node = targetFor(key);
    if (!node) return;
    Promise.resolve(window.RELIGION_LIVE_RELEASE.releaseForNode(node)).catch(function () {});
  }

  function stages() {
    return Array.prototype.slice.call(document.querySelectorAll(sectionSelector));
  }

  function isExcludedFlowNode(node) {
    return !node || !node.matches || node.matches("script,style,template,[hidden]") ||
      Boolean(node.closest('[data-rolle="lehrer"],[data-role="teacher"],.teacher,.private,.export-tools,.classroom-presenter-toolbar,.live-classroom-manager'));
  }

  function meaningfulFlowNode(node) {
    if (isExcludedFlowNode(node)) return false;
    if (explicitTargetKey(node)) return true;
    if (node.matches("article,aside,figure,details,blockquote,table,.earth-station,.video-stage,.source-card,.philosopher-profile,.section-illustration,.live-entry,.reading,.consciousness-map,.consciousness-bridge,.rights-sequence,.rights-source-grid")) return true;
    return String(node.textContent || "").trim().length >= 90;
  }

  function flowRoot(section) {
    var root = section;
    var structural = /(^|\s)(wrap|container|inner|content|shell)(\s|$)/;
    while (root && root.children && root.children.length === 1) {
      var child = root.children[0];
      if (!child || !structural.test(String(child.className || ""))) break;
      root = child;
    }
    return root || section;
  }

  /* Every view derives the same fallback anchors from the shared public DOM.
     This lets a long chapter follow semantically without coupling two layouts
     by raw page pixels. Explicit author anchors always keep precedence. */
  function prepareFlowCues() {
    stages().forEach(function (section) {
      var sectionKey = explicitTargetKey(section);
      if (!sectionKey) return;
      var candidates = Array.prototype.slice.call(flowRoot(section).children || []);
      Array.prototype.slice.call(section.querySelectorAll(".earth-station,.video-stage,.source-card,.philosopher-profile,.section-illustration,.live-entry,.reading,.consciousness-map,.consciousness-bridge,.rights-sequence,.rights-source-grid")).forEach(function (node) {
        if (candidates.indexOf(node) < 0) candidates.push(node);
      });
      var index = 0;
      candidates.forEach(function (node) {
        if (!meaningfulFlowNode(node) || node.matches(cueSelector) || node.hasAttribute(flowCueAttribute)) return;
        index += 1;
        node.setAttribute(flowCueAttribute, sectionKey + "--flow-" + index);
      });
    });
  }

  function prepareSynchronizedState() {
    stages().forEach(function (section) {
      var sectionKey = explicitTargetKey(section) || section.id || "abschnitt";
      var detailIndex = 0;
      Array.prototype.slice.call(section.querySelectorAll("details")).filter(function (detail) { return !isExcludedFlowNode(detail); }).forEach(function (detail) {
        detailIndex += 1;
        var key = String(detail.dataset.classroomDetailsKey || explicitTargetKey(detail) || detail.dataset.classroomFlowCue || sectionKey + "--details-" + detailIndex);
        detail.dataset.classroomDetailsKey = key;
        if (view === "teacher") presenterState.details[key] = Boolean(detail.open);
      });
    });
    if (view === "teacher") {
      document.querySelectorAll("[data-classroom-control-group][data-classroom-control-value]").forEach(function (control) {
        if (isExcludedFlowNode(control)) return;
        var group = String(control.dataset.classroomControlGroup || "");
        if (!group || Object.prototype.hasOwnProperty.call(presenterState.controls, group)) return;
        if (control.classList.contains("active") || control.getAttribute("aria-selected") === "true" || control.getAttribute("aria-pressed") === "true") presenterState.controls[group] = String(control.dataset.classroomControlValue || "");
      });
    }
  }

  function detailForKey(key) {
    var clean = String(key || "");
    if (!clean) return null;
    var escaped = window.CSS && CSS.escape ? CSS.escape(clean) : clean.replace(/[^a-zA-Z0-9_-]/g, "");
    return document.querySelector('[data-classroom-details-key="' + escaped + '"]');
  }

  function applyDetailsState(details) {
    if (!details || typeof details !== "object") return;
    Object.keys(details).forEach(function (key) { var detail = detailForKey(key); if (detail) detail.open = Boolean(details[key]); });
  }

  function applyControlState(group, value) {
    var cleanGroup = String(group || ""), cleanValue = String(value == null ? "" : value);
    if (!cleanGroup) return;
    var escapedGroup = window.CSS && CSS.escape ? CSS.escape(cleanGroup) : cleanGroup.replace(/[^a-zA-Z0-9_-]/g, "");
    var escapedValue = window.CSS && CSS.escape ? CSS.escape(cleanValue) : cleanValue.replace(/[^a-zA-Z0-9_-]/g, "");
    var control = document.querySelector('[data-classroom-control-group="' + escapedGroup + '"][data-classroom-control-value="' + escapedValue + '"]');
    if (!control) {
      var range = document.querySelector('[data-classroom-range-group="' + escapedGroup + '"]');
      if (!range) return;
      if (String(range.value) !== cleanValue) {
        range.value = cleanValue;
        range.dispatchEvent(new Event("input", { bubbles: true }));
        range.dispatchEvent(new Event("change", { bubbles: true }));
      }
      return;
    }
    var alreadyActive = control.classList.contains("active") || control.getAttribute("aria-selected") === "true" || control.getAttribute("aria-pressed") === "true";
    if (!alreadyActive) control.click();
  }

  function applyPresenterSnapshot(snapshot, skipFocus, skipMedia) {
    if (!snapshot || typeof snapshot !== "object") return;
    if (snapshot.theme) document.documentElement.dataset.theme = snapshot.theme === "dark" ? "dark" : "light";
    if (snapshot.mode) beamerDimmed = snapshot.mode === "dim";
    applyDetailsState(snapshot.details);
    if (snapshot.controls && typeof snapshot.controls === "object") Object.keys(snapshot.controls).forEach(function (group) { applyControlState(group, snapshot.controls[group]); });
    if (snapshot.learningHighlights && typeof snapshot.learningHighlights === "object") {
      try { document.dispatchEvent(new CustomEvent("religion-classroom-learning-highlights", { detail: { highlights: snapshot.learningHighlights } })); } catch (error) {}
    }
    if (!skipMedia && snapshot.media && snapshot.media.target) applyMedia(snapshot.media, false);
    if (!skipMedia && snapshot.youtube && snapshot.youtube.target) applyYoutube(snapshot.youtube, false);
    if (snapshot.image && snapshot.image.action) applyImage(snapshot.image);
    if (snapshot.mode === "join" && snapshot.join) renderJoin(snapshot.join);
    else if (snapshot.mode === "dim") showShade("Arbeitsphase", "Der nächste Inhalt wird erst auf Signal der Lehrkraft sichtbar.");
    else if (snapshot.focus && !skipFocus) applyFollow({ step: snapshot.focus, progress: snapshot.progress || 0 });
  }

  function prepareBeamerStages() {
    if (view !== "beamer") return;
    var nodes = stages();
    nodes.forEach(function (node) {
      node.setAttribute("data-classroom-beamer-stage", "");
      node.classList.add("classroom-stage-hidden");
      node.setAttribute("aria-hidden", "true");
    });
    document.body.classList.remove("classroom-stage-outside-main");
    document.body.classList.add("classroom-beamer-awaiting");
    showShade("Beamer bereit", roomCode() ? "Verbunden mit Klassenraum " + roomCode() + ". Die Lehrkraft wählt den ersten Präsentationshalt." : "Bitte die Beameransicht aus einem geöffneten Klassenraum starten.");
  }

  function isolate(target) {
    if (view !== "beamer" || !target) return;
    var section = target.matches(sectionSelector) ? target : target.closest(sectionSelector);
    if (!section) return;
    stages().forEach(function (node) {
      var current = node === section;
      node.classList.toggle("classroom-stage-hidden", !current);
      node.classList.toggle("classroom-stage-current", current);
      if (current) {
        node.removeAttribute("aria-hidden");
        try { node.inert = false; } catch (error) {}
      } else {
        node.setAttribute("aria-hidden", "true");
        try { node.inert = true; } catch (error) {}
      }
    });
    var main = document.querySelector("body > main");
    document.body.classList.toggle("classroom-stage-outside-main", Boolean(main && !main.contains(section)));
    document.body.classList.remove("classroom-beamer-awaiting");
    document.body.classList.add("classroom-beamer-active");
    if (!beamerDimmed) hideShade();
    if (joinScreen) joinScreen.hidden = true;
  }

  function beamerSectionFor(target) {
    return target && (target.matches(sectionSelector) ? target : target.closest(sectionSelector));
  }

  function scrollBeamerTarget(target, progress, immediate) {
    if (view !== "beamer" || !target) return;
    var section = beamerSectionFor(target);
    if (!section) return;
    var amount = Math.max(0, Math.min(1, Number(progress) || 0));
    var sectionRect = section.getBoundingClientRect();
    var targetRect = target.getBoundingClientRect();
    var viewport = Math.max(1, section.clientHeight || window.innerHeight || 1);
    var targetTop = target === section ? 0 : section.scrollTop + targetRect.top - sectionRect.top;
    var targetHeight = target === section ? section.scrollHeight : Math.max(target.scrollHeight || 0, targetRect.height || 0);
    var readableTail = Math.min(targetHeight, viewport * 0.68);
    var travel = Math.max(0, targetHeight - readableTail);
    var maximum = Math.max(0, section.scrollHeight - viewport);
    var top = Math.max(0, Math.min(maximum, targetTop + amount * travel - 16));
    var reduced = false;
    try { reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches; } catch (error) {}
    if (immediate || reduced || beamerScrollGoal && beamerScrollGoal.section !== section) {
      if (beamerScrollFrame) cancelAnimationFrame(beamerScrollFrame);
      beamerScrollFrame = 0;
      beamerScrollGoal = { section: section, top: top };
      section.scrollTop = top;
      return;
    }
    beamerScrollGoal = { section: section, top: top };
    if (beamerScrollFrame) return;
    function settle() {
      beamerScrollFrame = 0;
      var goal = beamerScrollGoal;
      if (!goal || !goal.section || !goal.section.isConnected) return;
      var difference = goal.top - goal.section.scrollTop;
      if (Math.abs(difference) < 0.7) {
        goal.section.scrollTop = goal.top;
        return;
      }
      /* Große Distanzen dürfen nicht im ersten Frame fast vollständig
         übersprungen werden. Die Geschwindigkeitskappe hält die Bewegung
         lesbar; die proportionale Annäherung sorgt für ein ruhiges Auslaufen. */
      var maximumStep = Math.max(42, viewport * 0.12);
      var step = Math.max(-maximumStep, Math.min(maximumStep, difference * 0.22));
      goal.section.scrollTop += step;
      beamerScrollFrame = requestAnimationFrame(settle);
    }
    beamerScrollFrame = requestAnimationFrame(settle);
  }

  function focusTarget(key, progress) {
    var target = targetFor(key);
    if (!target) return;
    var ancestors = [], parent = target.parentElement;
    while (parent) { if (parent.tagName === "DETAILS") ancestors.unshift(parent); parent = parent.parentElement; }
    ancestors.forEach(function (detail) { detail.open = true; });
    document.querySelectorAll("dialog[open]").forEach(function (dialog) {
      if (!dialog.contains(target)) {
        try { dialog.close(); } catch (error) { dialog.removeAttribute("open"); }
      }
    });
    isolate(target);
    scrollBeamerTarget(target, progress || 0, true);
    requestAnimationFrame(function () { scrollBeamerTarget(target, progress || 0, true); });
    try { target.focus({ preventScroll: true }); } catch (error) {}
  }

  function applyFollow(payload) {
    var key = payload.step || payload.target || payload.key;
    var target = targetFor(key);
    if (!target) return;
    var ancestors = [], parent = target.parentElement;
    while (parent) { if (parent.tagName === "DETAILS") ancestors.unshift(parent); parent = parent.parentElement; }
    ancestors.forEach(function (detail) { detail.open = true; });
    isolate(target);
    scrollBeamerTarget(target, payload.progress, false);
  }

  function showShade(title, message) {
    if (view !== "beamer") return;
    if (!shade) {
      shade = document.createElement("section");
      shade.className = "classroom-presenter-shade";
      shade.setAttribute("role", "status");
      shade.setAttribute("aria-live", "polite");
      document.body.appendChild(shade);
    }
    shade.hidden = false;
    shade.innerHTML = "<div><p class=\"live-beamer-join-kicker\">Präsentationsansicht</p><h1>" + escapeHtml(title) + "</h1><p>" + escapeHtml(message) + "</p></div>";
  }

  function hideShade() {
    if (shade) shade.hidden = true;
  }

  function makeQr(box, url) {
    if (!box) return;
    if (typeof window.qrcode !== "function") { box.textContent = "QR-Code nicht verfügbar."; return; }
    try {
      var qr = window.qrcode(0, "M");
      qr.addData(url);
      qr.make();
      box.innerHTML = qr.createSvgTag({ cellSize: 8, margin: 12, scalable: true, title: "QR-Code zum Unterricht", alt: "Schülerlink mit Klassenraumcode" });
    } catch (error) { box.textContent = "QR-Code nicht verfügbar."; }
  }

  function renderJoin(payload) {
    if (view !== "beamer") return;
    var code = classroom.codeClean(payload.room || roomCode());
    var url = String(payload.url || classroom.studentUrl());
    if (!joinScreen) {
      joinScreen = document.createElement("section");
      joinScreen.className = "live-beamer-join-screen";
      joinScreen.setAttribute("role", "status");
      document.body.appendChild(joinScreen);
    }
    joinScreen.hidden = false;
    hideShade();
    joinScreen.innerHTML = '<div class="live-beamer-join-inner"><div><p class="live-beamer-join-kicker">Unterricht beitreten</p><h1>' + escapeHtml(payload.label || config.moduleLabel || document.title) + '</h1><p class="live-beamer-join-note">QR-Code scannen oder den Code auf der Lernseite eingeben.</p><p class="live-beamer-join-code">' + escapeHtml(code || "–") + '</p><p class="live-beamer-join-note">Klassenraumcode</p><p class="live-beamer-join-url">' + escapeHtml(url) + '</p></div><div class="live-beamer-join-qr" data-classroom-join-qr></div></div>';
    makeQr(joinScreen.querySelector("[data-classroom-join-qr]"), url);
  }

  function mediaTarget(payload) {
    var target = targetFor(payload.target || payload.key || "");
    if (target && target.matches("audio,video")) return target;
    return target && target.querySelector ? target.querySelector("audio,video") : null;
  }

  function mediaSignature(payload) {
    return [String(payload.target || payload.key || ""), String(payload.action || "play"), Math.max(0, Number(payload.time || 0))].join("|");
  }

  function applyMedia(payload, force) {
    var media = mediaTarget(payload);
    if (!media) return;
    var nextMediaSignature = mediaSignature(payload);
    if (!force && nextMediaSignature === lastAppliedMediaSignature) return;
    lastAppliedMediaSignature = nextMediaSignature;
    isolate(media);
    var action = String(payload.action || "play");
    if (Number.isFinite(Number(payload.time))) {
      try { media.currentTime = Math.max(0, Number(payload.time)); } catch (error) {}
    }
    if (action === "play") Promise.resolve(media.play()).catch(function () {
      showShade("Medienfreigabe nötig", "Bitte einmal in die Beameransicht klicken und anschließend den Startbefehl erneut senden.");
    });
    if (action === "pause") media.pause();
    if (action === "stop") { media.pause(); try { media.currentTime = 0; } catch (error) {} }
  }

  function youtubeTarget(payload) {
    var target = targetFor(payload.target || payload.key || "");
    if (!target && payload.id) {
      var escaped = window.CSS && CSS.escape ? CSS.escape(String(payload.id)) : String(payload.id).replace(/[^a-zA-Z0-9_-]/g, "");
      target = document.getElementById(escaped) || document.querySelector('[data-youtube-id="' + escaped + '"],[data-youtube="' + escaped + '"]');
    }
    return target;
  }

  function youtubeSignature(payload) {
    return [String(payload.target || payload.key || ""), String(payload.action || "play"), String(payload.videoId || ""), Math.max(0, Number(payload.start || 0)), Math.max(0, Number(payload.end || 0))].join("|");
  }

  function youtubeStart(box) {
    return Math.max(0, Number(box && (box.dataset.youtubeStart || box.dataset.start) || 0));
  }

  function youtubeEnd(box, start) {
    var end = Math.max(0, Number(box && (box.dataset.youtubeEnd || box.dataset.end) || 0));
    return end > start ? end : 0;
  }

  function applyYoutube(payload, force) {
    var target = youtubeTarget(payload);
    if (!target) return;
    var nextYoutubeSignature = youtubeSignature(payload);
    if (!force && nextYoutubeSignature === lastAppliedYoutubeSignature) return;
    lastAppliedYoutubeSignature = nextYoutubeSignature;
    isolate(target);
    focusTarget(targetKey(target));
    var box = target.matches("[data-youtube-id],[data-youtube]") ? target : target.querySelector("[data-youtube-id],[data-youtube]");
    if (!box) return;
    var id = String(payload.videoId || box.dataset.youtubeId || box.dataset.youtube || "").replace(/[^A-Za-z0-9_-]/g, "");
    if (!id) return;
    var iframe = box.querySelector("iframe");
    var action = String(payload.action || "play");
    /* Ein wiederhergestellter Pause-/Stoppzustand darf keinen neuen Autoplay-
       Frame erzeugen. Erst ein ausdrücklicher Play-Befehl lädt das Medium. */
    if (!iframe && action !== "play") return;
    if (!iframe) {
      iframe = document.createElement("iframe");
      iframe.title = payload.title || box.dataset.youtubeTitle || box.dataset.title || "Video";
      iframe.loading = "eager";
      iframe.allow = "accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share";
      iframe.allowFullscreen = true;
      iframe.referrerPolicy = "strict-origin-when-cross-origin";
      var start = Math.max(0, Number(payload.start || youtubeStart(box)));
      var end = Math.max(0, Number(payload.end || youtubeEnd(box, start)));
      iframe.src = "https://www.youtube-nocookie.com/embed/" + encodeURIComponent(id) + "?rel=0&playsinline=1&enablejsapi=1&autoplay=1" + (start ? "&start=" + Math.floor(start) : "") + (end > start ? "&end=" + Math.floor(end) : "");
      box.replaceChildren(iframe);
      box.classList.add("is-loaded");
    } else {
      try { iframe.contentWindow.postMessage(JSON.stringify({ event: "command", func: action === "pause" ? "pauseVideo" : action === "stop" ? "stopVideo" : "playVideo", args: [] }), "*"); } catch (error) {}
    }
  }

  function ensureLightbox() {
    var dialog = document.querySelector("[data-classroom-lightbox]");
    if (dialog) return dialog;
    dialog = document.createElement("dialog");
    dialog.className = "media-lightbox";
    dialog.setAttribute("data-classroom-lightbox", "");
    dialog.innerHTML = '<div class="media-lightbox-shell"><div class="media-lightbox-head"><strong>Bildansicht</strong><button type="button" aria-label="Bildansicht schließen" data-classroom-lightbox-close>×</button></div><div class="media-lightbox-stage"><img alt=""></div><p class="media-lightbox-caption"></p></div>';
    document.body.appendChild(dialog);
    dialog.querySelector("[data-classroom-lightbox-close]").addEventListener("click", function () { dialog.close(); });
    dialog.addEventListener("click", function (event) { if (event.target === dialog) dialog.close(); });
    return dialog;
  }

  function applyImage(payload) {
    var dialog = ensureLightbox();
    if (payload.action === "close") { if (dialog.open) dialog.close(); return; }
    var target = targetFor(payload.target || payload.key || "");
    var image = payload.src ? { currentSrc: payload.src, src: payload.src, alt: payload.alt || "" } :
      target && target.matches("img") ? target : target && target.querySelector ? target.querySelector("img") : null;
    if (!image) return;
    if (target && target.nodeType === 1) isolate(target);
    var output = dialog.querySelector("img");
    output.src = image.currentSrc || image.src;
    output.alt = payload.alt || image.alt || "Vergrößerte Ansicht";
    dialog.querySelector(".media-lightbox-caption").textContent = payload.caption || output.alt;
    if (!dialog.open) dialog.showModal();
  }

  function apply(command) {
    if (view !== "beamer" || !command || command.moduleId !== moduleId) return;
    var activeRoom = roomCode();
    var commandRoom = classroom.codeClean(command.room || "");
    /* Ein Beamer in einem Klassenraum darf niemals einen älteren, raumlosen
       localStorage-Befehl übernehmen. Genau solche Befehle entstehen, wenn
       die Lehreransicht vor dem Anlegen des Raums bereits gescrollt wurde. */
    if (activeRoom && commandRoom !== activeRoom) return;
    if (!activeRoom && commandRoom) return;
    var sentAt = Number(command.sentAt || 0);
    if (sentAt && sentAt < lastAppliedSentAt) return;
    var nextSignature = signature(command);
    if (!nextSignature || nextSignature === lastSignature) return;
    lastSignature = nextSignature;
    if (sentAt) lastAppliedSentAt = Math.max(lastAppliedSentAt, sentAt);
    activeEnvelope = command;
    var payload = command.payload || {};
    applyPresenterSnapshot(payload._classroom, command.type === "goto" || command.type === "follow", command.type === "media" || command.type === "youtube");
    if (command.type === "goto") focusTarget(payload.step || payload.target || payload.key);
    if (command.type === "follow") applyFollow(payload);
    if (command.type === "join") renderJoin(payload);
    if (command.type === "theme") document.documentElement.dataset.theme = payload.theme === "dark" ? "dark" : "light";
    if (command.type === "dim") {
      beamerDimmed = payload.active !== false;
      beamerDimmed ? showShade(payload.title || "Arbeitsphase", payload.message || "Der Beamer ist vorübergehend abgedunkelt.") : hideShade();
    }
    if (command.type === "media") applyMedia(payload, true);
    if (command.type === "youtube") applyYoutube(payload, true);
    if (command.type === "image") applyImage(payload);
    if (command.type === "stepper") { var stepTarget=targetFor(payload.target||payload.key);if(stepTarget)isolate(stepTarget); }
    if (command.type === "details") { var detail=detailForKey(payload.target||payload.key);if(detail)detail.open=Boolean(payload.open);if(payload.focus)focusTarget(payload.focus); }
    if (command.type === "control") { applyControlState(payload.group,payload.value);if(payload.focus)focusTarget(payload.focus); }
    if (command.type === "interaction" && payload.focus) focusTarget(payload.focus);
    announce(command);
  }

  function receive(raw) {
    var value = raw;
    try { if (typeof value === "string") value = JSON.parse(value); } catch (error) { return; }
    apply(value);
  }

  function openBeamer() {
    var url = classroom.beamerUrl();
    beamerWindow = window.open(url, moduleId + "-beamer");
    return beamerWindow;
  }

  function showJoin(data) {
    var code = classroom.codeClean(data && data.room || roomCode());
    if (code.length !== 6) return Promise.reject(new Error("Bitte zuerst einen Klassenraum öffnen."));
    var payload = {
      room: code,
      label: data && data.label || classroom.state() && classroom.state().label || config.moduleLabel || document.title,
      url: data && data.url || classroom.studentUrl()
    };
    if (view === "beamer") { renderJoin(payload); return Promise.resolve(); }
    if (!data || data.openBeamer !== false) openBeamer();
    return new Promise(function (resolve) { setTimeout(function () { send({ type: "join", payload: payload }).then(resolve); }, 280); });
  }

  function topObstructionBottom() {
    var bottom = 0;
    Array.prototype.slice.call(document.querySelectorAll("header,nav,.site-header,.site-nav,.topbar,[data-sticky-header]")).forEach(function (node) {
      if (!node.getClientRects().length || node.closest(".classroom-presenter-toolbar")) return;
      var style = window.getComputedStyle(node);
      if (style.position !== "fixed" && style.position !== "sticky") return;
      var rect = node.getBoundingClientRect();
      if (rect.top > 4 || rect.bottom <= 0 || rect.width < window.innerWidth * 0.42) return;
      bottom = Math.max(bottom, rect.bottom);
    });
    return Math.min(bottom, window.innerHeight * 0.44);
  }

  function readingLine() {
    var configured = Math.max(90, Number(options.readingLine || 96));
    var clearance = Math.max(22, Math.min(54, window.innerHeight * 0.045));
    return Math.min(window.innerHeight * 0.49, Math.max(configured, topObstructionBottom() + clearance));
  }

  function cueNodes() {
    return Array.prototype.slice.call(document.querySelectorAll(allCueSelector)).filter(function (node) {
      return node.getClientRects().length && !node.matches(".image-open") &&
        !node.closest('[data-rolle="lehrer"],[data-role="teacher"],.teacher,.private,.export-tools');
    });
  }

  function nodeDepth(node) {
    var depth = 0;
    while (node && node.parentElement) { depth += 1; node = node.parentElement; }
    return depth;
  }

  function currentCue() {
    var nodes = cueNodes();
    if (!nodes.length) return null;
    var line = readingLine();
    var current = null;
    var currentTop = -Infinity;
    var upcoming = null;
    var upcomingTop = Infinity;
    nodes.forEach(function (node) {
      var top = node.getBoundingClientRect().top;
      if (top <= line) {
        if (top > currentTop + 0.5 || Math.abs(top - currentTop) <= 0.5 && nodeDepth(node) > nodeDepth(current)) {
          current = node;
          currentTop = top;
        }
      } else if (top < upcomingTop) {
        upcoming = node;
        upcomingTop = top;
      }
    });
    var candidate = current || upcoming || nodes[0];
    if (view !== "teacher") return candidate;

    /* Zwischen zwei verschachtelten Ankern bleibt der zuletzt erreichte
       Inhaltsanker aktiv. Ein kleiner Richtungs-Puffer verhindert, dass
       Trackpad- oder Mausrad-Nachlauf an einer Grenze vor und zurück schaltet. */
    if (teacherStableCue && nodes.indexOf(teacherStableCue) >= 0 && candidate !== teacherStableCue) {
      var stableIndex = nodes.indexOf(teacherStableCue);
      var candidateIndex = nodes.indexOf(candidate);
      var goingDown = (window.scrollY || 0) >= teacherLastScrollY;
      var hysteresis = Math.max(18, Math.min(34, window.innerHeight * 0.028));
      if (goingDown && candidateIndex > stableIndex && candidate.getBoundingClientRect().top > line - hysteresis) candidate = teacherStableCue;
      if (!goingDown && candidateIndex < stableIndex && teacherStableCue.getBoundingClientRect().top < line + hysteresis) candidate = teacherStableCue;
    }
    teacherLastScrollY = window.scrollY || 0;
    teacherStableCue = candidate;
    return candidate;
  }

  function teacherCues() {
    var nodes = cueNodes();
    return nodes.filter(function (node, index, all) {
      var key = targetKey(node);
      return key && all.findIndex(function (candidate) { return targetKey(candidate) === key; }) === index;
    });
  }

  function cueProgress(node) {
    if (!node) return 0;
    var rect = node.getBoundingClientRect();
    var height = Math.max(rect.height || 0, 1);
    var readableTail = Math.min(height, window.innerHeight * 0.58);
    var travel = Math.max(1, height - readableTail);
    return Math.max(0, Math.min(1, (readingLine() - rect.top) / travel));
  }

  function followPayload(node) {
    return { step: targetKey(node), progress: Math.round(cueProgress(node) * 1000) / 1000 };
  }

  function labelForCue(node) {
    if (!node) return "Kein Präsentationshalt";
    var heading = node.matches("h1,h2,h3,h4") ? node : node.querySelector("h1,h2,h3,h4");
    return String(node.dataset.beamerLabel || heading && heading.textContent || targetKey(node)).trim().replace(/\s+/g, " ").slice(0, 90);
  }

  function updateToolbar(node) {
    if (!teacherToolbar) return;
    var cues = teacherCues();
    var current = node || currentCue();
    teacherCueIndex = Math.max(0, cues.indexOf(current));
    var label = teacherToolbar.querySelector("[data-classroom-presenter-current]");
    if (label) label.textContent = labelForCue(cues[teacherCueIndex] || current);
    var previous = teacherToolbar.querySelector("[data-classroom-presenter-prev]");
    var next = teacherToolbar.querySelector("[data-classroom-presenter-next]");
    if (previous) previous.disabled = teacherCueIndex <= 0;
    if (next) next.disabled = teacherCueIndex < 0 || teacherCueIndex >= cues.length - 1;
  }

  function scrollTeacherToCue(node) {
    if (!node) return;
    var top = Math.max(0, window.scrollY + node.getBoundingClientRect().top - readingLine() + 10);
    window.scrollTo({ top: top, behavior: "smooth" });
  }

  function sendCue(node, scrollTeacher) {
    if (teacherManagerActive) {
      showJoin({}).catch(function () {});
      return;
    }
    var key = targetKey(node);
    if (!key) return;
    if (scrollTeacher) scrollTeacherToCue(node);
    updateToolbar(node);
    send({ type: "follow", payload: followPayload(node) });
  }

  function moveCue(delta) {
    var cues = teacherCues();
    if (!cues.length) return;
    var current = currentCue();
    var index = cues.indexOf(current);
    if (index < 0) index = teacherCueIndex >= 0 ? teacherCueIndex : 0;
    sendCue(cues[Math.max(0, Math.min(cues.length - 1, index + delta))], true);
  }

  function nearestInCurrentStage(selector) {
    var cue = currentCue();
    if (!cue) return null;
    var direct = cue.matches(selector) ? cue : cue.querySelector(selector);
    if (direct) return direct;
    var section = cue.matches(sectionSelector) ? cue : cue.closest(sectionSelector);
    if (!section) return null;
    var line = readingLine(), best = null, bestDistance = Infinity;
    section.querySelectorAll(selector).forEach(function (node) {
      if (!node.getClientRects().length) return;
      var rect = node.getBoundingClientRect(), distance = rect.top <= line && rect.bottom >= line ? 0 : Math.min(Math.abs(rect.top - line), Math.abs(rect.bottom - line));
      if (distance < bestDistance) { best = node; bestDistance = distance; }
    });
    return best;
  }

  function currentMedia() {
    return nearestInCurrentStage("audio,video");
  }

  function currentYoutube() {
    return nearestInCurrentStage("[data-youtube-id],[data-youtube]");
  }

  function sendCurrentPlayback(action) {
    var media = currentMedia();
    if (media) {
      var mediaKey = targetKey(media.closest(allCueSelector) || media);
      if (mediaKey) send({ type: "media", payload: { target: mediaKey, action: action, time: media.currentTime || 0 } });
      return;
    }
    var box = currentYoutube();
    if (!box) { updateToolbarStatus("Kein Audio oder Video am aktuellen Präsentationshalt.", true); return; }
    var target = box.closest(allCueSelector) || box;
    var key = targetKey(target);
    if (key) {
      var start = youtubeStart(box);
      send({ type: "youtube", payload: { target: key, action: action, videoId: box.dataset.youtubeId || box.dataset.youtube || "", start: start, end: youtubeEnd(box, start), title: box.dataset.youtubeTitle || box.dataset.title || "Video" } });
      updateToolbarStatus(action === "pause" ? "Video am Beamer pausiert." : "Video an den Beamer gesendet.");
    }
  }

  function updateToolbarStatus(message, error) {
    if (!teacherToolbar) return;
    var status = teacherToolbar.querySelector("[data-classroom-presenter-feedback]");
    if (!status) return;
    status.textContent = message || "";
    status.classList.toggle("is-error", Boolean(error));
  }

  function installTeacherToolbar() {
    if (view !== "teacher" || options.toolbar === false || options.adapterOwnsNavigation) return;
    teacherToolbar = document.createElement("aside");
    teacherToolbar.className = "classroom-presenter-toolbar";
    teacherToolbar.setAttribute("aria-label", "Beameransicht steuern");
    teacherToolbar.innerHTML = '<span class="classroom-presenter-connection"><i aria-hidden="true"></i><span data-classroom-presenter-status>Beamer nicht verbunden</span></span>' +
      '<button type="button" data-classroom-presenter-open>Beamer öffnen</button>' +
      '<button type="button" data-classroom-presenter-join>Beitritt zeigen</button>' +
      '<button type="button" data-classroom-presenter-prev title="Vorherigen Präsentationshalt zeigen">←</button>' +
      '<button type="button" data-classroom-presenter-here>Hier zeigen</button>' +
      '<button type="button" data-classroom-presenter-next title="Nächsten Präsentationshalt zeigen">→</button>' +
      '<button type="button" data-classroom-presenter-follow aria-pressed="' + String(teacherFollowEnabled) + '" title="Automatisches Folgen beim Scrollen ein- oder ausschalten">Folgen: ' + (teacherFollowEnabled ? "an" : "aus") + '</button>' +
      '<button type="button" data-classroom-presenter-play title="Aktuelles Audio oder Video am Beamer abspielen">▶</button>' +
      '<button type="button" data-classroom-presenter-pause title="Aktuelles Audio oder Video am Beamer pausieren">Ⅱ</button>' +
      '<details class="classroom-presenter-timer" data-classroom-presenter-timer><summary>Timer</summary><div><label>Minuten<input type="number" min="1" max="180" step="1" value="10" data-presenter-timer-minutes></label><label>Bezeichnung<input type="text" maxlength="48" value="Arbeitszeit" data-presenter-timer-label></label><span class="classroom-presenter-timer-actions"><button type="button" data-presenter-timer-start>Start</button><button type="button" data-presenter-timer-toggle disabled>Pause</button><button type="button" data-presenter-timer-add="60" disabled>+1</button><button type="button" data-presenter-timer-add="180" disabled>+3</button><button type="button" data-presenter-timer-reset disabled>Aus</button></span><small data-presenter-timer-status>Noch kein Timer aktiv.</small></div></details>' +
      '<button type="button" data-classroom-presenter-dim aria-pressed="false">Abblenden</button>' +
      '<span class="classroom-presenter-current" data-classroom-presenter-current>Kein Präsentationshalt</span><span class="classroom-presenter-feedback" data-classroom-presenter-feedback aria-live="polite"></span>';
    document.body.appendChild(teacherToolbar);
    teacherToolbar.querySelector("[data-classroom-presenter-open]").addEventListener("click", openBeamer);
    teacherToolbar.querySelector("[data-classroom-presenter-join]").addEventListener("click", function () { showJoin({}); });
    teacherToolbar.querySelector("[data-classroom-presenter-prev]").addEventListener("click", function () { moveCue(-1); });
    teacherToolbar.querySelector("[data-classroom-presenter-next]").addEventListener("click", function () { moveCue(1); });
    teacherToolbar.querySelector("[data-classroom-presenter-here]").addEventListener("click", function () { sendCue(currentCue(), false); });
    teacherToolbar.querySelector("[data-classroom-presenter-follow]").addEventListener("click", function (event) {
      teacherFollowEnabled = !teacherFollowEnabled;
      event.currentTarget.setAttribute("aria-pressed", String(teacherFollowEnabled));
      event.currentTarget.textContent = teacherFollowEnabled ? "Folgen: an" : "Folgen: aus";
      if (teacherFollowEnabled) sendCue(currentCue(), false);
    });
    teacherToolbar.querySelector("[data-classroom-presenter-play]").addEventListener("click", function () { sendCurrentPlayback("play"); });
    teacherToolbar.querySelector("[data-classroom-presenter-pause]").addEventListener("click", function () { sendCurrentPlayback("pause"); });
    teacherToolbar.querySelector("[data-classroom-presenter-dim]").addEventListener("click", function (event) { teacherDimmed = !teacherDimmed; event.currentTarget.setAttribute("aria-pressed", String(teacherDimmed)); event.currentTarget.textContent = teacherDimmed ? "Einblenden" : "Abblenden"; send({ type: "dim", payload: { active: teacherDimmed, title: "Arbeitsphase", message: "Der nächste Inhalt wird erst auf Signal der Lehrkraft sichtbar." } }); });
    installToolbarTimer();
    document.addEventListener("religion-classroom-state", function (event) {
      var data = event.detail && event.detail.data || {};
      var active = Number(data.beamerHeartbeatAt || 0) * 1000 > Date.now() - 15000;
      teacherToolbar.classList.toggle("is-connected", active);
      var status = teacherToolbar.querySelector("[data-classroom-presenter-status]");
      if (status) status.textContent = active ? "Beamer verbunden" : "Beamer nicht verbunden";
    });
    updateToolbar();
  }

  function installToolbarTimer() {
    if (!teacherToolbar) return;
    var menu = teacherToolbar.querySelector("[data-classroom-presenter-timer]");
    if (!menu) return;
    var panel = menu.querySelector(":scope > div"), minutes = menu.querySelector("[data-presenter-timer-minutes]"), label = menu.querySelector("[data-presenter-timer-label]"), toggle = menu.querySelector("[data-presenter-timer-toggle]"), adds = Array.prototype.slice.call(menu.querySelectorAll("[data-presenter-timer-add]")), reset = menu.querySelector("[data-presenter-timer-reset]"), status = menu.querySelector("[data-presenter-timer-status]"), summary = menu.querySelector("summary");
    function timerApi() { return window.RELIGION_LIVE_TIMER; }
    function run(action, success) {
      var api = timerApi();
      if (!api) { status.textContent = "Timersteuerung wird noch geladen. Bitte kurz erneut versuchen."; return; }
      status.textContent = "Timer wird aktualisiert …";
      var result;
      try { result = action(api); } catch (error) { status.textContent = error.message || "Timer konnte nicht aktualisiert werden."; return; }
      Promise.resolve(result).then(function () { status.textContent = success; }).catch(function (error) { status.textContent = error.message || "Timer konnte nicht aktualisiert werden."; });
    }
    function render(detail) {
      var activeApi = timerApi();
      if (!activeApi) { summary.textContent = "Timer"; status.textContent = "Timersteuerung wird geladen …"; toggle.disabled = true; adds.forEach(function (button) { button.disabled = true; }); reset.disabled = true; return; }
      var timer = detail && detail.timer || activeApi.state().timer, remaining = detail && Number(detail.remainingMs);
      if (!Number.isFinite(remaining)) remaining = activeApi.state().remainingMs;
      var active = Boolean(timer), seconds = Math.max(0, Math.ceil((remaining || 0) / 1000)), value = String(Math.floor(seconds / 60)).padStart(2, "0") + ":" + String(seconds % 60).padStart(2, "0");
      summary.textContent = active ? "Timer " + value : "Timer";
      status.textContent = active ? (timer.label || "Arbeitszeit") + " · " + value + " · " + (seconds <= 0 ? "Zeit ist um" : timer.running ? "läuft" : "pausiert") : "Noch kein Timer aktiv.";
      toggle.disabled = !active; toggle.textContent = timer && timer.running ? "Pause" : "Fortsetzen"; adds.forEach(function (button) { button.disabled = !active; }); reset.disabled = !active;
    }
    menu.querySelector("[data-presenter-timer-start]").addEventListener("click", function () { var value = Math.max(1, Math.min(180, Number(minutes.value) || 10)); minutes.value = value; run(function (api) { return api.start(value, String(label.value || "Arbeitszeit").trim() || "Arbeitszeit"); }, "Timer gestartet."); });
    toggle.addEventListener("click", function () { run(function (api) { return api.pauseResume(); }, "Timerstatus geändert."); });
    adds.forEach(function (button) { button.addEventListener("click", function () { run(function (api) { return api.add(Number(button.dataset.presenterTimerAdd) || 0); }, "Zeit ergänzt."); }); });
    reset.addEventListener("click", function () { run(function (api) { return api.reset(); }, "Timer ausgeblendet."); });
    document.addEventListener("religion-classroom-timer-state", function (event) { render(event.detail || {}); });
    document.addEventListener("religion-classroom-timer-tick", function (event) { render(event.detail || {}); });
    document.addEventListener("religion-classroom-timer-ready", function () { render({}); });
    render({});
    if (panel) {
      panel.classList.add("classroom-presenter-timer-panel");
      panel.id = moduleId + "-presenter-timer-panel";
      panel.setAttribute("role", "dialog");
      panel.setAttribute("aria-label", "Gemeinsamen Beamer-Timer steuern");
      summary.setAttribute("aria-controls", panel.id);
      document.body.appendChild(panel);
      function syncPanel() {
        panel.hidden = !menu.open;
        summary.setAttribute("aria-expanded", String(menu.open));
      }
      menu.addEventListener("toggle", syncPanel);
      document.addEventListener("click", function (event) {
        if (menu.open && !menu.contains(event.target) && !panel.contains(event.target)) menu.open = false;
      });
      document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && menu.open) { menu.open = false; summary.focus(); }
      });
      syncPanel();
    }
  }

  function installHeaderCollapse() {
    if ((view !== "teacher" && view !== "student") || options.collapsibleHeader === false) return;
    var header = document.querySelector(String(options.headerSelector || ".site-header,body > header,header"));
    if (!header) return;
    var candidates = Array.prototype.slice.call(header.querySelectorAll(String(options.headerCollapseSelector || ".header-brand,.brand,.view-switcher,.classroom-view-switcher,.header-actions,[data-classroom-header-optional]")));
    candidates = candidates.filter(function (node) {
      var navigation = node.closest(".nav,.site-nav");
      return !node.matches(".nav,.site-nav") && (!navigation || navigation === header);
    });
    if (!candidates.length) return;
    candidates.forEach(function (node) { node.setAttribute("data-classroom-header-collapsible", ""); });
    header.setAttribute("data-classroom-collapsible-header", "");
    headerToggle = document.createElement("button");
    headerToggle.type = "button";
    headerToggle.className = "classroom-header-collapse-toggle";
    var key = moduleId + "-" + view + "-header-collapsed-v1";
    var collapsed = false;
    try { collapsed = localStorage.getItem(key) === "true"; } catch (error) {}
    function render() {
      header.setAttribute("data-classroom-header-collapsed", String(collapsed));
      headerToggle.setAttribute("aria-expanded", String(!collapsed));
      headerToggle.textContent = collapsed ? "Kopf zeigen" : "Kopf einklappen";
      headerToggle.title = collapsed ? "Vorbereitungs- und Ansichtszeilen einblenden" : "Vorbereitungs- und Ansichtszeilen ausblenden";
      try { localStorage.setItem(key, String(collapsed)); } catch (error) {}
      if (view === "teacher") setTimeout(function () { if (teacherFollowEnabled) sendCue(currentCue(), false); }, 40);
    }
    headerToggle.addEventListener("click", function () { collapsed = !collapsed; render(); });
    header.appendChild(headerToggle);
    render();
  }

  function installThemeSync() {
    if (view !== "teacher") return;
    var lastTheme = currentTheme();
    presenterState.theme = lastTheme;
    var pending = false;
    function publish() {
      pending = false;
      var theme = currentTheme();
      if (theme === lastTheme) return;
      lastTheme = theme;
      presenterState.theme = theme;
      send({ type: "theme", payload: { theme: theme } });
    }
    var observer = new MutationObserver(function () { if (pending) return; pending = true; requestAnimationFrame(publish); });
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ["class", "data-theme"] });
    if (document.body) observer.observe(document.body, { attributes: true, attributeFilter: ["class", "data-theme"] });
  }

  function installTeacherBindings() {
    if (view !== "teacher") return;
    if (!window.RELIGION_OPEN_BEAMER) window.RELIGION_OPEN_BEAMER = openBeamer;
    if (!window.RELIGION_BEAMER_JOIN) window.RELIGION_BEAMER_JOIN = { show: showJoin };
    document.addEventListener("religion-classroom-manager-visibility", function (event) {
      var wasActive = teacherManagerActive;
      teacherManagerActive = Boolean(event.detail && event.detail.visible && classroom.codeClean(event.detail.room || roomCode()).length === 6);
      if (wasActive && !teacherManagerActive && teacherFollowEnabled) {
        setTimeout(function () { sendCue(currentCue(), false); }, 40);
      }
    });
    if (!window.RELIGION_BEAMER_FOCUS_POLL) window.RELIGION_BEAMER_FOCUS_POLL = function (id) {
      var escaped = window.CSS && CSS.escape ? CSS.escape(String(id)) : String(id).replace(/[^a-zA-Z0-9_-]/g, "");
      var node = document.querySelector('[data-live-poll="' + escaped + '"],[data-classroom-poll="' + escaped + '"],[data-live-poll-host="' + escaped + '"],[data-classroom-quiz="' + escaped + '"]');
      var key = targetKey(node && (node.closest(allCueSelector) || node));
      if (key) send({ type: "goto", payload: { step: key } });
    };

    document.addEventListener("toggle", function (event) {
      var detail = event.target;
      if (!detail || detail.tagName !== "DETAILS" || isExcludedFlowNode(detail)) return;
      var key = String(detail.dataset.classroomDetailsKey || "");
      if (!key) return;
      presenterState.details[key] = Boolean(detail.open);
      var cue = detail.closest(allCueSelector) || detail.closest(sectionSelector);
      send({ type: "details", payload: { target: key, open: Boolean(detail.open), focus: targetKey(cue) } });
    }, true);

    document.addEventListener("click", function (event) {
      var control = event.target.closest && event.target.closest("[data-classroom-control-group][data-classroom-control-value]");
      if (!control || isExcludedFlowNode(control)) return;
      var group = String(control.dataset.classroomControlGroup || ""), value = String(control.dataset.classroomControlValue || "");
      if (!group) return;
      presenterState.controls[group] = value;
      var cue = control.closest(allCueSelector) || control.closest(sectionSelector);
      send({ type: "control", payload: { group: group, value: value, focus: targetKey(cue) } });
    }, true);

    document.addEventListener("input", function (event) {
      var range = event.target && event.target.closest && event.target.closest("[data-classroom-range-group]");
      if (!range || isExcludedFlowNode(range)) return;
      var group = String(range.dataset.classroomRangeGroup || "");
      if (!group) return;
      var value = String(range.value == null ? "" : range.value);
      presenterState.controls[group] = value;
      var cue = range.closest(allCueSelector) || range.closest(sectionSelector);
      send({ type: "control", payload: { group: group, value: value, focus: targetKey(cue) } });
    }, true);

    if (!options.adapterOwnsMedia) {
      document.addEventListener("play", function (event) {
        if (!event.target.matches || !event.target.matches("audio,video")) return;
        var key = targetKey(event.target.closest(allCueSelector) || event.target);
        if (key) send({ type: "media", payload: { target: key, action: "play", time: event.target.currentTime || 0 } });
      }, true);
      document.addEventListener("pause", function (event) {
        if (!event.target.matches || !event.target.matches("audio,video") || event.target.ended) return;
        var key = targetKey(event.target.closest(allCueSelector) || event.target);
        if (key) send({ type: "media", payload: { target: key, action: "pause", time: event.target.currentTime || 0 } });
      }, true);
      document.addEventListener("click", function (event) {
        var image = event.target.closest && event.target.closest("img[data-zoomable],.image-open img");
        if (!image) return;
        var key = targetKey(image.closest(allCueSelector) || image);
        var opener = image.closest("[data-full-image]");
        var rawSource = opener && opener.dataset.fullImage ? opener.dataset.fullImage : image.currentSrc || image.src || "";
        var exactSource = rawSource;
        try { exactSource = new URL(rawSource, document.baseURI).href; } catch (error) {}
        var figure = image.closest("figure");
        var captionNode = figure && figure.querySelector("figcaption");
        if (key) {
          teacherImageOpen = true;
          send({
          type: "image",
          payload: {
            target: key,
            action: "open",
            src: exactSource,
            alt: image.alt || "",
            caption: captionNode ? captionNode.textContent.trim() : image.alt || ""
          }
          });
        }
      }, true);
      document.addEventListener("close", function (event) {
        var dialog = event.target;
        if (!teacherImageOpen || !dialog || !dialog.matches || !dialog.matches("dialog,.image-dialog,.media-lightbox,[data-image-dialog]")) return;
        teacherImageOpen = false;
        send({ type: "image", payload: { action: "close" } });
      }, true);
      document.addEventListener("click", function (event) {
        var button = event.target.closest && event.target.closest("[data-youtube-load],.media-load");
        if (!button) return;
        var box = button.closest("[data-youtube-id],[data-youtube]");
        var target = box && (box.closest(allCueSelector) || box);
        var key = targetKey(target);
        if (key) {
          event.preventDefault();
          event.stopPropagation();
          var start = youtubeStart(box);
          var state = classroom.state && classroom.state();
          var beamerConnected = Boolean(state && Number(state.beamerHeartbeatAt || 0) > 0 && Date.now() / 1000 - Number(state.beamerHeartbeatAt) < 14);
          var originalLabel = button.dataset.youtubeOriginalLabel || button.textContent;
          button.dataset.youtubeOriginalLabel = originalLabel;
          send({ type: "youtube", payload: { target: key, action: "play", videoId: box.dataset.youtubeId || box.dataset.youtube || "", start: start, end: youtubeEnd(box, start), title: box.dataset.youtubeTitle || box.dataset.title || "Video" } });
          button.textContent = beamerConnected ? "Am Beamer gestartet" : "Gesendet · Beamer nicht verbunden";
          button.setAttribute("aria-live", "polite");
          updateToolbarStatus(beamerConnected ? "Video am Beamer gestartet." : "Video gesendet; der Beamer ist derzeit nicht verbunden.", !beamerConnected);
          window.setTimeout(function () {
            if (!document.contains(button)) return;
            button.textContent = originalLabel;
            button.removeAttribute("aria-live");
          }, 3200);
        }
      }, true);
    }

    if (!options.adapterOwnsNavigation && options.autoFollow !== false) {
      var pending = false;
      function publishFollow(persist) {
        if (teacherManagerActive) return;
        var node = currentCue();
        var payload = followPayload(node);
        updateToolbar(node);
        if (!teacherFollowEnabled || !payload.step) return;
        var marker = payload.step + ":" + Math.round(payload.progress * 50);
        if (!persist && marker === lastTransientFollow) return;
        lastTransientFollow = marker;
        if (persist) send({ type: "follow", payload: payload });
        else sendTransient({ type: "follow", payload: payload });
      }
      window.addEventListener("scroll", function () {
        if (teacherFollowTimer) clearTimeout(teacherFollowTimer);
        teacherFollowTimer = setTimeout(function () {
          if (teacherTransientTimer) { clearTimeout(teacherTransientTimer); teacherTransientTimer = null; }
          publishFollow(true);
        }, 180);
        if (pending || teacherTransientTimer) return;
        var elapsed = Date.now() - lastTransientAt;
        function publishFrame() {
          teacherTransientTimer = null;
          pending = true;
          requestAnimationFrame(function () {
            pending = false;
            lastTransientAt = Date.now();
            publishFollow(false);
          });
        }
        if (elapsed >= 64) publishFrame();
        else teacherTransientTimer = setTimeout(publishFrame, 64 - elapsed);
      }, { passive: true });
    }
  }

  function installBeamerBindings() {
    if (view !== "beamer") return;
    prepareBeamerStages();
    window.addEventListener("storage", function (event) { if (event.key === channelKey && event.newValue) receive(event.newValue); });
    window.addEventListener("message", function (event) { if (event.origin === location.origin && event.data && event.data.religionClassroomPresentation) receive(event.data.religionClassroomPresentation); });
    if (broadcast) broadcast.addEventListener("message", function (event) { receive(event.data); });
    try { receive(localStorage.getItem(channelKey)); } catch (error) {}
    document.addEventListener("religion-classroom-state", function (event) {
      var data = event.detail && event.detail.data;
      if (data && data.presentation) receive(data.presentation);
    });
    document.addEventListener("religion-course-material-ready", function () {
      if (!activeEnvelope) return;
      var payload = activeEnvelope.payload || {};
      var pending = activeEnvelope.type === "youtube" ? payload : payload._classroom && payload._classroom.youtube;
      if (pending && pending.target) applyYoutube(pending, true);
    });
    function heartbeat() {
      if (!roomCode()) return;
      classroom.request("beamer_heartbeat", { room: roomCode() }).catch(function () {});
    }
    heartbeat();
    setInterval(heartbeat, 5000);
  }

  window.RELIGION_PRESENTATION = {
    version: "1.2.8",
    send: send,
    goto: function (key) { return send({ type: "goto", payload: { step: key } }); },
    join: showJoin,
    media: function (target, action, time) { return send({ type: "media", payload: { target: target, action: action, time: time } }); },
    image: function (target, action) { return send({ type: "image", payload: { target: target, action: action || "open" } }); },
    stepper: function (target, step) { return send({ type: "stepper", payload: { target: target, step: step } }); },
    current: function () { return activeEnvelope; },
    debug: function () {
      var cue = currentCue();
      var currentSection = document.querySelector(".classroom-stage-current");
      return {
        view: view,
        cue: targetKey(cue),
        cueLabel: labelForCue(cue),
        cueProgress: cueProgress(cue),
        cueCount: view === "teacher" ? teacherCues().length : document.querySelectorAll(allCueSelector).length,
        readingLine: readingLine(),
        obstructionBottom: topObstructionBottom(),
        followEnabled: teacherFollowEnabled,
        beamerScrollTop: currentSection ? currentSection.scrollTop : 0,
        beamerSection: currentSection ? targetKey(currentSection) : ""
      };
    },
    apply: apply,
    openBeamer: openBeamer
  };

  prepareFlowCues();
  prepareSynchronizedState();
  installTeacherBindings();
  installTeacherToolbar();
  installThemeSync();
  installHeaderCollapse();
  installBeamerBindings();
}());
