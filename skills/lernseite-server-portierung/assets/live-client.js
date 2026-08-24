(() => {
  'use strict';

  const config = Object.assign({
    api: 'api/live.php',
    view: document.documentElement.dataset.liveView || 'student',
    root: '[data-live-root]',
    roomParameter: 'raum',
    storagePrefix: 'lernseite-live',
    refreshMs: 2500,
    presets: []
  }, window.LernseiteLiveConfig || {});

  const root = document.querySelector(config.root);
  if (!root) return;

  const state = {
    room: new URLSearchParams(location.search).get(config.roomParameter) || sessionStorage.getItem(`${config.storagePrefix}:room`) || '',
    data: null,
    busy: false,
    message: '',
    error: ''
  };

  const node = (tag, attrs = {}, children = []) => {
    const element = document.createElement(tag);
    Object.entries(attrs).forEach(([key, value]) => {
      if (key === 'class') element.className = value;
      else if (key === 'text') element.textContent = value;
      else if (key.startsWith('on')) element.addEventListener(key.slice(2), value);
      else if (value !== false && value != null) element.setAttribute(key, String(value));
    });
    (Array.isArray(children) ? children : [children]).forEach(child => {
      if (child) element.append(child);
    });
    return element;
  };

  const setMessage = (message = '', error = '') => {
    state.message = message;
    state.error = error;
    render();
  };

  async function request(action, payload = {}) {
    const response = await fetch(config.api, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({action, ...payload})
    });
    let body = {};
    try { body = await response.json(); } catch (_) { /* Antwort wird unten abgefangen. */ }
    if (!response.ok || !body.ok) throw new Error(body.message || `Serverfehler ${response.status}`);
    return body;
  }

  async function perform(action, payload, success = '') {
    if (state.busy) return;
    state.busy = true;
    setMessage();
    try {
      state.data = await request(action, payload);
      if (state.data.room) {
        state.room = state.data.room;
        sessionStorage.setItem(`${config.storagePrefix}:room`, state.room);
      }
      state.message = success;
    } catch (error) {
      state.error = error.message;
    } finally {
      state.busy = false;
      render();
    }
  }

  const statusBlock = () => {
    if (!state.message && !state.error) return null;
    return node('p', {
      class: `live-message${state.error ? ' is-error' : ''}`,
      role: state.error ? 'alert' : 'status',
      text: state.error || state.message
    });
  };

  const roomHeader = () => node('header', {class: 'live-room-header'}, [
    node('div', {}, [
      node('span', {class: 'live-kicker', text: state.data?.label || 'Live-Abstimmung'}),
      node('h3', {text: `Raum ${state.room}`})
    ]),
    node('button', {
      type: 'button', class: 'live-button is-quiet', text: 'Raum verlassen',
      onclick: () => {
        sessionStorage.removeItem(`${config.storagePrefix}:room`);
        state.room = '';
        state.data = null;
        history.replaceState(null, '', location.pathname + location.hash);
        render();
      }
    })
  ]);

  const joinForm = () => {
    const input = node('input', {name: 'room', maxlength: '6', pattern: '[A-Za-z2-9]{6}', autocomplete: 'off', required: true, 'aria-label': 'Raumcode'});
    input.value = state.room;
    return node('form', {
      class: 'live-card live-join',
      onsubmit: event => {
        event.preventDefault();
        const code = input.value.trim().toUpperCase();
        if (!/^[A-Z2-9]{6}$/.test(code)) return setMessage('', 'Bitte einen gültigen sechsstelligen Raumcode eingeben.');
        state.room = code;
        sessionStorage.setItem(`${config.storagePrefix}:room`, code);
        history.replaceState(null, '', `${location.pathname}?${config.roomParameter}=${encodeURIComponent(code)}${location.hash}`);
        perform('status', {room: code});
      }
    }, [
      node('h3', {text: config.view === 'beamer' ? 'Abstimmung anzeigen' : 'An Abstimmung teilnehmen'}),
      node('p', {text: 'Den Raumcode von der Lehrkraft eingeben. Es werden keine Namen erfasst.'}),
      node('div', {class: 'live-inline'}, [input, node('button', {class: 'live-button', type: 'submit', text: 'Öffnen'})])
    ]);
  };

  const createForm = () => {
    const label = node('input', {name: 'label', maxlength: '60', autocomplete: 'off', placeholder: 'z. B. 11a · Stunde 3'});
    return node('form', {
      class: 'live-card',
      onsubmit: event => {
        event.preventDefault();
        perform('create', {label: label.value.trim()}, 'Raum erstellt.');
      }
    }, [
      node('h3', {text: 'Neuen Abstimmungsraum erstellen'}),
      node('p', {text: 'Für parallelen Unterricht erzeugt jede Lehrkraft einen eigenen Code. Keine Namen in die Bezeichnung schreiben.'}),
      node('label', {text: 'Raumbezeichnung (optional)'}), label,
      node('button', {class: 'live-button', type: 'submit', text: 'Raum erstellen'})
    ]);
  };

  const voteKey = poll => `${config.storagePrefix}:vote:${state.room}:${poll.id}`;

  const timerRemaining = timer => {
    if (!timer) return 0;
    if (timer.running && timer.endsAt) return Math.max(0, Number(timer.endsAt) * 1000 - Date.now());
    return Math.max(0, Number(timer.remaining || 0) * 1000);
  };
  const timerText = milliseconds => {
    const seconds = Math.max(0, Math.ceil(milliseconds / 1000));
    return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
  };
  const timerBlock = timer => timer ? node('aside', {class: 'live-timer'}, [
    node('span', {text: timer.label || 'Arbeitszeit'}),
    node('strong', {'data-live-timer': '', text: timerText(timerRemaining(timer))}),
    node('small', {'data-live-timer-state': '', text: timerRemaining(timer) <= 0 ? 'Zeit ist um' : timer.running ? 'läuft' : 'pausiert'})
  ]) : null;

  const resultList = poll => {
    const counts = Array.isArray(poll.counts) ? poll.counts : null;
    const total = counts ? counts.reduce((sum, value) => sum + Number(value || 0), 0) : 0;
    const list = node('ol', {class: 'live-results'});
    poll.options.forEach((option, index) => {
      const count = counts ? Number(counts[index] || 0) : null;
      const share = total && count != null ? Math.round(count / total * 100) : 0;
      const item = node('li');
      item.append(node('div', {class: 'live-result-label'}, [
        node('span', {text: option}),
        counts ? node('strong', {text: `${count} · ${share} %`}) : null
      ]));
      if (counts) item.append(node('div', {class: 'live-bar', 'aria-hidden': 'true'}, node('span', {style: `width:${share}%`})));
      list.append(item);
    });
    if (counts) list.append(node('li', {class: 'live-total', text: `${total} Stimme${total === 1 ? '' : 'n'} insgesamt`}));
    return list;
  };

  const studentPoll = poll => {
    let selected = sessionStorage.getItem(voteKey(poll));
    const card = node('section', {class: 'live-card'}, [
      node('p', {class: 'live-kicker', text: poll.closed ? 'Beendet' : 'Jetzt abstimmen'}),
      node('h3', {text: poll.question})
    ]);
    if (!poll.closed) {
      const options = node('div', {class: 'live-options'});
      poll.options.forEach((option, index) => options.append(node('button', {
        type: 'button', class: `live-option${selected === String(index) ? ' is-selected' : ''}`, text: option,
        'aria-pressed': selected === String(index) ? 'true' : 'false',
        onclick: async () => {
          if (selected === String(index)) return;
          const previousOption = selected === null ? null : Number(selected);
          await perform('vote', {room: state.room, pollId: poll.id, option: index, previousOption}, previousOption === null ? 'Deine Stimme wurde gezählt.' : 'Deine Antwort wurde geändert.');
          if (!state.error) {
            selected = String(index);
            sessionStorage.setItem(voteKey(poll), selected);
            render();
          }
        }
      })));
      card.append(options);
      card.append(node('p', {class: 'live-message', text: selected === null ? 'Bitte eine Antwort auswählen.' : 'Antwort gespeichert. Du kannst sie bis zum Ende ändern.'}));
    } else if (!Array.isArray(poll.counts)) {
      card.append(node('p', {text: 'Die Abstimmung ist beendet.'}));
    }
    if (Array.isArray(poll.counts)) card.append(resultList(poll));
    return card;
  };

  const beamerPoll = poll => node('section', {class: 'live-card is-beamer'}, [
    node('p', {class: 'live-kicker', text: poll.closed ? 'Abstimmung beendet' : 'Live-Abstimmung'}),
    node('h3', {text: poll.question}),
    Array.isArray(poll.counts)
      ? resultList(poll)
      : node('p', {class: 'live-wait', text: 'Ergebnisse sind noch nicht freigegeben.'})
  ]);

  const teacherControls = poll => {
    const wrapper = node('section', {class: 'live-card'}, [
      node('p', {class: 'live-kicker', text: 'Lehrersteuerung'}),
      node('h3', {text: poll.question}),
      resultList(poll)
    ]);
    const actions = node('div', {class: 'live-actions'});
    if (!poll.reveal) actions.append(node('button', {type: 'button', class: 'live-button', text: 'Ergebnisse freigeben', onclick: () => perform('reveal', {room: state.room})}));
    if (!poll.closed) actions.append(node('button', {type: 'button', class: 'live-button is-quiet', text: 'Abstimmung schließen', onclick: () => perform('close_poll', {room: state.room})}));
    wrapper.append(actions);
    return wrapper;
  };

  const questionForm = () => {
    let templateId = 'single-poll';
    const preset = node('select', {'aria-label': 'Vorbereitete Frage'});
    preset.append(node('option', {value: '', text: 'Eigene Frage'}));
    config.presets.forEach((item, index) => preset.append(node('option', {value: index, text: item.question || `Frage ${index + 1}`})));
    const question = node('input', {required: true, maxlength: '180', placeholder: 'Abstimmungsfrage'});
    const options = node('textarea', {required: true, rows: '5', placeholder: 'Eine Antwort pro Zeile (2–6 Antworten)'});
    const resultPolicy = node('select', {'aria-label': 'Ergebnisanzeige'}, [
      node('option', {value: 'store', text: 'Ergebnis zunächst verbergen'}),
      node('option', {value: 'beamer', text: 'Ergebnis direkt zeigen'})
    ]);
    preset.addEventListener('change', () => {
      const selected = preset.value === '' ? null : config.presets[Number(preset.value)];
      if (!selected) {
        templateId = 'single-poll';
        return;
      }
      templateId = selected.id || `preset-${Number(preset.value) + 1}`;
      question.value = selected.question || '';
      options.value = Array.isArray(selected.options) ? selected.options.join('\n') : '';
    });
    return node('form', {
      class: 'live-card',
      onsubmit: event => {
        event.preventDefault();
        perform('start', {
          room: state.room,
          templateId,
          question: question.value.trim(),
          options: options.value.split(/\r?\n/).map(value => value.trim()).filter(Boolean),
          resultPolicy: resultPolicy.value
        }, 'Abstimmung gestartet.');
      }
    }, [
      node('h3', {text: 'Frage starten'}),
      ...(config.presets.length ? [node('label', {text: 'Vorlage'}), preset] : []),
      node('label', {text: 'Frage'}), question,
      node('label', {text: 'Antwortmöglichkeiten'}), options,
      node('label', {text: 'Ergebnisanzeige'}), resultPolicy,
      node('button', {class: 'live-button', type: 'submit', text: 'Frage starten'})
    ]);
  };

  const safeCsv = value => {
    let text = String(value ?? '');
    if (/^[=+\-@]/.test(text)) text = `'${text}`;
    return `"${text.replaceAll('"', '""')}"`;
  };
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
  const records = () => {
    const history = Array.isArray(state.data?.history) ? [...state.data.history] : [];
    const current = state.data?.poll;
    if (current && !history.some(item => item.id === current.id)) history.push(current);
    return history;
  };
  const download = (name, type, content) => {
    const url = URL.createObjectURL(new Blob([content], {type}));
    const link = node('a', {href: url, download: name});
    document.body.append(link); link.click(); link.remove(); URL.revokeObjectURL(url);
  };
  const exportCsv = () => {
    const rows = [['Raum','Bezeichnung','Frage','Antwort','Stimmen','Anteil','Status']];
    records().forEach(poll => {
      const total = (poll.counts || []).reduce((sum, value) => sum + Number(value || 0), 0);
      (poll.options || []).forEach((option, index) => {
        const count = Number(poll.counts?.[index] || 0);
        rows.push([state.room, state.data?.label || '', poll.question, option, count, total ? `${(count / total * 100).toFixed(1)} %` : '0 %', poll.closed ? 'beendet' : 'offen']);
      });
    });
    download(`abstimmung-${state.room}.csv`, 'text/csv;charset=utf-8', '\ufeff' + rows.map(row => row.map(safeCsv).join(';')).join('\r\n'));
  };
  const exportHtml = () => {
    const sections = records().map(poll => {
      const total = (poll.counts || []).reduce((sum, value) => sum + Number(value || 0), 0);
      const rows = (poll.options || []).map((option, index) => `<tr><td>${escapeHtml(option)}</td><td>${Number(poll.counts?.[index] || 0)}</td></tr>`).join('');
      return `<section><h2>${escapeHtml(poll.question)}</h2><table><thead><tr><th>Antwort</th><th>Stimmen</th></tr></thead><tbody>${rows}</tbody><tfoot><tr><th>Gesamt</th><th>${total}</th></tr></tfoot></table></section>`;
    }).join('');
    download(`abstimmung-${state.room}.html`, 'text/html;charset=utf-8', `<!doctype html><html lang="de"><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'"><title>Abstimmung ${escapeHtml(state.room)}</title><style>body{font:16px/1.5 system-ui;max-width:850px;margin:auto;padding:2rem}table{border-collapse:collapse;width:100%}th,td{border:1px solid #aaa;padding:.5rem;text-align:left}</style><h1>Abstimmung ${escapeHtml(state.room)}</h1><p>${escapeHtml(state.data?.label || '')}</p>${sections}</html>`);
  };

  const teacherFooter = () => node('section', {class: 'live-card'}, [
    node('h3', {text: 'Ergebnisse sichern oder Raum beenden'}),
    node('div', {class: 'live-actions'}, [
      node('button', {type: 'button', class: 'live-button is-quiet', text: 'CSV herunterladen', onclick: exportCsv}),
      node('button', {type: 'button', class: 'live-button is-quiet', text: 'HTML-Bericht', onclick: exportHtml}),
      node('button', {
        type: 'button', class: 'live-button is-danger', text: 'Raum löschen',
        onclick: async () => {
          if (!confirm('Der Raum und seine aggregierten Ergebnisse werden sofort gelöscht. Vorher exportieren?')) return;
          await perform('end_room', {room: state.room});
          if (!state.error) {
            sessionStorage.removeItem(`${config.storagePrefix}:room`);
            state.room = ''; state.data = null;
          }
          render();
        }
      })
    ])
  ]);

  function render() {
    root.replaceChildren();
    root.classList.add('live-root');
    const status = statusBlock();
    if (status) root.append(status);
    if (!state.room) {
      root.append(config.view === 'teacher' ? createForm() : joinForm());
      return;
    }
    if (!state.data) {
      root.append(joinForm());
      return;
    }
    root.append(roomHeader());
    const timer = timerBlock(state.data.timer);
    if (timer) root.append(timer);
    const poll = state.data.poll;
    if (config.view === 'teacher') {
      if (poll) root.append(teacherControls(poll));
      root.append(questionForm(), teacherFooter());
    } else if (!poll) {
      root.append(node('section', {class: 'live-card'}, [node('h3', {text: 'Noch keine aktive Frage'}), node('p', {text: 'Diese Ansicht aktualisiert sich automatisch.'})]));
    } else {
      root.append(config.view === 'beamer' ? beamerPoll(poll) : studentPoll(poll));
    }
  }

  async function refresh() {
    if (!state.room || state.busy) return;
    try {
      state.data = await request('status', {room: state.room});
      state.error = '';
      render();
    } catch (error) {
      state.error = error.message;
      render();
    }
  }

  render();
  if (state.room) refresh();
  window.setInterval(refresh, Math.max(1500, Number(config.refreshMs) || 2500));
  window.setInterval(() => {
    const value = root.querySelector('[data-live-timer]');
    const label = root.querySelector('[data-live-timer-state]');
    if (value && state.data?.timer) {
      const remaining = timerRemaining(state.data.timer);
      value.textContent = timerText(remaining);
      if (label) label.textContent = remaining <= 0 ? 'Zeit ist um' : state.data.timer.running ? 'läuft' : 'pausiert';
    }
  }, 250);
})();
