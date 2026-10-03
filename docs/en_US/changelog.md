# Changelog

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
- 15 min cron, 15 min cache, 1 h backoff after HTTP failure.
- Explicit note in UI and doc: gas is not covered — ORES provides no gas
  outage API.
