🇩🇪 [Deutsche Fassung](README.md)

# Automat

[![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Module Version](https://img.shields.io/badge/Module%20Version-0.2.2-blue.svg)](https://github.com/DG65/Automat)
[![Symcon Version](https://img.shields.io/badge/Symcon%20Version-9.0%2B-blue.svg)](https://www.symcon.de/en/service/documentation/installation/)
[![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-green.svg)](LICENSE)
[![Check Style](https://github.com/DG65/Automat/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/Automat/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Donate-00457C.svg)](https://paypal.me/DietmarGureth)

Part of the **DG65-Toolkit** — tools without an energy focus, for every Symcon installation.

The module follows the language of your Symcon (English or German): form, variables, states, log, notifications and the tile.
Variable idents (e.g. `Hausmodus`, `Pause`) stay the same in both languages.

Automat bundles the small standard automations of a house in **one instance**: staircase light,
motion light, dusk switching, window open → heating down, "door open too long" watchdog and
"washing machine finished". Instead of dozens of single instances and events there is one list with the state
and reason per automation, a log, a tile — and **Discover** suggests from your existing
devices what is worth automating.

> **As of 0.2.2 (beta in the Module Store, 726 checks):** motion light, window open, watchdog, presence, central off,
> shutters and house mode run in my own installation; the other automations are tested on the test bench.
> Feedback is welcome in the [forum thread](https://community.symcon.de/t/beta-modul-dg65-toolkit-automat-alle-standard-automatismen-in-einer-instanz/144647) (German forum, English posts are fine).

## Automations

| | Type | What it does | Details |
|---|---|---|---|
| ⏲ | Timed light (staircase light) | light switches itself off after a set time | started by push button or on every switch-on; retrigger; pre-warning (dimmer: half brightness, switch: briefly off); permanent light |
| 🚶 | Motion light | on with motion, off after the follow-up time | any number of sensors (OR); stays on while motion is reported; brightness threshold only when switching on (its own light does not switch it off again); does not take over a light that was already on; pause after manual switch-off; **learning follow-up time**: if the light comes back on shortly after switching off, it gets longer; **further signs of presence** (e.g. cooker power, TV) keep the light on; **sensor fallback**: if the "no motion" report gets lost on the way (radio/Z-Wave), a sensor counts as idle after three times its learned usual report duration |
| 🌆 | Dusk and time switching | on/off by sun position, time or brightness | offset to sunrise/sunset, random offset per day, across midnight; switched off by hand = stays off until the next switching window |
| 🪟 | Window open → heating down | lowers setpoints, restores them when closed | delay (short airing does not count); if a weekly schedule changes the setpoint while the window is open, the new value is remembered; inverted contacts (Matter/Zigbee) |
| ⏱ | Watchdog "stays like this too long" | notifies when a state lasts too long | equal/unequal/greater/less/on/off; second threshold (hysteresis); repetition; all-clear |
| 🧺 | Appliance finished | reports the end of a run from the power | minimum runtime against spikes, pauses/spinning bridged |
| 🏠 | Combine presence | several presence signals → "someone at home" | "present" immediately, "nobody there" only after a waiting time; names who is there; feeds the house mode "Away" |
| 🌙 | Central off / scene by house mode | on change to Night/Away/… switch devices off, on or to a value | delay, only what differs; other automations see it as "central", not as manual operation |
| 🚪 | Leaving check | on change to Away/Holiday (optionally Night) ONE notification about what is open or on | windows/doors/gates and devices (switch or power), optionally switch them off right away |
| 🛎 | Doorbell | notify, light on in the evening, music quieter — afterwards as before | notification "nobody at home" when away; camera image to script channels; manual changes stay |
| ☀️ | Summer airing | "close windows" when it is warmer outside, "air now" when it is cooler | minimum indoor temperature, hysteresis, dew point with humidity inside/outside (cellar) |
| 🧊 | Failure watchdog | without power for too long (fridge) or running too long in one go (pump) | remembers the longest pause or run so far as "normal" |
| 📅 | Time switch | on/off by weekday and time | only on / only off, across midnight, public holidays, random offset; manual operation stays until the next switching time |
| 🚨 | Water/smoke alarm reaction | alarm: light on, shutters up, valve/devices off, notify | works during pause and quiet hours too; reminder until all-clear; switches nothing back afterwards |
| 🪟 | Shutters by time/sun | up in the morning, down in the evening | time, sunrise/sunset ± offset, brightness or house mode; earliest (weekdays/weekend), latest; lock-out protection with open door; direction taken from the profile (~Shutter / ~Shutter.Reversed) |
| 🔌 | Standby cut-off | socket off when the device is in standby for a long time | threshold in W, duration; operation in between resets the time |
| 💨 | Airing reminder | "please air" with too much humidity/CO₂, "can be closed again" | waiting time against short spikes (shower), reminder, window contact optional |
| 🌡 | Heating by house mode | lower on Away/Holiday (optionally Night), restore afterwards | remembers the old setpoint; manual changes stay |
| 🔘 | Push button assignment | short/double/long press → action | for buttons that report the press as a value ("single", "double", "hold", 1/2/3); toggle, on, off, value, house mode |
| 🌅 | Wake-up/fall-asleep light | light slowly up until the wake-up time or down until off | time, wake-up time variable or house mode "Night"; manual intervention ends the ramp |
| 🔁 | Reset sensors | sets sensors stuck on "true" back to "false" after a hold time | for sensors without action; shows a hint if the variables are locked in the device instance |

## Common to all automations

- **Remaining time** per automation as a variable "… · Remaining time" (e.g. "1:35 min" until the light goes off, until lowering or a notification), otherwise empty.
- **Lock** per automation as a variable "… · locked" — visible, switchable from the visualization, the tile and scripts
  (cleaning, party, craftsmen). Locked means: does nothing at all.
- **Pause** for all at once (variable "Pause all automations").
- **House mode** Day/Evening/Night/Away/Holiday as a variable of this instance — by hand, by script or
  automatically by time, sun position/dusk or "Is it day" (Location Control), brightness sensor (e.g. weather station) and presence. Every automation can be limited to modes;
  light uses its own brightness value at "Night".
- **Notifications — you decide what and how**: any number of named notification channels (push to all or one
  specific visualization, e-mail via SMTP to specific recipients, script e.g. for Telegram), per channel
  "default" and "respect quiet hours". Per automation "notify" and the choice of its channels (no choice: default);
  optionally faults as well. Quiet hours with catch-up, test notification with result per channel.
- **Filter** above the list (search text, room, function/type, state) and in Discover (search text, room, type);
  filtered-out automations are kept when saving.
- **Duplicate**: tick automations and copy them into another room — sensors, lights, contacts of the same kind
  are filled in there; what does not fit stays empty and the copy stays inactive until chosen.
- **Trigger by hand**: ticked automations "▶ Run now" (catch up a missed switching, scene, time switch, staircase light, leaving check, heating) or shutters "⬇ close" / "⬆ open" — also in the tile. `AUTOMAT_RunNow($id, $uid, '' | 'down' | 'up')` for scripts.
- **Dry run** per automation: switch nothing, notify nothing, only log what would have happened.
- **Public holidays** by German state, Austria or Switzerland for shutters, wake-up light and time switch.
- **Statistics** per automation (switchings, notifications) and a hint on those that did nothing for 30 days.
- **Habits**: Discover evaluates the archive of the lights and suggests e.g. "weekdays at 22:30 off".
- **Form: layout** — choose per section whether it is expanded or collapsed when opening, at which position it
  stands or whether it is hidden completely (e.g. "Catalog" and "Discover" once everything is set up).
- **Conditions** via Symcon's own condition selection (e.g. only on weekdays, only when a switch is on).
- **Manual operation has priority** — Automat recognizes the echo of its own commands and tells it apart from
  switching by hand.
- **Reason for the decision**: status variable per automation and log ("Motion — not switched on:
  bright enough (120 lx ≥ 30 lx)").
- **Double control** is detected: Automat warns when the same devices are already controlled by another
  automation, a triggered event or another instance.

## Discover

"Search for suggestions now" searches the object tree for motion sensors, lights (switches/dimmers),
window/door contacts, heating setpoints, brightness sensors and power measurements of
household appliances — via profiles, presentations (Symcon 8+) and names. The devices are grouped by room:
from the **DG65-Toolkit Raumstruktur**, if installed, otherwise from the device names
("Licht Flur" + "Flur Bewegungsmelder" = Flur). This results in suggestions such as "Motion light Flur",
"Window open → heating down Bad" or "Report washing machine finished".

Name recognition is tuned to German device names; profiles and presentations work in any language.

Suggestions are shown in a sortable list (ordered by room or function). Ticked suggestions only land in the list after "Add ticked to the list" and only work after
"Apply changes". Unsuitable suggestions can be hidden.

## Sensors of different systems

Automat does not look at the radio system but at the behaviour of the sensor variable — usually there is nothing to configure:

| Behaviour | What Automat does |
|---|---|
| Sensor resets after a few seconds, but "off" occasionally gets lost (Z-Wave, Zigbee, radio Homematic, Wi-Fi) | learns the usual report duration per sensor (archive of the last 3 days, then continuously) and treats it as idle after three times that without a new report; every intervention is counted ("📶 3× report lost (7 days)") |
| Presence sensor with long occupancy (radar/mmWave, Zigbee with its own occupancy time) | automatically learns a long time (up to 1 h) |
| Sensor repeats "on" regularly while someone is present (e.g. KNX presence sensor) | every repetition extends |
| Sensor never sends "off" | is detected ("ℹ️ Pulse sensor detected") — then switch on "Follow-up time on every motion report" in the dialog |

If lost reports pile up for one sensor, check reception, battery or location (with Z-Wave/Zigbee a repeater nearby often helps).

## Setup

1. Install "DG65-Toolkit Automat" from the Module Store (or: Module Control → Add → `https://github.com/DG65/Automat`).
2. Create the instance "DG65-Toolkit Automat" (one instance for the whole house is enough; several are possible).
3. "Discover" → check suggestions → add them, or create them yourself in the "Catalog" or with "+" on the list.
4. Click "Apply changes" at the bottom.
5. Optional: place the tile "Automat" in the tile visualization (compact overview with search,
   filter Running/Conspicuous/Locked, groups by room or function, sorting; locks, permanent light,
   house mode, pause, remaining times).

## Scripts

```php
echo AUTOMAT_Overview($InstanceID);                 // state of all automations as text
IPS_RequestAction($InstanceID, 'Hausmodus', 2);     // 0 Day, 1 Evening, 2 Night, 3 Away, 4 Holiday
IPS_RequestAction($InstanceID, 'Pause', true);      // pause all
```

Lock and permanent light of single automations are variables below the instance (`l_<id>`,
`d_<id>`) and can be switched with `RequestAction` on the variable.

## Scope

Deliberately **not** in Automat: shading by sun position (there are specialised modules for that),
alarm system with zones, presence simulation (→ **DG65-Toolkit Anwesenheitssimulation**), battery monitoring
(→ **DG65-Toolkit Batteriewächter**), averages/trends (→ **DG65-Toolkit Messwertrechner**).

## License

PolyForm Noncommercial 1.0.0 — private/non-commercial use is free, commercial use requires
a separate license from the rights holder (DG65). The full license text is in the repo (LICENSE).
Donations are welcome: [paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
