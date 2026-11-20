# iOS Home Screen compass permission notes

## Status (20 November 2026)

GaugeIQ's compass works in the iPhone Home Screen web app, but the user reports that iOS displays the Motion & Orientation permission prompt again after reopening the app.

The in-app diagnostic log supplied on 20 November 2026 confirms:

- GaugeIQ launches in standalone mode (`standalone: true`, `displayMode: "standalone"`).
- `DeviceOrientationEvent.requestPermission()` is available.
- Each recorded Wind and Rain Radar permission request resolved to `"granted"`.
- A `deviceorientation` event arrived immediately after each successful request.
- The first orientation event included an iOS native heading (`webkitCompassHeading`) and alpha value.

The log does **not** record whether iOS displayed a native prompt during each call. The repeated prompt is the user's observed behaviour; the `"granted"` result alone cannot tell us whether iOS showed a prompt or silently reused a grant.

## Current implementation requirements

- On iOS, call `DeviceOrientationEvent.requestPermission()` directly from the user's Enable Compass tap. iOS requires transient user activation for this API; moving the call to page startup or an asynchronous background task can prevent sensor delivery.
- Keep the Wind and Rain Radar enable controls user initiated.
- Treat the localStorage permission flag as diagnostic/application state only. It is not proof of the operating system's current permission state.
- Do not attempt to auto-accept, simulate a tap on Allow, or bypass the native permission prompt. A web app cannot grant its own iOS motion/orientation permission.
- Do not rely on `navigator.permissions.query()` to read Device Orientation permission state; browser support for this permission is not consistently exposed.
- The current implementation requests permission when the user enables either compass control. Do not remove the user-gesture request solely because a previous localStorage flag exists: an earlier experiment that skipped the request prevented iOS orientation events from being delivered.

## Diagnostic interpretation

The supplied log contains successful request/result/event sequences for both controls:

- 12:18:01 Wind request → 12:18:03 `granted` → orientation event.
- 12:18:15 Radar request → 12:18:15 `granted` → orientation event.
- 12:19:06 Wind request → 12:19:08 `granted` → orientation event.
- 12:23:47 Wind request → 12:23:49 `granted` → orientation event.
- 12:23:53 Radar request → 12:23:53 `granted` → orientation event.
- 12:24:11 Wind request → 12:24:13 `granted` → orientation event.

Several further app launches are recorded without a compass request, which is expected when the user opens the app but does not enable the compass.

This evidence shows that the JavaScript permission request and sensor activation succeed. It does not establish why iOS is presenting the prompt again after a cold launch.

## Next investigation

1. Keep the current user-gesture permission request until a safe alternative is demonstrated on the affected iPhone.
2. Test a cold close/reopen cycle and record whether the native prompt appears separately for Wind and Radar.
3. Compare the result when enabling Wind only, Radar only, and both, to distinguish an iOS grant-persistence issue from duplicate requests within one app session.
4. Check current WebKit/iOS reports for Home Screen web-app permission persistence. Reports involving camera or microphone are useful context but are not proof of the same Device Orientation defect.
5. Only change the permission flow after the cause is established and sensor delivery is verified on the actual iPhone.

## Diagnostic data and privacy

The temporary Compass diagnostics panel stores its latest 60 events in this browser's localStorage. The log is copied manually by the user and is not uploaded by GaugeIQ. It can be cleared using `GaugeIQCompassDiagnostics.clear()` from the browser's JavaScript context.

## References

- [MDN: DeviceOrientationEvent.requestPermission()](https://developer.mozilla.org/en-US/docs/Web/API/DeviceOrientationEvent/requestPermission_static)
- [WebKit Bug 327237](https://bugs.webkit.org/show_bug.cgi?id=327237) — a report about Home Screen web-app camera/microphone permission persistence on iOS 26.6.2. This is related platform context only; it does not establish a Device Orientation bug.
