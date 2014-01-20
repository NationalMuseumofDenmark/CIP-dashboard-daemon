CIP-dashboard-daemon
====================
A script for gathering statistics on a CIP database (compatible with CIP-dashboard)

Running
-------
You need to set up the environment variables `CIP_ENDPOINT`, `CIP_USER` and
`CIP_PASSWORD` for the daemon to connect to the CIP. These are the URL and user
credentials for your CIP. You can then run the daemon with `php run_daemon.php`.

    export CIP_ENDPOINT='http://cip.example.org'
    export CIP_USER='username'
    export CIP_PASSWORD='password'
    php run_daemon.php

The daemon will by default save the result (statistics and layout) to a MongoDB
database. In order to do that you need to specify the MongoDB url, user and
password with URL containing all three like this:

    export CIP_DAEMON_MONGODB_URL='mongodb://username:password@mongo.example.org:port'

You do not need to specify this if your MongoDB is running on the same machine
(`localhost`).

### Specifying catalog aliases
The dashboard daemon needs to know the aliases of the catalogs you are want
statistics on, and you must specify a layout to use. You should specify these in
a file called `conf.json`. An example config is provided in `conf.example.json`.

A pseudo-catalog containing combined statistics for all the catalogs you have
specified will be saved with a catalog alias of `ALL` and the with the name
specified under `all_catalogs_label` in `conf.json`.

Custom queries
--------------
The daemon can also gather statistic on custom Cumulus queries (instead of
simply gathering statistics on the full database). These are specified
in `conf.json` under the key `queries`. Please see `conf.example.json`.

Every query will be run for every catalog and the queries are sent directly to
the CIP without modification. The queries you specify should therefore follow
the _Cumulus Query Format_.

Dependencies
------------
This tool depends on PHP 5.x with lib cURL installed (+ the pecl tool depends the installation of the php-pear package)
To install this on an debian/ubuntu machine, type

    sudo apt-get install php5 php5-curl php-pear

It also depends the mongo db extension for php

    sudo pecl install mongo

If you are deploying with a localhost mongo db, you need the mongodb daemon as well

    sudo apt-get install mongodb-server
