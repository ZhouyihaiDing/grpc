<?php

namespace Grpc\GCP;

class Config
{
  private $hostname;
  private $gcp_call_invoker;

  public function __construct($hostname, $conf)
  {
    if (session_status() == PHP_SESSION_NONE) {
      session_start();
    }
    // TODO(ddyihai): add session expire to free channel pool.
    // session_set_cookie_params(2);
    // session_cache_expire(1);

    $gcp_channel = null;
    // hostname is used for distinguishing different cloud APIs.
    $this->hostname = $hostname;
    $channel_pool_key = $hostname. 'gcp_channel' . getmypid();

    if(array_key_exists($channel_pool_key, $_SESSION)) {
      // Channel pool for the $hostname API has already created.
      echo "count: ". count($_SESSION). " key: $channel_pool_key\n";
      foreach ($_SESSION as $key=>$value)
      {
        echo "key $key\n";
      }
      echo "GCP channel has already created by this PHP-FPM worker process\n";
      $gcp_call_invoker = unserialize($_SESSION[$channel_pool_key]);
    } else {
      echo "GCP channel has not created by this worker process before\n";
      $affinity_conf = $this->parseConfObject($conf);
      // Create GCP channel based on the information.
      $gcp_call_invoker = new GCPCallInvoker($affinity_conf);
    }
    $this->gcp_call_invoker = $gcp_call_invoker;

    register_shutdown_function(function ($gcp_call_invoker, $channel_pool_key) {
      // Push the current gcp_channel back into the pool when the script finishes.
      //  $affinity_conf['gcp_channel'.getmypid()] = $channel;
      $channel = $gcp_call_invoker->_getChannel();
      echo "register_shutdown_function $channel_pool_key version:" . $channel->version . "\n";
      $_SESSION[$channel_pool_key] = serialize($gcp_call_invoker);
    }, $gcp_call_invoker, $channel_pool_key);
  }

  public function callInvoker() {
    return $this->gcp_call_invoker;
  }

  private function parseConfObject($conf_object) {
    $config = json_decode($conf_object->serializeToJsonString(), true);
    $affinity_conf['channelPool'] = $config['channelPool'];
    $aff_by_method = array();
    for ($i = 0; $i < count($config['method']); $i++) {
      // In proto3, if the value is default, eg 0 for int, it won't be serialized.
      // Thus serialized string may not have `command` if the value is default 0(BOUND).
      if (!array_key_exists('command', $config['method'][$i]['affinity'])) {
        $config['method'][$i]['affinity']['command'] = 'BOUND';
      }
      $aff_by_method[$config['method'][$i]['name'][0]] = $config['method'][$i]['affinity'];
    }
    $affinity_conf['affinity_by_method'] = $aff_by_method;
    return $affinity_conf;
  }

  private function sessionExpireUpdate() {
    foreach ($_SESSION as $key=>$value)
    {
      if($value['expiretime'] >= time()) {
        unset($_SESSION[$key]);
      }
    }
  }
}
