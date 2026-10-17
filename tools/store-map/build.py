"""Generuje inc/modules/store-map-data.php z danych OpenStreetMap (granica: Nominatim, trasy: OSRM).
Uruchom: python3 tools/store-map/build.py"""
import json, math, os
D = os.path.dirname(__file__)
city = json.load(open(os.path.join(D, 'wroclaw-granica.json')))
LON0, LON1, LAT0, LAT1 = 16.795, 17.19, 51.025, 51.218
K = math.cos(math.radians(51.11))
W = 1000
H = round(W * (LAT1 - LAT0) / ((LON1 - LON0) * K))
def P(lon, lat):
    return (round((lon - LON0) * K / ((LON1 - LON0) * K) * W, 1), round((LAT1 - lat) / (LAT1 - LAT0) * H, 1))
def path(pts, close=False):
    s = ' '.join(('M' if i == 0 else 'L') + '%g %g' % P(*p) for i, p in enumerate(pts))
    return s + (' Z' if close else '')
STORE = (16.9372, 51.0710)
routes = {
 'rynek': dict(label='Z Rynku', place='Rynek', at=(17.0320, 51.1100), m=10098, s=1098,
   c=[[17.032,51.11],[17.0297,51.103],[17.0241,51.1039],[17.0233,51.1037],[17.0222,51.1047],[16.9792,51.0946],[16.9783,51.0939],[16.9755,51.0878],[16.9632,51.0773],[16.9568,51.0788],[16.9438,51.0748],[16.9397,51.0725],[16.9378,51.0702],[16.937,51.0704],[16.9374,51.0709]]),
 'lotnisko': dict(label='Z lotniska', place='Lotnisko', at=(16.8858, 51.1027), m=10744, s=806,
   c=[[16.8858,51.1027],[16.8858,51.1068],[16.8887,51.1111],[16.9017,51.1107],[16.9047,51.1091],[16.9102,51.1084],[16.9249,51.1096],[16.9372,51.1092],[16.9361,51.1049],[16.934,51.1033],[16.9273,51.0786],[16.9272,51.0732],[16.9288,51.0665],[16.9343,51.0685],[16.9361,51.0682],[16.9378,51.0702],[16.937,51.0704],[16.9374,51.0709]]),
 'stadion': dict(label='Ze stadionu', place='Stadion', at=(16.9443, 51.1414), m=10868, s=690,
   c=[[16.9441,51.1427],[16.9418,51.141],[16.9396,51.1395],[16.9356,51.1384],[16.9328,51.134],[16.9344,51.1317],[16.9318,51.1218],[16.9341,51.1042],[16.9273,51.0786],[16.9288,51.0665],[16.9343,51.0685],[16.9361,51.0682],[16.9374,51.0709]]),
 'a4': dict(label='Z A4 (Bielany)', place='Bielany', at=(16.9887, 51.0445), m=10232, s=831,
   c=[[16.9887,51.0445],[16.9916,51.0432],[16.9863,51.0387],[16.9828,51.0364],[16.969,51.0395],[16.9705,51.0449],[16.9707,51.0474],[16.9739,51.0502],[16.9774,51.0493],[16.9726,51.0495],[16.962,51.0505],[16.9558,51.0498],[16.9454,51.0475],[16.9395,51.0463],[16.9353,51.0472],[16.9335,51.0494],[16.9293,51.0661],[16.9338,51.0684],[16.9361,51.0682],[16.9378,51.0702],[16.937,51.0704],[16.9374,51.0709]]),
}
a8 = [[16.9372,51.1600],[16.9356,51.1384],[16.9339,51.13],[16.9318,51.1221],[16.934,51.1033],[16.9273,51.0786],[16.9293,51.0661],[16.9335,51.0494],[16.9300,51.0300]]
a4 = [[16.8900,51.0420],[16.9353,51.0472],[16.9454,51.0475],[16.9558,51.0498],[16.962,51.0505],[16.9726,51.0495],[17.0000,51.0440],[17.0500,51.0330]]
def km(a, b):
    return math.hypot((a[0]-b[0])*111.32*K, (a[1]-b[1])*111.32)
edge = min(km(STORE, p) for p in city)
out = {'w': W, 'h': H, 'city': path(city, True), 'a8': path(a8), 'a4': path(a4), 'store': P(*STORE),
       'city_label': P(17.065, 51.158), 'edge_km': round(edge, 1), 'a8_label': P(16.9345, 51.150), 'a4_label': P(17.03, 51.0385),
       'km2px': W / ((LON1 - LON0) * K * 111.32), 'routes': {}}
xs, ys = [], []
for r in routes.values():
    for c in r['c']:
        x, y = P(*c); xs.append(x); ys.append(y)
pad = 60
out['focus'] = '%g %g %g %g' % (min(xs) - pad, min(ys) - pad - 40, max(xs) - min(xs) + 2 * pad + 60, max(ys) - min(ys) + 2 * pad + 110)
for k, r in routes.items():
    out['routes'][k] = {'label': r['label'], 'place': r['place'], 'at': P(*r['at']), 'd': path(r['c']),
                        'km': round(r['m'] / 1000, 1), 'min': int(5 * math.ceil(r['s'] / 60 / 5))}
php = "<?php\n/* Wygenerowane przez tools/store-map/build.py z danych © OpenStreetMap (Nominatim, OSRM). Nie edytuj ręcznie. */\ndefined( 'ABSPATH' ) || exit;\nreturn json_decode( '" + json.dumps(out, ensure_ascii=False).replace("'", "\\'") + "', true );\n"
open(os.path.join(D, '..', '..', 'inc', 'modules', 'store-map-data.php'), 'w').write(php)
print(W, H, 'edge km', round(edge, 2), {k: (v['km'], v['min']) for k, v in out['routes'].items()})
