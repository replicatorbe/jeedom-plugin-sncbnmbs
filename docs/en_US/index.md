# SNCB/NMBS plugin

This plugin watches Belgian trains for commuters, using the iRail open data. One
device stands for one journey: a departure station, an arrival station, a time
window and the days of the week concerned. The plugin lists the trains of the
window, then checks their state during the watched window — delay, cancellation,
platform change, network disturbance — and exposes all of it as Jeedom commands.

It is not a journey planner. It does not look for the best route, books nothing,
buys no ticket and offers no alternative when your train is cancelled. It
answers one question only, the one you ask with the coffee mug still in hand:
do I leave now, and from which platform?

No dependency, no daemon, no API key. The core cron runs every minute; each
journey decides on its own whether to query iRail or simply recompute its
commands.

## Installation

1. Plugins → Plugin management → Add → Github.
2. Fill in the repository (see the README), branch `master`.
3. Enable the plugin.

Nothing else has to be installed.

## Creating a journey

Plugins → Organization → SNCB/NMBS → **Add a journey**. The journey page has four
tabs: Device, Trains, Network and Commands. The whole configuration fits in the
first one.

**Journey** block:

| Field | Value |
|---|---|
| Departure station | type at least two letters, click the magnifier, pick it from the drop-down list |
| Arrival station | same |
| Swap the journey | exchanges both stations |
| Selected journey | shows, in plain words, the two stations as they will be saved |

The search ignores accents and hyphens: `bruxelles midi` finds
"Bruxelles-Midi". Both stations must be **picked from the list**, not merely
typed: the plugin works with iRail identifiers, not with names.

**Time window** block:

| Field | Value |
|---|---|
| Start time, End | the hours between which you usually take the train. `07:00` → `09:00` by default |
| Days | the Mo to Su boxes |

**Watching** block:

| Field | Value |
|---|---|
| Delay threshold | from how many minutes a train counts as "delayed". 5 by default |
| Minute-by-minute watching | unticked, the journey is only read again once an hour |
| Minutes ahead | how many minutes before the window the minute-by-minute watch starts. 60 by default, 240 at most |
| Number of trains | how many departures to follow per window, from 1 to 6. 6 by default |
| Command to trigger | the Jeedom action command called as soon as a problem shows up. The cross clears it |

Two buttons complete that block: **Refresh now**, which queries iRail without
waiting for the cron — pointless right after a save, which already does it — and
**Acknowledge alert**. The result appears just below.

> A departure station identical to the arrival station is not only absurd: iRail
> times out and answers after thirty seconds. The plugin therefore rejects such
> a journey before any call, and says so in the message centre.

A journey stands for **one direction of travel**. The way back is a second
device: duplicate the morning journey, click **Swap the journey**, shift the time
window. Nothing forces the two directions to share a threshold or a set of days.

The three other tabs hold no setting:

- **Trains** lists the selected trains — day, departure, delay, train, direction,
  platform, arrival, duration, transfers, occupancy — along with the time of the
  last check and the state of the watch. It reads what the plugin has already
  fetched: opening it queries nothing and costs nothing.
- **Network** shows the disturbances published for the whole network, your
  journey or not, with a Refresh button. Those naming one of your two stations
  are repeated in the Trains tab.
- **Commands** is the usual command table of the device.

## The time window and the days

The window bounds both ends: iRail returns the trains following the requested
time, and never stops on its own. Without an upper bound, a window from 7 to
9 am would also bring back the 11 am train, and wake you up for a delay that
does not concern you.

Active days are ticked from Monday to Sunday. A new journey is created with
**Monday to Friday ticked**: that is the commuting journey, and it keeps a fresh
journey from querying the network on a Sunday. Untick them all and the rule
flips: **no day ticked means every day**, for a journey with no fixed schedule.

