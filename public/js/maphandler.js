class Spotmap {
    constructor(options) {
        if (!options.maps) {
            console.error("Missing important options!!");
        }
        this.options = options;
        this.mapcenter = {};
        this.debug("Spotmap obj created.");
        this.debug(this.options);
        this.map = {};
        this.points = [];
        this.speedUnit = 'kt';
        this.distanceUnit = 'nm';
        this.tempUnit = 'C';
        this.layerControl = L.control.layers({}, {}, {
            hideSingleBase: true
        });
        this.layers = {
            feeds: {},
            gpx: {},
        };
        this.totalDistance = 0;
        this.totalSeconds = 0;
        this.totalMovingSeconds = 0;
        this.sailboat = null;
        this.ruler = {};
    }

    doesFeedExists(feedName) {
        return this.layers.feeds.hasOwnProperty(feedName)
    }
    initMap() {
        jQuery('#' + this.options.mapId).height(this.options.height);
        var self = this;

        let oldOptions = jQuery('#' + this.options.mapId).data('options');
        jQuery('#' + this.options.mapId).data('options', this.options);
        var container = L.DomUtil.get(this.options.mapId);
        if (container != null) {
            if (!lodash.isEqual(this.options, oldOptions)) {
                // https://github.com/Leaflet/Leaflet/issues/3962
                container._leaflet_id = null;
                jQuery('#' + this.options.mapId + " > .leaflet-control-container").empty();
                jQuery('#' + this.options.mapId + " > .leaflet-pane").empty();
            } else {
                return 0;
            }
        }

        var mapOptions = {
            fullscreenControl: true,
            scrollWheelZoom: false,
            attributionControl: false,
        };
        this.map = L.map(this.options.mapId, mapOptions);
        L.control.scale({ imperial: false }).addTo(this.map);
        // use no prefix in attribution
        L.control.attribution({
            prefix: ''
        }).addTo(this.map);
        // enable scrolling with mouse once the map was focused
        this.map.once('focus', function () {
            self.map.scrollWheelZoom.enable();
        });

        var info = L.control({
            position: 'bottomright'
        });
        info.onAdd = function (map) {
            this._div = L.DomUtil.create('div', 'info');
            return this._div;
        };

        info.update = function (props) {
            const contents = Object.entries(props).map(value => `<b>${value[0]}</b>: ${value[1]}`).join('<br/>');
            // this._div.innerHTML = `<h4>Under Sail</h4>${contents}`;
            this._div.innerHTML = `${contents}`;
        };
        info.addTo(this.map);

        self.getOption('maps');
        this.addButtons();

        var rulerOptions = {
            position: 'bottomleft',
            lengthUnit: {
                factor: 0.539956803, //  from km to nm
                display: 'Nautical Miles',
                speedDisplay: 'kt',
                decimal: 2,
                label: 'Distance:'
            },
            timeSpeed: {
                values: [4, 5],
                label: 'Time',
            },
            circleMarker: { // Leaflet circle marker options for points used in this plugin
                color: 'black',
                radius: 1
            },
            lineStyle: { // Leaflet polyline options for lines used in this plugin
                color: 'black',
                dashArray: '1,6'
            },
        };
        this.ruler = L.control.ruler(rulerOptions).addTo(this.map);

        // define obj to post data
        let body = {
            'action': 'get_positions',
            'select': "*",
            'feeds': '',
            'date-range': this.options.dateRange,
            'date': this.options.date,
            'orderBy': 'feed_name, time',
            'groupBy': '',
        }
        if (this.options.feeds) {
            body.feeds = this.options.feeds;
        }
        self.layerControl.addTo(self.map);
        this.getPoints(function (response) {
            // this is the case if explicitly no feeds were provided
            if (!response.empty) {
                self.points = response;
                // loop thru the data received from server
                response.forEach(function (entry, index) {
                    this.addPoint(entry, index);
                    this.addPointToLine(entry);
                }, self);
                let icon = L.icon({
                    iconUrl: '/wp-content/uploads/2023/12/sailboat.png',
                    iconSize: [36, 36],
                    iconAnchor: [18, 25],
                });

                let lastPoint = self.points[self.points.length - 1];
                self.sailboat = L.marker(L.latLng(lastPoint.latitude, lastPoint.longitude), { interactive: false, icon: icon }).addTo(self.map);
                self.ruler.setHomePoint({lat: +lastPoint.latitude, lng: +lastPoint.longitude});

            }
            if (self.options.gpx) {

                for (var i = 0; i < self.options.gpx.length; i++) {
                    let entry = self.options.gpx[i];
                    let title = self.options.gpx[i].title;
                    let color = self.getOption('color', {
                        gpx: entry
                    });
                    let gpxOption = {
                        async: true,
                        marker_options: {
                            wptIcons: {
                                '': self.getMarkerIcon({
                                    color: color
                                }),
                            },
                            wptIconsType: {
                                '': self.getMarkerIcon({
                                    color: color
                                }),
                            },
                            startIconUrl: '',
                            endIconUrl: '',
                            shadowUrl: spotmapjsobj.url + 'leaflet-gpx/pin-shadow.png',
                        },
                        polyline_options: {
                            'color': color,
                        }
                    }

                    let track = new L.GPX(entry.url, gpxOption).on('loaded', function (e) {
                        // if last track
                        if (self.options.mapcenter == 'gpx' || response.empty) {
                            self.setBounds('gpx');
                        }
                    }).on('addline', function (e) {
                        e.line.bindPopup(title);
                    });
                    let html = ' ' + self.getColorDot(color);
                    self.layers.gpx[title] = {
                        featureGroup: L.featureGroup([track])
                    };
                    self.layers.gpx[title].featureGroup.addTo(self.map)
                    self.layerControl.addOverlay(self.layers.gpx[title].featureGroup, title + html);

                }
                self.calculateTrip();
                info.update({ 'Distance': self.formatDistance(self.totalDistance), 'Moving': self.formatDuration(self.totalMovingSeconds), 'Elapsed': self.formatDuration(self.totalSeconds) })
            }
            // add feeds to layercontrol
            lodash.forEach(self.layers.feeds, function (value, key) {
                self.layers.feeds[key].featureGroup.addTo(self.map);

                if (self.layers.feeds.length + self.options.gpx.length == 1) {
                    self.layerControl.addOverlay(self.layers.feeds[key].featureGroup, key);
                } else {
                    let color = self.getOption('color', {
                        'feed': key
                    })
                    let label = key + ' ' + self.getColorDot(color)
                    // if last element and overlays exists
                    // label += '<div class="leaflet-control-layers-separator"></div>'
                    self.layerControl.addOverlay(self.layers.feeds[key].featureGroup, label)
                }
            });
            if (response.empty && self.options.gpx.length == 0) {
                self.map.setView([51.505, -0.09], 13)
                var popup = L.popup()
                    .setLatLng([51.513, -0.09])
                    .setContent("There is nothing to show here yet.")
                    .openOn(self.map);
            } else {
                self.setBounds(self.options.mapcenter);
            }

            // TODO merge displayOverlays
            self.getOption('mapOverlays');

            // if (Object.keys(displayOverlays).length == 1) {
            // displayOverlays[Object.keys(displayOverlays)[0]].addTo(self.map);
            // if (Object.keys(baseLayers).length > 1)
            // L.control.layers(baseLayers,{},{hideSingleBase: true}).addTo(self.map);
            // } else {
            // L.control.layers(baseLayers, displayOverlays,{hideSingleBase: true}).addTo(self.map);
            // self.layerControl.addOverlay(self.layers.feeds[key].featureGroup, label)
            // }
            // self.map.on('baselayerchange', self.onBaseLayerChange(event));

            if (self.options.autoReload == true && !response.empty) {
                var refresh = setInterval(function () {
                    body.groupBy = 'feed_name';
                    body.orderBy = 'time DESC';
                    self.getPoints(function (response) {
                        if (response.error) {
                            return;
                        }
                        response.forEach(function (entry, index) {
                            let feedName = entry.feed_name;
                            let lastPoint = lodash.last(self.layers.feeds[feedName].points)
                            if (lastPoint.unixtime < entry.unixtime) {
                                self.debug("Found a new point for Feed: " + feedName);
                                self.addPoint(entry, self.points.length + index);
                                self.addPointToLine(entry);
                                self.points.push(entry);
                                self.calculateTrip();

                                if (self.options.mapcenter == 'last') {
                                    self.map.setView([entry.latitude, entry.longitude], 14);
                                }
                                if (self.sailboat) {
                                    self.sailboat.setLatLng(L.latLng(entry.latitude, entry.longitude));
                                }
                            }
                        });


                    }, {
                        body: body,
                        filter: self.options.filterPoints
                    });
                }, 30000);
            }
        }, {
            body: body,
            filter: this.options.filterPoints
        });
    }

    getOption(option, config) {
        if (!config) {
            config = {};
        }
        if (option == 'maps') {
            if (this.options.maps) {
                let firstmap = true;
                for (let mapName in this.options.maps) {
                    mapName = this.options.maps[mapName];
                    let layer;
                    if (lodash.keys(spotmapjsobj.maps).includes(mapName)) {
                        let map = spotmapjsobj.maps[mapName];
                        if (map.wms) {
                            layer = L.tileLayer.wms(map.url, map.options);
                        } else {
                            layer = L.tileLayer(map.url, map.options);
                        }
                        this.layerControl.addBaseLayer(layer, map.label);
                    }
                    // if (this.options.maps.includes('swisstopo')) {
                    //     layer = L.tileLayer.swiss()
                    //     this.layerControl.addBaseLayer(layer, 'swissTopo');
                    //     L.Control.Layers.prototype._checkDisabledLayers = function () { };
                    // }
                    if (firstmap && layer) {
                        firstmap = false;
                        layer.addTo(this.map);
                    }

                }
                // if (lodash.startsWith(this.options.maps[0], "swiss") && self.map.options.crs.code == "EPSG:3857") {
                //     self.changeCRS(L.CRS.EPSG2056)
                //     self.map.setZoom(zoom + 7)
                // }
            }
            return;
        }


        if (option == 'mapOverlays') {

            if (this.options.mapOverlays) {
                for (let overlayName in this.options.mapOverlays) {
                    overlayName = this.options.mapOverlays[overlayName];
                    let layer;
                    if (lodash.keys(spotmapjsobj.overlays).includes(overlayName)) {
                        let overlay = spotmapjsobj.overlays[overlayName];
                        if (overlay.wms) {
                            layer = L.tileLayer.wms(overlay.url, overlay.options);
                        } else {
                            layer = L.tileLayer(overlay.url, overlay.options);
                        }
                        if (overlay.enabled) layer.addTo(this.map);
                        this.layerControl.addOverlay(layer, overlay.label);
                    }

                }
            }
        }
        if (option == 'color' && config.feed) {
            if (this.options.styles[config.feed] && this.options.styles[config.feed].color)
                return this.options.styles[config.feed].color;
            return 'blue';
        }
        if (option == 'color' && config.gpx) {
            if (config.gpx.color)
                return config.gpx.color;
            return 'gold';
        }
        if (option == 'lastPoint') {
            if (this.options.lastPoint)
                return this.options.lastPoint;
            return false;
        }
        if (option == 'feeds') {
            if (this.options.feeds || this.options.feeds.length == 0)
                return false;
            return this.options.feeds;
        }

        if (option == 'splitLines' && config.feed) {
            if (this.options.styles[config.feed] && this.options.styles[config.feed].splitLinesEnabled && this.options.styles[config.feed].splitLinesEnabled === false)
                return false;
            if (this.options.styles[config.feed] && this.options.styles[config.feed].splitLines)
                return this.options.styles[config.feed].splitLines;
            return false;
        }
    }
    debug(message) {
        if (this.options && this.options.debug)
            console.log(message)
    }

    getPoints(callback, options) {
        var self = this;
        jQuery.post(spotmapjsobj.ajaxUrl, options.body, function (response) {
            let feeds = true;
            if (self.options.feeds && self.options.feeds.length == 0) {
                feeds = false
            }
            if (feeds && (response.error || response == 0)) {
                self.debug("There was an error in the response");
                self.debug(response);
                self.map.setView([51.505, -0.09], 13);
                response = response.error ? response : {};
                response.title = response.title || "No data found!";
                response.message = response.message || "";
                if (self.options.gpx.length == 0) {
                    var popup = L.popup()
                        .setLatLng([51.5, 0])
                        .setContent("<b>" + response.title + "</b><br>" + response.message)
                        .openOn(self.map);
                    self.map.setView([51.5, 0], 13);
                }
            } else if (feeds && options.filter && !response.empty) {
                response = self.removeClosePoints(response, options.filter);
                callback(response);
            } else {
                callback(response);
            }
        });
    }

    removeClosePoints(points, radius) {
        points = lodash.eachRight(points, function (element, index) {
            // if we spliced the array, or check the last element, do nothing
            if (!element || index == 0)
                return
            let nextPoint,
                indexesToBeDeleted = [];
            for (let i = index - 1; i > 0; i--) {
                nextPoint = [points[i].latitude, points[i].longitude];
                let dif = L.latLng(element.latitude, element.longitude).distanceTo(nextPoint);
                if (dif <= radius && element.type == points[i].type) {
                    indexesToBeDeleted.push(i);
                    continue;
                }
                if (indexesToBeDeleted.length != 0) {
                    points[index].hiddenPoints = {
                        count: indexesToBeDeleted.length,
                        radius: radius
                    };
                }
                break;
            }
            lodash.each(indexesToBeDeleted, function (index) {
                points[index] = undefined;
            });
        });
        // completely remove the entries from the points
        points = points.filter(function (element) {
            return element !== undefined;
        });
        return points;
    }

    addButtons() {
        // zoom to bounds btn 
        var self = this;
        let zoomOptions = {
            duration: 2
        };
        let last = L.easyButton({
            states: [{
                stateName: 'all',
                icon: 'fa-globe',
                title: 'Show all points',
                onClick: function (control) {
                    self.setBounds('all');
                    control.state('last');
                },
            }, {
                stateName: 'last',
                icon: 'fa-map-pin',
                title: 'Jump to last known location',
                onClick: function (control) {
                    self.setBounds('last');
                    if (!lodash.isEmpty(self.options.gpx))
                        control.state('gpx');
                    else
                        control.state('all');
                },
            }, {
                stateName: 'gpx',
                icon: '<span class="target">Tr.</span>',
                title: 'Show GPX track(s)',
                onClick: function (control) {
                    self.setBounds('gpx');
                    control.state('all');
                },
            }]
        });
        //   the users position
        let position = L.easyButton({
            states: [{
                icon: 'fa-location-arrow',
                title: 'Jump to your location',
                onClick: function () {
                    self.map.locate({
                        setView: true,
                        maxZoom: 15
                    });
                },
            }]
        });
        let strava = L.easyButton({
            states: [{
                icon: 'fa-bicycle',
                title: 'Show Strava Tracks',
                onClick: function () {
                    self.loadStrava();
                },
            }],
        });
        // add all btns to map
        L.easyBar([last, position, strava]).addTo(this.map);
    }
    loadStrava() {
        const stravaApiUrl = 'https://www.strava.com/api/v3/activities/10248558791';
        const accessToken = 'fc7dd583e5c7031e3498c5ebfddeed019ee56837';
        fetch(stravaApiUrl, {
            headers: {
                Authorization: `Bearer ${accessToken}`,
            },
        })
            .then(response => response.json())
            .then(activity => {
                if (activity && activity.map && activity.map.summary_polyline) {
                    const decodedPolyline = L.Polyline.fromEncoded(activity.map.summary_polyline).getLatLngs();
                    const polylineLayer = L.polyline(decodedPolyline, {
                        color: 'blue'
                    }).addTo(this.map);
                    this.map.fitBounds(polylineLayer.getBounds());
                }
            })
            .catch(error => console.error('Error fetching Strava data:', error));
    }
    getColorDot(color) {
        return '<span class="dot" style="position: relative;height: 10px;width: 10px;background-color: ' + color + ';border-radius: 50%;display: inline-block;"></span>'
    }
    formatSpeed(speed1, speed2 = null, decimals = 1) {
        const formatSingleSpeed = (speed) => {
            switch (this.speedUnit.toLowerCase()) {
                case 'kmh':
                    return `${(+speed * 3.6).toFixed(decimals)} km/h`;
                case 'ms':
                    return `${(+speed).toFixed(decimals)} m/s`;
                case 'kt':
                    return `${(+speed * 1.94384).toFixed(decimals)} knots`;
                case 'mph':
                    return `${(+speed * 2.23694).toFixed(decimals)} mph`;
                default:
                    return 'Invalid unit';
            }
        };
        if (!speed2 || +speed1 >= +speed2) {
            return formatSingleSpeed(speed1);
        }
        const formattedSpeed1 = formatSingleSpeed(speed1).split(' ')[0];
        const formattedSpeed2 = formatSingleSpeed(speed2);
        return `${formattedSpeed1}-${formattedSpeed2}`;
    }
    formatDistance(distance, decimals = 1) {
        switch (this.distanceUnit.toLowerCase()) {
            case 'mi':
                return `${(+distance / 1609.34).toFixed(decimals)} miles`;
            case 'nm':
                return `${(+distance / 1852).toFixed(decimals)} nm`;
            case 'km':
                return `${(+distance / 1000).toFixed(decimals)} km`;
            case 'm':
                return `${(+distance).toFixed(decimals)} m`;
            default:
                return 'Invalid unit';
        }
    }
    formatDuration(seconds) {
        if (!seconds) return "now";

        const years = Math.floor(seconds / (365 * 24 * 60 * 60));
        const months = Math.floor((seconds % (365 * 24 * 60 * 60)) / (30 * 24 * 60 * 60));
        const weeks = Math.floor((seconds % (30 * 24 * 60 * 60)) / (7 * 24 * 60 * 60));
        const days = Math.floor((seconds % (7 * 24 * 60 * 60)) / (24 * 60 * 60));

        const durationArray = [];
        if (years > 0) durationArray.push(years === 1 ? "1 year" : `${years} years`);
        if (months > 0) durationArray.push(months === 1 ? "1 month" : `${months} months`);
        if (weeks > 0) durationArray.push(weeks === 1 ? "1 week" : `${weeks} weeks`);
        if (days > 0) durationArray.push(days === 1 ? "1 day" : `${days} days`);

        return durationArray.slice(0, 2).join(" ");
    }
    getWindDirection(degrees) {
        const arrows = ['↑', '↗', '→', '↘', '↓', '↙', '←', '↖'];
        const letterDirections = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
        const index1 = Math.round(+degrees / 22.5) % 16;
        const index2 = Math.round((+degrees + 180) / 45) % 8;
        return `${arrows[index2]} ${letterDirections[index1]}`;
    }
    formatTemperature(kelvin, decimals = 1) {
        const convertToCelsius = (temperature) => +temperature - 273.15;
        const convertToFahrenheit = (temperature) => (+temperature * 9 / 5) - 459.67;
        switch (this.tempUnit.toUpperCase()) {
            case 'C':
                return `${convertToCelsius(kelvin).toFixed(decimals)}°C`;
            case 'K':
                return `${(+kelvin).toFixed(decimals)} K`;
            case 'F':
                return `${convertToFahrenheit(kelvin).toFixed(decimals)}°F`;
            default:
                return 'Invalid unit';
        }
    }
    getCloudCoverEmoji(percentage) {
        if (+percentage <= 10) {
            return '☀️'; // Clear sky
        } else if (+percentage <= 30) {
            return '🌤️'; // Mostly clear
        } else if (+percentage <= 50) {
            return '⛅'; // Partly cloudy
        } else if (+percentage <= 70) {
            return '🌥️'; // Mostly cloudy
        } else {
            return '☁️'; // Cloudy
        }
    }
    timeSince(unixTime) {
        const now = Math.floor(Date.now() / 1000); // Current UNIX timestamp in seconds
        const secondsElapsed = now - unixTime;
        if (secondsElapsed < 60) {
            return secondsElapsed + " secs";
        } else if (secondsElapsed < 3600) {
            const minutes = Math.floor(secondsElapsed / 60);
            return minutes + " min" + (minutes !== 1 ? "s" : "");
        } else if (secondsElapsed < 86400) {
            const hours = Math.floor(secondsElapsed / 3600);
            return hours + " hr" + (hours !== 1 ? "s" : "");
        } else if (secondsElapsed < 604800) {
            const days = Math.floor(secondsElapsed / 86400);
            return days + " day" + (days !== 1 ? "s" : "");
        } else if (secondsElapsed < 2592000) { // 30 days
            const weeks = Math.floor(secondsElapsed / 604800);
            return weeks + " week" + (weeks !== 1 ? "s" : "");
        } else if (secondsElapsed < 31536000) { // 365 days
            const months = Math.floor(secondsElapsed / 2592000);
            return months + " month" + (months !== 1 ? "s" : "");
        } else {
            const years = Math.floor(secondsElapsed / 31536000);
            return years + " year" + (years !== 1 ? "s" : "");
        }
    }
    // Function to calculate distance between two points using Haversine formula (spherical trigonometry)
    calculateDistance(lat1, lon1, lat2, lon2) {
        // Radius of the Earth in meters
        const earthRadius = 6371000;
        const dLat = this.toRadians(lat2 - lat1);
        const dLon = this.toRadians(lon2 - lon1);
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(this.toRadians(lat1)) * Math.cos(this.toRadians(lat2)) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return earthRadius * c;
    }
    toRadians(degrees) {
        return degrees * (Math.PI / 180);
    }
    calculateTrip() {
        if (!this.points || this.points.length < 2) return;
        let lastPoint = this.points[0];
        this.totalDistance = 0;
        this.totalMovingSeconds = 0;
        for (var i = 1; i < this.points.length; i++) {
            let point = this.points[i];
            this.totalDistance += this.calculateDistance(
                point.latitude,
                point.longitude,
                lastPoint.latitude,
                lastPoint.longitude
            );
            const seconds = point.unixtime - lastPoint.unixtime;
            // only count as moving if short time between points
            if (seconds < 2 * 60 * 60) {
                this.totalMovingSeconds += seconds;
            }
            lastPoint = point;
        }
        this.totalSeconds = this.points[this.points.length - 1].unixtime - this.points[0].unixtime;
    }
    calculateInstantSpeed(index = 0) {
        if (!this.points || this.points.length < 2 || index === 0) return 0;
        const latestPoint = this.points[index];
        const secondLatestPoint = this.points[index - 1];
        const timeDiff = latestPoint.unixtime - secondLatestPoint.unixtime;
        const distance = this.calculateDistance(
            latestPoint.latitude,
            latestPoint.longitude,
            secondLatestPoint.latitude,
            secondLatestPoint.longitude
        );
        return timeDiff > 0 ? distance / timeDiff : 0;
    }
    calculateSpeed(index, timeWindowSeconds) {
        const timeDistance = this.calculateTimeDistance(index, timeWindowSeconds);
        return timeDistance.time <= 0 ? 0 : timeDistance.distance / timeDistance.time;
    }
    calculateTimeDistance(index, timeWindowSeconds) {
        if (!this.points || this.points.length < 2) {
            return {
                distance: 0,
                time: timeWindowSeconds
            }; // should never happen
        }
        let currentPoint = this.points[index];
        const startTime = currentPoint.unixtime - timeWindowSeconds;
        let distanceSum = 0;
        let timeDiffSum = 0;
        for (let i = index - 1; i > 0; i--) {
            const previousPoint = this.points[i];
            if (previousPoint.unixtime < startTime && index - i > 1) {
                break; // need at least 2 points
            }
            const distance = this.calculateDistance(
                previousPoint.latitude,
                previousPoint.longitude,
                currentPoint.latitude,
                currentPoint.longitude
            );
            const timeDiff = currentPoint.unixtime - previousPoint.unixtime;
            distanceSum += distance;
            timeDiffSum += timeDiff;
            currentPoint = previousPoint;
        }
        return {
            distance: distanceSum,
            time: timeDiffSum
        };
    }
    // calculate time and distance that we have traveled today
    calculateTimeDistanceToday(index) {
        const ms = +this.points[index].unixtime * 1000;
        const now = Math.floor(new Date(ms) / 1000);
        const today = new Date(ms).setHours(0, 0, 0, 0) / 1000;
        return this.calculateTimeDistance(index, now - today);
    }
    getPopupText(entry, index) {
        let message = `<b>${entry.device_name}</b> - ${this.timeSince(+entry.unixtime)} ago</br>`;
        message += '🕑 ' + entry.localtime + ' ' + entry.localdate + '</br>';
        if (entry.message && entry.type == 'MEDIA')
            message += '<img width="180"  src="' + entry.message + '" class="attachment-thumbnail size-thumbnail" alt="" decoding="async" loading="lazy" /></br>';
        else if (entry.message)
            message += entry.message + '</br>';
        message += 'Speed: ' + this.formatSpeed(this.calculateInstantSpeed(index)) + '</br>';
        message += 'Speed 1hr: ' + this.formatSpeed(this.calculateSpeed(index, 1 * 60 * 60)) + '</br>';
        message += 'Speed 24hr: ' + this.formatSpeed(this.calculateSpeed(index, 24 * 60 * 60)) + '</br>';
        message += 'Distance 24hr: ' + this.formatDistance(this.calculateTimeDistance(index, 24 * 60 * 60).distance) + '</br>';
        message += 'Distance today: ' + this.formatDistance(this.calculateTimeDistanceToday(index).distance) + '</br>';
        // if (entry.altitude > 0)
        //     message += 'Altitude: ' + Number(entry.altitude) + 'm</br>';
        if (entry.battery_status == 'LOW')
            message += '🚨 Battery status is low!' + '</br>';
        if (entry.hiddenPoints)
            message += entry.hiddenPoints.count + ' hidden points ' + entry.hiddenPoints.radius + ' meters</br>';
        if (entry.temp) {
            message += `<br><strong>Weather</strong></br>`;
            message += `🌡️ ${this.formatTemperature(entry.temp)} ${entry.weather_description ?? ""}</br>`;
            message += `🌬️ ${this.getWindDirection(entry.wind_deg)} ${this.formatSpeed(entry.wind_speed, entry.wind_gust, 0)}</br>`;
            message += `${this.getCloudCoverEmoji(entry.clouds)} ${entry.clouds}% cloud cover</br>`;
            message += `💨 ${entry.pressure} hPa</br>`;
            message += `💧 ${entry.humidity}%</br>`;
            message += `🔭 ${entry.visibility} m</br>`;
        }
        return message;
    }
    setNewFeedLayer(feedName) {
        if (this.doesFeedExists(feedName)) {
            return false;
        }
        this.layers.feeds[feedName] = {
            lines: [this.addNewLine(feedName)],
            markers: [],
            points: [],
            featureGroup: L.featureGroup(),
        };
        this.layers.feeds[feedName].featureGroup.addLayer(this.layers.feeds[feedName].lines[0]);
        return true;
    }

    addPoint(point, index) {
        let feedName = point.feed_name;
        let coordinates = [point.latitude, point.longitude];
        if (!this.doesFeedExists(feedName)) {
            this.setNewFeedLayer(feedName);
        }

        // this.getOption('lastPoint')

        let markerOptions = this.getMarkerOptions(point)
        let marker = L.marker(coordinates, {
            ...markerOptions,
            owner: this,
            point,
            index
        });
        marker.on('click', function (e) {
            const options = e.target.options;
            const message = options.owner.getPopupText(options.point, options.index);
            if (e.target.getPopup()) e.target.unbindPopup();
            e.target.bindPopup(message).openPopup();
        })

        this.layers.feeds[feedName].points.push(point);
        this.layers.feeds[feedName].markers.push(marker);
        this.layers.feeds[feedName].featureGroup.addLayer(marker)
        jQuery("#spotmap_" + point.id).click(function () {
            marker.togglePopup();
            self.map.panTo(coordinates)
        });
        jQuery("#spotmap_" + point.id).dblclick(function () {
            marker.togglePopup();
            self.map.setView(coordinates, 14)
        });
    }

    getMarkerOptions(point) {
        let zIndexOffset = 0;
        if (!lodash.includes(['UNLIMITED-TRACK', 'EXTREME-TRACK', 'TRACK'], point.type)) {
            zIndexOffset += 1000;
        } else if (!lodash.includes(['CUSTOM', 'OK'], point.type)) {
            zIndexOffset -= 2000;
        } else if (!lodash.includes(['HELP', 'HELP-CANCEL',], point.type)) {
            zIndexOffset -= 3000;
        }

        let markerOptions = {
            icon: this.getMarkerIcon(point),
            zIndexOffset: zIndexOffset,
        };

        return markerOptions;
    }
    getMarkerIcon(point) {
        let color = point.color ? point.color : this.getOption('color', {
            'feed': point.feed_name
        });
        let iconOptions = {
            textColor: color,
            borderColor: color,
        }

        if (lodash.includes(['UNLIMITED-TRACK', 'EXTREME-TRACK', 'TRACK'], point.type)) {
            iconOptions.iconShape = spotmapjsobj.marker["UNLIMITED-TRACK"].iconShape;
            iconOptions.icon = spotmapjsobj.marker["UNLIMITED-TRACK"].icon;
            iconOptions.iconAnchor = [4, 4];
            iconOptions.iconSize = [8, 8];
            iconOptions.borderWith = 8;
        }
        // Is the point.type configured?
        if (spotmapjsobj.marker[point.type]) {
            iconOptions.iconShape = spotmapjsobj.marker[point.type].iconShape;
            iconOptions.icon = spotmapjsobj.marker[point.type].icon;
            if (iconOptions.iconShape == 'circle-dot') {
                iconOptions.iconAnchor = [4, 4];
                iconOptions.iconSize = [8, 8];
                iconOptions.borderWith = 8;
            }
        } else {
            iconOptions.iconShape = "marker";
            iconOptions.icon = "circle";
        }
        return L.BeautifyIcon.icon(iconOptions)
    }
    addPointToLine(point) {
        let feedName = point.feed_name;
        if (feedName == 'media')
            return
        let coordinates = [point.latitude, point.longitude];
        let splitLines = this.getOption('splitLines', {
            'feed': feedName
        });
        if (!splitLines) {
            return false;
        }
        let numberOfPointsAddedToMap = this.layers.feeds[feedName].points.length;
        let lastPoint;
        if (numberOfPointsAddedToMap == 2) {
            //  TODO
            lastPoint = this.layers.feeds[feedName].points[numberOfPointsAddedToMap - 1];
            // compare with given point if it's the same exit
        }
        if (numberOfPointsAddedToMap >= 2) {
            lastPoint = this.layers.feeds[feedName].points[numberOfPointsAddedToMap - 2];
        }
        let length = this.layers.feeds[feedName].lines.length;
        if (lastPoint && point.unixtime - lastPoint.unixtime >= splitLines * 60 * 60) {
            // start new line and add to map
            let line = this.addNewLine(feedName);
            line.addLatLng(coordinates);
            this.layers.feeds[feedName].lines.push(line)
            this.layers.feeds[feedName].featureGroup.addLayer(line);
        } else {
            this.layers.feeds[feedName].lines[length - 1].addLatLng(coordinates);
        }

        return true;
    }
    /**
     * Creates an empty polyline according to the settings gathered from the feedname
     * @param {string} feedName 
     * @returns {L.polyline} line 
     */
    addNewLine(feedName) {
        let color = this.getOption('color', {
            'feed': feedName
        });
        let line = L.polyline([], {
            color: color
        });

        line.setText('  \u25BA  ', {
            repeat: true,
            offset: 2,
            attributes: {
                'fill': 'black',
                'font-size': 7
            }
        });
        return line;
        // this.layers.feeds[feedName].lines.push(line);
    }
    /**
     * 
     * @param {string} option
     */
    setBounds(option) {
        this.map.fitBounds(this.getBounds(option));
    }
    /**
     * Calculates the bounds to the given option
     * @param {string} option - all,last,last-trip,gpx
     * @returns {L.latLngBounds}
     */
    getBounds(option) {
        let bounds = L.latLngBounds();
        let coordinates = [];
        var self = this;
        let latestPoint;
        if (option == "last" || option == "last-trip") {
            let unixtime = 0;
            lodash.forEach(self.layers.feeds, function (value, feedName) {
                let point = lodash.last(self.layers.feeds[feedName].points);

                if (point.unixtime > unixtime) {
                    latestPoint = lodash.last(self.layers.feeds[feedName].points);
                }
            });
            bounds.extend([latestPoint.latitude, latestPoint.longitude]);
            if (option == "last") {
                return bounds;
            }
            // get bounds for last-trip 
            let line = lodash.last(self.layers.feeds[latestPoint.feed_name].lines);
            return line.getBounds();
        }

        let feedBounds = L.latLngBounds();
        var self = this;
        lodash.forEach(self.layers.feeds, function (value, feedName) {
            let layerBounds = self.layers.feeds[feedName].featureGroup.getBounds();
            feedBounds.extend(layerBounds);
        });
        if (option == "feeds") {
            return feedBounds;
        }
        let gpxBounds = L.latLngBounds();
        lodash.forEach(self.layers.gpx, function (value, key) {
            let layerBounds = self.layers.gpx[key].featureGroup.getBounds();
            gpxBounds.extend(layerBounds);

        });
        if (option == "gpx") {
            return gpxBounds;
        }
        if (option == "all") {
            bounds.extend(gpxBounds);
            bounds.extend(feedBounds);
            return bounds;
        }

    }
}