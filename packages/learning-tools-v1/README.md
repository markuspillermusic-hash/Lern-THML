# Learning Tools v1.2.3

Lokale, serverfreie Lernwerkzeuge für Religions-LernHTMLs:

- persistente Textmarkierungen über die Custom Highlight API: Farbe einmal
  aktivieren, anschließend direkt im Text markieren und per erneutem Farbklick,
  schwebender Schaltfläche oder `Esc` wieder ausschalten;
- persistenter Radierer für ausgewählte Teilbereiche;
- private Schülermarkierungen sowie flüchtig in den Präsentationszustand
  gespiegelte Lehrermarkierungen; nachgeladene Beamertexte werden ebenfalls
  initialisiert;
- Zeichenebene für Maus und Eingabestift mit Stift, Marker und Radierer;
- Export-/Import-Schnittstelle über `window.RELIGION_LEARNING_TOOLS`.

Die Werkzeuge speichern ausschließlich im Browser. In der Beameransicht gibt
es keine Werkzeugleiste; dort werden nur die Markierungen der Lehrkraft
gerendert.

## Einbindungsvertrag

- Jeder markierbare Text besitzt eine explizite und zwischen den Rollen
  identische `data-learning-highlighter-id`; dies gilt auch für geschützte oder
  asynchron nachgeladene Materialien.
- Der Initialisierungsscan beobachtet dynamisch eingefügte Texte in allen
  Rollen. Eine Werkzeugleiste wird ausschließlich für Schüler und Lehrkraft
  erzeugt.
- Material-Clients dürfen einen bereits mit derselben Materialversion
  gerenderten Text nicht erneut ersetzen. Sonst gingen aktive Werkzeuge,
  Auswahlzustände und lokale Markierungen verloren.
- Maus und Stift schalten Marker oder Radierer einmal über `pointerdown` um;
  der nachfolgende Zeiger-Klick darf nicht erneut toggeln. Ein Tastatur-`click`
  bleibt als eigener Bedienweg erhalten.
- Lehrermarkierungen werden erst gesendet, wenn der Präsentationsadapter bereit
  ist; ein begrenzter Initialisierungs-Retry verhindert den Verlust des ersten
  Zustands. Persönliche Schülermarkierungen verlassen den Browser nie.