The plugin follows two windows at a time: today's and the one of the next active
day. Once today's window is over, the display switches to the next one by
itself — there is nothing left to watch today. Tomorrow's trains are only read
again every thirty minutes: a timetable for tomorrow morning does not change by
the minute, and every read costs a call.

> "Tomorrow" means the next active day, not the next day on the calendar. A
> journey ticked Monday to Friday shows Monday from Friday evening on.

A window whose end time comes before its start time is understood as a night
window: `22:00` → `01:00` ends the following morning, and is still followed after
midnight — which is exactly when you look at it. A window whose start and end are
identical lasts one hour, not twenty-four: you meant a moment, not a whole day.

## Minute-by-minute watching

Inside the watch window, the plugin queries iRail **every minute**. Outside of
it, it settles for one call **an hour**, and none between 1 and 5 in the
morning: hardly any train runs then, and nobody is looking.

The watch window is the time window, widened upstream by the "Minutes ahead".
With a window from 7 to 9 am and 60 minutes upstream, the plugin watches from 6
to 9 am. The upstream part matters as much as the window itself: a delay learnt
at 6:20 leaves you time to take the next train or to drive; the same delay
learnt on the platform is of no use any more.

Outside the window, commands keep being recomputed every minute, without any
network call: the countdown and the next train change with the passing time, not
with iRail's answers.

This policy is not an arbitrary limitation. iRail is a free, community-run
service, without an API key and without an invoice: watching a 7 am journey
around the clock would make 1440 requests a day in order to use 120 of them. The
plugin therefore sets its own bounds:

- the number of trains followed is capped at 6. That is not only restraint:
  iRail silently ignores any higher request and returns six connections anyway;
- the upstream watch delay is capped at 240 minutes;
- the "Refresh" command reads nothing again if the last read is less than
  20 seconds old, even when called in a loop by a scenario;
- saving the device only queries iRail again if the journey really changed — the
  stations, the window, the days or the number of trains. Renaming the device or
  changing its icon costs no call;
- network disturbances are shared between all journeys and read again every
  three minutes, whatever the number of devices.

The "Minute-by-minute watching" box turns that watch off without deleting the
journey: the quarter-hourly read still applies, and the timetable stays
readable.

After a failure, the journey **waits before trying again**, and the wait doubles
with every further failure: one minute, two, four, up to an hour. A service
coming back up is therefore found again within a minute, while a permanently
wrong station costs twenty-four requests a day instead of twelve hundred. The
first success resets the counter.

When iRail does not answer, nothing is erased. The last known timetable stays
displayed, the "Last check" command keeps the time of the last successful read,
and a message appears in the message centre.

## Acting on a delay

Every info command triggers your scenarios on event. But the plugin can also act
on its own: the **command to trigger**, chosen in the Watching block, is an
action command of your choosing — a notification, a spoken message, a lamp —
called as soon as a problem is found on a train that has not left yet.

It is called with two parameters, the ones Jeedom message commands expect:

| Parameter | Content |
|---|---|
| `title` | `Train — <device name>` |
| `message` | the route, then the problems found, at most three |

Three situations, and only three, count as a problem:

- a cancellation, at departure or at arrival;
- a departure delay equal to or above the threshold;
- a platform change, when iRail reports the platform is not the usual one.

The iRail alerts attached to trains and the network disturbances **never call
that command**. They feed the "Disturbance message" command and may light up
"Journey disrupted", nothing more: they are free text, often works or commercial
notices, worth reading but not worth waking anyone up for.

A train that has already left is no longer reported: keeping on alerting about it
would only delay the alert about the next one.

**The same situation is only reported once.** Without that memory, a train
delayed by twenty minutes would send twenty notifications, one per cron pass.
One exception: a delay growing by a ten-minute band is reported again. A train
announced at +5 and then at +25 deserves a second alert, because you may already
have decided to leave on the strength of the first one.

