<?php

namespace Grpc_gcp;

use Google\Auth\ApplicationDefaultCredentials;

function parseConfObject($conf_object) {
  $config = json_decode($conf_object->serializeToJsonString(), true);
  $api_conf = $config['api'][0];
  $global_conf['target'] = $api_conf['target'];
  $global_conf['channelPool'] = $api_conf['channelPool'];
  $aff_by_method = array();
  for ($i = 0; $i < count($api_conf['method']); $i++) {
    // In proto3, if the value is default, eg 0 for int, it won't be serialized.
    // Thus serialized string may not have `command` if the value is default 0(BOUND).
    if (!array_key_exists('command', $api_conf['method'][$i]['affinity'])) {
      $api_conf['method'][$i]['affinity']['command'] = 'BOUND';
    }
    $aff_by_method[$api_conf['method'][$i]['name'][0]] = $api_conf['method'][$i]['affinity'];
  }
  $global_conf['affinity_by_method'] = $aff_by_method;
  return $global_conf;
}

function enableGrpcGcp($conf, $opts) {

  $channel_pool_key = 'gcp_channel'.getmypid();
  if(apcu_exists($channel_pool_key)) {
    echo "PFP_FPM GCP has been enabled. Reuse the config and the channel.\n";
    $gcp_channel = apcu_fetch($channel_pool_key);
    register_shutdown_function(function ($channel, $channel_pool_key) {
      // Push the current gcp_channel back into the pool when the script finishes.
      //  $global_conf['gcp_channel'.getmypid()] = $channel;
      echo "register_shutdown_function ". $channel->version. "\n";
      apcu_delete($channel_pool_key);
      apcu_add($channel_pool_key, $channel);
    }, $gcp_channel, $channel_pool_key);
    $gcp_channel->reCreateCredentials();
    $channel_interceptor = new \Grpc_gcp\GCPCallInterceptor($gcp_channel, $opts);
    $channel = \Grpc\Interceptor::intercept($gcp_channel, $channel_interceptor);
    return $channel;
  } else {
    $global_conf = parseConfObject($conf);
    $opts_include_conf = array_merge($opts, ['global_conf' => $global_conf]);
    $gcp_channel = new \Grpc_gcp\GrpcExtensionChannel($opts_include_conf);
    $channel_interceptor = new \Grpc_gcp\GCPCallInterceptor($gcp_channel, $opts);
    $channel = \Grpc\Interceptor::intercept($gcp_channel, $channel_interceptor);
    apcu_add('gcp_channel' . getmypid(), $gcp_channel);
    return $channel;
  }
}
