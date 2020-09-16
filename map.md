# Map Configuration

In this section you will learn how to configure the map to your needs.

To display the map use the shortcode `[spotmap]`. To configure the map we use attributes and values. 
See here how they are used `[spotmap attribute=value]`
If `value` contains a space for example a name you have to use quotes: `[spotmap attribute="My Value"]`

Many attributes allow multiple values. Just use a comma to separate them: `[spotmap maps=opentopomap,tf-landscape]`

A default value is the value that will get used if nothing is specified in the shortcode. Those default values are configurable in the Dashboard.

There are certain configurations that rely on third party services. For those settings, you will need to generate an API key and put it in the Settings section of Spotmap in your WP Dashboard.

We have three areas to configure: The map, how to display the spot data and gpx tracks

## Map

Configure the displayed with the following attribute:

`maps` (Default: `maps=openstreetmap,opentopomap`)

Supported options:
* openstreetmap
* opentopomap
* tf-landscape
* tf-cycle
* tf-outdoors
* mb-outdoors
* mb-satelite
* mb-streets

For the maps that start with "mb-" (Mapbox) and "tf-" (Thunderforest) (the 3rd party maps) you need to create a API key to be able to use the maps.
Create a Thunderforest account [here](https://manage.thunderforest.com/users/sign_up?plan_id=5) to create your API Key.
Create a Mapbox account [here](https://timezonedb.com/register) to create your API Key.

If you have not set an API Key in the Dashboard the 3rd party maps will not appear.

## Feed data

Configure how the spot data will appear on the map.

`splitlines` (Default: `splitlines=12`)

Can be used to interrupt the line between sent positions. If two positions are sent with a gap of X hours or greater it will split the line. 

Set it to 0 if you don't like to see any line.

`date-range-from` (No Default value) 

It can be used to show all points starting from date and time X. (Can lie in the future). 
A possible value must match the format: `YYYY-MM-DD (HH:MM)`.
The time is optional.

`date-range-to` 

It can be used to show all points until date and time X. Same format than above.

`date` 

This attribute can be used to only show the data from only the speciefied date.

`auto-reload=1` (Default: 0 (off) )

If enabled (set to `1`) this will auto update the map without the need to reload the page.

`tiny-types` 

Supports multiple values. All listed point types will be shown as little dots on the map.

`feeds` 

can be set, if multiple feeds are set up in the Dashboard. In the settings section you can give the feed a name. This name can be specified here.
If you have multiple feeds configured in the Dashboard. You can use the name of a feed as a value for this attribute.

Let's imagine  you have three spot feeds configured in the Dashboard. For simplicity those are named as following: "Spot A" "Spot B" and "My third Spot"
The feed data  of all three will be displayed normally. In case you want a subset use `feeds="Spot A,My third Spot"`