The **Acknowledge alert** action command, and the button of the same name on the
journey page, remember **the trains currently in trouble**. Those no longer
trigger anything, even if their delay grows worse, even if they turn into
cancellations. Acknowledging says "I have seen that delay", not "stop
warning me about anything":

- a problem on **another** train of the window still alerts;
- the acknowledgement goes away with the train. Once it has left, it drops off
  the board and its acknowledgement with it: the next day, the same IC alerts
  again;
- the button reports how many trains were acknowledged, or that there was no
  alert to acknowledge.

## The fallback train

When the plugin wakes you to say your train is cancelled, it already knows the
next one: it is in the same reading. Four commands expose it, and the
notification offers it by itself:

> Soignies → Bruxelles-Central — IC 1706 at 07:38 cancelled — fallback: P 7802
> at 07:54, platform 1

The fallback is the first train **of the same day** leaving after the next one,
neither cancelled nor already gone. Two limits worth knowing:

- there is no fallback beyond the last train of the window. Widen the window if
  you want one offered after your usual last train;
- the fallback never crosses over to another day. At 8:50 am on a window ending
  at 9, "Fallback in" is `-1`: offering you tomorrow's first train would help
  nobody.

## Available commands

Twenty-seven commands, four of them visible by default — three tiles and a
button.

| Command | Type | Description |
|---|---|---|
| Next train (`summary`) | info / string | the summary line: `IC 2137 · 07:42 · +5 min · platform 3`, or `No train in the window` |
| Next train delay (`next_delay`) | info / numeric, min | historised. `0` when the train is on time |
| Journey disrupted (`disturbed`) | info / binary | `1` as soon as a train is cancelled, an alert is attached to the next train, its platform changes, or a delay reaches the threshold |
| Scheduled departure (`next_time`) | info / string | the timetable time, `HH:MM` |
| Actual departure (`next_real`) | info / string | the timetable time plus the delay |
| Departure in (`next_countdown`) | info / numeric, min | minutes before the actual departure, never negative. `-1` only when there is no train at all |
| Train (`next_vehicle`) | info / string | `IC 2137`, `S13424`... |
| Direction (`next_direction`) | info / string | the train's displayed destination, not your arrival station |
| Platform (`next_platform`) | info / string | empty while iRail has not published it |
| Platform change (`next_platform_changed`) | info / binary | `1` when the platform is not the usual one |
| Next train cancelled (`next_canceled`) | info / binary | cancellation at departure or at arrival |
| Actual arrival (`next_arrival`) | info / string | the arrival time, arrival delay included |
| Journey duration (`next_duration`) | info / numeric, min | |
| Transfers (`next_transfers`) | info / numeric | `0` for a direct train |
| Occupancy (`next_occupancy`) | info / string | `Low`, `Medium`, `High`, or empty |
| Fallback train (`next2_summary`) | info / string | the same summary line, for the train after |
| Fallback departure (`next2_time`) | info / string | its timetable time, `HH:MM` |
| Fallback train (number) (`next2_vehicle`) | info / string | `P 7800`, `IC 3706`… |
| Fallback in (`next2_countdown`) | info / numeric, min | minutes before its actual departure. `-1` when there is no fallback |
| Trains in the window (`trains_count`) | info / numeric | number of known trains, both days together |
| Delayed trains (`trains_delayed`) | info / numeric | historised. Any delay, even of one minute |
| Cancelled trains (`trains_canceled`) | info / numeric | historised |
| Maximum delay (`delay_max`) | info / numeric, min | the worst delay of the window |
| Disturbance message (`alert_message`) | info / string | the iRail alerts and the network disturbances, at most three, separated by dashes |
| Last check (`last_update`) | info / string | the time of the last **successful** read, `DD/MM/YYYY HH:MM` |
| Refresh (`refresh`) | action | forces a read from iRail, no more than once every 20 seconds |
| Acknowledge alert (`acknowledge`) | action | silences the alerts of the trains currently in trouble; the others stay watched |

