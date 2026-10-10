document.getElementById('locationButton')?.addEventListener('click',()=>{
    const button=document.getElementById('locationButton');
    const latitude=document.getElementById('latitude');
    const longitude=document.getElementById('longitude');
    const locationName=document.getElementById('location_name');

    if(!navigator.geolocation){
        alert('Location services are not available in this browser. Please enter your coordinates manually.');
        return;
    }

    if(!window.isSecureContext){
        alert('GaugeIQ needs a secure HTTPS connection to access your location. Please open the installer using HTTPS.');
        return;
    }

    const originalText=button.textContent;
    button.disabled=true;
    button.textContent='Finding your location…';

    const restoreButton=()=>{
        button.disabled=false;
        button.textContent=originalText;
    };

    const success=(position)=>{
        latitude.value=position.coords.latitude.toFixed(6);
        longitude.value=position.coords.longitude.toFixed(6);
        locationName.value='My location';
        restoreButton();
    };

    const failure=(error)=>{
        restoreButton();

        let message='GaugeIQ could not access your location. You can enter the coordinates manually.';

        switch(error.code){
            case error.PERMISSION_DENIED:
                message='Location access was denied. Allow location access for GaugeIQ in your browser/site settings, then try again.';
                break;
            case error.POSITION_UNAVAILABLE:
                message='Your device could not determine its current location. Make sure Location Services are enabled and try again.';
                break;
            case error.TIMEOUT:
                message='GaugeIQ timed out while waiting for your location. Make sure Location Services are enabled and try again.';
                break;
        }

        alert(message);
    };

    navigator.geolocation.getCurrentPosition(success,failure,{
        enableHighAccuracy:true,
        timeout:15000,
        maximumAge:0
    });
});