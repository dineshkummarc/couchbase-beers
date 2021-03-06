<?php

// require stuffs
require_once 'config.php';
require 'vendors/Resty/Resty.php';
require 'vendors/Slim/Slim/Slim.php';
require 'vendors/Slim-Extras/Views/MustacheView.php';

const INSIDE_BEERNIQUE = true;

// set up the app
MustacheView::$mustacheDirectory = 'vendors';
$app = new Slim(array('view' => 'MustacheView'));
$env = $app->environment();
$app->view()->appendData(array(
  'app_title' => 'Beernique',
  'base_url' => str_replace('/index.php', '', $env['SCRIPT_NAME']),
  'current_url' => $env['PATH_INFO']
));

$app->add(new Slim_Middleware_SessionCookie());

// http://www.php.net/manual/en/function.max.php#97004
function max_key($array) {
  foreach ($array as $key => $val) {
    if ($val == max($array)) return $key;
  }
}

// Setup Couchbase connected objects
try {
  $cb = new Couchbase(COUCHBASE_HOST.':'.COUCHBASE_PORT, COUCHBASE_USER, COUCHBASE_PASSWORD, COUCHBASE_BUCKET);
} catch (ErrorException $e) {
  die($e->getMessage());
}


// GET route
$app->get('/', function () use ($app, $cb) {
  $on_index = true;
  if (isset($_SESSION['email']) && $_SESSION['email'] !== ''
      && ($users_beers = $cb->get(sha1($_SESSION['email']))) !== null) {
    $users_beers = array_filter(explode('|', $users_beers));
    $users_beer_counts = array_count_values($users_beers);
    if (count($users_beers) > 0) {
      $breweries = array();
      $unique_beers = array();
      foreach ($users_beers as $beer_id) {
        $beer = json_decode($cb->get($beer_id));
        // if a non-existent beer was accidently added to the users doc, skip it
        if ($beer === null) {
          continue;
        }
        // add to the brewery counter for "mostly_by" list
        if (!isset($breweries[$beer->brewery])) {
          $breweries[$beer->brewery] = 1;
        } else {
          $breweries[$beer->brewery]++;
        }
        // if we already have the beer in the list, though, let's skip it
        if (in_array($beer_id, $unique_beers)) {
          continue;
        }
        $beer->beer_url = 'beers/' . str_replace(' ', '_', $beer->name);
        $beer->brewery_url = 'breweries/' . str_replace(' ', '_', $beer->brewery);
        $beer->drank_times = $users_beer_counts[$beer_id];
        $beers[] = $beer;
        $unique_beers[] = $beer_id;
      }
    }
  }
  $content = $app->view()->render('index.mustache');
  if (isset($beers)) {
    $app->render('layout.mustache',
                compact('content', 'beers', 'on_index')
                  + array('has_beers' => (count($beers) > 0),
                          'mostly_drink' => str_replace('_', ' ', str_replace('beer_', '', max_key($users_beer_counts))),
                          'mostly_by' => max_key($breweries)
                    )
              );
  } else {
    $app->render('layout.mustache', compact('content', 'on_index'));
  }
});

// GET BrowserID verification
$app->post('/browserid/login', function () use ($app, $cb) {
  header('Content-Type: application/json');
  $resty = new Resty();
  $resty->debug(true);
  $assertion=$app->request()->post('assertion');
  // get the POSTed assertion
  $post_data = array('assertion' => $assertion, 'audience' => $_SERVER['SERVER_NAME']);
  // SERVER is my site's hostname
  $resty->setBaseURL('https://browserid.org/');
  // This makes a post request to browserid.org
  $r = $resty->post('verify',$post_data);

  if ($r['body']->status == 'okay') {
    // This logs the user in if we have an account for that email address,
    // or creates it otherwise
    //$email = sha1($r['body']['email']);
    $email = $_SESSION['email'] = $r['body']->email;
    if ($cb->get(sha1($email)) === null) {
      $cb->set(sha1($email), '');
    }
    echo json_encode($email);
  } else {
    $msg = 'Could not log you in';
    $status = false;
    echo json_encode(array('message'=>$msg,'status'=>$status));
  }
});

$app->post('/browserid/logout', function() use ($app) {
  $_SESSION['email'] = null;
});

$app->get('/browserid/whoami', function() use ($app) {
  $app->response()->header('Content-Type', 'application/json');
  if (isset($_SESSION['email'])) {
    echo json_encode($_SESSION['email']);
  }
});

// beer routes
require_once 'routes/beers.php';
// brewery routes
require_once 'routes/breweries.php';
// run, Slim, run
$app->run();