A few details that keep scenarios honest:

- "Delayed trains" counts any delay, even of one minute; it is "Maximum delay"
  and "Journey disrupted" that take your threshold into account;
- the next train is the first one that has not left yet, with one minute of
  leeway after its actual time: a train you have just missed is no longer the
  next one;
- "Departure in" never drops below `0` as long as a train is known, and is `-1`
  when there is none. Test on `>= 0` before comparing it to a duration;
- "Trains in the window", "Delayed trains", "Cancelled trains" and "Maximum
  delay" cover **both known windows**: today's and the one of the next active
  day. A train announced at +30 for tomorrow morning therefore inflates those
  counters tonight, and lights up "Journey disrupted" while nothing is running
  any more. To speak only of the train that concerns you, use the next train
  commands;
- "Occupancy" is very often empty. iRail publishes it from the feedback of
  travellers using the SNCB app: most trains get none. A scenario must not depend
  on that value.

## On the dashboard

Four commands are visible by default: the **Next train**, **Next train delay**
and **Journey disrupted** tiles, and the **Refresh** button. The others exist for
scenarios and graphs, and would otherwise pile up as a column of twenty tiles for
a single journey. To display another one, make it visible from the Commands tab.

The "Next train" tile is a plugin widget. It shows a train pictogram, the train
name and its departure time in large type, then a delay label and a platform
label. The delay label is green and reads "no delay" when all is well, orange
and reads `+7 min` when the train is late, red and reads `CANCELLED` when it will
not run at all. The time is then struck through and the pictogram takes the same
colour: the tile stays readable in black and white, or for someone who cannot
tell red apart. When there is nothing to show, the tile repeats the sentence as
it is: `No train in the window`.

Three optional settings, in the command configuration, Display tab, "Optional
widget parameters" block:

| Parameter | Value | Effect |
|---|---|---|
| `time` | `duration` or `date` | shows how old the value is, under the tile |
| `platform` | `0` | hides the platform label |
| `timeonly` | `1` | shows the time only, without the train name |

"Next train delay" gets two alert thresholds when the device is created: orange
from the first minute of delay, red from your own threshold on. The colour of the
tile and the notification then say the same thing. Those thresholds are only set
once: if you change them, the plugin never touches them again.

The plugin's binary commands — "Journey disrupted", "Platform change", "Next
train cancelled" — use a widget showing a red triangle when true, a green tick
otherwise. A journey without a problem has to be readable at a glance, without
telling a `0` from a `1`.

## Using it in a scenario

The examples below are pseudo-code: they show the trigger, the condition and the
idea of the action, to be written out in the scenario block of your choice.
`message::notification` stands for the action command of your own notification
tool — the very one you would pick as the command to trigger.

Being warned of a serious delay, on event:

```
Trigger: #[Home][Morning train][Next train delay]#
If: #[Home][Morning train][Next train delay]# >= 10
Then: message::notification with
      "Train " + #[Home][Morning train][Train]# + " delayed by "
      + #[Home][Morning train][Next train delay]# + " min"
```

Knowing at once that a train will not run:

```
Trigger: #[Home][Morning train][Next train cancelled]#
If: #[Home][Morning train][Next train cancelled]# == 1
Then: message::notification with
      "Cancelled: " + #[Home][Morning train][Train]#
      + " at " + #[Home][Morning train][Scheduled departure]#
```

> "Scheduled departure" is the time of the next train, cancelled ones included: a
> cancelled train stays the next one until its time has passed. These commands
> describe that train, not the one after it.

Announcing the platform when it is time to leave, at 7:10 am:

```
Trigger: schedule, 10 7 * * 1-5
Then: message::notification with
      #[Home][Morning train][Next train]#
```

Turning a lamp red as long as the journey is disrupted:

```
Trigger: #[Home][Morning train][Journey disrupted]#
If: #[Home][Morning train][Journey disrupted]# == 1
Then: #[Living room][Lamp][Red]#
Else: #[Living room][Lamp][Off]#
```

