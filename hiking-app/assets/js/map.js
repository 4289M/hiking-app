/* =====================================================================
 * assets/js/map.js — ตัวช่วย Leaflet + OpenStreetMap
 *   HikeMap.create(el, options)
 *   map.setPosition(lat, lon, accuracy)  แสดงตำแหน่งปัจจุบัน + วงความแม่นยำ
 *   map.addTrackPoint(segment, lat, lon) ต่อเส้นทาง (แยกเส้นตาม segment)
 *   map.setStart / setEnd               Marker จุดเริ่ม/จุดสิ้นสุด
 *   map.renderHistory(points)           วาดเส้นทางย้อนหลังจาก API (คลิกเส้นเพื่อดูเวลา/พิกัด)
 * ===================================================================== */
(function () {
    'use strict';

    const DEFAULT_CENTER = [13.7563, 100.5018]; // กรุงเทพฯ (ใช้ก่อนได้ตำแหน่ง GPS)
    const TRACK_STYLE = { color: '#1E4634', weight: 5, opacity: 0.95, lineCap: 'round', lineJoin: 'round' };

    function haversine(lat1, lon1, lat2, lon2) {
        const R = 6371008.8;
        const toRad = (d) => d * Math.PI / 180;
        const dLat = toRad(lat2 - lat1);
        const dLon = toRad(lon2 - lon1);
        const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
        return 2 * R * Math.asin(Math.min(1, Math.sqrt(a)));
    }

    function dotIcon(cls, size) {
        return L.divIcon({ className: '', html: '<div class="' + cls + '"></div>', iconSize: [size, size], iconAnchor: [size / 2, size / 2] });
    }

    function fmtTime(ms) {
        return new Date(ms).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
    }

    class HikeMap {
        constructor(el, { zoomControl = true, center = DEFAULT_CENTER, zoom = 6, bottomInset = null } = {}) {
            if (!window.L) throw new Error('โหลด Leaflet ไม่สำเร็จ');
            this.map = L.map(el, { zoomControl: false, preferCanvas: true }).setView(center, zoom);
            if (zoomControl) L.control.zoom({ position: 'topright' }).addTo(this.map);
            L.control.scale({ imperial: false, position: 'bottomleft' }).addTo(this.map);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            }).addTo(this.map);

            this.segments = new Map(); // segment → L.polyline
            this.posMarker = null;
            this.accCircle = null;
            this.startMarker = null;
            this.endMarker = null;
            this.follow = true;
            this.hasFix = false;
            this.historyPoints = [];
            this.popup = L.popup({ autoPan: true });
            // ความสูง (px) ของแผงที่บังด้านล่างแผนที่ — ใช้เลื่อนจุดให้อยู่ในส่วนที่มองเห็น
            this.bottomInset = typeof bottomInset === 'function' ? bottomInset : () => 0;

            // ผู้ใช้ลากแผนที่เอง → หยุดเลื่อนตามตำแหน่ง
            this.map.on('dragstart', () => this.setFollow(false));
        }

        static create(el, opts) { return new HikeMap(el, opts); }

        setFollow(on) {
            this.follow = on;
            this.map.fire('followchange', { follow: on });
        }

        /** จุดศูนย์กลางที่ชดเชยแผงด้านล่าง เพื่อให้ตำแหน่งอยู่กึ่งกลางพื้นที่ที่มองเห็น */
        _visibleCenter(ll, zoom) {
            const z = zoom === undefined ? this.map.getZoom() : zoom;
            const inset = Math.max(0, this.bottomInset() || 0);
            if (!inset) return L.latLng(ll);
            return this.map.unproject(this.map.project(L.latLng(ll), z).add([0, inset / 2]), z);
        }

        setPosition(lat, lon, accuracy) {
            const ll = [lat, lon];
            if (!this.posMarker) {
                this.accCircle = L.circle(ll, { radius: accuracy, color: '#2563EB', weight: 1, opacity: 0.4, fillOpacity: 0.12, interactive: false }).addTo(this.map);
                this.posMarker = L.marker(ll, { icon: dotIcon('pos-marker', 18), keyboard: false, zIndexOffset: 1000, title: 'ตำแหน่งปัจจุบัน' }).addTo(this.map);
            } else {
                this.posMarker.setLatLng(ll);
                this.accCircle.setLatLng(ll).setRadius(accuracy);
            }
            if (!this.hasFix) {
                this.hasFix = true;
                this.map.setView(this._visibleCenter(ll, 17), 17);
            } else if (this.follow) {
                this.map.panTo(this._visibleCenter(ll), { animate: true });
            }
        }

        locate() {
            if (!this.posMarker) return false;
            this.setFollow(true);
            const z = Math.max(this.map.getZoom(), 16);
            this.map.setView(this._visibleCenter(this.posMarker.getLatLng(), z), z);
            return true;
        }

        _segmentLine(segment) {
            let line = this.segments.get(segment);
            if (!line) {
                line = L.polyline([], TRACK_STYLE).addTo(this.map);
                this.segments.set(segment, line);
            }
            return line;
        }

        addTrackPoint(segment, lat, lon) {
            this._segmentLine(segment).addLatLng([lat, lon]);
        }

        /** วาดเส้นทางทั้งหมดใหม่ (ใช้ตอนกู้คืนกิจกรรม) — segments: { [seg]: [[lat,lon],...] } */
        setTrack(segments) {
            this.segments.forEach((l) => l.remove());
            this.segments.clear();
            Object.keys(segments).forEach((seg) => {
                const line = this._segmentLine(Number(seg));
                line.setLatLngs(segments[seg]);
            });
        }

        setStart(lat, lon) {
            if (this.startMarker) this.startMarker.setLatLng([lat, lon]);
            else this.startMarker = L.marker([lat, lon], { icon: dotIcon('start-marker', 22), title: 'จุดเริ่มต้น' }).bindTooltip('จุดเริ่มต้น').addTo(this.map);
        }

        setEnd(lat, lon) {
            if (this.endMarker) this.endMarker.setLatLng([lat, lon]);
            else this.endMarker = L.marker([lat, lon], { icon: dotIcon('end-marker', 22), title: 'จุดสิ้นสุด' }).bindTooltip('จุดสิ้นสุด').addTo(this.map);
        }

        clearTrack() {
            this.segments.forEach((l) => l.remove());
            this.segments.clear();
            if (this.startMarker) { this.startMarker.remove(); this.startMarker = null; }
            if (this.endMarker) { this.endMarker.remove(); this.endMarker = null; }
        }

        zoomIn() { this.map.zoomIn(); }
        zoomOut() { this.map.zoomOut(); }

        /**
         * เส้นทางย้อนหลัง
         * points: [[seq, segment, lat, lon, alt, acc, t_ms], ...] (รูปแบบจาก api/hike-detail.php)
         */
        renderHistory(points) {
            this.clearTrack();
            this.historyPoints = points;
            if (!points.length) return false;

            const bySeg = {};
            points.forEach((p) => { (bySeg[p[1]] = bySeg[p[1]] || []).push([p[2], p[3]]); });
            this.setTrack(bySeg);
            this.segments.forEach((line) => {
                line.on('click', (ev) => this.showNearest(ev.latlng));
            });

            const first = points[0];
            const last = points[points.length - 1];
            this.setStart(first[2], first[3]);
            this.setEnd(last[2], last[3]);

            const bounds = L.latLngBounds(points.map((p) => [p[2], p[3]]));
            this.map.fitBounds(bounds, { padding: [30, 30], maxZoom: 17 });
            return true;
        }

        /** หาจุดที่ใกล้ตำแหน่งที่คลิกที่สุด แล้วแสดง popup เวลา/พิกัด */
        showNearest(latlng) {
            let best = null;
            let bestD = Infinity;
            for (const p of this.historyPoints) {
                const d = haversine(latlng.lat, latlng.lng, p[2], p[3]);
                if (d < bestD) { bestD = d; best = p; }
            }
            if (best) this.showPoint(best);
        }

        showPointBySeq(seq) {
            const p = this.historyPoints.find((x) => x[0] === seq);
            if (p) {
                this.showPoint(p);
                this.map.setView([p[2], p[3]], Math.max(this.map.getZoom(), 16));
            }
            return !!p;
        }

        showPoint(p) {
            const div = document.createElement('div');
            const title = document.createElement('strong');
            title.textContent = 'จุดที่ ' + p[0] + ' · ' + fmtTime(p[6]);
            const line1 = document.createElement('div');
            line1.textContent = p[2].toFixed(6) + ', ' + p[3].toFixed(6);
            const line2 = document.createElement('div');
            line2.textContent = 'ความแม่นยำ ±' + Math.round(p[5]) + ' ม.' + (p[4] !== null ? ' · สูง ' + Math.round(p[4]) + ' ม.' : '');
            div.append(title, line1, line2);
            this.popup.setLatLng([p[2], p[3]]).setContent(div).openOn(this.map);
        }

        invalidate() { this.map.invalidateSize(); }
    }

    window.HikeMap = HikeMap;
    window.GeoUtil = { haversine };
})();
