(() => {
  const card=document.getElementById('surfReportCard'); if(!card) return;
  const spots={
    pollock:{name:'Pollock Beach',lat:-33.982,lon:25.662,face:115},
    hobie:{name:'Hobie Beach',lat:-33.984,lon:25.657,face:115},
    sardinia:{name:'Sardinia Bay',lat:-34.038,lon:25.535,face:145},
    capeStFrancis:{name:'Cape St Francis',lat:-34.205,lon:24.833,face:105},
    jeffreys:{name:'Jeffreys Bay',lat:-34.052,lon:24.931,face:95},
    muizenberg:{name:'Muizenberg',lat:-34.109,lon:18.468,face:165}
  };
  const el=id=>document.getElementById(id), select=el('surfSpotSelect');
  const compass=d=>['N','NE','E','SE','S','SW','W','NW'][Math.round((((Number(d)||0)%360)+360)%360/45)%8];
  const degDiff=(a,b)=>Math.abs(((a-b+540)%360)-180);
  const relation=(wind,face)=>{if(!Number.isFinite(wind))return 'Wind direction unavailable';const off=degDiff(wind,(face+180)%360);return off<55?'Offshore':off>125?'Onshore':'Cross-shore';};
  const rating=(h,p,w,rel)=>{let score=0;if(h>=.5&&h<=2.2)score+=2;else if(h>2.2&&h<3.2)score+=1;else if(h<.5)score-=2;else score-=1;if(p>=9)score+=2;else if(p>=7)score+=1;if(w<12)score+=2;else if(w<20)score+=1;else score-=2;if(rel==='Offshore')score+=2;else if(rel==='Onshore')score-=2;return score>=6?'Excellent':score>=4?'Good':score>=2?'Fair':score>=0?'Poor':'Flat';};
  const symbol={Flat:'—',Poor:'×',Fair:'△',Good:'✓',Excellent:'★'};
  const set=(id,v)=>{const n=el(id);if(n)n.textContent=v;};
  async function load(){
    const spot=spots[select.value]||spots.pollock;set('surfReportStatus','Fetching marine forecast for '+spot.name+'…');el('surfReportSummary').hidden=true;el('surfHourly').innerHTML='';el('surfSevenDay').innerHTML='';
    const vars='wave_height,wave_direction,wave_period,wind_wave_height,wind_wave_direction,wind_wave_period,swell_wave_height,swell_wave_direction,swell_wave_period,sea_surface_temperature';
    const marineUrl='https://marine-api.open-meteo.com/v1/marine?latitude='+spot.lat+'&longitude='+spot.lon+'&hourly='+vars+'&forecast_days=7&timezone=auto';
    const weatherUrl='https://api.open-meteo.com/v1/forecast?latitude='+spot.lat+'&longitude='+spot.lon+'&hourly=wind_speed_10m,wind_direction_10m&forecast_days=7&timezone=auto';
    try{
      const [marineResponse,weatherResponse]=await Promise.all([fetch(marineUrl),fetch(weatherUrl)]);if(!marineResponse.ok)throw new Error('Marine forecast request failed ('+marineResponse.status+')');if(!weatherResponse.ok)throw new Error('Wind forecast unavailable');
      const [raw,weatherRaw]=await Promise.all([marineResponse.text(),weatherResponse.text()]);const data=JSON.parse(raw),weather=JSON.parse(weatherRaw);const h=data.hourly,wh=weather.hourly;if(!h?.time?.length)throw new Error('No marine forecast returned for this location');
      set('surfDataUsage',((new TextEncoder().encode(raw).length+new TextEncoder().encode(weatherRaw).length)/1024).toFixed(1)+' KB');
      const now=new Date(),start=h.time.findIndex(t=>new Date(t)>=now);const ix=Math.max(0,start);
      const wave=h.wave_height[ix],period=h.wave_period[ix],swell=h.swell_wave_height[ix],swellDir=h.swell_wave_direction[ix],sea=h.sea_surface_temperature?.[ix];
      const hourlyWind=wh.wind_speed_10m;
      const rel=relation(wh.wind_direction_10m?.[ix],spot.face);const grade=rating(Number(wave),Number(period),Number(hourlyWind?.[ix]),rel);
      set('surfRating',symbol[grade]);set('surfRatingLabel',grade+' conditions');set('surfWaveHeight',Number.isFinite(wave)?wave.toFixed(1)+' m':'—');set('surfWavePeriod',Number.isFinite(period)?period.toFixed(0)+' s period':'—');
      set('surfSwellHeight',Number.isFinite(swell)?swell.toFixed(1)+' m':'—');set('surfSwellDirection',Number.isFinite(swellDir)?compass(swellDir)+' · '+Math.round(swellDir)+'°':'—');
      set('surfWindSpeed',Number.isFinite(hourlyWind?.[ix])?Math.round(hourlyWind[ix])+' km/h':'—');set('surfWindRelation',rel+(Number.isFinite(hourlyWind?.[ix])?' · '+compass(wh.wind_direction_10m?.[ix])+' wind':''));
      set('surfSeaTemp',Number.isFinite(sea)?sea.toFixed(1)+' °C':'—');set('surfTide','Tide data not included');el('surfReportSummary').hidden=false;
      // Find today's best 3-hour block using the same simple suitability score.
      let best=-1,bestIndex=ix;for(let j=ix;j<Math.min(ix+18,h.time.length-2);j++){let avgH=(h.wave_height[j]+h.wave_height[j+1]+h.wave_height[j+2])/3,avgP=(h.wave_period[j]+h.wave_period[j+1]+h.wave_period[j+2])/3,avgW=(hourlyWind?.[j]+hourlyWind?.[j+1]+hourlyWind?.[j+2])/3,rr=relation(wh.wind_direction_10m?.[j],spot.face);let sc=rating(avgH,avgP,avgW,rr);let score=['Flat','Poor','Fair','Good','Excellent'].indexOf(sc);if(score>best){best=score;bestIndex=j;}}
      const bestTime=new Date(h.time[bestIndex]);set('surfBestTime','Best window ~ '+bestTime.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}));
      const hours=[];for(let j=ix;j<Math.min(ix+12,h.time.length);j+=3){let dt=new Date(h.time[j]);hours.push('<div class="surf-hour"><small>'+dt.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})+'</small><strong>'+Number(h.wave_height[j]).toFixed(1)+'m</strong><span>'+Math.round(wh.wind_speed_10m?.[j]||0)+' km/h</span></div>');}el('surfHourly').innerHTML='<div class="surf-block-title">NEXT 24 HOURS · WAVE / WIND</div><div class="surf-hour-grid">'+hours.join('')+'</div>';
      const days={};h.time.forEach((t,j)=>{const d=new Date(t);const key=d.toLocaleDateString();(days[key]||(days[key]={date:d,w:[],p:[],s:[],v:[]}));days[key].w.push(Number(h.wave_height[j]));days[key].p.push(Number(h.wave_period[j]));days[key].s.push(Number(h.swell_wave_height[j]));days[key].v.push(Number(wh.wind_speed_10m?.[j]));});
      el('surfSevenDay').innerHTML='<div class="surf-block-title">7-DAY OUTLOOK</div><div class="surf-day-grid">'+Object.values(days).slice(0,7).map(d=>{const avg=a=>a.filter(Number.isFinite).reduce((sum,v)=>sum+v,0)/(a.filter(Number.isFinite).length||1);return '<div class="surf-day"><small>'+d.date.toLocaleDateString([], {weekday:'short'})+'</small><strong>'+avg(d.w).toFixed(1)+' m</strong><span>'+avg(d.p).toFixed(0)+' s · swell '+avg(d.s).toFixed(1)+' m</span><em>'+Math.round(avg(d.v))+' km/h wind</em></div>';}).join('')+'</div>';
      set('surfReportStatus',spot.name+' · forecast refreshed '+new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}));
    }catch(e){set('surfReportStatus',e.message||'Surf forecast unavailable');set('surfDataUsage','—');}
  }
  select.addEventListener('change',load);load();
})();