# SNCB/NMBS plugin changelog

## 1.2

**Leaving on time**

- New "Time to the station" setting and new "Leave in" command
  (`leave_countdown`): minutes before you must leave home, delay included. If
  the next train is cancelled, the count is based on the fallback train.

**Fixes**

- Minute-by-minute watching unticked: the journey was only read once an hour,
  even during the time window. It is read every quarter of an hour again, as
  the help text says.
- Transient iRail failures (HTTP 5xx, timeout) only reach the message centre
  from the third consecutive failure; before that, they are logged as warnings.
- "Disrupted journey", "Trains in window", "Delayed trains", "Cancelled
  trains" and "Maximum delay" now only count the upcoming trains of the current
  window: a train that left late, or tomorrow's trains, no longer keep the
  journey "disrupted".
- The red colour threshold of "Next train delay" now follows the journey's
  threshold when it changes, unless it was customised.

## 1.1

**The fallback train**

- Four commands expose the train after: `next2_summary`, `next2_time`,
  `next2_vehicle`, `next2_countdown`. The plugin already knew it; it kept it to
  itself.
- The notification offers it by itself: "IC 1706 at 07:38 cancelled — fallback:
  P 7802 at 07:54, platform 1". The fallback never crosses over to another day.

**Less load on iRail, without losing real time**

- A journey with an unknown station fired 1,200 requests a day, forever. A
  back-off after failure puts an end to it, doubling the wait with every new
  attempt.
- That back-off is capped at two minutes while watching: a train can be
  cancelled at any moment there, and a passing outage must not blind us when the
  plugin is of use.
- Network disruptions are only read again while watching, and their cache goes
  from three to ten minutes.
- Outside the watched window, one reading an hour instead of one every quarter
  of an hour, and none between 1 and 5 in the morning.
- A window more than six hours away is only read again every three hours.
- The minute-by-minute pace during the window has not changed.

**Fixes**

- Creating a device was impossible: an object property without a leading
  underscore was taken for a database column.
- Saving a journey kept nothing: a private `setCmd()` method collided with the
  core's saving mechanism.
- A new journey was born disabled and invisible, hence ignored by the cron.
- A station search with no result erased the station already saved.
- The days and categories of the previous journey stayed ticked on the next one.
- iRail sometimes returns the same departure twice: the train was counted and
  displayed twice.
- A night window vanished at midnight; a window whose start equals its end
  lasted twenty-four hours.
- Mid-window, a train scheduled before the current time but delayed was no
  longer offered.
- A platform change on an already delayed train was never reported; a shrinking
  delay raised an alert.
- Text coming from iRail can no longer carry angle brackets through to widgets.
- The number of trains per window is capped at six, iRail's real limit.

## 1.0

- First release.
- "Journey" device: departure station, arrival station, time window, active days.
- Today's and tomorrow's trains, from the iRail API.
- Minute-by-minute checks of delays, cancellations, platform changes and disturbances.
- Info commands for the next train and for the overall state of the journey.
- "Refresh" and "Acknowledge" action commands.
- Automatic triggering of a Jeedom action command beyond a delay threshold.