Announcing nothing while the departure is still far away:

```
If: #[Home][Morning train][Departure in]# >= 0
    AND #[Home][Morning train][Departure in]# <= 20
```

## Plugin configuration

Plugins → Organization → SNCB/NMBS → **Configuration**, "iRail service" block.

| Setting | Role |
|---|---|
| Request timeout | seconds before giving up on an iRail call. 8 by default, 3 at the very least. Since the journey is read every minute while being watched, a high value would hold up the cron of the whole box for a timetable that will be read again a minute later |
| Label language | language in which iRail returns station names, directions and disturbances: French, Dutch, German or English. By default, the Jeedom language |

The language deserves a word in Belgium: iRail returns station names in the
requested language, and a Brussels station does not have the same name in French
and in Dutch. Changing that setting therefore changes the names shown in the
lists, but not the identifiers already saved in your journeys.

## The data

Everything comes from [iRail](https://docs.irail.be/), which republishes the
SNCB/NMBS open data: timetables, real time, cancellations, platforms, network
disturbances. The data is under the CC0 licence.

There is **no API key to ask for** and no account to create. iRail does ask,
however, that every client identifies itself through its User-Agent: the plugin
does so, with its name, its version and the address of its repository. That is
the counterpart of a free service, hosted by a non-profit.

It is also the reason for the whole call policy described above: minute-by-minute
watching reserved for the useful window, one reading an hour the rest of the
time, shared disturbances, the station list kept for a week. A plugin calling
without restraint would not merely get blocked: it would degrade the service for
everyone.

> Network disturbances are matched against the **names** of the two stations of
> the journey, looked up in the title and the description of the disturbance.
> iRail does not publish the list of stations concerned: this is all the source
> allows. The name has to be found whole, between two word boundaries — so "Mol"
> no longer sticks to "Molenbeek" — and names shorter than four letters are
> ignored, failing which "Ans" would recognise itself inside the French word
> "dans". It remains that a "Mechelen - Dendermonde" disturbance shows up on
> every journey naming either of those two stations, even with no relation to the
> closed section, and that a station with too short a name brings back no
> disturbance at all. The plugin prefers that false positive — which informs — to
> the false negative, which leaves you on the platform.

Only ongoing incidents are kept. iRail mixes incidents and planned works in the
same list, the latter by far the more numerous: a commuter wants to know what is
happening this morning, not what is planned in three weeks. The alerts attached
to trains are filtered the same way, on their validity period.

## Troubleshooting

Logs are under Analysis → Logs, log `sncbnmbs`.

| Symptom | Likely cause |
|---|---|
| "Incomplete journey: choose a departure station and an arrival station" | a station was typed but not picked from the list: the plugin works with iRail identifiers |
| "The departure station and the arrival station are the same" | twice the same station; iRail only answers with a timeout, the plugin refuses before the call |
| "No journey found: check the stations and the time window" | iRail does not know that connection, or no train runs at that time |
| "iRail is not answering" | outage, network cut or too short a timeout; the plugin retries on the next pass |
| "iRail refused the request: HTTP ..." | service error; the known timetable stays displayed |
| "No train in the window" | today's window is already over and no active day is near, or it is too narrow to hold a train |
| The window only returns one or two trains | the requested number of trains is low, or the upper bound of the window cuts off the following ones |
| "Occupancy" always empty | normal: iRail only publishes occupancy for trains commented on by travellers |
| A disturbance unrelated to your line | matching is done on station names; see the warning above |
| No notification despite a delay | the delay does not reach the threshold, the problem has already been reported, the train has been acknowledged, or no command to trigger is selected |
| "Alert command not found" | the chosen action command has been deleted since; pick another one |
| Commands stop moving | the core cron, shared by every plugin, is no longer running; check Analysis → Task engine |
