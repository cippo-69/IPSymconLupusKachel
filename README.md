# Lupus Kachel

Kachel (HTML-SDK) für IP-Symcon 8 zu einer Lupus-Alarmzentrale (XT1 Plus, XT2 Plus, XT3) im LAN.

**Nur lesend:** Die Kachel schaltet nichts an der Anlage – kein Scharf/Unscharf, keine Thermostate, keine Steckdosen.

## Inhalt

- Modus je Bereich (Unscharf, Scharf, Home) und Alarm
- Kennzahlen: offene Kontakte, Hinweise, Anzahl Geräte
- „Wartung nötig“: Zustand der Zentrale (Stromversorgung, Akku, Sabotage, Funkstörung, GSM) und je Gerät Batterie leer, Sabotage, Alarm, schwacher Funk
- Geräte nach Gruppen (aufklappbar): Tür-/Fensterkontakte mit offen/geschlossen, Bewegungsmelder, Sirenen und weitere Geräte – jeweils mit Funkstärke
- Heizkörperthermostate: Ist, Soll, Ventilöffnung, Betrieb (manuell/Zeitplan/aus), Batterie
- Funktionen: frei gepflegte Liste in der Instanz (z. B. was IP-Symcon mit der Anlage darf)
- Aktualisieren und Link zur Weboberfläche der Zentrale

## Einrichtung

1. Modul über die Modulverwaltung hinzufügen: `https://github.com/cippo-69/IPSymconLupusKachel`
2. Instanz „Lupus Kachel“ anlegen.
3. Adresse der Zentrale, Benutzer und Passwort eintragen, Abfrageintervall wählen (Standard 30 s).

Empfehlung: In der Zentrale das Konto „user“ aktivieren (System → Zugangsdaten), umbenennen und nur dafür verwenden.
Dieses Konto darf ohne die Option „Moduswechsel durch User“ nicht scharf/unscharf schalten.

## Technik

- HTTPS mit Basic Auth, selbstsigniertes Zertifikat der Zentrale wird akzeptiert
- `GET /action/panelCondGet` (Modus, Alarm, Zustand der Zentrale) und `GET /action/deviceListGet` (Geräte, Fallback `deviceGet`)
- Gerätetypen: 4 = Tür-/Fensterkontakt, 9 = Bewegungsmelder, 79 = Heizkörperthermostat; alle übrigen erscheinen unter „Sirenen und weitere Geräte“
- Die JSON-API der Lupus-Zentrale ist nicht offiziell dokumentiert (Quellen: [lupupy](https://github.com/majuss/lupupy), [ioBroker.lupusec](https://github.com/schmupu/ioBroker.lupusec)). Ein Firmware-Update der Zentrale kann sie ändern.
- Die Kachel ist nicht für die Alarmierung gedacht: Sie fragt im Intervall ab und bekommt keine Meldungen von der Zentrale geschickt.

## Farben

Die Kachel hat keine eigenen Farbeinstellungen. Die Farben kommen aus dem Modul [Visu Design](https://github.com/cippo-69/IPSymconVisuDesign) (CSS-Variablen `--w5-*`, heller Modus und Dark Mode). Ohne Design-Instanz gilt das Farbkonzept W5x. Ein Theme-Wechsel kommt ohne Neuladen an.

## Lizenz

MIT
