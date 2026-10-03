# ORES plugin

Monitors outages and planned interruptions on the **ORES** electricity
grid (Walloon distributor) for a precise address, and exposes the state
as Jeedom commands.

## Gas is NOT covered

ORES publishes **no API or real-time map for gas outages**. This plugin
monitors electricity only. For a gas leak or odour, call **0800/87.087** —
24/7 emergency line. This is a distributor-side limitation, not a plugin
one.

## Installation

1. **Plugins → Plugin management → Add → Github**: user `replicatorbe`,
   repo `jeedom-plugin-oresbe`, branch `beta` or `master`.
2. Enable the plugin.
3. No dependencies: pure native PHP.

## Configuration

Enter the postal code, street name (accents and case don't matter — the
comparison is normalised), and house number (e.g. `3A`). The
**Test the address** button queries the API without saving.

## Exposed commands

| Command | Type | Content |
|---|---|---|
| **Ongoing outage** | info / binary | 1 if an outage affects the address |
| **Number of outages** | info / numeric | counter (usually 0 or 1) |
| **Title** | info / string | "Electrical outage" or "Planned interruption" |
| **Type** | info / string | `panne` or `interruption` (raw key) |
| **Start date** | info / string | ISO 8601 |
| **Expected end date** | info / string | ISO 8601 |
| **Delayed** | info / binary | 1 if ORES reports a delay |
| **Impacted customers** | info / numeric | count |
| **Impacted street** | info / string | as ORES publishes it |
| **Generator planned** | info / binary | backup generator planned |
| **ORES page** | info / string | link to ORES generic page |
| **Details (JSON)** | info / string | full state |
| **Refresh** | action | force immediate reload |

## Built-in alerts

The **Alerts** tab lets you be notified when a new outage appears, with
mirror actions to run when the outage clears (e.g. erase a matrix
display). Tokens available in titles and messages: `#titre#`, `#type#`,
`#debut#`, `#fin#`, `#retard#`, `#clients#`, `#rue#`, `#generateur#`,
`#url#`, `#equipement#`, `#cp#`. Jeedom tokens remain usable above them.

## How it works

The plugin calls the undocumented but public endpoint
`https://ores-breakdownmapapi-prd.azurewebsites.net/Breakdown`, which
returns all active outages in Wallonia (~350 outages, ~300 KB). Filtering
is done client-side: street name is normalised (ASCII transliteration,
uppercase, punctuation squashed), equality is strict (no partial match
to avoid alerting on a nearby street), house number parity (odd/even) is
honoured against the published number ranges.
