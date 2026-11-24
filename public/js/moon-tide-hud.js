(() => {
    'use strict';
    const root = document.getElementById('moonTideHud');
    if (!root) return;
    const lat = Number(root.dataset.latitude);
    const lon = Number(root.dataset.longitude);
    const zone = root.dataset.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    const validLocation = root.dataset.latitude.trim() !== '' && root.dataset.longitude.trim() !== '' && Number.isFinite(lat) && Number.isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180;
    const $ = id => document.getElementById(id);
    // Temporary Section 05 diagnostic logging has been removed.
    const tideLog = () => {};

    const synodic = 29.530588853;
    const knownNewMoon = Date.UTC(2000, 0, 6, 18, 14);
    const now = new Date();
    const cycle = ((now.getTime() - knownNewMoon) / 86400000 % synodic + synodic) % synodic;
    const phase = cycle / synodic;
    const illumination = (1 - Math.cos(2 * Math.PI * phase)) / 2;
    const names = ['NEW MOON','WAXING CRESCENT','FIRST QUARTER','WAXING GIBBOUS','FULL MOON','WANING GIBBOUS','LAST QUARTER','WANING CRESCENT'];
    const phaseName = names[Math.round(phase * 8) % 8];
    const nextFullDays = ((0.5 - phase + 1) % 1) * synodic;
    const nextFull = new Date(now.getTime() + nextFullDays * 86400000);
    const anomaly = 2 * Math.PI * ((cycle - 1.0) / 27.55455);
    const distance = 385001 - 20905 * Math.cos(anomaly);
    $('mthPhaseName').textContent = phaseName;
    $('mthIllumination').textContent = (illumination * 100).toFixed(1) + '%';
    $('mthLunarAge').textContent = cycle.toFixed(1) + ' DAYS';
    $('mthMoonDistance').textContent = Math.round(distance).toLocaleString() + ' KM';
    $('mthNextFull').textContent = nextFull.toLocaleDateString(undefined, {timeZone:zone,day:'2-digit',month:'short'});
    const disc = $('mthMoonDisc');
    // Apparent orientation of lunar north in the selected observer's local sky.
    let lunarSurfaceRotation = validLocation && lat < 0 ? Math.PI : 0;
    function renderLunarDisc(target, lunarPhase, litFraction) {
        // Quiet, non-cartoon fallback if the photographic texture is unavailable.
        const size = 320;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        canvas.setAttribute('aria-hidden', 'true');
        Object.assign(canvas.style, {position:'absolute',inset:'0',width:'100%',height:'100%',display:'block',borderRadius:'50%'});
        target.replaceChildren(canvas);
        const context = canvas.getContext('2d');
        if (context) {
            context.clearRect(0, 0, size, size);
            const hemisphere = validLocation && lat >= 0 ? -1 : 1;
            const angle = Math.PI * 2 * lunarPhase;
            const lightX = -Math.sin(angle) * hemisphere;
            const gradient = context.createRadialGradient(size*(0.5-lightX*0.16),size*0.34,size*0.025,size*0.5,size*0.5,size*0.49);
            gradient.addColorStop(0,'#b9bdba');
            gradient.addColorStop(0.72,'#777d7c');
            gradient.addColorStop(1,'#242b30');
            context.fillStyle = gradient;
            context.beginPath();
            context.arc(size/2,size/2,size*0.485,0,Math.PI*2);
            context.fill();
        }
        target.style.setProperty('--mth-phase',String(lunarPhase));
        target.style.setProperty('--mth-illumination',String(litFraction));
        target.style.setProperty('--mth-moon-size',(58+42*Math.max(0,Math.min(1,litFraction)))+'%');
        target.style.setProperty('--mth-shadow-width','0%');
        target.dataset.phase=lunarPhase.toFixed(4);
        target.dataset.lunarTexture='fallback';
        target.setAttribute('role','img');
        target.setAttribute('aria-label',phaseName+', '+(litFraction*100).toFixed(1)+'% illuminated. Lunar surface texture unavailable.');
    }
    if (disc) {
        // NASA LRO WAC global color mosaic served locally (same-origin) for reliable iOS/WebGL texture access.
        // Credit: NASA's Scientific Visualization Studio; Ernie Wright (USRA); Noah Petro (NASA/GSFC).
        // The equirectangular source is centred at 0° lunar longitude.
        renderLunarDisc(disc,phase,illumination);
        const lunarTexture=new Image();
        lunarTexture.crossOrigin='anonymous';
        lunarTexture.onload=()=>{
            try { renderTexturedLunarDisc(disc,phase,illumination,lunarTexture); }
            catch(error) {
                try { renderTexturedLunarDisc2D(disc,phase,illumination,lunarTexture); }
                catch(fallbackError) { renderLunarDisc(disc,phase,illumination); }
            }
        };
        lunarTexture.onerror=()=>renderLunarDisc(disc,phase,illumination);
        lunarTexture.src='/assets/moon-lroc-2k.jpg';
    }

    function renderTexturedLunarDisc(target, lunarPhase, litFraction, textureImage) {
        const size = 320;
        // A canvas cannot switch from a 2D context to WebGL, so replace
        // the procedural fallback canvas with a fresh WebGL canvas.
        const canvas = document.createElement('canvas');
        target.replaceChildren(canvas);
        canvas.width = size;
        canvas.height = size;
        Object.assign(canvas.style, {
            position: 'absolute', inset: '0', width: '100%', height: '100%',
            display: 'block', borderRadius: '50%'
        });
        const gl = canvas.getContext('webgl', { alpha: true, antialias: true, premultipliedAlpha: false });
        if (!gl) throw new Error('WebGL unavailable');

        const vertexSource = `
            attribute vec2 a_position;
            varying vec2 v_uv;
            void main() {
                v_uv = a_position * 0.5 + 0.5;
                gl_Position = vec4(a_position, 0.0, 1.0);
            }
        `;
        const fragmentSource = `
            precision highp float;
            varying vec2 v_uv;
            uniform sampler2D u_texture;
            uniform float u_light_x;
            uniform float u_light_z;
            uniform float u_surface_rotation;
            void main() {
                vec2 p = (v_uv - 0.5) * 2.0;
                float r2 = dot(p, p);
                if (r2 > 1.0) {
                    gl_FragColor = vec4(0.0);
                    return;
                }
                float nz = sqrt(max(0.0, 1.0 - r2));
                vec3 normal = normalize(vec3(p.x, -p.y, nz));
                float diffuse = max(0.0, normal.x * u_light_x + normal.z * u_light_z);
                float limb = 0.82 + 0.18 * nz;
                float lighting = (0.018 + 0.982 * diffuse) * limb;

                // Equirectangular longitude/latitude lookup on the visible sphere.
                // Rotate lunar geography into the configured observer's local sky view
                // without rotating the apparent phase illumination.
                float cr = cos(u_surface_rotation);
                float sr = sin(u_surface_rotation);
                vec3 mapNormal = vec3(normal.x*cr-normal.y*sr,normal.x*sr+normal.y*cr,normal.z);
                float longitude = atan(mapNormal.x, mapNormal.z);
                float latitude = asin(clamp(mapNormal.y, -1.0, 1.0));
                vec2 uv = vec2(longitude / 6.28318530718 + 0.5,
                               0.5 - latitude / 3.14159265359);
                vec3 albedo = texture2D(u_texture, uv).rgb;
                // Slightly neutralise the map's color enhancement while
                // preserving real maria, highlands and crater structure.
                float grey = dot(albedo, vec3(0.299, 0.587, 0.114));
                albedo = mix(vec3(grey), albedo, 0.42);
                gl_FragColor = vec4(albedo * lighting, 1.0);
            }
        `;
        const compile = (type, source) => {
            const shader = gl.createShader(type);
            gl.shaderSource(shader, source);
            gl.compileShader(shader);
            if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
                const message = gl.getShaderInfoLog(shader);
                gl.deleteShader(shader);
                throw new Error(message || 'Moon shader compilation failed');
            }
            return shader;
        };
        const program = gl.createProgram();
        gl.attachShader(program, compile(gl.VERTEX_SHADER, vertexSource));
        gl.attachShader(program, compile(gl.FRAGMENT_SHADER, fragmentSource));
        gl.linkProgram(program);
        if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
            throw new Error(gl.getProgramInfoLog(program) || 'Moon shader link failed');
        }
        gl.useProgram(program);
        const vertices = new Float32Array([-1,-1, 1,-1, -1,1, 1,1]);
        const buffer = gl.createBuffer();
        gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
        gl.bufferData(gl.ARRAY_BUFFER, vertices, gl.STATIC_DRAW);
        const position = gl.getAttribLocation(program, 'a_position');
        gl.enableVertexAttribArray(position);
        gl.vertexAttribPointer(position, 2, gl.FLOAT, false, 0, 0);

        const texture = gl.createTexture();
        gl.bindTexture(gl.TEXTURE_2D, texture);
        gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, true);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
        gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, textureImage);

        const angle = Math.PI * 2 * lunarPhase;
        const hemisphere = validLocation && lat >= 0 ? -1 : 1;
        gl.uniform1f(gl.getUniformLocation(program, 'u_light_x'), -Math.sin(angle) * hemisphere);
        gl.uniform1f(gl.getUniformLocation(program, 'u_light_z'), -Math.cos(angle));
        gl.uniform1f(gl.getUniformLocation(program, 'u_surface_rotation'), lunarSurfaceRotation);
        gl.viewport(0, 0, size, size);
        gl.clearColor(0, 0, 0, 0);
        gl.clear(gl.COLOR_BUFFER_BIT);
        gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);

        target.style.setProperty('--mth-phase', String(lunarPhase));
        target.style.setProperty('--mth-illumination', String(litFraction));
        target.style.setProperty('--mth-moon-size', (58 + 42 * Math.max(0, Math.min(1, litFraction))) + '%');
        target.style.setProperty('--mth-shadow-width', '0%');
        target.dataset.phase = lunarPhase.toFixed(4);
        target.setAttribute('role', 'img');
        target.setAttribute('aria-label', phaseName + ', ' + (litFraction * 100).toFixed(1) + '% illuminated. NASA Lunar Reconnaissance Orbiter surface texture.');
        target.dataset.lunarTexture = 'NASA LRO WAC';
    }
    function renderTexturedLunarDisc2D(target,lunarPhase,litFraction,textureImage) {
        // Canvas fallback for devices without WebGL: project the same real
        // equirectangular lunar map onto a shaded sphere.
        const size=320, source=document.createElement('canvas');
        source.width=textureImage.naturalWidth; source.height=textureImage.naturalHeight;
        const sc=source.getContext('2d',{willReadFrequently:true});
        sc.drawImage(textureImage,0,0);
        const src=sc.getImageData(0,0,source.width,source.height).data;
        const canvas=document.createElement('canvas'); canvas.width=size; canvas.height=size;
        Object.assign(canvas.style,{position:'absolute',inset:'0',width:'100%',height:'100%',display:'block',borderRadius:'50%'});
        const ctx=canvas.getContext('2d'), out=ctx.createImageData(size,size);
        const hemisphere=validLocation&&lat>=0?-1:1, angle=Math.PI*2*lunarPhase;
        const lightX=-Math.sin(angle)*hemisphere, lightZ=-Math.cos(angle);
        const rotation=lunarSurfaceRotation, cr=Math.cos(rotation), sr=Math.sin(rotation);
        const centre=(size-1)/2, radius=size*0.485;
        for(let y=0;y<size;y++){
            const py=-(y-centre)/radius;
            for(let x=0;x<size;x++){
                const px=(x-centre)/radius, r2=px*px+py*py;
                if(r2>1) continue;
                const nz=Math.sqrt(1-r2), diffuse=Math.max(0,px*lightX+nz*lightZ);
                const shade=(0.018+0.982*diffuse)*(0.82+0.18*nz);
                const mx=px*cr-py*sr, my=px*sr+py*cr;
                const longitude=Math.atan2(mx,nz), latitude=Math.asin(Math.max(-1,Math.min(1,my)));
                const sx=Math.max(0,Math.min(source.width-1,Math.floor(((longitude/(2*Math.PI)+0.5)*source.width)%source.width)));
                const sy=Math.max(0,Math.min(source.height-1,Math.floor((0.5-latitude/Math.PI)*source.height)));
                const si=(sy*source.width+sx)*4, di=(y*size+x)*4;
                const grey=src[si]*0.299+src[si+1]*0.587+src[si+2]*0.114;
                for(let k=0;k<3;k++) out.data[di+k]=Math.round((src[si+k]*0.58+grey*0.42)*shade);
                out.data[di+3]=255;
            }
        }
        ctx.putImageData(out,0,0); target.replaceChildren(canvas);
        target.style.setProperty('--mth-phase',String(lunarPhase));
        target.style.setProperty('--mth-illumination',String(litFraction));
        target.style.setProperty('--mth-moon-size',(58+42*Math.max(0,Math.min(1,litFraction)))+'%');
        target.style.setProperty('--mth-shadow-width','0%');
        target.dataset.phase=lunarPhase.toFixed(4);
        target.dataset.lunarTexture='NASA LRO WAC (2D)';
        target.setAttribute('role','img');
        target.setAttribute('aria-label',phaseName+', '+(litFraction*100).toFixed(1)+'% illuminated. NASA LRO lunar surface texture.');
    }

    function solarEvent(day, latitude, longitude, sunrise) {
        const n = Math.floor((Date.UTC(day.getUTCFullYear(),day.getUTCMonth(),day.getUTCDate()) - Date.UTC(day.getUTCFullYear(),0,0)) / 86400000);
        const lngHour = longitude / 15;
        const t = n + ((sunrise ? 6 : 18) - lngHour) / 24;
        const m = 0.9856 * t - 3.289;
        let l = m + 1.916 * Math.sin(m * Math.PI / 180) + 0.020 * Math.sin(2 * m * Math.PI / 180) + 282.634;
        l = (l % 360 + 360) % 360;
        let ra = Math.atan(0.91764 * Math.tan(l * Math.PI / 180)) * 180 / Math.PI;
        ra = (ra % 360 + 360) % 360;
        ra += Math.floor(l / 90) * 90 - Math.floor(ra / 90) * 90;
        ra /= 15;
        const sinDec = 0.39782 * Math.sin(l * Math.PI / 180);
        const cosDec = Math.cos(Math.asin(sinDec));
        const cosH = (Math.cos(90.833 * Math.PI / 180) - sinDec * Math.sin(latitude * Math.PI / 180)) / (cosDec * Math.cos(latitude * Math.PI / 180));
        if (cosH > 1 || cosH < -1) return null;
        const h = (sunrise ? 360 - Math.acos(cosH) * 180 / Math.PI : Math.acos(cosH) * 180 / Math.PI) / 15;
        const localMean = h + ra - 0.06571 * t - 6.622;
        let utcHour = (localMean - lngHour) % 24;
        if (utcHour < 0) utcHour += 24;
        return new Date(Date.UTC(day.getUTCFullYear(),day.getUTCMonth(),day.getUTCDate()) + utcHour * 3600000);
    }
    function timeLabel(date) {
        if (!date) return 'POLAR';
        try { return new Intl.DateTimeFormat('en-GB',{timeZone:zone,hour:'2-digit',minute:'2-digit',hour12:false}).format(date); }
        catch { return date.toISOString().slice(11,16) + ' UTC'; }
    }
    if (validLocation) {
        const localDateParts = new Intl.DateTimeFormat('en-CA',{timeZone:zone,year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(now).reduce((out,part) => (out[part.type] = part.value, out), {});
        const locationDay = new Date(Date.UTC(Number(localDateParts.year),Number(localDateParts.month)-1,Number(localDateParts.day)));
        const sunrise = solarEvent(locationDay,lat,lon,true);
        const sunset = solarEvent(locationDay,lat,lon,false);
        $('mthSunrise').textContent = timeLabel(sunrise);
        $('mthSunset').textContent = timeLabel(sunset);
        if (sunrise && sunset) {
            const noon = new Date((sunrise.getTime() + sunset.getTime()) / 2);
            const minutes = Math.round((sunset - sunrise) / 60000);
            $('mthSolarNoon').textContent = timeLabel(noon);
            $('mthDaylight').textContent = Math.floor(minutes/60) + 'H ' + String(minutes%60).padStart(2,'0') + 'M';
        } else {
            $('mthSolarNoon').textContent = '—';
            $('mthDaylight').textContent = 'POLAR DAY/NIGHT';
        }
        const marker = root.querySelector('.mth-location-marker');
        if (marker) {
            marker.style.left = (50 + (lon / 180) * 40).toFixed(2) + '%';
            marker.style.top = (50 - (lat / 90) * 40).toFixed(2) + '%';
            marker.setAttribute('aria-label','Selected location: ' + (root.dataset.locationName || 'GaugeIQ location') + ' (' + lat.toFixed(2) + ', ' + lon.toFixed(2) + ')');
        }
    } else {
        ['mthSunrise','mthSolarNoon','mthSunset','mthDaylight'].forEach(id => $(id).textContent = 'LOCATION NEEDED');
    }

    // Low-precision lunar orbital elements (Meeus/Schlyter-style) and
    // Greenwich mean sidereal time keep the Earth, Moon and location on one clock.
    const rad = Math.PI / 180;
    const norm360 = value => (value % 360 + 360) % 360;
    const orbitalElements = date => {
        const d = (date.getTime() - Date.UTC(2000,0,0,0,0,0)) / 86400000;
        const N = norm360(125.1228 - 0.0529538083 * d);
        const inclination = 5.1454;
        const w = norm360(318.0634 + 0.1643573223 * d);
        const a = 60.2666;
        const e = 0.0549;
        const M = norm360(115.3654 + 13.0649929509 * d);
        const E = M + (180 / Math.PI) * e * Math.sin(M * rad) * (1 + e * Math.cos(M * rad));
        const xv = a * (Math.cos(E * rad) - e);
        const yv = a * Math.sqrt(1 - e * e) * Math.sin(E * rad);
        const v = Math.atan2(yv, xv) / rad;
        const r = Math.sqrt(xv*xv + yv*yv);
        const xh = r * (Math.cos(N*rad)*Math.cos((v+w)*rad) - Math.sin(N*rad)*Math.sin((v+w)*rad)*Math.cos(inclination*rad));
        const yh = r * (Math.sin(N*rad)*Math.cos((v+w)*rad) + Math.cos(N*rad)*Math.sin((v+w)*rad)*Math.cos(inclination*rad));
        const zh = r * Math.sin((v+w)*rad) * Math.sin(inclination*rad);
        const eclLon = norm360(Math.atan2(yh,xh) / rad);
        const eclLat = Math.atan2(zh,Math.sqrt(xh*xh+yh*yh)) / rad;
        const obliq = 23.4393 - 3.563e-7 * d;
        const xe = xh;
        const ye = yh * Math.cos(obliq*rad) - zh * Math.sin(obliq*rad);
        const ze = yh * Math.sin(obliq*rad) + zh * Math.cos(obliq*rad);
        const ra = norm360(Math.atan2(ye,xe) / rad);
        const dec = Math.atan2(ze,Math.sqrt(xe*xe+ye*ye)) / rad;
        return {ra,dec,distanceKm:r*6378.14,eclipticLongitude:eclLon,eclipticLatitude:eclLat};
    };
    const solarCoordinates = date => {
        const d = (date.getTime() - Date.UTC(2000,0,0,0,0,0)) / 86400000;
        const w = 282.9404 + 4.70935e-5*d;
        const M = norm360(356.0470 + 0.9856002585*d);
        const L = norm360(w + M);
        const obliq = 23.4393 - 3.563e-7*d;
        const x = Math.cos(L*rad);
        const y = Math.cos(obliq*rad)*Math.sin(L*rad);
        const z = Math.sin(obliq*rad)*Math.sin(L*rad);
        return {ra:norm360(Math.atan2(y,x)/rad),dec:Math.atan2(z,Math.sqrt(x*x+y*y))/rad,longitude:L};
    };
    const gmstDegrees = date => {
        const jd = date.getTime()/86400000 + 2440587.5;
        const T = (jd - 2451545.0)/36525;
        return norm360(280.46061837 + 360.98564736629*(jd-2451545) + 0.000387933*T*T - T*T*T/38710000);
    };
    if (validLocation) {
        // Position angle of the lunar north pole, corrected by the local
        // parallactic angle. This gives the disk's actual apparent rotation
        // for the configured latitude, longitude and current observation time.
        const moon = orbitalElements(now);
        const phi = lat * rad;
        const dec = moon.dec * rad;
        const hourAngle = ((gmstDegrees(now) + lon - moon.ra + 540) % 360 - 180) * rad;
        const parallactic = Math.atan2(
            Math.sin(hourAngle),
            Math.tan(phi) * Math.cos(dec) - Math.sin(dec) * Math.cos(hourAngle)
        );
        const poleRa = 269.9949 * rad;
        const poleDec = 66.5392 * rad;
        const deltaRa = poleRa - moon.ra * rad;
        const polePositionAngle = Math.atan2(
            Math.cos(poleDec) * Math.sin(deltaRa),
            Math.sin(poleDec) * Math.cos(dec)
                - Math.cos(poleDec) * Math.sin(dec) * Math.cos(deltaRa)
        );
        lunarSurfaceRotation = polePositionAngle - parallactic;
    }
    const orbitMoon = $('mthOrbitMoon');
    const globe = root.querySelector('.mth-real-earth');
    const locationMarker = $('mthLocationMarker');
    const timeScrubber = $('mthTimeScrubber');
    const trackerDisplay = root.querySelector('.mth-tracker-display');
    const trackerSize = () => trackerDisplay ? Math.min(trackerDisplay.clientWidth,520) : 340;

    // WebGL 3D tracker: Earth is the origin; the camera sits above the selected
    // location. Sun and Moon are positioned from their calculated equatorial
    // coordinates, then projected into this local viewpoint.
    const trackerCanvas = $('mthTracker3D');
    let trackerGL = null;
    let trackerProgram = null;
    let trackerSphere = null;
    let trackerUniforms = null;
    function initTracker3D() {
        if (!trackerCanvas || trackerGL) return !!trackerGL;
        const gl = trackerCanvas.getContext('webgl', {alpha:true,antialias:true,premultipliedAlpha:false});
        if (!gl) return false;
        const vertex = `
            attribute vec3 a_position;
            uniform vec3 u_center;
            uniform float u_radius;
            uniform vec3 u_right;
            uniform vec3 u_up;
            uniform vec3 u_forward;
            uniform float u_aspect;
            varying vec3 v_normal;
            void main() {
                vec3 world = u_center + a_position * u_radius;
                vec3 view = vec3(dot(world,u_right),dot(world,u_up),dot(world,u_forward));
                float depth = max(0.25, 4.8 - view.z);
                float focal = 1.72;
                // Perspective-correct depth is essential: z=0 placed every
                // sphere on the same depth plane, hiding bodies drawn later.
                float clipZ = depth * 1.004008 - 0.200401;
                gl_Position = vec4(view.x*focal/u_aspect, view.y*focal, clipZ, depth);
                v_normal = a_position;
            }`;
        const fragment = `
            precision mediump float;
            varying vec3 v_normal;
            uniform vec3 u_light;
            uniform float u_kind;
            float terrain(vec3 p) {
                float n = sin(p.x*8.0 + sin(p.z*5.0))*0.42
                        + sin(p.y*11.0 + p.x*3.0)*0.28
                        + cos(p.z*13.0 - p.y*4.0)*0.20
                        + sin(p.x*19.0 + p.y*7.0 + p.z*11.0)*0.10;
                return n;
            }
            void main() {
                vec3 n = normalize(v_normal);
                float diffuse = max(0.0,dot(n,normalize(u_light)));
                float limb = 0.72 + 0.28*max(0.0,n.z);
                vec3 color;
                float light;
                if (u_kind < 0.5) {
                    float land = smoothstep(0.02,0.13,terrain(n*2.0));
                    vec3 ocean = vec3(0.025,0.20,0.34);
                    vec3 ground = vec3(0.30,0.43,0.27);
                    color = mix(ocean,ground,land);
                    light = 0.045 + 0.955*diffuse;
                    color *= light*limb;
                } else if (u_kind < 1.5) {
                    color = vec3(0.66,0.68,0.64);
                    light = 0.025 + 0.975*diffuse;
                    color *= light*limb;
                } else if (u_kind < 2.5) {
                    color = vec3(1.0,0.69,0.27);
                    color *= 1.1 + 0.25*diffuse;
                } else {
                    color = vec3(1.0,0.72,0.28);
                    color *= 1.35;
                }
                gl_FragColor = vec4(color,1.0);
            }`;
        const compile=(type,source)=>{const sh=gl.createShader(type);gl.shaderSource(sh,source);gl.compileShader(sh);if(!gl.getShaderParameter(sh,gl.COMPILE_STATUS))throw new Error(gl.getShaderInfoLog(sh));return sh;};
        const program=gl.createProgram();
        gl.attachShader(program,compile(gl.VERTEX_SHADER,vertex));
        gl.attachShader(program,compile(gl.FRAGMENT_SHADER,fragment));
        gl.linkProgram(program);
        if(!gl.getProgramParameter(program,gl.LINK_STATUS))throw new Error(gl.getProgramInfoLog(program));
        const positions=[],indices=[],latitudes=28,longitudes=40;
        for(let y=0;y<=latitudes;y++){const theta=y*Math.PI/latitudes;for(let x=0;x<=longitudes;x++){const phi=x*2*Math.PI/longitudes;positions.push(Math.sin(theta)*Math.cos(phi),Math.cos(theta),Math.sin(theta)*Math.sin(phi));}}
        // Counter-clockwise outward-facing triangles. The old winding
        // pointed inward, so BACK-face culling discarded the visible globe.
        for(let y=0;y<latitudes;y++)for(let x=0;x<longitudes;x++){const a=y*(longitudes+1)+x,b=a+longitudes+1;indices.push(a,a+1,b,b,a+1,b+1);}
        const vertexBuffer=gl.createBuffer();gl.bindBuffer(gl.ARRAY_BUFFER,vertexBuffer);gl.bufferData(gl.ARRAY_BUFFER,new Float32Array(positions),gl.STATIC_DRAW);
        const indexBuffer=gl.createBuffer();gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER,indexBuffer);gl.bufferData(gl.ELEMENT_ARRAY_BUFFER,new Uint16Array(indices),gl.STATIC_DRAW);
        trackerGL=gl;trackerProgram=program;trackerSphere={vertexBuffer,indexBuffer,count:indices.length};
        trackerUniforms={position:gl.getAttribLocation(program,'a_position'),center:gl.getUniformLocation(program,'u_center'),radius:gl.getUniformLocation(program,'u_radius'),right:gl.getUniformLocation(program,'u_right'),up:gl.getUniformLocation(program,'u_up'),forward:gl.getUniformLocation(program,'u_forward'),aspect:gl.getUniformLocation(program,'u_aspect'),light:gl.getUniformLocation(program,'u_light'),kind:gl.getUniformLocation(program,'u_kind')};
        return true;
    }
    function drawTrackerSphere(center,radius,kind,light,right,up,forward,aspect) {
        const gl=trackerGL,u=trackerUniforms;
        gl.uniform3fv(u.center,center);gl.uniform1f(u.radius,radius);gl.uniform1f(u.kind,kind);
        gl.uniform3fv(u.light,light);gl.uniform3fv(u.right,right);gl.uniform3fv(u.up,up);gl.uniform3fv(u.forward,forward);gl.uniform1f(u.aspect,aspect);
        gl.drawElements(gl.TRIANGLES,trackerSphere.count,gl.UNSIGNED_SHORT,0);
    }
    function renderTracker3D(date,moonPosition,sunPosition,gmst) {
        if(!trackerCanvas) return;
        if(!initTracker3D()) {
            trackerCanvas.dataset.renderState='webgl-unavailable';
            const state=$('mthOrbitState');
            if(state) state.textContent='3D RENDERER UNAVAILABLE';
            return;
        }
        trackerCanvas.dataset.renderState='webgl-active';
        const width=Math.max(1,trackerCanvas.clientWidth),height=Math.max(1,trackerCanvas.clientHeight),dpr=Math.min(2,window.devicePixelRatio||1);
        const pixelWidth=Math.round(width*dpr),pixelHeight=Math.round(height*dpr);
        if(trackerCanvas.width!==pixelWidth||trackerCanvas.height!==pixelHeight){trackerCanvas.width=pixelWidth;trackerCanvas.height=pixelHeight;}
        const gl=trackerGL;gl.viewport(0,0,pixelWidth,pixelHeight);gl.clearColor(0.012,0.035,0.065,0);gl.clear(gl.COLOR_BUFFER_BIT|gl.DEPTH_BUFFER_BIT);gl.enable(gl.DEPTH_TEST);gl.enable(gl.CULL_FACE);gl.useProgram(trackerProgram);
        gl.bindBuffer(gl.ARRAY_BUFFER,trackerSphere.vertexBuffer);gl.enableVertexAttribArray(trackerUniforms.position);gl.vertexAttribPointer(trackerUniforms.position,3,gl.FLOAT,false,0,0);gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER,trackerSphere.indexBuffer);
        const latRad=lat*rad,observerLon=(lon+gmst)*rad;
        const forward=[Math.cos(latRad)*Math.cos(observerLon),Math.cos(latRad)*Math.sin(observerLon),Math.sin(latRad)];
        let right=[-Math.sin(observerLon),Math.cos(observerLon),0];
        const up=[-Math.sin(latRad)*Math.cos(observerLon),-Math.sin(latRad)*Math.sin(observerLon),Math.cos(latRad)];
        const celestial=(ra,dec)=>[Math.cos(dec*rad)*Math.cos(ra*rad),Math.cos(dec*rad)*Math.sin(ra*rad),Math.sin(dec*rad)];
        const moonDir=celestial(moonPosition.ra,moonPosition.dec),sunDir=celestial(sunPosition.ra,sunPosition.dec);
        const aspect=pixelWidth/pixelHeight;
        gl.disable(gl.BLEND);
        drawTrackerSphere([0,0,0],1,0,sunDir,right,up,forward,aspect);
        // The gold marker is the observer's location on the near-side surface.
        gl.enable(gl.BLEND);gl.blendFunc(gl.SRC_ALPHA,gl.ONE_MINUS_SRC_ALPHA);
        drawTrackerSphere(forward.map(v=>v*1.012),0.025,3,sunDir,right,up,forward,aspect);
        // Plot Sun and Moon on the observer's local sky dome. Their positions
        // use true altitude/azimuth for this latitude, longitude and timestamp.
        // This avoids placing the midday Sun behind the Earth when viewed from
        // the selected location, while Earth shading still uses the real Sun vector.
        const skyPosition=(direction)=>{
            const east=direction[0]*right[0]+direction[1]*right[1]+direction[2]*right[2];
            const north=direction[0]*up[0]+direction[1]*up[1]+direction[2]*up[2];
            const altitude=Math.asin(Math.max(-1,Math.min(1,direction[0]*forward[0]+direction[1]*forward[1]+direction[2]*forward[2])));
            const horizontal=Math.cos(altitude);
            const azimuth=Math.atan2(east,north);
            return {center:[right[0]*(Math.sin(azimuth)*horizontal*1.45)+up[0]*(Math.sin(altitude)*1.45)+forward[0]*2.15,
                            right[1]*(Math.sin(azimuth)*horizontal*1.45)+up[1]*(Math.sin(altitude)*1.45)+forward[1]*2.15,
                            right[2]*(Math.sin(azimuth)*horizontal*1.45)+up[2]*(Math.sin(altitude)*1.45)+forward[2]*2.15],
                    altitude:altitude*180/Math.PI};
        };
        const moonSky=skyPosition(moonDir),sunSky=skyPosition(sunDir);
        if(moonSky.altitude>-1) drawTrackerSphere(moonSky.center,0.135,1,sunDir,right,up,forward,aspect);
        if(sunSky.altitude>-1) drawTrackerSphere(sunSky.center,0.19,2,sunDir,right,up,forward,aspect);
        gl.disable(gl.BLEND);
    }

    function updateEarthMoonTracker(date) {
        if (!globe || !orbitMoon || !date) return;
        const moonPosition = orbitalElements(date);
        const sunPosition = solarCoordinates(date);
        const gmst = gmstDegrees(date);
        renderTracker3D(date,moonPosition,sunPosition,gmst);
        const moonHourAngle = (moonPosition.ra - gmst) * rad;
        const moonX = Math.sin(moonHourAngle) * Math.cos(moonPosition.dec*rad);
        const moonY = -Math.sin(moonPosition.dec*rad);
        const moonZ = Math.cos(moonHourAngle) * Math.cos(moonPosition.dec*rad);
        // Keep the calculated Moon on the visible dotted orbit. The orbit
        // ring is a 248px circle centred in the 260px tracker system.
        const orbitRadius = 124;
        // Use the calculated sky direction but normalise its 2-D projection
        // so the Moon remains on the circumference of the displayed orbit.
        const moonScreenAngle = Math.atan2(moonY,moonX);
        orbitMoon.style.left = (130 + Math.cos(moonScreenAngle) * orbitRadius - 15) + 'px';
        orbitMoon.style.top = (130 + Math.sin(moonScreenAngle) * orbitRadius - 15) + 'px';
        orbitMoon.style.transform = 'none';
        orbitMoon.style.opacity = moonZ < -0.1 ? '.62' : '1';
        const phaseAngle = Math.acos(Math.max(-1,Math.min(1,
            Math.sin(moonPosition.dec*rad)*Math.sin(sunPosition.dec*rad) +
            Math.cos(moonPosition.dec*rad)*Math.cos(sunPosition.dec*rad)*Math.cos((moonPosition.ra-sunPosition.ra)*rad)
        )));
        const lit = (1 + Math.cos(Math.PI - phaseAngle))/2;
        orbitMoon.style.setProperty('--mth-moon-lit',String(lit));
        // The Sun is a fixed external light source in this diagram. Only
        // Earth's surface rotates and the Moon moves along its orbit.
        const axis = root.querySelector('.mth-tidal-axis');
        if (axis) axis.style.transform = 'translate(-50%,-50%) rotate(' + (Math.atan2(moonY,moonX)*180/Math.PI) + 'deg)';
        if (locationMarker && validLocation) {
            const delta = (lon-gmst)*rad;
            const markerX = Math.cos(lat*rad)*Math.sin(delta);
            const markerY = -Math.sin(lat*rad);
            const markerZ = Math.cos(lat*rad)*Math.cos(delta);
            const globeRadiusPercent = 46;
            locationMarker.style.left = (50 + markerX*globeRadiusPercent) + '%';
            locationMarker.style.top = (50 + markerY*globeRadiusPercent) + '%';
            locationMarker.style.opacity = markerZ > 0 ? '1' : '.22';
            locationMarker.style.zIndex = markerZ > 0 ? '5' : '0';
            locationMarker.title = (root.dataset.locationName || 'Selected location') + (markerZ > 0 ? ' · visible hemisphere' : ' · far side of Earth');
            const locationLabel = (root.dataset.locationName || 'LOCATION').toUpperCase();
            $('mthOrbitLocation').textContent = locationLabel + ' · ' + Math.abs(lat).toFixed(1) + (lat >= 0 ? 'N' : 'S') + ' ' + Math.abs(lon).toFixed(1) + (lon >= 0 ? 'E' : 'W');
        }
        $('mthOrbitDistance').textContent = Math.round(moonPosition.distanceKm).toLocaleString() + ' KM';
        $('mthOrbitPosition').textContent = norm360(moonPosition.eclipticLongitude).toFixed(1) + '° ECL.';
        const hourOffset = (date.getTime()-now.getTime())/3600000;
        $('mthOrbitTime').textContent = Math.abs(hourOffset)<0.01 ? 'NOW · ' + timeLabel(date) : (hourOffset>0?'+':'') + hourOffset.toFixed(2) + ' H · ' + timeLabel(date);
        $('mthOrbitState').textContent = 'CALCULATED POSITION';
        globe.style.setProperty('--mth-earth-turn',norm360(gmst)+'deg');
        // Keep the equirectangular texture's Greenwich meridian aligned with the
        // camera-facing meridian. The texture must move west as Earth rotates east.
        const textureWidth = 300;
        const globeViewportWidth = globe.clientWidth;
        const textureOffset = (globeViewportWidth / 2) - (norm360(gmst) / 360) * textureWidth;
        globe.style.backgroundPosition = textureOffset.toFixed(2) + 'px center';
        globe.setAttribute('aria-label','Rotating Earth. Moon range ' + Math.round(moonPosition.distanceKm).toLocaleString() + ' kilometres. ' + (root.dataset.locationName || 'Selected location') + ' marker updates with Earth rotation.');
    }
    if (timeScrubber) {
        timeScrubber.addEventListener('input',() => {
            const previewDate = new Date(now.getTime() + Number(timeScrubber.value)*3600000);
            updateEarthMoonTracker(previewDate);
        });
    }
    updateEarthMoonTracker(now);
    const liveTrackerTimer = window.setInterval(() => {
        if (timeScrubber && Number(timeScrubber.value) !== 0) return;
        updateEarthMoonTracker(new Date());
    },60000);

    function tideUrl(mode, start, end, stationId = null) {
        const params = new URLSearchParams({
            mode, latitude: String(lat), longitude: String(lon),
            start: start.toISOString(), end: end.toISOString(), units: 'meters'
        });
        if (stationId) params.set('station', stationId);
        return 'api/tides.php?' + params.toString();
    }
    async function fetchTidePayload(mode, start, end, stationId = null, timeoutMs = 30000) {
        // Scope cached data to the exact prediction window and station.
        const cacheKey = 'gaugeiq-mth-tides-v4:' + mode + ':' + lat.toFixed(3) + ':' + lon.toFixed(3) + ':' + start.toISOString() + ':' + end.toISOString() + ':' + (stationId || 'nearest');
        try {
            const cached = JSON.parse(sessionStorage.getItem(cacheKey) || 'null');
            if (cached && cached.savedAt && Date.now() - cached.savedAt < 15 * 60 * 1000) { tideLog(mode.toUpperCase() + ' CACHE HIT', 'age=' + Math.round((Date.now()-cached.savedAt)/1000) + 's'); return cached.payload; }
        } catch { /* Ignore unavailable or invalid session cache. */ }
        const controller = new AbortController();
        let timedOut = false;
        let timeoutId;
        // Race the whole request (including response parsing) against a hard
        // deadline. Some mobile browsers can leave an aborted fetch pending;
        // the independent rejection guarantees the HUD always leaves loading.
        tideLog(mode.toUpperCase() + ' REQUEST START', tideUrl(mode,start,end,stationId));
        const requestStartedAt = Date.now();
        const requestPromise = (async () => {
            const response = await fetch(tideUrl(mode, start, end, stationId), {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                signal: controller.signal
            });
            tideLog(mode.toUpperCase() + ' HTTP RESPONSE', 'status=' + response.status + '; elapsed=' + (Date.now()-requestStartedAt) + 'ms');
            let payload;
            try {
                payload = await response.json();
            } catch {
                throw new Error('Tide ' + mode + ' request returned an invalid JSON response (HTTP ' + response.status + ').');
            }
            if (!response.ok) {
                const details = Array.isArray(payload?.errors)
                    ? payload.errors.map(item => [item?.path, item?.message].filter(Boolean).join(': ')).filter(Boolean).join('; ')
                    : '';
                const detail = [payload?.message, details].filter(Boolean).join(' — ');
                throw new Error(detail || 'Tide ' + mode + ' request returned HTTP ' + response.status);
            }
            tideLog(mode.toUpperCase() + ' JSON PARSED', 'elapsed=' + (Date.now()-requestStartedAt) + 'ms; keys=' + Object.keys(payload || {}).join(','));
            return payload;
        })();
        const deadlinePromise = new Promise((resolve, reject) => {
            timeoutId = window.setTimeout(() => {
                timedOut = true;
                controller.abort();
                reject(new Error('Tide ' + mode + ' request timed out after ' + Math.round(timeoutMs / 1000) + ' seconds.'));
            }, timeoutMs);
        });
        try {
            const payload = await Promise.race([requestPromise, deadlinePromise]);
            try { sessionStorage.setItem(cacheKey, JSON.stringify({savedAt:Date.now(),payload})); } catch { /* Cache is optional. */ }
            return payload;
        } catch (error) {
            tideLog(mode.toUpperCase() + ' REQUEST FAILED', (Date.now()-requestStartedAt) + 'ms; ' + (error?.stack || error?.message || String(error)));
            if (timedOut) throw new Error('Tide ' + mode + ' request timed out after ' + Math.round(timeoutMs / 1000) + ' seconds.');
            throw error;
        } finally {
            window.clearTimeout(timeoutId);
        }
    }
    function tideLocalTime(timestamp, includeDay = true) {
        return new Intl.DateTimeFormat(undefined,{
            timeZone:zone,
            ...(includeDay ? {weekday:'short'} : {}),
            hour:'2-digit',minute:'2-digit',hour12:false
        }).format(new Date(timestamp));
    }
    function setGraphUnavailable(messageText) {
        tideLog('GRAPH UNAVAILABLE', messageText);
        const graph = $('mthTideGraph');
        const messageNode = $('mthGraphMessage');
        if (graph) graph.hidden = true;
        if (messageNode) {
            messageNode.hidden = false;
            messageNode.textContent = messageText;
        }
        const state = $('mthGraphState');
        if (state) {
            state.textContent = 'PREDICTION UNAVAILABLE';
            state.classList.add('mth-state-pending');
        }
        $('mthGraphCurrent').textContent = '—';
        $('mthGraphTrend').textContent = 'NO VERIFIED CURVE AVAILABLE';
        $('mthGraphStation').textContent = 'NO VERIFIED STATION DATA';
        $('mthGraphDistance').textContent = 'PREDICTION OFFLINE';
    }
    function hasUsableTideTimeline(payload) {
        if (!Array.isArray(payload?.timeline)) return false;
        const samples = payload.timeline.map(point => ({
            timestamp: Date.parse(point?.time),
            level: Number(point?.level)
        })).filter(point => Number.isFinite(point.timestamp) && Number.isFinite(point.level))
          .sort((a,b) => a.timestamp - b.timestamp);
        if (samples.length < 2) return false;
        return samples[samples.length - 1].timestamp - samples[0].timestamp >= 20 * 60 * 60 * 1000;
    }
    function drawTideGraph(payload, extremesPayload) {
        tideLog('GRAPH RENDER START', 'timelineSamples=' + (Array.isArray(payload?.timeline) ? payload.timeline.length : 'not-array') + '; extremes=' + (Array.isArray(extremesPayload?.extremes) ? extremesPayload.extremes.length : 0));
        const samples = Array.isArray(payload.timeline) ? payload.timeline.map(point => ({
            timestamp: Date.parse(point.time), level: Number(point.level)
        })).filter(point => Number.isFinite(point.timestamp) && Number.isFinite(point.level))
          .sort((a,b) => a.timestamp - b.timestamp) : [];
        if (samples.length < 2) throw new Error('The station did not return enough tide-height samples to draw a 24-hour graph.');
        tideLog('GRAPH SAMPLES VALID', 'count=' + samples.length + '; first=' + new Date(samples[0].timestamp).toISOString() + '; last=' + new Date(samples[samples.length-1].timestamp).toISOString());
        const startTime = samples[0].timestamp;
        const endTime = samples[samples.length - 1].timestamp;
        if (endTime - startTime < 20 * 60 * 60 * 1000) throw new Error('The tide provider returned less than 20 hours of prediction data.');
        const rawMin = Math.min(...samples.map(point => point.level));
        const rawMax = Math.max(...samples.map(point => point.level));
        const span = Math.max(0.1, rawMax - rawMin);
        const minLevel = rawMin - span * 0.12;
        const maxLevel = rawMax + span * 0.12;
        const x = timestamp => 54 + ((timestamp - startTime) / (endTime - startTime)) * 638;
        const y = level => 184 - ((level - minLevel) / (maxLevel - minLevel)) * 142;
        const path = samples.map((point,index) => (index ? 'L' : 'M') + x(point.timestamp).toFixed(2) + ' ' + y(point.level).toFixed(2)).join(' ');
        const area = path + ' L ' + x(endTime).toFixed(2) + ' 184 L ' + x(startTime).toFixed(2) + ' 184 Z';
        const grid = [0,1,2,3].map(i => {
            const level = maxLevel - (maxLevel - minLevel) * i / 3;
            const yy = 42 + i * 47.33;
            return '<line class="mth-graph-gridline" x1="54" y1="' + yy.toFixed(2) + '" x2="692" y2="' + yy.toFixed(2) + '"/>' +
                '<text class="mth-graph-axis-label" x="45" y="' + (yy + 3).toFixed(2) + '" text-anchor="end">' + level.toFixed(1) + '</text>';
        }).join('');
        const ticks = [0,6,12,18,24].map(hour => {
            const timestamp = startTime + (endTime - startTime) * hour / 24;
            const xx = x(timestamp);
            return '<line class="mth-graph-tick" x1="' + xx.toFixed(2) + '" y1="42" x2="' + xx.toFixed(2) + '" y2="190"/>' +
                '<text class="mth-graph-axis-label" x="' + xx.toFixed(2) + '" y="211" text-anchor="middle">' + tideLocalTime(timestamp,false) + '</text>';
        }).join('');
        const now = Date.now();
        const nowX = x(Math.max(startTime,Math.min(endTime,now)));
        const nearest = samples.reduce((best,point) => Math.abs(point.timestamp-now) < Math.abs(best.timestamp-now) ? point : best,samples[0]);
        const before = samples.filter(point => point.timestamp <= now).slice(-1)[0] || samples[0];
        const after = samples.find(point => point.timestamp > now) || samples[samples.length-1];
        const trend = after.level > before.level + 0.005 ? 'RISING' : (after.level < before.level - 0.005 ? 'FALLING' : 'NEAR SLACK');
        const extrema = Array.isArray(extremesPayload?.extremes) ? extremesPayload.extremes : [];
        const extremeMarks = extrema.map(event => {
            const timestamp = Date.parse(event.time);
            const level = Number(event.level);
            if (!Number.isFinite(timestamp) || !Number.isFinite(level) || timestamp < startTime || timestamp > endTime) return '';
            const isHigh = event.high === true || String(event.label || '').toLowerCase() === 'high';
            const label = isHigh ? 'H' : 'L';
            return '<circle class="mth-graph-extreme-dot" cx="' + x(timestamp).toFixed(2) + '" cy="' + y(level).toFixed(2) + '" r="4"/>' +
                '<text class="mth-graph-extreme-label" x="' + x(timestamp).toFixed(2) + '" y="' + (y(level) + (isHigh ? -10 : 17)).toFixed(2) + '" text-anchor="middle">' + label + '</text>';
        }).join('');
        const graph = $('mthTideGraph');
        tideLog('GRAPH SVG BUILD', 'pathLength=' + path.length + '; points=' + samples.length);
        graph.innerHTML = '<defs><linearGradient id="mthTideArea" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#72e5e0" stop-opacity=".28"/><stop offset="1" stop-color="#72e5e0" stop-opacity=".015"/></linearGradient></defs>' +
            grid + ticks + '<path class="mth-graph-area" d="' + area + '"/><path class="mth-graph-line" d="' + path + '"/>' +
            '<line class="mth-graph-now-line" x1="' + nowX.toFixed(2) + '" y1="32" x2="' + nowX.toFixed(2) + '" y2="190"/>' +
            '<text class="mth-graph-now-label" x="' + Math.max(65,Math.min(681,nowX)).toFixed(2) + '" y="25" text-anchor="middle">NOW</text>' +
            extremeMarks;
        graph.hidden = false;
        // Some mobile Safari/iOS stylesheet states keep this SVG at display:none
        // even after the hidden attribute is removed. Apply the visible layout
        // directly to the rendered graph so the valid prediction is never blank.
        graph.style.setProperty('display', 'block', 'important');
        graph.style.setProperty('width', '100%');
        graph.style.setProperty('height', 'auto');
        graph.style.setProperty('min-height', '195px');
        tideLog('GRAPH SVG INSERTED', 'hidden=' + graph.hidden + '; children=' + graph.childElementCount + '; viewBox=' + graph.getAttribute('viewBox'));
        const graphMessage = $('mthGraphMessage');
        if (graphMessage) {
            graphMessage.hidden = true;
            // The loading element has display:grid in its class rule, which can
            // override the browser's built-in [hidden] styling. Force it closed.
            graphMessage.style.setProperty('display', 'none', 'important');
        }
        $('mthGraphCurrent').textContent = nearest.level.toFixed(2) + ' M';
        tideLog('CURRENT HEIGHT SET', $('mthGraphCurrent').textContent);
        $('mthGraphTrend').textContent = trend + ' · ' + tideLocalTime(now,false) + ' LOCAL';
        $('mthGraphWindow').textContent = 'NEXT 24 HOURS';
        tideLog('FORECAST WINDOW SET', $('mthGraphWindow').textContent);
        $('mthGraphDatum').textContent = (payload.datum || 'STATION DATUM').toString().toUpperCase();
        const station = payload.station || {};
        $('mthGraphStation').textContent = (station.name || 'VERIFIED TIDE STATION').toString().toUpperCase();
        const distance = Number(payload.distance);
        $('mthGraphDistance').textContent = Number.isFinite(distance) ? distance.toFixed(1) + ' KM FROM LOCATION' : 'NEAREST PREDICTION STATION';
        const state = $('mthGraphState');
        state.textContent = 'LIVE PREDICTION';
        state.classList.remove('mth-state-pending');
        const windowNode = $('mthGraphWindow');
        const graphWrap = $('mthTideGraphWrap');
        const graphStyle = window.getComputedStyle(graph);
        const windowStyle = window.getComputedStyle(windowNode);
        const graphRect = graph.getBoundingClientRect();
        const windowRect = windowNode.getBoundingClientRect();
        const wrapRect = graphWrap.getBoundingClientRect();
        let pathBounds = 'unavailable';
        try {
            const lineNode = graph.querySelector('.mth-graph-line');
            const bounds = lineNode && lineNode.getBBox();
            if (bounds) pathBounds = [bounds.x,bounds.y,bounds.width,bounds.height].map(value => Number(value.toFixed(1))).join(',');
        } catch (error) { pathBounds = 'getBBox failed: ' + error.message; }
        tideLog('RENDER GEOMETRY', 'graph=' + Math.round(graphRect.width) + 'x' + Math.round(graphRect.height) + '; wrap=' + Math.round(wrapRect.width) + 'x' + Math.round(wrapRect.height) + '; display=' + graphStyle.display + '; inlineDisplay=' + graph.style.getPropertyValue('display') + '; inlinePriority=' + graph.style.getPropertyPriority('display') + '; visibility=' + graphStyle.visibility + '; opacity=' + graphStyle.opacity + '; color=' + graphStyle.color + '; pathBounds=' + pathBounds);
        tideLog('WINDOW GEOMETRY', 'text=' + windowNode.textContent + '; rect=' + Math.round(windowRect.width) + 'x' + Math.round(windowRect.height) + '; display=' + windowStyle.display + '; visibility=' + windowStyle.visibility + '; opacity=' + windowStyle.opacity + '; color=' + windowStyle.color + '; fontSize=' + windowStyle.fontSize);
        tideLog('PARENT GEOMETRY', 'readout=' + Math.round($('mthGraphCurrent').parentElement.parentElement.getBoundingClientRect().width) + 'px; section=' + Math.round(root.querySelector('.mth-tide-graph-section').getBoundingClientRect().width) + 'px');
        tideLog('GRAPH RENDER COMPLETE', 'state=' + state.textContent + '; graphHidden=' + graph.hidden + '; window=' + $('mthGraphWindow').textContent);
    }
    async function loadTidePredictions() {
        const status = $('mthTideStatus');
        const message = $('mthTideMessage');
        if (!validLocation) {
            status.textContent = 'LOCATION COORDINATES UNAVAILABLE';
            message.textContent = 'Set valid coordinates for the selected GaugeIQ location to search for a nearby tide station.';
            setGraphUnavailable('Set valid coordinates for this GaugeIQ location to load a local tide profile.');
            return;
        }
        tideLog('TIDE LOAD START');
        const start = new Date();
        const end = new Date(start.getTime() + 24 * 60 * 60 * 1000);
        const extremesEnd = new Date(start.getTime() + 4 * 86400000);

        // Start both documented coordinate-based requests together. The timeline
        // must not wait for the high/low response before it can even begin loading.
        // Both responses are still checked against the same resolved station before
        // any curve is shown.
        const graphMessage = $('mthGraphMessage');
        if (graphMessage) graphMessage.textContent = 'Connecting to the tide prediction service…';
        const extremesRequest = fetchTidePayload('extremes',start,extremesEnd, null,15000);
        const timelineRequest = fetchTidePayload('timeline',start,end,null,15000);

        const extremesUi = extremesRequest.then(extremesPayload => {
            const station = extremesPayload.station || {};
            const distance = Number(extremesPayload.distance);
            if (!station.name || !Array.isArray(extremesPayload.extremes)) {
                status.textContent = 'INCOMPLETE STATION DATA';
                message.textContent = 'The tide provider returned an incomplete station prediction.';
                $('mthTideStation').textContent = 'INCOMPLETE STATION RESPONSE';
                $('mthTideDistance').textContent = 'VERIFICATION FAILED';
                return;
            }
            if (Number.isFinite(distance) && distance > 150) {
                status.textContent = 'NO SUITABLE COASTAL STATION';
                message.textContent = 'The nearest prediction station is ' + Math.round(distance) + ' km away. Distant predictions are not presented as local.';
                $('mthTideStation').textContent = 'STATION OUTSIDE LOCAL RANGE';
                $('mthTideDistance').textContent = Math.round(distance) + ' KM AWAY';
                return;
            }
            const events = extremesPayload.extremes.map(event => ({...event,timestamp:Date.parse(event.time)}))
                .filter(event => Number.isFinite(event.timestamp) && event.timestamp >= Date.now() - 60000)
                .sort((a,b) => a.timestamp - b.timestamp);
            const nextHigh = events.find(event => event.high === true || String(event.label || '').toLowerCase() === 'high');
            const nextLow = events.find(event => event.low === true || String(event.label || '').toLowerCase() === 'low');
            if (!nextHigh && !nextLow) {
                status.textContent = 'NO UPCOMING TIDE EVENTS';
                message.textContent = 'The station did not return upcoming high or low water events.';
                $('mthTideStation').textContent = station.name.toUpperCase();
                $('mthTideDistance').textContent = Number.isFinite(distance) ? distance.toFixed(1) + ' KM FROM LOCATION' : 'NEAREST STATION';
                return;
            }
            const height = event => Number.isFinite(Number(event.level)) ? Number(event.level).toFixed(2) + ' m' : '—';
            $('mthNextHigh').textContent = nextHigh ? height(nextHigh) : '—';
            $('mthNextHighTime').textContent = nextHigh ? tideLocalTime(nextHigh.timestamp) : 'NOT AVAILABLE';
            $('mthNextLow').textContent = nextLow ? height(nextLow) : '—';
            $('mthNextLowTime').textContent = nextLow ? tideLocalTime(nextLow.timestamp) : 'NOT AVAILABLE';
            status.textContent = 'HARMONIC PREDICTIONS AVAILABLE';
            message.textContent = 'Upcoming high and low water from the nearest supported prediction station. Heights use that station’s chart datum.';
            $('mthTideStation').textContent = station.name.toUpperCase();
            $('mthTideDistance').textContent = Number.isFinite(distance) ? distance.toFixed(1) + ' KM FROM LOCATION' : 'NEAREST STATION';
            $('mthTideAttribution').textContent = (station.attribution ? String(station.attribution) : 'Source: Open Waters / Slackwater harmonic tide predictions') + ' · Predictions do not account for weather-driven surge. Not for navigation.';
            root.querySelector('.mth-tides .mth-state-pending')?.classList.remove('mth-state-pending');
            const tideState = root.querySelector('.mth-tides .mth-section-state');
            if (tideState && tideState.textContent.includes('DATA LINK')) tideState.textContent = 'STATION LINKED';
        }).catch(error => {
            tideLog('EXTREMES HANDLER FAILED', error?.stack || error?.message || String(error));
            status.textContent = 'TIDE DATA UNAVAILABLE';
            message.textContent = 'Nearby high/low tide events could not be loaded. ' + (error?.message || 'Please try again later.');
            $('mthTideStation').textContent = 'NO VERIFIED STATION DATA';
            $('mthTideDistance').textContent = 'PREDICTION OFFLINE';
        });

        // The 24-hour curve is an independent product of the timeline endpoint.
        // Never hold it behind the separate extremes/high-low request: that
        // endpoint may fail even when the timeline is valid.
        let resolvedTimelinePayload = null;
        const timelineUi = timelineRequest.then(timelinePayload => {
            resolvedTimelinePayload = timelinePayload;
            tideLog('TIMELINE PAYLOAD RECEIVED', 'samples=' + (Array.isArray(timelinePayload?.timeline) ? timelinePayload.timeline.length : 'not-array') + '; station=' + (timelinePayload?.station?.name || 'none') + '; distance=' + timelinePayload?.distance);
            try { drawTideGraph(timelinePayload, null); } catch (error) { tideLog('GRAPH RENDER EXCEPTION', error?.stack || error?.message || String(error)); throw error; }
        }).catch(error => {
            tideLog('TIMELINE HANDLER FAILED', error?.stack || error?.message || String(error));
            setGraphUnavailable('The 24-hour tide timeline could not be loaded. ' + (error?.message || 'Please try again later.'));
        });

        // Add high/low markers if the second request succeeds, without making
        // its success a prerequisite for displaying the tide curve.
        extremesRequest.then(extremesPayload => {
            if (resolvedTimelinePayload) { tideLog('EXTREMES ARRIVED; ADDING MARKERS'); try { drawTideGraph(resolvedTimelinePayload, extremesPayload); } catch (error) { tideLog('MARKER RENDER EXCEPTION', error?.stack || error?.message || String(error)); } }
        }).catch(() => { /* High/low status is reported by extremesUi. */ });

        await Promise.all([extremesUi,timelineUi]);
    }
    loadTidePredictions().catch(error => {
        tideLog('TOP-LEVEL TIDE FAILURE', error?.stack || error?.message || String(error));
        const status = $('mthTideStatus');
        const message = $('mthTideMessage');
        if (status) status.textContent = 'TIDE DATA UNAVAILABLE';
        if (message) message.textContent = 'A verified nearby prediction could not be loaded. ' + (error && error.message ? error.message : 'Please try again later.');
        $('mthTideStation').textContent = 'NO VERIFIED STATION DATA';
        $('mthTideDistance').textContent = 'PREDICTION OFFLINE';
        setGraphUnavailable('A verified local tide curve could not be loaded. ' + (error && error.message ? error.message : 'Please try again later.'));
    });

    const nowText = new Intl.DateTimeFormat(undefined,{timeZone:zone,day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit',hour12:false});
    root.querySelector('.mth-live').setAttribute('aria-label','Astronomical calculations updated ' + nowText.format(now));
})();
