(function () {
  "use strict";

  var config = window.RELIGION_CLASSROOM_CONFIG || {};
  var view = String(config.view || window.RELIGION_VIEW || "student");
  var endpoint = String(config.feedbackEndpoint || "");
  if (view !== "student" || !endpoint || !window.fetch) return;

  var widgets = [];
  var availabilityCache = new Map();
  var clientId = readClientId();
  var room = "";

  function readClientId() {
    var key = "religion-feedback-client-v1";
    var value = "";
    try { value = localStorage.getItem(key) || ""; } catch (error) {}
    if (!/^[a-zA-Z0-9_-]{20,80}$/.test(value)) {
      var bytes = new Uint8Array(18);
      crypto.getRandomValues(bytes);
      value = Array.from(bytes, function (byte) {
        return byte.toString(16).padStart(2, "0");
      }).join("");
      try { localStorage.setItem(key, value); } catch (error) {}
    }
    return value;
  }

  function api(action, data) {
    return fetch(endpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      credentials: "same-origin",
      cache: "no-store",
      body: JSON.stringify(Object.assign({ action: action }, data || {}))
    }).then(function (response) {
      return response.json().catch(function () {
        return { ok: false, message: "Der Server hat keine lesbare Antwort geliefert." };
      }).then(function (payload) {
        if (!response.ok || !payload.ok) {
          var error = new Error(payload.message || "KI-Feedback ist momentan nicht verfügbar.");
          error.status = response.status;
          throw error;
        }
        return payload;
      });
    });
  }

  function roomCode() {
    try {
      if (window.RELIGION_CLASSROOM && typeof window.RELIGION_CLASSROOM.room === "function") {
        return String(window.RELIGION_CLASSROOM.room() || "");
      }
    } catch (error) {}
    return room;
  }

  function safeList(values) {
    return Array.isArray(values)
      ? values.map(function (value) { return String(value || "").trim(); }).filter(Boolean)
      : [];
  }

  function fieldLabel(input, index) {
    var explicit = String(input.dataset.feedbackPart || "").trim();
    if (explicit) return explicit;
    if (input.id) {
      var label = document.querySelector('label[for="' + CSS.escape(input.id) + '"]');
      if (label) return String(label.textContent || "").replace(/\s+/g, " ").trim();
    }
    var parentLabel = input.closest("label");
    if (parentLabel) {
      var clone = parentLabel.cloneNode(true);
      clone.querySelectorAll("textarea,input,select,button").forEach(function (node) { node.remove(); });
      var text = String(clone.textContent || "").replace(/\s+/g, " ").trim();
      if (text) return text;
    }
    return "Teil " + (index + 1);
  }

  function answerLength(widget) {
    return widget.inputs.reduce(function (total, input) {
      return total + String(input.value || "").trim().length;
    }, 0);
  }

  function bundledAnswer(widget) {
    if (widget.inputs.length === 1 && !widget.inputs[0].dataset.feedbackPart) {
      return String(widget.inputs[0].value || "").trim();
    }
    return widget.inputs.map(function (input, index) {
      var value = String(input.value || "").trim();
      return "## " + fieldLabel(input, index) + "\n" + (value || "[nicht bearbeitet]");
    }).join("\n\n");
  }

  function isJudgementOperator(operator) {
    return /(beurteilen|bewerten|erörtern|stellung nehmen|stellungnahme)/i.test(String(operator || ""));
  }

  function renderFeedback(target, payload) {
    var feedback = payload.feedback || {};
    target.innerHTML = "";
    var title = document.createElement("h4");
    title.textContent = "Dein formatives KI-Feedback";
    target.appendChild(title);
    var overall = document.createElement("p");
    overall.textContent = String(feedback.overall || "");
    target.appendChild(overall);
    [["Das gelingt bereits", safeList(feedback.strengths)], ["Nächste Überarbeitungsschritte", safeList(feedback.next_steps)]].forEach(function (group) {
      var heading = document.createElement("h5");
      heading.textContent = group[0];
      target.appendChild(heading);
      var list = document.createElement("ul");
      group[1].forEach(function (value) {
        var item = document.createElement("li");
        item.textContent = value;
        list.appendChild(item);
      });
      target.appendChild(list);
    });
    [["Operator", feedback.operator_check], ["Fachlichkeit", feedback.subject_check], ["Überarbeitungsimpuls", feedback.revision_prompt]].forEach(function (row) {
      var paragraph = document.createElement("p");
      var strong = document.createElement("strong");
      strong.textContent = row[0] + ": ";
      paragraph.appendChild(strong);
      paragraph.appendChild(document.createTextNode(String(row[1] || "")));
      target.appendChild(paragraph);
    });
    if (feedback.privacy_note) {
      var privacy = document.createElement("p");
      privacy.className = "ai-feedback-privacy";
      privacy.textContent = String(feedback.privacy_note);
      target.appendChild(privacy);
    }
    var notice = document.createElement("small");
    notice.textContent = String(payload.notice || "Formative Überarbeitungshilfe – keine Note.");
    target.appendChild(notice);
    target.hidden = false;
  }

  function setState(widget, message, error) {
    widget.status.textContent = message || "";
    widget.status.classList.toggle("error", Boolean(error));
  }

  function updateButton(widget) {
    var code = roomCode();
    var length = answerLength(widget);
    var minimum = Number(widget.availability && widget.availability.minimumChars || 180);
    var maximum = Number(widget.availability && widget.availability.maximumChars || 9000);
    widget.count.textContent = length + " Zeichen";
    if (!code) {
      widget.button.disabled = true;
      setState(widget, "Verbinde dich zuerst mit dem Klassenraum.");
      return;
    }
    if (!widget.availability || !widget.availability.available) {
      widget.button.disabled = true;
      setState(widget, widget.availability && widget.availability.reason || "Verfügbarkeit wird geprüft …");
      return;
    }
    widget.judgementNote.hidden = !isJudgementOperator(widget.availability.operator);
    if (length < minimum) {
      widget.button.disabled = true;
      setState(widget, "Noch " + (minimum - length) + " Zeichen bis zu einer hinreichend ausgearbeiteten Lösung.");
      return;
    }
    if (length > maximum) {
      widget.button.disabled = true;
      setState(widget, "Die Lösung ist " + (length - maximum) + " Zeichen länger als der Feedbackrahmen. Kürze sie zuerst.", true);
      return;
    }
    widget.button.disabled = false;
    setState(widget, "Feedback prüft Operator " + widget.availability.operator + " · AFB " + widget.availability.afb + ".");
  }

  function checkAvailability(widget, force) {
    var code = roomCode();
    if (!code) {
      widget.availability = null;
      updateButton(widget);
      return Promise.resolve();
    }
    var key = code + "|" + widget.taskId;
    var cached = availabilityCache.get(key);
    if (!force && cached && Date.now() - cached.at < 30000) {
      widget.availability = cached.value;
      updateButton(widget);
      return Promise.resolve();
    }
    return api("availability", { room: code, taskId: widget.taskId }).then(function (data) {
      widget.availability = data;
      availabilityCache.set(key, { at: Date.now(), value: data });
      updateButton(widget);
    }).catch(function (error) {
      widget.availability = { available: false, reason: error.message };
      updateButton(widget);
    });
  }

  function buildWidget(host, index) {
    if (host.dataset.feedbackInitialized === "true") return;
    var inputs = Array.from(host.querySelectorAll("textarea,input[type='text']")).filter(function (input) {
      return input.dataset.feedbackIgnore !== "true";
    });
    if (!inputs.length) return;
    var taskId = String(host.dataset.feedbackTask || "");
    if (!taskId) return;
    host.dataset.feedbackInitialized = "true";

    var panel = document.createElement("section");
    panel.className = "ai-feedback-widget";
    panel.setAttribute("aria-labelledby", "ai-feedback-title-" + index);
    panel.innerHTML = '<div class="ai-feedback-head"><div><p class="live-inline-eyebrow">Optionale Überarbeitungshilfe</p><h4 id="ai-feedback-title-' + index + '">KI-Feedback zur eigenen Lösung</h4></div><span data-feedback-count>0 Zeichen</span></div><p>Erst selbst lösen, dann gezielt überarbeiten. Bitte keine Namen oder sensiblen persönlichen Angaben eingeben. <a href="/datenschutz-lernplattform/" target="_blank" rel="noopener noreferrer">Was wird übertragen?</a></p><p class="ai-feedback-judgement" data-feedback-judgement hidden>Bei einem Urteil wird nicht deine persönliche Meinung bewertet. Entscheidend sind Sachbezug, nachvollziehbare Kriterien, Argumente, Gegenargumente und die Begründung deines Ergebnisses. Auch unterschiedliche Urteile können fachlich tragfähig sein.</p><button class="live-btn" type="button" data-feedback-generate disabled>KI-Feedback generieren</button><p class="live-status" data-feedback-status role="status" aria-live="polite"></p><div class="ai-feedback-result" data-feedback-result hidden></div>';
    host.appendChild(panel);

    var widget = {
      host: host,
      inputs: inputs,
      taskId: taskId,
      panel: panel,
      button: panel.querySelector("[data-feedback-generate]"),
      status: panel.querySelector("[data-feedback-status]"),
      count: panel.querySelector("[data-feedback-count]"),
      judgementNote: panel.querySelector("[data-feedback-judgement]"),
      result: panel.querySelector("[data-feedback-result]"),
      availability: null
    };
    inputs.forEach(function (input) {
      input.addEventListener("input", function () { updateButton(widget); });
    });
    widget.button.addEventListener("click", function () {
      var code = roomCode();
      widget.button.disabled = true;
      widget.result.hidden = true;
      setState(widget, "Feedback wird erzeugt …");
      api("generate", { room: code, taskId: taskId, answer: bundledAnswer(widget), clientId: clientId }).then(function (data) {
        renderFeedback(widget.result, data);
        setState(widget, "Rückmeldung bereit. Überarbeite nun gezielt deinen Text.");
        updateButton(widget);
      }).catch(function (error) {
        setState(widget, error.message, true);
        updateButton(widget);
      });
    });
    widgets.push(widget);
    checkAvailability(widget, false);
  }

  document.querySelectorAll("[data-feedback-task]").forEach(buildWidget);
  document.addEventListener("religion-classroom-state", function (event) {
    var next = String(event.detail && event.detail.room || "");
    if (next !== room) {
      room = next;
      widgets.forEach(function (widget) {
        widget.availability = null;
        checkAvailability(widget, true);
      });
    }
  });
  setInterval(function () {
    widgets.forEach(function (widget) { checkAvailability(widget, false); });
  }, 30000);
})();
