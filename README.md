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
database. In order to that you need to specify the MongoDB url, user and
password with URL containing all three like this:

    export CIP_DAEMON_MONGODB_URL='mongodb://username:password@mongo.example.org:port'
