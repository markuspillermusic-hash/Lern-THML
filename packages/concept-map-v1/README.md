# Concept Map v1.1.0

Wiederverwendbares, lokal gespeichertes Begriffsnetz für Religions-LernHTMLs.

## Einbindungsvertrag

Das Modul stellt einen leeren Host und eine JSON-Konfiguration bereit:

```html
<div data-concept-map data-concept-map-id="ethik-grundbegriffe">
  <script type="application/json" data-concept-map-config>{
    "nodes": [
      {"id":"wert","title":"Wert","sourceField":"termCardWert"}
    ]
  }</script>
</div>
```

`sourceField` verweist auf ein vorhandenes Feld mit `data-save`. Dessen Text
wird nicht dupliziert, sondern als Vorschau in der Karte angezeigt. Das Netz
speichert Positionen, Farben und gerichtete, beschriftete Beziehungen als
versioniertes JSON ausschließlich im Browser.

## Funktionen

- Karten mit Maus, Stift und Tastatur verschieben;
- Arbeitsfläche zoomen, verschieben und bildschirmfüllend öffnen;
- Karten einfärben;
- gerichtete Beziehungen mit Verknüpfungswort und Begründung anlegen;
- Beziehungen in einer zugänglichen Liste prüfen und löschen;
- Rückgängig, Wiederholen, Druckansicht und lokales Zurücksetzen;
- Export-/Import-Schnittstelle über `window.RELIGION_CONCEPT_MAPS`.

Die Schnittstelle exportiert ein semantisches Zustandsobjekt. `setStorageScope(scope)` trennt Personen und Unterrichtszuweisungen. Der optionale Adapter `learning-sync-v1` überträgt den Stand im persönlich zugewiesenen Unterricht; der Werkzeugkern selbst sendet keine Daten. Das gespeicherte Datenformat bleibt Version 1.
