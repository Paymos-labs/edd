# Paymos für Easy Digital Downloads

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | **Deutsch** | [Türkçe](README.tr.md)

Offizielles Paymos-Zahlungsgateway für Easy Digital Downloads. Käufer wählen Paymos im Checkout aus, begleichen eine Stablecoin-Rechnung auf der gehosteten Paymos-Zahlungsseite und kehren zu einer ausstehenden Bestellung zurück. Die Bestellung wechselt auf `complete` — dem Status, bei dem EDD die Download-Links freigibt und die Kaufbestätigung versendet — sobald ein signierter Webhook die On-Chain-Bestätigung der Transaktion meldet. Die bloße Rückkehr von der Zahlungsseite schaltet die Datei nicht frei.

[![Video ansehen: 12 offizielle CMS-Krypto-Plugins — 1-Klick-Verbindung (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **Video-Rundgang (1:12, Untertitel in 6 Sprachen):** [Auf YouTube ansehen](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Voraussetzungen

- WordPress 6.5 oder neuer, getestet bis 7.0 — die Mindestanforderung im Plugin-Header von `paymos-easy-digital-downloads.php`
- Easy Digital Downloads, in diesem Header als erforderliches Plugin deklariert. Das Gateway schreibt in das Bestellmodell von EDD 3.x
- PHP 7.4 oder neuer mit OpenSSL — Zugangsdaten werden mit AES-256-GCM verschlüsselt gespeichert
- Eine öffentlich erreichbare HTTPS-Website. Der Webhook ist eine REST-Route auf Ihrer eigenen Domain; Zustellungen erfolgen niemals an Loopback-, private oder Link-Local-Adressen
- Ein Paymos-Konto, bei dem im Dashboard bereits das Projekt ausgewählt ist, über das dieser Shop verkauft

## Installation

Laden Sie `paymos-edd-<version>.zip` unter [Releases](https://github.com/Paymos-labs/edd/releases/latest) herunter oder beziehen Sie das Paket im Bereich **CMS integration** des Paymos-Dashboards. Installieren Sie es in WordPress über **Plugins → Add New Plugin → Upload Plugin** — in älteren WordPress-Versionen heißt dieser Menüpunkt **Add New** — und aktivieren Sie das Plugin.

Das Paymos PHP SDK ist direkt im Archiv enthalten, sodass kein Composer-Schritt erforderlich ist. Jeder Händler lädt exakt dieselbe Datei herunter; sie enthält weder API-Key, API-Secret, Projekt-ID, Webhook-Secret noch OAuth-Token oder Device-Code.

## Verbinden

Für die Verbindung ist die EDD-Berechtigung `manage_shop_settings` erforderlich (Shop-Manager oder Administrator).

1. Wählen Sie im Paymos-Dashboard das Projekt aus, über das dieser Shop verkauft — dieses Projekt wird direkt verknüpft, eine spätere Auswahl gibt es nicht.
2. Öffnen Sie **Downloads → Settings → Payments → Paymos** und klicken Sie auf **Connect Paymos**.
3. Bestätigen Sie Website-URL und Projekt im sich öffnenden Tab. Falls der Browser den Tab blockiert hat, erscheinen der Link und der Bestätigungscode stattdessen direkt neben der Schaltfläche.
4. **Connection status** wird automatisch befüllt: aktiver Modus, Konfigurationsstatus für Sandbox und Live, maskierter API-Key sowie die Projekt-ID.

Diese eine Freigabe stellt Sandbox und Live gemeinsam bereit. Ihr einziger aktiver Payment-Key wird wiederverwendet (oder neu erstellt, falls keiner existiert), und ein Invoice-Webhook wird unter folgender URL registriert:

```text
https://your-store.example/wp-json/paymos-edd/v1/webhook
```

Ein bestehender Webhook wird nur dann wiederverwendet, wenn Callback-URL, Kategorie und Projekt vollständig übereinstimmen.

Die Zugangsdaten werden in einer nicht automatisch geladenen WordPress-Option gespeichert und mit AES-256-GCM versiegelt, wobei der Schlüssel aus den WordPress-Salts `auth` und `secure_auth` abgeleitet wird. Das kurzlebige Autorisierungstoken wird verworfen, und jeder nachfolgende Aufruf der Merchant API wird per HMAC signiert.

## Einstellungen

| Einstellung | Standardwert |
|---|---|
| Checkout-Bezeichnung (Checkout label) | `Pay with stablecoins` |
| Modus (Mode) | Sandbox |
| Webhook-URL | Schreibgeschützt; wird beim Verbinden automatisch registriert |
| Verbindungsstatus (Connection status) | Schreibgeschützt |
| Debug-Protokollierung (Debug logging) | Standardmäßig aus; schreibt Paymos-Meldungen bei Aktivierung in das EDD-Debug-Log |

Es gibt weder ein Feld für ein Secret noch eine Projektauswahl — beides wird über die Freigabe automatisch eingerichtet.

## Wann der Download freigeschaltet wird

| Paymos-Ereignis | EDD-Bestellung |
|---|---|
| Käufer wählt Paymos, Bestellung wird angelegt, Warenkorb geleert | `pending`, mit einer Notiz samt Rechnungs-ID |
| `invoice.confirming` | `pending` — `"Paymos payment is confirming."` |
| `invoice.underpaid_waiting` | `pending`, die Notiz nennt den noch ausstehenden Betrag und das Token |
| `invoice.awaiting_payment` | `pending`, die Notiz weist darauf hin, dass eine bestätigte Transaktion On-Chain zurückgerollt wurde |
| `invoice.paid`, `invoice.paid_over` | `complete` — Downloads freigegeben, Kaufbestätigung gesendet |
| `invoice.underpaid` | `failed` |
| `invoice.expired`, `invoice.cancelled` | `abandoned`, der EDD-Status für eine ausstehende Bestellung, die nie abgeschlossen wurde |

Beim Abschluss wird der On-Chain-Transaktions-Hash als EDD-Transaktions-ID hinterlegt, zusammen mit den Metafeldern `_paymos_tx_hash` und `_paymos_explorer_url`. Ein zweites Abschlussereignis für eine bereits abgeschlossene Bestellung wird als erneute Bestätigung erkannt und fügt keine doppelte Notiz hinzu; später eintreffende Ereignisse wie `confirming`, `underpaid`, `expired` oder `cancelled` werden verworfen und verändern nichts.

Weicht Betrag oder Währung einer Bestellung von der Rechnung ab, wird die Bestellung gar nicht abgeschlossen. Das Metafeld `_paymos_amount_mismatch` wird auf `yes` gesetzt, die Abweichung in den Bestellnotizen protokolliert und muss manuell geklärt werden. Bis dahin bleibt der Download gesperrt.

## Was Käufer danach sehen

Auf der Kaufbestätigungsseite und in der Bestell-E-Mail erscheint jeweils eine Zeile **Payment confirmation**: der Link zum Block-Explorer, sofern im Payload vorhanden, andernfalls der reine Transaktions-Hash. Solange kein Hash vorliegt — was bei jeder Sandbox-Bestellung der Fall ist — wird diese Zeile nicht ausgegeben.

## Vor dem Produktivbetrieb testen

1. Belassen Sie **Mode** auf Sandbox und kaufen Sie einen Download regulär über den Shop.
2. Suchen Sie diese Rechnung im Paymos-Dashboard (weiterhin im Sandbox-Modus) und steuern Sie sie über **Pay Full**, **Pay 50%**, **Pay 150%** oder **Cancel**. Dabei laufen echte Lebenszyklus-Ereignisse ab, ohne dass Geld bewegt wird.
3. Prüfen Sie die Bestellung: Status, Notizen und ob der Download-Link freigegeben wird.
4. Stellen Sie **Mode** auf Live um. Durch die einmalige Freigabe sind bereits beide Zugangsdatensätze vorhanden, sodass keine zweite Einrichtung nötig ist.

## Webhooks

Die Route ist in der WordPress REST API registriert und akzeptiert ausschließlich `POST`-Anfragen. `X-Webhook-Signature` wird gleichzeitig gegen die Secrets beider Umgebungen geprüft; anschließend wird das `is_test`-Flag des Payloads mit genau der Umgebung abgeglichen, deren Secret die Prüfung bestanden hat — ein Live-Payload kann keine Sandbox-Bestellung abschließen, und umgekehrt wird ebenso strikt abgelehnt. Eine bereits verarbeitete `event_id` wird bestätigt und übersprungen. Bei Abschlussereignissen ruft das Plugin den Status zusätzlich über die Merchant API ab und gleicht Projekt, Betrag und Währung mit der Bestellung ab, bevor Änderungen geschrieben werden.

Bei Fehlern antwortet der Endpunkt mit einem eindeutigen Statuscode, da ein Non-2xx-Status das Ereignis in der Wiederholungsschleife hält: `401` bei ungültiger Signatur oder abgelaufenem Zeitstempel, `400` bei Verarbeitungsfehlern und `500`, wenn kein Webhook-Secret hinterlegt ist. Die Wiederholungsstaffel umfasst 11 Versuche über rund 16 Stunden. Unbezahlte Rechnungen verlassen sich auf signierte `invoice.paid`-Webhooks und besitzen keinen lokalen Rechnungs-Abgleichs-Cron; ein Rechnungsereignis, das alle Wiederholungen überdauert, wird manuell im Paymos-Dashboard erneut zugestellt. Ausstehende Rückerstattungen werden hingegen jede Minute lokal durch den WP-Cron-Job `paymos_edd_refunds_reconcile` abgefragt, bis sie einen Endstatus erreichen.

## Rückerstattungen

Das Gateway unterstützt native On-Chain-Rückerstattungen über die Paymos Merchant API (`/v1/refunds`) in zwei Modi:

- `full` — erstattet den vollständig bezahlten Rechnungsbetrag;
- `overpayment` — erstattet ausschließlich den überzahlten Differenzbetrag einer `invoice.paid_over`-Bestellung, während die Bestellung auf `complete` bleibt.

Die Auszahlung erfolgt ausschließlich zurück an die verifizierte `first_sender`-Wallet-Adresse, die Paymos bei der Zahlung der Rechnung durch den Käufer erfasst hat. Es wird keine Empfänger-Wallet-Adresse manuell eingegeben, und beliebige Teilrückerstattungsbeträge werden nicht unterstützt.

Bei einer abgeschlossenen Paymos-Bestellung unter **Downloads → Orders** können Sie eine `full`-Rückerstattung direkt über den nativen EDD-Dialog **Initiate a Refund** starten (mit allen Bestellpositionen vollständig ausgewählt) oder im Panel **Paymos refunds** in der Bestellansicht sowohl `full`- als auch `overpayment`-Rückerstattungen auslösen und deren Status prüfen. EDD legt die lokale Rückerstattungsbestellung erst an und versendet den Rückerstattungsbeleg erst, nachdem Paymos den Status `completed` über `/wp-json/paymos-edd/v1/webhook` oder den minütlichen WP-Cron-Job `paymos_edd_refunds_reconcile` bestätigt hat. Im Sandbox-Modus lassen sich Endzustände für Rückerstattungen (`completed`, `failed`, `cancelled`) ohne reale Geldbewegungen simulieren.

## Fehlerbehebung

**Der Käufer hat bezahlt, aber die Datei ist weiterhin gesperrt.** Die Bestellung wartet auf einen Webhook, der noch nicht angekommen ist. Prüfen Sie, ob der Endpunkt von außen erreichbar ist — ein Sicherheits-Plugin, HTTP Basic Auth oder eine Option zum Deaktivieren der REST API blockieren Anfragen stillschweigend, und Zustellungen folgen keinen Weiterleitungen auf eine andere Adresse.

**Der Endpunkt antwortet mit `not_configured`.** Es sind keine Zugangsdaten gespeichert, daher fehlt das Secret zur Signaturprüfung. Klicken Sie auf der Einstellungsseite auf **Connect Paymos** und schließen Sie die Freigabe ab.

**Nach dem Bearbeiten von `wp-config.php` funktioniert nichts mehr.** Der verschlüsselte Zugangsdaten-Container verwendet die Salts `auth` und `secure_auth`. Werden diese rotiert oder wird eine Datenbank auf einer Website mit anderen Salts wiederhergestellt, sind die Daten nicht mehr lesbar — verbinden Sie das Plugin erneut, um einen neuen Container ausstellen zu lassen.

**Nach einem Domain-Umzug funktioniert nichts mehr.** Die genehmigte Website-URL und der registrierte Webhook verweisen noch auf den alten Host. Verbinden Sie den Shop unter der neuen Domain neu.

## Links

- Dokumentation: [paymos.io/de/docs/cms-easy-digital-downloads](https://paymos.io/de/docs/cms-easy-digital-downloads)
- Quellcode und Releases: [Paymos-labs/edd](https://github.com/Paymos-labs/edd)
- Support: [support@paymos.io](mailto:support@paymos.io)
