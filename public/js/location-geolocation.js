(() => {
  'use strict';

  const errorMessage = (error) => {
    if (error && error.code === 1) {
      return 'Location permission was denied. Allow location access for this site in your browser settings, then try again.';
    }
    if (error && error.code === 2) {
      return 'Your device could not determine its location. Check Location Services and try again.';
    }
    if (error && error.code === 3) {
      return 'The location request timed out. Try again, preferably outdoors or near a window.';
    }
    return 'Could not determine device location. Please enter the coordinates manually.';
  };

  const locate = (button, latId, lonId, helpId) => {
    const lat = document.getElementById(latId);
    const lon = document.getElementById(lonId);
    const help = document.getElementById(helpId);

    if (!lat || !lon || !help) {
      if (help) help.textContent = 'The location fields could not be found. Reload this page and try again.';
      return;
    }

    if (!window.isSecureContext) {
      help.textContent = 'Device location requires a secure HTTPS connection. Open GaugeIQ using its HTTPS address.';
      return;
    }

    if (!navigator.geolocation || typeof navigator.geolocation.getCurrentPosition !== 'function') {
      help.textContent = 'This browser does not support device location. Please enter the coordinates manually.';
      return;
    }

    if (button.dataset.locating === '1') return;
    button.dataset.locating = '1';
    button.disabled = true;
    const originalLabel = button.textContent;
    button.textContent = 'Finding location…';
    help.textContent = 'Requesting an approximate device location… Please allow access if your browser asks.';

    const finish = () => {
      button.disabled = false;
      button.textContent = originalLabel;
      delete button.dataset.locating;
    };

    const success = (position) => {
      const latitude = position && position.coords && position.coords.latitude;
      const longitude = position && position.coords && position.coords.longitude;
      const accuracy = position && position.coords && position.coords.accuracy;

      if (!Number.isFinite(latitude) || !Number.isFinite(longitude) ||
          Math.abs(latitude) > 90 || Math.abs(longitude) > 180) {
        help.textContent = 'The device returned invalid coordinates. Please enter them manually.';
        finish();
        return;
      }

      lat.value = latitude.toFixed(6);
      lon.value = longitude.toFixed(6);
      lat.dispatchEvent(new Event('input', { bubbles: true }));
      lon.dispatchEvent(new Event('input', { bubbles: true }));
      const accuracyText = Number.isFinite(accuracy) ? ' (±' + Math.round(accuracy) + ' m accuracy)' : '';
      help.textContent = 'Location found' + accuracyText + '. Check the location name, then save.';
      finish();
    };

    const requestHighAccuracy = () => {
      help.textContent = 'Approximate location was unavailable. Trying a more accurate GPS fix…';
      navigator.geolocation.getCurrentPosition(success, (error) => {
        help.textContent = errorMessage(error);
        finish();
      }, { enableHighAccuracy: true, timeout: 30000, maximumAge: 0 });
    };

    try {
      // A balanced first request works better on phones when GPS reception is weak.
      navigator.geolocation.getCurrentPosition(success, (error) => {
        if (error && error.code === 1) {
          help.textContent = errorMessage(error);
          finish();
          return;
        }
        requestHighAccuracy();
      }, { enableHighAccuracy: false, timeout: 15000, maximumAge: 60000 });
    } catch (error) {
      help.textContent = 'The browser could not start the location request: ' +
        (error && error.message ? error.message : 'unknown error') + '.';
      finish();
    }
  };

  const bind = (buttonId, latId, lonId, helpId) => {
    const button = document.getElementById(buttonId);
    if (!button) return;
    button.addEventListener('click', () => locate(button, latId, lonId, helpId));
  };

  bind('useCurrentLocation', 'location_latitude', 'location_longitude', 'locationHelp');
  bind('useSecondDeviceLocation', 'second_location_latitude', 'second_location_longitude', 'secondLocationHelp');
})();