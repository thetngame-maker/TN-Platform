(() => {
  const metersBetween = (lat1, lon1, lat2, lon2) => {
    const earth = 6371000;
    const toRad = (value) => value * Math.PI / 180;
    const p1 = toRad(lat1);
    const p2 = toRad(lat2);
    const dp = toRad(lat2 - lat1);
    const dl = toRad(lon2 - lon1);
    const a = Math.sin(dp / 2) ** 2 + Math.cos(p1) * Math.cos(p2) * Math.sin(dl / 2) ** 2;
    return earth * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  };

  document.querySelectorAll('.tng-runtime-gps-form').forEach((form) => {
    const button = form.querySelector('.tng-runtime-location-button');
    const status = form.querySelector('.tng-runtime-location-status');
    const latField = form.querySelector('input[name="player_lat"]');
    const lngField = form.querySelector('input[name="player_lng"]');
    if (!button || !status || !latField || !lngField) return;

    button.addEventListener('click', () => {
      if (!navigator.geolocation) {
        status.textContent = 'Location is not supported by this browser.';
        return;
      }

      button.disabled = true;
      button.textContent = 'Checking location…';
      status.textContent = 'Getting your current location…';

      navigator.geolocation.getCurrentPosition((position) => {
        const latitude = position.coords.latitude;
        const longitude = position.coords.longitude;
        latField.value = String(latitude);
        lngField.value = String(longitude);

        const stop = form.closest('.tng-runtime-stop');
        const targetLat = stop ? Number(stop.dataset.targetLat || 0) : 0;
        const targetLng = stop ? Number(stop.dataset.targetLng || 0) : 0;
        const radius = Number(form.dataset.radius || 30);

        if (targetLat && targetLng) {
          const distance = Math.round(metersBetween(latitude, longitude, targetLat, targetLng));
          status.textContent = distance <= radius
            ? `You are about ${distance} m away. Checking in…`
            : `You are about ${distance} m away. You need to be within ${radius} m.`;
          if (distance > radius) {
            button.disabled = false;
            button.textContent = 'Check again';
            return;
          }
        } else {
          status.textContent = 'Location found. Verifying checkpoint…';
        }

        form.submit();
      }, (error) => {
        const messages = {
          1: 'Location permission was denied. Allow location access and try again.',
          2: 'Your location is temporarily unavailable. Try again.',
          3: 'Location lookup timed out. Try again.'
        };
        status.textContent = messages[error.code] || 'Could not read your location. Try again.';
        button.disabled = false;
        button.textContent = 'Use my location';
      }, {
        enableHighAccuracy: true,
        timeout: 12000,
        maximumAge: 5000
      });
    });
  });
})();
