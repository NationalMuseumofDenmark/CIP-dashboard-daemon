<?php
require_once 'daemon.php';

if (!(getenv('CIP_ENDPOINT') && getenv('CIP_USER') && getenv('CIP_PASSWORD'))) {
  echo 'You need to set the CIP url, user and password in the environment variables
        CIP_ENDPOINT, CIP_USER and CIP_PASSWORD.';
} else {
  error_log('Instantiating daemon...');
  $daemon = CIP\CIPAnalysisDaemon::constructWithUserPassword(getenv('CIP_ENDPOINT'), getenv('CIP_USER'), getenv('CIP_PASSWORD'));

  error_log('Running daemon...');
  $daemon->run();

  error_log('Saving results...');
  $daemon->save();
}
