var spotmap = L.map('spotmap',{ fullscreenControl: true,});
var OpenTopoMap = L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
    maxZoom: 16,
    attribution: 'Map: &copy; <a href="https://opentopomap.org">OpenTopoMap</a> (<a href="https://creativecommons.org/licenses/by-sa/3.0/">CC-BY-SA</a>)'
});
OpenTopoMap.addTo(spotmap);

function onEachFeature(feature, layer) {
    switch (feature.properties.type) {
        case 'CUSTOM':
        case 'OK':
            layer.bindPopup(
                'Date: ' + feature.properties.date + '</br>'
                + 'Time: ' + feature.properties.time + '</br>'
                + 'Message: ' + feature.properties.message);
            break;
        default:
            layer.bindPopup(
                'Date: ' + feature.properties.date + '</br>'
                + 'Time: ' + feature.properties.time);
    }
}

jQuery(document).ready(function () {
    jQuery.post(spotmapjsobj.ajax_url,{ 'action': 'get_positions'},function (response) {

        if(response.length == 0){
            spotmap.setView([51.505, -0.09], 13);
            var popup = L.popup()
                .setLatLng([51.5, -0.09])
                .setContent("<b>No data found</b><br>Please register your feed id in the settings")
                .openOn(spotmap);
            return;
        }

        response.forEach(function(point){

        });

        L.geoJSON(response, {
            onEachFeature: onEachFeature
        }).addTo(spotmap);

        const mapcenter = jQuery('#spotmap').data("mapcenter");
        if(mapcenter == 'all'){
            //get the outermost points to set the map boarders accordingly
            var corner1 = [200,200], corner2 = [-200,-200];
            response.forEach(function (point) {
                if (corner1[1] > point.geometry.coordinates[0]){
                    corner1[1] = point.geometry.coordinates[0];
                }
                if (corner1[0] > point.geometry.coordinates[1]){
                    corner1[0] = point.geometry.coordinates[1];
                }
                if (corner2[1] < point.geometry.coordinates[0]){
                    corner2[1] = point.geometry.coordinates[0];
                }
                if (corner2[0] < point.geometry.coordinates[1]){
                    corner2[0] = point.geometry.coordinates[1];
                }
            });
            //console.log(JSON.stringify([corner2,corner1]));
            spotmap.fitBounds([
                corner2,
                corner1
            ]);
        } else if (mapcenter == 'last'){
            var lastpoint = response[response.length-1];
            spotmap.setView([lastpoint.geometry.coordinates[1], lastpoint.geometry.coordinates[0]], 13);
        }

    });
});
