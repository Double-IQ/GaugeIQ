<?php
declare(strict_types=1);

/**
 * Hourly, server-owned snapshot for the complete Moon & Tide card.
 * The browser consumes this snapshot; it never calls the tide provider itself.
 */
final class MoonTideSnapshotService
{
    public function __construct(private PDO $pdo, private array $config) {}

    private function setting(string $key, string $fallback = ''): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $fallback : (string)$value;
    }

    private function save(string $key, string $value): void
    {
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?) ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`'
            : 'INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$key, $value]);
    }

    private static function norm(float $value): float { return fmod(fmod($value, 360.0) + 360.0, 360.0); }

    private function astronomy(DateTimeImmutable $at, float $lat, float $lon, DateTimeZone $zone): array
    {
        $ts = $at->getTimestamp();
        $jd = $ts / 86400 + 2440587.5;
        $T = ($jd - 2451545.0) / 36525;
        $rad = M_PI / 180;
        $L = self::norm(280.46646 + $T * (36000.76983 + 0.0003032 * $T));
        $M = self::norm(357.52911 + $T * (35999.05029 - 0.0001537 * $T)) * $rad;
        $C = (1.914602 - $T * (0.004817 + 0.000014 * $T)) * sin($M)
            + (0.019993 - 0.000101 * $T) * sin(2 * $M) + 0.000289 * sin(3 * $M);
        $omega = (125.04 - 1934.136 * $T) * $rad;
        $lambda = ($L + $C - 0.00569 - 0.00478 * sin($omega)) * $rad;
        $eps = (23.439291 - 0.0130042 * $T - 0.000000164 * $T * $T + 0.000000504 * $T ** 3 + 0.00256 * cos($omega)) * $rad;
        $sx = cos($lambda); $sy = cos($eps) * sin($lambda); $sz = sin($eps) * sin($lambda);
        $sunRa = self::norm(atan2($sy, $sx) / $rad);
        $sunDec = atan2($sz, hypot($sx, $sy)) / $rad;

        $d = ($ts - gmmktime(0, 0, 0, 1, 0, 2000)) / 86400;
        $N = self::norm(125.1228 - 0.0529538083 * $d);
        $w = self::norm(318.0634 + 0.1643573223 * $d);
        $Mm = self::norm(115.3654 + 13.0649929509 * $d);
        $E = $Mm + (180 / M_PI) * 0.0549 * sin($Mm * $rad) * (1 + 0.0549 * cos($Mm * $rad));
        $xv = 60.2666 * (cos($E * $rad) - 0.0549);
        $yv = 60.2666 * sqrt(1 - 0.0549 ** 2) * sin($E * $rad);
        $v = atan2($yv, $xv) / $rad; $r = hypot($xv, $yv);
        $xh = $r * (cos($N*$rad)*cos(($v+$w)*$rad) - sin($N*$rad)*sin(($v+$w)*$rad)*cos(5.1454*$rad));
        $yh = $r * (sin($N*$rad)*cos(($v+$w)*$rad) + cos($N*$rad)*sin(($v+$w)*$rad)*cos(5.1454*$rad));
        $zh = $r * sin(($v+$w)*$rad) * sin(5.1454*$rad);
        $obl = (23.4393 - 3.563e-7 * $d) * $rad;
        $xe = $xh; $ye = $yh*cos($obl)-$zh*sin($obl); $ze = $yh*sin($obl)+$zh*cos($obl);
        $moonRa = self::norm(atan2($ye,$xe)/$rad);
        $moonDec = atan2($ze,hypot($xe,$ye))/$rad;
        $moonLon = self::norm(atan2($yh,$xh)/$rad);
        $moonLat = asin(max(-1.0,min(1.0,$zh/$r))) / $rad;
        $gmst = self::norm(280.46061837 + 360.98564736629*($jd-2451545.0) + 0.000387933*(($jd-2451545.0)/36525)**2 - ((($jd-2451545.0)/36525)**3)/38710000);

        $synodic = 29.530588853;
        $cycle = fmod(fmod(($ts - gmmktime(18,14,0,1,6,2000))/86400, $synodic)+$synodic, $synodic);
        $phase = $cycle/$synodic;
        $illum = (1-cos(2*M_PI*$phase))/2;
        $names = ['NEW MOON','WAXING CRESCENT','FIRST QUARTER','WAXING GIBBOUS','FULL MOON','WANING GIBBOUS','LAST QUARTER','WANING CRESCENT'];
        $nextFullDays = fmod(0.5-$phase+1,1)*$synodic;
        $anomaly = 2*M_PI*(($cycle-1.0)/27.55455);
        $moonDistance = 385001-20905*cos($anomaly);

        $day = $at->setTimezone($zone)->setTime(0,0);
        $solarEvent = static function (DateTimeImmutable $day, float $latitude, float $longitude, bool $sunrise): ?DateTimeImmutable {
            $n = (int)$day->format('z') + 1; $lngHour=$longitude/15;
            $t=$n+(($sunrise?6:18)-$lngHour)/24; $m=0.9856*$t-3.289;
            $l=fmod($m+1.916*sin(deg2rad($m))+0.020*sin(2*deg2rad($m))+282.634+3600,360);
            $ra=rad2deg(atan(0.91764*tan(deg2rad($l)))); $ra=fmod($ra+360,360);
            $ra+=floor($l/90)*90-floor($ra/90)*90; $ra/=15;
            $sinDec=0.39782*sin(deg2rad($l)); $cosDec=cos(asin($sinDec));
            $cosH=(cos(deg2rad(90.833))-$sinDec*sin(deg2rad($latitude)))/($cosDec*cos(deg2rad($latitude)));
            if ($cosH>1 || $cosH < -1) return null;
            $h=($sunrise?360-rad2deg(acos($cosH)):rad2deg(acos($cosH)))/15;
            $utc=(($h+$ra-0.06571*$t-6.622)-$lngHour);
            $base=gmmktime(0,0,0,(int)$day->format('n'),(int)$day->format('j'),(int)$day->format('Y'));
            return (new DateTimeImmutable('@'.(string)($base+$utc*3600)))->setTimezone(new DateTimeZone('UTC'));
        };
        $rise=$solarEvent($day,$lat,$lon,true); $set=$solarEvent($day,$lat,$lon,false);
        $noon=($rise && $set) ? (new DateTimeImmutable('@'.(string)(int)(($rise->getTimestamp()+$set->getTimestamp())/2)))->setTimezone(new DateTimeZone('UTC')) : null;
        $localFmt=static fn(?DateTimeImmutable $v): string => $v ? $v->setTimezone($zone)->format('H:i') : 'POLAR';
        return [
            'sun'=>['ra'=>$sunRa,'dec'=>$sunDec,'longitude'=>self::norm($lambda/$rad)],
            'moon'=>['ra'=>$moonRa,'dec'=>$moonDec,'distanceKm'=>$r*6378.14,'eclipticLongitude'=>$moonLon,'eclipticLatitude'=>atan2($zh,hypot($xh,$yh))/$rad],
            'gmst'=>$gmst,'phase'=>$phase,'illumination'=>$illum,'phaseName'=>$names[(int)round($phase*8)%8],
            'lunarAgeDays'=>$cycle,'moonDistanceKm'=>$moonDistance,'nextFullMoonAt'=>$at->modify('+'.(int)round($nextFullDays*86400).' seconds')->format(DATE_ATOM),
            'sunrise'=>$localFmt($rise),'sunset'=>$localFmt($set),'solarNoon'=>$localFmt($noon),
            'daylightMinutes'=>($rise && $set) ? (int)round(($set->getTimestamp()-$rise->getTimestamp())/60) : null,
        ];
    }

    private function tideRequest(string $url): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('cURL is unavailable for hourly tide refresh.');
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_USERAGENT=>'GaugeIQ/1.0']);
        $body=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $error=curl_error($ch); curl_close($ch);
        if ($body===false || $status<200 || $status>=300) throw new RuntimeException('Tide provider HTTP '.$status.($error!==''?': '.$error:''));
        $data=json_decode((string)$body,true);
        if (!is_array($data)) throw new RuntimeException('Tide provider returned invalid JSON.');
        return $data;
    }

    private function tides(float $lat,float $lon,DateTimeImmutable $at): array
    {
        $start=$at->setTimezone(new DateTimeZone('UTC'));
        $end=$start->modify('+24 hours'); $extEnd=$start->modify('+4 days');
        $base='https://api.openwaters.io/tides/';
        $params=['start'=>$start->format('Y-m-d\TH:i:s\Z'),'end'=>$extEnd->format('Y-m-d\TH:i:s\Z'),'units'=>'meters','latitude'=>$lat,'longitude'=>$lon];
        $ext=$this->tideRequest($base.'extremes?'.http_build_query($params));
        $station=$ext['station']??[]; $id=(string)($station['id']??'');
        if ($id==='' || !preg_match('~^([a-z0-9_-]+)/([a-z0-9._@-]+)$~i',$id,$m)) {
            return ['extremes'=>$ext,'timeline'=>null,'error'=>'No verified nearby tide station was returned.'];
        }
        $timelineParams=['start'=>$start->format('Y-m-d\TH:i:s\Z'),'end'=>$end->format('Y-m-d\TH:i:s\Z'),'units'=>'meters'];
        $timeline=$this->tideRequest($base.'stations/'.rawurlencode($m[1]).'/'.rawurlencode($m[2]).'/timeline?'.http_build_query($timelineParams));
        return ['extremes'=>$ext,'timeline'=>$timeline,'error'=>null];
    }

    public function refreshIfDue(int $locationId, int $maxAgeSeconds = 3600): array
    {
        if (!in_array($locationId, [1, 2], true)) {
            throw new InvalidArgumentException('Invalid location ID.');
        }
        $key = 'moon_tide_snapshot_location_' . $locationId;
        $existing = $this->setting($key);
        if ($existing !== '') {
            $snapshot = json_decode($existing, true);
            $generatedAt = is_array($snapshot) ? strtotime((string)($snapshot['generatedAt'] ?? '')) : false;
            if ($generatedAt !== false && (time() - $generatedAt) < $maxAgeSeconds) {
                return $snapshot;
            }
        }
        return $this->refresh($locationId);
    }

    public function refresh(int $locationId): array
    {
        if (!in_array($locationId,[1,2],true)) throw new InvalidArgumentException('Invalid location ID.');
        $prefix=$locationId===2?'second_location_':'location_';
        $fallbackLat=(string)($this->config['pressure']['latitude']??'');
        $fallbackLon=(string)($this->config['pressure']['longitude']??'');
        $lat=(float)($locationId===1
            ? $this->setting('active_location_latitude',$this->setting('location_latitude',$fallbackLat))
            : $this->setting('second_location_latitude',''));
        $lon=(float)($locationId===1
            ? $this->setting('active_location_longitude',$this->setting('location_longitude',$fallbackLon))
            : $this->setting('second_location_longitude',''));
        if (!is_finite($lat)||!is_finite($lon)||abs($lat)>90||abs($lon)>180||($lat===0.0&&$lon===0.0)) throw new RuntimeException('Location '.$locationId.' has no valid coordinates.');
        $zoneName=$this->setting($locationId===2?'location_timezone_location_2':'location_timezone',$this->setting('active_location_timezone',(string)($this->config['app']['timezone']??'UTC')));
        try{$zone=new DateTimeZone($zoneName);}catch(Throwable){$zone=new DateTimeZone('UTC');}
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $payload=['locationId'=>$locationId,'locationName'=>$this->setting($locationId===1?'active_location_name':'second_location_name',$this->setting($locationId===1?'location_name':'second_location_name',$locationId===1?(string)($this->config['pressure']['location_name']??'Location 1'):'Location 2')),'generatedAt'=>$now->format(DATE_ATOM),'timezone'=>$zone->getName(),'latitude'=>$lat,'longitude'=>$lon,'astronomy'=>$this->astronomy($now,$lat,$lon,$zone)];
        $payload['tides']=$this->tides($lat,$lon,$now);
        $this->save('moon_tide_snapshot_location_'.$locationId,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $this->save('moon_tide_snapshot_location_'.$locationId.'_updated_at',$now->format(DATE_ATOM));
        return $payload;
    }
}
