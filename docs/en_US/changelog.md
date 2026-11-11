# Changelog

## 0.1.2

- An isolated failed read of the ORES API is no longer logged as an error, and
  so no longer creates an entry in the message centre: it is a warning. The
  error is only raised on the third failure in a row, once, and the return to
  normal is then reported in the log.
- A failed read with no known state is no longer logged twice.
- The message the core posts for that error in the message centre is removed
  on the first successful read.

## 0.1.1

- New **Last check**, **API error** and **Last error** commands: when the ORES API
  stops answering, the previous state is still shown but no longer silently —
  the date of the last successful read is visible and the error stays raised
  until the next one, backoff included.
- During an error, the republished state no longer counts as a
  communication: the device timeout, set to 60 minutes when empty, reports a
  stuck read.
- Health page: age of the last successful read and last error of each
  device.

## 0.1 — initial release

- Monitors ORES electricity outages and planned interruptions for a
  precise address (postal code + street + house number with parity).
- Collection via the undocumented but public Azure `/Breakdown` endpoint,
  client-side filtering with ASCII transliteration and strict matching.
- Exposed commands: binary state, counter, title, type, start/end dates,
  delayed flag, impacted customer count, impacted street, backup
  generator flag, URL, JSON details, refresh button.
- Full built-in alerts: start actions, mirror end actions, Test button,
  per-equipment memory of notified `businessId` to prevent re-triggering.
- Tokens: `#titre#`, `#type#`, `#debut#`, `#fin#`, `#retard#`,
  `#clients#`, `#rue#`, `#generateur#`, `#url#`, `#equipement#`, `#cp#`.
- 15 min cron, 5 min cache shared between devices, 1 h backoff after HTTP failure.
- Explicit note in UI and doc: gas is not covered — ORES provides no gas
  outage API.
