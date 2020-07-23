# LHR Export

This repository contains an application in which LHR's (holding data) of monograph's are downloaded from OCLC's [WMS](https://www.oclc.org/nl/worldshare-management-services.html) for import in other library systems like Syndeo. The application uses two API's on WMS: the [WMS Collection Management API](https://www.oclc.org/developer/develop/web-services/wms-collection-management-api.en.html) and the [WorldCat Metadata API](https://www.oclc.org/developer/develop/web-services/worldcat-metadata-api.en.html). PHP libraries for interfacing with the API's are provided.

For libraries using WMS, OCLC provides on a daily basis files with all new, deleted and updated holding data of publications that are held by a library. Together with the upload in other systems (like discovery or cataloguing systems) this process can take too much time. 

With this application librarians can export one or more LHR's in a browser window and provide the file to another system they are using.

## Only on windows

The application only works on Windows, because it depends on marcedit for conversion from MARC xml to the mrc format. Marcedit must be installed or downloaded. Change the directory where the app can find marcedit in `index.php`.

## Dependencies
* [MarcEdit](https://marcedit.reeset.net/) must be installed.
* This app must be installed in a LAMP environment, like [XAMP](https://www.apachefriends.org/index.html).
* [TWIG](https://twig.symfony.com) must be installed. 



